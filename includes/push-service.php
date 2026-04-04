<?php

defined( 'ABSPATH' ) || exit;

/**
 * Nombre completo de la tabla de suscripciones push del equipo.
 */
function tavox_menu_api_get_waiter_push_subscriptions_table_name(): string {
	global $wpdb;

	return $wpdb->prefix . 'tavox_waiter_push_subscriptions';
}

/**
 * Nombre completo de la tabla de bandeja push del equipo.
 */
function tavox_menu_api_get_waiter_push_messages_table_name(): string {
	global $wpdb;

	return $wpdb->prefix . 'tavox_waiter_push_messages';
}

/**
 * Codifica en base64url.
 */
function tavox_menu_api_base64url_encode( string $value ): string {
	return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
}

/**
 * Decodifica desde base64url.
 */
function tavox_menu_api_base64url_decode( string $value ): string {
	$padding = 4 - ( strlen( $value ) % 4 );
	if ( $padding < 4 ) {
		$value .= str_repeat( '=', $padding );
	}

	$decoded = base64_decode( strtr( $value, '-_', '+/' ), true );

	return is_string( $decoded ) ? $decoded : '';
}

/**
 * Sanitiza el contacto VAPID.
 */
function tavox_menu_api_sanitize_push_subject( string $value ): string {
	$value = trim( $value );

	if ( '' === $value ) {
		$admin_email = sanitize_email( (string) get_option( 'admin_email', '' ) );
		if ( '' !== $admin_email ) {
			return 'mailto:' . $admin_email;
		}

		return '';
	}

	if ( is_email( $value ) ) {
		return 'mailto:' . sanitize_email( $value );
	}

	$url = esc_url_raw( $value, [ 'https', 'mailto' ] );

	return is_string( $url ) ? $url : '';
}

/**
 * Normaliza el alcance operativo de una tablet del equipo.
 */
function tavox_menu_api_sanitize_push_scope( string $value ): string {
	$scope = sanitize_key( $value );

	if ( in_array( $scope, array_merge( [ 'service', 'all' ], tavox_menu_api_get_production_station_values() ), true ) ) {
		return $scope;
	}

	return 'service';
}

/**
 * Genera un par de claves VAPID.
 *
 * @return array<string, string>|WP_Error
 */
function tavox_menu_api_generate_push_keypair() {
	if ( ! function_exists( 'openssl_pkey_new' ) ) {
		return new WP_Error( 'openssl_missing', __( 'No pudimos preparar los avisos de la tablet en este servidor.', 'tavox-menu-api' ), [ 'status' => 500 ] );
	}

	$key = openssl_pkey_new(
		[
			'private_key_type' => OPENSSL_KEYTYPE_EC,
			'curve_name'       => 'prime256v1',
		]
	);

	if ( ! $key ) {
		return new WP_Error( 'vapid_generation_failed', __( 'No pudimos generar las claves para los avisos de la tablet.', 'tavox-menu-api' ), [ 'status' => 500 ] );
	}

	$private_pem = '';
	$exported    = openssl_pkey_export( $key, $private_pem );
	$details     = openssl_pkey_get_details( $key );

	if ( ! $exported || ! is_array( $details ) || empty( $details['ec']['x'] ) || empty( $details['ec']['y'] ) ) {
		return new WP_Error( 'vapid_details_failed', __( 'No pudimos preparar las claves para los avisos de la tablet.', 'tavox-menu-api' ), [ 'status' => 500 ] );
	}

	$public_raw = "\x04" . $details['ec']['x'] . $details['ec']['y'];

	return [
		'public_key'  => tavox_menu_api_base64url_encode( $public_raw ),
		'private_key' => base64_encode( $private_pem ),
	];
}

/**
 * Devuelve la configuración push lista para usar.
 *
 * @param array<string, mixed> $settings Ajustes existentes.
 * @return array<string, mixed>
 */
function tavox_menu_api_prepare_push_settings( array $settings, bool $generate_missing = false ): array {
	$enabled = array_key_exists( 'push_notifications_enabled', $settings ) ? ! empty( $settings['push_notifications_enabled'] ) : true;
	$subject = tavox_menu_api_sanitize_push_subject( (string) ( $settings['push_vapid_subject'] ?? '' ) );
	$public  = preg_replace( '/[^A-Za-z0-9\-_]/', '', (string) ( $settings['push_vapid_public_key'] ?? '' ) );
	$private = trim( (string) ( $settings['push_vapid_private_key'] ?? '' ) );

	if ( '' === $subject ) {
		$subject = tavox_menu_api_sanitize_push_subject( '' );
	}

	if ( $generate_missing && ( '' === $public || '' === $private ) && $enabled ) {
		$generated = tavox_menu_api_generate_push_keypair();
		if ( ! is_wp_error( $generated ) ) {
			$public  = (string) $generated['public_key'];
			$private = (string) $generated['private_key'];
		}
	}

	$settings['push_notifications_enabled'] = $enabled;
	$settings['push_vapid_subject']         = $subject;
	$settings['push_vapid_public_key']      = sanitize_text_field( $public );
	$settings['push_vapid_private_key']     = preg_replace( '/[^A-Za-z0-9+\/=\r\n]/', '', $private );

	return $settings;
}

/**
 * Indica si los avisos push están listos para usarse.
 */
function tavox_menu_api_is_push_ready(): bool {
	$settings = tavox_menu_api_get_settings();

	return ! empty( $settings['push_notifications_enabled'] )
		&& '' !== (string) ( $settings['push_vapid_public_key'] ?? '' )
		&& '' !== (string) ( $settings['push_vapid_private_key'] ?? '' )
		&& '' !== (string) ( $settings['push_vapid_subject'] ?? '' );
}

/**
 * Convierte firma DER a JOSE raw.
 */
function tavox_menu_api_convert_der_signature_to_jose( string $der, int $part_length = 32 ): string {
	$offset = 3;
	$r_len  = ord( $der[3] );
	$r      = substr( $der, 4, $r_len );
	$offset = 4 + $r_len + 1;
	$s_len  = ord( $der[ $offset ] );
	$s      = substr( $der, $offset + 1, $s_len );

	$r = ltrim( $r, "\x00" );
	$s = ltrim( $s, "\x00" );

	return str_pad( $r, $part_length, "\x00", STR_PAD_LEFT ) . str_pad( $s, $part_length, "\x00", STR_PAD_LEFT );
}

/**
 * Devuelve el JWT VAPID para un endpoint.
 *
 * @return array<string, string>|WP_Error
 */
function tavox_menu_api_build_vapid_headers( string $endpoint ) {
	$settings     = tavox_menu_api_get_settings();
	$public_key   = (string) ( $settings['push_vapid_public_key'] ?? '' );
	$private_base = (string) ( $settings['push_vapid_private_key'] ?? '' );
	$subject      = (string) ( $settings['push_vapid_subject'] ?? '' );

	if ( '' === $public_key || '' === $private_base || '' === $subject ) {
		return new WP_Error( 'push_not_ready', __( 'Los avisos de la tablet todavía no están listos.', 'tavox-menu-api' ), [ 'status' => 503 ] );
	}

	$parts = wp_parse_url( $endpoint );
	$host  = (string) ( $parts['host'] ?? '' );
	$scheme = (string) ( $parts['scheme'] ?? 'https' );
	$port  = ! empty( $parts['port'] ) ? ':' . absint( $parts['port'] ) : '';

	if ( '' === $host ) {
		return new WP_Error( 'push_endpoint_invalid', __( 'No pudimos identificar el destino del aviso.', 'tavox-menu-api' ), [ 'status' => 400 ] );
	}

	$aud = $scheme . '://' . $host . $port;

	$header  = tavox_menu_api_base64url_encode( wp_json_encode( [ 'alg' => 'ES256', 'typ' => 'JWT' ] ) );
	$payload = tavox_menu_api_base64url_encode(
		wp_json_encode(
			[
				'aud' => $aud,
				'exp' => time() + 12 * HOUR_IN_SECONDS,
				'sub' => $subject,
			]
		)
	);
	$signing_input = $header . '.' . $payload;
	$private_pem   = base64_decode( $private_base, true );

	if ( ! is_string( $private_pem ) || '' === $private_pem ) {
		return new WP_Error( 'push_private_invalid', __( 'Las claves de los avisos no están disponibles.', 'tavox-menu-api' ), [ 'status' => 500 ] );
	}

	$signature = '';
	$signed    = openssl_sign( $signing_input, $signature, $private_pem, OPENSSL_ALGO_SHA256 );

	if ( ! $signed ) {
		return new WP_Error( 'push_sign_failed', __( 'No pudimos firmar el aviso para la tablet.', 'tavox-menu-api' ), [ 'status' => 500 ] );
	}

	$jose_signature = tavox_menu_api_convert_der_signature_to_jose( $signature );
	$jwt            = $signing_input . '.' . tavox_menu_api_base64url_encode( $jose_signature );

	return [
		'authorization' => sprintf( 'vapid t=%1$s, k=%2$s', $jwt, $public_key ),
		'public_key'    => $public_key,
	];
}

/**
 * Devuelve la URL frontal del equipo.
 */
function tavox_menu_api_get_team_frontend_url( string $path = '/equipo/pedidos' ): string {
	$settings = tavox_menu_api_get_settings();
	$base     = trim( (string) ( $settings['menu_frontend_url'] ?? '' ) );

	if ( '' === $base ) {
		$base = trailingslashit( home_url( '/menu' ) );
	}

	return rtrim( $base, '/' ) . $path;
}

/**
 * Genera una referencia estable para una cuenta operativa.
 */
function tavox_menu_api_build_waiter_account_ref( string $table_type, int $table_id ): string {
	$table_type = 'takeaway' === sanitize_key( $table_type ) ? 'takeaway' : 'dine_in';
	$table_id   = absint( $table_id );

	return $table_type . ':' . $table_id;
}

/**
 * Determina si un aviso debe quedar visible en el centro del equipo.
 */
