<?php

defined( 'ABSPATH' ) || exit;

/**
 * Devuelve la configuración completa de categorías del menú.
 *
 * @return array<int, array<string, mixed>>
 */
function tavox_menu_api_get_category_config(): array {
	$config = get_option( 'tavox_menu_cats', [] );
	$config = is_array( $config ) ? $config : [];

	return array_values(
		array_map(
			static function ( $item ): array {
				$item = is_array( $item ) ? $item : [];

				return [
					'id'      => absint( $item['id'] ?? 0 ),
					'enabled' => ! empty( $item['enabled'] ),
					'order'   => absint( $item['order'] ?? 0 ),
					'aliases' => tavox_menu_api_parse_search_aliases( $item['aliases'] ?? [] ),
					'menu_scope' => tavox_menu_api_sanitize_menu_scope( (string) ( $item['menu_scope'] ?? 'zona_b' ) ),
					'service_station' => tavox_menu_api_sanitize_service_station( (string) ( $item['service_station'] ?? 'auto' ) ),
				];
			},
			$config
		)
	);
}

/**
 * Sanitiza el alcance visual configurado para una categoría o promoción.
 */
function tavox_menu_api_sanitize_menu_scope( string $value, string $default = 'zona_b' ): string {
	$normalized = sanitize_key( $value );
	$allowed    = [ 'zona_b', 'isola', 'common' ];

	if ( in_array( $normalized, $allowed, true ) ) {
		return $normalized;
	}

	return in_array( $default, $allowed, true ) ? $default : 'zona_b';
}

/**
 * Sanitiza la estación operativa configurada para una categoría.
 */
function tavox_menu_api_get_production_station_values(): array {
	return [ 'kitchen', 'bar', 'horno' ];
}

/**
 * Devuelve todas las estaciones operativas soportadas por Tavox.
 *
 * @return array<int, string>
 */
function tavox_menu_api_get_supported_service_stations( bool $include_auto = true ): array {
	$stations = tavox_menu_api_get_production_station_values();

	return $include_auto ? array_merge( [ 'auto' ], $stations ) : $stations;
}

/**
 * Sanitiza una estación operativa real de producción.
 */
function tavox_menu_api_sanitize_production_station( string $value, string $default = 'kitchen' ): string {
	$normalized = sanitize_key( $value );
	$allowed    = tavox_menu_api_get_production_station_values();

	if ( in_array( $normalized, $allowed, true ) ) {
		return $normalized;
	}

	return in_array( $default, $allowed, true ) ? $default : 'kitchen';
}

/**
 * Devuelve la etiqueta visible de una estación.
 */
function tavox_menu_api_get_service_station_label( string $value ): string {
	$station = tavox_menu_api_sanitize_production_station( $value, 'kitchen' );

	if ( 'bar' === $station ) {
		return __( 'Barra', 'tavox-menu-api' );
	}

	if ( 'horno' === $station ) {
		return __( 'Horno', 'tavox-menu-api' );
	}

	return __( 'Cocina', 'tavox-menu-api' );
}

/**
 * Devuelve la ruta del frontend del equipo para una estación.
 */
function tavox_menu_api_get_service_station_frontend_path( string $value ): string {
	$station = tavox_menu_api_sanitize_production_station( $value, 'kitchen' );

	if ( 'bar' === $station ) {
		return '/equipo/barra';
	}

	if ( 'horno' === $station ) {
		return '/equipo/horno';
	}

	return '/equipo/cocina';
}

/**
 * Registra Horno como tercera área nativa de restaurante en OpenPOS.
 *
 * @param array<string, array<string, string>> $areas
 * @return array<string, array<string, string>>
 */
function tavox_menu_api_extend_openpos_restaurant_areas( array $areas ): array {
	if ( isset( $areas['horno'] ) ) {
		return $areas;
	}

	$extended = [];

	foreach ( $areas as $key => $area ) {
		$extended[ $key ] = $area;

		if ( 'cook' === sanitize_key( (string) ( $area['code'] ?? $key ) ) ) {
			$extended['horno'] = [
				'code'        => 'horno',
				'label'       => __( 'Horno', 'tavox-menu-api' ),
				'description' => __( 'Display on Oven View', 'tavox-menu-api' ),
			];
		}
	}

	if ( ! isset( $extended['horno'] ) ) {
		$extended['horno'] = [
			'code'        => 'horno',
			'label'       => __( 'Horno', 'tavox-menu-api' ),
			'description' => __( 'Display on Oven View', 'tavox-menu-api' ),
		];
	}

	return $extended;
}
add_filter( 'op_list_restaurant_area', 'tavox_menu_api_extend_openpos_restaurant_areas' );

/**
 * Sanitiza la estación operativa configurada para una categoría.
 */
function tavox_menu_api_sanitize_service_station( string $value, string $default = 'auto' ): string {
	$normalized = sanitize_key( $value );
	$allowed    = tavox_menu_api_get_supported_service_stations( true );

	if ( in_array( $normalized, $allowed, true ) ) {
		return $normalized;
	}

	return in_array( $default, $allowed, true ) ? $default : 'auto';
}

