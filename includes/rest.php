<?php

defined( 'ABSPATH' ) || exit;

/**
 * Registra las rutas REST públicas.
 */
function tavox_menu_api_register_routes(): void {
	register_rest_route(
		'tavox/v1',
		'/categories',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'tavox_api_categories',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'tavox/v1',
		'/products',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'tavox_api_products',
			'permission_callback' => '__return_true',
			'args'                => [
				'category' => [
					'sanitize_callback' => 'sanitize_text_field',
					'default'           => '',
				],
				'q'        => [
					'sanitize_callback' => 'sanitize_text_field',
					'default'           => '',
				],
			],
		]
	);

	register_rest_route(
		'tavox/v1',
		'/promotions',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'tavox_api_promotions',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'tavox/v1',
		'/settings',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'tavox_api_settings',
			'permission_callback' => '__return_true',
		]
	);
}
add_action( 'rest_api_init', 'tavox_menu_api_register_routes' );

/**
 * Devuelve las categorías visibles del menú.
 */
function tavox_api_categories( WP_REST_Request $request ) {
	$cache_key = tavox_menu_api_get_cache_key( 'categories' );
	$cached    = tavox_menu_api_get_cached_value( $cache_key );
	if ( false !== $cached ) {
		return rest_ensure_response( $cached );
	}

	$items = [];
	foreach ( tavox_get_active_categories() as $category ) {
		$term = get_term( (int) $category['id'], 'product_cat' );
		if ( ! $term || is_wp_error( $term ) ) {
			continue;
		}

		$thumb_id  = get_term_meta( $term->term_id, 'thumbnail_id', true );
		$image_url = $thumb_id ? wp_get_attachment_url( $thumb_id ) : '';
		$items[]   = [
			'id'              => (int) $term->term_id,
			'name'            => html_entity_decode( $term->name ),
			'slug'            => $term->slug,
			'aliases'         => tavox_menu_api_parse_search_aliases( $category['aliases'] ?? [] ),
			'menu_scope'      => tavox_menu_api_sanitize_menu_scope( (string) ( $category['menu_scope'] ?? 'zona_b' ) ),
			'service_station' => tavox_menu_api_sanitize_service_station( (string) ( $category['service_station'] ?? 'auto' ) ),
			'enabled'         => true,
			'order'           => (int) $category['order'],
			'image'           => $image_url,
		];
	}

	tavox_menu_api_set_cached_value( $cache_key, $items );

	return rest_ensure_response( $items );
}

/**
 * Devuelve los productos del menú aplicando filtro por categoría y búsqueda.
 */
function tavox_api_products( WP_REST_Request $request ) {
	$raw_category       = trim( (string) $request->get_param( 'category' ) );
	$search             = trim( (string) $request->get_param( 'q' ) );
	$cache_key          = tavox_menu_api_get_cache_key(
		'products',
		[
			'category' => $raw_category,
			'q'        => $search,
		]
	);
	$cached             = tavox_menu_api_get_cached_value( $cache_key );
	if ( false !== $cached ) {
		return rest_ensure_response( $cached );
	}

	$active_terms_by_id = tavox_menu_api_get_active_category_terms();
	$active_category_search_terms = tavox_menu_api_get_active_category_search_terms( $active_terms_by_id );
	$active_category_ids = array_keys( $active_terms_by_id );
	if ( empty( $active_category_ids ) ) {
		return rest_ensure_response( [] );
	}

	$requested_category_ids = tavox_menu_api_resolve_requested_category( $raw_category, $active_terms_by_id );
	if ( ( '' !== $raw_category && '0' !== $raw_category ) && empty( $requested_category_ids ) ) {
		return rest_ensure_response( [] );
	}

	$normalized_query = tavox_menu_api_normalize_text( $search );
	$products         = wc_get_products(
		[
			'status'             => 'publish',
			'catalog_visibility' => 'visible',
			'orderby'            => 'title',
			'order'              => 'ASC',
			'limit'              => -1,
		]
	);

	$items = [];
	foreach ( $products as $product ) {
		if ( ! $product instanceof WC_Product ) {
			continue;
		}

		$menu_category_ids = tavox_menu_api_get_menu_category_ids_for_product( $product, $active_category_ids );
		if ( empty( $menu_category_ids ) ) {
			continue;
		}

		if ( ! empty( $requested_category_ids ) && empty( array_intersect( $menu_category_ids, $requested_category_ids ) ) ) {
			continue;
		}

		if ( ! tavox_menu_api_product_matches_search( $product, $menu_category_ids, $active_category_search_terms, $normalized_query ) ) {
			continue;
		}

		$items[] = tavox_menu_api_map_product( $product, $active_category_ids );
	}

	tavox_menu_api_set_cached_value( $cache_key, $items );

	return rest_ensure_response( $items );
}

