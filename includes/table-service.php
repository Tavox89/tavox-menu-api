<?php

defined( 'ABSPATH' ) || exit;

/**
 * Fecha/hora MySQL actual en la zona WordPress.
 */
function tavox_menu_api_now_mysql(): string {
	return current_time( 'mysql' );
}

/**
 * Limpia solicitudes vencidas y claims que expiraron.
 */
function tavox_menu_api_cleanup_request_states(): void {
	global $wpdb;

	$maintenance_key = 'request_states_cleanup';
	$has_windowing   = function_exists( 'tavox_menu_api_begin_maintenance_window' ) && function_exists( 'tavox_menu_api_end_maintenance_window' );

	if ( $has_windowing && ! tavox_menu_api_begin_maintenance_window( $maintenance_key, 15, 8 ) ) {
		return;
	}

	$requests_table  = tavox_menu_api_get_table_requests_table_name();
	$settings        = tavox_menu_api_get_settings();
	$now_mysql       = tavox_menu_api_now_mysql();
	$claim_timeout   = max( 15, (int) ( $settings['claim_timeout_seconds'] ?? 90 ) );
	$stale_claim_sql = gmdate( 'Y-m-d H:i:s', time() - $claim_timeout );
	$expired_rows    = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, table_id, table_type
			FROM {$requests_table}
			WHERE status = 'pending' AND expires_at IS NOT NULL AND expires_at < %s",
			$now_mysql
		),
		ARRAY_A
	);
	$expired_rows    = is_array( $expired_rows ) ? $expired_rows : [];

	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$requests_table}
			SET status = 'expired', updated_at = %s
			WHERE status = 'pending' AND expires_at IS NOT NULL AND expires_at < %s",
			$now_mysql,
			$now_mysql
		)
	);

	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$requests_table}
			SET status = 'pending',
				waiter_user_id = 0,
				waiter_name = '',
				claimed_at = NULL,
				updated_at = %s
			WHERE status = 'claimed' AND claimed_at IS NOT NULL AND claimed_at < %s",
			$now_mysql,
			$stale_claim_sql
		)
	);

	if ( ! empty( $expired_rows ) ) {
		$expired_request_ids = array_values( array_filter( array_map( 'absint', wp_list_pluck( $expired_rows, 'id' ) ) ) );
		$expired_account_refs = array_values(
			array_filter(
				array_map(
					static function ( array $row ): string {
						return function_exists( 'tavox_menu_api_build_waiter_account_ref' )
							? tavox_menu_api_build_waiter_account_ref(
								(string) ( $row['table_type'] ?? 'dine_in' ),
								absint( $row['table_id'] ?? 0 )
							)
							: '';
					},
					$expired_rows
				)
			)
		);

		if ( ! empty( $expired_request_ids ) && function_exists( 'tavox_menu_api_resolve_waiter_notifications' ) ) {
			tavox_menu_api_resolve_waiter_notifications(
				[
					'event_types'  => [ 'new_request' ],
					'request_ids'  => $expired_request_ids,
					'account_refs' => $expired_account_refs,
				]
			);
		}

		if ( function_exists( 'tavox_menu_api_publish_realtime_event' ) ) {
			tavox_menu_api_publish_realtime_event(
				[
					'event'   => 'queue.sync',
					'targets' => [ 'scope:queue' ],
				]
			);
			tavox_menu_api_publish_realtime_event(
				[
					'event'   => 'notifications.sync',
					'targets' => [ 'scope:service' ],
				]
			);
		}
	}

	if ( $has_windowing ) {
		tavox_menu_api_end_maintenance_window( $maintenance_key, 15 );
	}
}

/**
 * Normaliza una línea del request para mantener un contrato estable.
 *
 * @param array<string, mixed> $item
 * @return array<string, mixed>
 */
function tavox_menu_api_normalize_request_payload_item( array $item, string $default_fulfillment_mode = 'dine_in' ): array {
	$extras = [];

	foreach ( (array) ( $item['extras'] ?? [] ) as $extra ) {
		if ( ! is_array( $extra ) ) {
			continue;
		}

		$extras[] = [
			'groupId'  => sanitize_text_field( (string) ( $extra['groupId'] ?? $extra['group_id'] ?? '' ) ),
			'optionId' => sanitize_text_field( (string) ( $extra['optionId'] ?? $extra['option_id'] ?? '' ) ),
			'label'    => sanitize_text_field( (string) ( $extra['label'] ?? '' ) ),
			'price'    => (float) ( $extra['price'] ?? $extra['price_usd'] ?? 0 ),
		];
	}

	return [
		'id'               => sanitize_text_field( (string) ( $item['id'] ?? '' ) ),
		'productId'        => absint( $item['productId'] ?? $item['product_id'] ?? 0 ),
		'sku'              => sanitize_text_field( (string) ( $item['sku'] ?? '' ) ),
		'name'             => sanitize_text_field( (string) ( $item['name'] ?? '' ) ),
		'qty'              => max( 1, (float) ( $item['qty'] ?? 1 ) ),
		'basePrice'        => (float) ( $item['basePrice'] ?? $item['price_usd'] ?? $item['price'] ?? 0 ),
		'extras'           => $extras,
		'note'             => sanitize_textarea_field( (string) ( $item['note'] ?? '' ) ),
		'fulfillment_mode' => tavox_menu_api_sanitize_fulfillment_mode(
			(string) ( $item['fulfillment_mode'] ?? $item['fulfillmentMode'] ?? '' ),
			$default_fulfillment_mode
		),
	];
}

/**
 * Normaliza el payload completo del request.
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function tavox_menu_api_normalize_request_payload( array $payload, string $default_fulfillment_mode = 'dine_in' ): array {
	$fulfillment_mode = tavox_menu_api_sanitize_fulfillment_mode(
		(string) ( $payload['fulfillment_mode'] ?? $payload['fulfillmentMode'] ?? '' ),
		$default_fulfillment_mode
	);

	$items = array_values(
		array_filter(
			array_map(
				static fn( $item ) => is_array( $item )
					? tavox_menu_api_normalize_request_payload_item( $item, $fulfillment_mode )
					: null,
				(array) ( $payload['items'] ?? [] )
			)
		)
	);

	return [
		'request_key'      => sanitize_key( (string) ( $payload['request_key'] ?? '' ) ),
		'table_token'      => sanitize_text_field( (string) ( $payload['table_token'] ?? '' ) ),
		'client_label'     => sanitize_text_field( (string) ( $payload['client_label'] ?? '' ) ),
		'brand_scope'      => tavox_menu_api_sanitize_menu_scope( (string) ( $payload['brand_scope'] ?? 'zona_b' ) ),
		'note'             => sanitize_textarea_field( (string) ( $payload['note'] ?? '' ) ),
		'fulfillment_mode' => $fulfillment_mode,
		'items'            => $items,
	];
}

/**
 * Normaliza una fila de solicitud para REST.
 *
 * @param array<string, mixed> $row Registro crudo desde la base de datos.
 * @return array<string, mixed>
 */
function tavox_menu_api_format_request_row( array $row ): array {
	$payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
	$payload = is_array( $payload ) ? $payload : [ 'items' => [] ];
	$payload = tavox_menu_api_normalize_request_payload(
		$payload,
		'takeaway' === sanitize_key( (string) ( $row['table_type'] ?? 'dine_in' ) ) ? 'takeaway' : 'dine_in'
	);

	return [
		'id'            => absint( $row['id'] ?? 0 ),
		'request_key'   => (string) ( $row['request_key'] ?? '' ),
		'table_key'     => (string) ( $row['table_key'] ?? '' ),
		'table_id'      => absint( $row['table_id'] ?? 0 ),
		'table_type'    => (string) ( $row['table_type'] ?? 'dine_in' ),
		'table_name'    => (string) ( $row['table_name'] ?? '' ),
		'register_id'   => absint( $row['register_id'] ?? 0 ),
		'warehouse_id'  => absint( $row['warehouse_id'] ?? 0 ),
		'request_source'=> (string) ( $row['request_source'] ?? 'customer' ),
		'client_label'  => (string) ( $row['client_label'] ?? '' ),
		'brand_scope'   => tavox_menu_api_sanitize_menu_scope( (string) ( $row['brand_scope'] ?? 'zona_b' ) ),
		'status'        => sanitize_key( (string) ( $row['status'] ?? 'pending' ) ),
		'waiter_user_id'=> absint( $row['waiter_user_id'] ?? 0 ),
		'waiter_name'   => function_exists( 'tavox_menu_api_resolve_waiter_staff_name' )
			? tavox_menu_api_resolve_waiter_staff_name(
				absint( $row['waiter_user_id'] ?? 0 ),
				(string) ( $row['waiter_name'] ?? '' )
			)
			: (string) ( $row['waiter_name'] ?? '' ),
		'global_note'   => (string) ( $row['global_note'] ?? '' ),
		'payload'       => $payload,
		'claimed_at'    => (string) ( $row['claimed_at'] ?? '' ),
		'accepted_at'   => (string) ( $row['accepted_at'] ?? '' ),
		'pushed_at'     => (string) ( $row['pushed_at'] ?? '' ),
		'expires_at'    => (string) ( $row['expires_at'] ?? '' ),
		'created_at'    => (string) ( $row['created_at'] ?? '' ),
		'updated_at'    => (string) ( $row['updated_at'] ?? '' ),
		'error_message' => (string) ( $row['error_message'] ?? '' ),
	];
}

/**
 * Busca una solicitud puntual por ID.
 *
 * @return array<string, mixed>|null
 */
function tavox_menu_api_get_request_row_by_id( int $request_id ) {
	global $wpdb;

	tavox_menu_api_cleanup_request_states();

	$request_id = absint( $request_id );
	if ( $request_id < 1 ) {
		return null;
	}

	$requests_table = tavox_menu_api_get_table_requests_table_name();
	$row            = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$requests_table} WHERE id = %d LIMIT 1",
			$request_id
		),
		ARRAY_A
	);

	return is_array( $row ) ? $row : null;
}

