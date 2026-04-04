<?php

defined( 'ABSPATH' ) || exit;

/**
 * Verifica si OpenPOS está cargado en el runtime actual.
 */
function tavox_menu_api_is_openpos_ready(): bool {
	return class_exists( 'OP_Table' ) || class_exists( 'Openpos_Front' ) || isset( $GLOBALS['op_table'] );
}

/**
 * Intenta asegurar que los servicios base de OpenPOS existan en globals.
 */
function tavox_menu_api_boot_openpos_services(): void {
	global $op_table, $op_warehouse, $op_register;

	if ( ( ! isset( $op_table ) || ! is_object( $op_table ) ) && class_exists( 'OP_Table' ) ) {
		$op_table = new OP_Table();
	}

	if ( ( ! isset( $op_warehouse ) || ! is_object( $op_warehouse ) ) && class_exists( 'OP_Warehouse' ) ) {
		$op_warehouse = new OP_Warehouse();
	}

	if ( ( ! isset( $op_register ) || ! is_object( $op_register ) ) && class_exists( 'OP_Register' ) ) {
		$op_register = new OP_Register();
	}
}

/**
 * Devuelve el frontend del menú configurado para redirigir mesas.
 */
function tavox_menu_api_get_frontend_base_url(): string {
	$settings = tavox_menu_api_get_settings();
	$url      = (string) ( $settings['menu_frontend_url'] ?? '' );

	if ( '' !== $url ) {
		return trailingslashit( $url );
	}

	return trailingslashit( home_url( '/menu/' ) );
}

/**
 * Lee el JSON vivo del desk directamente desde disco para evitar desfaces de caché por outlet.
 *
 * @return array<string, mixed>
 */
function tavox_menu_api_read_openpos_table_file( int $table_id, string $table_type = 'dine_in' ): array {
	global $op_table;

	tavox_menu_api_boot_openpos_services();

	if ( ! isset( $op_table ) || ! is_object( $op_table ) || ! method_exists( $op_table, 'bill_screen_file_path' ) ) {
		return [];
	}

	$table_key = $table_id;
	if ( 'dine_in' !== $table_type ) {
		$table_key = $table_type . '-' . $table_id;
	}

	$candidates = [ $op_table->bill_screen_file_path( $table_key ) ];
	if ( 'dine_in' !== $table_type ) {
		$candidates[] = $op_table->bill_screen_file_path( 'takeaway-' . $table_key );
	}

	foreach ( $candidates as $path ) {
		if ( ! is_string( $path ) || '' === $path || ! file_exists( $path ) ) {
			continue;
		}

		$raw = file_get_contents( $path );
		if ( false === $raw || '' === trim( $raw ) ) {
			continue;
		}

		$data = json_decode( $raw, true );
		if ( is_array( $data ) ) {
			return $data;
		}
	}

	return [];
}

/**
 * Resume una versión comparable del snapshot del desk.
 *
 * @param array<string, mixed> $data
 */
function tavox_menu_api_get_openpos_data_version( array $data ): int {
	return max(
		(int) ( $data['ver'] ?? 0 ),
		(int) ( $data['online_ver'] ?? 0 ),
		(int) ( $data['system_ver'] ?? 0 ),
		(int) ( $data['created_at_time'] ?? 0 )
	);
}

/**
 * Indica si un snapshot del desk sigue teniendo actividad visible.
 *
 * @param array<string, mixed> $data
 */
function tavox_menu_api_openpos_data_has_activity( array $data ): bool {
	$items = is_array( $data['items'] ?? null ) ? $data['items'] : [];
	if ( ! empty( $items ) ) {
		return true;
	}

	foreach ( [ 'cost', 'collection', 'sub_total_incl_tax', 'total_qty', 'serverd_qty' ] as $key ) {
		if ( (float) ( $data[ $key ] ?? 0 ) > 0 ) {
			return true;
		}
	}

	return ! empty( $data['seller'] ) || ! empty( $data['customer'] );
}

/**
 * Indica si el desk conserva productos activos que todavía no deben vaciarse.
 *
 * @param array<string, mixed> $data
 */
function tavox_menu_api_openpos_data_has_active_items( array $data ): bool {
	$items = is_array( $data['items'] ?? null ) ? $data['items'] : [];
	if ( empty( $items ) ) {
		return false;
	}

	foreach ( $items as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}

		$qty   = (float) ( $item['qty'] ?? 0 );
		$state = sanitize_key( (string) ( $item['state'] ?? '' ) );

		if ( $qty <= 0 || 'cancel' === $state ) {
			continue;
		}

		if ( 'delivered' !== tavox_menu_api_get_openpos_item_service_state( $item ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Rehidrata metadatos Tavox por línea cuando una lectura pierde estado operativo.
 *
 * @param array<string, mixed> $preferred_data
 * @param array<string, mixed> $fallback_data
 * @return array<string, mixed>
 */
function tavox_menu_api_restore_openpos_runtime_metadata( array $preferred_data, array $fallback_data ): array {
	$preferred_items = is_array( $preferred_data['items'] ?? null ) ? $preferred_data['items'] : [];
	$fallback_items  = is_array( $fallback_data['items'] ?? null ) ? $fallback_data['items'] : [];

	if ( empty( $preferred_items ) || empty( $fallback_items ) ) {
		return $preferred_data;
	}

	$fallback_by_id = [];
	foreach ( $fallback_items as $fallback_item ) {
		if ( ! is_array( $fallback_item ) ) {
			continue;
		}

		$line_id = tavox_menu_api_get_openpos_item_line_id( $fallback_item );
		if ( '' !== $line_id ) {
			$fallback_by_id[ $line_id ] = $fallback_item;
		}
	}

	if ( empty( $fallback_by_id ) ) {
		return $preferred_data;
	}

	$changed = false;
	foreach ( $preferred_items as &$preferred_item ) {
		if ( ! is_array( $preferred_item ) ) {
			continue;
		}

		$line_id = tavox_menu_api_get_openpos_item_line_id( $preferred_item );
		if ( '' === $line_id || ! isset( $fallback_by_id[ $line_id ] ) || ! is_array( $fallback_by_id[ $line_id ] ) ) {
			continue;
		}

		$fallback_item   = $fallback_by_id[ $line_id ];
		$preferred_state = tavox_menu_api_get_openpos_item_service_state( $preferred_item );
		$fallback_state  = tavox_menu_api_get_openpos_item_service_state( $fallback_item );
		$preferred_prep  = tavox_menu_api_get_openpos_item_preparing_started_at( $preferred_item );
		$fallback_prep   = tavox_menu_api_get_openpos_item_preparing_started_at( $fallback_item );

		if ( 'pending' === $preferred_state && in_array( $fallback_state, [ 'preparing', 'ready', 'delivered' ], true ) ) {
			$preferred_item['state'] = $fallback_item['state'] ?? $preferred_item['state'] ?? '';
			$preferred_item['done']  = $fallback_item['done'] ?? $preferred_item['done'] ?? '';
			if ( $fallback_prep > 0 ) {
				tavox_menu_api_set_openpos_item_preparing_started_at( $preferred_item, $fallback_prep );
			}
			$changed = true;
			continue;
		}

		if ( 'preparing' === $preferred_state && $preferred_prep < 1 && $fallback_prep > 0 ) {
			tavox_menu_api_set_openpos_item_preparing_started_at( $preferred_item, $fallback_prep );
			$changed = true;
		}
	}
	unset( $preferred_item );

	if ( $changed ) {
		$preferred_data['items'] = $preferred_items;
	}

	return $preferred_data;
}

/**
 * Indica si un snapshot activo parece venir de una escritura Tavox reciente.
 */
function tavox_menu_api_openpos_data_has_recent_tavox_write( array $data, int $grace_seconds = 45 ): bool {
	$source      = sanitize_key( (string) ( $data['source'] ?? '' ) );
	$source_type = sanitize_key( (string) ( $data['source_type'] ?? '' ) );
	$items       = is_array( $data['items'] ?? null ) ? $data['items'] : [];
	$has_markers = false;

	foreach ( $items as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}

		$request_meta = tavox_menu_api_get_openpos_item_request_meta( $item );
		if ( absint( $request_meta['request_id'] ?? 0 ) > 0 || '' !== sanitize_key( (string) ( $request_meta['request_key'] ?? '' ) ) ) {
			$has_markers = true;
			break;
		}
	}

	if ( ! $has_markers && 'tavox_menu_api' !== $source && 'table_request' !== $source_type ) {
		return false;
	}

	$version = max(
		(int) ( $data['ver'] ?? 0 ),
		(int) ( $data['online_ver'] ?? 0 ),
		(int) ( $data['created_at_time'] ?? 0 ),
		(int) ( $data['system_ver'] ?? 0 )
	);

	if ( $version <= 0 ) {
		return $has_markers;
	}

	$timestamp = $version > 100000000000 ? (int) floor( $version / 1000 ) : $version;
	return ( time() - $timestamp ) <= max( 5, $grace_seconds );
}

/**
 * Convierte un datetime operativo MySQL en timestamp Unix.
 *
 * @param string $value Fecha/hora en formato MySQL.
 */
function tavox_menu_api_parse_openpos_operational_datetime( string $value ): int {
	$value = trim( $value );
	if ( '' === $value ) {
		return 0;
	}

	$timestamp = strtotime( $value );
	return false === $timestamp ? 0 : (int) $timestamp;
}

/**
 * Devuelve el último instante operativo conocido de una solicitud Tavox.
 *
 * @param array<string, mixed> $request_row
 */
function tavox_menu_api_get_request_activity_timestamp( array $request_row ): int {
	return max(
		tavox_menu_api_parse_openpos_operational_datetime( (string) ( $request_row['updated_at'] ?? '' ) ),
		tavox_menu_api_parse_openpos_operational_datetime( (string) ( $request_row['pushed_at'] ?? '' ) ),
		tavox_menu_api_parse_openpos_operational_datetime( (string) ( $request_row['accepted_at'] ?? '' ) ),
		tavox_menu_api_parse_openpos_operational_datetime( (string) ( $request_row['claimed_at'] ?? '' ) ),
		tavox_menu_api_parse_openpos_operational_datetime( (string) ( $request_row['created_at'] ?? '' ) )
	);
}

/**
 * Mantiene visible un desk Tavox persistido sólo durante una gracia corta.
 *
 * Si OpenPOS sigue reportando la mesa vacía después de ese margen, Tavox debe
 * dejar de surfear el snapshot del archivo para no mostrar mesas fantasma.
 *
 * @param array<string, mixed> $data
 */
function tavox_menu_api_should_keep_tavox_live_table_data( int $table_id, string $table_type, array $data, int $grace_seconds = 90 ): bool {
	if ( ! tavox_menu_api_openpos_data_has_activity( $data ) ) {
		return false;
	}

	$source      = sanitize_key( (string) ( $data['source'] ?? '' ) );
	$source_type = sanitize_key( (string) ( $data['source_type'] ?? '' ) );
	$request_id  = 0;
	$request_key = '';
	$items       = is_array( $data['items'] ?? null ) ? $data['items'] : [];

	foreach ( $items as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}

		$meta = tavox_menu_api_get_openpos_item_request_meta( $item );
		if ( $request_id <= 0 ) {
			$request_id = absint( $meta['request_id'] ?? 0 );
		}
		if ( '' === $request_key ) {
			$request_key = sanitize_key( (string) ( $meta['request_key'] ?? '' ) );
		}
		if ( $request_id > 0 && '' !== $request_key ) {
			break;
		}
	}

	if ( $request_id <= 0 ) {
		$request_id = absint( $data['source_details']['request_id'] ?? 0 );
	}
	if ( '' === $request_key ) {
		$request_key = sanitize_key( (string) ( $data['source_details']['request_key'] ?? '' ) );
	}

	if ( $request_id <= 0 && '' === $request_key && 'tavox_menu_api' !== $source && 'table_request' !== $source_type ) {
		return false;
	}

	if ( ! function_exists( 'tavox_menu_api_get_latest_table_request' ) ) {
		return false;
	}

	$latest_request = tavox_menu_api_get_latest_table_request(
		$table_id,
		$table_type,
		[ 'pending', 'claimed', 'pushed', 'delivered', 'error' ]
	);

	if ( ! is_array( $latest_request ) ) {
		return false;
	}

	$latest_status = sanitize_key( (string) ( $latest_request['status'] ?? '' ) );
	if ( ! in_array( $latest_status, [ 'pushed', 'delivered' ], true ) ) {
		return false;
	}

	$latest_id  = absint( $latest_request['id'] ?? 0 );
	$latest_key = sanitize_key( (string) ( $latest_request['request_key'] ?? '' ) );
	$matches_latest = ( $request_id > 0 && $latest_id === $request_id )
		|| ( '' !== $request_key && '' !== $latest_key && $latest_key === $request_key );

	if ( ! $matches_latest ) {
		return false;
	}

	$activity_timestamp = tavox_menu_api_get_request_activity_timestamp( $latest_request );
	if ( $activity_timestamp < 1 ) {
		return false;
	}

	return ( time() - $activity_timestamp ) <= max( 15, $grace_seconds );
}

/**
 * Detecta si el tablero de OpenPOS ya refleja la mesa vacía.
 *
 * @param array<string, mixed> $raw_table
 */
function tavox_menu_api_openpos_raw_table_looks_empty( array $raw_table ): bool {
	if ( empty( $raw_table ) ) {
		return false;
	}

	$known_keys = [ 'cost', 'total', 'total_price', 'collection', 'total_qty', 'qty', 'count', 'count_product', 'item_count' ];
	$found_any  = false;

	foreach ( $known_keys as $key ) {
		if ( ! array_key_exists( $key, $raw_table ) ) {
			continue;
		}

		$found_any = true;
		if ( (float) $raw_table[ $key ] > 0 ) {
			return false;
		}
	}

	return $found_any;
}

/**
 * Crea un snapshot vacío a partir del desk actual para evitar fantasmas de caché.
 *
 * @param array<string, mixed> $current_data
 * @return array<string, mixed>
 */
function tavox_menu_api_build_empty_openpos_live_data( array $current_data ): array {
	$now_ms = time() * 1000;

	$current_data['items']              = [];
	$current_data['customer']           = [];
	$current_data['seller']             = [];
	$current_data['collection']         = 0;
	$current_data['cost']               = 0;
	$current_data['start_time']         = 0;
	$current_data['total_qty']          = 0;
	$current_data['serverd_qty']        = 0;
	$current_data['sub_total_incl_tax'] = 0;
	$current_data['note']               = '';
	$current_data['state']              = '';
	$current_data['messages']           = '';
	$current_data['tag']                = '';
	$current_data['ver']                = max( (int) ( $current_data['ver'] ?? 0 ), $now_ms );
	$current_data['online_ver']         = max( (int) ( $current_data['online_ver'] ?? 0 ), $now_ms );
	$current_data['system_ver']         = max( (int) ( $current_data['system_ver'] ?? 0 ), $now_ms );

	return $current_data;
}

/**
 * Obtiene el desk vivo priorizando la lectura directa del archivo sobre la caché por outlet de OpenPOS.
 *
 * @return array<string, mixed>
 */
function tavox_menu_api_get_openpos_live_table_data( int $table_id, string $table_type = 'dine_in', int $warehouse_id = 0, array $raw_table = [] ): array {
	global $op_table;

	tavox_menu_api_boot_openpos_services();

	$current_data = [];
	if ( isset( $op_table ) && is_object( $op_table ) && method_exists( $op_table, 'get_data' ) ) {
		$current_data = $op_table->get_data( $table_id, $table_type, $warehouse_id );
	}
	$current_data = is_array( $current_data ) ? $current_data : [];

	$fresh_data = tavox_menu_api_read_openpos_table_file( $table_id, $table_type );
	$raw_empty  = tavox_menu_api_openpos_raw_table_looks_empty( $raw_table );

	if ( ! empty( $fresh_data ) && ! empty( $current_data ) ) {
		$fresh_data   = tavox_menu_api_restore_openpos_runtime_metadata( $fresh_data, $current_data );
		$current_data = tavox_menu_api_restore_openpos_runtime_metadata( $current_data, $fresh_data );
	}

	if ( $raw_empty ) {
		$base_snapshot = ! empty( $fresh_data ) && is_array( $fresh_data ) ? $fresh_data : $current_data;

		if ( tavox_menu_api_openpos_data_has_active_items( $base_snapshot ) ) {
			if ( ! empty( $fresh_data ) ) {
				return $base_snapshot;
			}

			if (
				tavox_menu_api_should_keep_tavox_live_table_data( $table_id, $table_type, $base_snapshot )
				|| tavox_menu_api_openpos_data_has_recent_tavox_write( $base_snapshot )
			) {
				return $base_snapshot;
			}
		}

		if ( tavox_menu_api_openpos_data_has_activity( $base_snapshot ) ) {
			if ( tavox_menu_api_should_keep_tavox_live_table_data( $table_id, $table_type, $base_snapshot ) ) {
				return $base_snapshot;
			}

			return tavox_menu_api_build_empty_openpos_live_data( $base_snapshot );
		}
	}

	if ( empty( $fresh_data ) ) {
		if ( $raw_empty && tavox_menu_api_openpos_data_has_active_items( $current_data ) ) {
			if (
				tavox_menu_api_should_keep_tavox_live_table_data( $table_id, $table_type, $current_data )
				|| tavox_menu_api_openpos_data_has_recent_tavox_write( $current_data )
			) {
				return $current_data;
			}
		}

		if ( $raw_empty && tavox_menu_api_openpos_data_has_activity( $current_data ) ) {
			if ( tavox_menu_api_should_keep_tavox_live_table_data( $table_id, $table_type, $current_data ) ) {
				return $current_data;
			}

			return tavox_menu_api_build_empty_openpos_live_data( $current_data );
		}

		return $current_data;
	}

	$current_version      = tavox_menu_api_get_openpos_data_version( $current_data );
	$fresh_version        = tavox_menu_api_get_openpos_data_version( $fresh_data );
	$current_items = is_array( $current_data['items'] ?? null ) ? $current_data['items'] : [];
	$fresh_items   = is_array( $fresh_data['items'] ?? null ) ? $fresh_data['items'] : [];
	$current_active = tavox_menu_api_openpos_data_has_activity( $current_data );
	$fresh_active   = tavox_menu_api_openpos_data_has_activity( $fresh_data );

	if ( empty( $current_data ) ) {
		return $fresh_data;
	}

	if ( $fresh_version >= $current_version ) {
		return $fresh_data;
	}

	if ( $current_active && ! $fresh_active ) {
		return $fresh_data;
	}

	if ( count( $fresh_items ) >= count( $current_items ) ) {
		return $fresh_data;
	}

	if ( ! empty( $fresh_data['seller'] ) || ! empty( $fresh_data['customer'] ) ) {
		return $fresh_data;
	}

	return $current_data;
}

/**
 * Crea una firma corta para payloads firmados del flujo de mesas.
 *
 * @param array<string, mixed> $payload Payload serializable.
 */
function tavox_menu_api_sign_payload( array $payload ): string {
	$json = wp_json_encode( $payload );

	return hash_hmac( 'sha256', (string) $json, wp_salt( 'auth' ) . '|tavox_menu_api' );
}

/**
 * Codifica un payload firmado en formato portable.
 *
 * @param array<string, mixed> $payload Payload a serializar.
 */
function tavox_menu_api_encode_signed_payload( array $payload ): string {
	$json      = wp_json_encode( $payload );
	$encoded   = rtrim( strtr( base64_encode( (string) $json ), '+/', '-_' ), '=' );
	$signature = tavox_menu_api_sign_payload( $payload );

	return $encoded . '.' . $signature;
}

/**
 * Decodifica y valida un payload firmado.
 *
 * @return array<string, mixed>|WP_Error
 */
function tavox_menu_api_decode_signed_payload( string $token ) {
	$token = trim( $token );
	if ( '' === $token || false === strpos( $token, '.' ) ) {
		return new WP_Error( 'invalid_token', __( 'No pudimos validar este acceso.', 'tavox-menu-api' ), [ 'status' => 400 ] );
	}

	[ $encoded, $signature ] = explode( '.', $token, 2 );
	$json = base64_decode( strtr( $encoded, '-_', '+/' ), true );
	if ( false === $json ) {
		return new WP_Error( 'invalid_token', __( 'No pudimos validar este acceso.', 'tavox-menu-api' ), [ 'status' => 400 ] );
	}

	$payload = json_decode( $json, true );
	if ( ! is_array( $payload ) ) {
		return new WP_Error( 'invalid_token', __( 'No pudimos validar este acceso.', 'tavox-menu-api' ), [ 'status' => 400 ] );
	}

	$expected = tavox_menu_api_sign_payload( $payload );
	if ( ! hash_equals( $expected, (string) $signature ) ) {
		return new WP_Error( 'invalid_token', __( 'Este acceso ya no es válido.', 'tavox-menu-api' ), [ 'status' => 403 ] );
	}

	$expires_at = absint( $payload['exp'] ?? 0 );
	if ( $expires_at > 0 && $expires_at < time() ) {
		return new WP_Error( 'expired_token', __( 'Este acceso ya venció. Escanea de nuevo el código de la mesa.', 'tavox-menu-api' ), [ 'status' => 410 ] );
	}

	return $payload;
}

/**
 * Construye un token firmado para el contexto de mesa.
 *
 * @param array<string, mixed> $context Contexto validado de OpenPOS.
 */
function tavox_menu_api_build_table_token( array $context ): string {
	$payload = [
		'iat'         => time(),
		'exp'         => time() + ( 12 * HOUR_IN_SECONDS ),
		'table_key'   => (string) ( $context['key'] ?? '' ),
		'table_id'    => absint( $context['table_id'] ?? 0 ),
		'table_type'  => sanitize_key( (string) ( $context['table_type'] ?? 'dine_in' ) ),
		'table_name'  => sanitize_text_field( (string) ( $context['table_name'] ?? '' ) ),
		'register_id' => absint( $context['register_id'] ?? 0 ),
		'warehouse_id'=> absint( $context['warehouse_id'] ?? 0 ),
	];

	return tavox_menu_api_encode_signed_payload( $payload );
}

/**
 * Intenta reconstruir una mesa OpenPOS desde su identidad operativa, sin depender del QR.
 *
 * @return array<string, mixed>|WP_Error
 */
function tavox_menu_api_get_openpos_table_context_by_identity(
	int $table_id,
	string $table_type = 'dine_in',
	int $register_id = 0,
	int $warehouse_id = 0,
	string $table_name = '',
	array $raw_table = []
) {
	global $op_table, $op_register;

	$table_id   = absint( $table_id );
	$table_type = 'takeaway' === sanitize_key( $table_type ) ? 'takeaway' : 'dine_in';

	if ( $table_id <= 0 ) {
		return new WP_Error( 'table_not_found', __( 'La mesa no existe.', 'tavox-menu-api' ), [ 'status' => 404 ] );
	}

	try {
		tavox_menu_api_boot_openpos_services();

		if ( ! tavox_menu_api_is_openpos_ready() || ! isset( $op_table ) || ! is_object( $op_table ) ) {
			return new WP_Error( 'openpos_unavailable', __( 'No pudimos abrir esta mesa en este momento.', 'tavox-menu-api' ), [ 'status' => 503 ] );
		}

		if ( empty( $raw_table ) ) {
			if ( 'takeaway' === $table_type && method_exists( $op_table, 'takeawayTables' ) ) {
				foreach ( (array) $op_table->takeawayTables( -1 ) as $candidate ) {
					if ( is_array( $candidate ) && absint( $candidate['id'] ?? 0 ) === $table_id ) {
						$raw_table = $candidate;
						break;
					}
				}
			} elseif ( method_exists( $op_table, 'get' ) ) {
				$candidate = $op_table->get( $table_id, true );
				if ( is_array( $candidate ) ) {
					$raw_table = $candidate;
				}
			}
		}

		if ( $register_id <= 0 && 'takeaway' !== $table_type ) {
			$register_id = absint( get_post_meta( $table_id, '_op_barcode_register', true ) );
		}

		if ( $warehouse_id <= 0 ) {
			$warehouse_id = absint( $raw_table['warehouse'] ?? 0 );
		}

		$register = [];
		if ( 'takeaway' !== $table_type && $register_id > 0 && isset( $op_register ) && is_object( $op_register ) && method_exists( $op_register, 'get' ) ) {
			$register = $op_register->get( $register_id );
			if ( is_array( $register ) ) {
				$warehouse_id = absint( $register['warehouse'] ?? $warehouse_id );
			}
		}

		$current_data = tavox_menu_api_get_openpos_live_table_data( $table_id, $table_type, $warehouse_id, $raw_table );

		if ( '' === $table_name ) {
			$table_name = (string) ( $raw_table['name'] ?? '' );
		}
		if ( ! empty( $current_data['label'] ) ) {
			$table_name = (string) $current_data['label'];
		} elseif ( ! empty( $current_data['desk']['name'] ) ) {
			$table_name = (string) $current_data['desk']['name'];
		}

		if ( ! empty( $current_data['desk']['warehouse_id'] ) ) {
			$warehouse_id = absint( $current_data['desk']['warehouse_id'] );
		}

		$table_key = sanitize_text_field(
			(string) (
				$raw_table['key']
				?? get_post_meta( $table_id, '_op_barcode_key', true )
				?? ''
			)
		);

		return [
			'key'          => $table_key,
			'table_id'     => $table_id,
			'table_type'   => $table_type,
			'table_name'   => sanitize_text_field( $table_name ),
			'desk_ref'     => ( 'takeaway' === $table_type ? 'takeaway-' : 'desk-' ) . $table_id,
			'register_id'  => $register_id,
			'warehouse_id' => $warehouse_id,
			'register'     => is_array( $register ) ? $register : [],
			'raw_table'    => is_array( $raw_table ) ? $raw_table : [],
			'current_data' => $current_data,
		];
	} catch ( Throwable $error ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[Tavox Menu API] identity table context error: ' . $error->getMessage() . ' @ ' . $error->getFile() . ':' . $error->getLine() );
		}

		return new WP_Error(
			'table_context_failed',
			__( 'No se pudo preparar la mesa para pedido directo.', 'tavox-menu-api' ),
			[ 'status' => 500 ]
		);
	}
}

