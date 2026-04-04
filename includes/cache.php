<?php

defined( 'ABSPATH' ) || exit;

const TAVOX_MENU_API_CACHE_TTL = 15 * MINUTE_IN_SECONDS;

/**
 * Obtiene la versión actual del caché de catálogo.
 */
function tavox_menu_api_get_cache_version(): int {
	$version = (int) get_option( 'tavox_menu_api_cache_version', 1 );

	return $version > 0 ? $version : 1;
}

/**
 * Incrementa la versión del caché para invalidar todas las respuestas.
 */
function tavox_menu_api_bump_cache_version(): int {
	$next_version = tavox_menu_api_get_cache_version() + 1;
	update_option( 'tavox_menu_api_cache_version', $next_version, false );

	return $next_version;
}

/**
 * Construye una clave de caché estable usando recurso, parámetros y versión.
 *
 * @param string $resource Nombre lógico del recurso REST.
 * @param array  $params   Parámetros relevantes de la respuesta.
 */
function tavox_menu_api_get_cache_key( string $resource, array $params = [] ): string {
	$payload = [
		'v'        => tavox_menu_api_get_cache_version(),
		'resource' => $resource,
		'params'   => $params,
	];

	return 'tavox_api_' . md5( wp_json_encode( $payload ) );
}

/**
 * Lee una respuesta cacheada.
 */
function tavox_menu_api_get_cached_value( string $cache_key ) {
	return get_transient( $cache_key );
}

/**
 * Guarda una respuesta cacheada.
 *
 * @param string $cache_key Clave del transient.
 * @param mixed  $value     Valor serializable.
 * @param int    $ttl       Tiempo de vida.
 */
function tavox_menu_api_set_cached_value( string $cache_key, $value, int $ttl = TAVOX_MENU_API_CACHE_TTL ): void {
	set_transient( $cache_key, $value, $ttl );
}

/**
 * Invalida el catálogo cuando cambia un producto.
 */
function tavox_menu_api_invalidate_product_cache_on_save( int $post_id, WP_Post $post ): void {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	if ( 'product' !== $post->post_type ) {
		return;
	}

	tavox_menu_api_bump_cache_version();
}
add_action( 'save_post_product', 'tavox_menu_api_invalidate_product_cache_on_save', 10, 2 );

/**
 * Invalida el catálogo cuando cambia el stock vía WooCommerce.
 */
function tavox_menu_api_invalidate_cache_from_product_object( $product ): void {
	if ( ! $product instanceof WC_Product ) {
		return;
	}

	tavox_menu_api_bump_cache_version();
}
add_action( 'woocommerce_product_set_stock', 'tavox_menu_api_invalidate_cache_from_product_object' );
add_action( 'woocommerce_variation_set_stock', 'tavox_menu_api_invalidate_cache_from_product_object' );

/**
 * Invalida el catálogo cuando cambian propiedades del producto.
 */
function tavox_menu_api_invalidate_cache_from_product_event(): void {
	tavox_menu_api_bump_cache_version();
}
add_action( 'woocommerce_update_product', 'tavox_menu_api_invalidate_cache_from_product_event' );
add_action( 'woocommerce_new_product', 'tavox_menu_api_invalidate_cache_from_product_event' );
add_action( 'woocommerce_delete_product_transients', 'tavox_menu_api_invalidate_cache_from_product_event' );

/**
 * Invalida el catálogo cuando cambia el stock status.
 */
function tavox_menu_api_invalidate_cache_from_stock_status(): void {
	tavox_menu_api_bump_cache_version();
}
add_action( 'woocommerce_product_set_stock_status', 'tavox_menu_api_invalidate_cache_from_stock_status', 10, 3 );
add_action( 'woocommerce_variation_set_stock_status', 'tavox_menu_api_invalidate_cache_from_stock_status', 10, 3 );

/**
 * Invalida el catálogo cuando cambia la relación producto/categoría.
 */
function tavox_menu_api_invalidate_cache_on_set_object_terms( int $object_id, $terms, $tt_ids, string $taxonomy ): void {
	if ( 'product_cat' !== $taxonomy || 'product' !== get_post_type( $object_id ) ) {
		return;
	}

	tavox_menu_api_bump_cache_version();
}
add_action( 'set_object_terms', 'tavox_menu_api_invalidate_cache_on_set_object_terms', 10, 4 );

/**
 * Invalida el catálogo cuando cambia una categoría de producto.
 */
function tavox_menu_api_invalidate_cache_on_product_cat_event(): void {
	tavox_menu_api_bump_cache_version();
}
add_action( 'created_product_cat', 'tavox_menu_api_invalidate_cache_on_product_cat_event' );
add_action( 'edited_product_cat', 'tavox_menu_api_invalidate_cache_on_product_cat_event' );
add_action( 'delete_product_cat', 'tavox_menu_api_invalidate_cache_on_product_cat_event' );

/**
 * Invalida el catálogo cuando cambian los grupos Tavox del producto.
 */
function tavox_menu_api_invalidate_cache_on_groups_meta_change( $meta_id, int $object_id, string $meta_key ): void {
	if ( '_tavox_groups' !== $meta_key || 'product' !== get_post_type( $object_id ) ) {
		return;
	}

	tavox_menu_api_bump_cache_version();
}
add_action( 'added_post_meta', 'tavox_menu_api_invalidate_cache_on_groups_meta_change', 10, 3 );
add_action( 'updated_post_meta', 'tavox_menu_api_invalidate_cache_on_groups_meta_change', 10, 3 );
add_action( 'deleted_post_meta', 'tavox_menu_api_invalidate_cache_on_groups_meta_change', 10, 3 );

/**
 * Invalida el catálogo cuando un producto es movido o eliminado.
 */
function tavox_menu_api_invalidate_cache_on_post_transition( int $post_id ): void {
	$post = get_post( $post_id );
	if ( ! $post || 'product' !== $post->post_type ) {
		return;
	}

	tavox_menu_api_bump_cache_version();
}
add_action( 'deleted_post', 'tavox_menu_api_invalidate_cache_on_post_transition' );
add_action( 'trashed_post', 'tavox_menu_api_invalidate_cache_on_post_transition' );
add_action( 'untrashed_post', 'tavox_menu_api_invalidate_cache_on_post_transition' );