/**
 * Busca la última solicitud de una mesa filtrando por estados si aplica.
 *
 * @param int           $table_id    ID interno de mesa/takeaway.
 * @param string        $table_type  Tipo operativo.
 * @param string[]|null $statuses    Estados permitidos.
 * @return array<string, mixed>|null
 */
function tavox_menu_api_get_latest_table_request( int $table_id, string $table_type, ?array $statuses = null ) {
	global $wpdb;

	tavox_menu_api_cleanup_request_states();

	$requests_table = tavox_menu_api_get_table_requests_table_name();
	$table_type     = sanitize_key( $table_type );
	$table_id       = absint( $table_id );

	if ( $table_id < 1 ) {
		return null;
	}

	if ( is_array( $statuses ) && ! empty( $statuses ) ) {
		$statuses     = array_values( array_filter( array_map( 'sanitize_key', $statuses ) ) );
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$params       = array_merge( [ $table_id, $table_type ], $statuses );
		$sql          = $wpdb->prepare(
			"SELECT * FROM {$requests_table}
			WHERE table_id = %d AND table_type = %s AND status IN ({$placeholders})
			ORDER BY id DESC
			LIMIT 1",
			$params
		);
	} else {
		$sql = $wpdb->prepare(
			"SELECT * FROM {$requests_table}
			WHERE table_id = %d AND table_type = %s
			ORDER BY id DESC
			LIMIT 1",
			$table_id,
			$table_type
		);
	}

	$row = $wpdb->get_row( $sql, ARRAY_A );

	return is_array( $row ) ? $row : null;
}

/**
 * Busca la última solicitud abierta para una mesa.
 *
 * @return array<string, mixed>|null
 */
function tavox_menu_api_get_latest_open_table_request( int $table_id, string $table_type ) {
	return tavox_menu_api_get_latest_table_request( $table_id, $table_type, [ 'pending', 'claimed', 'error' ] );
}

/**
 * Devuelve la gracia de visibilidad del microchat cuando ya no hay cuenta activa.
 */
function tavox_menu_api_get_table_message_visibility_grace_seconds(): int {
	return 30 * MINUTE_IN_SECONDS;
}

/**
 * Devuelve una fecha MySQL desplazada hacia atrás en la zona horaria de WordPress.
 */
function tavox_menu_api_get_past_mysql_datetime( int $seconds_ago ): string {
	$seconds_ago = max( 0, $seconds_ago );
	return wp_date( 'Y-m-d H:i:s', time() - $seconds_ago, wp_timezone() );
}

/**
 * Intenta derivar el request activo más representativo del hilo actual.
 */
function tavox_menu_api_get_public_table_active_request_key( array $table_context ): string {
	$current_data = is_array( $table_context['current_data'] ?? null ) ? $table_context['current_data'] : [];
	$items        = is_array( $current_data['items'] ?? null ) ? $current_data['items'] : [];
	$request_keys = [];

	foreach ( $items as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}

		$request_meta = tavox_menu_api_get_openpos_item_request_meta( $item );
		$request_key  = sanitize_key( (string) ( $request_meta['request_key'] ?? '' ) );

		if ( '' !== $request_key ) {
			$request_keys[] = $request_key;
		}
	}

	$request_keys = array_values( array_unique( array_filter( $request_keys ) ) );
	if ( ! empty( $request_keys ) ) {
		return (string) end( $request_keys );
	}

	$table_type     = sanitize_key( (string) ( $table_context['table_type'] ?? 'dine_in' ) );
	$table_id       = absint( $table_context['table_id'] ?? 0 );
	$latest_request = tavox_menu_api_get_latest_open_table_request( $table_id, $table_type );
	$request_key    = sanitize_key( (string) ( is_array( $latest_request ) ? ( $latest_request['request_key'] ?? '' ) : '' ) );

	if ( '' !== $request_key ) {
		return $request_key;
	}

	if (
		$table_id > 0 &&
		function_exists( 'tavox_menu_api_openpos_data_has_activity' ) &&
		tavox_menu_api_openpos_data_has_activity( $current_data )
	) {
		$latest_request = tavox_menu_api_get_latest_table_request(
			$table_id,
			$table_type,
			[ 'pending', 'claimed', 'pushed', 'delivered', 'error' ]
		);
		$request_key    = sanitize_key( (string) ( is_array( $latest_request ) ? ( $latest_request['request_key'] ?? '' ) : '' ) );
		if ( '' !== $request_key ) {
			return $request_key;
		}
	}

	return '';
}

/**
 * Construye un token estable para una sesión viva de desk.
 */
function tavox_menu_api_get_public_table_live_session_token( array $table_context ): string {
	$table_type   = sanitize_key( (string) ( $table_context['table_type'] ?? 'dine_in' ) );
	$table_id     = absint( $table_context['table_id'] ?? 0 );
	$current_data = is_array( $table_context['current_data'] ?? null ) ? $table_context['current_data'] : [];
	$start_time   = max(
		(int) ( $current_data['start_time'] ?? 0 ),
		(int) ( $current_data['created_at_time'] ?? 0 ),
		(int) ( $current_data['system_ver'] ?? 0 ),
		(int) ( $current_data['online_ver'] ?? 0 ),
		(int) ( $current_data['ver'] ?? 0 )
	);

	if ( $start_time > 0 && $table_id > 0 ) {
		return sprintf( 'desk:%s:%d:%d', $table_type, $table_id, $start_time );
	}

	return '';
}

/**
 * Indica si el hilo de microchat debe considerarse actualmente activo.
 */
function tavox_menu_api_table_message_context_has_active_thread( array $table_context ): bool {
	$current_data = is_array( $table_context['current_data'] ?? null ) ? $table_context['current_data'] : [];
	if ( function_exists( 'tavox_menu_api_openpos_data_has_activity' ) && tavox_menu_api_openpos_data_has_activity( $current_data ) ) {
		return true;
	}

	$table_type = sanitize_key( (string) ( $table_context['table_type'] ?? 'dine_in' ) );
	$table_id   = absint( $table_context['table_id'] ?? 0 );

	return is_array( tavox_menu_api_get_latest_open_table_request( $table_id, $table_type ) );
}

/**
 * Devuelve una llave estable para el hilo operativo de una mesa.
 */
function tavox_menu_api_get_public_table_thread_token( array $table_context ): string {
	$table_type         = sanitize_key( (string) ( $table_context['table_type'] ?? 'dine_in' ) );
	$table_id           = absint( $table_context['table_id'] ?? 0 );
	$active_request_key = tavox_menu_api_get_public_table_active_request_key( $table_context );

	if ( '' !== $active_request_key ) {
		return 'request:' . $active_request_key;
	}

	$current_data = is_array( $table_context['current_data'] ?? null ) ? $table_context['current_data'] : [];
	if (
		function_exists( 'tavox_menu_api_openpos_data_has_activity' ) &&
		tavox_menu_api_openpos_data_has_activity( $current_data )
	) {
		$live_session_token = tavox_menu_api_get_public_table_live_session_token( $table_context );
		if ( '' !== $live_session_token ) {
			return $live_session_token;
		}
	}

	$key = sanitize_text_field( (string) ( $table_context['key'] ?? '' ) );
	if ( '' !== $key ) {
		return 'idle:' . sanitize_key( $key );
	}

	return sprintf( 'account:%s:%d', $table_type, $table_id );
}

/**
 * Devuelve patrones legacy para seguir operando hilos viejos con token volátil.
 *
 * @return array<int, string>
 */
function tavox_menu_api_get_public_table_legacy_thread_tokens( array $table_context ): array {
	$table_type = sanitize_key( (string) ( $table_context['table_type'] ?? 'dine_in' ) );
	$table_id   = absint( $table_context['table_id'] ?? 0 );
	$tokens     = [];

	if ( $table_id > 0 ) {
		$tokens[] = sprintf( 'desk:%s:%d:', $table_type, $table_id );
	}

	$current_data = is_array( $table_context['current_data'] ?? null ) ? $table_context['current_data'] : [];
	$start_time   = max(
		(int) ( $current_data['start_time'] ?? 0 ),
		(int) ( $current_data['created_at_time'] ?? 0 ),
		(int) ( $current_data['system_ver'] ?? 0 )
	);

	if ( $start_time > 0 && $table_id > 0 ) {
		$tokens[] = sprintf( 'desk:%s:%d:%d', $table_type, $table_id, $start_time );
	}

	$key = sanitize_text_field( (string) ( $table_context['key'] ?? '' ) );
	if ( '' !== $key ) {
		$tokens[] = 'idle:' . $key;
	}

	return array_values( array_unique( array_filter( $tokens ) ) );
}

/**
 * Busca el último hilo reciente visible de una mesa.
 *
 * @return array<string, mixed>|null
 */
function tavox_menu_api_get_recent_table_message_anchor( array $table_context, int $grace_seconds = 0 ) {
	global $wpdb;

	$table         = tavox_menu_api_get_table_messages_table_name();
	$table_id      = absint( $table_context['table_id'] ?? 0 );
	$table_type    = sanitize_key( (string) ( $table_context['table_type'] ?? 'dine_in' ) );
	$grace_seconds = $grace_seconds > 0 ? $grace_seconds : tavox_menu_api_get_table_message_visibility_grace_seconds();
	$cutoff_mysql  = tavox_menu_api_get_past_mysql_datetime( $grace_seconds );
	$activity_sql  = 'COALESCE(resolved_at, read_at, created_at)';

	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, table_session_token, request_key, {$activity_sql} AS activity_at
			FROM {$table}
			WHERE table_id = %d
				AND table_type = %s
				AND {$activity_sql} >= %s
			ORDER BY {$activity_sql} DESC, id DESC
			LIMIT 1",
			$table_id,
			$table_type,
			$cutoff_mysql
		),
		ARRAY_A
	);

	return is_array( $row ) ? $row : null;
}