function tavox_menu_api_should_keep_waiter_message_in_center( string $event_type ): bool {
	return ! in_array( sanitize_key( $event_type ), [ 'push_test', 'push_enabled' ], true );
}

/**
 * Traduce un aviso operativo a su área principal.
 */
function tavox_menu_api_get_waiter_notification_area( array $row, array $meta = [] ): string {
	$station = sanitize_key( (string) ( $meta['station'] ?? '' ) );
	if ( in_array( $station, tavox_menu_api_get_production_station_values(), true ) ) {
		return $station;
	}

	$event_type = sanitize_key( (string) ( $row['event_type'] ?? '' ) );
	if ( str_starts_with( $event_type, 'request_' ) || 'new_request' === $event_type ) {
		return 'service';
	}

	if ( in_array( $event_type, [ 'service_partial_ready', 'service_ready', 'service_delivered' ], true ) ) {
		return 'service';
	}

	return 'service';
}

/**
 * Devuelve la etiqueta visible del estado operativo del aviso.
 */
function tavox_menu_api_get_waiter_notification_status_label( array $row, array $meta = [] ): string {
	$event_type = sanitize_key( (string) ( $row['event_type'] ?? '' ) );

	if ( ! empty( $row['resolved_at'] ) ) {
		return __( 'Resuelto', 'tavox-menu-api' );
	}

	if ( 'new_request' === $event_type ) {
		$request_status = sanitize_key( (string) ( $meta['request_status'] ?? '' ) );
		if ( '' !== $request_status && function_exists( 'tavox_menu_api_get_waiter_request_status_label' ) ) {
			return tavox_menu_api_get_waiter_request_status_label( $request_status );
		}

		return __( 'Pendiente', 'tavox-menu-api' );
	}

	if ( 'request_claimed' === $event_type ) {
		return __( 'Ya atendido', 'tavox-menu-api' );
	}

	if ( 'table_message_new' === $event_type ) {
		return __( 'Pendiente', 'tavox-menu-api' );
	}

	if ( 'table_message_reply' === $event_type ) {
		return __( 'Respondido', 'tavox-menu-api' );
	}

	if ( 'table_message_resolved' === $event_type ) {
		return __( 'Resuelto', 'tavox-menu-api' );
	}

	if ( in_array( $event_type, [ 'service_partial_ready', 'service_ready' ], true ) ) {
		return __( 'Listo', 'tavox-menu-api' );
	}

	if ( 'service_delivered' === $event_type ) {
		return __( 'Entregado', 'tavox-menu-api' );
	}

	return empty( $row['read_at'] ) ? __( 'Nuevo', 'tavox-menu-api' ) : __( 'Visto', 'tavox-menu-api' );
}

/**
 * Carga en bloque los requests referenciados por avisos del centro.
 *
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function tavox_menu_api_get_waiter_notification_request_map( array $rows ): array {
	global $wpdb;

	$request_ids = [];

	foreach ( $rows as $row ) {
		$event_type = sanitize_key( (string) ( $row['event_type'] ?? '' ) );
		if ( 'new_request' !== $event_type ) {
			continue;
		}

		$meta       = json_decode( (string) ( $row['meta_json'] ?? '{}' ), true );
		$meta       = is_array( $meta ) ? $meta : [];
		$request_id = absint( $meta['request_id'] ?? 0 );

		if ( $request_id > 0 ) {
			$request_ids[] = $request_id;
		}
	}

	$request_ids = array_values( array_unique( array_filter( $request_ids ) ) );
	if ( empty( $request_ids ) ) {
		return [];
	}

	$requests_table = tavox_menu_api_get_table_requests_table_name();
	$placeholders   = implode( ', ', array_fill( 0, count( $request_ids ), '%d' ) );
	$results        = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$requests_table} WHERE id IN ({$placeholders})",
			$request_ids
		),
		ARRAY_A
	);

	$map = [];
	foreach ( (array) $results as $result ) {
		if ( ! is_array( $result ) ) {
			continue;
		}

		$map[ absint( $result['id'] ?? 0 ) ] = $result;
	}

	return $map;
}

/**
 * Enriquece el meta del aviso con el estado vivo del pedido cuando aplica.
 *
 * @param array<string, mixed>                 $row
 * @param array<string, mixed>                 $meta
 * @param array<int, array<string, mixed>>     $request_map
 * @return array<string, mixed>
 */
function tavox_menu_api_enrich_waiter_notification_meta( array $row, array $meta, array $request_map = [], ?WP_User $current_user = null ): array {
	$event_type = sanitize_key( (string) ( $row['event_type'] ?? '' ) );
	if ( 'new_request' !== $event_type ) {
		return $meta;
	}

	$request_id = absint( $meta['request_id'] ?? 0 );
	if ( $request_id < 1 ) {
		return $meta;
	}

	$request_row = isset( $request_map[ $request_id ] ) && is_array( $request_map[ $request_id ] )
		? $request_map[ $request_id ]
		: ( function_exists( 'tavox_menu_api_get_request_row_by_id' ) ? tavox_menu_api_get_request_row_by_id( $request_id ) : null );

	if ( ! is_array( $request_row ) || ! function_exists( 'tavox_menu_api_build_waiter_request_detail_payload' ) ) {
		$meta['request_status'] = 'missing';
		$meta['can_claim']      = false;
		return $meta;
	}

	$request_detail = tavox_menu_api_build_waiter_request_detail_payload( $request_row, $current_user );
	$meta['request_id']      = absint( $request_detail['id'] ?? $request_id );
	$meta['table_name']      = sanitize_text_field( (string) ( $request_detail['table_name'] ?? $meta['table_name'] ?? '' ) );
	$meta['account_ref']     = sanitize_text_field( (string) ( $request_detail['account_ref'] ?? $meta['account_ref'] ?? '' ) );
	$meta['request_status']  = sanitize_key( (string) ( $request_detail['status'] ?? '' ) );
	$meta['can_claim']       = ! empty( $request_detail['can_claim'] );
	$meta['can_accept']      = ! empty( $request_detail['can_accept'] );
	$meta['primary_action']  = sanitize_key( (string) ( $request_detail['primary_action'] ?? 'none' ) );
	$meta['is_mine']         = ! empty( $request_detail['is_mine'] );
	$meta['table_type']      = sanitize_key( (string) ( $request_detail['table_type'] ?? '' ) );
	$meta['waiter_name']     = sanitize_text_field( (string) ( $request_detail['waiter_name'] ?? '' ) );
	$meta['client_label']    = sanitize_text_field( (string) ( $request_detail['client_label'] ?? '' ) );
	$meta['request_source']  = sanitize_key( (string) ( $request_detail['request_source'] ?? '' ) );
	$meta['table_availability'] = sanitize_key( (string) ( $request_detail['table_availability'] ?? '' ) );
	$meta['action_reason']   = sanitize_text_field( (string) ( $request_detail['action_reason'] ?? '' ) );
	$meta['managed_by']      = sanitize_text_field( (string) ( $request_detail['managed_by'] ?? '' ) );

	return $meta;
}

/**
 * Normaliza un aviso del equipo para REST.
 *
 * @return array<string, mixed>
 */
function tavox_menu_api_format_waiter_push_message_row( array $row, array $request_map = [], ?WP_User $current_user = null ): array {
	$meta = json_decode( (string) ( $row['meta_json'] ?? '{}' ), true );
	$meta = is_array( $meta ) ? $meta : [];
	$meta = tavox_menu_api_enrich_waiter_notification_meta( $row, $meta, $request_map, $current_user );

	return [
		'id'           => absint( $row['id'] ?? 0 ),
		'type'         => sanitize_key( (string) ( $row['event_type'] ?? 'team_update' ) ),
		'title'        => sanitize_text_field( (string) ( $row['title'] ?? '' ) ),
		'body'         => sanitize_textarea_field( (string) ( $row['body'] ?? '' ) ),
		'url'          => esc_url_raw( (string) ( $row['link_url'] ?? '' ), [ 'http', 'https' ] ),
		'tag'          => sanitize_text_field( (string) ( $row['tag'] ?? '' ) ),
		'meta'         => $meta,
		'area'         => tavox_menu_api_get_waiter_notification_area( $row, $meta ),
		'status_label' => tavox_menu_api_get_waiter_notification_status_label( $row, $meta ),
		'is_read'      => ! empty( $row['read_at'] ),
		'is_active'    => empty( $row['resolved_at'] ),
		'created_at'   => (string) ( $row['created_at'] ?? '' ),
		'delivered_at' => (string) ( $row['delivered_at'] ?? '' ),
		'read_at'      => (string) ( $row['read_at'] ?? '' ),
		'resolved_at'  => (string) ( $row['resolved_at'] ?? '' ),
	];
}

/**
 * Normaliza el payload visible de un aviso push.
 *
 * @param array<string, mixed> $message Aviso original.
 * @return array<string, mixed>
 */
function tavox_menu_api_prepare_web_push_payload( array $message ): array {
	return [
		'type'  => sanitize_key( (string) ( $message['type'] ?? 'team_update' ) ),
		'title' => sanitize_text_field( (string) ( $message['title'] ?? '' ) ),
		'body'  => sanitize_textarea_field( (string) ( $message['body'] ?? '' ) ),
		'url'   => esc_url_raw( (string) ( $message['url'] ?? '' ), [ 'http', 'https' ] ),
		'tag'   => sanitize_text_field( (string) ( $message['tag'] ?? '' ) ),
		'meta'  => is_array( $message['meta'] ?? null ) ? $message['meta'] : [],
	];
}

/**
 * Ejecuta HKDF-Extract con SHA-256.
 */
function tavox_menu_api_push_hkdf_extract( string $salt, string $ikm ): string {
	return hash_hmac( 'sha256', $ikm, $salt, true );
}

/**
 * Ejecuta HKDF-Expand con SHA-256.
 */
function tavox_menu_api_push_hkdf_expand( string $prk, string $info, int $length ): string {
	$output  = '';
	$block   = '';
	$counter = 1;

	while ( strlen( $output ) < $length ) {
		$block   = hash_hmac( 'sha256', $block . $info . chr( $counter ), $prk, true );
		$output .= $block;
		++$counter;
	}

	return substr( $output, 0, $length );
}