/**
 * Sanitiza el modo de entrega operativo de una línea o pedido.
 */
function tavox_menu_api_sanitize_fulfillment_mode( string $value, string $default = 'dine_in' ): string {
	$normalized = sanitize_key( $value );
	$allowed    = [ 'dine_in', 'takeaway' ];

	if ( in_array( $normalized, $allowed, true ) ) {
		return $normalized;
	}

	return in_array( $default, $allowed, true ) ? $default : 'dine_in';
}

/**
 * Devuelve la etiqueta visible del modo de entrega.
 */
function tavox_menu_api_get_fulfillment_mode_label( string $value ): string {
	return 'takeaway' === tavox_menu_api_sanitize_fulfillment_mode( $value )
		? __( 'Para llevar', 'tavox-menu-api' )
		: __( 'Mesa', 'tavox-menu-api' );
}

/**
 * Sanitiza un número de WhatsApp para uso en enlaces wa/api.
 */
function tavox_menu_api_sanitize_whatsapp_phone( string $value ): string {
	$digits = preg_replace( '/\D+/', '', $value );

	return is_string( $digits ) ? $digits : '';
}

/**
 * Sanitiza una URL de frontend manteniendo sólo http/https.
 */
function tavox_menu_api_sanitize_frontend_url( string $value ): string {
	$url = esc_url_raw( trim( $value ), [ 'http', 'https' ] );

	return is_string( $url ) ? $url : '';
}

/**
 * Sanitiza la URL del socket realtime.
 */
function tavox_menu_api_sanitize_realtime_socket_url( string $value ): string {
	$url = esc_url_raw( trim( $value ), [ 'ws', 'wss', 'http', 'https' ] );

	return is_string( $url ) ? $url : '';
}

/**
 * Sanitiza la URL interna de publicación hacia el sidecar realtime.
 */
function tavox_menu_api_sanitize_realtime_publish_url( string $value ): string {
	$url = esc_url_raw( trim( $value ), [ 'http', 'https' ] );

	return is_string( $url ) ? $url : '';
}

/**
 * Normaliza el secreto compartido del sidecar realtime.
 */
function tavox_menu_api_sanitize_realtime_secret( string $value ): string {
	$secret = preg_replace( '/[^A-Za-z0-9]/', '', trim( $value ) );

	return is_string( $secret ) ? $secret : '';
}

/**
 * Genera un secreto compartido para el sidecar realtime.
 */
function tavox_menu_api_generate_realtime_secret(): string {
	return tavox_menu_api_sanitize_realtime_secret( wp_generate_password( 64, false, false ) );
}

/**
 * Sanitiza un entero positivo acotado.
 */
function tavox_menu_api_sanitize_positive_int( $value, int $default, int $min = 1, int $max = 86400 ): int {
	$number = is_numeric( $value ) ? (int) $value : $default;
	$number = max( $min, min( $max, $number ) );

	return $number;
}

/**
 * Registra eventos operativos puntuales cuando WP_DEBUG_LOG está activo.
 *
 * @param array<string, mixed> $context
 */
function tavox_menu_api_log_operational_event( string $event, array $context = [] ): void {
	if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
		return;
	}

	$event = preg_replace( '/[^a-z0-9._-]+/i', '_', trim( $event ) );
	$event = is_string( $event ) ? trim( $event, '_' ) : '';

	if ( '' === $event ) {
		return;
	}

	$encoded = wp_json_encode(
		is_array( $context ) ? $context : [],
		JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
	);
	if ( false === $encoded ) {
		$encoded = '{}';
	}

	error_log( sprintf( '[Tavox Menu API] %s %s', $event, $encoded ) );
}

/**
 * Devuelve la configuración general del plugin.
 *
 * @return array<string, mixed>
 */
function tavox_menu_api_get_settings(): array {
	$settings = get_option( 'tavox_menu_settings', [] );
	$settings = is_array( $settings ) ? $settings : [];

	return tavox_menu_api_sanitize_settings_payload( $settings );
}

/**
 * Normaliza y sanitiza la configuración general del plugin.
 *
 * @param array<string, mixed> $settings Valores crudos.
 * @return array<string, mixed>
 */