/**
 * Determina el alcance SQL del hilo visible actual o reciente.
 *
 * @return array{where_sql:string,params:array<int,mixed>,thread_token:string,request_key:string,uses_recent_fallback:bool}
 */
function tavox_menu_api_get_table_message_scope( array $table_context, bool $allow_recent_fallback = true ): array {
	global $wpdb;

	$active_thread = tavox_menu_api_table_message_context_has_active_thread( $table_context );

	if ( $active_thread ) {
		$thread_token  = tavox_menu_api_get_public_table_thread_token( $table_context );
		$legacy_tokens = tavox_menu_api_get_public_table_legacy_thread_tokens( $table_context );
		$where_parts   = [ 'table_session_token = %s' ];
		$params        = [ $thread_token ];

		foreach ( $legacy_tokens as $legacy_token ) {
			if ( str_ends_with( $legacy_token, ':' ) ) {
				$where_parts[] = 'table_session_token LIKE %s';
				$params[]      = $wpdb->esc_like( $legacy_token ) . '%';
				continue;
			}

			$where_parts[] = 'table_session_token = %s';
			$params[]      = $legacy_token;
		}

		return [
			'where_sql'             => '(' . implode( ' OR ', $where_parts ) . ')',
			'params'                => $params,
			'thread_token'          => $thread_token,
			'request_key'           => tavox_menu_api_get_public_table_active_request_key( $table_context ),
			'uses_recent_fallback'  => false,
		];
	}

	if ( ! $allow_recent_fallback ) {
		return [
			'where_sql'            => '1 = 0',
			'params'               => [],
			'thread_token'         => '',
			'request_key'          => '',
			'uses_recent_fallback' => false,
		];
	}

	$grace_seconds = tavox_menu_api_get_table_message_visibility_grace_seconds();
	$cutoff_mysql  = tavox_menu_api_get_past_mysql_datetime( $grace_seconds );
	$anchor        = tavox_menu_api_get_recent_table_message_anchor( $table_context, $grace_seconds );

	if ( ! is_array( $anchor ) ) {
		return [
			'where_sql'            => '1 = 0',
			'params'               => [],
			'thread_token'         => '',
			'request_key'          => '',
			'uses_recent_fallback' => true,
		];
	}

	$thread_token = sanitize_text_field( (string) ( $anchor['table_session_token'] ?? '' ) );
	$request_key  = sanitize_key( (string) ( $anchor['request_key'] ?? '' ) );

	if ( '' !== $request_key ) {
		$where_sql = 'request_key = %s';
		$params    = [ $request_key ];

		if ( str_starts_with( $thread_token, 'request:' ) || str_starts_with( $thread_token, 'desk:' ) ) {
			$where_sql = '(request_key = %s OR table_session_token = %s)';
			$params[]  = $thread_token;
		}

		return [
			'where_sql'            => $where_sql,
			'params'               => $params,
			'thread_token'         => $thread_token,
			'request_key'          => $request_key,
			'uses_recent_fallback' => true,
		];
	}

	$requires_cutoff = str_starts_with( $thread_token, 'idle:' ) || str_starts_with( $thread_token, 'account:' );
	$where_sql       = 'table_session_token = %s';
	$params          = [ $thread_token ];

	if ( $requires_cutoff ) {
		$where_sql .= ' AND COALESCE(resolved_at, read_at, created_at) >= %s';
		$params[]   = $cutoff_mysql;
	}

	return [
		'where_sql'            => $where_sql,
		'params'               => $params,
		'thread_token'         => $thread_token,
		'request_key'          => '',
		'uses_recent_fallback' => true,
	];
}

/**
 * Construye un alcance exacto a partir de un mensaje concreto del hilo.
 *
 * @return array{where_sql:string,params:array<int,mixed>,thread_token:string,request_key:string,uses_recent_fallback:bool}|null
 */
function tavox_menu_api_get_table_message_scope_from_message_id( array $table_context, int $message_id ) {
	global $wpdb;

	$message_id = absint( $message_id );
	if ( $message_id < 1 ) {
		return null;
	}

	$table = tavox_menu_api_get_table_messages_table_name();
	$row   = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, table_session_token, request_key
			FROM {$table}
			WHERE id = %d
				AND table_id = %d
				AND table_type = %s
			LIMIT 1",
			$message_id,
			absint( $table_context['table_id'] ?? 0 ),
			sanitize_key( (string) ( $table_context['table_type'] ?? 'dine_in' ) )
		),
		ARRAY_A
	);

	if ( ! is_array( $row ) ) {
		return null;
	}

	$thread_token = sanitize_text_field( (string) ( $row['table_session_token'] ?? '' ) );
	$request_key  = sanitize_key( (string) ( $row['request_key'] ?? '' ) );

	if ( '' !== $request_key ) {
		$where_sql = 'request_key = %s';
		$params    = [ $request_key ];

		if ( str_starts_with( $thread_token, 'request:' ) || str_starts_with( $thread_token, 'desk:' ) ) {
			$where_sql = '(request_key = %s OR table_session_token = %s)';
			$params[]  = $thread_token;
		}

		return [
			'where_sql'            => $where_sql,
			'params'               => $params,
			'thread_token'         => $thread_token,
			'request_key'          => $request_key,
			'uses_recent_fallback' => false,
		];
	}

	if ( '' === $thread_token ) {
		return null;
	}

	return [
		'where_sql'            => 'table_session_token = %s',
		'params'               => [ $thread_token ],
		'thread_token'         => $thread_token,
		'request_key'          => '',
		'uses_recent_fallback' => false,
	];
}

/**
 * Construye el resumen operativo público de una mesa sin duplicar la lógica del staff.
 *
 * @return array<string, mixed>
 */
function tavox_menu_api_build_public_table_operational_summary( array $table_context ): array {
	$table_id       = absint( $table_context['table_id'] ?? 0 );
	$table_type     = (string) ( $table_context['table_type'] ?? 'dine_in' );
	$current_raw    = tavox_menu_api_get_latest_open_table_request( $table_id, $table_type );
	$latest_raw     = tavox_menu_api_get_latest_table_request( $table_id, $table_type, [ 'pending', 'claimed', 'pushed', 'delivered', 'error' ] );
	$current_request = is_array( $current_raw ) ? tavox_menu_api_format_request_row( $current_raw ) : null;
	$latest_request  = is_array( $latest_raw ) ? tavox_menu_api_format_request_row( $latest_raw ) : null;
	$pickup_summary  = tavox_menu_api_build_openpos_pickup_summary( (array) ( $table_context['current_data'] ?? [] ) );
	$consumption     = tavox_menu_api_build_table_consumption_summary( $table_context );
	if ( function_exists( 'tavox_menu_api_should_ignore_shadowed_open_request' ) && tavox_menu_api_should_ignore_shadowed_open_request( $current_request, $latest_request, $consumption ) ) {
		$current_request = null;
	}
	$surface_latest  = function_exists( 'tavox_menu_api_should_surface_latest_request' )
		? tavox_menu_api_should_surface_latest_request( $latest_request, $consumption )
		: false;
	$service_stage   = function_exists( 'tavox_menu_api_resolve_waiter_service_stage' )
		? tavox_menu_api_resolve_waiter_service_stage( $current_request, $latest_request, $consumption )
		: [
			'service_stage'      => '',
			'service_label'      => '',
			'service_note'       => '',
			'can_mark_delivered' => false,
		];
	$service_counts  = function_exists( 'tavox_menu_api_get_waiter_service_counts' )
		? tavox_menu_api_get_waiter_service_counts( $consumption )
		: [
			'pending_count'   => 0,
			'ready_count'     => 0,
			'delivered_count' => 0,
			'ready_mode'      => 'none',
		];
	$customer_display_name = function_exists( 'tavox_menu_api_get_waiter_customer_label' )
		? tavox_menu_api_get_waiter_customer_label( $consumption, $latest_request, $pickup_summary )
		: sanitize_text_field( (string) ( $consumption['customer']['display_name'] ?? $consumption['customer']['name'] ?? '' ) );
	$customer_secondary_label = function_exists( 'tavox_menu_api_get_waiter_customer_secondary_label' )
		? tavox_menu_api_get_waiter_customer_secondary_label( $consumption, $pickup_summary )
		: sanitize_text_field( (string) ( $consumption['customer']['secondary_label'] ?? $consumption['customer']['email'] ?? '' ) );
	$shared_staff_display_names = function_exists( 'tavox_menu_api_get_waiter_shared_staff_display_names' )
		? tavox_menu_api_get_waiter_shared_staff_display_names( $current_request, $latest_request, $consumption )
		: [];
	$operability = function_exists( 'tavox_menu_api_resolve_waiter_table_operability' )
		? tavox_menu_api_resolve_waiter_table_operability( true, $current_request, $latest_request, $consumption, null )
		: [
			'availability'        => 'available',
			'availability_reason' => '',
			'managed_by'          => '',
			'can_direct_order'    => true,
		];
	$owner_display_name = sanitize_text_field( (string) ( $operability['managed_by'] ?? '' ) );

	if ( '' === $owner_display_name && ! empty( $shared_staff_display_names[0] ) ) {
		$owner_display_name = sanitize_text_field( (string) $shared_staff_display_names[0] );
	}

	$has_open_request = in_array( sanitize_key( (string) ( $current_request['status'] ?? '' ) ), [ 'pending', 'claimed', 'error' ], true );
	$has_visible_consumption =
		absint( $consumption['lines_count'] ?? 0 ) > 0 ||
		absint( $consumption['items_count'] ?? 0 ) > 0 ||
		(float) ( $consumption['total_amount'] ?? 0 ) > 0 ||
		absint( $consumption['ready_lines'] ?? 0 ) > 0 ||
		absint( $consumption['pending_lines'] ?? 0 ) > 0 ||
		absint( $consumption['preparing_lines'] ?? 0 ) > 0 ||
		absint( $consumption['delivered_lines'] ?? 0 ) > 0;

	if ( ! $has_visible_consumption && ! $has_open_request && ! $surface_latest ) {
		$latest_request              = null;
		$owner_display_name          = '';
		$customer_display_name       = '';
		$customer_secondary_label    = '';
		$shared_staff_display_names  = [];
	}

	$is_shared = tavox_menu_api_are_shared_tables_enabled() && count( $shared_staff_display_names ) > 1;

	return [
		'current_request'           => $current_request,
		'latest_request'            => $latest_request,
		'pickup'                    => $pickup_summary,
		'consumption'               => $consumption,
		'service_stage'             => (string) ( $service_stage['service_stage'] ?? '' ),
		'service_label'             => (string) ( $service_stage['service_label'] ?? '' ),
		'service_note'              => (string) ( $service_stage['service_note'] ?? '' ),
		'service_counts'            => $service_counts,
		'owner_display_name'        => $owner_display_name,
		'customer_display_name'     => $customer_display_name,
		'customer_secondary_label'  => $customer_secondary_label,
		'shared_staff_display_names'=> $shared_staff_display_names,
		'shared_mode'               => $is_shared,
		'is_shared'                 => $is_shared,
	];
}