/**
 * Devuelve la secuencia activa de promociones.
 */
function tavox_api_promotions( WP_REST_Request $request ) {
	$cache_key = tavox_menu_api_get_cache_key( 'promotions' );
	$cached    = tavox_menu_api_get_cached_value( $cache_key );
	if ( false !== $cached ) {
		return rest_ensure_response( $cached );
	}

	$active_category_ids = tavox_menu_api_get_active_category_ids();
	$items               = [];

	foreach ( tavox_menu_api_get_promotions_config() as $promotion ) {
		if ( ! tavox_menu_api_is_promotion_active( $promotion ) ) {
			continue;
		}

		$product_id      = absint( $promotion['product_id'] ?? 0 );
		$promotion_style = ! empty( $promotion['promo_style'] ) ? sanitize_key( (string) $promotion['promo_style'] ) : 'default';
		$product         = $product_id > 0 ? wc_get_product( $product_id ) : null;
		$mapped_product  = null;

		if ( $product instanceof WC_Product ) {
			if ( ! tavox_menu_api_is_product_available_for_promotion( $product ) ) {
				continue;
			}

			$mapped_product = tavox_menu_api_map_product( $product, $active_category_ids );
			if ( empty( $mapped_product['menu_category_ids'] ) ) {
				continue;
			}
		} elseif ( 'event' !== $promotion_style ) {
			continue;
		}

		$items[] = [
			'id'         => 'promo_' . (int) $promotion['order'] . '_' . ( $product instanceof WC_Product ? (int) $product->get_id() : 'event' ),
			'product_id' => $product instanceof WC_Product ? (int) $product->get_id() : 0,
			'order'      => (int) $promotion['order'],
			'promo_style'=> $promotion_style,
			'brand_scope'=> $mapped_product['brand_scope'] ?? tavox_menu_api_sanitize_menu_scope( (string) ( $promotion['brand_scope'] ?? 'zona_b' ) ),
			'badge'      => (string) ( $promotion['badge'] ?? '' ),
			'title'      => '' !== (string) ( $promotion['title'] ?? '' ) ? (string) $promotion['title'] : ( $mapped_product['name'] ?? '' ),
			'copy'       => (string) ( $promotion['copy'] ?? '' ),
			'event_meta' => (string) ( $promotion['event_meta'] ?? '' ),
			'event_guests' => (string) ( $promotion['event_guests'] ?? '' ),
			'image_focus_x' => tavox_menu_api_normalize_focus_value( $promotion['image_focus_x'] ?? 50 ),
			'image_focus_y' => tavox_menu_api_normalize_focus_value( $promotion['image_focus_y'] ?? 50 ),
			'show_in_search' => array_key_exists( 'show_in_search', $promotion ) ? ! empty( $promotion['show_in_search'] ) : true,
			'image'      => '' !== (string) ( $promotion['image'] ?? '' ) ? (string) $promotion['image'] : ( $mapped_product['image'] ?? '' ),
			'starts_at'  => (string) ( $promotion['starts_at'] ?? '' ),
			'ends_at'    => (string) ( $promotion['ends_at'] ?? '' ),
			'product'    => $mapped_product,
		];
	}

	tavox_menu_api_set_cached_value( $cache_key, $items );

	return rest_ensure_response( $items );
}