/**
 * Convierte una clave pública P-256 cruda a PEM.
 *
 * @return string|WP_Error
 */
function tavox_menu_api_build_push_public_key_pem( string $raw_public_key ) {
	if ( 65 !== strlen( $raw_public_key ) || "\x04" !== substr( $raw_public_key, 0, 1 ) ) {
		return new WP_Error( 'push_public_key_invalid', __( 'La tablet no entregó una clave pública válida.', 'tavox-menu-api' ), [ 'status' => 400 ] );
	}

	$prefix = hex2bin( '3059301306072A8648CE3D020106082A8648CE3D030107034200' );
	if ( ! is_string( $prefix ) || '' === $prefix ) {
		return new WP_Error( 'push_public_key_prefix_invalid', __( 'No pudimos preparar la clave del aviso push.', 'tavox-menu-api' ), [ 'status' => 500 ] );
	}

	$der = $prefix . $raw_public_key;
	$pem = "-----BEGIN PUBLIC KEY-----\n";
	$pem .= chunk_split( base64_encode( $der ), 64, "\n" );
	$pem .= "-----END PUBLIC KEY-----\n";

	return $pem;
}

/**
 * Genera un par de claves efímeras para cifrar el push.
 *
 * @return array<string, mixed>|WP_Error
 */
function tavox_menu_api_generate_push_ephemeral_keypair() {
	$key = openssl_pkey_new(
		[
			'private_key_type' => OPENSSL_KEYTYPE_EC,
			'curve_name'       => 'prime256v1',
		]
	);

	if ( false === $key ) {
		return new WP_Error( 'push_ephemeral_key_failed', __( 'No pudimos preparar una clave temporal para el aviso push.', 'tavox-menu-api' ), [ 'status' => 500 ] );
	}

	$details = openssl_pkey_get_details( $key );
	$ec      = is_array( $details ) && is_array( $details['ec'] ?? null ) ? $details['ec'] : null;
	$x       = is_string( $ec['x'] ?? null ) ? $ec['x'] : '';
	$y       = is_string( $ec['y'] ?? null ) ? $ec['y'] : '';

	if ( '' === $x || '' === $y ) {
		return new WP_Error( 'push_ephemeral_key_details_failed', __( 'No pudimos leer la clave temporal del aviso push.', 'tavox-menu-api' ), [ 'status' => 500 ] );
	}

	return [
		'key'        => $key,
		'public_raw' => "\x04" . $x . $y,
	];
}

/**
 * Cifra un payload Web Push usando aes128gcm.
 *
 * @param array<string, mixed> $subscription Suscripción activa.
 * @param array<string, mixed> $message      Aviso visible.
 * @return array<string, mixed>|WP_Error
 */
function tavox_menu_api_encrypt_web_push_payload( array $subscription, array $message ) {
	$client_public_key = tavox_menu_api_base64url_decode( (string) ( $subscription['client_public_key'] ?? '' ) );
	$auth_secret       = tavox_menu_api_base64url_decode( (string) ( $subscription['auth_secret'] ?? '' ) );

	if ( '' === $client_public_key || '' === $auth_secret ) {
		return new WP_Error( 'push_payload_keys_missing', __( 'La tablet todavía no registró sus claves de avisos.', 'tavox-menu-api' ), [ 'status' => 409 ] );
	}

	$client_public_pem = tavox_menu_api_build_push_public_key_pem( $client_public_key );
	if ( is_wp_error( $client_public_pem ) ) {
		return $client_public_pem;
	}

	$client_key = openssl_pkey_get_public( $client_public_pem );
	if ( false === $client_key ) {
		return new WP_Error( 'push_client_key_invalid', __( 'No pudimos abrir la clave pública de la tablet.', 'tavox-menu-api' ), [ 'status' => 500 ] );
	}

	$server_keys = tavox_menu_api_generate_push_ephemeral_keypair();
	if ( is_wp_error( $server_keys ) ) {
		return $server_keys;
	}

	$shared_secret = openssl_pkey_derive( $client_key, $server_keys['key'] );
	if ( ! is_string( $shared_secret ) || '' === $shared_secret ) {
		return new WP_Error( 'push_ecdh_failed', __( 'No pudimos acordar la clave del aviso con esta tablet.', 'tavox-menu-api' ), [ 'status' => 500 ] );
	}

	$auth_prk = tavox_menu_api_push_hkdf_extract( $auth_secret, $shared_secret );
	$ikm      = tavox_menu_api_push_hkdf_expand(
		$auth_prk,
		"WebPush: info\x00" . $client_public_key . $server_keys['public_raw'],
		32
	);
	try {
		$salt = random_bytes( 16 );
	} catch ( Throwable $error ) {
		return new WP_Error( 'push_random_bytes_failed', __( 'No pudimos preparar el aviso cifrado para la tablet.', 'tavox-menu-api' ), [ 'status' => 500 ] );
	}
	$prk      = tavox_menu_api_push_hkdf_extract( $salt, $ikm );
	$cek      = tavox_menu_api_push_hkdf_expand( $prk, "Content-Encoding: aes128gcm\x00", 16 );
	$nonce    = tavox_menu_api_push_hkdf_expand( $prk, "Content-Encoding: nonce\x00", 12 );
	$payload  = wp_json_encode(
		tavox_menu_api_prepare_web_push_payload( $message ),
		JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
	);

	if ( ! is_string( $payload ) || '' === $payload ) {
		return new WP_Error( 'push_payload_invalid', __( 'No pudimos preparar el contenido visible del aviso push.', 'tavox-menu-api' ), [ 'status' => 500 ] );
	}

	$plaintext = $payload . "\x02";
	$tag       = '';
	$ciphertext = openssl_encrypt(
		$plaintext,
		'aes-128-gcm',
		$cek,
		OPENSSL_RAW_DATA,
		$nonce,
		$tag
	);

	if ( ! is_string( $ciphertext ) || '' === $tag ) {
		return new WP_Error( 'push_encrypt_failed', __( 'No pudimos cifrar el aviso para la tablet.', 'tavox-menu-api' ), [ 'status' => 500 ] );
	}

	$header = $salt . pack( 'N', 4096 ) . chr( strlen( $server_keys['public_raw'] ) ) . $server_keys['public_raw'];

	return [
		'body'         => $header . $ciphertext . $tag,
		'content_type' => 'application/octet-stream',
		'encoding'     => 'aes128gcm',
	];
}

/**
 * Devuelve la suscripción push activa de una sesión.
 *
 * @return array<string, mixed>|null
 */
function tavox_menu_api_get_active_waiter_push_subscription( string $session_token ): ?array {
	global $wpdb;

	if ( '' === $session_token ) {
		return null;
	}

	$table = tavox_menu_api_get_waiter_push_subscriptions_table_name();
	$row   = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$table} WHERE session_token = %s AND status = 'active' LIMIT 1",
			$session_token
		),
		ARRAY_A
	);

	return is_array( $row ) ? $row : null;
}

/**
 * Guarda o actualiza la suscripción push ligada a la sesión del equipo.
 *
 * @param array<string, mixed> $session Sesión activa del equipo.
 * @param array<string, mixed> $subscription Suscripción Push del navegador.
 * @return array<string, mixed>|WP_Error
 */
function tavox_menu_api_upsert_waiter_push_subscription( array $session, array $subscription ) {
	global $wpdb;

	$endpoint = esc_url_raw( trim( (string) ( $subscription['endpoint'] ?? '' ) ), [ 'https' ] );
	if ( '' === $endpoint ) {
		return new WP_Error( 'push_subscription_invalid', __( 'No pudimos registrar esta tablet para recibir avisos.', 'tavox-menu-api' ), [ 'status' => 400 ] );
	}

	$keys                = is_array( $subscription['keys'] ?? null ) ? $subscription['keys'] : [];
	$client_public_key   = sanitize_text_field( (string) ( $keys['p256dh'] ?? $subscription['p256dh'] ?? $subscription['client_public_key'] ?? '' ) );
	$auth_secret         = sanitize_text_field( (string) ( $keys['auth'] ?? $subscription['auth'] ?? $subscription['auth_secret'] ?? '' ) );
	$table              = tavox_menu_api_get_waiter_push_subscriptions_table_name();
	$now_mysql          = tavox_menu_api_now_mysql();
	$session_row        = is_array( $session['session'] ?? null ) ? $session['session'] : [];
	$session_token      = (string) ( $session_row['session_token'] ?? '' );
	$user_id            = absint( $session['user']->ID ?? 0 );
	$device_label       = sanitize_text_field( (string) ( $subscription['device_label'] ?? $session_row['device_label'] ?? '' ) );
	$content_encoding   = sanitize_text_field( (string) ( $subscription['contentEncoding'] ?? $subscription['content_encoding'] ?? 'aes128gcm' ) );
	$endpoint_hash      = hash( 'sha256', $endpoint );
	$notification_scope = tavox_menu_api_sanitize_push_scope( (string) ( $subscription['notification_scope'] ?? 'service' ) );

	$existing_id = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$table} WHERE session_token = %s OR endpoint_hash = %s LIMIT 1",
			$session_token,
			$endpoint_hash
		)
	);

	$data = [
		'session_token'     => $session_token,
		'waiter_user_id'    => $user_id,
		'endpoint_hash'     => $endpoint_hash,
		'endpoint_url'      => $endpoint,
		'device_label'      => $device_label,
		'notification_scope'=> $notification_scope,
		'status'            => 'active',
		'updated_at'        => $now_mysql,
	];

	if ( '' !== $client_public_key ) {
		$data['client_public_key'] = $client_public_key;
	}

	if ( '' !== $auth_secret ) {
		$data['auth_secret'] = $auth_secret;
	}

	if ( '' !== $content_encoding ) {
		$data['content_encoding'] = $content_encoding;
	}

	if ( $existing_id > 0 ) {
		$updated = $wpdb->update(
			$table,
			$data,
			[ 'id' => $existing_id ],
			array_values(
				array_map(
					static fn( $value ): string => is_int( $value ) ? '%d' : '%s',
					$data
				)
			),
			[ '%d' ]
		);
		if ( false === $updated ) {
			return new WP_Error( 'push_subscription_update_failed', __( 'No pudimos actualizar los avisos de esta tablet.', 'tavox-menu-api' ), [ 'status' => 500 ] );
		}
	} else {
		$data['client_public_key'] = $data['client_public_key'] ?? '';
		$data['auth_secret']       = $data['auth_secret'] ?? '';
		$data['content_encoding']  = $data['content_encoding'] ?? $content_encoding;
		$data['created_at'] = $now_mysql;
		$inserted = $wpdb->insert(
			$table,
			$data,
			array_values(
				array_map(
					static fn( $value ): string => is_int( $value ) ? '%d' : '%s',
					$data
				)
			)
		);
		if ( false === $inserted ) {
			return new WP_Error( 'push_subscription_insert_failed', __( 'No pudimos activar los avisos para esta tablet.', 'tavox-menu-api' ), [ 'status' => 500 ] );
		}
	}

	return [
		'active'       => true,
		'device_label' => $device_label,
		'scope'        => $notification_scope,
	];
}