/**
 * Normaliza una fila del microchat de mesa.
 *
 * @param array<string, mixed> $row Registro crudo.
 * @return array<string, mixed>
 */
function tavox_menu_api_format_table_message_row( array $row ): array {
	$status = sanitize_key( (string) ( $row['status'] ?? 'open' ) );

	return [
		'id'                 => absint( $row['id'] ?? 0 ),
		'table_id'           => absint( $row['table_id'] ?? 0 ),
		'table_type'         => sanitize_key( (string) ( $row['table_type'] ?? 'dine_in' ) ),
		'table_session_token'=> (string) ( $row['table_session_token'] ?? '' ),
		'request_key'        => (string) ( $row['request_key'] ?? '' ),
		'sender_role'        => sanitize_key( (string) ( $row['sender_role'] ?? 'customer' ) ),
		'sender_user_id'     => absint( $row['sender_user_id'] ?? 0 ),
		'sender_label'       => sanitize_text_field( (string) ( $row['sender_label'] ?? '' ) ),
		'message_type'       => sanitize_key( (string) ( $row['message_type'] ?? 'free_text' ) ),
		'message_text'       => sanitize_textarea_field( (string) ( $row['message_text'] ?? '' ) ),
		'status'             => $status,
		'is_pending'         => 'open' === $status && empty( $row['resolved_at'] ),
		'is_read'            => ! empty( $row['read_at'] ),
		'is_resolved'        => ! empty( $row['resolved_at'] ),
		'created_at'         => (string) ( $row['created_at'] ?? '' ),
		'read_at'            => (string) ( $row['read_at'] ?? '' ),
		'resolved_at'        => (string) ( $row['resolved_at'] ?? '' ),
	];
}

/**
 * Devuelve el hilo vigente de una mesa.
 *
 * @return array<string, mixed>
 */
function tavox_menu_api_list_table_messages( array $table_context, int $limit = 40 ): array {
	global $wpdb;

	$table      = tavox_menu_api_get_table_messages_table_name();
	$table_id   = absint( $table_context['table_id'] ?? 0 );
	$table_type = sanitize_key( (string) ( $table_context['table_type'] ?? 'dine_in' ) );
	$limit      = max( 10, min( 80, $limit ) );
	$scope      = tavox_menu_api_get_table_message_scope( $table_context, true );

	if ( '1 = 0' === $scope['where_sql'] ) {
		return [
			'items'         => [],
			'pending_count' => 0,
			'active_count'  => 0,
		];
	}

	$params   = array_merge( [ $table_id, $table_type ], (array) $scope['params'], [ $limit ] );
	$sql = $wpdb->prepare(
		"SELECT * FROM {$table}
		WHERE table_id = %d
			AND table_type = %s
			AND {$scope['where_sql']}
		ORDER BY id DESC
		LIMIT %d",
		$params
	);
	$rows = $wpdb->get_results( $sql, ARRAY_A );
	$rows        = is_array( $rows ) ? array_reverse( $rows ) : [];
	$items       = array_map( 'tavox_menu_api_format_table_message_row', $rows );
	$pending_count = count(
		array_filter(
			$items,
			static fn( array $item ): bool => 'customer' === (string) ( $item['sender_role'] ?? '' ) && 'open' === (string) ( $item['status'] ?? '' )
		)
	);

	return [
		'items'         => $items,
		'pending_count' => $pending_count,
		'active_count'  => count( $items ),
	];
}

/**
 * Guarda un mensaje en el hilo vigente de la mesa.
 *
 * @param array<string, mixed> $args
 * @return array<string, mixed>|WP_Error
 */
function tavox_menu_api_insert_table_message( array $table_context, array $args ) {
	global $wpdb;

	$message_text = sanitize_textarea_field( (string) ( $args['message_text'] ?? '' ) );
	if ( '' === $message_text ) {
		return new WP_Error( 'empty_message', __( 'Escribe un mensaje antes de enviarlo.', 'tavox-menu-api' ), [ 'status' => 400 ] );
	}

	$thread_token  = sanitize_text_field( (string) ( $args['thread_token'] ?? '' ) );
	if ( '' === $thread_token ) {
		$thread_token = tavox_menu_api_get_public_table_thread_token( $table_context );
	}
	$table_id      = absint( $table_context['table_id'] ?? 0 );
	$table_type    = sanitize_key( (string) ( $table_context['table_type'] ?? 'dine_in' ) );
	$latest_raw    = tavox_menu_api_get_latest_table_request( $table_id, $table_type, [ 'pending', 'claimed', 'pushed', 'delivered', 'error' ] );
	$request_key   = sanitize_key( (string) ( $args['request_key'] ?? ( is_array( $latest_raw ) ? (string) ( $latest_raw['request_key'] ?? '' ) : '' ) ) );
	$sender_role   = sanitize_key( (string) ( $args['sender_role'] ?? 'customer' ) );
	$sender_user_id= absint( $args['sender_user_id'] ?? 0 );
	$sender_label  = sanitize_text_field( (string) ( $args['sender_label'] ?? '' ) );
	$message_type  = sanitize_key( (string) ( $args['message_type'] ?? 'free_text' ) );
	$status        = sanitize_key( (string) ( $args['status'] ?? 'open' ) );
	$now_mysql     = tavox_menu_api_now_mysql();
	$table         = tavox_menu_api_get_table_messages_table_name();

	$inserted = $wpdb->insert(
		$table,
		[
			'table_id'            => $table_id,
			'table_type'          => $table_type,
			'table_session_token' => $thread_token,
			'request_key'         => $request_key,
			'sender_role'         => $sender_role,
			'sender_user_id'      => $sender_user_id,
			'sender_label'        => $sender_label,
			'message_type'        => $message_type,
			'message_text'        => $message_text,
			'status'              => $status,
			'created_at'          => $now_mysql,
			'read_at'             => 'open' === $status ? null : $now_mysql,
			'resolved_at'         => 'resolved' === $status ? $now_mysql : null,
		],
		[ '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
	);

	if ( false === $inserted ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[Tavox Menu API] insert table message failed: ' . $wpdb->last_error );
		}

		return new WP_Error( 'table_message_insert_failed', __( 'No pudimos enviar este mensaje en este momento.', 'tavox-menu-api' ), [ 'status' => 500 ] );
	}

	$row = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", absint( $wpdb->insert_id ) ),
		ARRAY_A
	);

	return tavox_menu_api_format_table_message_row( is_array( $row ) ? $row : [] );
}

/**
 * Marca como leído el hilo vigente de la mesa para el equipo.
 */
function tavox_menu_api_mark_table_messages_read( array $table_context ): int {
	global $wpdb;

	$table      = tavox_menu_api_get_table_messages_table_name();
	$table_id   = absint( $table_context['table_id'] ?? 0 );
	$table_type = sanitize_key( (string) ( $table_context['table_type'] ?? 'dine_in' ) );
	$scope      = tavox_menu_api_get_table_message_scope( $table_context, true );

	if ( '1 = 0' === $scope['where_sql'] ) {
		return 0;
	}

	$params = array_merge(
		[ tavox_menu_api_now_mysql(), $table_id, $table_type ],
		(array) $scope['params']
	);

	$sql = $wpdb->prepare(
		"UPDATE {$table}
		SET status = 'read', read_at = %s
		WHERE table_id = %d
			AND table_type = %s
			AND {$scope['where_sql']}
			AND sender_role = 'customer'
			AND resolved_at IS NULL
			AND read_at IS NULL",
		$params
	);
	$updated = $wpdb->query( $sql );

	return false === $updated ? 0 : (int) $updated;
}

/**
 * Resuelve el hilo vigente de la mesa.
 */
function tavox_menu_api_resolve_table_messages( array $table_context, ?array $scope_override = null ): int {
	global $wpdb;

	$table      = tavox_menu_api_get_table_messages_table_name();
	$table_id   = absint( $table_context['table_id'] ?? 0 );
	$table_type = sanitize_key( (string) ( $table_context['table_type'] ?? 'dine_in' ) );
	$scope      = is_array( $scope_override ) && ! empty( $scope_override['where_sql'] )
		? $scope_override
		: tavox_menu_api_get_table_message_scope( $table_context, true );

	if ( '1 = 0' === $scope['where_sql'] ) {
		return 0;
	}

	$params = array_merge(
		[ tavox_menu_api_now_mysql(), $table_id, $table_type ],
		(array) $scope['params']
	);

	$sql = $wpdb->prepare(
		"UPDATE {$table}
		SET status = 'resolved', resolved_at = %s
		WHERE table_id = %d
			AND table_type = %s
			AND {$scope['where_sql']}
			AND resolved_at IS NULL",
		$params
	);
	$updated = $wpdb->query( $sql );

	return false === $updated ? 0 : (int) $updated;
}

/**
 * Construye un resumen liviano para sincronización pública por SSE.
 *
 * @return array<string, mixed>
 */