/**
 * Valida y devuelve el contexto real de OpenPOS usando el key del QR/NFC.
 *
 * @return array<string, mixed>|WP_Error
 */
function tavox_menu_api_get_openpos_table_context_by_key( string $key ) {
	global $op_register, $op_table, $op_warehouse;

	$key = sanitize_text_field( trim( $key ) );
	if ( '' === $key ) {
		return new WP_Error( 'missing_key', __( 'Falta la llave de la mesa.', 'tavox-menu-api' ), [ 'status' => 400 ] );
	}

	try {
		tavox_menu_api_boot_openpos_services();

		if ( ! tavox_menu_api_is_openpos_ready() || ! isset( $op_table ) || ! is_object( $op_table ) || ! method_exists( $op_table, 'getTableByKey' ) ) {
			return new WP_Error( 'openpos_unavailable', __( 'No pudimos validar esta mesa en este momento.', 'tavox-menu-api' ), [ 'status' => 503 ] );
		}

		$table      = $op_table->getTableByKey( $key );
		$table_type = 'dine_in';

		if ( empty( $table ) && isset( $op_warehouse ) && is_object( $op_warehouse ) && method_exists( $op_warehouse, 'getTakeawayByKey' ) ) {
			$table      = $op_warehouse->getTakeawayByKey( $key );
			$table_type = 'takeaway';
		}

		if ( empty( $table ) || ! is_array( $table ) ) {
			return new WP_Error( 'table_not_found', __( 'La mesa no existe o el código expiró.', 'tavox-menu-api' ), [ 'status' => 404 ] );
		}

		if ( isset( $table['status'] ) && 'publish' !== (string) $table['status'] ) {
			return new WP_Error( 'table_unavailable', __( 'La mesa todavía no está disponible.', 'tavox-menu-api' ), [ 'status' => 409 ] );
		}

		$table_id     = absint( $table['id'] ?? 0 );
		$register_id  = absint( $table['register_id'] ?? 0 );
		$register     = null;
		$warehouse_id = absint( $table['warehouse'] ?? 0 );

		if ( 'takeaway' !== $table_type && $register_id > 0 && isset( $op_register ) && is_object( $op_register ) && method_exists( $op_register, 'get' ) ) {
			$register = $op_register->get( $register_id );
			if ( ! empty( $register ) && is_array( $register ) ) {
				$warehouse_id = absint( $register['warehouse'] ?? $warehouse_id );
			}
		}

		$current_data = tavox_menu_api_get_openpos_live_table_data(
			$table_id,
			'takeaway' === $table_type ? 'takeaway' : 'dine_in',
			$warehouse_id,
			$table
		);

		$table_name = sanitize_text_field( (string) ( $table['name'] ?? '' ) );

		if ( is_array( $current_data ) && ! empty( $current_data['label'] ) ) {
			$table_name = sanitize_text_field( (string) $current_data['label'] );
		} elseif ( is_array( $current_data ) && ! empty( $current_data['desk']['name'] ) ) {
			$table_name = sanitize_text_field( (string) $current_data['desk']['name'] );
		}

		$desk_ref = ( 'takeaway' === $table_type ? 'takeaway-' : 'desk-' ) . $table_id;

		return [
			'key'           => $key,
			'table_id'      => $table_id,
			'table_type'    => $table_type,
			'table_name'    => $table_name,
			'desk_ref'      => $desk_ref,
			'register_id'   => $register_id,
			'warehouse_id'  => $warehouse_id,
			'register'      => is_array( $register ) ? $register : [],
			'raw_table'     => $table,
			'current_data'  => is_array( $current_data ) ? $current_data : [],
		];
	} catch ( Throwable $error ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[Tavox Menu API] table context error: ' . $error->getMessage() . ' @ ' . $error->getFile() . ':' . $error->getLine() );
		}

		return new WP_Error(
			'table_context_failed',
			__( 'No se pudo validar la mesa en este momento.', 'tavox-menu-api' ),
			[ 'status' => 500 ]
		);
	}
}

/**
 * Resuelve un token de mesa a contexto real y fresco de OpenPOS.
 *
 * @return array<string, mixed>|WP_Error
 */
function tavox_menu_api_get_openpos_table_context_from_token( string $table_token ) {
	$decoded = tavox_menu_api_decode_signed_payload( $table_token );
	if ( is_wp_error( $decoded ) ) {
		return $decoded;
	}

	$table_key = (string) ( $decoded['table_key'] ?? '' );
	if ( '' !== $table_key ) {
		$context = tavox_menu_api_get_openpos_table_context_by_key( $table_key );
		if ( ! is_wp_error( $context ) ) {
			return $context;
		}
	}

	return tavox_menu_api_get_openpos_table_context_by_identity(
		absint( $decoded['table_id'] ?? 0 ),
		(string) ( $decoded['table_type'] ?? 'dine_in' ),
		absint( $decoded['register_id'] ?? 0 ),
		absint( $decoded['warehouse_id'] ?? 0 ),
		(string) ( $decoded['table_name'] ?? '' )
	);
}