/**
 * Actualiza el alcance operativo de los avisos para una sesión.
 */
function tavox_menu_api_update_waiter_push_scope( string $session_token, string $scope ): bool {
	global $wpdb;

	if ( '' === $session_token ) {
		return false;
	}

	$table = tavox_menu_api_get_waiter_push_subscriptions_table_name();
	$updated = $wpdb->update(
		$table,
		[
			'notification_scope' => tavox_menu_api_sanitize_push_scope( $scope ),
			'updated_at'         => tavox_menu_api_now_mysql(),
		],
		[ 'session_token' => $session_token, 'status' => 'active' ],
		[ '%s', '%s' ],
		[ '%s', '%s' ]
	);

	return false !== $updated;
}

/**
 * Desactiva la suscripción push de una sesión.
 */
function tavox_menu_api_deactivate_waiter_push_subscription( string $session_token ): void {
	global $wpdb;

	if ( '' === $session_token ) {
		return;
	}

	$table = tavox_menu_api_get_waiter_push_subscriptions_table_name();
	$wpdb->update(
		$table,
		[
			'status'     => 'inactive',
			'updated_at' => tavox_menu_api_now_mysql(),
		],
		[ 'session_token' => $session_token ],
		[ '%s', '%s' ],
		[ '%s' ]
	);
}

/**
 * Sincroniza suscripciones con sesiones activas.
 */
function tavox_menu_api_cleanup_waiter_push_subscriptions(): void {
	global $wpdb;

	$subscriptions_table = tavox_menu_api_get_waiter_push_subscriptions_table_name();
	$sessions_table      = tavox_menu_api_get_waiter_sessions_table_name();

	$wpdb->query(
		"UPDATE {$subscriptions_table} s
		LEFT JOIN {$sessions_table} ws ON ws.session_token = s.session_token
		SET s.status = 'inactive', s.updated_at = UTC_TIMESTAMP()
		WHERE s.status = 'active' AND (ws.id IS NULL OR ws.status <> 'active')"
	);
}

/**
 * Guarda un aviso en la bandeja de una sesión.
 *
 * @param array<string, mixed> $message Datos visibles del aviso.
 */
function tavox_menu_api_queue_waiter_push_message( string $session_token, int $user_id, array $message ): bool {
	global $wpdb;

	if ( '' === $session_token ) {
		return false;
	}

	$table      = tavox_menu_api_get_waiter_push_messages_table_name();
	$now_mysql  = tavox_menu_api_now_mysql();
	$inserted   = $wpdb->insert(
		$table,
		[
			'session_token' => $session_token,
			'waiter_user_id'=> $user_id,
			'event_type'    => sanitize_key( (string) ( $message['type'] ?? 'team_update' ) ),
			'title'         => sanitize_text_field( (string) ( $message['title'] ?? '' ) ),
			'body'          => sanitize_textarea_field( (string) ( $message['body'] ?? '' ) ),
			'link_url'      => esc_url_raw( (string) ( $message['url'] ?? '' ), [ 'http', 'https' ] ),
			'tag'           => sanitize_text_field( (string) ( $message['tag'] ?? '' ) ),
			'meta_json'     => wp_json_encode( $message['meta'] ?? [] ),
			'created_at'    => $now_mysql,
		],
		[ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
	);

	if ( false === $inserted && function_exists( 'tavox_menu_api_log_operational_event' ) ) {
		tavox_menu_api_log_operational_event(
			'waiter_push_queue_failed',
			[
				'session_token_suffix' => substr( $session_token, -12 ),
				'user_id'              => $user_id,
				'event_type'           => sanitize_key( (string) ( $message['type'] ?? 'team_update' ) ),
				'url_length'           => strlen( (string) ( $message['url'] ?? '' ) ),
				'db_error'             => sanitize_text_field( (string) $wpdb->last_error ),
			]
		);
	}

	return false !== $inserted;
}

/**
 * Devuelve las sesiones activas que deben recibir un aviso en el centro del equipo.
 *
 * @param array<int, int> $target_waiter_user_ids
 * @return array<int, array<string, mixed>>
 */
function tavox_menu_api_get_active_waiter_notification_sessions( array $target_waiter_user_ids = [], string $exclude_session = '', int $exclude_user_id = 0 ): array {
	global $wpdb;

	$sessions_table = tavox_menu_api_get_waiter_sessions_table_name();
	$where          = [ "status = 'active'" ];
	$params         = [];

	if ( '' !== $exclude_session ) {
		$where[]  = 'session_token <> %s';
		$params[] = $exclude_session;
	}

	if ( $exclude_user_id > 0 ) {
		$where[]  = 'user_id <> %d';
		$params[] = $exclude_user_id;
	}

	$target_waiter_user_ids = array_values( array_filter( array_map( 'absint', $target_waiter_user_ids ) ) );
	if ( ! empty( $target_waiter_user_ids ) ) {
		$placeholders = implode( ', ', array_fill( 0, count( $target_waiter_user_ids ), '%d' ) );
		$where[]      = "user_id IN ({$placeholders})";
		$params       = array_merge( $params, $target_waiter_user_ids );
	}

	$sql = "SELECT id, user_id, session_token, device_label, last_seen
		FROM {$sessions_table}
		WHERE " . implode( ' AND ', $where ) . '
		ORDER BY last_seen DESC';

	if ( ! empty( $params ) ) {
		$sql = $wpdb->prepare( $sql, $params );
	}

	$rows = $wpdb->get_results( $sql, ARRAY_A );

	return is_array( $rows ) ? $rows : [];
}

/**
 * Marca avisos como vistos para una sesión.
 */
function tavox_menu_api_mark_waiter_notifications_read( string $session_token, array $ids = [] ): int {
	global $wpdb;

	if ( '' === $session_token ) {
		return 0;
	}

	$table  = tavox_menu_api_get_waiter_push_messages_table_name();
	$where  = [ 'session_token = %s', 'read_at IS NULL', 'resolved_at IS NULL' ];
	$params = [ $session_token ];

	$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
	if ( ! empty( $ids ) ) {
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$where[]      = "id IN ({$placeholders})";
		$params       = array_merge( $params, $ids );
	}

	array_unshift( $params, tavox_menu_api_now_mysql() );
	$sql = $wpdb->prepare(
		"UPDATE {$table} SET read_at = %s WHERE " . implode( ' AND ', $where ),
		$params
	);

	$updated = $wpdb->query( $sql );

	return false === $updated ? 0 : (int) $updated;
}

/**
 * Resuelve avisos operativos vinculados a una causa ya atendida.
 */
function tavox_menu_api_resolve_waiter_notifications( array $filters = [] ): int {
	global $wpdb;

	$table        = tavox_menu_api_get_waiter_push_messages_table_name();
	$ids          = array_values( array_filter( array_map( 'absint', (array) ( $filters['ids'] ?? [] ) ) ) );
	$event_types  = array_values( array_filter( array_map( 'sanitize_key', (array) ( $filters['event_types'] ?? [] ) ) ) );
	$request_ids  = array_values( array_filter( array_map( 'absint', (array) ( $filters['request_ids'] ?? [] ) ) ) );
	$account_refs = array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $filters['account_refs'] ?? [] ) ) ) );
	$stations     = array_values( array_filter( array_map( 'sanitize_key', (array) ( $filters['stations'] ?? [] ) ) ) );

	if ( empty( $ids ) && empty( $event_types ) && empty( $request_ids ) && empty( $account_refs ) && empty( $stations ) ) {
		return 0;
	}

	$where  = [ 'resolved_at IS NULL' ];
	$params = [ tavox_menu_api_now_mysql() ];

	if ( ! empty( $ids ) ) {
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$where[]      = "id IN ({$placeholders})";
		$params       = array_merge( $params, $ids );
	}

	if ( ! empty( $event_types ) ) {
		$placeholders = implode( ', ', array_fill( 0, count( $event_types ), '%s' ) );
		$where[]      = "event_type IN ({$placeholders})";
		$params       = array_merge( $params, $event_types );
	}

	$match_parts = [];

	foreach ( $request_ids as $request_id ) {
		$match_parts[] = 'meta_json LIKE %s';
		$params[]      = '%"request_id":' . $request_id . '%';
	}

	foreach ( $account_refs as $account_ref ) {
		$match_parts[] = 'meta_json LIKE %s';
		$params[]      = '%"account_ref":"' . $wpdb->esc_like( $account_ref ) . '"%';
	}

	foreach ( $stations as $station ) {
		$match_parts[] = 'meta_json LIKE %s';
		$params[]      = '%"station":"' . $wpdb->esc_like( $station ) . '"%';
	}

	if ( ! empty( $match_parts ) ) {
		$where[] = '(' . implode( ' OR ', $match_parts ) . ')';
	}

	$sql = $wpdb->prepare(
		"UPDATE {$table} SET resolved_at = %s WHERE " . implode( ' AND ', $where ),
		$params
	);

	$updated = $wpdb->query( $sql );

	return false === $updated ? 0 : (int) $updated;
}