function tavox_menu_api_get_public_table_live_state( array $table_context ): array {
	$payload   = tavox_menu_api_build_public_table_context_payload( $table_context );
	$messages  = tavox_menu_api_list_table_messages( $table_context, 20 );
	$items     = [];

	foreach ( (array) ( $payload['consumption']['items'] ?? [] ) as $item ) {
		$items[] = [
			'id'      => sanitize_text_field( (string) ( $item['id'] ?? '' ) ),
			'qty'     => (float) ( $item['qty'] ?? 0 ),
			'total'   => (float) ( $item['total'] ?? 0 ),
			'state'   => sanitize_key( (string) ( $item['service_state'] ?? '' ) ),
			'version' => (int) ( $item['order_time'] ?? 0 ),
		];
	}

	$message_state = array_map(
		static fn( array $item ): array => [
			'id'         => absint( $item['id'] ?? 0 ),
			'role'       => sanitize_key( (string) ( $item['sender_role'] ?? '' ) ),
			'status'     => sanitize_key( (string) ( $item['status'] ?? '' ) ),
			'created_at' => (string) ( $item['created_at'] ?? '' ),
		],
		(array) ( $messages['items'] ?? [] )
	);

	$state = [
		'table'       => [
			'id'          => absint( $payload['table']['id'] ?? 0 ),
			'type'        => sanitize_key( (string) ( $payload['table']['type'] ?? 'dine_in' ) ),
			'desk_version'=> (int) ( $payload['consumption']['desk_version'] ?? 0 ),
			'desk_updated'=> (int) ( $payload['consumption']['desk_updated_at'] ?? 0 ),
		],
		'service'     => [
			'stage' => sanitize_key( (string) ( $payload['service_stage'] ?? '' ) ),
			'label' => sanitize_text_field( (string) ( $payload['service_label'] ?? '' ) ),
			'counts'=> (array) ( $payload['service_counts'] ?? [] ),
		],
		'consumption' => [
			'lines_count' => absint( $payload['consumption']['lines_count'] ?? 0 ),
			'items_count' => absint( $payload['consumption']['items_count'] ?? 0 ),
			'total'       => (float) ( $payload['consumption']['total_amount'] ?? 0 ),
			'items'       => $items,
		],
		'messages'    => $message_state,
		'server_now'  => time(),
	];
	$state['hash'] = md5( (string) wp_json_encode( $state ) );

	return $state;
}

/**
 * Emite un evento SSE público de mesa.
 *
 * @param array<string, mixed> $payload
 */
function tavox_menu_api_emit_table_live_event( string $event, array $payload ): void {
	echo 'event: ' . $event . "\n";
	echo 'data: ' . wp_json_encode( $payload ) . "\n\n";

	@ob_flush();
	@flush();
}

/**
 * Arma el payload público de contexto de mesa.
 *
 * @param array<string, mixed> $table_context Contexto OpenPOS.
 * @return array<string, mixed>
 */
function tavox_menu_api_build_public_table_context_payload( array $table_context ): array {
	$settings       = tavox_menu_api_get_settings();
	$operational    = tavox_menu_api_build_public_table_operational_summary( $table_context );

	return [
		'table_token'  => tavox_menu_api_build_table_token( $table_context ),
		'table'        => [
			'id'         => absint( $table_context['table_id'] ?? 0 ),
			'key'        => (string) ( $table_context['key'] ?? '' ),
			'name'       => (string) ( $table_context['table_name'] ?? '' ),
			'type'       => (string) ( $table_context['table_type'] ?? 'dine_in' ),
			'desk_ref'   => (string) ( $table_context['desk_ref'] ?? '' ),
			'register_id'=> absint( $table_context['register_id'] ?? 0 ),
			'warehouse_id'=> absint( $table_context['warehouse_id'] ?? 0 ),
		],
		'consumption'  => (array) ( $operational['consumption'] ?? [] ),
		'features'     => [
			'table_order_enabled'    => ! empty( $settings['table_order_enabled'] ),
			'waiter_console_enabled' => ! empty( $settings['waiter_console_enabled'] ),
		],
		'wifi'         => [
			'label'    => (string) ( $settings['wifi_label'] ?? 'Wi‑Fi' ),
			'name'     => (string) ( $settings['wifi_name'] ?? '' ),
			'password' => (string) ( $settings['wifi_password'] ?? '' ),
		],
		'pickup'                    => (array) ( $operational['pickup'] ?? [] ),
		'current_request'           => $operational['current_request'] ?? null,
		'latest_request'            => $operational['latest_request'] ?? null,
		'service_stage'             => (string) ( $operational['service_stage'] ?? '' ),
		'service_label'             => (string) ( $operational['service_label'] ?? '' ),
		'service_note'              => (string) ( $operational['service_note'] ?? '' ),
		'service_counts'            => (array) ( $operational['service_counts'] ?? [] ),
		'owner_display_name'        => (string) ( $operational['owner_display_name'] ?? '' ),
		'customer_display_name'     => (string) ( $operational['customer_display_name'] ?? '' ),
		'customer_secondary_label'  => (string) ( $operational['customer_secondary_label'] ?? '' ),
		'shared_staff_display_names'=> (array) ( $operational['shared_staff_display_names'] ?? [] ),
		'shared_mode'               => ! empty( $operational['shared_mode'] ),
		'is_shared'                 => ! empty( $operational['is_shared'] ),
	];
}

/**
 * Devuelve a qué personas del equipo debe avisarse sobre una cuenta ya tomada.
 *
 * @return int[]
 */
function tavox_menu_api_get_waiter_owner_user_ids_for_table_context( array $table_context ): array {
	if ( tavox_menu_api_are_shared_tables_enabled() ) {
		return [];
	}

	$table_id   = absint( $table_context['table_id'] ?? 0 );
	$table_type = (string) ( $table_context['table_type'] ?? 'dine_in' );

	$current_request = tavox_menu_api_get_latest_open_table_request( $table_id, $table_type );
	if ( is_array( $current_request ) && absint( $current_request['waiter_user_id'] ?? 0 ) > 0 ) {
		return [ absint( $current_request['waiter_user_id'] ) ];
	}

	$consumption = tavox_menu_api_build_table_consumption_summary( $table_context );
	$seller_id   = absint( $consumption['seller']['id'] ?? 0 );
	if ( $seller_id > 0 ) {
		return [ $seller_id ];
	}

	$latest_request = tavox_menu_api_get_latest_table_request( $table_id, $table_type, [ 'pushed', 'delivered' ] );
	if ( is_array( $latest_request ) && absint( $latest_request['waiter_user_id'] ?? 0 ) > 0 ) {
		return [ absint( $latest_request['waiter_user_id'] ) ];
	}

	return [];
}

/**
 * Inserta una nueva solicitud pendiente.
 *
 * @param array<string, mixed> $table_context Contexto validado.
 * @param array<string, mixed> $payload       Payload del carrito.
 * @param string               $request_key   Llave de idempotencia opcional.
 * @param string               $source        customer|waiter
 * @param int                  $waiter_user_id Usuario del mesero cuando aplique.
 * @param string               $waiter_name   Nombre del mesero cuando aplique.
 * @return array<string, mixed>|WP_Error
 */
function tavox_menu_api_insert_table_request( array $table_context, array $payload, string $request_key = '', string $source = 'customer', int $waiter_user_id = 0, string $waiter_name = '' ) {
	global $wpdb;

	$payload = tavox_menu_api_normalize_request_payload(
		$payload,
		'takeaway' === sanitize_key( (string) ( $table_context['table_type'] ?? 'dine_in' ) ) ? 'takeaway' : 'dine_in'
	);

	$items = is_array( $payload['items'] ?? null ) ? $payload['items'] : [];
	if ( empty( $items ) ) {
		return new WP_Error( 'empty_request', __( 'Agrega productos antes de procesar la solicitud.', 'tavox-menu-api' ), [ 'status' => 400 ] );
	}

	tavox_menu_api_cleanup_request_states();

	$requests_table   = tavox_menu_api_get_table_requests_table_name();
	$request_key      = sanitize_key( $request_key );
	$now_mysql        = tavox_menu_api_now_mysql();
	$expires_at_mysql = gmdate( 'Y-m-d H:i:s', time() + ( (int) tavox_menu_api_get_settings()['request_hold_minutes'] * MINUTE_IN_SECONDS ) );
	$brand_scope      = tavox_menu_api_sanitize_menu_scope( (string) ( $payload['brand_scope'] ?? 'zona_b' ) );

	if ( '' !== $request_key ) {
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$requests_table}
				WHERE request_key = %s AND table_id = %d AND table_type = %s
				LIMIT 1",
				$request_key,
				absint( $table_context['table_id'] ?? 0 ),
				(string) ( $table_context['table_type'] ?? 'dine_in' )
			),
			ARRAY_A
		);

		if ( is_array( $existing ) ) {
			return tavox_menu_api_format_request_row( $existing );
		}
	}

	$inserted = $wpdb->insert(
		$requests_table,
		[
			'request_key'    => $request_key,
			'table_key'      => (string) ( $table_context['key'] ?? '' ),
			'table_id'       => absint( $table_context['table_id'] ?? 0 ),
			'table_type'     => (string) ( $table_context['table_type'] ?? 'dine_in' ),
			'table_name'     => (string) ( $table_context['table_name'] ?? '' ),
			'register_id'    => absint( $table_context['register_id'] ?? 0 ),
			'warehouse_id'   => absint( $table_context['warehouse_id'] ?? 0 ),
			'request_source' => sanitize_key( $source ),
			'session_token'  => (string) ( $payload['table_token'] ?? '' ),
			'client_label'   => sanitize_text_field( (string) ( $payload['client_label'] ?? '' ) ),
			'waiter_user_id' => $waiter_user_id,
			'waiter_name'    => sanitize_text_field( $waiter_name ),
			'brand_scope'    => $brand_scope,
			'status'         => 'pending',
			'payload'        => wp_json_encode( $payload ),
			'global_note'    => sanitize_textarea_field( (string) ( $payload['note'] ?? '' ) ),
			'expires_at'     => $expires_at_mysql,
			'created_at'     => $now_mysql,
			'updated_at'     => $now_mysql,
		],
		[
			'%s',
			'%s',
			'%d',
			'%s',
			'%s',
			'%d',
			'%d',
			'%s',
			'%s',
			'%s',
			'%d',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
		]
	);

	if ( false === $inserted ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[Tavox Menu API] insert table request failed: ' . $wpdb->last_error );
		}

		return new WP_Error( 'insert_failed', __( 'No se pudo registrar la solicitud de mesa.', 'tavox-menu-api' ), [ 'status' => 500 ] );
	}

	$row = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$requests_table} WHERE id = %d", absint( $wpdb->insert_id ) ),
		ARRAY_A
	);

	return tavox_menu_api_format_request_row( is_array( $row ) ? $row : [] );
}