/**
 * Devuelve un resumen del consumo actual de la mesa.
 *
 * @param array<string, mixed> $table_context Contexto de mesa ya validado.
 * @return array<string, mixed>
 */
function tavox_menu_api_get_product_category_labels( int $product_id ): array {
	static $cache = [];

	$product_id = absint( $product_id );
	if ( $product_id < 1 ) {
		return [];
	}

	if ( isset( $cache[ $product_id ] ) ) {
		return $cache[ $product_id ];
	}

	$terms = get_the_terms( $product_id, 'product_cat' );
	if ( ! is_array( $terms ) ) {
		$cache[ $product_id ] = [];
		return [];
	}

	$labels = array_values(
		array_filter(
			array_map(
				static fn( $term ) => $term instanceof WP_Term ? sanitize_text_field( html_entity_decode( $term->name ) ) : '',
				$terms
			)
		)
	);

	$cache[ $product_id ] = $labels;
	return $labels;
}

/**
 * Intenta inferir si una línea pertenece a cocina, barra u horno.
 *
 * @param array<string, mixed> $item Línea cruda de OpenPOS.
 */
function tavox_menu_api_infer_item_station( array $item ): string {
	$product_id         = absint( $item['product_id'] ?? $item['product']['id'] ?? 0 );
	$openpos_station    = tavox_menu_api_get_openpos_product_station( $product_id );
	$configured_station = tavox_menu_api_get_service_station_for_product( $product_id );
	$category_labels = tavox_menu_api_get_product_category_labels( $product_id );
	$haystack        = tavox_menu_api_normalize_text(
		implode(
			' ',
			array_filter(
				array_merge(
					$category_labels,
					[
						(string) ( $item['name'] ?? '' ),
						(string) ( $item['sub_name'] ?? '' ),
						(string) ( $item['note'] ?? '' ),
					]
				)
			)
		)
	);

	$bar_keywords = [
		'trago',
		'tragos',
		'coctel',
		'cocteles',
		'cocktail',
		'bar',
		'cerveza',
		'cervezas',
		'vino',
		'vinos',
		'champagne',
		'ron',
		'rones',
		'vodka',
		'vodkas',
		'tequila',
		'tequilas',
		'whisky',
		'whiskey',
		'gin',
		'jugo',
		'jugos',
		'batido',
		'batidos',
		'refresco',
		'refrescos',
		'malta',
		'agua',
		'soda',
		'cafe',
		'bebida',
		'bebidas',
	];

	if ( '' !== $openpos_station ) {
		$station = $openpos_station;
	} elseif ( 'auto' !== $configured_station ) {
		$station = $configured_station;
	} else {
		$station = 'kitchen';
		foreach ( $bar_keywords as $keyword ) {
			if ( false !== strpos( $haystack, $keyword ) ) {
				$station = 'bar';
				break;
			}
		}
	}

	/**
	 * Permite ajustar la estación operativa de una línea.
	 *
	 * @param string               $station Estación inferida.
	 * @param array<string, mixed> $item    Línea cruda.
	 * @param int                  $product_id Producto asociado.
	 * @param array<int, string>   $category_labels Categorías visibles.
	 */
	return (string) apply_filters( 'tavox_menu_api_item_station', $station, $item, $product_id, $category_labels );
}

/**
 * Resume la ventana de retiro para pedidos para llevar cuando exista.
 *
 * @param array<string, mixed> $current_data Desk crudo.
 * @return array<string, mixed>
 */
function tavox_menu_api_build_openpos_pickup_summary( array $current_data ): array {
	$source_details = is_array( $current_data['source_details'] ?? null ) ? $current_data['source_details'] : [];
	$order_id       = absint( $source_details['id'] ?? $source_details['order_id'] ?? 0 );

	if ( $order_id < 1 ) {
		return [];
	}

	$order = wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order ) {
		return [];
	}

	$pickup_slot = (string) get_post_meta( $order_id, '_op_pickup_time', true );
	$from_unix   = absint( get_post_meta( $order_id, '_op_pickup_time_from', true ) );
	$to_unix     = absint( get_post_meta( $order_id, '_op_pickup_time_to', true ) );
	$window_label = '';

	if ( $from_unix > 0 && $to_unix > 0 ) {
		$window_label = wp_date( 'd/m H:i', $from_unix ) . ' - ' . wp_date( 'H:i', $to_unix );
	} elseif ( '' !== trim( $pickup_slot ) ) {
		$window_label = sanitize_text_field( str_replace( '@', ' · ', $pickup_slot ) );
	}

	return [
		'order_id'      => $order_id,
		'slot'          => sanitize_text_field( $pickup_slot ),
		'window_label'  => $window_label,
		'customer_name' => sanitize_text_field(
			(string) (
				$order->get_formatted_billing_full_name()
				?: $order->get_billing_email()
				?: $order->get_shipping_first_name()
			)
		),
		'phone'         => sanitize_text_field( (string) $order->get_billing_phone() ),
	];
}

function tavox_menu_api_build_table_consumption_summary( array $table_context ): array {
	$current_data = is_array( $table_context['current_data'] ?? null ) ? $table_context['current_data'] : [];
	$items        = is_array( $current_data['items'] ?? null ) ? $current_data['items'] : [];
	$customer     = tavox_menu_api_build_openpos_customer_summary( $current_data );
	$seller       = tavox_menu_api_build_openpos_seller_summary( $current_data );
	$summary      = [
		'items_count'   => 0,
		'lines_count'   => count( $items ),
		'total_amount'  => 0.0,
		'total_qty'     => 0.0,
		'served_qty'    => (float) ( $current_data['serverd_qty'] ?? 0 ),
		'pending_lines' => 0,
		'preparing_lines' => 0,
		'ready_lines'   => 0,
		'delivered_lines' => 0,
		'currency_code' => get_woocommerce_currency(),
		'desk_version'  => max(
			(int) ( $current_data['ver'] ?? 0 ),
			(int) ( $current_data['online_ver'] ?? 0 ),
			(int) ( $current_data['system_ver'] ?? 0 )
		),
		'desk_updated_at' => (int) ( $current_data['system_ver'] ?? 0 ),
		'customer'      => $customer,
		'seller'        => $seller,
		'items'         => [],
	];

	foreach ( $items as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}

		$qty   = (float) ( $item['qty'] ?? 0 );
		$total = (float) ( $item['total_incl_tax'] ?? $item['total'] ?? 0 );
		$done  = tavox_menu_api_get_openpos_item_done_state( $item );
		$service_state = tavox_menu_api_get_openpos_item_service_state( $item );
		$staff_display_state = tavox_menu_api_get_openpos_item_service_label( $service_state, $item );
		$customer_display_state = tavox_menu_api_get_openpos_item_customer_service_label( $service_state, $item );
		$request_meta  = tavox_menu_api_get_openpos_item_request_meta( $item );
		$fulfillment_mode = tavox_menu_api_get_openpos_item_fulfillment_mode(
			$item,
			'takeaway' === sanitize_key( (string) ( $table_context['table_type'] ?? 'dine_in' ) ) ? 'takeaway' : 'dine_in'
		);
		$preparing_started_at = tavox_menu_api_get_openpos_item_preparing_started_at( $item );
		$modifiers_label = tavox_menu_api_get_openpos_item_modifiers_label( $item );
		$customer_note   = tavox_menu_api_get_openpos_item_customer_note( $item );
		$details_label   = implode( ' · ', array_filter( [ $modifiers_label, $customer_note ] ) );
		$product_id    = absint( $item['product_id'] ?? $item['product']['id'] ?? 0 );
		$category_labels = tavox_menu_api_get_product_category_labels( $product_id );
		$station       = tavox_menu_api_infer_item_station( $item );
		$image_url     = tavox_menu_api_get_openpos_item_image_url( $item, $product_id );
		$seller_id     = absint( $item['seller_id'] ?? $item['seller']['id'] ?? 0 );
		$seller_fallback = sanitize_text_field(
			(string) (
				$item['seller_name']
				?? $item['seller']['name']
				?? $item['seller']
				?? ''
			)
		);
		$seller_name = function_exists( 'tavox_menu_api_resolve_waiter_staff_name' )
			? tavox_menu_api_resolve_waiter_staff_name( $seller_id, $seller_fallback )
			: $seller_fallback;

		$summary['items_count'] += (int) $qty;
		$summary['total_qty'] += $qty;
		$summary['total_amount'] += $total;

		if ( 'ready' === $service_state ) {
			$summary['ready_lines']++;
		} elseif ( 'delivered' === $service_state ) {
			$summary['delivered_lines']++;
		} elseif ( 'preparing' === $service_state ) {
			$summary['preparing_lines']++;
		} else {
			$summary['pending_lines']++;
		}

		$summary['items'][] = [
			'id'              => tavox_menu_api_get_openpos_item_line_id( $item ),
			'lot_key'         => tavox_menu_api_get_openpos_item_lot_key( $item ),
			'product_id'      => $product_id,
			'name'            => sanitize_text_field( (string) ( $item['name'] ?? '' ) ),
			'display_name'    => sanitize_text_field( (string) ( $item['name'] ?? '' ) ),
			'qty'             => $qty,
			'served_qty'      => (float) ( $item['serverd_qty'] ?? $item['served_qty'] ?? 0 ),
			'total'           => $total,
			'note'            => $details_label,
			'modifiers_label' => $modifiers_label,
			'customer_note'   => $customer_note,
			'state'           => sanitize_key( (string) ( $item['state'] ?? '' ) ),
			'done'            => $done,
			'service_state'   => $service_state,
			'display_state'   => $staff_display_state,
			'staff_display_state' => $staff_display_state,
			'customer_display_state' => $customer_display_state,
			'station'         => $station,
			'fulfillment_mode'=> $fulfillment_mode,
			'fulfillment_label' => tavox_menu_api_get_fulfillment_mode_label( $fulfillment_mode ),
			'preparing_started_at' => $preparing_started_at,
			'elapsed_prep_seconds' => $preparing_started_at > 0 ? max( 0, (int) floor( ( time() * 1000 - $preparing_started_at ) / 1000 ) ) : 0,
			'category_labels' => $category_labels,
			'order_time'      => (int) ( $item['order_time'] ?? $item['update_time'] ?? 0 ),
			'order_label'     => sanitize_text_field( (string) ( $item['order_time'] ?? '' ) ),
			'seller_id'       => $seller_id,
			'seller_name'     => $seller_name,
			'staff_display_name' => $seller_name,
			'image_url'       => $image_url,
			'request_id'      => $request_meta['request_id'],
			'request_key'     => $request_meta['request_key'],
		];
	}

	return $summary;
}

/**
 * Normaliza la versión de escritura del desk para OpenPOS.
 *
 * OpenPOS compara `system_ver` como entero creciente; si mezclamos segundos con
 * milisegundos, rechaza la actualización aunque no exista otro cambio real.
 *
 * @param array<string, mixed> $desk_data
 * @return array<string, mixed>
 */
function tavox_menu_api_prepare_openpos_write_versions( array $desk_data ): array {
	$next_version = max(
		(int) round( microtime( true ) * 1000 ),
		(int) ( $desk_data['ver'] ?? 0 ) + 1,
		(int) ( $desk_data['online_ver'] ?? 0 ) + 1,
		(int) ( $desk_data['system_ver'] ?? 0 ) + 1
	);

	$desk_data['ver']        = $next_version;
	$desk_data['online_ver'] = max( (int) ( $desk_data['online_ver'] ?? 0 ), $next_version );
	$desk_data['system_ver'] = $next_version;

	return $desk_data;
}

/**
 * Devuelve la miniatura más útil de una línea del consumo.
 *
 * @param array<string, mixed> $item Línea cruda de OpenPOS.
 */
function tavox_menu_api_get_openpos_item_image_url( array $item, int $product_id = 0 ): string {
	$candidates = [
		(string) ( $item['image'] ?? '' ),
		(string) ( $item['image_url'] ?? '' ),
		(string) ( $item['product']['image'] ?? '' ),
		(string) ( $item['product']['image_url'] ?? '' ),
		(string) ( $item['product_image'] ?? '' ),
	];

	foreach ( $candidates as $candidate ) {
		$url = esc_url_raw( trim( $candidate ), [ 'http', 'https' ] );
		if ( '' !== $url ) {
			return $url;
		}
	}

	$product_id = absint( $product_id );
	if ( $product_id > 0 ) {
		$thumbnail_id = get_post_thumbnail_id( $product_id );
		if ( $thumbnail_id ) {
			$url = wp_get_attachment_image_url( $thumbnail_id, 'woocommerce_thumbnail' );
			if ( is_string( $url ) && '' !== $url ) {
				return esc_url_raw( $url, [ 'http', 'https' ] );
			}
		}
	}

	return '';
}

/**
 * Obtiene el marcador de preparación/entrega real de OpenPOS.
 *
 * @param array<string, mixed> $item Línea cruda de OpenPOS.
 */
function tavox_menu_api_get_openpos_item_done_state( array $item ): string {
	return sanitize_key( (string) ( $item['done'] ?? '' ) );
}

/**
 * Normaliza el estado visible de una línea del consumo.
 *
 * @param array<string, mixed> $item Línea cruda de OpenPOS.
 */
function tavox_menu_api_get_openpos_item_service_state( array $item ): string {
	$qty        = (float) ( $item['qty'] ?? 0 );
	$served_qty = (float) ( $item['serverd_qty'] ?? $item['served_qty'] ?? 0 );
	$done       = tavox_menu_api_get_openpos_item_done_state( $item );
	$state      = sanitize_key( (string) ( $item['state'] ?? '' ) );

	if ( $qty > 0 && $served_qty >= $qty ) {
		return 'delivered';
	}

	if ( 'done_all' === $done ) {
		return 'delivered';
	}

	if ( in_array( $done, [ 'ready', 'done' ], true ) ) {
		return 'ready';
	}

	if ( 'cooking' === $state ) {
		return 'preparing';
	}

	return 'pending';
}

/**
 * Devuelve la etiqueta visible de una línea del consumo.
 *
 * @param array<string, mixed> $item Línea cruda de OpenPOS.
 */
function tavox_menu_api_get_openpos_item_service_label( string $service_state, array $item = [] ): string {
	if ( 'ready' === $service_state ) {
		return __( 'Listo', 'tavox-menu-api' );
	}

	if ( 'delivered' === $service_state ) {
		return __( 'Entregado', 'tavox-menu-api' );
	}

	if ( 'preparing' === $service_state ) {
		return __( 'En preparación', 'tavox-menu-api' );
	}

	$state = sanitize_key( (string) ( $item['state'] ?? '' ) );
	if ( 'cancel' === $state ) {
		return __( 'Cancelado', 'tavox-menu-api' );
	}

	return __( 'Pendiente', 'tavox-menu-api' );
}

/**
 * Devuelve la etiqueta visible al cliente para una línea del consumo.
 *
 * @param array<string, mixed> $item Línea cruda de OpenPOS.
 */