/**
 * Envía un push vacío a una suscripción.
 *
 * @param array<string, mixed> $subscription Fila de suscripción.
 */
function tavox_menu_api_send_empty_push( array $subscription ) {
	$endpoint = (string) ( $subscription['endpoint_url'] ?? '' );
	if ( '' === $endpoint ) {
		return new WP_Error( 'push_endpoint_missing', __( 'No encontramos el destino del aviso.', 'tavox-menu-api' ), [ 'status' => 400 ] );
	}

	$vapid = tavox_menu_api_build_vapid_headers( $endpoint );
	if ( is_wp_error( $vapid ) ) {
		return $vapid;
	}

	$response = wp_remote_post(
		$endpoint,
		[
			'timeout' => 8,
			'headers' => [
				'TTL'           => '60',
				'Urgency'       => 'high',
				'Authorization' => (string) $vapid['authorization'],
				'Content-Length'=> '0',
			],
			'body'    => '',
		]
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$status = (int) wp_remote_retrieve_response_code( $response );

	if ( in_array( $status, [ 401, 403, 404, 410 ], true ) ) {
		tavox_menu_api_deactivate_waiter_push_subscription( (string) ( $subscription['session_token'] ?? '' ) );
	}

	if ( $status < 200 || $status >= 300 ) {
		return new WP_Error( 'push_delivery_failed', __( 'No pudimos enviar el aviso a esta tablet.', 'tavox-menu-api' ), [ 'status' => $status ] );
	}

	global $wpdb;

	$table = tavox_menu_api_get_waiter_push_subscriptions_table_name();
	$wpdb->update(
		$table,
		[
			'last_notified_at' => tavox_menu_api_now_mysql(),
			'updated_at'       => tavox_menu_api_now_mysql(),
		],
		[ 'session_token' => (string) ( $subscription['session_token'] ?? '' ) ],
		[ '%s', '%s' ],
		[ '%s' ]
	);

	return [
		'ok'     => true,
		'status' => $status,
	];
}

/**
 * Envía un aviso push con payload real a una suscripción.
 *
 * @param array<string, mixed> $subscription Fila de suscripción.
 * @param array<string, mixed> $message      Aviso visible.
 * @return array<string, mixed>|WP_Error
 */
function tavox_menu_api_send_web_push_message( array $subscription, array $message ) {
	$endpoint = (string) ( $subscription['endpoint_url'] ?? '' );
	if ( '' === $endpoint ) {
		return new WP_Error( 'push_endpoint_missing', __( 'No encontramos el destino del aviso.', 'tavox-menu-api' ), [ 'status' => 400 ] );
	}

	$content_encoding = sanitize_text_field( (string) ( $subscription['content_encoding'] ?? 'aes128gcm' ) );
	if ( 'aes128gcm' !== $content_encoding ) {
		return new WP_Error( 'push_encoding_unsupported', __( 'Esta tablet usa un formato de aviso que no soportamos todavía.', 'tavox-menu-api' ), [ 'status' => 409 ] );
	}

	$vapid = tavox_menu_api_build_vapid_headers( $endpoint );
	if ( is_wp_error( $vapid ) ) {
		return $vapid;
	}

	$encrypted = tavox_menu_api_encrypt_web_push_payload( $subscription, $message );
	if ( is_wp_error( $encrypted ) ) {
		return $encrypted;
	}

	$headers = [
		'TTL'              => '60',
		'Urgency'          => 'high',
		'Authorization'    => (string) $vapid['authorization'],
		'Content-Encoding' => (string) ( $encrypted['encoding'] ?? 'aes128gcm' ),
		'Content-Type'     => (string) ( $encrypted['content_type'] ?? 'application/octet-stream' ),
		'Content-Length'   => (string) strlen( (string) ( $encrypted['body'] ?? '' ) ),
	];

	$tag = sanitize_text_field( (string) ( $message['tag'] ?? '' ) );
	if ( '' !== $tag ) {
		$topic = substr( preg_replace( '/[^a-zA-Z0-9\-_]/', '-', $tag ) ?: '', 0, 32 );
		if ( '' !== $topic ) {
			$headers['Topic'] = $topic;
		}
	}

	$response = wp_remote_post(
		$endpoint,
		[
			'timeout' => 8,
			'headers' => $headers,
			'body'    => (string) ( $encrypted['body'] ?? '' ),
		]
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$status = (int) wp_remote_retrieve_response_code( $response );

	if ( in_array( $status, [ 401, 403, 404, 410 ], true ) ) {
		tavox_menu_api_deactivate_waiter_push_subscription( (string) ( $subscription['session_token'] ?? '' ) );
	}

	if ( $status < 200 || $status >= 300 ) {
		return new WP_Error( 'push_delivery_failed', __( 'No pudimos enviar el aviso a esta tablet.', 'tavox-menu-api' ), [ 'status' => $status ] );
	}

	global $wpdb;

	$table = tavox_menu_api_get_waiter_push_subscriptions_table_name();
	$wpdb->update(
		$table,
		[
			'last_notified_at' => tavox_menu_api_now_mysql(),
			'updated_at'       => tavox_menu_api_now_mysql(),
		],
		[ 'session_token' => (string) ( $subscription['session_token'] ?? '' ) ],
		[ '%s', '%s' ],
		[ '%s' ]
	);

	return [
		'ok'     => true,
		'status' => $status,
	];
}

/**
 * Expande los scopes operativos a targets realtime válidos.
 *
 * @param array<int, string> $audiences
 * @return array<int, string>
 */
function tavox_menu_api_expand_realtime_scope_targets( array $audiences ): array {
	$targets = [];

	foreach ( $audiences as $audience ) {
		$scope = tavox_menu_api_sanitize_push_scope( (string) $audience );

		if ( 'all' === $scope ) {
			$targets[] = 'scope:queue';
			$targets[] = 'scope:service';
			foreach ( tavox_menu_api_get_production_station_values() as $station ) {
				$targets[] = 'scope:' . $station;
			}
			continue;
		}

		$targets[] = 'scope:' . $scope;
	}

	return array_values( array_unique( array_filter( $targets ) ) );
}

/**
 * Publica invalidaciones realtime equivalentes a un aviso operativo del equipo.
 *
 * @param array<string, mixed> $message
 * @param array<string, mixed> $options
 * @param array<int, string>   $audiences
 * @param array<int, int>      $target_waiter_user_ids
 */
function tavox_menu_api_publish_realtime_from_team_notification( array $message, array $options, array $audiences, array $target_waiter_user_ids ): void {
	$type = sanitize_key( (string) ( $message['type'] ?? 'team_update' ) );
	if ( in_array( $type, [ 'push_test', 'push_enabled' ], true ) ) {
		return;
	}

	$scope_targets = tavox_menu_api_expand_realtime_scope_targets( $audiences );
	$user_targets  = array_values(
		array_unique(
			array_filter(
				array_map(
					static fn( $user_id ): string => $user_id > 0 ? 'user:' . absint( $user_id ) : '',
					$target_waiter_user_ids
				)
			)
		)
	);
	$targets       = ! empty( $user_targets ) ? $user_targets : $scope_targets;
	$table_token   = sanitize_text_field( (string) ( $options['table_token'] ?? $message['meta']['table_token'] ?? '' ) );
	$meta          = is_array( $message['meta'] ?? null ) ? $message['meta'] : [];

	if ( empty( $targets ) ) {
		return;
	}

	tavox_menu_api_publish_realtime_event(
		[
			'event'      => 'notifications.sync',
			'targets'    => $targets,
			'table_token'=> $table_token,
			'meta'       => $meta,
		]
	);

	if ( in_array( $type, [ 'new_request', 'request_claimed' ], true ) ) {
		tavox_menu_api_publish_realtime_event(
			[
				'event'      => 'queue.sync',
				'targets'    => array_values( array_unique( array_merge( $targets, [ 'scope:queue' ] ) ) ),
				'table_token'=> $table_token,
				'meta'       => $meta,
			]
		);
	}

	if ( in_array( $type, [ 'request_claimed', 'service_ready', 'service_partial_ready', 'service_delivered', 'table_message_new', 'table_message_reply', 'table_message_resolved' ], true ) || in_array( 'scope:service', $scope_targets, true ) || ! empty( $user_targets ) ) {
		tavox_menu_api_publish_realtime_event(
			[
				'event'      => 'service.sync',
				'targets'    => array_values( array_unique( array_merge( $targets, in_array( 'scope:service', $scope_targets, true ) ? [ 'scope:service' ] : [] ) ) ),
				'table_token'=> $table_token,
				'meta'       => $meta,
			]
		);
	}

	foreach ( tavox_menu_api_get_production_station_values() as $station ) {
		if ( ! in_array( 'scope:' . $station, $scope_targets, true ) ) {
			continue;
		}

		tavox_menu_api_publish_realtime_event(
			[
				'event'      => 'production.sync',
				'targets'    => [ 'scope:' . $station ],
				'table_token'=> $table_token,
				'scope'      => $station,
				'meta'       => $meta,
			]
		);
	}

	if ( in_array( $type, [ 'table_message_new', 'table_message_reply', 'table_message_resolved' ], true ) ) {
		tavox_menu_api_publish_realtime_event(
			[
				'event'      => str_replace( '_', '.', $type ),
				'targets'    => $targets,
				'table_token'=> $table_token,
				'meta'       => $meta,
			]
		);
	}
}

/**
 * Dispara un aviso a las tablets activas del equipo.
 *
 * @param array<string, mixed> $message Aviso visible.
 * @param array<string, mixed> $options Opciones de envío.
 */
function tavox_menu_api_push_team_notification( array $message, array $options = [] ): void {
	global $wpdb;

	$subscriptions_table = tavox_menu_api_get_waiter_push_subscriptions_table_name();
	$sessions_table      = tavox_menu_api_get_waiter_sessions_table_name();
	$exclude_session     = sanitize_text_field( (string) ( $options['exclude_session_token'] ?? '' ) );
	$exclude_user_id     = absint( $options['exclude_user_id'] ?? 0 );
	$target_waiter_user_ids = array_values( array_filter( array_map( 'absint', (array) ( $options['target_waiter_user_ids'] ?? [] ) ) ) );
	$audiences_raw       = $options['audiences'] ?? array_merge( [ 'all', 'service' ], tavox_menu_api_get_production_station_values() );
	$audiences           = array_values(
		array_unique(
			array_map(
				'tavox_menu_api_sanitize_push_scope',
				is_array( $audiences_raw ) ? $audiences_raw : [ (string) $audiences_raw ]
			)
		)
	);

	tavox_menu_api_publish_realtime_from_team_notification( $message, $options, $audiences, $target_waiter_user_ids );

	$notification_sessions = tavox_menu_api_get_active_waiter_notification_sessions(
		$target_waiter_user_ids,
		$exclude_session,
		$exclude_user_id
	);

	foreach ( $notification_sessions as $session_row ) {
		$session_token = sanitize_text_field( (string) ( $session_row['session_token'] ?? '' ) );
		if ( '' === $session_token ) {
			continue;
		}

		tavox_menu_api_queue_waiter_push_message(
			$session_token,
			absint( $session_row['user_id'] ?? 0 ),
			$message
		);
	}

	if ( ! tavox_menu_api_is_push_ready() ) {
		return;
	}

	tavox_menu_api_cleanup_waiter_push_subscriptions();

	$where               = [ "s.status = 'active'", "ws.status = 'active'" ];
	$params              = [];

	if ( '' !== $exclude_session ) {
		$where[]  = 's.session_token <> %s';
		$params[] = $exclude_session;
	}

	if ( $exclude_user_id > 0 ) {
		$where[]  = 's.waiter_user_id <> %d';
		$params[] = $exclude_user_id;
	}

	if ( ! empty( $target_waiter_user_ids ) ) {
		$placeholders = implode( ', ', array_fill( 0, count( $target_waiter_user_ids ), '%d' ) );
		$where[]      = "s.waiter_user_id IN ({$placeholders})";
		$params       = array_merge( $params, $target_waiter_user_ids );
	}

	if ( ! in_array( 'all', $audiences, true ) ) {
		$placeholders = implode( ', ', array_fill( 0, count( $audiences ), '%s' ) );
		$where[]      = "s.notification_scope IN ({$placeholders})";
		$params       = array_merge( $params, $audiences );
	}

	$sql = "SELECT s.* FROM {$subscriptions_table} s
		INNER JOIN {$sessions_table} ws ON ws.session_token = s.session_token
		WHERE " . implode( ' AND ', $where ) . '
		ORDER BY s.updated_at DESC';

	if ( ! empty( $params ) ) {
		$sql = $wpdb->prepare( $sql, $params );
	}

	$subscriptions = $wpdb->get_results( $sql, ARRAY_A );
	if ( empty( $subscriptions ) ) {
		return;
	}

	foreach ( $subscriptions as $subscription ) {
		$session_token = (string) ( $subscription['session_token'] ?? '' );
		if ( '' === $session_token ) {
			continue;
		}

		$result = tavox_menu_api_send_web_push_message( $subscription, $message );
		if ( is_wp_error( $result ) ) {
			tavox_menu_api_log_operational_event(
				'push_web_payload_failed',
				[
					'session_token' => $session_token,
					'device_label'  => sanitize_text_field( (string) ( $subscription['device_label'] ?? '' ) ),
					'scope'         => sanitize_key( (string) ( $subscription['notification_scope'] ?? '' ) ),
					'type'          => sanitize_key( (string) ( $message['type'] ?? '' ) ),
					'error'         => $result->get_error_message(),
				]
			);

			$fallback = tavox_menu_api_send_empty_push( $subscription );
			if ( is_wp_error( $fallback ) ) {
				tavox_menu_api_log_operational_event(
					'push_empty_fallback_failed',
					[
						'session_token' => $session_token,
						'device_label'  => sanitize_text_field( (string) ( $subscription['device_label'] ?? '' ) ),
						'scope'         => sanitize_key( (string) ( $subscription['notification_scope'] ?? '' ) ),
						'type'          => sanitize_key( (string) ( $message['type'] ?? '' ) ),
						'error'         => $fallback->get_error_message(),
					]
				);
				continue;
			}

			tavox_menu_api_log_operational_event(
				'push_empty_fallback_sent',
				[
					'session_token' => $session_token,
					'device_label'  => sanitize_text_field( (string) ( $subscription['device_label'] ?? '' ) ),
					'scope'         => sanitize_key( (string) ( $subscription['notification_scope'] ?? '' ) ),
					'type'          => sanitize_key( (string) ( $message['type'] ?? '' ) ),
					'status'        => (int) ( $fallback['status'] ?? 0 ),
				]
			);
			continue;
		}

		tavox_menu_api_log_operational_event(
			'push_web_payload_sent',
			[
				'session_token' => $session_token,
				'device_label'  => sanitize_text_field( (string) ( $subscription['device_label'] ?? '' ) ),
				'scope'         => sanitize_key( (string) ( $subscription['notification_scope'] ?? '' ) ),
				'type'          => sanitize_key( (string) ( $message['type'] ?? '' ) ),
				'status'        => (int) ( $result['status'] ?? 0 ),
			]
		);
	}
}

/**
 * Devuelve y marca como entregados los avisos pendientes de una sesión.
 *
 * @return array<string, mixed>
 */
function tavox_menu_api_get_waiter_push_inbox( string $session_token ): array {
	global $wpdb;

	$table = tavox_menu_api_get_waiter_push_messages_table_name();
	$rows  = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$table}
			WHERE session_token = %s AND delivered_at IS NULL AND resolved_at IS NULL
			ORDER BY created_at ASC
			LIMIT 10",
			$session_token
		),
		ARRAY_A
	);

	$rows = is_array( $rows ) ? $rows : [];
	if ( empty( $rows ) ) {
		return [ 'items' => [] ];
	}

	$ids = array_values( array_filter( array_map( 'absint', wp_list_pluck( $rows, 'id' ) ) ) );
	if ( ! empty( $ids ) ) {
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$params       = array_merge( [ tavox_menu_api_now_mysql() ], $ids );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET delivered_at = %s WHERE id IN ({$placeholders})",
				$params
			)
		);
	}

	$request_map = tavox_menu_api_get_waiter_notification_request_map( $rows );

	return [
		'items' => array_map(
			static fn( array $row ): array => tavox_menu_api_format_waiter_push_message_row( $row, $request_map ),
			$rows
		),
	];
}