/**
 * Registra rutas REST de mesa.
 */
function tavox_menu_api_register_table_routes(): void {
	register_rest_route(
		'tavox/v1',
		'/table/session',
		[
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'tavox_menu_api_rest_table_session',
				'permission_callback' => '__return_true',
			],
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'tavox_menu_api_rest_table_session',
				'permission_callback' => '__return_true',
			],
		]
	);

	register_rest_route(
		'tavox/v1',
		'/table/context',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'tavox_menu_api_rest_table_context',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'tavox/v1',
		'/table/request',
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'tavox_menu_api_rest_table_request',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'tavox/v1',
		'/table/messages',
		[
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'tavox_menu_api_rest_table_messages',
				'permission_callback' => '__return_true',
			],
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'tavox_menu_api_rest_table_messages_create',
				'permission_callback' => '__return_true',
			],
		]
	);

	register_rest_route(
		'tavox/v1',
		'/table/live',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'tavox_menu_api_rest_table_live',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'tavox/v1',
		'/waiter/table-message/reply',
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'tavox_menu_api_rest_waiter_table_message_reply',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'tavox/v1',
		'/waiter/table-message/read',
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'tavox_menu_api_rest_waiter_table_message_read',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'tavox/v1',
		'/waiter/table-message/resolve',
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'tavox_menu_api_rest_waiter_table_message_resolve',
			'permission_callback' => '__return_true',
		]
	);
}
add_action( 'rest_api_init', 'tavox_menu_api_register_table_routes' );

/**
 * Crea/lee una sesión de mesa desde key QR/NFC.
 *
 * @return WP_REST_Response|WP_Error
 */
function tavox_menu_api_rest_table_session( WP_REST_Request $request ) {
	try {
		$key = (string) $request->get_param( 'key' );

		$table_context = tavox_menu_api_get_openpos_table_context_by_key( $key );
		if ( is_wp_error( $table_context ) ) {
			return $table_context;
		}

		$response = tavox_menu_api_build_public_table_context_payload( $table_context );
		$response['entry_url'] = tavox_menu_api_get_table_entry_redirect_url( $table_context );

		return tavox_menu_api_no_store_rest_response( $response );
	} catch ( Throwable $error ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[Tavox Menu API] table session endpoint error: ' . $error->getMessage() . ' @ ' . $error->getFile() . ':' . $error->getLine() );
		}

		return new WP_Error(
			'table_session_failed',
			__( 'No se pudo crear la sesión de mesa en este momento.', 'tavox-menu-api' ),
			[ 'status' => 500 ]
		);
	}
}

/**
 * Devuelve contexto fresco de mesa usando un token firmado.
 *
 * @return WP_REST_Response|WP_Error
 */
function tavox_menu_api_rest_table_context( WP_REST_Request $request ) {
	try {
		$table_token = (string) $request->get_param( 'table_token' );

		$table_context = tavox_menu_api_get_openpos_table_context_from_token( $table_token );
		if ( is_wp_error( $table_context ) ) {
			return $table_context;
		}

		return tavox_menu_api_no_store_rest_response( tavox_menu_api_build_public_table_context_payload( $table_context ) );
	} catch ( Throwable $error ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[Tavox Menu API] table context endpoint error: ' . $error->getMessage() . ' @ ' . $error->getFile() . ':' . $error->getLine() );
		}

		return new WP_Error(
			'table_context_endpoint_failed',
			__( 'No se pudo leer la sesión de mesa en este momento.', 'tavox-menu-api' ),
			[ 'status' => 500 ]
		);
	}
}

/**
 * Crea una solicitud pendiente desde el carrito del cliente en mesa.
 *
 * @return WP_REST_Response|WP_Error
 */
function tavox_menu_api_rest_table_request( WP_REST_Request $request ) {
	try {
		$table_token = (string) $request->get_param( 'table_token' );

		$table_context = tavox_menu_api_get_openpos_table_context_from_token( $table_token );
		if ( is_wp_error( $table_context ) ) {
			return $table_context;
		}

		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : [];
		$payload['table_token'] = $table_token;

		$request_row = tavox_menu_api_insert_table_request(
			$table_context,
			$payload,
			(string) ( $payload['request_key'] ?? '' ),
			'customer'
		);

		if ( is_wp_error( $request_row ) ) {
			return $request_row;
		}

		tavox_menu_api_log_operational_event(
			'table_request_created',
			[
				'request_id'    => absint( $request_row['id'] ?? 0 ),
				'request_key'   => sanitize_key( (string) ( $request_row['request_key'] ?? '' ) ),
				'table_id'      => absint( $request_row['table_id'] ?? 0 ),
				'table_type'    => sanitize_key( (string) ( $request_row['table_type'] ?? 'dine_in' ) ),
				'table_name'    => sanitize_text_field( (string) ( $request_row['table_name'] ?? '' ) ),
				'request_source'=> sanitize_key( (string) ( $request_row['request_source'] ?? 'customer' ) ),
				'items_count'   => count( (array) ( $request_row['payload']['items'] ?? [] ) ),
			]
		);

		if ( function_exists( 'tavox_menu_api_push_team_notification' ) ) {
			$account_ref = tavox_menu_api_build_waiter_account_ref(
				(string) ( $request_row['table_type'] ?? 'dine_in' ),
				absint( $request_row['table_id'] ?? 0 )
			);
			tavox_menu_api_push_team_notification(
				[
					'type'  => 'new_request',
					'title' => 'Nuevo pedido',
					'body'  => sprintf( 'Llegó un pedido nuevo en %s.', (string) ( $request_row['table_name'] ?? 'una mesa' ) ),
					'url'   => tavox_menu_api_get_team_frontend_url( '/equipo/pedidos' ),
					'tag'   => 'new-request-' . absint( $request_row['id'] ?? 0 ),
					'meta'  => [
						'request_id' => absint( $request_row['id'] ?? 0 ),
						'table_name' => (string) ( $request_row['table_name'] ?? '' ),
						'account_ref'=> $account_ref,
					],
				],
				[
					'audiences'             => [ 'service' ],
					'target_waiter_user_ids'=> tavox_menu_api_get_waiter_owner_user_ids_for_table_context( $table_context ),
				]
			);
		}

		return tavox_menu_api_no_store_rest_response(
			[
				'status'  => 'pending',
				'request' => $request_row,
			]
		);
	} catch ( Throwable $error ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[Tavox Menu API] table request endpoint error: ' . $error->getMessage() . ' @ ' . $error->getFile() . ':' . $error->getLine() );
		}

		return new WP_Error(
			'table_request_failed',
			__( 'No se pudo registrar la solicitud de mesa.', 'tavox-menu-api' ),
			[ 'status' => 500 ]
		);
	}
}

/**
 * Resuelve el contexto público de mesa desde token o key.
 *
 * @return array<string, mixed>|WP_Error
 */
function tavox_menu_api_resolve_public_table_context_from_request( WP_REST_Request $request ) {
	$table_token = (string) $request->get_param( 'table_token' );
	$table_key   = (string) $request->get_param( 'key' );

	if ( '' !== trim( $table_token ) ) {
		return tavox_menu_api_get_openpos_table_context_from_token( $table_token );
	}

	return tavox_menu_api_get_openpos_table_context_by_key( $table_key );
}

/**
 * Verifica si el mesero actual puede atender el hilo de una mesa.
 *
 * @return true|WP_Error
 */
function tavox_menu_api_assert_waiter_can_handle_table_messages( array $table_context, WP_User $user ) {
	if ( ! function_exists( 'tavox_menu_api_resolve_waiter_table_operability' ) ) {
		return true;
	}

	$current_request = tavox_menu_api_get_latest_open_table_request(
		absint( $table_context['table_id'] ?? 0 ),
		(string) ( $table_context['table_type'] ?? 'dine_in' )
	);
	$current_request = is_array( $current_request ) ? tavox_menu_api_format_request_row( $current_request ) : null;
	$latest_request  = tavox_menu_api_get_latest_table_request(
		absint( $table_context['table_id'] ?? 0 ),
		(string) ( $table_context['table_type'] ?? 'dine_in' ),
		[ 'pending', 'claimed', 'pushed', 'delivered', 'error' ]
	);
	$latest_request  = is_array( $latest_request ) ? tavox_menu_api_format_request_row( $latest_request ) : null;
	$consumption     = tavox_menu_api_build_table_consumption_summary( $table_context );
	if ( function_exists( 'tavox_menu_api_should_ignore_shadowed_open_request' ) && tavox_menu_api_should_ignore_shadowed_open_request( $current_request, $latest_request, $consumption ) ) {
		$current_request = null;
	}
	$operability     = tavox_menu_api_resolve_waiter_table_operability(
		true,
		$current_request,
		$latest_request,
		$consumption,
		$user
	);

	if ( 'busy' === (string) ( $operability['availability'] ?? '' ) ) {
		return new WP_Error(
			'table_message_forbidden',
			__( 'Esta cuenta la atiende otra persona en este momento.', 'tavox-menu-api' ),
			[ 'status' => 403 ]
		);
	}

	return true;
}