function tavox_menu_api_get_openpos_item_customer_service_label( string $service_state, array $item = [] ): string {
	if ( 'ready' === $service_state ) {
		return __( 'En camino', 'tavox-menu-api' );
	}

	if ( 'delivered' === $service_state ) {
		return __( 'Entregado', 'tavox-menu-api' );
	}

	if ( 'preparing' === $service_state ) {
		return __( 'En preparación', 'tavox-menu-api' );
	}

	$state = sanitize_key( (string) ( $item['state'] ?? '' ) );
	if ( 'cancel' === $state ) {
		return __( 'Cancelado', 'tavox-menu-api' );
	}

	return __( 'Pendiente', 'tavox-menu-api' );
}

/**
 * Devuelve un identificador estable para una línea de producción.
 *
 * @param array<string, mixed> $item Línea cruda de OpenPOS.
 */
function tavox_menu_api_get_openpos_item_line_id( array $item ): string {
	return trim( (string) ( $item['id'] ?? '' ) );
}

/**
 * Divide el resumen compuesto de extras/nota que usa OpenPOS.
 *
 * @return string[]
 */
function tavox_menu_api_split_openpos_compound_note( string $value ): array {
	$tokens = preg_split( '/\s*·\s*/u', sanitize_text_field( $value ) );
	if ( ! is_array( $tokens ) ) {
		return [];
	}

	return array_values(
		array_filter(
			array_map(
				static fn( $token ): string => sanitize_text_field( (string) $token ),
				$tokens
			)
		)
	);
}

/**
 * Devuelve la nota libre del cliente para una línea.
 *
 * @param array<string, mixed> $item Línea cruda de OpenPOS.
 */
function tavox_menu_api_get_openpos_item_customer_note( array $item ): string {
	$custom_fields  = is_array( $item['custom_fields'] ?? null ) ? $item['custom_fields'] : [];
	$source_details = is_array( $item['source_details'] ?? null ) ? $item['source_details'] : [];
	$note           = sanitize_text_field(
		(string) (
			$item['tavox_customer_note']
			?? $custom_fields['tavox_customer_note']
			?? $source_details['customer_note']
			?? $item['note']
			?? ''
		)
	);

	if ( '' !== $note ) {
		return $note;
	}

	$modifiers_label = sanitize_text_field(
		(string) (
			$item['tavox_modifiers_label']
			?? $custom_fields['tavox_modifiers_label']
			?? $source_details['modifiers_label']
			?? ''
		)
	);
	$compound_note = sanitize_text_field( (string) ( $item['sub_name'] ?? '' ) );

	if ( '' !== $compound_note && '' === $modifiers_label ) {
		$tokens = tavox_menu_api_split_openpos_compound_note( $compound_note );
		return count( $tokens ) <= 1 ? $compound_note : '';
	}

	return '';
}

/**
 * Devuelve los ingredientes/extras visibles de una línea.
 *
 * @param array<string, mixed> $item Línea cruda de OpenPOS.
 */
function tavox_menu_api_get_openpos_item_modifiers_label( array $item ): string {
	$custom_fields   = is_array( $item['custom_fields'] ?? null ) ? $item['custom_fields'] : [];
	$source_details  = is_array( $item['source_details'] ?? null ) ? $item['source_details'] : [];
	$modifiers_label = sanitize_text_field(
		(string) (
			$item['tavox_modifiers_label']
			?? $custom_fields['tavox_modifiers_label']
			?? $source_details['modifiers_label']
			?? ''
		)
	);

	if ( '' !== $modifiers_label ) {
		return $modifiers_label;
	}

	$compound_note = sanitize_text_field( (string) ( $item['sub_name'] ?? '' ) );
	if ( '' === $compound_note ) {
		return '';
	}

	$customer_note = tavox_menu_api_get_openpos_item_customer_note( $item );
	if ( '' === $customer_note ) {
		return $compound_note;
	}

	$customer_note_normalized = tavox_menu_api_normalize_text( $customer_note );
	$filtered_tokens          = array_values(
		array_filter(
			tavox_menu_api_split_openpos_compound_note( $compound_note ),
			static fn( string $token ): bool => tavox_menu_api_normalize_text( $token ) !== $customer_note_normalized
		)
	);

	return implode( ' · ', $filtered_tokens );
}

/**
 * Devuelve la firma de lote para una línea.
 *
 * @param array<string, mixed> $item Línea cruda de OpenPOS.
 */
function tavox_menu_api_get_openpos_item_lot_key( array $item ): string {
	$request_meta = tavox_menu_api_get_openpos_item_request_meta( $item );
	$station      = tavox_menu_api_infer_item_station( $item );

	if ( ! empty( $request_meta['request_key'] ) ) {
		return 'request:' . sanitize_key( (string) $request_meta['request_key'] ) . '|' . $station;
	}

	if ( absint( $request_meta['request_id'] ?? 0 ) > 0 ) {
		return 'request:' . absint( $request_meta['request_id'] ) . '|' . $station;
	}

	$product_id = absint( $item['product_id'] ?? $item['product']['id'] ?? 0 );
	$name       = tavox_menu_api_normalize_text( (string) ( $item['name'] ?? '' ) );
	$note       = tavox_menu_api_normalize_text( (string) ( $item['sub_name'] ?? $item['note'] ?? '' ) );

	return implode( '|', [ (string) $product_id, $name, $note, $station ] );
}

/**
 * Extrae la referencia interna de Tavox guardada en una línea de OpenPOS.
 *
 * @param array<string, mixed> $item Línea cruda de OpenPOS.
 * @return array{request_id:int,request_key:string}
 */
function tavox_menu_api_get_openpos_item_request_meta( array $item ): array {
	$custom_fields  = is_array( $item['custom_fields'] ?? null ) ? $item['custom_fields'] : [];
	$source_details = is_array( $item['source_details'] ?? null ) ? $item['source_details'] : [];

	return [
		'request_id'  => absint(
			$item['tavox_request_id']
			?? $custom_fields['tavox_request_id']
			?? $source_details['request_id']
			?? 0
		),
		'request_key' => sanitize_key(
			(string) (
				$item['tavox_request_key']
				?? $custom_fields['tavox_request_key']
				?? $source_details['request_key']
				?? ''
			)
		),
	];
}

/**
 * Devuelve el modo de consumo real de una línea.
 *
 * @param array<string, mixed> $item Línea cruda de OpenPOS.
 */
function tavox_menu_api_get_openpos_item_fulfillment_mode( array $item, string $default = 'dine_in' ): string {
	$custom_fields  = is_array( $item['custom_fields'] ?? null ) ? $item['custom_fields'] : [];
	$source_details = is_array( $item['source_details'] ?? null ) ? $item['source_details'] : [];

	return tavox_menu_api_sanitize_fulfillment_mode(
		(string) (
			$item['fulfillment_mode']
			?? $item['fulfillmentMode']
			?? $custom_fields['fulfillment_mode']
			?? $source_details['fulfillment_mode']
			?? $item['dining']
			?? ''
		),
		$default
	);
}

/**
 * Devuelve el timestamp interno en que una línea entró a preparación.
 *
 * @param array<string, mixed> $item Línea cruda de OpenPOS.
 */
function tavox_menu_api_get_openpos_item_preparing_started_at( array $item ): int {
	$custom_fields  = is_array( $item['custom_fields'] ?? null ) ? $item['custom_fields'] : [];
	$source_details = is_array( $item['source_details'] ?? null ) ? $item['source_details'] : [];
	$timestamp      = max(
		0,
		(int) (
			$item['tavox_preparing_started_at']
			?? $custom_fields['tavox_preparing_started_at']
			?? $source_details['preparing_started_at']
			?? 0
		)
	);

	if ( $timestamp > 0 ) {
		return $timestamp;
	}

	if ( 'cooking' === sanitize_key( (string) ( $item['state'] ?? '' ) ) ) {
		return max(
			0,
			(int) ( $item['update_time'] ?? 0 ),
			(int) ( $item['order_timestamp'] ?? 0 )
		);
	}

	return 0;
}

/**
 * Sincroniza el modo de consumo de una línea en todos los metadatos Tavox/OpenPOS.
 *
 * @param array<string, mixed> $item
 */
function tavox_menu_api_set_openpos_item_fulfillment_mode( array &$item, string $fulfillment_mode ): void {
	$normalized_mode = tavox_menu_api_sanitize_fulfillment_mode( $fulfillment_mode, 'dine_in' );
	$dining          = 'takeaway' === $normalized_mode ? 'takeaway' : 'dine_in';

	$item['dining'] = $dining;
	$item['fulfillment_mode'] = $normalized_mode;
	$item['source_details'] = is_array( $item['source_details'] ?? null ) ? $item['source_details'] : [];
	$item['custom_fields'] = is_array( $item['custom_fields'] ?? null ) ? $item['custom_fields'] : [];
	$item['source_details']['fulfillment_mode'] = $normalized_mode;
	$item['custom_fields']['fulfillment_mode'] = $normalized_mode;
}

/**
 * Sincroniza el timestamp de preparación de una línea.
 *
 * @param array<string, mixed> $item
 */
function tavox_menu_api_set_openpos_item_preparing_started_at( array &$item, int $timestamp_ms ): void {
	$item['tavox_preparing_started_at'] = max( 0, $timestamp_ms );
	$item['source_details'] = is_array( $item['source_details'] ?? null ) ? $item['source_details'] : [];
	$item['custom_fields'] = is_array( $item['custom_fields'] ?? null ) ? $item['custom_fields'] : [];
	$item['source_details']['preparing_started_at'] = max( 0, $timestamp_ms );
	$item['custom_fields']['tavox_preparing_started_at'] = max( 0, $timestamp_ms );
}

/**
 * Resume el cliente actual de un desk OpenPOS.
 *
 * @param array<string, mixed> $current_data Desk crudo.
 * @return array<string, mixed>
 */
function tavox_menu_api_build_openpos_customer_summary( array $current_data ): array {
	$customer = is_array( $current_data['customer'] ?? null ) ? $current_data['customer'] : [];
	$first_name = sanitize_text_field( (string) ( $customer['first_name'] ?? '' ) );
	$last_name  = sanitize_text_field( (string) ( $customer['last_name'] ?? '' ) );
	$full_name  = trim( $first_name . ' ' . $last_name );
	$raw_name   = sanitize_text_field(
		(string) (
			$customer['name']
			?? $customer['display_name']
			?? $current_data['customer_name']
			?? ''
		)
	);
	$raw_name = is_email( $raw_name ) ? '' : $raw_name;
	$phone    = sanitize_text_field(
		(string) (
			$customer['phone']
			?? $customer['phone_number']
			?? $customer['billing_phone']
			?? $current_data['customer_phone']
			?? ''
		)
	);
	$email = sanitize_email( (string) ( $customer['email'] ?? $current_data['customer_email'] ?? '' ) );
	$name  = '' !== $full_name ? $full_name : $raw_name;
	$display_name = $name ?: ( $phone ?: $email );
	$secondary_label = '';

	if ( '' !== $display_name ) {
		if ( $display_name === $name ) {
			$secondary_label = $phone ?: $email;
		} elseif ( $display_name === $phone ) {
			$secondary_label = $email;
		}
	}

	return [
		'id'    => absint( $customer['id'] ?? 0 ),
		'name'  => $name,
		'display_name' => $display_name,
		'secondary_label' => $secondary_label,
		'email' => $email,
		'phone' => $phone,
	];
}

/**
 * Resume el vendedor/mesero actual de un desk OpenPOS.
 *
 * @param array<string, mixed> $current_data Desk crudo.
 * @return array<string, mixed>
 */
function tavox_menu_api_build_openpos_seller_summary( array $current_data ): array {
	$seller = is_array( $current_data['seller'] ?? null ) ? $current_data['seller'] : [];
	$items  = is_array( $current_data['items'] ?? null ) ? $current_data['items'] : [];
	$item_seller_id = 0;
	$item_seller_name = '';

	foreach ( $items as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}

		$item_seller_id = absint( $item['seller_id'] ?? 0 );
		$item_seller_name = sanitize_text_field(
			(string) (
				$item['seller_name']
				?? $item['seller']['name']
				?? $item['seller']
				?? ''
			)
		);

		if ( $item_seller_id > 0 || '' !== $item_seller_name ) {
			break;
		}
	}

	$seller_id   = absint( $seller['id'] ?? $item_seller_id ?? 0 );
	$raw_name    = sanitize_text_field(
		(string) (
			$seller['name']
			?? $current_data['seller_name']
			?? $current_data['sale_person_name']
			?? $item_seller_name
			?? ''
		)
	);
	$display_name = function_exists( 'tavox_menu_api_resolve_waiter_staff_name' )
		? tavox_menu_api_resolve_waiter_staff_name( $seller_id, $raw_name )
		: $raw_name;

	return [
		'id'           => $seller_id,
		'name'         => $display_name,
		'display_name' => $display_name,
	];
}

/**
 * Construye la URL de entrada del frontend para una mesa.
 *
 * @param array<string, mixed> $table_context Contexto validado.
 */
function tavox_menu_api_get_table_entry_redirect_url( array $table_context ): string {
	$table_token = tavox_menu_api_build_table_token( $table_context );
	$base_url    = tavox_menu_api_get_frontend_base_url();
	$mesa_url    = add_query_arg(
		[
			'table_token' => rawurlencode( $table_token ),
		],
		untrailingslashit( $base_url ) . '/mesa'
	);

	return add_query_arg(
		[
			'open' => wp_parse_url( $mesa_url, PHP_URL_PATH ) . ( wp_parse_url( $mesa_url, PHP_URL_QUERY ) ? '?' . wp_parse_url( $mesa_url, PHP_URL_QUERY ) : '' ),
		],
		untrailingslashit( $base_url ) . '/'
	);
}

/**
 * Devuelve la estructura base de una mesa OpenPOS cuando todavía no tiene consumo.
 *
 * @param array<string, mixed> $table_context Contexto validado.
 * @param array<string, mixed> $request_row   Solicitud aceptada.
 * @return array<string, mixed>
 */
function tavox_menu_api_get_empty_openpos_desk_payload( array $table_context, array $request_row ): array {
	$now_ms     = time() * 1000;
	$table_name = (string) ( $table_context['table_name'] ?? '' );
	$table_id   = absint( $table_context['table_id'] ?? 0 );
	$type       = 'takeaway' === ( $table_context['table_type'] ?? '' ) ? 'takeaway' : 'dine_in';

	return [
		'id'                => $table_id,
		'label'             => $table_name,
		'desk'              => [
			'id'           => $table_id,
			'name'         => $table_name,
			'type'         => $type,
			'warehouse_id' => absint( $table_context['warehouse_id'] ?? 0 ),
		],
		'order_number'      => $table_id,
		'parent'            => 0,
		'child_desks'       => [],
		'ver'               => $now_ms,
		'online_ver'        => $now_ms,
		'system_ver'        => $now_ms,
		'collection'        => 0,
		'cost'              => 0,
		'start_time'        => 0,
		'seat'              => 0,
		'total_qty'         => 0,
		'serverd_qty'       => 0,
		'seller'            => [
			'id'   => absint( $request_row['waiter_user_id'] ?? 0 ),
			'name' => sanitize_text_field( (string) ( $request_row['waiter_name'] ?? '' ) ),
		],
		'customer'          => [],
		'type'              => $type,
		'created_at_time'   => $now_ms,
		'items'             => [],
		'fee_item'          => null,
		'note'              => sanitize_textarea_field( (string) ( $request_row['global_note'] ?? '' ) ),
		'source'            => 'tavox_menu_api',
		'source_type'       => 'table_request',
		'source_details'    => [
			'request_id'  => absint( $request_row['id'] ?? 0 ),
			'request_key' => sanitize_key( (string) ( $request_row['request_key'] ?? '' ) ),
		],
		'state'             => '',
		'tag'               => '',
		'dining'            => $type,
		'messages'          => '',
		'session'           => 'tavox-' . absint( $request_row['id'] ?? 0 ),
		'sub_total_incl_tax'=> 0,
	];
}