/**
 * Devuelve el centro de avisos vigente para una sesión.
 *
 * @return array<string, mixed>
 */
function tavox_menu_api_list_waiter_notifications( string $session_token, int $limit = 40, ?WP_User $current_user = null ): array {
	global $wpdb;

	if ( '' === $session_token ) {
		return [
			'items'        => [],
			'unread_count' => 0,
			'active_count' => 0,
		];
	}

	$table = tavox_menu_api_get_waiter_push_messages_table_name();
	$limit = max( 10, min( 100, $limit ) );

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$table}
			WHERE session_token = %s
				AND resolved_at IS NULL
				AND event_type NOT IN ('push_test', 'push_enabled')
			ORDER BY created_at DESC
			LIMIT %d",
			$session_token,
			$limit
		),
		ARRAY_A
	);

	$rows = is_array( $rows ) ? $rows : [];
	$request_map = tavox_menu_api_get_waiter_notification_request_map( $rows );

	return [
		'items'        => array_map(
			static fn( array $row ): array => tavox_menu_api_format_waiter_push_message_row( $row, $request_map, $current_user ),
			$rows
		),
		'unread_count' => count(
			array_filter(
				$rows,
				static fn( array $row ): bool => empty( $row['read_at'] ) && empty( $row['resolved_at'] )
			)
		),
		'active_count' => count( $rows ),
	];
}

/**
 * Devuelve el estado push de la sesión actual.
 *
 * @param array<string, mixed> $session Sesión activa.
 * @return array<string, mixed>
 */
function tavox_menu_api_get_waiter_push_state( array $session ): array {
	$session_row   = is_array( $session['session'] ?? null ) ? $session['session'] : [];
	$session_token = (string) ( $session_row['session_token'] ?? '' );
	$row           = tavox_menu_api_get_active_waiter_push_subscription( $session_token );
	$settings = tavox_menu_api_get_settings();

	return [
		'enabled'      => tavox_menu_api_is_push_ready() && ! empty( $settings['push_notifications_enabled'] ),
		'public_key'   => (string) ( $settings['push_vapid_public_key'] ?? '' ),
		'active'       => is_array( $row ),
		'device_label' => is_array( $row ) ? (string) ( $row['device_label'] ?? '' ) : '',
		'scope'        => is_array( $row ) ? tavox_menu_api_sanitize_push_scope( (string) ( $row['notification_scope'] ?? 'service' ) ) : 'service',
	];
}

/**
 * Envía un aviso de prueba a una sesión concreta.
 *
 * @return array<string, mixed>|WP_Error
 */
function tavox_menu_api_send_waiter_push_test_to_session( string $session_token, int $user_id = 0 ) {
	$row = tavox_menu_api_get_active_waiter_push_subscription( $session_token );
	if ( ! is_array( $row ) ) {
		return new WP_Error( 'push_inactive', __( 'Activa primero los avisos en esta tablet.', 'tavox-menu-api' ), [ 'status' => 409 ] );
	}

	$message = [
		'type'  => 'push_test',
		'title' => 'Aviso de prueba',
		'body'  => 'Si estás viendo esto, la tablet ya quedó lista para recibir avisos.',
		'url'   => tavox_menu_api_get_team_frontend_url( '/equipo/pedidos' ),
		'tag'   => 'push-test',
		'meta'  => [ 'kind' => 'push_test' ],
	];

	tavox_menu_api_queue_waiter_push_message( $session_token, $user_id, $message );

	$result = tavox_menu_api_send_web_push_message( $row, $message );
	if ( ! is_wp_error( $result ) ) {
		return $result;
	}

	return tavox_menu_api_send_empty_push( $row );
}