/**
 * Devuelve el canal de entrega operativo para avisos ligados a la mesa.
 *
 * @return array{audiences:array<int,string>,target_waiter_user_ids:array<int,int>,shared_mode:bool}
 */
function tavox_menu_api_get_table_message_notification_targets( array $table_context ): array {
	$shared_mode = tavox_menu_api_are_shared_tables_enabled();
	$owner_ids   = tavox_menu_api_get_waiter_owner_user_ids_for_table_context( $table_context );

	if ( $shared_mode || empty( $owner_ids ) ) {
		return [
			'audiences'             => [ 'service' ],
			'target_waiter_user_ids'=> [],
			'shared_mode'           => $shared_mode,
		];
	}

	return [
		'audiences'             => [ 'service' ],
		'target_waiter_user_ids'=> $owner_ids,
		'shared_mode'           => false,
	];
}

/**
 * Lista el hilo vigente del cliente en la mesa.
 *
 * @return WP_REST_Response|WP_Error
 */
function tavox_menu_api_rest_table_messages( WP_REST_Request $request ) {
	try {
		$table_context = tavox_menu_api_resolve_public_table_context_from_request( $request );
		if ( is_wp_error( $table_context ) ) {
			return $table_context;
		}

		return tavox_menu_api_no_store_rest_response( tavox_menu_api_list_table_messages( $table_context ) );
	} catch ( Throwable $error ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[Tavox Menu API] table messages endpoint error: ' . $error->getMessage() . ' @ ' . $error->getFile() . ':' . $error->getLine() );
		}

		return new WP_Error(
			'table_messages_failed',
			__( 'No se pudo cargar la conversación de la mesa.', 'tavox-menu-api' ),
			[ 'status' => 500 ]
		);
	}
}

/**
 * Crea un mensaje del cliente hacia el equipo.
 *
 * @return WP_REST_Response|WP_Error
 */
function tavox_menu_api_rest_table_messages_create( WP_REST_Request $request ) {
	try {
		$table_context = tavox_menu_api_resolve_public_table_context_from_request( $request );
		if ( is_wp_error( $table_context ) ) {
			return $table_context;
		}

		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : [];

		$summary = tavox_menu_api_build_public_table_operational_summary( $table_context );
		$sender  = sanitize_text_field( (string) ( $summary['customer_display_name'] ?? '' ) );
		if ( '' === $sender ) {
			$sender = sanitize_text_field( (string) ( $table_context['table_name'] ?? __( 'Mesa', 'tavox-menu-api' ) ) );
		}

		$message = tavox_menu_api_insert_table_message(
			$table_context,
			[
				'sender_role'   => 'customer',
				'sender_label'  => $sender,
				'message_type'  => sanitize_key( (string) ( $payload['message_type'] ?? 'free_text' ) ),
				'message_text'  => (string) ( $payload['message_text'] ?? '' ),
				'status'        => 'open',
				'request_key'   => (string) ( $payload['request_key'] ?? '' ),
			]
		);

		if ( is_wp_error( $message ) ) {
			return $message;
		}

		tavox_menu_api_log_operational_event(
			'table_message_created',
			[
				'message_id'    => absint( $message['id'] ?? 0 ),
				'table_id'      => absint( $table_context['table_id'] ?? 0 ),
				'table_type'    => sanitize_key( (string) ( $table_context['table_type'] ?? 'dine_in' ) ),
				'table_name'    => sanitize_text_field( (string) ( $table_context['table_name'] ?? '' ) ),
				'thread_token'  => sanitize_text_field( (string) ( $message['table_session_token'] ?? '' ) ),
				'request_key'   => sanitize_key( (string) ( $message['request_key'] ?? '' ) ),
				'sender_role'   => sanitize_key( (string) ( $message['sender_role'] ?? 'customer' ) ),
				'message_type'  => sanitize_key( (string) ( $message['message_type'] ?? 'free_text' ) ),
			]
		);

		if ( function_exists( 'tavox_menu_api_push_team_notification' ) ) {
			$targets = tavox_menu_api_get_table_message_notification_targets( $table_context );
			$account_ref = tavox_menu_api_build_waiter_account_ref(
				(string) ( $table_context['table_type'] ?? 'dine_in' ),
				absint( $table_context['table_id'] ?? 0 )
			);
			$table_token = tavox_menu_api_build_table_token( $table_context );

			tavox_menu_api_push_team_notification(
				[
					'type'  => 'table_message_new',
					'title' => 'Solicitar al mesero',
					'body'  => sprintf(
						'%s escribió desde %s: %s',
						$sender,
						(string) ( $table_context['table_name'] ?? __( 'la mesa', 'tavox-menu-api' ) ),
						wp_trim_words( (string) ( $message['message_text'] ?? '' ), 12, '...' )
					),
					'url'   => tavox_menu_api_get_team_frontend_url( '/equipo/servicio?table_token=' . rawurlencode( $table_token ) ),
					'tag'   => 'table-message-new-' . absint( $message['id'] ?? 0 ),
					'meta'  => [
						'message_id'  => absint( $message['id'] ?? 0 ),
						'table_name'  => (string) ( $table_context['table_name'] ?? '' ),
						'account_ref' => $account_ref,
						'table_token' => $table_token,
						'sender_role' => 'customer',
					],
				],
				$targets
			);
		}

		return tavox_menu_api_no_store_rest_response(
			[
				'ok'       => true,
				'message'  => $message,
				'messages' => tavox_menu_api_list_table_messages( $table_context ),
			]
		);
	} catch ( Throwable $error ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[Tavox Menu API] table messages create endpoint error: ' . $error->getMessage() . ' @ ' . $error->getFile() . ':' . $error->getLine() );
		}

		return new WP_Error(
			'table_message_create_failed',
			__( 'No se pudo enviar tu mensaje al equipo.', 'tavox-menu-api' ),
			[ 'status' => 500 ]
		);
	}
}

/**
 * Stream SSE público para refrescar estado de mesa y microchat.
 *
 * @return WP_REST_Response|WP_Error
 */
function tavox_menu_api_rest_table_live( WP_REST_Request $request ) {
	$table_context = tavox_menu_api_resolve_public_table_context_from_request( $request );
	if ( is_wp_error( $table_context ) ) {
		return $table_context;
	}

	if ( function_exists( 'session_write_close' ) ) {
		session_write_close();
	}

	while ( ob_get_level() > 0 ) {
		ob_end_flush();
	}

	ignore_user_abort( true );
	@set_time_limit( 0 );
	@ini_set( 'output_buffering', 'off' );
	@ini_set( 'zlib.output_compression', '0' );

	nocache_headers();
	header( 'Content-Type: text/event-stream; charset=utf-8' );
	header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
	header( 'Pragma: no-cache' );
	header( 'X-Accel-Buffering: no' );

	echo "retry: 2500\n\n";
	@flush();

	$last_hash = '';
	for ( $index = 0; $index < 2; $index++ ) {
		if ( connection_aborted() ) {
			exit;
		}

		$state = tavox_menu_api_get_public_table_live_state( $table_context );
		$event = $state['hash'] !== $last_hash ? 'sync' : 'ping';
		tavox_menu_api_emit_table_live_event( $event, $state );
		$last_hash = (string) ( $state['hash'] ?? '' );

		if ( 0 === $index ) {
			sleep( 1 );
		}
	}

	exit;
}

/**
 * Responde un mensaje de mesa desde el panel del equipo.
 *
 * @return WP_REST_Response|WP_Error
 */