/**
 * Convierte una línea del carrito Tavox al formato de item que OpenPOS espera.
 *
 * @param array<string, mixed> $cart_item    Línea serializada.
 * @param array<string, mixed> $request_row  Solicitud aceptada.
 * @return array<string, mixed>
 */
function tavox_menu_api_map_cart_item_to_openpos_item( array $cart_item, array $request_row ): array {
	$product_id   = absint( $cart_item['productId'] ?? $cart_item['product_id'] ?? 0 );
	$product      = $product_id > 0 ? wc_get_product( $product_id ) : null;
	$default_fulfillment_mode = 'takeaway' === sanitize_key( (string) ( $request_row['table_type'] ?? 'dine_in' ) ) ? 'takeaway' : 'dine_in';
	$fulfillment_mode = tavox_menu_api_sanitize_fulfillment_mode(
		(string) ( $cart_item['fulfillment_mode'] ?? $cart_item['fulfillmentMode'] ?? '' ),
		$default_fulfillment_mode
	);
	$qty          = max( 1, (float) ( $cart_item['qty'] ?? 1 ) );
	$base_price   = (float) ( $cart_item['basePrice'] ?? $cart_item['price_usd'] ?? $cart_item['price'] ?? 0 );
	$extras       = is_array( $cart_item['extras'] ?? null ) ? $cart_item['extras'] : [];
	$extras_total     = 0.0;
	$modifier_labels  = [];

	foreach ( $extras as $extra ) {
		if ( ! is_array( $extra ) ) {
			continue;
		}

		$extras_total += (float) ( $extra['price'] ?? $extra['price_usd'] ?? 0 );
		if ( ! empty( $extra['label'] ) ) {
			$modifier_labels[] = sanitize_text_field( (string) $extra['label'] );
		}
	}

	$modifiers_label = implode( ' · ', array_filter( $modifier_labels ) );
	$customer_note   = sanitize_text_field( (string) ( $cart_item['note'] ?? '' ) );
	$sub_notes       = array_values( array_filter( [ $modifiers_label, $customer_note ] ) );

	$unit_price = max( 0, $base_price + $extras_total );
	$total      = $unit_price * $qty;
	$now_ms     = time() * 1000;
	$product_name = sanitize_text_field( (string) ( $cart_item['name'] ?? ( $product instanceof WC_Product ? $product->get_name() : '' ) ) );
	$product_image = '';

	if ( $product instanceof WC_Product && $product->get_image_id() ) {
		$product_image = (string) wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' );
	}

	return [
		'id'                       => $now_ms + wp_rand( 1, 999 ),
		'item_parent_id'           => 0,
		'name'                     => $product_name,
		'barcode'                  => (string) ( $product instanceof WC_Product ? $product->get_sku() : '' ),
		'sub_name'                 => implode( ' · ', array_filter( $sub_notes ) ),
		'dining'                   => 'takeaway' === $fulfillment_mode ? 'takeaway' : 'dine_in',
		'price'                    => $unit_price,
		'price_incl_tax'           => $unit_price,
		'product_id'               => $product_id,
		'custom_price'             => null,
		'final_price'              => $unit_price,
		'final_price_incl_tax'     => $unit_price,
		'final_price_source'       => '',
		'batches'                  => null,
		'options'                  => [],
		'bundles'                  => [],
		'variations'               => [],
		'rule_discount'            => [],
		'discount_source'          => '',
		'discount_amount'          => 0,
		'discount_type'            => 'fixed',
		'final_discount_amount'    => 0,
		'final_discount_amount_incl_tax' => 0,
		'qty'                      => $qty,
		'refund_qty'               => 0,
		'exchange_qty'             => 0,
		'refund_total'             => 0,
		'tax_amount'               => 0,
		'total_tax'                => 0,
		'total'                    => $total,
		'total_incl_tax'           => $total,
		'product'                  => [
			'name'               => $product_name,
			'id'                 => $product_id,
			'parent_id'          => $product_id,
			'sku'                => (string) ( $product instanceof WC_Product ? $product->get_sku() : '' ),
			'qty'                => $product instanceof WC_Product && $product->get_manage_stock() ? (int) $product->get_stock_quantity() : -1,
			'manage_stock'       => $product instanceof WC_Product ? (bool) $product->get_manage_stock() : false,
			'stock_status'       => $product instanceof WC_Product ? (string) $product->get_stock_status() : 'instock',
			'barcode'            => (string) ( $product instanceof WC_Product ? $product->get_sku() : '' ),
			'image'              => $product_image,
			'price'              => $unit_price,
			'price_incl_tax'     => $unit_price,
			'final_price'        => $unit_price,
			'special_price'      => $unit_price,
			'regular_price'      => $unit_price,
			'sale_from'          => null,
			'sale_to'            => null,
			'status'             => 'publish',
			'categories'         => $product instanceof WC_Product ? array_map( 'strval', $product->get_category_ids() ) : [],
			'tax'                => [],
			'tax_amount'         => 0,
			'price_included_tax' => 0,
			'group_items'        => [],
			'variations'         => [],
			'options'            => [],
			'bundles'            => [],
			'display_special_price' => false,
			'allow_change_price' => false,
			'price_display_html' => '',
			'display'            => true,
			'type'               => '',
			'custom_notes'       => [],
			'search_keyword'     => sanitize_title( $product_name ),
		],
		'option_pass'              => true,
		'option_total'             => 0,
		'option_total_tax'         => 0,
		'option_total_excl_tax'    => 0,
		'bundle_total'             => 0,
		'note'                     => $customer_note,
		'parent_id'                => 0,
		'seller_id'                => absint( $request_row['waiter_user_id'] ?? 0 ),
		'seller_name'              => sanitize_text_field( (string) ( $request_row['waiter_name'] ?? '' ) ),
		'item_type'                => '',
		'has_custom_discount'      => false,
		'has_price_change'         => false,
		'has_custom_price_change'  => false,
		'disable_qty_change'       => false,
		'read_only'                => false,
		'promotion_added'          => 0,
		'tax_details'              => [],
		'custom_fields'            => [],
		'is_exchange'              => false,
		'update_time'              => $now_ms,
		'order_time'               => wp_date( 'H:i' ),
		'order_timestamp'          => $now_ms,
		'source'                   => 'tavox_menu_api',
		'source_details'           => [
			'request_id'  => absint( $request_row['id'] ?? 0 ),
			'request_key' => sanitize_key( (string) ( $request_row['request_key'] ?? '' ) ),
			'fulfillment_mode' => $fulfillment_mode,
			'modifiers_label'  => $modifiers_label,
			'customer_note'    => $customer_note,
		],
		'tavox_request_id'         => absint( $request_row['id'] ?? 0 ),
		'tavox_request_key'        => sanitize_key( (string) ( $request_row['request_key'] ?? '' ) ),
		'fulfillment_mode'         => $fulfillment_mode,
		'tavox_modifiers_label'    => $modifiers_label,
		'tavox_customer_note'      => $customer_note,
		'custom_fields'            => [
			'tavox_request_id'       => absint( $request_row['id'] ?? 0 ),
			'tavox_request_key'      => sanitize_key( (string) ( $request_row['request_key'] ?? '' ) ),
			'fulfillment_mode'       => $fulfillment_mode,
			'tavox_modifiers_label'  => $modifiers_label,
			'tavox_customer_note'    => $customer_note,
		],
		'state'                    => 'new',
		'done'                     => '',
	];
}

/**
 * Empuja una solicitud aceptada al desk real de OpenPOS.
 *
 * @param array<string, mixed> $request_row Solicitud aceptada.
 * @return array<string, mixed>|WP_Error
 */
function tavox_menu_api_build_openpos_request_write_summary( array $desk_data, array $request_row ): array {
	$items          = is_array( $desk_data['items'] ?? null ) ? $desk_data['items'] : [];
	$request_id     = absint( $request_row['id'] ?? 0 );
	$request_key    = sanitize_key( (string) ( $request_row['request_key'] ?? '' ) );
	$expected_name  = sanitize_text_field( (string) ( $request_row['waiter_name'] ?? '' ) );
	$expected_user  = absint( $request_row['waiter_user_id'] ?? 0 );
	$matched_lines  = 0;
	$matched_line_ids = [];

	foreach ( $items as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}

		$meta = tavox_menu_api_get_openpos_item_request_meta( $item );
		$matches_request = ( $request_id > 0 && absint( $meta['request_id'] ?? 0 ) === $request_id )
			|| ( '' !== $request_key && sanitize_key( (string) ( $meta['request_key'] ?? '' ) ) === $request_key );

		if ( ! $matches_request ) {
			continue;
		}

		$matched_lines++;
		$matched_line_ids[] = tavox_menu_api_get_openpos_item_line_id( $item );
	}

	$seller = tavox_menu_api_build_openpos_seller_summary( $desk_data );
	$seller_name = sanitize_text_field( (string) ( $seller['display_name'] ?? $seller['name'] ?? '' ) );
	$seller_matches = ( $expected_user > 0 && absint( $seller['id'] ?? 0 ) === $expected_user );

	if ( ! $seller_matches && '' !== $expected_name && '' !== $seller_name ) {
		$seller_matches = strtolower( trim( $seller_name ) ) === strtolower( trim( $expected_name ) );
	}

	return [
		'lines_count'       => count( $items ),
		'total_qty'         => (float) ( $desk_data['total_qty'] ?? 0 ),
		'total_amount'      => (float) ( $desk_data['sub_total_incl_tax'] ?? $desk_data['collection'] ?? $desk_data['cost'] ?? 0 ),
		'matched_lines'     => $matched_lines,
		'matched_line_ids'  => array_values( array_filter( $matched_line_ids ) ),
		'seller_id'         => absint( $seller['id'] ?? 0 ),
		'seller_name'       => $seller_name,
		'seller_matches'    => $seller_matches,
		'desk_version'      => tavox_menu_api_get_openpos_data_version( $desk_data ),
		'has_activity'      => tavox_menu_api_openpos_data_has_activity( $desk_data ),
	];
}

/**
 * Confirma si la escritura de un request quedó realmente reflejada en el desk.
 *
 * @return array<string, mixed>
 */
function tavox_menu_api_assess_openpos_request_write_confirmation( array $before_desk, array $after_desk, array $request_row ): array {
	$before = tavox_menu_api_build_openpos_request_write_summary( $before_desk, $request_row );
	$after  = tavox_menu_api_build_openpos_request_write_summary( $after_desk, $request_row );

	$lines_grew  = (int) $after['lines_count'] > (int) $before['lines_count'];
	$qty_grew    = (float) $after['total_qty'] > (float) $before['total_qty'];
	$amount_grew = (float) $after['total_amount'] > (float) $before['total_amount'];
	$has_markers = (int) $after['matched_lines'] > 0;
	$confirmed   = $has_markers || ( ! empty( $after['seller_matches'] ) && ( $lines_grew || $qty_grew || $amount_grew ) );

	return [
		'confirmed'             => $confirmed,
		'has_request_markers'   => $has_markers,
		'lines_grew'            => $lines_grew,
		'qty_grew'              => $qty_grew,
		'amount_grew'           => $amount_grew,
		'pre_write_summary'     => $before,
		'post_write_summary'    => $after,
	];
}

/**
 * Resume la confirmación de líneas que debieron pasar a preparación.
 *
 * @param array<string, mixed> $desk_data
 * @param string[]             $target_line_ids
 * @return array<string, mixed>
 */
function tavox_menu_api_build_openpos_preparing_write_summary( array $desk_data, array $target_line_ids ): array {
	$items         = is_array( $desk_data['items'] ?? null ) ? $desk_data['items'] : [];
	$targets       = array_values(
		array_filter(
			array_map(
				static fn( $value ): string => trim( (string) $value ),
				$target_line_ids
			)
		)
	);
	$target_map    = array_fill_keys( $targets, true );
	$confirmed_ids = [];
	$line_state    = [];

	foreach ( $items as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}

		$line_id = tavox_menu_api_get_openpos_item_line_id( $item );
		if ( '' === $line_id || ! isset( $target_map[ $line_id ] ) ) {
			continue;
		}

		$service_state = tavox_menu_api_get_openpos_item_service_state( $item );
		$started_at    = tavox_menu_api_get_openpos_item_preparing_started_at( $item );

		$line_state[ $line_id ] = [
			'service_state'        => $service_state,
			'state'                => sanitize_key( (string) ( $item['state'] ?? '' ) ),
			'done'                 => tavox_menu_api_get_openpos_item_done_state( $item ),
			'preparing_started_at' => $started_at,
		];

		if ( 'preparing' === $service_state && $started_at > 0 ) {
			$confirmed_ids[] = $line_id;
		}
	}

	$confirmed_ids = array_values( array_unique( $confirmed_ids ) );

	return [
		'target_line_ids'    => $targets,
		'confirmed_line_ids' => $confirmed_ids,
		'missing_line_ids'   => array_values( array_diff( $targets, $confirmed_ids ) ),
		'confirmed_count'    => count( $confirmed_ids ),
		'target_count'       => count( $targets ),
		'line_state'         => $line_state,
		'desk_version'       => tavox_menu_api_get_openpos_data_version( $desk_data ),
		'has_activity'       => tavox_menu_api_openpos_data_has_activity( $desk_data ),
	];
}

/**
 * Confirma si la transición a preparación quedó persistida en el desk real.
 *
 * @param array<string, mixed> $before_desk
 * @param array<string, mixed> $after_desk
 * @param string[]             $target_line_ids
 * @return array<string, mixed>
 */
function tavox_menu_api_assess_openpos_preparing_write_confirmation( array $before_desk, array $after_desk, array $target_line_ids ): array {
	$before    = tavox_menu_api_build_openpos_preparing_write_summary( $before_desk, $target_line_ids );
	$after     = tavox_menu_api_build_openpos_preparing_write_summary( $after_desk, $target_line_ids );
	$confirmed = $after['target_count'] > 0 && $after['confirmed_count'] === $after['target_count'];

	return [
		'confirmed'          => $confirmed,
		'pre_write_summary'  => $before,
		'post_write_summary' => $after,
	];
}

/**
 * Resume la confirmación de líneas que debieron pasar a listo.
 *
 * @param array<string, mixed> $desk_data
 * @param string[]             $target_line_ids
 * @return array<string, mixed>
 */