/**
 * Devuelve la clave transient de un aviso push programado.
 */
function tavox_menu_api_get_waiter_push_test_job_key( string $job_id ): string {
	return 'tavox_push_test_job_' . sanitize_key( $job_id );
}

/**
 * Acorta un token de sesión para logs.
 */
function tavox_menu_api_shorten_waiter_push_session_token( string $session_token ): string {
	$session_token = trim( $session_token );

	if ( '' === $session_token ) {
		return '';
	}

	if ( strlen( $session_token ) <= 14 ) {
		return $session_token;
	}

	return substr( $session_token, 0, 8 ) . '...' . substr( $session_token, -6 );
}

/**
 * Registra un error puntual de avisos push.
 *
 * @param array<string, scalar|null> $context
 */
function tavox_menu_api_log_waiter_push_error( string $event, array $context = [] ): void {
	$parts = [];

	foreach ( $context as $key => $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			continue;
		}

		$normalized = str_replace( [ "\r", "\n" ], ' ', (string) $value );
		$parts[]    = sanitize_key( (string) $key ) . '=' . $normalized;
	}

	error_log( '[tavox push] ' . sanitize_key( $event ) . ( $parts ? ' ' . implode( ' ', $parts ) : '' ) );
}

/**
 * Guarda un aviso push programado en el servidor.
 *
 * @return array<string, mixed>
 */
function tavox_menu_api_create_waiter_push_test_job( string $session_token, int $user_id, int $delay_seconds ): array {
	$job_id  = strtolower( wp_generate_password( 24, false, false ) );
	$expires = time() + max( 180, $delay_seconds + 120 );
	$job     = [
		'job_id'        => $job_id,
		'session_token' => $session_token,
		'user_id'       => $user_id,
		'delay_seconds' => $delay_seconds,
		'expires'       => $expires,
	];

	set_transient( tavox_menu_api_get_waiter_push_test_job_key( $job_id ), $job, max( 180, $delay_seconds + 180 ) );

	return $job;
}

/**
 * Recupera un aviso push programado.
 *
 * @return array<string, mixed>
 */
function tavox_menu_api_get_waiter_push_test_job( string $job_id ): array {
	$job = get_transient( tavox_menu_api_get_waiter_push_test_job_key( $job_id ) );

	return is_array( $job ) ? $job : [];
}

/**
 * Elimina un aviso push programado.
 */
function tavox_menu_api_delete_waiter_push_test_job( string $job_id ): void {
	if ( '' !== $job_id ) {
		delete_transient( tavox_menu_api_get_waiter_push_test_job_key( $job_id ) );
	}
}

/**
 * Firma una ejecución diferida del aviso de prueba.
 */
function tavox_menu_api_sign_waiter_push_test_run( string $job_id, int $expires ): string {
	$data = implode( '|', [ $job_id, (string) $expires ] );

	return hash_hmac( 'sha256', $data, wp_salt( 'auth' ) );
}

/**
 * Lanza una petición interna y no bloqueante para ejecutar un aviso de prueba diferido.
 *
 * @param array<string, mixed> $job
 */
function tavox_menu_api_dispatch_delayed_waiter_push_test_request( array $job ): void {
	$job_id  = sanitize_key( (string) ( $job['job_id'] ?? '' ) );
	$expires = absint( $job['expires'] ?? 0 );

	if ( '' === $job_id || $expires < time() ) {
		tavox_menu_api_log_waiter_push_error(
			'push_test_dispatch_failed',
			[
				'job_id' => $job_id,
				'reason' => 'invalid-job',
			]
		);
		return;
	}

	$url = add_query_arg(
		[
			'job_id'    => $job_id,
			'expires'   => $expires,
			'signature' => tavox_menu_api_sign_waiter_push_test_run( $job_id, $expires ),
		],
		rest_url( 'tavox/v1/waiter/push/test-run' )
	);

	$response = wp_remote_post(
		$url,
		[
			'timeout'   => 5,
			'blocking'  => false,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			'headers'   => [
				'X-Tavox-Push-Source' => 'delayed-dispatch',
			],
			'body'      => '',
		]
	);

	if ( is_wp_error( $response ) ) {
		tavox_menu_api_log_waiter_push_error(
			'push_test_dispatch_failed',
			[
				'job_id' => $job_id,
				'reason' => $response->get_error_message(),
			]
		);
		return;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( 0 !== $code && $code >= 400 ) {
		tavox_menu_api_log_waiter_push_error(
			'push_test_dispatch_failed',
			[
				'job_id' => $job_id,
				'code'   => $code,
			]
		);
	}
}

/**
 * Ejecuta un aviso de prueba programado.
 *
 * @param array<string, mixed> $job
 * @return array<string, mixed>|WP_Error|null
 */
function tavox_menu_api_execute_waiter_push_test_job( array $job ) {
	$job_id        = sanitize_key( (string) ( $job['job_id'] ?? '' ) );
	$session_token = sanitize_text_field( (string) ( $job['session_token'] ?? '' ) );
	$user_id       = absint( $job['user_id'] ?? 0 );
	$delay_seconds = max( 0, min( 60, absint( $job['delay_seconds'] ?? 0 ) ) );
	$expires       = absint( $job['expires'] ?? 0 );

	if ( '' === $job_id || '' === $session_token || $expires < time() ) {
		tavox_menu_api_delete_waiter_push_test_job( $job_id );
		tavox_menu_api_log_waiter_push_error(
			'push_test_run_invalid',
			[
				'job_id'  => $job_id,
				'session' => tavox_menu_api_shorten_waiter_push_session_token( $session_token ),
				'reason'  => 'job-invalid',
			]
		);

		return new WP_Error( 'push_test_run_invalid', __( 'No pudimos preparar el aviso programado.', 'tavox-menu-api' ), [ 'status' => 403 ] );
	}

	if ( function_exists( 'ignore_user_abort' ) ) {
		ignore_user_abort( true );
	}

	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( max( 20, $delay_seconds + 20 ) );
	}

	if ( $delay_seconds > 0 ) {
		sleep( $delay_seconds );
	}

	wp_clear_scheduled_hook( 'tavox_menu_api_run_scheduled_waiter_push_test', [ $job_id, $user_id ] );
	wp_clear_scheduled_hook( 'tavox_menu_api_run_scheduled_waiter_push_test', [ $session_token, $user_id ] );
	tavox_menu_api_delete_waiter_push_test_job( $job_id );

	$result = tavox_menu_api_send_waiter_push_test_to_session( $session_token, $user_id );

	if ( is_wp_error( $result ) ) {
		tavox_menu_api_log_waiter_push_error(
			'push_test_send_failed',
			[
				'job_id'  => $job_id,
				'session' => tavox_menu_api_shorten_waiter_push_session_token( $session_token ),
				'error'   => $result->get_error_code(),
			]
		);
	}

	return $result;
}

/**
 * Ejecuta un aviso de prueba programado.
 */
function tavox_menu_api_run_scheduled_waiter_push_test( string $job_or_session_token, int $user_id = 0 ): void {
	$job = tavox_menu_api_get_waiter_push_test_job( sanitize_key( $job_or_session_token ) );

	if ( ! empty( $job ) ) {
		$result = tavox_menu_api_execute_waiter_push_test_job( $job );

		if ( is_wp_error( $result ) ) {
			tavox_menu_api_log_waiter_push_error(
				'push_test_cron_failed',
				[
					'job_id' => sanitize_key( (string) ( $job['job_id'] ?? '' ) ),
					'error'  => $result->get_error_code(),
				]
			);
		}

		return;
	}

	if ( ! preg_match( '/^[A-Za-z0-9]{40,}$/', $job_or_session_token ) ) {
		return;
	}

	$result = tavox_menu_api_send_waiter_push_test_to_session( sanitize_text_field( $job_or_session_token ), absint( $user_id ) );

	if ( is_wp_error( $result ) ) {
		tavox_menu_api_log_waiter_push_error(
			'push_test_cron_failed',
			[
				'session' => tavox_menu_api_shorten_waiter_push_session_token( $job_or_session_token ),
				'error'   => $result->get_error_code(),
			]
		);
	}
}
add_action( 'tavox_menu_api_run_scheduled_waiter_push_test', 'tavox_menu_api_run_scheduled_waiter_push_test', 10, 2 );

/**
 * Registra rutas REST de avisos push del equipo.
 */
function tavox_menu_api_register_waiter_push_routes(): void {
	register_rest_route(
		'tavox/v1',
		'/waiter/push/config',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'tavox_menu_api_rest_waiter_push_config',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'tavox/v1',
		'/waiter/push/subscribe',
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'tavox_menu_api_rest_waiter_push_subscribe',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'tavox/v1',
		'/waiter/push/unsubscribe',
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'tavox_menu_api_rest_waiter_push_unsubscribe',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'tavox/v1',
		'/waiter/push/inbox',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'tavox_menu_api_rest_waiter_push_inbox',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'tavox/v1',
		'/waiter/notifications',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'tavox_menu_api_rest_waiter_notifications',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'tavox/v1',
		'/waiter/notifications/read',
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'tavox_menu_api_rest_waiter_notifications_read',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'tavox/v1',
		'/waiter/push/test',
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'tavox_menu_api_rest_waiter_push_test',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'tavox/v1',
		'/waiter/push/test-delayed',
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'tavox_menu_api_rest_waiter_push_test_delayed',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'tavox/v1',
		'/waiter/push/test-run',
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'tavox_menu_api_rest_waiter_push_test_run',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'tavox/v1',
		'/waiter/push/context',
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'tavox_menu_api_rest_waiter_push_context',
			'permission_callback' => '__return_true',
		]
	);
}
add_action( 'rest_api_init', 'tavox_menu_api_register_waiter_push_routes' );

/**
 * Configuración push de la sesión actual.
 *
 * @return WP_REST_Response|WP_Error
 */