/**
 * Devuelve la configuración pública del menú.
 */
function tavox_api_settings( WP_REST_Request $request ) {
	$cache_key = tavox_menu_api_get_cache_key( 'settings' );
	$cached    = tavox_menu_api_get_cached_value( $cache_key );
	if ( false !== $cached ) {
		return rest_ensure_response( $cached );
	}

	$settings = tavox_menu_api_get_settings();
	$public_settings = [
		'whatsapp_phone'         => (string) ( $settings['whatsapp_phone'] ?? '' ),
		'multi_menu_enabled'     => ! empty( $settings['multi_menu_enabled'] ),
		'table_order_enabled'    => ! empty( $settings['table_order_enabled'] ),
		'waiter_console_enabled' => ! empty( $settings['waiter_console_enabled'] ),
		'menu_frontend_url'      => (string) ( $settings['menu_frontend_url'] ?? '' ),
		'notification_sound_enabled' => array_key_exists( 'notification_sound_enabled', $settings ) ? ! empty( $settings['notification_sound_enabled'] ) : true,
		'push_notifications_enabled' => array_key_exists( 'push_notifications_enabled', $settings ) ? ! empty( $settings['push_notifications_enabled'] ) : true,
		'push_public_key'        => tavox_menu_api_is_push_ready() ? (string) ( $settings['push_vapid_public_key'] ?? '' ) : '',
	];
	tavox_menu_api_set_cached_value( $cache_key, $public_settings );

	return rest_ensure_response( $public_settings );
}

/**
 * Mapea un producto al shape REST consumido por el frontend.
 *
 * @return array<string, mixed>
 */
function tavox_menu_api_map_product( WC_Product $product, array $active_category_ids = [] ): array {
	$active_category_ids = ! empty( $active_category_ids ) ? $active_category_ids : tavox_menu_api_get_active_category_ids();
	$menu_category_ids   = tavox_menu_api_get_menu_category_ids_for_product( $product, $active_category_ids );
	$raw_groups          = get_post_meta( $product->get_id(), '_tavox_groups', true );
	$decoded_groups      = tavox_menu_api_decode_groups( $raw_groups );
	$image_url           = '';
	$thumb_id            = $product->get_image_id();

	if ( $thumb_id ) {
		$image_url = wp_get_attachment_image_url( $thumb_id, 'woocommerce_thumbnail' );
	}

	$stock_qty = -1;
	if ( $product->get_manage_stock() ) {
		$qty_raw   = $product->get_stock_quantity();
		$stock_qty = is_numeric( $qty_raw ) ? (int) $qty_raw : -1;
	}

	return [
		'id'                      => (int) $product->get_id(),
		'sku'                     => (string) $product->get_sku(),
		'slug'                    => $product->get_slug(),
		'name'                    => html_entity_decode( $product->get_name() ),
		'short_description'       => wp_kses_post( $product->get_short_description() ),
		'description'             => wp_kses_post( $product->get_description() ),
		'price_usd'               => (float) $product->get_price(),
		'in_stock'                => (bool) $product->is_in_stock(),
		'stock_qty'               => $stock_qty,
		'image'                   => $image_url,
		'extras'                  => tavox_menu_api_map_extras( $decoded_groups ),
		'categories'              => array_map( 'intval', $product->get_category_ids() ),
		'menu_category_ids'       => $menu_category_ids,
		'primary_menu_category_id'=> tavox_menu_api_get_primary_menu_category_id( $menu_category_ids ),
		'brand_scope'             => tavox_menu_api_get_brand_scope_for_menu_categories( $menu_category_ids ),
	];
}