function tavox_menu_api_sanitize_settings_payload( array $settings ): array {
	$sanitized = [
		'whatsapp_phone'            => tavox_menu_api_sanitize_whatsapp_phone( (string) ( $settings['whatsapp_phone'] ?? '' ) ),
		'multi_menu_enabled'        => ! empty( $settings['multi_menu_enabled'] ),
		'table_order_enabled'       => ! empty( $settings['table_order_enabled'] ),
		'waiter_console_enabled'    => ! empty( $settings['waiter_console_enabled'] ),
		'shared_tables_enabled'     => ! empty( $settings['shared_tables_enabled'] ),
		'realtime_enabled'          => ! empty( $settings['realtime_enabled'] ),
		'realtime_socket_url'       => tavox_menu_api_sanitize_realtime_socket_url( (string) ( $settings['realtime_socket_url'] ?? '' ) ),
		'realtime_publish_url'      => tavox_menu_api_sanitize_realtime_publish_url( (string) ( $settings['realtime_publish_url'] ?? '' ) ),
		'realtime_shared_secret'    => tavox_menu_api_sanitize_realtime_secret( (string) ( $settings['realtime_shared_secret'] ?? '' ) ),
		'menu_frontend_url'         => tavox_menu_api_sanitize_frontend_url( (string) ( $settings['menu_frontend_url'] ?? '' ) ),
		'wifi_name'                 => sanitize_text_field( (string) ( $settings['wifi_name'] ?? '' ) ),
		'wifi_password'             => sanitize_text_field( (string) ( $settings['wifi_password'] ?? '' ) ),
		'wifi_label'                => sanitize_text_field( (string) ( $settings['wifi_label'] ?? '' ) ),
		'request_hold_minutes'      => tavox_menu_api_sanitize_positive_int( $settings['request_hold_minutes'] ?? 15, 15, 1, 240 ),
		'claim_timeout_seconds'     => tavox_menu_api_sanitize_positive_int( $settings['claim_timeout_seconds'] ?? 90, 90, 15, 3600 ),
		'session_idle_timeout_minutes' => tavox_menu_api_sanitize_positive_int( $settings['session_idle_timeout_minutes'] ?? 120, 120, 15, 720 ),
		'notification_sound_enabled'=> array_key_exists( 'notification_sound_enabled', $settings ) ? ! empty( $settings['notification_sound_enabled'] ) : true,
		'push_notifications_enabled'=> array_key_exists( 'push_notifications_enabled', $settings ) ? ! empty( $settings['push_notifications_enabled'] ) : true,
		'push_vapid_subject'        => tavox_menu_api_sanitize_push_subject( (string) ( $settings['push_vapid_subject'] ?? '' ) ),
		'push_vapid_public_key'     => sanitize_text_field( (string) ( $settings['push_vapid_public_key'] ?? '' ) ),
		'push_vapid_private_key'    => preg_replace( '/[^A-Za-z0-9+\/=\r\n]/', '', (string) ( $settings['push_vapid_private_key'] ?? '' ) ),
	];

	return tavox_menu_api_prepare_realtime_settings( tavox_menu_api_prepare_push_settings( $sanitized, false ), false );
}

/**
 * Nombre completo de la tabla de solicitudes.
 */
function tavox_menu_api_get_table_requests_table_name(): string {
	global $wpdb;

	return $wpdb->prefix . 'tavox_table_requests';
}

/**
 * Nombre completo de la tabla de sesiones de mesero.
 */
function tavox_menu_api_get_waiter_sessions_table_name(): string {
	global $wpdb;

	return $wpdb->prefix . 'tavox_waiter_sessions';
}

/**
 * Nombre completo de la tabla de mensajes breves entre mesa y equipo.
 */
function tavox_menu_api_get_table_messages_table_name(): string {
	global $wpdb;

	return $wpdb->prefix . 'tavox_table_messages';
}

/**
 * Devuelve el tiempo máximo de inactividad permitido para una sesión del equipo.
 */
function tavox_menu_api_get_waiter_session_idle_timeout_minutes(): int {
	$settings = tavox_menu_api_get_settings();

	return max( 15, (int) ( $settings['session_idle_timeout_minutes'] ?? 120 ) );
}

/**
 * Devuelve el tiempo máximo de inactividad permitido para una sesión del equipo en segundos.
 */
function tavox_menu_api_get_waiter_session_idle_timeout_seconds(): int {
	return tavox_menu_api_get_waiter_session_idle_timeout_minutes() * MINUTE_IN_SECONDS;
}

/**
 * Completa la configuración realtime del plugin.
 *
 * @param array<string, mixed> $settings Ajustes existentes.
 * @return array<string, mixed>
 */
function tavox_menu_api_prepare_realtime_settings( array $settings, bool $generate_missing = false ): array {
	$enabled    = ! empty( $settings['realtime_enabled'] );
	$socket_url = tavox_menu_api_sanitize_realtime_socket_url( (string) ( $settings['realtime_socket_url'] ?? '' ) );
	$publish_url = tavox_menu_api_sanitize_realtime_publish_url( (string) ( $settings['realtime_publish_url'] ?? '' ) );
	$secret     = tavox_menu_api_sanitize_realtime_secret( (string) ( $settings['realtime_shared_secret'] ?? '' ) );

	if ( $generate_missing && '' === $secret ) {
		$secret = tavox_menu_api_generate_realtime_secret();
	}

	$settings['realtime_enabled']       = $enabled;
	$settings['realtime_socket_url']    = $socket_url;
	$settings['realtime_publish_url']   = $publish_url;
	$settings['realtime_shared_secret'] = $secret;

	return $settings;
}

/**
 * Indica si el sidecar realtime está listo para usarse.
 */
function tavox_menu_api_is_realtime_enabled(): bool {
	$settings = tavox_menu_api_get_settings();

	return ! empty( $settings['realtime_enabled'] )
		&& '' !== (string) ( $settings['realtime_socket_url'] ?? '' )
		&& '' !== (string) ( $settings['realtime_publish_url'] ?? '' )
		&& '' !== (string) ( $settings['realtime_shared_secret'] ?? '' );
}