function tavox_menu_api_rest_waiter_push_config( WP_REST_Request $request ) {
	$session = tavox_menu_api_require_waiter_session( $request );
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	return tavox_menu_api_no_store_rest_response( tavox_menu_api_get_waiter_push_state( $session ) );
}

/**
 * Activa avisos push para la sesión actual.
 *
 * @return WP_REST_Response|WP_Error
 */
function tavox_menu_api_rest_waiter_push_subscribe( WP_REST_Request $request ) {
	$session = tavox_menu_api_require_waiter_session( $request );
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	if ( ! tavox_menu_api_is_push_ready() ) {
		return new WP_Error( 'push_not_ready', __( 'Los avisos de la tablet todavía no están disponibles.', 'tavox-menu-api' ), [ 'status' => 503 ] );
	}

	$payload       = $request->get_json_params();
	$payload       = is_array( $payload ) ? $payload : [];
	$subscription  = is_array( $payload['subscription'] ?? null ) ? $payload['subscription'] : [];
	$device_label  = sanitize_text_field( (string) ( $payload['device_label'] ?? '' ) );
	$scope         = tavox_menu_api_sanitize_push_scope( (string) ( $payload['scope'] ?? 'service' ) );
	$subscription['device_label'] = $device_label;
	$subscription['notification_scope'] = $scope;

	$session_row  = is_array( $session['session'] ?? null ) ? $session['session'] : [];
	$session_token = (string) ( $session_row['session_token'] ?? '' );

	$result = tavox_menu_api_upsert_waiter_push_subscription( $session, $subscription );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$message = [
		'type'  => 'push_enabled',
		'title' => 'Avisos activados',
		'body'  => 'Esta tablet ya puede avisarte cuando entre un pedido o haya algo listo.',
		'url'   => tavox_menu_api_get_team_frontend_url( '/equipo/pedidos' ),
		'tag'   => 'push-enabled',
		'meta'  => [ 'kind' => 'push_enabled' ],
	];

	tavox_menu_api_queue_waiter_push_message( $session_token, absint( $session['user']->ID ), $message );

	return tavox_menu_api_no_store_rest_response(
		[
			'ok'    => true,
			'push'  => tavox_menu_api_get_waiter_push_state( $session ),
		]
	);
}

/**
 * Actualiza el tipo de avisos que debe recibir la pantalla actual.
 *
 * @return WP_REST_Response|WP_Error
 */
function tavox_menu_api_rest_waiter_push_context( WP_REST_Request $request ) {
	$session = tavox_menu_api_require_waiter_session( $request );
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	$payload = $request->get_json_params();
	$payload = is_array( $payload ) ? $payload : [];
	$scope   = tavox_menu_api_sanitize_push_scope( (string) ( $payload['scope'] ?? 'service' ) );

	tavox_menu_api_update_waiter_push_scope( (string) ( $session['session']['session_token'] ?? '' ), $scope );

	return tavox_menu_api_no_store_rest_response(
		[
			'ok'   => true,
			'push' => tavox_menu_api_get_waiter_push_state( $session ),
		]
	);
}

/**
 * Desactiva avisos push de la sesión actual.
 *
 * @return WP_REST_Response|WP_Error
 */
function tavox_menu_api_rest_waiter_push_unsubscribe( WP_REST_Request $request ) {
	$session = tavox_menu_api_require_waiter_session( $request );
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	tavox_menu_api_deactivate_waiter_push_subscription( (string) ( $session['session']['session_token'] ?? '' ) );

	return tavox_menu_api_no_store_rest_response(
		[
			'ok'   => true,
			'push' => tavox_menu_api_get_waiter_push_state( $session ),
		]
	);
}

/**
 * Devuelve la bandeja push pendiente de la sesión actual.
 *
 * @return WP_REST_Response|WP_Error
 */
function tavox_menu_api_rest_waiter_push_inbox( WP_REST_Request $request ) {
	$session = tavox_menu_api_require_waiter_session( $request );
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	return tavox_menu_api_no_store_rest_response( tavox_menu_api_get_waiter_push_inbox( (string) ( $session['session']['session_token'] ?? '' ) ) );
}

/**
 * Devuelve el centro de avisos vigente para la sesión actual.
 *
 * @return WP_REST_Response|WP_Error
 */
function tavox_menu_api_rest_waiter_notifications( WP_REST_Request $request ) {
	$session = tavox_menu_api_require_waiter_session( $request );
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	$limit = absint( $request->get_param( 'limit' ) ?: 40 );

	return tavox_menu_api_no_store_rest_response(
		tavox_menu_api_list_waiter_notifications(
			(string) ( $session['session']['session_token'] ?? '' ),
			$limit,
			$session['user']
		)
	);
}

/**
 * Marca avisos como vistos en la sesión actual.
 *
 * @return WP_REST_Response|WP_Error
 */
function tavox_menu_api_rest_waiter_notifications_read( WP_REST_Request $request ) {
	$session = tavox_menu_api_require_waiter_session( $request );
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	$payload = $request->get_json_params();
	$payload = is_array( $payload ) ? $payload : [];
	$ids_raw = $payload['ids'] ?? [];
	$ids     = is_array( $ids_raw ) ? $ids_raw : wp_parse_list( (string) $ids_raw );

	$updated = tavox_menu_api_mark_waiter_notifications_read(
		(string) ( $session['session']['session_token'] ?? '' ),
		$ids
	);

	return rest_ensure_response(
		[
			'ok'      => true,
			'updated' => $updated,
		]
	);
}

/**
 * Lanza un aviso de prueba a la tablet actual.
 *
 * @return WP_REST_Response|WP_Error
 */
function tavox_menu_api_rest_waiter_push_test( WP_REST_Request $request ) {
	$session = tavox_menu_api_require_waiter_session( $request );
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	$session_token = (string) ( $session['session']['session_token'] ?? '' );
	$result        = tavox_menu_api_send_waiter_push_test_to_session( $session_token, absint( $session['user']->ID ) );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( [ 'ok' => true ] );
}

/**
 * Programa un aviso de prueba desde el servidor para comprobar la notificación fuera de la app.
 *
 * @return WP_REST_Response|WP_Error
 */
function tavox_menu_api_rest_waiter_push_test_delayed( WP_REST_Request $request ) {
	$session = tavox_menu_api_require_waiter_session( $request );
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	$session_token = (string) ( $session['session']['session_token'] ?? '' );
	$user_id       = absint( $session['user']->ID ?? 0 );
	$payload       = $request->get_json_params();
	$payload       = is_array( $payload ) ? $payload : [];
	$delay_seconds = absint( $payload['delay_seconds'] ?? 10 );
	$delay_seconds = max( 3, min( 60, $delay_seconds ) );

	if ( ! is_array( tavox_menu_api_get_active_waiter_push_subscription( $session_token ) ) ) {
		return new WP_Error( 'push_inactive', __( 'Activa primero los avisos en esta tablet.', 'tavox-menu-api' ), [ 'status' => 409 ] );
	}

	$job = tavox_menu_api_create_waiter_push_test_job( $session_token, $user_id, $delay_seconds );

	wp_clear_scheduled_hook( 'tavox_menu_api_run_scheduled_waiter_push_test', [ $session_token, $user_id ] );
	wp_clear_scheduled_hook( 'tavox_menu_api_run_scheduled_waiter_push_test', [ (string) ( $job['job_id'] ?? '' ), $user_id ] );
	wp_schedule_single_event( time() + $delay_seconds + 30, 'tavox_menu_api_run_scheduled_waiter_push_test', [ (string) ( $job['job_id'] ?? '' ), $user_id ] );
	tavox_menu_api_dispatch_delayed_waiter_push_test_request( $job );

	return rest_ensure_response(
		[
			'ok'            => true,
			'delay_seconds' => $delay_seconds,
		]
	);
}

/**
 * Ejecuta la prueba diferida desde una petición interna del servidor.
 *
 * @return WP_REST_Response|WP_Error
 */
function tavox_menu_api_rest_waiter_push_test_run( WP_REST_Request $request ) {
	$job_id    = sanitize_key( (string) $request->get_param( 'job_id' ) );
	$expires   = absint( $request->get_param( 'expires' ) );
	$signature = sanitize_text_field( (string) $request->get_param( 'signature' ) );

	if ( '' === $job_id || '' === $signature || $expires < time() ) {
		tavox_menu_api_log_waiter_push_error(
			'push_test_run_invalid',
			[
				'job_id' => $job_id,
				'reason' => 'request-invalid',
			]
		);
		return new WP_Error( 'push_test_run_invalid', __( 'No pudimos preparar el aviso programado.', 'tavox-menu-api' ), [ 'status' => 403 ] );
	}

	$expected_signature = tavox_menu_api_sign_waiter_push_test_run( $job_id, $expires );
	if ( ! hash_equals( $expected_signature, $signature ) ) {
		tavox_menu_api_log_waiter_push_error(
			'push_test_run_invalid',
			[
				'job_id' => $job_id,
				'reason' => 'signature-mismatch',
			]
		);
		return new WP_Error( 'push_test_run_invalid', __( 'No pudimos validar el aviso programado.', 'tavox-menu-api' ), [ 'status' => 403 ] );
	}

	$job = tavox_menu_api_get_waiter_push_test_job( $job_id );
	if ( empty( $job ) ) {
		tavox_menu_api_log_waiter_push_error(
			'push_test_run_invalid',
			[
				'job_id' => $job_id,
				'reason' => 'missing-job',
			]
		);
		return rest_ensure_response( [ 'ok' => true, 'skipped' => true ] );
	}

	if ( $expires !== absint( $job['expires'] ?? 0 ) ) {
		tavox_menu_api_log_waiter_push_error(
			'push_test_run_invalid',
			[
				'job_id' => $job_id,
				'reason' => 'expiry-mismatch',
			]
		);
		return new WP_Error( 'push_test_run_invalid', __( 'No pudimos validar el aviso programado.', 'tavox-menu-api' ), [ 'status' => 403 ] );
	}

	$result = tavox_menu_api_execute_waiter_push_test_job( $job );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( [ 'ok' => true ] );
}