function tavox_menu_api_build_openpos_ready_write_summary( array $desk_data, array $target_line_ids ): array {
	$items         = is_array( $desk_data['items'] ?? null ) ? $desk_data['items'] : [];
	$targets       = array_values(
		array_filter(
			array_map(
				static fn( $value ): string => trim( (string) $value ),
				$target_line_ids
			)
		)
	);
	$target_map    = array_fill_keys( $targets, true );
	$confirmed_ids = [];
	$line_state    = [];

	foreach ( $items as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}

		$line_id = tavox_menu_api_get_openpos_item_line_id( $item );
		if ( '' === $line_id || ! isset( $target_map[ $line_id ] ) ) {
			continue;
		}

		$service_state = tavox_menu_api_get_openpos_item_service_state( $item );
		$line_state[ $line_id ] = [
			'service_state' => $service_state,
			'state'         => sanitize_key( (string) ( $item['state'] ?? '' ) ),
			'done'          => tavox_menu_api_get_openpos_item_done_state( $item ),
			'served_qty'    => (float) ( $item['serverd_qty'] ?? $item['served_qty'] ?? 0 ),
		];

		if ( 'ready' === $service_state ) {
			$confirmed_ids[] = $line_id;
		}
	}

	$confirmed_ids = array_values( array_unique( $confirmed_ids ) );

	return [
		'target_line_ids'    => $targets,
		'confirmed_line_ids' => $confirmed_ids,
		'missing_line_ids'   => array_values( array_diff( $targets, $confirmed_ids ) ),
		'confirmed_count'    => count( $confirmed_ids ),
		'target_count'       => count( $targets ),
		'line_state'         => $line_state,
		'desk_version'       => tavox_menu_api_get_openpos_data_version( $desk_data ),
		'has_activity'       => tavox_menu_api_openpos_data_has_activity( $desk_data ),
	];
}

/**
 * Confirma si la transición a listo quedó persistida en el desk real.
 *
 * @param array<string, mixed> $before_desk
 * @param array<string, mixed> $after_desk
 * @param string[]             $target_line_ids
 * @return array<string, mixed>
 */
function tavox_menu_api_assess_openpos_ready_write_confirmation( array $before_desk, array $after_desk, array $target_line_ids ): array {
	$before    = tavox_menu_api_build_openpos_ready_write_summary( $before_desk, $target_line_ids );
	$after     = tavox_menu_api_build_openpos_ready_write_summary( $after_desk, $target_line_ids );
	$confirmed = $after['target_count'] > 0 && $after['confirmed_count'] === $after['target_count'];

	return [
		'confirmed'          => $confirmed,
		'pre_write_summary'  => $before,
		'post_write_summary' => $after,
	];
}

/**
 * Fuerza una persistencia directa del desk cuando update_bill_screen no refleja cambios.
 *
 * @param object               $op_table     Instancia viva de OP_Table.
 * @param int|string           $table_id     Identidad numérica de la mesa.
 * @param string               $type         Tipo Tavox/OpenPOS de la mesa.
 * @param array<string, mixed> $desk_data    Snapshot exacto a persistir.
 * @return bool
 */
function tavox_menu_api_force_persist_openpos_desk( $op_table, $table_id, string $type, array $desk_data ): bool {
	if ( ! is_object( $op_table ) ) {
		return false;
	}

	if ( method_exists( $op_table, 'update_table_bill_screen' ) ) {
		$op_table->update_table_bill_screen( $table_id, $desk_data, $type );
		return true;
	}

	if ( method_exists( $op_table, 'update_data' ) ) {
		$warehouse_id = absint( $desk_data['desk']['warehouse'] ?? $desk_data['desk']['warehouse_id'] ?? 0 );
		$op_table->update_data( $desk_data, $table_id, $type, $warehouse_id );

		if ( method_exists( $op_table, 'update_kitchen_data' ) ) {
			$op_table->update_kitchen_data( $warehouse_id );
		}

		return true;
	}

	return false;
}

function tavox_menu_api_push_request_to_openpos( array $request_row ) {
	global $op_table;

	tavox_menu_api_boot_openpos_services();

	if ( ! isset( $op_table ) || ! is_object( $op_table ) || ! method_exists( $op_table, 'update_bill_screen' ) ) {
		return new WP_Error(
			'openpos_unavailable',
			__( 'No pudimos conectar con OpenPOS para cargar el pedido.', 'tavox-menu-api' ),
			[ 'status' => 503 ]
		);
	}

	$table_key = (string) ( $request_row['table_key'] ?? '' );
	if ( '' !== $table_key ) {
		$table_context = tavox_menu_api_get_openpos_table_context_by_key( $table_key );
		if ( ! is_wp_error( $table_context ) ) {
			goto table_context_ready;
		}
	}

	$table_context = tavox_menu_api_get_openpos_table_context_by_identity(
		absint( $request_row['table_id'] ?? 0 ),
		(string) ( $request_row['table_type'] ?? 'dine_in' ),
		absint( $request_row['register_id'] ?? 0 ),
		absint( $request_row['warehouse_id'] ?? 0 ),
		(string) ( $request_row['table_name'] ?? '' )
	);
	if ( is_wp_error( $table_context ) ) {
		return $table_context;
	}

table_context_ready:

	$payload = json_decode( (string) ( $request_row['payload'] ?? '' ), true );
	if ( ! is_array( $payload ) || empty( $payload['items'] ) || ! is_array( $payload['items'] ) ) {
		return new WP_Error( 'invalid_payload', __( 'El pedido no tiene productos válidos para subir a la mesa.', 'tavox-menu-api' ), [ 'status' => 400 ] );
	}

	$type      = 'takeaway' === ( $table_context['table_type'] ?? '' ) ? 'takeaway' : 'dine_in';
	$table_id  = absint( $table_context['table_id'] ?? 0 );
	$warehouse_id = absint( $table_context['warehouse_id'] ?? 0 );
	$desk_ref  = ( 'takeaway' === $type ? 'takeaway-' : 'desk-' ) . $table_id;
	$desk_data = is_array( $table_context['current_data'] ?? null ) && ! empty( $table_context['current_data'] )
		? $table_context['current_data']
		: tavox_menu_api_get_empty_openpos_desk_payload( $table_context, $request_row );
	$desk_before = tavox_menu_api_get_openpos_live_table_data(
		$table_id,
		$type,
		$warehouse_id
	);
	if ( empty( $desk_before ) ) {
		$desk_before = $desk_data;
	}

	$items = is_array( $desk_data['items'] ?? null ) ? $desk_data['items'] : [];
	foreach ( $payload['items'] as $cart_item ) {
		if ( ! is_array( $cart_item ) ) {
			continue;
		}

		$items[] = tavox_menu_api_map_cart_item_to_openpos_item( $cart_item, $request_row );
	}

	$total_qty = 0.0;
	$total_amount = 0.0;
	foreach ( $items as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}

		$total_qty += (float) ( $item['qty'] ?? 0 );
		$total_amount += (float) ( $item['total_incl_tax'] ?? $item['total'] ?? 0 );
	}

	$desk_data['items']               = $items;
	$desk_data['note']                = sanitize_textarea_field( (string) ( $request_row['global_note'] ?? '' ) );
	$desk_data['seller']              = [
		'id'   => absint( $request_row['waiter_user_id'] ?? 0 ),
		'name' => sanitize_text_field( (string) ( $request_row['waiter_name'] ?? '' ) ),
	];
	$desk_data['desk']                = is_array( $desk_data['desk'] ?? null ) ? $desk_data['desk'] : [];
	$desk_data['desk']['id']          = $table_id;
	$desk_data['desk']['name']        = sanitize_text_field( (string) ( $desk_data['desk']['name'] ?? $table_context['table_name'] ?? '' ) );
	$desk_data['desk']['warehouse']   = absint( $desk_data['desk']['warehouse'] ?? $desk_data['desk']['warehouse_id'] ?? $warehouse_id );
	$desk_data['desk']['warehouse_id']= absint( $desk_data['desk']['warehouse_id'] ?? $desk_data['desk']['warehouse'] ?? $warehouse_id );
	$desk_data['desk']['type']        = sanitize_key( (string) ( $desk_data['desk']['type'] ?? ( 'takeaway' === $type ? 'takeaway' : 'default' ) ) );
	$desk_data['desk']['status']      = sanitize_key( (string) ( $desk_data['desk']['status'] ?? 'publish' ) );
	$desk_data['desk']['seat']        = absint( $desk_data['desk']['seat'] ?? ( $table_context['raw_table']['seat'] ?? 0 ) );
	$desk_data['desk']['position']    = absint( $desk_data['desk']['position'] ?? ( $table_context['raw_table']['position'] ?? 0 ) );
	$desk_data['desk']['cost']        = (float) ( $desk_data['desk']['cost'] ?? ( $table_context['raw_table']['cost'] ?? 0 ) );
	$desk_data['desk']['cost_type']   = sanitize_key( (string) ( $desk_data['desk']['cost_type'] ?? ( $table_context['raw_table']['cost_type'] ?? '' ) ) );
	$desk_data['total_qty']           = $total_qty;
	$desk_data['sub_total_incl_tax']  = $total_amount;
	$desk_data['source']              = 'tavox_menu_api';
	$desk_data['source_type']         = 'table_request';
	$desk_data['source_details']      = [
		'request_id'       => absint( $request_row['id'] ?? 0 ),
		'request_key'      => sanitize_key( (string) ( $request_row['request_key'] ?? '' ) ),
		'brand_scope'      => sanitize_key( (string) ( $request_row['brand_scope'] ?? 'zona_b' ) ),
		'fulfillment_mode' => tavox_menu_api_sanitize_fulfillment_mode(
			(string) ( $payload['fulfillment_mode'] ?? '' ),
			'takeaway' === $type ? 'takeaway' : 'dine_in'
		),
	];
	$desk_data['messages']            = sanitize_text_field( (string) ( $desk_data['messages'] ?? '' ) );
	$desk_data                        = tavox_menu_api_prepare_openpos_write_versions( $desk_data );

	try {
		$result = $op_table->update_bill_screen(
			[
				$desk_ref => $desk_data,
			],
			false,
			'tavox'
		);
	} catch ( Throwable $error ) {
		tavox_menu_api_log_operational_event(
			'request_accept_openpos_write_failed',
			[
				'request_id'  => absint( $request_row['id'] ?? 0 ),
				'request_key' => sanitize_key( (string) ( $request_row['request_key'] ?? '' ) ),
				'table_id'    => $table_id,
				'table_name'  => sanitize_text_field( (string) ( $request_row['table_name'] ?? '' ) ),
				'desk_ref'    => $desk_ref,
				'error'       => $error->getMessage(),
			]
		);

		return new WP_Error(
			'openpos_write_failed',
			__( 'OpenPOS rechazó la actualización de esta mesa. Intenta de nuevo.', 'tavox-menu-api' ),
			[
				'status'  => 500,
				'message' => $error->getMessage(),
			]
		);
	}

	$confirmation = [];
	$desk_after   = [];

	for ( $attempt = 0; $attempt < 3; $attempt++ ) {
		$desk_after = tavox_menu_api_get_openpos_live_table_data(
			$table_id,
			$type,
			$warehouse_id
		);
		$confirmation = tavox_menu_api_assess_openpos_request_write_confirmation( $desk_before, $desk_after, $request_row );

		if ( ! empty( $confirmation['confirmed'] ) ) {
			break;
		}

		if ( $attempt < 2 ) {
			usleep( 250000 );
		}
	}

	if ( empty( $confirmation['confirmed'] ) ) {
		$forced_write = false;

		try {
			$forced_write = tavox_menu_api_force_persist_openpos_desk( $op_table, $table_id, $type, $desk_data );
		} catch ( Throwable $error ) {
			tavox_menu_api_log_operational_event(
				'request_accept_openpos_force_persist_failed',
				[
					'request_id'  => absint( $request_row['id'] ?? 0 ),
					'request_key' => sanitize_key( (string) ( $request_row['request_key'] ?? '' ) ),
					'table_id'    => $table_id,
					'table_name'  => sanitize_text_field( (string) ( $request_row['table_name'] ?? '' ) ),
					'desk_ref'    => $desk_ref,
					'error'       => $error->getMessage(),
				]
			);
		}

		if ( $forced_write ) {
			for ( $attempt = 0; $attempt < 3; $attempt++ ) {
				$desk_after = tavox_menu_api_get_openpos_live_table_data(
					$table_id,
					$type,
					$warehouse_id
				);
				$confirmation = tavox_menu_api_assess_openpos_request_write_confirmation( $desk_before, $desk_after, $request_row );

				if ( ! empty( $confirmation['confirmed'] ) ) {
					break;
				}

				if ( $attempt < 2 ) {
					usleep( 250000 );
				}
			}

			if ( ! empty( $confirmation['confirmed'] ) ) {
				tavox_menu_api_log_operational_event(
					'request_accept_openpos_force_persist_confirmed',
					[
						'request_id'   => absint( $request_row['id'] ?? 0 ),
						'request_key'  => sanitize_key( (string) ( $request_row['request_key'] ?? '' ) ),
						'table_id'     => $table_id,
						'table_name'   => sanitize_text_field( (string) ( $request_row['table_name'] ?? '' ) ),
						'desk_ref'     => $desk_ref,
						'confirmation' => $confirmation,
					]
				);
			}
		}
	}

	if ( empty( $confirmation['confirmed'] ) ) {
		tavox_menu_api_log_operational_event(
			'request_accept_openpos_write_unconfirmed',
			[
				'request_id'   => absint( $request_row['id'] ?? 0 ),
				'request_key'  => sanitize_key( (string) ( $request_row['request_key'] ?? '' ) ),
				'table_id'     => $table_id,
				'table_name'   => sanitize_text_field( (string) ( $request_row['table_name'] ?? '' ) ),
				'desk_ref'     => $desk_ref,
				'update_result'=> is_array( $result ) ? array_keys( $result ) : gettype( $result ),
				'confirmation' => $confirmation,
			]
		);

		return new WP_Error(
			'openpos_write_unconfirmed',
			__( 'No pudimos confirmar que el pedido quedara cargado en la mesa real.', 'tavox-menu-api' ),
			[
				'status'      => 409,
				'desk_ref'    => $desk_ref,
				'confirmation'=> $confirmation,
			]
		);
	}

	return [
		'desk_ref'              => $desk_ref,
		'result'                => $result,
		'desk'                  => $desk_after,
		'write_confirmed'       => true,
		'write_mode'            => ! empty( $confirmation['has_request_markers'] ) ? 'update_bill_screen' : 'force_persist',
		'post_write_lines_count'=> (int) ( $confirmation['post_write_summary']['lines_count'] ?? 0 ),
		'post_write_total'      => (float) ( $confirmation['post_write_summary']['total_amount'] ?? 0 ),
		'confirmation'          => $confirmation,
	];
}

/**
 * Marca como entregadas todas las líneas listas de una mesa o pedido para llevar.
 *
 * @param array<string, mixed> $table_context Contexto OpenPOS ya validado.
 * @param array<string, mixed> $actor         Usuario que confirma la entrega.
 * @return array<string, mixed>|WP_Error
 */