/**
 * Devuelve la URL pública del socket realtime.
 */
function tavox_menu_api_get_realtime_socket_url(): string {
	$settings = tavox_menu_api_get_settings();

	return (string) ( $settings['realtime_socket_url'] ?? '' );
}

/**
 * Devuelve la URL interna usada para publicar invalidaciones realtime.
 */
function tavox_menu_api_get_realtime_publish_url(): string {
	$settings = tavox_menu_api_get_settings();

	return (string) ( $settings['realtime_publish_url'] ?? '' );
}

/**
 * Devuelve el secreto compartido del sidecar realtime.
 */
function tavox_menu_api_get_realtime_shared_secret(): string {
	$settings = tavox_menu_api_get_settings();

	return (string) ( $settings['realtime_shared_secret'] ?? '' );
}

/**
 * Devuelve la configuración realtime visible para el frontend del equipo.
 *
 * @return array<string, mixed>
 */
function tavox_menu_api_get_waiter_realtime_config(): array {
	if ( ! tavox_menu_api_is_realtime_enabled() ) {
		return [
			'enabled'          => false,
			'socket_url'       => '',
			'fallback_poll_ms' => 20000,
		];
	}

	return [
		'enabled'          => true,
		'socket_url'       => tavox_menu_api_get_realtime_socket_url(),
		'fallback_poll_ms' => 20000,
	];
}

/**
 * Publica un evento de invalidación hacia el sidecar realtime.
 *
 * @param array<string, mixed> $payload
 */