function tavox_menu_api_rest_waiter_table_message_reply( WP_REST_Request $request ) {
	try {
		$session = function_exists( 'tavox_menu_api_require_waiter_session' )
			? tavox_menu_api_require_waiter_session( $request )
			: new WP_Error( 'waiter_session_missing', __( 'No pudimos validar tu acceso del equipo.', 'tavox-menu-api' ), [ 'status' => 401 ] );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$table_context = tavox_menu_api_resolve_public_table_context_from_request( $request );
		if ( is_wp_error( $table_context ) ) {
			return $table_context;
		}

		$allowed = tavox_menu_api_assert_waiter_can_handle_table_messages( $table_context, $session['user'] );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : [];
		$reply   = tavox_menu_api_insert_table_message(
			$table_context,
			[
				'sender_role'    => 'waiter',
				'sender_user_id' => absint( $session['user']->ID ?? 0 ),
				'sender_label'   => function_exists( 'tavox_menu_api_get_waiter_staff_name' ) ? tavox_menu_api_get_waiter_staff_name( $session['user'] ) : sanitize_text_field( (string) $session['user']->display_name ),
				'message_type'   => 'reply',
				'message_text'   => (string) ( $payload['message_text'] ?? '' ),
				'status'         => 'read',
			]
		);

		if ( is_wp_error( $reply ) ) {
			return $reply;
		}

		tavox_menu_api_log_operational_event(
			'table_message_reply',
			[
				'message_id'    => absint( $reply['id'] ?? 0 ),
				'table_id'      => absint( $table_context['table_id'] ?? 0 ),
				'table_type'    => sanitize_key( (string) ( $table_context['table_type'] ?? 'dine_in' ) ),
				'table_name'    => sanitize_text_field( (string) ( $table_context['table_name'] ?? '' ) ),
				'thread_token'  => sanitize_text_field( (string) ( $reply['table_session_token'] ?? '' ) ),
				'request_key'   => sanitize_key( (string) ( $reply['request_key'] ?? '' ) ),
				'waiter_id'     => absint( $session['user']->ID ?? 0 ),
				'waiter_name'   => function_exists( 'tavox_menu_api_get_waiter_staff_name' ) ? tavox_menu_api_get_waiter_staff_name( $session['user'] ) : sanitize_text_field( (string) $session['user']->display_name ),
			]
		);

		tavox_menu_api_mark_table_messages_read( $table_context );

		if ( function_exists( 'tavox_menu_api_resolve_waiter_notifications' ) ) {
			tavox_menu_api_resolve_waiter_notifications(
				[
					'event_types'  => [ 'table_message_new' ],
					'account_refs' => [
						tavox_menu_api_build_waiter_account_ref(
							(string) ( $table_context['table_type'] ?? 'dine_in' ),
							absint( $table_context['table_id'] ?? 0 )
						),
					],
				]
			);
		}

		if ( function_exists( 'tavox_menu_api_push_team_notification' ) ) {
			$targets = tavox_menu_api_get_table_message_notification_targets( $table_context );
			$targets['exclude_user_id'] = absint( $session['user']->ID ?? 0 );
			if ( ! empty( $targets['target_waiter_user_ids'] ) ) {
				$targets['target_waiter_user_ids'] = array_values(
					array_filter(
						(array) $targets['target_waiter_user_ids'],
						static fn( $user_id ): bool => absint( $user_id ) !== absint( $session['user']->ID ?? 0 )
					)
				);
			}

			if ( ! empty( $targets['shared_mode'] ) || ! empty( $targets['target_waiter_user_ids'] ) ) {
				$table_token = tavox_menu_api_build_table_token( $table_context );
				tavox_menu_api_push_team_notification(
					[
						'type'  => 'table_message_reply',
						'title' => 'Respuesta enviada',
						'body'  => sprintf(
							'%s respondió una solicitud en %s.',
							function_exists( 'tavox_menu_api_get_waiter_staff_name' ) ? tavox_menu_api_get_waiter_staff_name( $session['user'] ) : __( 'El equipo', 'tavox-menu-api' ),
							(string) ( $table_context['table_name'] ?? __( 'esta cuenta', 'tavox-menu-api' ) )
						),
						'url'   => tavox_menu_api_get_team_frontend_url( '/equipo/servicio?table_token=' . rawurlencode( $table_token ) ),
						'tag'   => 'table-message-reply-' . absint( $reply['id'] ?? 0 ),
						'meta'  => [
							'message_id'  => absint( $reply['id'] ?? 0 ),
							'table_name'  => (string) ( $table_context['table_name'] ?? '' ),
							'account_ref' => tavox_menu_api_build_waiter_account_ref(
								(string) ( $table_context['table_type'] ?? 'dine_in' ),
								absint( $table_context['table_id'] ?? 0 )
							),
							'table_token' => $table_token,
							'sender_role' => 'waiter',
						],
					],
					$targets
				);
			}
		}

		return tavox_menu_api_no_store_rest_response(
			[
				'ok'       => true,
				'message'  => $reply,
				'messages' => tavox_menu_api_list_table_messages( $table_context ),
			]
		);
	} catch ( Throwable $error ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[Tavox Menu API] waiter table message reply error: ' . $error->getMessage() . ' @ ' . $error->getFile() . ':' . $error->getLine() );
		}

		return new WP_Error(
			'table_message_reply_failed',
			__( 'No se pudo enviar la respuesta del equipo.', 'tavox-menu-api' ),
			[ 'status' => 500 ]
		);
	}
}

/**
 * Marca como leído el hilo de mesa para el equipo.
 *
 * @return WP_REST_Response|WP_Error
 */
function tavox_menu_api_rest_waiter_table_message_read( WP_REST_Request $request ) {
	$session = function_exists( 'tavox_menu_api_require_waiter_session' )
		? tavox_menu_api_require_waiter_session( $request )
		: new WP_Error( 'waiter_session_missing', __( 'No pudimos validar tu acceso del equipo.', 'tavox-menu-api' ), [ 'status' => 401 ] );
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	$table_context = tavox_menu_api_resolve_public_table_context_from_request( $request );
	if ( is_wp_error( $table_context ) ) {
		return $table_context;
	}

	$allowed = tavox_menu_api_assert_waiter_can_handle_table_messages( $table_context, $session['user'] );
	if ( is_wp_error( $allowed ) ) {
		return $allowed;
	}

	$updated = tavox_menu_api_mark_table_messages_read( $table_context );

	return tavox_menu_api_no_store_rest_response(
		[
			'ok'      => true,
			'updated' => $updated,
		]
	);
}

/**
 * Resuelve el hilo vigente de la mesa para cerrar la solicitud.
 *
 * @return WP_REST_Response|WP_Error
 */
function tavox_menu_api_rest_waiter_table_message_resolve( WP_REST_Request $request ) {
	try {
		$session = function_exists( 'tavox_menu_api_require_waiter_session' )
			? tavox_menu_api_require_waiter_session( $request )
			: new WP_Error( 'waiter_session_missing', __( 'No pudimos validar tu acceso del equipo.', 'tavox-menu-api' ), [ 'status' => 401 ] );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$table_context = tavox_menu_api_resolve_public_table_context_from_request( $request );
		if ( is_wp_error( $table_context ) ) {
			return $table_context;
		}

		$allowed = tavox_menu_api_assert_waiter_can_handle_table_messages( $table_context, $session['user'] );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$payload          = $request->get_json_params();
		$payload          = is_array( $payload ) ? $payload : [];
		$message_id       = absint( $payload['message_id'] ?? 0 );
		$notification_id  = absint( $payload['notification_id'] ?? 0 );
		$scope            = $message_id > 0
			? tavox_menu_api_get_table_message_scope_from_message_id( $table_context, $message_id )
			: null;
		$scope            = is_array( $scope ) ? $scope : tavox_menu_api_get_table_message_scope( $table_context, true );
		$updated          = tavox_menu_api_resolve_table_messages( $table_context, $scope );

		if ( $updated > 0 ) {
			tavox_menu_api_insert_table_message(
				$table_context,
				[
					'sender_role'    => 'system',
					'sender_user_id' => 0,
					'sender_label'   => __( 'Equipo', 'tavox-menu-api' ),
					'message_type'   => 'reply',
					'message_text'   => __( 'Solicitud confirmada.', 'tavox-menu-api' ),
					'status'         => 'read',
					'request_key'    => (string) ( $scope['request_key'] ?? '' ),
					'thread_token'   => (string) ( $scope['thread_token'] ?? '' ),
				]
			);

			tavox_menu_api_log_operational_event(
				'table_message_resolved',
				[
					'table_id'     => absint( $table_context['table_id'] ?? 0 ),
					'table_type'   => sanitize_key( (string) ( $table_context['table_type'] ?? 'dine_in' ) ),
					'table_name'   => sanitize_text_field( (string) ( $table_context['table_name'] ?? '' ) ),
					'thread_token' => sanitize_text_field( (string) tavox_menu_api_get_public_table_thread_token( $table_context ) ),
					'waiter_id'    => absint( $session['user']->ID ?? 0 ),
					'waiter_name'  => function_exists( 'tavox_menu_api_get_waiter_staff_name' ) ? tavox_menu_api_get_waiter_staff_name( $session['user'] ) : sanitize_text_field( (string) $session['user']->display_name ),
					'updated'      => $updated,
				]
			);
		}

		if ( function_exists( 'tavox_menu_api_resolve_waiter_notifications' ) ) {
			$notification_filters = [
				'event_types'  => [ 'table_message_new', 'table_message_reply' ],
				'account_refs' => [
					tavox_menu_api_build_waiter_account_ref(
						(string) ( $table_context['table_type'] ?? 'dine_in' ),
						absint( $table_context['table_id'] ?? 0 )
					),
				],
			];

			if ( $notification_id > 0 ) {
				$notification_filters['ids'] = [ $notification_id ];
			}

			tavox_menu_api_resolve_waiter_notifications( $notification_filters );
		}

		if ( $updated > 0 && function_exists( 'tavox_menu_api_push_team_notification' ) ) {
			$targets = tavox_menu_api_get_table_message_notification_targets( $table_context );
			$targets['exclude_user_id'] = absint( $session['user']->ID ?? 0 );
			if ( ! empty( $targets['target_waiter_user_ids'] ) ) {
				$targets['target_waiter_user_ids'] = array_values(
					array_filter(
						(array) $targets['target_waiter_user_ids'],
						static fn( $user_id ): bool => absint( $user_id ) !== absint( $session['user']->ID ?? 0 )
					)
				);
			}

			if ( ! empty( $targets['shared_mode'] ) || ! empty( $targets['target_waiter_user_ids'] ) ) {
				$table_token = tavox_menu_api_build_table_token( $table_context );
				tavox_menu_api_push_team_notification(
					[
						'type'  => 'table_message_resolved',
						'title' => 'Solicitud resuelta',
						'body'  => sprintf(
							'%s cerró la solicitud en %s.',
							function_exists( 'tavox_menu_api_get_waiter_staff_name' ) ? tavox_menu_api_get_waiter_staff_name( $session['user'] ) : __( 'El equipo', 'tavox-menu-api' ),
							(string) ( $table_context['table_name'] ?? __( 'esta cuenta', 'tavox-menu-api' ) )
						),
						'url'   => tavox_menu_api_get_team_frontend_url( '/equipo/servicio?table_token=' . rawurlencode( $table_token ) ),
						'tag'   => 'table-message-resolved-' . absint( $table_context['table_id'] ?? 0 ),
						'meta'  => [
							'table_name'  => (string) ( $table_context['table_name'] ?? '' ),
							'account_ref' => tavox_menu_api_build_waiter_account_ref(
								(string) ( $table_context['table_type'] ?? 'dine_in' ),
								absint( $table_context['table_id'] ?? 0 )
							),
							'table_token' => $table_token,
						],
					],
					$targets
				);
			}
		}

		return tavox_menu_api_no_store_rest_response(
			[
				'ok'       => true,
				'updated'  => $updated,
				'messages' => tavox_menu_api_list_table_messages( $table_context ),
			]
		);
	} catch ( Throwable $error ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[Tavox Menu API] waiter table message resolve error: ' . $error->getMessage() . ' @ ' . $error->getFile() . ':' . $error->getLine() );
		}

		return new WP_Error(
			'table_message_resolve_failed',
			__( 'No se pudo cerrar esta solicitud de mesa.', 'tavox-menu-api' ),
			[ 'status' => 500 ]
		);
	}
}