function tavox_menu_api_mark_ready_items_delivered( array $table_context, array $actor = [] ) {
	global $op_table;

	tavox_menu_api_boot_openpos_services();

	if ( ! isset( $op_table ) || ! is_object( $op_table ) || ! method_exists( $op_table, 'update_bill_screen' ) ) {
		return new WP_Error( 'openpos_unavailable', __( 'No pudimos actualizar esta mesa en este momento.', 'tavox-menu-api' ), [ 'status' => 503 ] );
	}

	$type      = 'takeaway' === ( $table_context['table_type'] ?? '' ) ? 'takeaway' : 'dine_in';
	$table_id  = absint( $table_context['table_id'] ?? 0 );
	$desk_ref  = ( 'takeaway' === $type ? 'takeaway-' : 'desk-' ) . $table_id;
	$desk_data = tavox_menu_api_get_openpos_live_table_data(
		$table_id,
		$type,
		absint( $table_context['warehouse_id'] ?? 0 )
	);

	if ( empty( $desk_data ) || ! is_array( $desk_data ) ) {
		return new WP_Error( 'desk_not_found', __( 'No pudimos encontrar el consumo actual para marcar la entrega.', 'tavox-menu-api' ), [ 'status' => 404 ] );
	}

	$items      = is_array( $desk_data['items'] ?? null ) ? $desk_data['items'] : [];
	$updated    = 0;
	$now_ms     = (int) round( microtime( true ) * 1000 );
	$actor_name = sanitize_text_field( (string) ( $actor['name'] ?? '' ) );
	$actor_id   = absint( $actor['id'] ?? 0 );
	$request_ids = [];
	$request_keys = [];

	foreach ( $items as &$item ) {
		if ( ! is_array( $item ) || 'ready' !== tavox_menu_api_get_openpos_item_service_state( $item ) ) {
			continue;
		}

		$request_meta = tavox_menu_api_get_openpos_item_request_meta( $item );
		if ( absint( $request_meta['request_id'] ?? 0 ) > 0 ) {
			$request_ids[] = absint( $request_meta['request_id'] );
		}
		if ( ! empty( $request_meta['request_key'] ) ) {
			$request_keys[] = sanitize_key( (string) $request_meta['request_key'] );
		}

		$qty                = (float) ( $item['qty'] ?? 0 );
		$item['done']       = 'done_all';
		$item['serverd_qty']= $qty;
		$item['served_qty'] = $qty;
		$item['update_time']= $now_ms;

		if ( $actor_id > 0 ) {
			$item['seller_id'] = $actor_id;
		}

		if ( '' !== $actor_name ) {
			$item['seller_name'] = $actor_name;
		}

		$updated++;
	}
	unset( $item );

	if ( $updated < 1 ) {
		return new WP_Error( 'nothing_ready', __( 'Todavía no hay nada listo para entregar aquí.', 'tavox-menu-api' ), [ 'status' => 409 ] );
	}

	$desk_data['items']      = $items;
	$desk_data['source']     = 'tavox_menu_api';
	$desk_data['messages']   = sanitize_text_field( (string) ( $desk_data['messages'] ?? '' ) );
	$desk_data               = tavox_menu_api_prepare_openpos_write_versions( $desk_data );

	try {
		$result = $op_table->update_bill_screen(
			[
				$desk_ref => $desk_data,
			],
			false,
			'tavox'
		);
	} catch ( Throwable $error ) {
		$retry_attempted = ! empty( $actor['__retried'] );
		if ( ! $retry_attempted && tavox_menu_api_is_openpos_concurrent_update_error( $error ) ) {
			$fresh_context = tavox_menu_api_refresh_openpos_table_context_snapshot( $table_context );
			if ( ! is_wp_error( $fresh_context ) ) {
				$actor['__retried'] = 1;
				return tavox_menu_api_mark_ready_items_delivered( $fresh_context, $actor );
			}
		}

		return new WP_Error(
			'desk_write_conflict',
			tavox_menu_api_is_openpos_concurrent_update_error( $error )
				? __( 'La mesa cambió mientras intentábamos confirmar la entrega. Reintenta una vez más.', 'tavox-menu-api' )
				: __( 'No pudimos marcar esta mesa como entregada en este momento.', 'tavox-menu-api' ),
			[
				'status' => tavox_menu_api_is_openpos_concurrent_update_error( $error ) ? 409 : 500,
			]
		);
	}

	return [
		'desk_ref'       => $desk_ref,
		'updated_lines'  => $updated,
		'request_ids'    => array_values( array_unique( array_filter( $request_ids ) ) ),
		'request_keys'   => array_values( array_unique( array_filter( $request_keys ) ) ),
		'result'         => $result,
		'desk'           => $desk_data,
	];
}

/**
 * Marca como en preparación los productos pendientes de una estación.
 *
 * @param array<string, mixed> $table_context Contexto OpenPOS ya validado.
 * @param string               $station       kitchen|bar|horno.
 * @param array<string, mixed> $actor         Usuario que inicia la preparación.
 * @param array<string, mixed> $options       Selección por línea o por lote.
 * @return array<string, mixed>|WP_Error
 */
function tavox_menu_api_mark_station_items_preparing( array $table_context, string $station, array $actor = [], array $options = [] ) {
	global $op_table;

	tavox_menu_api_boot_openpos_services();

	if ( ! isset( $op_table ) || ! is_object( $op_table ) || ! method_exists( $op_table, 'update_bill_screen' ) ) {
		return new WP_Error( 'service_unavailable', __( 'No pudimos actualizar este pedido en este momento.', 'tavox-menu-api' ), [ 'status' => 503 ] );
	}

	$station      = tavox_menu_api_sanitize_production_station( $station, 'kitchen' );
	$type         = 'takeaway' === ( $table_context['table_type'] ?? '' ) ? 'takeaway' : 'dine_in';
	$table_id     = absint( $table_context['table_id'] ?? 0 );
	$desk_ref     = ( 'takeaway' === $type ? 'takeaway-' : 'desk-' ) . $table_id;
	$warehouse_id = absint( $table_context['warehouse_id'] ?? 0 );
	$desk_data    = tavox_menu_api_get_openpos_live_table_data(
		$table_id,
		$type,
		$warehouse_id
	);
	$desk_before = $desk_data;

	if ( empty( $desk_data ) || ! is_array( $desk_data ) ) {
		return new WP_Error( 'desk_not_found', __( 'No encontramos el pedido activo para iniciar la preparación.', 'tavox-menu-api' ), [ 'status' => 404 ] );
	}

	$items            = is_array( $desk_data['items'] ?? null ) ? $desk_data['items'] : [];
	$updated          = 0;
	$request_ids      = [];
	$updated_line_ids = [];
	$now_ms           = (int) round( microtime( true ) * 1000 );
	$actor_name       = sanitize_text_field( (string) ( $actor['name'] ?? '' ) );
	$actor_id         = absint( $actor['id'] ?? 0 );
	$mode             = sanitize_key( (string) ( $options['mode'] ?? 'all_pending' ) );
	$mode             = in_array( $mode, [ 'selected', 'lot', 'all_pending' ], true ) ? $mode : 'all_pending';
	$selected_ids     = array_values(
		array_filter(
			array_map(
				static fn( $value ): string => trim( (string) $value ),
				(array) ( $options['line_ids'] ?? [] )
			)
		)
	);
	$selected_map = array_fill_keys( $selected_ids, true );
	$target_lots  = [];

	if ( in_array( $mode, [ 'selected', 'lot' ], true ) && empty( $selected_ids ) ) {
		return new WP_Error( 'line_required', __( 'Selecciona al menos un producto para iniciar la preparación.', 'tavox-menu-api' ), [ 'status' => 400 ] );
	}

	if ( 'lot' === $mode ) {
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$item_id = tavox_menu_api_get_openpos_item_line_id( $item );
			if ( '' === $item_id || ! isset( $selected_map[ $item_id ] ) ) {
				continue;
			}

			$target_lots[ tavox_menu_api_get_openpos_item_lot_key( $item ) ] = true;
		}

		if ( empty( $target_lots ) ) {
			return new WP_Error( 'lot_required', __( 'No encontramos un lote válido para esta selección.', 'tavox-menu-api' ), [ 'status' => 400 ] );
		}
	}

	foreach ( $items as &$item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}

		if ( $station !== tavox_menu_api_infer_item_station( $item ) ) {
			continue;
		}

		if ( 'pending' !== tavox_menu_api_get_openpos_item_service_state( $item ) ) {
			continue;
		}

		$item_id = tavox_menu_api_get_openpos_item_line_id( $item );
		$lot_key = tavox_menu_api_get_openpos_item_lot_key( $item );

		if ( 'selected' === $mode && ! isset( $selected_map[ $item_id ] ) ) {
			continue;
		}

		if ( 'lot' === $mode && ! isset( $target_lots[ $lot_key ] ) ) {
			continue;
		}

		$request_meta = tavox_menu_api_get_openpos_item_request_meta( $item );
		if ( absint( $request_meta['request_id'] ?? 0 ) > 0 ) {
			$request_ids[] = absint( $request_meta['request_id'] );
		}

		$item['state']       = 'cooking';
		$item['done']        = '';
		$item['update_time'] = $now_ms;

		if ( $actor_id > 0 ) {
			$item['seller_id'] = $actor_id;
		}

		if ( '' !== $actor_name ) {
			$item['seller_name'] = $actor_name;
		}

		tavox_menu_api_set_openpos_item_preparing_started_at( $item, $now_ms );

		$updated_line_ids[] = $item_id;
		$updated++;
	}
	unset( $item );

	if ( $updated < 1 ) {
		return new WP_Error( 'nothing_to_prepare', __( 'No encontramos productos pendientes en esta área para iniciar preparación.', 'tavox-menu-api' ), [ 'status' => 409 ] );
	}

	$desk_data['items']    = $items;
	$desk_data['source']   = 'tavox_menu_api';
	$desk_data['messages'] = sanitize_text_field( (string) ( $desk_data['messages'] ?? '' ) );
	$desk_data             = tavox_menu_api_prepare_openpos_write_versions( $desk_data );

	try {
		$result = $op_table->update_bill_screen(
			[
				$desk_ref => $desk_data,
			],
			false,
			'tavox'
		);
	} catch ( Throwable $error ) {
		$retry_attempted = ! empty( $options['__retried'] );
		if ( ! $retry_attempted && tavox_menu_api_is_openpos_concurrent_update_error( $error ) ) {
			$fresh_context = tavox_menu_api_refresh_openpos_table_context_snapshot( $table_context );
			if ( ! is_wp_error( $fresh_context ) ) {
				$options['__retried'] = 1;
				return tavox_menu_api_mark_station_items_preparing( $fresh_context, $station, $actor, $options );
			}
		}

		return new WP_Error(
			'desk_write_conflict',
			tavox_menu_api_is_openpos_concurrent_update_error( $error )
				? __( 'La mesa cambió mientras intentábamos iniciar la preparación. Reintenta una vez más.', 'tavox-menu-api' )
				: __( 'No pudimos actualizar este pedido en este momento.', 'tavox-menu-api' ),
			[
				'status' => tavox_menu_api_is_openpos_concurrent_update_error( $error ) ? 409 : 500,
			]
		);
	}

	$confirmation = [];
	$desk_after   = [];

	for ( $attempt = 0; $attempt < 3; $attempt++ ) {
		$desk_after = tavox_menu_api_get_openpos_live_table_data(
			$table_id,
			$type,
			$warehouse_id
		);
		$confirmation = tavox_menu_api_assess_openpos_preparing_write_confirmation(
			$desk_before,
			$desk_after,
			$updated_line_ids
		);

		if ( ! empty( $confirmation['confirmed'] ) ) {
			break;
		}

		if ( $attempt < 2 ) {
			usleep( 250000 );
		}
	}

	if ( empty( $confirmation['confirmed'] ) ) {
		$forced_write = false;

		try {
			$forced_write = tavox_menu_api_force_persist_openpos_desk( $op_table, $table_id, $type, $desk_data );
		} catch ( Throwable $error ) {
			tavox_menu_api_log_operational_event(
				'production_preparing_force_persist_failed',
				[
					'table_id'   => $table_id,
					'table_name' => sanitize_text_field( (string) ( $table_context['table_name'] ?? '' ) ),
					'desk_ref'   => $desk_ref,
					'station'    => $station,
					'line_ids'   => array_values( array_unique( array_filter( $updated_line_ids ) ) ),
					'error'      => $error->getMessage(),
				]
			);
		}

		if ( $forced_write ) {
			for ( $attempt = 0; $attempt < 3; $attempt++ ) {
				$desk_after = tavox_menu_api_get_openpos_live_table_data(
					$table_id,
					$type,
					$warehouse_id
				);
				$confirmation = tavox_menu_api_assess_openpos_preparing_write_confirmation(
					$desk_before,
					$desk_after,
					$updated_line_ids
				);

				if ( ! empty( $confirmation['confirmed'] ) ) {
					break;
				}

				if ( $attempt < 2 ) {
					usleep( 250000 );
				}
			}
		}
	}

	if ( empty( $confirmation['confirmed'] ) ) {
		tavox_menu_api_log_operational_event(
			'production_preparing_write_unconfirmed',
			[
				'table_id'     => $table_id,
				'table_name'   => sanitize_text_field( (string) ( $table_context['table_name'] ?? '' ) ),
				'desk_ref'     => $desk_ref,
				'station'      => $station,
				'line_ids'     => array_values( array_unique( array_filter( $updated_line_ids ) ) ),
				'confirmation' => $confirmation,
			]
		);

		return new WP_Error(
			'desk_write_unconfirmed',
			__( 'No pudimos confirmar que la preparación quedó guardada. Intenta una vez más.', 'tavox-menu-api' ),
			[ 'status' => 409 ]
		);
	}

	return [
		'desk_ref'         => $desk_ref,
		'updated_lines'    => $updated,
		'updated_line_ids' => array_values( array_unique( array_filter( $updated_line_ids ) ) ),
		'request_ids'      => array_values( array_unique( array_filter( $request_ids ) ) ),
		'mode'             => $mode,
		'result'           => $result,
		'desk'             => ! empty( $desk_after ) && is_array( $desk_after ) ? $desk_after : $desk_data,
		'confirmation'     => $confirmation,
	];
}

/**
 * Actualiza el modo mesa/para llevar de líneas activas en una cuenta.
 *
 * @param array<string, mixed> $table_context Contexto OpenPOS ya validado.
 * @param string               $fulfillment_mode dine_in|takeaway.
 * @param array<string, mixed> $options       Selección global o por línea.
 * @return array<string, mixed>|WP_Error
 */