function tavox_menu_api_publish_realtime_event( array $payload ): bool {
	if ( ! tavox_menu_api_is_realtime_enabled() ) {
		return false;
	}

	$publish_url = tavox_menu_api_get_realtime_publish_url();
	$secret      = tavox_menu_api_get_realtime_shared_secret();
	$event_raw   = preg_replace( '/[^A-Za-z0-9._-]/', '', (string) ( $payload['event'] ?? '' ) );
	$event       = is_string( $event_raw ) ? strtolower( $event_raw ) : '';
	$targets_raw = is_array( $payload['targets'] ?? null ) ? $payload['targets'] : [];
	$targets     = array_values(
		array_unique(
			array_filter(
				array_map(
					static function ( $target ): string {
						return sanitize_text_field( (string) $target );
					},
					$targets_raw
				)
			)
		)
	);

	if ( '' === $publish_url || '' === $secret || '' === $event || empty( $targets ) ) {
		return false;
	}

	$body = [
		'event'      => $event,
		'targets'    => $targets,
		'table_token'=> sanitize_text_field( (string) ( $payload['table_token'] ?? '' ) ),
		'scope'      => sanitize_key( (string) ( $payload['scope'] ?? '' ) ),
		'waiter_user_id' => absint( $payload['waiter_user_id'] ?? 0 ),
		'changed_at' => sanitize_text_field( (string) ( $payload['changed_at'] ?? tavox_menu_api_now_mysql() ) ),
		'meta'       => is_array( $payload['meta'] ?? null ) ? $payload['meta'] : [],
	];

	$response = wp_remote_post(
		$publish_url,
		[
			'timeout'     => 1,
			'redirection' => 0,
			'blocking'    => true,
			'headers'     => [
				'Content-Type'            => 'application/json',
				'X-Tavox-Realtime-Secret' => $secret,
			],
			'body'        => wp_json_encode( $body ),
		]
	);

	if ( is_wp_error( $response ) ) {
		error_log( '[Tavox Menu API] realtime publish failed: ' . $response->get_error_message() );
		return false;
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	if ( $status >= 400 ) {
		error_log( '[Tavox Menu API] realtime publish failed with status ' . $status );
		return false;
	}

	return true;
}

/**
 * Devuelve una respuesta REST marcada para no reutilizar contenido operativo desde caché.
 *
 * @param mixed $data Payload final de la respuesta.
 */
function tavox_menu_api_no_store_rest_response( $data ): WP_REST_Response {
	$response = rest_ensure_response( $data );

	if ( $response instanceof WP_REST_Response ) {
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Expires', '0' );
		$response->header( 'Vary', 'Authorization, Cookie' );
	}

	return $response;
}

/**
 * Indica si varias personas del equipo pueden atender la misma cuenta.
 */
function tavox_menu_api_are_shared_tables_enabled(): bool {
	$settings = tavox_menu_api_get_settings();

	return ! empty( $settings['shared_tables_enabled'] );
}

/**
 * Devuelve un mapa de configuración por category_id.
 *
 * @return array<int, array<string, mixed>>
 */
function tavox_menu_api_get_category_config_map(): array {
	$map = [];

	foreach ( tavox_menu_api_get_category_config() as $item ) {
		$category_id = absint( $item['id'] ?? 0 );
		if ( $category_id <= 0 ) {
			continue;
		}

		$map[ $category_id ] = $item;
	}

	return $map;
}

/**
 * Normaliza una lista de aliases/coincidencias de búsqueda.
 *
 * @param string|array<int, string> $value Valor crudo desde admin o config.
 * @return array<int, string>
 */
function tavox_menu_api_parse_search_aliases( $value ): array {
	$raw_items = [];

	if ( is_array( $value ) ) {
		$raw_items = $value;
	} else {
		$raw_items = preg_split( '/[\r\n,;]+/', (string) $value ) ?: [];
	}

	$aliases = [];
	foreach ( $raw_items as $raw_item ) {
		$alias = sanitize_text_field( trim( (string) $raw_item ) );
		if ( '' === $alias ) {
			continue;
		}

		$aliases[ tavox_menu_api_normalize_text( $alias ) ] = $alias;
	}

	return array_values( $aliases );
}

/**
 * Devuelve sólo las categorías activas ordenadas.
 *
 * @return array<int, array<string, mixed>>
 */
function tavox_get_active_categories(): array {
	$config = tavox_menu_api_get_category_config();

	usort(
		$config,
		static function ( array $a, array $b ): int {
			return (int) ( $a['order'] ?? 0 ) <=> (int) ( $b['order'] ?? 0 );
		}
	);

	return array_values(
		array_filter(
			$config,
			static fn( array $item ): bool => ! empty( $item['enabled'] ) && ! empty( $item['id'] )
		)
	);
}

/**
 * Resuelve la marca visual efectiva de un producto según sus categorías de menú.
 */
function tavox_menu_api_get_brand_scope_for_menu_categories( array $menu_category_ids ): string {
	if ( empty( $menu_category_ids ) ) {
		return 'zona_b';
	}

	$config_map = tavox_menu_api_get_category_config_map();
	$fallback   = 'common';

	foreach ( $menu_category_ids as $category_id ) {
		$scope = tavox_menu_api_sanitize_menu_scope( (string) ( $config_map[ (int) $category_id ]['menu_scope'] ?? 'zona_b' ) );
		if ( 'common' !== $scope ) {
			return $scope;
		}

		$fallback = 'common';
	}

	return $fallback;
}

/**
 * Resuelve la estación configurada para un producto según sus categorías visibles.
 */
function tavox_menu_api_get_service_station_for_product( int $product_id ): string {
	static $cache = [];

	$product_id = absint( $product_id );
	if ( $product_id < 1 ) {
		return 'auto';
	}

	if ( isset( $cache[ $product_id ] ) ) {
		return $cache[ $product_id ];
	}

	$product = wc_get_product( $product_id );
	if ( ! $product instanceof WC_Product ) {
		$cache[ $product_id ] = 'auto';
		return 'auto';
	}

	$menu_category_ids = tavox_menu_api_get_menu_category_ids_for_product( $product, tavox_menu_api_get_active_category_ids() );
	$config_map        = tavox_menu_api_get_category_config_map();

	foreach ( $menu_category_ids as $category_id ) {
		$station = tavox_menu_api_sanitize_service_station( (string) ( $config_map[ (int) $category_id ]['service_station'] ?? 'auto' ) );
		if ( 'auto' !== $station ) {
			$cache[ $product_id ] = $station;
			return $station;
		}
	}

	$cache[ $product_id ] = 'auto';
	return 'auto';
}

/**
 * Lee la estación operativa real configurada en OpenPOS para un producto.
 *
 * Si el producto no tiene una estación marcada en OpenPOS devuelve cadena vacía.
 * Si el producto quedó marcado en más de una área, la cocina tiene prioridad
 * porque la shell moderna todavía trabaja con una sola estación visible por línea.
 */
function tavox_menu_api_get_openpos_product_station( int $product_id ): string {
	static $cache = [];

	$product_id = absint( $product_id );
	if ( $product_id < 1 ) {
		return '';
	}

	if ( isset( $cache[ $product_id ] ) ) {
		return $cache[ $product_id ];
	}

	$post = get_post( $product_id );
	if ( $post && ! empty( $post->post_parent ) ) {
		$product_id = absint( $post->post_parent );
	}

	$areas = [];
	if ( isset( $GLOBALS['op_woo'] ) && is_object( $GLOBALS['op_woo'] ) && method_exists( $GLOBALS['op_woo'], 'getListRestaurantArea' ) ) {
		$areas = (array) $GLOBALS['op_woo']->getListRestaurantArea();
	}

	if ( empty( $areas ) ) {
		$areas = [
			'cook'  => [ 'code' => 'cook' ],
			'drink' => [ 'code' => 'drink' ],
		];
	}

	$has_kitchen_area = false;
	$has_bar_area     = false;
	$has_horno_area   = false;

	foreach ( $areas as $area_key => $area ) {
		$code = sanitize_key( (string) ( $area['code'] ?? $area_key ) );
		if ( '' === $code ) {
			continue;
		}

		$enabled = sanitize_text_field( (string) get_post_meta( $product_id, '_op_' . $code, true ) );
		if ( 'yes' !== $enabled ) {
			continue;
		}

		if ( in_array( $code, [ 'drink', 'bar', 'beverage', 'beverages', 'cocktail', 'cocktails' ], true ) ) {
			$has_bar_area = true;
		} elseif ( in_array( $code, [ 'horno', 'oven' ], true ) ) {
			$has_horno_area = true;
		} else {
			$has_kitchen_area = true;
		}
	}

	if ( $has_horno_area ) {
		$cache[ $product_id ] = 'horno';
		return $cache[ $product_id ];
	}

	if ( $has_kitchen_area ) {
		$cache[ $product_id ] = 'kitchen';
		return $cache[ $product_id ];
	}

	if ( $has_bar_area ) {
		$cache[ $product_id ] = 'bar';
		return $cache[ $product_id ];
	}

	$cache[ $product_id ] = '';
	return '';
}

/**
 * Devuelve los IDs activos del menú en el orden configurado.
 *
 * @return array<int>
 */
function tavox_menu_api_get_active_category_ids(): array {
	return array_map(
		'absint',
		wp_list_pluck( tavox_get_active_categories(), 'id' )
	);
}

/**
 * Devuelve las categorías activas como mapa term_id => WP_Term.
 *
 * @return array<int, WP_Term>
 */
function tavox_menu_api_get_active_category_terms(): array {
	$terms = [];

	foreach ( tavox_get_active_categories() as $item ) {
		$term = get_term( (int) $item['id'], 'product_cat' );
		if ( ! $term || is_wp_error( $term ) ) {
			continue;
		}

		$terms[ (int) $term->term_id ] = $term;
	}

	return $terms;
}

/**
 * Devuelve un mapa category_id => términos de búsqueda de categoría.
 *
 * @return array<int, array<string, mixed>>
 */
function tavox_menu_api_get_active_category_search_terms( array $active_terms_by_id ): array {
	$config_map = [];
	foreach ( tavox_get_active_categories() as $item ) {
		$config_map[ (int) ( $item['id'] ?? 0 ) ] = $item;
	}

	$search_terms = [];
	foreach ( $active_terms_by_id as $category_id => $term ) {
		$aliases           = tavox_menu_api_parse_search_aliases( $config_map[ $category_id ]['aliases'] ?? [] );
		$normalized_terms  = [];
		$raw_search_values = array_merge( [ html_entity_decode( $term->name ) ], $aliases );

		foreach ( $raw_search_values as $value ) {
			$normalized = tavox_menu_api_normalize_text( (string) $value );
			if ( '' === $normalized ) {
				continue;
			}

			$normalized_terms[ $normalized ] = $normalized;
		}

		$search_terms[ (int) $category_id ] = [
			'name'       => html_entity_decode( $term->name ),
			'aliases'    => $aliases,
			'normalized' => array_values( $normalized_terms ),
		];
	}

	return $search_terms;
}

/**
 * Normaliza texto para búsquedas robustas.
 */
function tavox_menu_api_normalize_text( string $value ): string {
	$value = html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$value = remove_accents( $value );

	if ( function_exists( 'mb_strtolower' ) ) {
		$value = mb_strtolower( $value, 'UTF-8' );
	} else {
		$value = strtolower( $value );
	}

	$value = preg_replace( '/[^a-z0-9\s]+/u', ' ', $value );
	$value = preg_replace( '/\s+/u', ' ', trim( (string) $value ) );

	return (string) $value;
}

/**
 * Resuelve una categoría pedida por ID o slug contra el menú activo.
 */
function tavox_menu_api_resolve_requested_category( string $raw_category, array $active_terms_by_id ): array {
	$raw_category = trim( $raw_category );
	if ( '' === $raw_category || '0' === $raw_category ) {
		return array_keys( $active_terms_by_id );
	}

	if ( is_numeric( $raw_category ) ) {
		$category_id = (int) $raw_category;

		return isset( $active_terms_by_id[ $category_id ] ) ? [ $category_id ] : [];
	}

	foreach ( $active_terms_by_id as $term ) {
		if ( $term->slug === $raw_category ) {
			return [ (int) $term->term_id ];
		}
	}

	return [];
}

/**
 * Calcula las categorías de menú visibles para un producto.
 *
 * Si el producto no pertenece directamente a una categoría visible,
 * intenta usar ancestros visibles como fallback editorial.
 *
 * @return array<int>
 */
function tavox_menu_api_get_menu_category_ids_for_product( WC_Product $product, array $active_category_ids ): array {
	$product_category_ids = array_map( 'intval', $product->get_category_ids() );
	$menu_category_ids    = [];

	foreach ( $active_category_ids as $active_id ) {
		if ( in_array( $active_id, $product_category_ids, true ) ) {
			$menu_category_ids[] = (int) $active_id;
		}
	}

	if ( ! empty( $menu_category_ids ) ) {
		return $menu_category_ids;
	}

	foreach ( $product_category_ids as $category_id ) {
		$ancestors = array_map( 'intval', get_ancestors( $category_id, 'product_cat', 'taxonomy' ) );
		foreach ( $active_category_ids as $active_id ) {
			if ( in_array( $active_id, $ancestors, true ) ) {
				$menu_category_ids[] = (int) $active_id;
			}
		}
	}

	return array_values( array_unique( $menu_category_ids ) );
}

/**
 * Devuelve la categoría primaria del menú para un producto.
 */
function tavox_menu_api_get_primary_menu_category_id( array $menu_category_ids ): int {
	return empty( $menu_category_ids ) ? 0 : (int) reset( $menu_category_ids );
}

/**
 * Verifica si un producto hace match con la búsqueda normalizada.
 */
function tavox_menu_api_product_matches_search( WC_Product $product, array $menu_category_ids, array $category_search_terms_by_id, string $normalized_query ): bool {
	if ( '' === $normalized_query ) {
		return true;
	}

	$pieces   = [ tavox_menu_api_normalize_text( $product->get_name() ) ];
	$catnames = [];

	foreach ( $menu_category_ids as $category_id ) {
		if ( isset( $category_search_terms_by_id[ $category_id ]['normalized'] ) ) {
			$catnames[] = implode( ' ', (array) $category_search_terms_by_id[ $category_id ]['normalized'] );
		}
	}

	$pieces[] = implode( ' ', $catnames );
	$haystack = trim( implode( ' ', array_filter( $pieces ) ) );
	$tokens   = array_filter( explode( ' ', $normalized_query ) );

	foreach ( $tokens as $token ) {
		if ( false === strpos( $haystack, $token ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Decodifica la meta _tavox_groups.
 *
 * @return array<int, array<string, mixed>>
 */
function tavox_menu_api_decode_groups( $raw_groups ): array {
	if ( empty( $raw_groups ) ) {
		return [];
	}

	$decoded = maybe_unserialize( $raw_groups );
	if ( is_string( $decoded ) ) {
		$decoded = json_decode( $decoded, true );
	}

	return is_array( $decoded ) ? $decoded : [];
}

/**
 * Normaliza la definición de extras y añade identificadores estables.
 *
 * @return array<int, array<string, mixed>>
 */
function tavox_menu_api_map_extras( array $decoded_groups ): array {
	$extras = [];

	foreach ( array_values( $decoded_groups ) as $group_index => $group ) {
		if ( ! is_array( $group ) ) {
			continue;
		}

		$is_legacy = isset( $group['options'] );
		$group_id  = ! empty( $group['group_id'] )
			? sanitize_key( (string) $group['group_id'] )
			: 'group_' . $group_index;
		$label     = $is_legacy
			? ( isset( $group['label'] ) ? wp_kses_data( (string) $group['label'] ) : '' )
			: ( ! empty( $group['show_title'] ) ? wp_kses_data( (string) ( $group['group_title'] ?? '' ) ) : '' );
		$multiple  = ! empty( $group['multiple'] );
		$options   = [];
		$raw_items = $is_legacy ? (array) $group['options'] : (array) ( $group['items'] ?? [] );

		foreach ( array_values( $raw_items ) as $option_index => $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}

			$option_id = ! empty( $option['option_id'] )
				? sanitize_key( (string) $option['option_id'] )
				: ( ! empty( $option['id'] ) ? sanitize_key( (string) $option['id'] ) : $group_id . '_option_' . $option_index );
			$options[] = [
				'id'        => $option_id,
				'option_id' => $option_id,
				'group_id'  => $group_id,
				'label'     => isset( $option['label'] ) ? wp_kses_data( (string) $option['label'] ) : '',
				'price'     => isset( $option['price'] ) ? (float) $option['price'] : 0,
			];
		}

		$extras[] = [
			'group_id'  => $group_id,
			'label'     => $label,
			'multiple'  => (bool) $multiple,
			'options'   => $options,
		];
	}

	return $extras;
}

/**
 * Devuelve la configuración de promociones ordenada.
 *
 * @return array<int, array<string, mixed>>
 */
function tavox_menu_api_get_promotions_config(): array {
	$config = get_option( 'tavox_menu_promotions', [] );
	$config = is_array( $config ) ? $config : [];

	$config = array_map(
		static function ( $item ): array {
			$item = is_array( $item ) ? $item : [];

			return [
				'product_id'      => absint( $item['product_id'] ?? 0 ),
				'enabled'         => ! empty( $item['enabled'] ),
				'show_in_search'  => array_key_exists( 'show_in_search', $item ) ? ! empty( $item['show_in_search'] ) : true,
				'order'           => absint( $item['order'] ?? 0 ),
				'promo_style'     => ! empty( $item['promo_style'] ) ? sanitize_key( (string) $item['promo_style'] ) : 'default',
				'brand_scope'     => tavox_menu_api_sanitize_menu_scope( (string) ( $item['brand_scope'] ?? 'zona_b' ) ),
				'badge'           => sanitize_text_field( (string) ( $item['badge'] ?? '' ) ),
				'title'           => sanitize_text_field( (string) ( $item['title'] ?? '' ) ),
				'copy'            => sanitize_textarea_field( (string) ( $item['copy'] ?? '' ) ),
				'event_meta'      => sanitize_text_field( (string) ( $item['event_meta'] ?? '' ) ),
				'event_guests'    => sanitize_text_field( (string) ( $item['event_guests'] ?? '' ) ),
				'image'           => esc_url_raw( (string) ( $item['image'] ?? '' ) ),
				'image_focus_x'   => tavox_menu_api_normalize_focus_value( $item['image_focus_x'] ?? 50 ),
				'image_focus_y'   => tavox_menu_api_normalize_focus_value( $item['image_focus_y'] ?? 50 ),
				'starts_at'       => sanitize_text_field( (string) ( $item['starts_at'] ?? '' ) ),
				'ends_at'         => sanitize_text_field( (string) ( $item['ends_at'] ?? '' ) ),
			];
		},
		$config
	);

	usort(
		$config,
		static function ( array $a, array $b ): int {
			return (int) ( $a['order'] ?? 0 ) <=> (int) ( $b['order'] ?? 0 );
		}
	);

	return $config;
}

/**
 * Normaliza un porcentaje de encuadre entre 0 y 100.
 */
function tavox_menu_api_normalize_focus_value( $value ): int {
	$number = is_numeric( $value ) ? (float) $value : 50;
	$number = max( 0, min( 100, $number ) );

	return (int) round( $number );
}

/**
 * Indica si un producto es válido para promocionarse.
 *
 * Si el producto maneja stock, debe tener disponibilidad real.
 *
 * @return array<string, mixed>
 */
function tavox_menu_api_get_product_promotion_availability( WC_Product $product ): array {
	$manages_stock = (bool) $product->get_manage_stock();
	$stock_qty_raw = $product->get_stock_quantity();
	$stock_qty     = is_numeric( $stock_qty_raw ) ? (int) $stock_qty_raw : null;
	$is_in_stock   = (bool) $product->is_in_stock();
	$is_available  = true;

	if ( $manages_stock ) {
		$is_available = $is_in_stock;

		if ( null !== $stock_qty && $stock_qty <= 0 ) {
			$is_available = false;
		}
	}

	return [
		'manages_stock'         => $manages_stock,
		'is_in_stock'           => $is_in_stock,
		'stock_qty'             => $stock_qty,
		'promotion_available'   => $is_available,
	];
}

/**
 * Determina si un producto puede usarse en una promoción.
 */
function tavox_menu_api_is_product_available_for_promotion( WC_Product $product ): bool {
	$availability = tavox_menu_api_get_product_promotion_availability( $product );

	return ! empty( $availability['promotion_available'] );
}

/**
 * Determina si una promoción está activa en este momento.
 */
function tavox_menu_api_is_promotion_active( array $promotion ): bool {
	if ( empty( $promotion['enabled'] ) ) {
		return false;
	}

	$promotion_style = ! empty( $promotion['promo_style'] ) ? sanitize_key( (string) $promotion['promo_style'] ) : 'default';
	$product_id      = absint( $promotion['product_id'] ?? 0 );

	if ( $product_id <= 0 && 'event' !== $promotion_style ) {
		return false;
	}

	$now = current_time( 'timestamp' );

	if ( ! empty( $promotion['starts_at'] ) ) {
		$starts_at = strtotime( (string) $promotion['starts_at'] );
		if ( false !== $starts_at && $starts_at > $now ) {
			return false;
		}
	}

	if ( ! empty( $promotion['ends_at'] ) ) {
		$ends_at = strtotime( (string) $promotion['ends_at'] );
		if ( false !== $ends_at && $ends_at < $now ) {
			return false;
		}
	}

	return true;
}

/**
 * Devuelve productos publicados para selects del admin.
 *
 * @return array<int, array<string, mixed>>
 */
function tavox_menu_api_get_admin_product_choices(): array {
	$product_ids = wc_get_products(
		[
			'status'  => 'publish',
			'orderby' => 'title',
			'order'   => 'ASC',
			'limit'   => -1,
			'return'  => 'ids',
		]
	);

	$choices = [];
	foreach ( $product_ids as $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			continue;
		}

		$availability = tavox_menu_api_get_product_promotion_availability( $product );
		$image_url    = '';
		$thumb_id     = $product->get_image_id();

		if ( $thumb_id ) {
			$image_url = wp_get_attachment_image_url( $thumb_id, 'medium_large' );
		}

		$choices[] = [
			'id'                  => (int) $product->get_id(),
			'name'                => html_entity_decode( $product->get_name() ),
			'image'               => $image_url,
			'brand_scope'         => tavox_menu_api_get_brand_scope_for_menu_categories(
				tavox_menu_api_get_menu_category_ids_for_product( $product, tavox_menu_api_get_active_category_ids() )
			),
			'manages_stock'       => (bool) $availability['manages_stock'],
			'is_in_stock'         => (bool) $availability['is_in_stock'],
			'stock_qty'           => $availability['stock_qty'],
			'promotion_available' => (bool) $availability['promotion_available'],
		];
	}

	return $choices;
}

/**
 * Normaliza un valor datetime-local para el input del admin.
 */
function tavox_menu_api_prepare_datetime_local_input( string $value ): string {
	if ( '' === $value ) {
		return '';
	}

	$timestamp = strtotime( $value );
	if ( false === $timestamp ) {
		return sanitize_text_field( $value );
	}

	return wp_date( 'Y-m-d\TH:i', $timestamp );
}