function tavox_menu_api_update_table_items_fulfillment( array $table_context, string $fulfillment_mode, array $options = [] ) {
	global $op_table;

	tavox_menu_api_boot_openpos_services();

	if ( ! isset( $op_table ) || ! is_object( $op_table ) || ! method_exists( $op_table, 'update_bill_screen' ) ) {
		return new WP_Error( 'service_unavailable', __( 'No pudimos actualizar esta cuenta en este momento.', 'tavox-menu-api' ), [ 'status' => 503 ] );
	}

	$fulfillment_mode = tavox_menu_api_sanitize_fulfillment_mode( $fulfillment_mode, 'dine_in' );
	$type             = 'takeaway' === ( $table_context['table_type'] ?? '' ) ? 'takeaway' : 'dine_in';
	$table_id         = absint( $table_context['table_id'] ?? 0 );
	$desk_ref         = ( 'takeaway' === $type ? 'takeaway-' : 'desk-' ) . $table_id;
	$desk_data        = tavox_menu_api_get_openpos_live_table_data(
		$table_id,
		$type,
		absint( $table_context['warehouse_id'] ?? 0 )
	);

	if ( empty( $desk_data ) || ! is_array( $desk_data ) ) {
		return new WP_Error( 'desk_not_found', __( 'No encontramos la cuenta activa para cambiar este modo.', 'tavox-menu-api' ), [ 'status' => 404 ] );
	}

	$items            = is_array( $desk_data['items'] ?? null ) ? $desk_data['items'] : [];
	$updated          = 0;
	$request_ids      = [];
	$updated_line_ids = [];
	$affected_stations = [];
	$mode             = sanitize_key( (string) ( $options['mode'] ?? 'all' ) );
	$mode             = in_array( $mode, [ 'all', 'selected' ], true ) ? $mode : 'all';
	$selected_ids     = array_values(
		array_filter(
			array_map(
				static fn( $value ): string => trim( (string) $value ),
				(array) ( $options['line_ids'] ?? [] )
			)
		)
	);
	$selected_map = array_fill_keys( $selected_ids, true );

	if ( 'selected' === $mode && empty( $selected_ids ) ) {
		return new WP_Error( 'line_required', __( 'Selecciona al menos un producto para cambiar este modo.', 'tavox-menu-api' ), [ 'status' => 400 ] );
	}

	foreach ( $items as &$item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}

		$item_id = tavox_menu_api_get_openpos_item_line_id( $item );
		if ( 'selected' === $mode && ! isset( $selected_map[ $item_id ] ) ) {
			continue;
		}

		if ( 'delivered' === tavox_menu_api_get_openpos_item_service_state( $item ) ) {
			continue;
		}

		if ( $fulfillment_mode === tavox_menu_api_get_openpos_item_fulfillment_mode( $item, 'takeaway' === $type ? 'takeaway' : 'dine_in' ) ) {
			continue;
		}

		tavox_menu_api_set_openpos_item_fulfillment_mode( $item, $fulfillment_mode );
		$item['update_time'] = (int) round( microtime( true ) * 1000 );

		$request_meta = tavox_menu_api_get_openpos_item_request_meta( $item );
		if ( absint( $request_meta['request_id'] ?? 0 ) > 0 ) {
			$request_ids[] = absint( $request_meta['request_id'] );
		}

		$affected_stations[] = tavox_menu_api_infer_item_station( $item );
		$updated_line_ids[]  = $item_id;
		$updated++;
	}
	unset( $item );

	if ( $updated < 1 ) {
		return new WP_Error( 'nothing_to_update', __( 'No encontramos productos activos para cambiar este modo.', 'tavox-menu-api' ), [ 'status' => 409 ] );
	}

	$desk_data['items']    = $items;
	$desk_data['source']   = 'tavox_menu_api';
	$desk_data['messages'] = sanitize_text_field( (string) ( $desk_data['messages'] ?? '' ) );
	$desk_data             = tavox_menu_api_prepare_openpos_write_versions( $desk_data );

	try {
		$result = $op_table->update_bill_screen(
			[
				$desk_ref => $desk_data,
			],
			false,
			'tavox'
		);
	} catch ( Throwable $error ) {
		$retry_attempted = ! empty( $options['__retried'] );
		if ( ! $retry_attempted && tavox_menu_api_is_openpos_concurrent_update_error( $error ) ) {
			$fresh_context = tavox_menu_api_refresh_openpos_table_context_snapshot( $table_context );
			if ( ! is_wp_error( $fresh_context ) ) {
				$options['__retried'] = 1;
				return tavox_menu_api_update_table_items_fulfillment( $fresh_context, $fulfillment_mode, $options );
			}
		}

		return new WP_Error(
			'desk_write_conflict',
			tavox_menu_api_is_openpos_concurrent_update_error( $error )
				? __( 'La cuenta cambió mientras intentábamos ajustar este modo. Reintenta una vez más.', 'tavox-menu-api' )
				: __( 'No pudimos actualizar el modo de esta cuenta en este momento.', 'tavox-menu-api' ),
			[
				'status' => tavox_menu_api_is_openpos_concurrent_update_error( $error ) ? 409 : 500,
			]
		);
	}

	return [
		'desk_ref'          => $desk_ref,
		'updated_lines'     => $updated,
		'updated_line_ids'  => array_values( array_unique( array_filter( $updated_line_ids ) ) ),
		'request_ids'       => array_values( array_unique( array_filter( $request_ids ) ) ),
		'affected_stations' => array_values( array_unique( array_filter( array_map( 'sanitize_key', $affected_stations ) ) ) ),
		'fulfillment_mode'  => $fulfillment_mode,
		'mode'              => $mode,
		'result'            => $result,
		'desk'              => $desk_data,
	];
}

/**
 * Marca como listos productos de una estación concreta.
 *
 * @param array<string, mixed> $table_context Contexto OpenPOS ya validado.
 * @param string               $station       kitchen|bar|horno.
 * @param array<string, mixed> $actor         Usuario que confirma el listo.
 * @param array<string, mixed> $options       Selección por línea o por lote.
 * @return array<string, mixed>|WP_Error
 */
function tavox_menu_api_mark_station_items_ready( array $table_context, string $station, array $actor = [], array $options = [] ) {
	global $op_table;

	tavox_menu_api_boot_openpos_services();

	if ( ! isset( $op_table ) || ! is_object( $op_table ) || ! method_exists( $op_table, 'update_bill_screen' ) ) {
		return new WP_Error( 'service_unavailable', __( 'No pudimos actualizar este pedido en este momento.', 'tavox-menu-api' ), [ 'status' => 503 ] );
	}

	$station      = tavox_menu_api_sanitize_production_station( $station, 'kitchen' );
	$type         = 'takeaway' === ( $table_context['table_type'] ?? '' ) ? 'takeaway' : 'dine_in';
	$table_id     = absint( $table_context['table_id'] ?? 0 );
	$desk_ref     = ( 'takeaway' === $type ? 'takeaway-' : 'desk-' ) . $table_id;
	$warehouse_id = absint( $table_context['warehouse_id'] ?? 0 );
	$desk_data    = tavox_menu_api_get_openpos_live_table_data(
		$table_id,
		$type,
		$warehouse_id
	);
	$desk_before = $desk_data;

	if ( empty( $desk_data ) || ! is_array( $desk_data ) ) {
		return new WP_Error( 'desk_not_found', __( 'No encontramos el pedido activo para marcarlo como listo.', 'tavox-menu-api' ), [ 'status' => 404 ] );
	}

	$items        = is_array( $desk_data['items'] ?? null ) ? $desk_data['items'] : [];
	$updated          = 0;
	$request_ids      = [];
	$updated_line_ids = [];
	$now_ms           = (int) round( microtime( true ) * 1000 );
	$actor_name       = sanitize_text_field( (string) ( $actor['name'] ?? '' ) );
	$actor_id         = absint( $actor['id'] ?? 0 );
	$mode             = sanitize_key( (string) ( $options['mode'] ?? 'all_preparing' ) );
	$mode             = in_array( $mode, [ 'selected', 'lot', 'all_pending', 'all_preparing' ], true ) ? $mode : 'all_preparing';
	$selected_ids     = array_values(
		array_filter(
			array_map(
				static fn( $value ): string => trim( (string) $value ),
				(array) ( $options['line_ids'] ?? [] )
			)
		)
	);
	$selected_map = array_fill_keys( $selected_ids, true );
	$target_lots  = [];

	if ( in_array( $mode, [ 'selected', 'lot' ], true ) && empty( $selected_ids ) ) {
		return new WP_Error( 'line_required', __( 'Selecciona al menos un producto para marcarlo como listo.', 'tavox-menu-api' ), [ 'status' => 400 ] );
	}

	if ( 'lot' === $mode ) {
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$item_id = tavox_menu_api_get_openpos_item_line_id( $item );
			if ( '' === $item_id || ! isset( $selected_map[ $item_id ] ) ) {
				continue;
			}

			$target_lots[ tavox_menu_api_get_openpos_item_lot_key( $item ) ] = true;
		}

		if ( empty( $target_lots ) ) {
			return new WP_Error( 'lot_required', __( 'No encontramos un lote válido para esta selección.', 'tavox-menu-api' ), [ 'status' => 400 ] );
		}
	}

	foreach ( $items as &$item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}

		if ( $station !== tavox_menu_api_infer_item_station( $item ) ) {
			continue;
		}

		if ( 'preparing' !== tavox_menu_api_get_openpos_item_service_state( $item ) ) {
			continue;
		}

		$item_id = tavox_menu_api_get_openpos_item_line_id( $item );
		$lot_key = tavox_menu_api_get_openpos_item_lot_key( $item );

		if ( 'selected' === $mode && ! isset( $selected_map[ $item_id ] ) ) {
			continue;
		}

		if ( 'lot' === $mode && ! isset( $target_lots[ $lot_key ] ) ) {
			continue;
		}

		$request_meta = tavox_menu_api_get_openpos_item_request_meta( $item );
		if ( absint( $request_meta['request_id'] ?? 0 ) > 0 ) {
			$request_ids[] = absint( $request_meta['request_id'] );
		}

		$item['done']        = 'done';
		$item['update_time'] = $now_ms;

		if ( $actor_id > 0 ) {
			$item['seller_id'] = $actor_id;
		}

		if ( '' !== $actor_name ) {
			$item['seller_name'] = $actor_name;
		}

		$updated_line_ids[] = $item_id;
		$updated++;
	}
	unset( $item );

	if ( $updated < 1 ) {
		return new WP_Error( 'nothing_to_mark', __( 'No encontramos productos en preparación en esta área.', 'tavox-menu-api' ), [ 'status' => 409 ] );
	}

	$desk_data['items']      = $items;
	$desk_data['source']     = 'tavox_menu_api';
	$desk_data['messages']   = sanitize_text_field( (string) ( $desk_data['messages'] ?? '' ) );
	$desk_data               = tavox_menu_api_prepare_openpos_write_versions( $desk_data );

	try {
		$result = $op_table->update_bill_screen(
			[
				$desk_ref => $desk_data,
			],
			false,
			'tavox'
		);
	} catch ( Throwable $error ) {
		$retry_attempted = ! empty( $options['__retried'] );
		if ( ! $retry_attempted && tavox_menu_api_is_openpos_concurrent_update_error( $error ) ) {
			$fresh_context = tavox_menu_api_refresh_openpos_table_context_snapshot( $table_context );
			if ( ! is_wp_error( $fresh_context ) ) {
				$options['__retried'] = 1;
				return tavox_menu_api_mark_station_items_ready( $fresh_context, $station, $actor, $options );
			}
		}

		return new WP_Error(
			'desk_write_conflict',
			tavox_menu_api_is_openpos_concurrent_update_error( $error )
				? __( 'La mesa cambió mientras intentábamos marcar este producto como listo. Reintenta una vez más.', 'tavox-menu-api' )
				: __( 'No pudimos actualizar este pedido en este momento.', 'tavox-menu-api' ),
			[
				'status' => tavox_menu_api_is_openpos_concurrent_update_error( $error ) ? 409 : 500,
			]
		);
	}

	$confirmation = [];
	$desk_after   = [];

	for ( $attempt = 0; $attempt < 3; $attempt++ ) {
		$desk_after = tavox_menu_api_get_openpos_live_table_data(
			$table_id,
			$type,
			$warehouse_id
		);
		$confirmation = tavox_menu_api_assess_openpos_ready_write_confirmation(
			$desk_before,
			$desk_after,
			$updated_line_ids
		);

		if ( ! empty( $confirmation['confirmed'] ) ) {
			break;
		}

		if ( $attempt < 2 ) {
			usleep( 250000 );
		}
	}

	if ( empty( $confirmation['confirmed'] ) ) {
		$forced_write = false;

		try {
			$forced_write = tavox_menu_api_force_persist_openpos_desk( $op_table, $table_id, $type, $desk_data );
		} catch ( Throwable $error ) {
			tavox_menu_api_log_operational_event(
				'production_ready_force_persist_failed',
				[
					'table_id'   => $table_id,
					'table_name' => sanitize_text_field( (string) ( $table_context['table_name'] ?? '' ) ),
					'desk_ref'   => $desk_ref,
					'station'    => $station,
					'line_ids'   => array_values( array_unique( array_filter( $updated_line_ids ) ) ),
					'error'      => $error->getMessage(),
				]
			);
		}

		if ( $forced_write ) {
			for ( $attempt = 0; $attempt < 3; $attempt++ ) {
				$desk_after = tavox_menu_api_get_openpos_live_table_data(
					$table_id,
					$type,
					$warehouse_id
				);
				$confirmation = tavox_menu_api_assess_openpos_ready_write_confirmation(
					$desk_before,
					$desk_after,
					$updated_line_ids
				);

				if ( ! empty( $confirmation['confirmed'] ) ) {
					break;
				}

				if ( $attempt < 2 ) {
					usleep( 250000 );
				}
			}
		}
	}

	if ( empty( $confirmation['confirmed'] ) ) {
		tavox_menu_api_log_operational_event(
			'production_ready_write_unconfirmed',
			[
				'table_id'     => $table_id,
				'table_name'   => sanitize_text_field( (string) ( $table_context['table_name'] ?? '' ) ),
				'desk_ref'     => $desk_ref,
				'station'      => $station,
				'line_ids'     => array_values( array_unique( array_filter( $updated_line_ids ) ) ),
				'confirmation' => $confirmation,
			]
		);

		return new WP_Error(
			'desk_write_unconfirmed',
			__( 'No pudimos confirmar que estos productos quedaron listos. Intenta una vez más.', 'tavox-menu-api' ),
			[ 'status' => 409 ]
		);
	}

	return [
		'desk_ref'         => $desk_ref,
		'updated_lines'    => $updated,
		'updated_line_ids' => array_values( array_unique( array_filter( $updated_line_ids ) ) ),
		'request_ids'      => array_values( array_unique( array_filter( $request_ids ) ) ),
		'mode'             => $mode,
		'result'           => $result,
		'desk'             => ! empty( $desk_after ) && is_array( $desk_after ) ? $desk_after : $desk_data,
		'confirmation'     => $confirmation,
	];
}

/**
 * Detecta conflictos de concurrencia del bill screen de OpenPOS.
 */
function tavox_menu_api_is_openpos_concurrent_update_error( Throwable $error ): bool {
	$message = strtolower( trim( $error->getMessage() ) );

	return false !== strpos( $message, 'other update of this table' )
		|| false !== strpos( $message, 'please refresh this table and try again' );
}

/**
 * Relee el contexto vivo de una mesa/cuenta usando su identidad estable.
 *
 * @return array<string, mixed>|WP_Error
 */
function tavox_menu_api_refresh_openpos_table_context_snapshot( array $table_context ) {
	return tavox_menu_api_get_openpos_table_context_by_identity(
		absint( $table_context['table_id'] ?? 0 ),
		(string) ( $table_context['table_type'] ?? 'dine_in' ),
		absint( $table_context['register_id'] ?? 0 ),
		absint( $table_context['warehouse_id'] ?? 0 ),
		(string) ( $table_context['table_name'] ?? '' ),
		is_array( $table_context['raw_table'] ?? null ) ? $table_context['raw_table'] : []
	);
}

/**
 * Redirige el shell de OpenPOS al frontend moderno de mesa cuando aplica.
 */
function tavox_menu_api_maybe_redirect_customer_table_shell(): void {
	if ( is_admin() || ! tavox_menu_api_get_settings()['table_order_enabled'] ) {
		return;
	}

	if ( ! isset( $_SERVER['SCRIPT_NAME'] ) ) {
		return;
	}

	$script_name = (string) $_SERVER['SCRIPT_NAME'];
	if ( false === strpos( $script_name, '/woocommerce-openpos/customer/index.php' ) ) {
		return;
	}

	$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
	if ( '' === $key ) {
		return;
	}

	$table_context = tavox_menu_api_get_openpos_table_context_by_key( $key );
	if ( is_wp_error( $table_context ) ) {
		return;
	}

	wp_safe_redirect( tavox_menu_api_get_table_entry_redirect_url( $table_context ) );
	exit;
}
add_action( 'wp_loaded', 'tavox_menu_api_maybe_redirect_customer_table_shell', 1 );
