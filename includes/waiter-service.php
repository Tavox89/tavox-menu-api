<?php

defined( 'ABSPATH' ) || exit;

/**
 * Registra la capability operativa para meseros.
 */
function tavox_menu_api_register_waiter_capability(): void {
	foreach ( [ 'administrator', 'shop_manager' ] as $role_name ) {
		$role = get_role( $role_name );
		if ( $role && ! $role->has_cap( 'tavox_waiter' ) ) {
			$role->add_cap( 'tavox_waiter' );
		}
	}
}
add_action( 'init', 'tavox_menu_api_register_waiter_capability', 20 );

/**
 * Determina si un usuario está habilitado para el panel de meseros.
 */
function tavox_menu_api_user_can_act_as_waiter( WP_User $user ): bool {
	if ( user_can( $user, 'tavox_waiter' ) || user_can( $user, 'manage_woocommerce' ) ) {
		return true;
	}

	return ! empty( get_user_meta( $user->ID, '_tavox_waiter_enabled', true ) );
}

/**
 * Obtiene el PIN operativo del mesero.
 */
function tavox_menu_api_get_waiter_pin( int $user_id ): string {
	$pin = trim( (string) get_user_meta( $user_id, '_tavox_waiter_pin', true ) );
	if ( '' !== $pin ) {
		return $pin;
	}

	return trim( (string) get_user_meta( $user_id, '_op_pin', true ) );
}

/**
 * Renderiza ajustes de mesero en el perfil.
 */
function tavox_menu_api_render_waiter_profile_fields( WP_User $user ): void {
	if ( ! current_user_can( 'edit_users' ) && ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	$enabled = ! empty( get_user_meta( $user->ID, '_tavox_waiter_enabled', true ) );
	$pin     = (string) get_user_meta( $user->ID, '_tavox_waiter_pin', true );
	?>
	<h2><?php esc_html_e( 'Acceso del equipo', 'tavox-menu-api' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th><label for="tavox_waiter_enabled"><?php esc_html_e( 'Habilitar acceso', 'tavox-menu-api' ); ?></label></th>
			<td>
				<label>
					<input type="checkbox" id="tavox_waiter_enabled" name="tavox_waiter_enabled" value="1" <?php checked( $enabled ); ?> />
					<?php esc_html_e( 'Este usuario puede entrar al panel del equipo.', 'tavox-menu-api' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th><label for="tavox_waiter_pin"><?php esc_html_e( 'PIN de acceso', 'tavox-menu-api' ); ?></label></th>
			<td>
				<input type="text" id="tavox_waiter_pin" name="tavox_waiter_pin" value="<?php echo esc_attr( $pin ); ?>" class="regular-text" inputmode="numeric" />
				<p class="description"><?php esc_html_e( 'Si lo dejas vacío, se intentará usar el PIN operativo que ya tenga este usuario.', 'tavox-menu-api' ); ?></p>
			</td>
		</tr>
	</table>
	<?php
}
add_action( 'show_user_profile', 'tavox_menu_api_render_waiter_profile_fields' );
add_action( 'edit_user_profile', 'tavox_menu_api_render_waiter_profile_fields' );

/**
 * Guarda ajustes de mesero en el perfil.
 */
function tavox_menu_api_save_waiter_profile_fields( int $user_id ): void {
	if ( ! current_user_can( 'edit_user', $user_id ) ) {
		return;
	}

	update_user_meta( $user_id, '_tavox_waiter_enabled', ! empty( $_POST['tavox_waiter_enabled'] ) ? 1 : 0 );

	if ( isset( $_POST['tavox_waiter_pin'] ) ) {
		$pin = preg_replace( '/\D+/', '', (string) wp_unslash( $_POST['tavox_waiter_pin'] ) );
		update_user_meta( $user_id, '_tavox_waiter_pin', is_string( $pin ) ? $pin : '' );
	}
}
add_action( 'personal_options_update', 'tavox_menu_api_save_waiter_profile_fields' );
add_action( 'edit_user_profile_update', 'tavox_menu_api_save_waiter_profile_fields' );

/**
 * Busca un usuario de mesero por login/email.
 */
function tavox_menu_api_find_waiter_user( string $login ): ?WP_User {
	$login = trim( $login );
	if ( '' === $login ) {
		return null;
	}

	$user = get_user_by( 'login', $login );
	if ( ! $user && is_email( $login ) ) {
		$user = get_user_by( 'email', $login );
	}
	if ( ! $user ) {
		$users = get_users(
			[
				'search'         => $login,
				'search_columns' => [ 'user_login', 'user_email', 'display_name' ],
				'number'         => 1,
			]
		);
		$user = ! empty( $users[0] ) ? $users[0] : null;
	}

	return $user instanceof WP_User ? $user : null;
}

/**
 * Devuelve el mejor nombre visible para una persona del equipo.
 */
function tavox_menu_api_get_waiter_staff_name( WP_User $user ): string {
	$first_name = sanitize_text_field( (string) get_user_meta( $user->ID, 'first_name', true ) );
	$last_name  = sanitize_text_field( (string) get_user_meta( $user->ID, 'last_name', true ) );
	$full_name  = trim( $first_name . ' ' . $last_name );

	if ( '' !== $full_name ) {
		return $full_name;
	}

	$display_name = sanitize_text_field( (string) $user->display_name );
	if ( '' !== $display_name && ! is_email( $display_name ) ) {
		return $display_name;
	}

	$nickname = sanitize_text_field( (string) get_user_meta( $user->ID, 'nickname', true ) );
	if ( '' !== $nickname && ! is_email( $nickname ) ) {
		return $nickname;
	}

	$user_nicename = sanitize_text_field( (string) $user->user_nicename );
	if ( '' !== $user_nicename && ! is_email( $user_nicename ) ) {
		return $user_nicename;
	}

	$user_login = sanitize_text_field( (string) $user->user_login );
	if ( '' !== $user_login ) {
		$normalized_login = strstr( $user_login, '@', true );
		$normalized_login = false === $normalized_login ? $user_login : $normalized_login;
		$normalized_login = trim( preg_replace( '/[\._\-]+/', ' ', (string) $normalized_login ) );

		if ( '' !== $normalized_login ) {
			return ucwords( $normalized_login );
		}
	}

	$user_email = sanitize_text_field( (string) $user->user_email );
	$email_base = strstr( $user_email, '@', true );
	$email_base = false === $email_base ? $user_email : $email_base;
	$email_base = trim( preg_replace( '/[\._\-]+/', ' ', (string) $email_base ) );

	return '' !== $email_base ? ucwords( $email_base ) : __( 'Equipo', 'tavox-menu-api' );
}

/**
 * Devuelve un nombre visible usando el usuario si está disponible.
 */
function tavox_menu_api_resolve_waiter_staff_name( int $user_id = 0, string $fallback = '' ): string {
	if ( $user_id > 0 ) {
		$user = get_user_by( 'id', $user_id );
		if ( $user instanceof WP_User ) {
			return tavox_menu_api_get_waiter_staff_name( $user );
		}
	}

	return sanitize_text_field( $fallback );
}

/**
 * Busca un mesero activo por PIN operativo.
 */
function tavox_menu_api_find_waiter_user_by_pin( string $pin ) {
	$pin = preg_replace( '/\D+/', '', trim( $pin ) );
	if ( '' === $pin ) {
		return null;
	}

	$users = get_users(
		[
			'meta_key'   => '_tavox_waiter_pin',
			'meta_value' => $pin,
			'number'     => 2,
			'fields'     => 'all',
		]
	);
	$users = array_values(
		array_filter(
			(array) $users,
			static fn ( $user ) => $user instanceof WP_User && tavox_menu_api_user_can_act_as_waiter( $user )
		)
	);

	if ( 1 === count( $users ) ) {
		return $users[0];
	}

	if ( count( $users ) > 1 ) {
		return new WP_Error(
			'duplicate_pin',
			__( 'Ese PIN está repetido en más de una cuenta del equipo. Revísalo en la configuración.', 'tavox-menu-api' ),
			[ 'status' => 409 ]
		);
	}

	$users = get_users(
		[
			'meta_key'   => '_op_pin',
			'meta_value' => $pin,
			'number'     => 2,
			'fields'     => 'all',
		]
	);
	$users = array_values(
		array_filter(
			(array) $users,
			static fn ( $user ) => $user instanceof WP_User && tavox_menu_api_user_can_act_as_waiter( $user )
		)
	);

	if ( 1 === count( $users ) ) {
		return $users[0];
	}

	if ( count( $users ) > 1 ) {
		return new WP_Error(
			'duplicate_pin',
			__( 'Ese PIN está repetido en más de una cuenta del equipo. Revísalo en la configuración.', 'tavox-menu-api' ),
			[ 'status' => 409 ]
		);
	}

	return null;
}

/**
 * Intenta abrir una ventana corta de mantenimiento para evitar limpiezas concurrentes.
 */
function tavox_menu_api_begin_maintenance_window( string $key, int $cooldown_seconds = 15, int $lock_ttl_seconds = 10 ): bool {
	$suffix               = sanitize_key( $key );
	$cooldown_transient   = 'tavox_maint_recent_' . $suffix;
	$lock_option          = 'tavox_maint_lock_' . $suffix;
	$cooldown_seconds     = max( 5, $cooldown_seconds );
	$lock_ttl_seconds     = max( 5, $lock_ttl_seconds );
	$now                  = time();
	$next_lock_expiration = $now + $lock_ttl_seconds;

	if ( get_transient( $cooldown_transient ) ) {
		return false;
	}

	if ( add_option( $lock_option, (string) $next_lock_expiration, '', false ) ) {
		return true;
	}

	$current_lock_expiration = absint( get_option( $lock_option, 0 ) );
	if ( $current_lock_expiration > $now ) {
		return false;
	}

	update_option( $lock_option, (string) $next_lock_expiration, false );

	return true;
}

/**
 * Cierra una ventana corta de mantenimiento y deja un cooldown para la próxima corrida.
 */
function tavox_menu_api_end_maintenance_window( string $key, int $cooldown_seconds = 15 ): void {
	$suffix             = sanitize_key( $key );
	$cooldown_transient = 'tavox_maint_recent_' . $suffix;
	$lock_option        = 'tavox_maint_lock_' . $suffix;

	set_transient( $cooldown_transient, 1, max( 5, $cooldown_seconds ) );
	delete_option( $lock_option );
}

/**
 * Limpia sesiones inactivas.
 */
function tavox_menu_api_cleanup_waiter_sessions(): void {
	global $wpdb;

	$maintenance_key = 'waiter_sessions_cleanup';
	if ( ! tavox_menu_api_begin_maintenance_window( $maintenance_key, 20, 10 ) ) {
		return;
	}

	$table     = tavox_menu_api_get_waiter_sessions_table_name();
	$threshold = gmdate( 'Y-m-d H:i:s', time() - tavox_menu_api_get_waiter_session_idle_timeout_seconds() );

	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$table} SET status = 'inactive' WHERE status = 'active' AND last_seen < %s",
			$threshold
		)
	);

	if ( function_exists( 'tavox_menu_api_cleanup_waiter_push_subscriptions' ) ) {
		tavox_menu_api_cleanup_waiter_push_subscriptions();
	}

	tavox_menu_api_end_maintenance_window( $maintenance_key, 20 );
}

/**
 * Devuelve el payload de sesión listo para el frontend del equipo.
 *
 * @return array<string, mixed>
 */
function tavox_menu_api_build_waiter_client_session_payload( string $session_token, WP_User $user ): array {
	return [
		'session_token'         => $session_token,
		'waiter'                => [
			'id'           => $user->ID,
			'display_name' => tavox_menu_api_get_waiter_staff_name( $user ),
			'login'        => $user->user_login,
		],
		'shared_tables_enabled' => tavox_menu_api_are_shared_tables_enabled(),
		'realtime'              => tavox_menu_api_get_waiter_realtime_config(),
	];
}

/**
 * Crea una sesión activa de mesero.
 *
 * @return array<string, mixed>|WP_Error
 */
function tavox_menu_api_create_waiter_session( WP_User $user, string $device_label = '' ) {
	global $wpdb;

	$sessions_table = tavox_menu_api_get_waiter_sessions_table_name();
	$token          = wp_generate_password( 64, false, false );
	$now_mysql      = tavox_menu_api_now_mysql();

	$inserted = $wpdb->insert(
		$sessions_table,
		[
			'user_id'      => $user->ID,
			'session_token'=> $token,
			'device_label' => sanitize_text_field( $device_label ),
			'status'       => 'active',
			'created_at'   => $now_mysql,
			'last_seen'    => $now_mysql,
		],
		[ '%d', '%s', '%s', '%s', '%s', '%s' ]
	);

	if ( false === $inserted ) {
		return new WP_Error( 'session_failed', __( 'No se pudo abrir el acceso del equipo.', 'tavox-menu-api' ), [ 'status' => 500 ] );
	}

	return tavox_menu_api_build_waiter_client_session_payload( $token, $user );
}

/**
 * Recupera una sesión válida desde token.
 *
 * @return array<string, mixed>|WP_Error
 */
function tavox_menu_api_require_waiter_session( ?WP_REST_Request $request = null, ?string $raw_token = null ) {
	global $wpdb;

	tavox_menu_api_cleanup_waiter_sessions();
	tavox_menu_api_cleanup_request_states();

	$token = $raw_token;
	if ( null === $token && $request instanceof WP_REST_Request ) {
		$token = (string) $request->get_param( 'session_token' );

		$auth = (string) $request->get_header( 'authorization' );
		if ( '' === $token && preg_match( '/Bearer\s+(.+)$/i', $auth, $matches ) ) {
			$token = trim( (string) $matches[1] );
		}
	}

	$token = trim( (string) $token );
	if ( '' === $token ) {
		return new WP_Error( 'missing_session', __( 'Falta tu acceso activo.', 'tavox-menu-api' ), [ 'status' => 401 ] );
	}

	$sessions_table = tavox_menu_api_get_waiter_sessions_table_name();
	$row            = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$sessions_table} WHERE session_token = %s AND status = 'active' LIMIT 1",
			$token
		),
		ARRAY_A
	);

	if ( ! is_array( $row ) ) {
		return new WP_Error( 'invalid_session', __( 'Tu acceso ya no está activo.', 'tavox-menu-api' ), [ 'status' => 401 ] );
	}

	$user = get_user_by( 'id', absint( $row['user_id'] ?? 0 ) );
	if ( ! $user instanceof WP_User || ! tavox_menu_api_user_can_act_as_waiter( $user ) ) {
		return new WP_Error( 'invalid_waiter', __( 'Este usuario no tiene acceso al panel del equipo.', 'tavox-menu-api' ), [ 'status' => 403 ] );
	}

	$wpdb->update(
		$sessions_table,
		[
			'last_seen' => tavox_menu_api_now_mysql(),
		],
		[
			'id' => absint( $row['id'] ?? 0 ),
		],
		[ '%s' ],
		[ '%d' ]
	);

	return [
		'session' => $row,
		'user'    => $user,
	];
}

/**
 * Realiza el claim atómico de una solicitud.
 *
 * @return array<string, mixed>|WP_Error
 */
function tavox_menu_api_claim_waiter_request( int $request_id, WP_User $user ) {
	global $wpdb;

	tavox_menu_api_cleanup_request_states();

	$requests_table = tavox_menu_api_get_table_requests_table_name();
	$now_mysql      = tavox_menu_api_now_mysql();
	$updated        = $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$requests_table}
			SET status = 'claimed',
				waiter_user_id = %d,
				waiter_name = %s,
				claimed_at = %s,
				updated_at = %s
			WHERE id = %d AND status = 'pending'",
			$user->ID,
			tavox_menu_api_get_waiter_staff_name( $user ),
			$now_mysql,
			$now_mysql,
			$request_id
		)
	);

	$row = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$requests_table} WHERE id = %d LIMIT 1", $request_id ),
		ARRAY_A
	);

	if ( is_array( $row ) && 'claimed' === ( $row['status'] ?? '' ) && absint( $row['waiter_user_id'] ?? 0 ) === $user->ID ) {
		$formatted = tavox_menu_api_format_request_row( $row );
		$account_ref = tavox_menu_api_build_waiter_account_ref(
			(string) ( $formatted['table_type'] ?? 'dine_in' ),
			absint( $formatted['table_id'] ?? 0 )
		);

		tavox_menu_api_log_operational_event(
			'request_claimed',
			[
				'request_id'  => absint( $formatted['id'] ?? 0 ),
				'request_key' => sanitize_key( (string) ( $formatted['request_key'] ?? '' ) ),
				'table_id'    => absint( $formatted['table_id'] ?? 0 ),
				'table_type'  => sanitize_key( (string) ( $formatted['table_type'] ?? 'dine_in' ) ),
				'table_name'  => sanitize_text_field( (string) ( $formatted['table_name'] ?? '' ) ),
				'waiter_id'   => $user->ID,
				'waiter_name' => tavox_menu_api_get_waiter_staff_name( $user ),
			]
		);

		if ( function_exists( 'tavox_menu_api_resolve_waiter_notifications' ) ) {
			tavox_menu_api_resolve_waiter_notifications(
				[
					'event_types'  => [ 'new_request' ],
					'request_ids'  => [ absint( $formatted['id'] ?? 0 ) ],
					'account_refs' => [ $account_ref ],
				]
			);
		}

		if ( function_exists( 'tavox_menu_api_push_team_notification' ) ) {
			tavox_menu_api_push_team_notification(
				[
					'type'  => 'request_claimed',
					'title' => 'Pedido tomado',
					'body'  => sprintf( '%s ahora lo atiende %s.', (string) ( $formatted['table_name'] ?? 'Este pedido' ), tavox_menu_api_get_waiter_staff_name( $user ) ),
					'url'   => tavox_menu_api_get_team_frontend_url( '/equipo/pedidos' ),
					'tag'   => 'request-claimed-' . absint( $formatted['id'] ?? 0 ),
					'meta'  => [
						'request_id' => absint( $formatted['id'] ?? 0 ),
						'table_name' => (string) ( $formatted['table_name'] ?? '' ),
						'account_ref'=> $account_ref,
					],
				],
				[
					'audiences'       => [ 'service' ],
					'exclude_user_id' => $user->ID,
				]
			);
		}

		return $formatted;
	}

	if ( ! $updated ) {
		return new WP_Error( 'claim_failed', __( 'Ese pedido ya lo tomó otro compañero.', 'tavox-menu-api' ), [ 'status' => 409 ] );
	}

	return tavox_menu_api_format_request_row( is_array( $row ) ? $row : [] );
}

/**
 * Traduce el estado operativo de una solicitud a una etiqueta visible.
 */
function tavox_menu_api_get_waiter_request_status_label( string $status ): string {
	$status = sanitize_key( $status );

	if ( 'pending' === $status ) {
		return __( 'Por revisar', 'tavox-menu-api' );
	}

	if ( 'claimed' === $status ) {
		return __( 'En atención', 'tavox-menu-api' );
	}

	if ( 'pushed' === $status ) {
		return __( 'Pedido agregado', 'tavox-menu-api' );
	}

	if ( 'delivered' === $status ) {
		return __( 'Entregado', 'tavox-menu-api' );
	}

	if ( 'error' === $status ) {
		return __( 'Revisar', 'tavox-menu-api' );
	}

	if ( 'expired' === $status ) {
		return __( 'Vencido', 'tavox-menu-api' );
	}

	if ( 'missing' === $status ) {
		return __( 'Ya no disponible', 'tavox-menu-api' );
	}

	return __( 'Sin novedad', 'tavox-menu-api' );
}

/**
 * Cuenta los artículos visibles dentro del payload del pedido.
 */
function tavox_menu_api_count_waiter_request_items( array $request ): int {
	$items = is_array( $request['payload']['items'] ?? null ) ? $request['payload']['items'] : [];
	$total = 0;

	foreach ( $items as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}

		$total += max( 0, (int) ( $item['qty'] ?? 0 ) );
	}

	return $total;
}

/**
 * Resume la acción operativa disponible para una solicitud concreta.
 *
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function tavox_menu_api_resolve_waiter_request_action_payload( array $request, ?WP_User $current_user = null ): array {
	$status             = sanitize_key( (string) ( $request['status'] ?? '' ) );
	$current_user_id    = $current_user instanceof WP_User ? (int) $current_user->ID : 0;
	$assigned_user_id   = absint( $request['waiter_user_id'] ?? 0 );
	$request_is_mine    = $current_user_id > 0 && $assigned_user_id === $current_user_id;
	$table_id           = absint( $request['table_id'] ?? 0 );
	$table_type         = sanitize_key( (string) ( $request['table_type'] ?? 'dine_in' ) );
	$has_context        = false;
	$consumption        = [
		'items_count'  => 0,
		'lines_count'  => 0,
		'total_amount' => 0.0,
		'total_qty'    => 0.0,
		'seller'       => [],
		'customer'     => [],
	];
	$latest_request     = $request;
	$blocking_request   = null;
	$table_availability = 'available';
	$table_reason       = __( 'Lista para tomar un pedido.', 'tavox-menu-api' );
	$managed_by         = '';
	$can_direct_order   = false;
	$can_claim          = false;
	$can_accept         = false;
	$primary_action     = 'none';
	$action_reason      = '';

	if ( $table_id > 0 ) {
		try {
			$context = tavox_menu_api_get_openpos_table_context_by_identity(
				$table_id,
				$table_type,
				absint( $request['register_id'] ?? 0 ),
				absint( $request['warehouse_id'] ?? 0 ),
				(string) ( $request['table_name'] ?? '' )
			);

			if ( ! is_wp_error( $context ) && is_array( $context ) ) {
				$has_context = true;
				$consumption = tavox_menu_api_build_table_consumption_summary( $context );
			}
		} catch ( Throwable $error ) {
			tavox_menu_api_log_operational_event(
				'request_action_context_probe_failed',
				[
					'request_id' => absint( $request['id'] ?? 0 ),
					'table_id'   => $table_id,
					'table_type' => $table_type,
					'error'      => $error->getMessage(),
				]
			);
		}

		$latest_candidate = tavox_menu_api_get_latest_table_request(
			$table_id,
			$table_type,
			[ 'pending', 'claimed', 'error', 'pushed', 'delivered', 'expired' ]
		);
		if ( is_array( $latest_candidate ) && ! empty( $latest_candidate ) ) {
			$latest_request = $latest_candidate;
		}

		$blocking_candidate = tavox_menu_api_get_latest_table_request(
			$table_id,
			$table_type,
			[ 'claimed', 'error' ]
		);
		if ( is_array( $blocking_candidate ) && ! empty( $blocking_candidate ) ) {
			$blocking_request = $blocking_candidate;
		}

		if ( tavox_menu_api_should_ignore_shadowed_open_request( $blocking_request, $latest_request, $consumption ) ) {
			$blocking_request = null;
		}

		$operability         = tavox_menu_api_resolve_waiter_table_operability(
			$has_context,
			$blocking_request,
			$latest_request,
			$consumption,
			$current_user
		);
		$table_availability  = sanitize_key( (string) ( $operability['availability'] ?? 'available' ) );
		$table_reason        = sanitize_text_field( (string) ( $operability['availability_reason'] ?? '' ) );
		$managed_by          = sanitize_text_field( (string) ( $operability['managed_by'] ?? '' ) );
		$can_direct_order    = ! empty( $operability['can_direct_order'] );
	}

	if ( in_array( $status, [ 'claimed', 'error' ], true ) ) {
		$can_accept     = $request_is_mine;
		$primary_action = $can_accept ? 'accept' : 'none';
		$action_reason  = $can_accept
			? __( 'Ya puedes agregar este pedido a la mesa.', 'tavox-menu-api' )
			: ( $table_reason ?: __( 'Este pedido lo atiende otra persona del equipo.', 'tavox-menu-api' ) );
	} elseif ( 'pending' === $status ) {
		$can_accept_direct = $request_is_mine || ( $can_direct_order && in_array( $table_availability, [ 'mine', 'shared' ], true ) );

		if ( $can_accept_direct ) {
			$can_accept     = true;
			$primary_action = 'accept';
			$action_reason  = 'shared' === $table_availability
				? __( 'Esta cuenta compartida ya permite sumar el pedido directamente.', 'tavox-menu-api' )
				: __( 'Esta mesa ya está a tu cargo. Puedes agregar el pedido directamente.', 'tavox-menu-api' );
		} elseif ( 'available' === $table_availability ) {
			$can_claim      = true;
			$primary_action = 'claim';
			$action_reason  = __( 'Primero toma el pedido para continuarlo.', 'tavox-menu-api' );
		} else {
			$primary_action = 'none';
			$action_reason  = $table_reason ?: __( 'Este pedido no está disponible para ti en este momento.', 'tavox-menu-api' );
		}
	} else {
		$primary_action = 'none';
		$action_reason  = $table_reason;
	}

	return [
		'is_mine'                   => $request_is_mine || 'mine' === $table_availability,
		'request_is_mine'           => $request_is_mine,
		'table_availability'        => $table_availability,
		'table_availability_reason' => $table_reason,
		'managed_by'                => $managed_by,
		'can_direct_order'          => $can_direct_order,
		'can_claim'                 => $can_claim,
		'can_accept'                => $can_accept,
		'primary_action'            => $primary_action,
		'action_reason'             => $action_reason,
	];
}

/**
 * Construye el detalle operativo de una solicitud para el equipo.
 *
 * @return array<string, mixed>
 */
function tavox_menu_api_build_waiter_request_detail_payload( array $row, ?WP_User $current_user = null ): array {
	$request          = tavox_menu_api_format_request_row( $row );
	$status           = sanitize_key( (string) ( $request['status'] ?? '' ) );
	$assigned_user_id = absint( $request['waiter_user_id'] ?? 0 );
	$action_payload   = tavox_menu_api_resolve_waiter_request_action_payload( $request, $current_user );

	return [
		'available'      => true,
		'id'             => absint( $request['id'] ?? 0 ),
		'request_key'    => (string) ( $request['request_key'] ?? '' ),
		'status'         => $status,
		'status_label'   => tavox_menu_api_get_waiter_request_status_label( $status ),
		'table_id'       => absint( $request['table_id'] ?? 0 ),
		'table_name'     => sanitize_text_field( (string) ( $request['table_name'] ?? '' ) ),
		'table_type'     => sanitize_key( (string) ( $request['table_type'] ?? 'dine_in' ) ),
		'account_ref'    => tavox_menu_api_build_waiter_account_ref(
			(string) ( $request['table_type'] ?? 'dine_in' ),
			absint( $request['table_id'] ?? 0 )
		),
		'brand_scope'    => sanitize_key( (string) ( $request['brand_scope'] ?? 'zona_b' ) ),
		'waiter_user_id' => $assigned_user_id,
		'waiter_name'    => sanitize_text_field( (string) ( $request['waiter_name'] ?? '' ) ),
		'request_source' => sanitize_key( (string) ( $request['request_source'] ?? 'customer' ) ),
		'global_note'    => sanitize_textarea_field( (string) ( $request['global_note'] ?? '' ) ),
		'client_label'   => sanitize_text_field( (string) ( $request['client_label'] ?? '' ) ),
		'payload'        => is_array( $request['payload'] ?? null ) ? $request['payload'] : [ 'items' => [] ],
		'item_count'     => tavox_menu_api_count_waiter_request_items( $request ),
		'can_claim'      => ! empty( $action_payload['can_claim'] ),
		'can_accept'     => ! empty( $action_payload['can_accept'] ),
		'can_release'    => in_array( $status, [ 'claimed', 'error' ], true ) && ! empty( $action_payload['request_is_mine'] ),
		'primary_action' => sanitize_key( (string) ( $action_payload['primary_action'] ?? 'none' ) ),
		'is_mine'        => ! empty( $action_payload['is_mine'] ),
		'request_is_mine'=> ! empty( $action_payload['request_is_mine'] ),
		'table_availability' => sanitize_key( (string) ( $action_payload['table_availability'] ?? 'available' ) ),
		'action_reason'  => sanitize_text_field( (string) ( $action_payload['action_reason'] ?? '' ) ),
		'availability_reason' => sanitize_text_field( (string) ( $action_payload['table_availability_reason'] ?? '' ) ),
		'managed_by'     => sanitize_text_field( (string) ( $action_payload['managed_by'] ?? '' ) ),
		'can_direct_order' => ! empty( $action_payload['can_direct_order'] ),
		'claimed_at'     => (string) ( $request['claimed_at'] ?? '' ),
		'accepted_at'    => (string) ( $request['accepted_at'] ?? '' ),
		'pushed_at'      => (string) ( $request['pushed_at'] ?? '' ),
		'expires_at'     => (string) ( $request['expires_at'] ?? '' ),
		'created_at'     => (string) ( $request['created_at'] ?? '' ),
		'updated_at'     => (string) ( $request['updated_at'] ?? '' ),
		'error_message'  => (string) ( $request['error_message'] ?? '' ),
	];
}

/**
 * Acepta una solicitud ya tomada y la sube al desk de OpenPOS.
 *
 * @return array<string, mixed>|WP_Error
 */
function tavox_menu_api_accept_waiter_request( int $request_id, WP_User $user ) {
	global $wpdb;

	tavox_menu_api_cleanup_request_states();

	$requests_table = tavox_menu_api_get_table_requests_table_name();
	$row            = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$requests_table} WHERE id = %d LIMIT 1", $request_id ),
		ARRAY_A
	);

	if ( ! is_array( $row ) ) {
		return new WP_Error( 'request_not_found', __( 'Ese pedido ya no está disponible.', 'tavox-menu-api' ), [ 'status' => 404 ] );
	}

	$current_status = sanitize_key( (string) ( $row['status'] ?? '' ) );
	if ( 'pending' === $current_status ) {
		$detail = tavox_menu_api_build_waiter_request_detail_payload( $row, $user );
		if ( empty( $detail['can_accept'] ) ) {
			return new WP_Error(
				'request_not_operable',
				(string) ( $detail['action_reason'] ?? __( 'Primero toma el pedido para continuarlo.', 'tavox-menu-api' ) ),
				[ 'status' => 409 ]
			);
		}

		$claimed = tavox_menu_api_claim_waiter_request( $request_id, $user );
		if ( is_wp_error( $claimed ) ) {
			return $claimed;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$requests_table} WHERE id = %d LIMIT 1", $request_id ),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return new WP_Error( 'request_not_found', __( 'Ese pedido ya no está disponible.', 'tavox-menu-api' ), [ 'status' => 404 ] );
		}

		$current_status = sanitize_key( (string) ( $row['status'] ?? '' ) );
	}

	if ( ! in_array( $current_status, [ 'claimed', 'error' ], true ) || absint( $row['waiter_user_id'] ?? 0 ) !== $user->ID ) {
		return new WP_Error( 'request_not_claimed', __( 'Primero toma el pedido para continuarlo.', 'tavox-menu-api' ), [ 'status' => 409 ] );
	}

	$row['waiter_name']    = tavox_menu_api_get_waiter_staff_name( $user );
	$row['waiter_user_id'] = $user->ID;

	tavox_menu_api_log_operational_event(
		'request_accept_started',
		[
			'request_id'  => $request_id,
			'request_key' => sanitize_key( (string) ( $row['request_key'] ?? '' ) ),
			'table_id'    => absint( $row['table_id'] ?? 0 ),
			'table_type'  => sanitize_key( (string) ( $row['table_type'] ?? 'dine_in' ) ),
			'table_name'  => sanitize_text_field( (string) ( $row['table_name'] ?? '' ) ),
			'desk_ref'    => sanitize_text_field( (string) ( $row['table_key'] ?? '' ) ),
			'waiter_id'   => $user->ID,
			'waiter_name' => tavox_menu_api_get_waiter_staff_name( $user ),
			'status'      => $current_status,
		]
	);

	$pushed = tavox_menu_api_push_request_to_openpos( $row );
	if ( is_wp_error( $pushed ) ) {
		$wpdb->update(
			$requests_table,
			[
				'status'        => 'error',
				'error_message' => $pushed->get_error_message(),
				'updated_at'    => tavox_menu_api_now_mysql(),
			],
			[ 'id' => $request_id ],
			[ '%s', '%s', '%s' ],
			[ '%d' ]
		);

		tavox_menu_api_log_operational_event(
			'openpos_write_unconfirmed' === $pushed->get_error_code()
				? 'request_accept_openpos_write_unconfirmed'
				: 'request_accept_openpos_write_failed',
			[
				'request_id'   => $request_id,
				'request_key'  => sanitize_key( (string) ( $row['request_key'] ?? '' ) ),
				'table_id'     => absint( $row['table_id'] ?? 0 ),
				'table_type'   => sanitize_key( (string) ( $row['table_type'] ?? 'dine_in' ) ),
				'table_name'   => sanitize_text_field( (string) ( $row['table_name'] ?? '' ) ),
				'waiter_id'    => $user->ID,
				'waiter_name'  => tavox_menu_api_get_waiter_staff_name( $user ),
				'error_code'   => $pushed->get_error_code(),
				'error'        => $pushed->get_error_message(),
				'diagnostics'  => is_array( $pushed->get_error_data() ) ? $pushed->get_error_data() : [],
			]
		);

		if ( function_exists( 'tavox_menu_api_publish_realtime_event' ) ) {
			tavox_menu_api_publish_realtime_event(
				[
					'event'   => 'queue.sync',
					'targets' => [ 'scope:queue', 'scope:service' ],
					'meta'    => [
						'request_id' => $request_id,
						'action'     => 'accept_failed',
					],
				]
			);
			tavox_menu_api_publish_realtime_event(
				[
					'event'   => 'notifications.sync',
					'targets' => [ 'scope:service' ],
					'meta'    => [
						'request_id' => $request_id,
						'action'     => 'accept_failed',
					],
				]
			);
		}

		return $pushed;
	}

	$now_mysql = tavox_menu_api_now_mysql();
	$wpdb->update(
		$requests_table,
		[
			'status'       => 'pushed',
			'accepted_at'  => $now_mysql,
			'pushed_at'    => $now_mysql,
			'updated_at'   => $now_mysql,
			'error_message'=> '',
		],
		[ 'id' => $request_id ],
		[ '%s', '%s', '%s', '%s', '%s' ],
		[ '%d' ]
	);

	$row = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$requests_table} WHERE id = %d LIMIT 1", $request_id ),
		ARRAY_A
	);

	$formatted_request = tavox_menu_api_format_request_row( is_array( $row ) ? $row : [] );
	$account_ref       = tavox_menu_api_build_waiter_account_ref(
		(string) ( $formatted_request['table_type'] ?? 'dine_in' ),
		absint( $formatted_request['table_id'] ?? 0 )
	);

	tavox_menu_api_log_operational_event(
		'request_accept_confirmed',
		[
			'request_id'             => absint( $formatted_request['id'] ?? 0 ),
			'request_key'            => sanitize_key( (string) ( $formatted_request['request_key'] ?? '' ) ),
			'table_id'               => absint( $formatted_request['table_id'] ?? 0 ),
			'table_type'             => sanitize_key( (string) ( $formatted_request['table_type'] ?? 'dine_in' ) ),
			'table_name'             => sanitize_text_field( (string) ( $formatted_request['table_name'] ?? '' ) ),
			'waiter_id'              => $user->ID,
			'waiter_name'            => tavox_menu_api_get_waiter_staff_name( $user ),
			'desk_ref'               => sanitize_text_field( (string) ( $pushed['desk_ref'] ?? '' ) ),
			'write_confirmed'        => ! empty( $pushed['write_confirmed'] ),
			'post_write_lines_count' => (int) ( $pushed['post_write_lines_count'] ?? 0 ),
			'post_write_total'       => (float) ( $pushed['post_write_total'] ?? 0 ),
		]
	);

	if ( function_exists( 'tavox_menu_api_publish_realtime_event' ) ) {
		tavox_menu_api_publish_realtime_event(
			[
				'event'   => 'queue.sync',
				'targets' => [ 'scope:queue', 'scope:service' ],
				'meta'    => [
					'request_id' => absint( $formatted_request['id'] ?? 0 ),
					'action'     => 'accepted',
				],
			]
		);
		tavox_menu_api_publish_realtime_event(
			[
				'event'   => 'service.sync',
				'targets' => [ 'scope:service', 'user:' . $user->ID ],
				'meta'    => [
					'request_id' => absint( $formatted_request['id'] ?? 0 ),
					'action'     => 'accepted',
				],
			]
		);
		tavox_menu_api_publish_realtime_event(
			[
				'event'   => 'notifications.sync',
				'targets' => [ 'scope:service', 'user:' . $user->ID ],
				'meta'    => [
					'request_id' => absint( $formatted_request['id'] ?? 0 ),
					'action'     => 'accepted',
				],
			]
		);
	}

	if ( function_exists( 'tavox_menu_api_resolve_waiter_notifications' ) ) {
		tavox_menu_api_resolve_waiter_notifications(
			[
				'event_types'  => [ 'new_request', 'request_claimed' ],
				'request_ids'  => [ absint( $formatted_request['id'] ?? 0 ) ],
				'account_refs' => [ $account_ref ],
			]
		);
	}

	if ( function_exists( 'tavox_menu_api_push_team_notification' ) ) {
		$payload  = json_decode( (string) ( $row['payload'] ?? '' ), true );
		$items    = is_array( $payload['items'] ?? null ) ? $payload['items'] : [];
		$stations = [];

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$stations[] = tavox_menu_api_infer_item_station( $item );
		}

		$stations = array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( $station ) => tavox_menu_api_sanitize_production_station( (string) $station, 'kitchen' ),
						$stations
					)
				)
			)
		);

		foreach ( $stations as $station ) {
			$station_label = tavox_menu_api_get_service_station_label( $station );
			$station_name  = function_exists( 'mb_strtolower' ) ? mb_strtolower( $station_label ) : strtolower( $station_label );
			tavox_menu_api_push_team_notification(
				[
					'type'  => 'request_added',
					'title' => sprintf( 'Nuevo en %s', $station_name ),
					'body'  => sprintf(
						'Hay productos nuevos de %s para preparar.',
						(string) ( $formatted_request['table_name'] ?? 'esta cuenta' )
					),
					'url'   => tavox_menu_api_get_team_frontend_url( tavox_menu_api_get_service_station_frontend_path( $station ) ),
					'tag'   => 'request-added-' . $station . '-' . absint( $formatted_request['id'] ?? 0 ),
					'meta'  => [
						'request_id' => absint( $formatted_request['id'] ?? 0 ),
						'table_name' => (string) ( $formatted_request['table_name'] ?? '' ),
						'station'    => $station,
						'account_ref'=> $account_ref,
					],
				],
				[
					'audiences' => [ $station ],
				]
			);
		}
	}

	return [
		'request' => tavox_menu_api_build_waiter_request_detail_payload( $row, $user ),
		'push'    => $pushed,
	];
}

/**
 * Devuelve la cola abierta para meseros.
 *
 * @return array<string, mixed>
 */
function tavox_menu_api_get_waiter_queue_payload( ?WP_User $current_waiter = null ): array {
	global $wpdb;

	tavox_menu_api_cleanup_request_states();

	$requests_table = tavox_menu_api_get_table_requests_table_name();
	$rows           = $wpdb->get_results(
		"SELECT * FROM {$requests_table}
		WHERE status IN ('pending', 'claimed', 'error')
		ORDER BY FIELD(status, 'pending', 'claimed', 'error'), created_at ASC",
		ARRAY_A
	);

	$latest_rows = $wpdb->get_results(
		"SELECT * FROM {$requests_table}
		WHERE status IN ('pending', 'claimed', 'error', 'pushed', 'delivered', 'expired')
		ORDER BY id DESC",
		ARRAY_A
	);

	$latest_request_map = [];
	foreach ( (array) $latest_rows as $row ) {
		$table_row_key = (string) ( $row['table_type'] ?? 'dine_in' ) . ':' . absint( $row['table_id'] ?? 0 );
		if ( isset( $latest_request_map[ $table_row_key ] ) ) {
			continue;
		}

		$latest_request_map[ $table_row_key ] = tavox_menu_api_format_request_row( $row );
	}

	$items = [];
	foreach ( (array) $rows as $row ) {
		$formatted      = tavox_menu_api_format_request_row( $row );
		$table_row_key  = (string) ( $row['table_type'] ?? 'dine_in' ) . ':' . absint( $row['table_id'] ?? 0 );
		$latest_request = $latest_request_map[ $table_row_key ] ?? $formatted;
		$consumption    = [
			'items_count'  => 0,
			'lines_count'  => 0,
			'total_amount' => 0.0,
			'total_qty'    => 0.0,
			'seller'       => [],
		];

		try {
			$context = tavox_menu_api_get_openpos_table_context_by_identity(
				absint( $row['table_id'] ?? 0 ),
				(string) ( $row['table_type'] ?? 'dine_in' ),
				absint( $row['register_id'] ?? 0 ),
				absint( $row['warehouse_id'] ?? 0 ),
				(string) ( $row['table_name'] ?? '' )
			);
			if ( ! is_wp_error( $context ) && is_array( $context ) ) {
				$consumption = tavox_menu_api_build_table_consumption_summary( $context );
			}
		} catch ( Throwable $error ) {
			tavox_menu_api_log_operational_event(
				'queue_consumption_probe_failed',
				[
					'request_id' => absint( $row['id'] ?? 0 ),
					'table_id'   => absint( $row['table_id'] ?? 0 ),
					'table_type' => sanitize_key( (string) ( $row['table_type'] ?? 'dine_in' ) ),
					'error'      => $error->getMessage(),
				]
			);
		}

		if ( function_exists( 'tavox_menu_api_should_ignore_shadowed_open_request' ) && tavox_menu_api_should_ignore_shadowed_open_request( $formatted, $latest_request, $consumption ) ) {
			continue;
		}

		$items[] = tavox_menu_api_build_waiter_request_detail_payload( $row, $current_waiter );
	}

	return [
		'items' => $items,
	];
}

/**
 * Devuelve el historial operativo reciente de pedidos ya cerrados o vencidos.
 *
 * @return array<string, mixed>
 */
function tavox_menu_api_get_waiter_request_history_payload( int $limit = 24 ): array {
	global $wpdb;

	tavox_menu_api_cleanup_request_states();

	$requests_table = tavox_menu_api_get_table_requests_table_name();
	$limit          = max( 10, min( 80, $limit ) );
	$rows           = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$requests_table}
			WHERE status IN ('expired', 'pushed', 'delivered')
			ORDER BY COALESCE(updated_at, pushed_at, accepted_at, created_at) DESC
			LIMIT %d",
			$limit
		),
		ARRAY_A
	);

	return [
		'items' => array_map( 'tavox_menu_api_format_request_row', is_array( $rows ) ? $rows : [] ),
	];
}

/**
 * Devuelve el cliente visible más útil para la operación.
 */
function tavox_menu_api_get_waiter_customer_label( array $consumption, ?array $latest_request = null, array $pickup = [] ): string {
	$customer_name = sanitize_text_field(
		(string) (
			$consumption['customer']['display_name']
			?? $consumption['customer']['name']
			?? ''
		)
	);
	if ( '' !== $customer_name ) {
		return $customer_name;
	}

	$pickup_name = sanitize_text_field( (string) ( $pickup['customer_name'] ?? '' ) );
	if ( '' !== $pickup_name ) {
		return $pickup_name;
	}

	if ( ! tavox_menu_api_should_surface_latest_request( $latest_request, $consumption ) ) {
		return '';
	}

	return sanitize_text_field( (string) ( $latest_request['client_label'] ?? '' ) );
}

/**
 * Devuelve el dato secundario más útil para identificar a un cliente.
 */
function tavox_menu_api_get_waiter_customer_secondary_label( array $consumption, array $pickup = [] ): string {
	$secondary = sanitize_text_field(
		(string) (
			$consumption['customer']['secondary_label']
			?? ''
		)
	);

	if ( '' !== $secondary ) {
		return $secondary;
	}

	$pickup_phone = sanitize_text_field( (string) ( $pickup['phone'] ?? '' ) );
	if ( '' !== $pickup_phone ) {
		return $pickup_phone;
	}

	return sanitize_email( (string) ( $consumption['customer']['email'] ?? '' ) );
}

/**
 * Resume quiénes figuran atendiendo una cuenta para el modo compartido.
 *
 * @return array<int, string>
 */
function tavox_menu_api_get_waiter_shared_staff_display_names(
	?array $current_request,
	?array $latest_request,
	array $consumption
): array {
	$names = [];

	$candidates = [
		tavox_menu_api_resolve_waiter_staff_name(
			absint( $current_request['waiter_user_id'] ?? 0 ),
			(string) ( $current_request['waiter_name'] ?? '' )
		),
		tavox_menu_api_resolve_waiter_staff_name(
			absint( $consumption['seller']['id'] ?? 0 ),
			(string) ( $consumption['seller']['display_name'] ?? $consumption['seller']['name'] ?? '' )
		),
		tavox_menu_api_resolve_waiter_staff_name(
			absint( $latest_request['waiter_user_id'] ?? 0 ),
			(string) ( $latest_request['waiter_name'] ?? '' )
		),
	];

	foreach ( $candidates as $candidate ) {
		$candidate = trim( sanitize_text_field( (string) $candidate ) );
		if ( '' === $candidate ) {
			continue;
		}

		$key = strtolower( $candidate );
		$names[ $key ] = $candidate;
	}

	return array_values( $names );
}

/**
 * Devuelve un nombre visible para mesa o para llevar.
 */
function tavox_menu_api_get_waiter_display_name( array $table, ?array $context = null ): string {
	$name = sanitize_text_field( (string) ( $context['table_name'] ?? $table['name'] ?? '' ) );
	if ( '' !== $name ) {
		return $name;
	}

	$table_id = absint( $table['id'] ?? 0 );
	$type     = ! empty( $table['dine_type'] ) && 'takeaway' === $table['dine_type'] ? 'takeaway' : 'dine_in';

	if ( 'takeaway' === $type ) {
		return sprintf(
			/* translators: %d takeaway id */
			__( 'Para llevar %d', 'tavox-menu-api' ),
			max( 1, $table_id )
		);
	}

	return sprintf(
		/* translators: %d table id */
		__( 'Mesa %d', 'tavox-menu-api' ),
		max( 1, $table_id )
	);
}

/**
 * Determina si la identidad actual del mesero coincide con un responsable operativo.
 */
function tavox_menu_api_waiter_matches_owner( ?WP_User $current_waiter, int $owner_id = 0, string $owner_name = '' ): bool {
	if ( ! $current_waiter instanceof WP_User ) {
		return false;
	}

	if ( $owner_id > 0 && $owner_id === $current_waiter->ID ) {
		return true;
	}

	$owner_name = strtolower( trim( $owner_name ) );
	if ( '' === $owner_name ) {
		return false;
	}

	foreach ( [ tavox_menu_api_get_waiter_staff_name( $current_waiter ), $current_waiter->display_name, $current_waiter->user_email, $current_waiter->user_login ] as $candidate ) {
		if ( strtolower( trim( (string) $candidate ) ) === $owner_name ) {
			return true;
		}
	}

	return false;
}

/**
 * Convierte una fecha MySQL a timestamp si es válida.
 */
function tavox_menu_api_parse_operational_datetime( string $value ): int {
	$value = trim( $value );
	if ( '' === $value ) {
		return 0;
	}

	$timestamp = strtotime( $value );
	return false === $timestamp ? 0 : (int) $timestamp;
}

/**
 * Decide si el último pedido todavía debe mostrarse como estado actual.
 *
 * @param array<string, mixed>|null $latest_request Último pedido conocido.
 * @param array<string, mixed>|null $consumption    Consumo actual de la mesa.
 */
function tavox_menu_api_should_surface_latest_request( ?array $latest_request, ?array $consumption ): bool {
	if ( ! is_array( $latest_request ) || empty( $latest_request ) ) {
		return false;
	}

	$status = sanitize_key( (string) ( $latest_request['status'] ?? '' ) );
	if ( in_array( $status, [ 'pending', 'claimed', 'error' ], true ) ) {
		return true;
	}

	$lines_count      = absint( $consumption['lines_count'] ?? 0 );
	$items_count      = absint( $consumption['items_count'] ?? 0 );
	$ready_lines      = absint( $consumption['ready_lines'] ?? 0 );
	$pending_lines    = absint( $consumption['pending_lines'] ?? 0 );
	$preparing_lines  = absint( $consumption['preparing_lines'] ?? 0 );
	$delivered_lines  = absint( $consumption['delivered_lines'] ?? 0 );
	$has_visible_live = $lines_count > 0
		|| $items_count > 0
		|| $ready_lines > 0
		|| $pending_lines > 0
		|| $preparing_lines > 0
		|| $delivered_lines > 0
		|| absint( $consumption['seller']['id'] ?? 0 ) > 0
		|| '' !== sanitize_text_field( (string) ( $consumption['seller']['name'] ?? $consumption['seller']['display_name'] ?? '' ) )
		|| '' !== sanitize_text_field( (string) ( $consumption['customer']['name'] ?? $consumption['customer']['display_name'] ?? $consumption['customer']['email'] ?? '' ) )
		|| (float) ( $consumption['total_amount'] ?? 0 ) > 0
		|| (float) ( $consumption['total_qty'] ?? 0 ) > 0;

	if ( $has_visible_live ) {
		return true;
	}

	if ( ! in_array( $status, [ 'pushed', 'delivered' ], true ) ) {
		return false;
	}

	$activity_timestamp = max(
		tavox_menu_api_parse_operational_datetime( (string) ( $latest_request['updated_at'] ?? '' ) ),
		tavox_menu_api_parse_operational_datetime( (string) ( $latest_request['pushed_at'] ?? '' ) ),
		tavox_menu_api_parse_operational_datetime( (string) ( $latest_request['accepted_at'] ?? '' ) ),
		tavox_menu_api_parse_operational_datetime( (string) ( $latest_request['claimed_at'] ?? '' ) ),
		tavox_menu_api_parse_operational_datetime( (string) ( $latest_request['created_at'] ?? '' ) )
	);

	if ( $activity_timestamp < 1 ) {
		return false;
	}

	$desk_timestamp = max(
		absint( $consumption['desk_updated_at'] ?? 0 ),
		absint( $consumption['desk_version'] ?? 0 )
	);

	if ( $has_visible_live && $desk_timestamp > 0 && $desk_timestamp >= $activity_timestamp ) {
		return false;
	}

	return ( time() - $activity_timestamp ) <= 120;
}

/**
 * Ignora solicitudes abiertas antiguas cuando la mesa ya quedó persistida por un request más nuevo.
 *
 * @param array<string, mixed>|null $current_request
 * @param array<string, mixed>|null $latest_request
 * @param array<string, mixed>|null $consumption
 */
if ( ! function_exists( 'tavox_menu_api_should_ignore_shadowed_open_request' ) ) {
	function tavox_menu_api_should_ignore_shadowed_open_request( ?array $current_request, ?array $latest_request, ?array $consumption ): bool {
		if ( ! is_array( $current_request ) || empty( $current_request ) || ! is_array( $latest_request ) || empty( $latest_request ) ) {
			return false;
		}

		$current_status = sanitize_key( (string) ( $current_request['status'] ?? '' ) );
		$latest_status  = sanitize_key( (string) ( $latest_request['status'] ?? '' ) );

		if ( ! in_array( $current_status, [ 'pending', 'claimed', 'error' ], true ) ) {
			return false;
		}

		if ( ! in_array( $latest_status, [ 'pushed', 'delivered' ], true ) ) {
			return false;
		}

		if ( absint( $latest_request['id'] ?? 0 ) <= absint( $current_request['id'] ?? 0 ) ) {
			return false;
		}

		$lines_count  = absint( $consumption['lines_count'] ?? 0 );
		$items_count  = absint( $consumption['items_count'] ?? 0 );
		$total_amount = (float) ( $consumption['total_amount'] ?? 0 );
		$seller_id    = absint( $consumption['seller']['id'] ?? 0 );

		return $lines_count > 0 || $items_count > 0 || $total_amount > 0 || $seller_id > 0;
	}
}

/**
 * Construye el estado operativo visible de una mesa para meseros.
 *
 * @param array<string, mixed>|null $current_request Solicitud Tavox activa.
 * @param array<string, mixed>|null $latest_request  Último movimiento Tavox conocido.
 * @param array<string, mixed>|null $consumption     Consumo actual OpenPOS.
 * @return array{availability:string,availability_reason:string,managed_by:string,can_direct_order:bool}
 */
function tavox_menu_api_resolve_waiter_table_operability(
	bool $has_context,
	?array $current_request,
	?array $latest_request,
	?array $consumption,
	?WP_User $current_waiter = null
): array {
	$shared_tables     = tavox_menu_api_are_shared_tables_enabled();
	$seller            = is_array( $consumption['seller'] ?? null ) ? $consumption['seller'] : [];
	$customer          = is_array( $consumption['customer'] ?? null ) ? $consumption['customer'] : [];
	$seller_id         = absint( $seller['id'] ?? 0 );
	$seller_name       = tavox_menu_api_resolve_waiter_staff_name(
		$seller_id,
		sanitize_text_field( (string) ( $seller['name'] ?? '' ) )
	);
	$request_waiter_id = absint( $current_request['waiter_user_id'] ?? 0 );
	$request_waiter_name = tavox_menu_api_resolve_waiter_staff_name(
		$request_waiter_id,
		sanitize_text_field( (string) ( $current_request['waiter_name'] ?? '' ) )
	);
	$request_status      = sanitize_key( (string) ( $current_request['status'] ?? '' ) );
	$latest_status       = sanitize_key( (string) ( $latest_request['status'] ?? '' ) );
	$latest_waiter_id    = absint( $latest_request['waiter_user_id'] ?? 0 );
	$latest_waiter_name  = tavox_menu_api_resolve_waiter_staff_name(
		$latest_waiter_id,
		sanitize_text_field( (string) ( $latest_request['waiter_name'] ?? '' ) )
	);
	$surface_latest      = tavox_menu_api_should_surface_latest_request( $latest_request, $consumption );
	$has_lines           = (int) ( $consumption['lines_count'] ?? 0 ) > 0;
	$has_items           = (int) ( $consumption['items_count'] ?? 0 ) > 0;
	$has_total           = (float) ( $consumption['total_amount'] ?? 0 ) > 0;
	$has_customer        = '' !== sanitize_text_field( (string) ( $customer['name'] ?? $customer['email'] ?? '' ) );
	$has_live_consumption = $has_lines || $has_items || $has_total || $has_customer || $seller_id > 0 || '' !== $seller_name;
	$managed_by          = $request_waiter_name ?: $seller_name ?: ( $surface_latest ? $latest_waiter_name : '' );

	if ( ! $has_context ) {
		return [
			'availability'        => 'view_only',
			'availability_reason' => __( 'Aquí sólo puedes consultar el estado actual.', 'tavox-menu-api' ),
			'managed_by'          => $managed_by,
			'can_direct_order'    => false,
		];
	}

	if ( 'claimed' === $request_status ) {
		$is_mine = tavox_menu_api_waiter_matches_owner( $current_waiter, $request_waiter_id, $request_waiter_name );

		return [
			'availability'        => $is_mine ? 'mine' : ( $shared_tables ? 'shared' : 'busy' ),
			'availability_reason' => $is_mine
				? __( 'Este pedido ya lo estás atendiendo tú.', 'tavox-menu-api' )
				: ( $shared_tables
					? sprintf(
						/* translators: %s waiter name */
						__( 'Cuenta compartida con %s.', 'tavox-menu-api' ),
						$managed_by ?: __( 'otra persona del equipo', 'tavox-menu-api' )
					)
					: sprintf(
					/* translators: %s waiter name */
					__( 'En este momento lo atiende %s.', 'tavox-menu-api' ),
					$managed_by ?: __( 'otra persona del equipo', 'tavox-menu-api' )
				) ),
			'managed_by'          => $managed_by,
			'can_direct_order'    => $is_mine || $shared_tables,
		];
	}

	if ( in_array( $request_status, [ 'pending', 'error' ], true ) ) {
		return [
			'availability'        => 'pending',
			'availability_reason' => __( 'Todavía hay un pedido por revisar antes de seguir aquí.', 'tavox-menu-api' ),
			'managed_by'          => $managed_by,
			'can_direct_order'    => false,
		];
	}

	if ( $seller_id > 0 || '' !== $seller_name ) {
		$owner_id   = $seller_id > 0 ? $seller_id : $latest_waiter_id;
		$owner_name = $seller_name ?: $latest_waiter_name;
		$is_mine    = tavox_menu_api_waiter_matches_owner( $current_waiter, $owner_id, $owner_name );
		$managed_by = $owner_name;
		$reason     = $is_mine
			? __( 'Esta mesa está a tu cargo.', 'tavox-menu-api' )
			: sprintf(
				/* translators: %s waiter name */
				__( 'En este momento la atiende %s.', 'tavox-menu-api' ),
				$managed_by ?: __( 'otra persona del equipo', 'tavox-menu-api' )
			);

		return [
			'availability'        => $is_mine ? 'mine' : ( $shared_tables ? 'shared' : 'busy' ),
			'availability_reason' => $reason,
			'managed_by'          => $managed_by,
			'can_direct_order'    => $is_mine || $shared_tables,
		];
	}

	if ( $has_live_consumption ) {
		$owner_id   = $latest_waiter_id;
		$owner_name = $latest_waiter_name;
		$is_mine    = tavox_menu_api_waiter_matches_owner( $current_waiter, $owner_id, $owner_name );

		if ( $owner_id > 0 || '' !== $owner_name ) {
			return [
				'availability'        => $is_mine ? 'mine' : ( $shared_tables ? 'shared' : 'busy' ),
				'availability_reason' => $is_mine
					? __( 'Esta mesa sigue a tu cargo.', 'tavox-menu-api' )
					: ( $shared_tables
						? sprintf(
							/* translators: %s waiter name */
							__( 'Cuenta compartida con %s.', 'tavox-menu-api' ),
							$owner_name ?: __( 'otra persona del equipo', 'tavox-menu-api' )
						)
						: sprintf(
						/* translators: %s waiter name */
						__( 'En este momento la atiende %s.', 'tavox-menu-api' ),
						$owner_name ?: __( 'otra persona del equipo', 'tavox-menu-api' )
					) ),
				'managed_by'          => $owner_name,
				'can_direct_order'    => $is_mine || $shared_tables,
			];
		}

		return [
			'availability'        => 'available',
			'availability_reason' => __( 'Puedes seguir sumando productos aquí.', 'tavox-menu-api' ),
			'managed_by'          => '',
			'can_direct_order'    => true,
		];
	}

	if ( $surface_latest && 'pushed' === $latest_status ) {
		$owner_id   = $latest_waiter_id;
		$owner_name = $latest_waiter_name;
		$is_mine    = tavox_menu_api_waiter_matches_owner( $current_waiter, $owner_id, $owner_name );

		if ( $owner_id > 0 || '' !== $owner_name ) {
			return [
				'availability'        => $is_mine ? 'mine' : ( $shared_tables ? 'shared' : 'busy' ),
				'availability_reason' => $is_mine
					? __( 'Tu último pedido ya quedó agregado aquí.', 'tavox-menu-api' )
					: ( $shared_tables
						? sprintf(
							/* translators: %s waiter name */
							__( 'Cuenta compartida con %s.', 'tavox-menu-api' ),
							$owner_name ?: __( 'otra persona del equipo', 'tavox-menu-api' )
						)
						: sprintf(
						/* translators: %s waiter name */
						__( 'El último pedido de esta mesa lo agregó %s.', 'tavox-menu-api' ),
						$owner_name ?: __( 'otra persona del equipo', 'tavox-menu-api' )
					) ),
				'managed_by'          => $owner_name,
				'can_direct_order'    => $is_mine || $shared_tables,
			];
		}
	}

	if ( $surface_latest && 'delivered' === $latest_status ) {
		return [
			'availability'        => 'available',
			'availability_reason' => __( 'El último pedido ya fue entregado. Puedes seguir atendiendo aquí.', 'tavox-menu-api' ),
			'managed_by'          => $latest_waiter_name,
			'can_direct_order'    => true,
		];
	}

	return [
		'availability'        => 'available',
		'availability_reason' => __( 'Lista para tomar un pedido.', 'tavox-menu-api' ),
		'managed_by'          => $managed_by,
		'can_direct_order'    => true,
	];
}

/**
 * Resume el avance visible del servicio por cuenta.
 *
 * @param array<string, mixed>|null $consumption Consumo actual.
 * @return array{pending_count:int,ready_count:int,delivered_count:int,ready_mode:string}
 */
function tavox_menu_api_get_waiter_service_counts( ?array $consumption ): array {
	$ready_count     = absint( $consumption['ready_lines'] ?? 0 );
	$pending_count   = absint( $consumption['pending_lines'] ?? 0 ) + absint( $consumption['preparing_lines'] ?? 0 );
	$delivered_count = absint( $consumption['delivered_lines'] ?? 0 );
	$ready_mode      = 'none';

	if ( $ready_count > 0 && $pending_count > 0 ) {
		$ready_mode = 'partial';
	} elseif ( $ready_count > 0 ) {
		$ready_mode = 'complete';
	}

	return [
		'pending_count'   => $pending_count,
		'ready_count'     => $ready_count,
		'delivered_count' => $delivered_count,
		'ready_mode'      => $ready_mode,
	];
}

/**
 * Resume la etapa visible del servicio para una mesa o pedido para llevar.
 *
 * @param array<string, mixed>|null $current_request Solicitud abierta actual.
 * @param array<string, mixed>|null $latest_request  Último movimiento conocido.
 * @param array<string, mixed>|null $consumption     Consumo actual.
 * @return array{service_stage:string,service_label:string,service_note:string,can_mark_delivered:bool}
 */
function tavox_menu_api_resolve_waiter_service_stage(
	?array $current_request,
	?array $latest_request,
	?array $consumption
): array {
	$current_status  = sanitize_key( (string) ( $current_request['status'] ?? '' ) );
	$latest_status   = sanitize_key( (string) ( $latest_request['status'] ?? '' ) );
	$surface_latest  = tavox_menu_api_should_surface_latest_request( $latest_request, $consumption );
	$service_counts  = tavox_menu_api_get_waiter_service_counts( $consumption );
	$ready_lines     = $service_counts['ready_count'];
	$delivered_lines = $service_counts['delivered_count'];
	$pending_lines   = $service_counts['pending_count'];
	$preparing_lines = absint( $consumption['preparing_lines'] ?? 0 );
	$items_count     = absint( $consumption['items_count'] ?? 0 );

	if ( in_array( $current_status, [ 'pending', 'error' ], true ) ) {
		return [
			'service_stage'      => 'review',
			'service_label'      => __( 'Por revisar', 'tavox-menu-api' ),
			'service_note'       => __( 'Hay un pedido esperando revisión.', 'tavox-menu-api' ),
			'can_mark_delivered' => false,
		];
	}

	if ( 'claimed' === $current_status ) {
		return [
			'service_stage'      => 'working',
			'service_label'      => __( 'En atención', 'tavox-menu-api' ),
			'service_note'       => __( 'El pedido ya está siendo atendido.', 'tavox-menu-api' ),
			'can_mark_delivered' => false,
		];
	}

	if ( $ready_lines > 0 && $pending_lines > 0 ) {
		return [
			'service_stage'      => 'partial_ready',
			'service_label'      => __( 'Listo parcial', 'tavox-menu-api' ),
			'service_note'       => __( 'Ya hay productos listos y otros siguen en preparación.', 'tavox-menu-api' ),
			'can_mark_delivered' => true,
		];
	}

	if ( $ready_lines > 0 ) {
		return [
			'service_stage'      => 'ready',
			'service_label'      => __( 'Listo para entregar', 'tavox-menu-api' ),
			'service_note'       => __( 'Hay productos listos para entregar.', 'tavox-menu-api' ),
			'can_mark_delivered' => true,
		];
	}

	if ( $pending_lines > 0 ) {
		return [
			'service_stage'      => 'working',
			'service_label'      => __( 'En atención', 'tavox-menu-api' ),
			'service_note'       => __( 'Todavía hay productos en preparación.', 'tavox-menu-api' ),
			'can_mark_delivered' => false,
		];
	}

	if ( ( $surface_latest && 'delivered' === $latest_status ) || ( $delivered_lines > 0 && $items_count > 0 && 0 === $ready_lines && 0 === $pending_lines && 0 === $preparing_lines ) ) {
		return [
			'service_stage'      => 'delivered',
			'service_label'      => __( 'Entregado', 'tavox-menu-api' ),
			'service_note'       => __( 'El último pedido ya fue entregado.', 'tavox-menu-api' ),
			'can_mark_delivered' => false,
		];
	}

	if ( ( $surface_latest && 'pushed' === $latest_status ) || $items_count > 0 ) {
		return [
			'service_stage'      => 'added',
			'service_label'      => __( 'Pedido agregado', 'tavox-menu-api' ),
			'service_note'       => __( 'Ya hay productos cargados en esta cuenta.', 'tavox-menu-api' ),
			'can_mark_delivered' => false,
		];
	}

	return [
		'service_stage'      => '',
		'service_label'      => '',
		'service_note'       => '',
		'can_mark_delivered' => false,
	];
}

/**
 * Marca como entregado el último pedido listo de una mesa o pedido para llevar.
 *
 * @return array<string, mixed>|WP_Error
 */
function tavox_menu_api_mark_waiter_table_delivered( string $table_token, WP_User $user ) {
	global $wpdb;

	$table_context = tavox_menu_api_get_openpos_table_context_from_token( $table_token );
	if ( is_wp_error( $table_context ) ) {
		return $table_context;
	}

	$current_request = tavox_menu_api_get_latest_open_table_request(
		absint( $table_context['table_id'] ?? 0 ),
		(string) ( $table_context['table_type'] ?? 'dine_in' )
	);
	$current_request = $current_request ? tavox_menu_api_format_request_row( $current_request ) : null;
	$latest_request  = tavox_menu_api_get_latest_table_request(
		absint( $table_context['table_id'] ?? 0 ),
		(string) ( $table_context['table_type'] ?? 'dine_in' ),
		[ 'pending', 'claimed', 'pushed', 'delivered', 'error' ]
	);
	$latest_request  = $latest_request ? tavox_menu_api_format_request_row( $latest_request ) : null;
	$consumption     = tavox_menu_api_build_table_consumption_summary( $table_context );
	if ( tavox_menu_api_should_ignore_shadowed_open_request( $current_request, $latest_request, $consumption ) ) {
		$current_request = null;
	}
	$operability     = tavox_menu_api_resolve_waiter_table_operability(
		true,
		$current_request,
		$latest_request,
		$consumption,
		$user
	);
	$service_stage   = tavox_menu_api_resolve_waiter_service_stage(
		$current_request,
		$latest_request,
		$consumption
	);

	if ( 'busy' === $operability['availability'] ) {
		return new WP_Error( 'delivery_forbidden', __( 'Esta mesa la atiende otro mesero.', 'tavox-menu-api' ), [ 'status' => 403 ] );
	}

	if ( ! $service_stage['can_mark_delivered'] ) {
		return new WP_Error( 'nothing_ready', __( 'Todavía no hay nada listo para entregar aquí.', 'tavox-menu-api' ), [ 'status' => 409 ] );
	}

	$result = tavox_menu_api_mark_ready_items_delivered(
		$table_context,
		[
			'id'   => $user->ID,
			'name' => tavox_menu_api_get_waiter_staff_name( $user ),
		]
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$request_ids = array_values( array_filter( array_map( 'absint', (array) ( $result['request_ids'] ?? [] ) ) ) );
	$requests_table = tavox_menu_api_get_table_requests_table_name();
	$now_mysql      = tavox_menu_api_now_mysql();

	if ( ! empty( $request_ids ) ) {
		$placeholders = implode( ', ', array_fill( 0, count( $request_ids ), '%d' ) );
		$params       = array_merge(
			[ 'delivered', $now_mysql, $now_mysql ],
			$request_ids,
			[ absint( $table_context['table_id'] ?? 0 ), (string) ( $table_context['table_type'] ?? 'dine_in' ) ]
		);

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$requests_table}
				SET status = %s, updated_at = %s, pushed_at = COALESCE(pushed_at, %s)
				WHERE id IN ({$placeholders}) AND table_id = %d AND table_type = %s",
				$params
			)
		);
	} else {
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$requests_table}
				SET status = 'delivered', updated_at = %s
				WHERE table_id = %d AND table_type = %s AND status = 'pushed'
				ORDER BY id DESC
				LIMIT 1",
				$now_mysql,
				absint( $table_context['table_id'] ?? 0 ),
				(string) ( $table_context['table_type'] ?? 'dine_in' )
			)
		);
	}

	return [
		'ok'            => true,
		'table_name'    => (string) ( $table_context['table_name'] ?? '' ),
		'updated_lines' => absint( $result['updated_lines'] ?? 0 ),
		'request_ids'   => $request_ids,
	];
}

/**
 * Devuelve la superficie de mesas que debe consumir el panel de servicio.
 *
 * Si OpenPOS falla al listar las mesas por su método normal, usa un fallback
 * directo a los posts `_op_table` para no dejar el panel vacío.
 *
 * @return array<int, array<string, mixed>>
 */
function tavox_menu_api_get_waiter_service_tables_source(): array {
	global $op_table;

	$tables    = [];
	$takeaways = [];

	try {
		$tables = (array) $op_table->tables( -1, true );
	} catch ( Throwable $error ) {
		tavox_menu_api_log_operational_event(
			'waiter_tables_dinein_source_failed',
			[
				'error' => $error->getMessage(),
			]
		);
	}

	try {
		$takeaways = (array) $op_table->takeawayTables( -1 );
	} catch ( Throwable $error ) {
		tavox_menu_api_log_operational_event(
			'waiter_tables_takeaway_source_failed',
			[
				'error' => $error->getMessage(),
			]
		);
	}

	$surface = array_merge( $tables, $takeaways );
	if ( ! empty( $surface ) ) {
		return $surface;
	}

	$fallback_posts = get_posts(
		[
			'post_type'   => '_op_table',
			'post_status' => [ 'publish', 'draft' ],
			'numberposts' => -1,
			'order'       => 'ASC',
			'meta_key'    => '_op_table_position',
			'orderby'     => 'meta_value_num',
		]
	);

	if ( empty( $fallback_posts ) ) {
		return [];
	}

	$fallback = [];
	foreach ( (array) $fallback_posts as $post ) {
		if ( ! ( $post instanceof WP_Post ) ) {
			continue;
		}

		$post_id = absint( $post->ID );
		if ( $post_id < 1 ) {
			continue;
		}

		$fallback[] = [
			'id'        => $post_id,
			'name'      => sanitize_text_field( (string) $post->post_title ),
			'warehouse' => absint( get_post_meta( $post_id, '_op_warehouse', true ) ),
			'position'  => (int) get_post_meta( $post_id, '_op_table_position', true ),
			'seat'      => (int) get_post_meta( $post_id, '_op_table_seat', true ),
			'type'      => sanitize_key( (string) get_post_meta( $post_id, '_op_table_type', true ) ) ?: 'default',
			'cost'      => (float) get_post_meta( $post_id, '_op_table_cost', true ),
			'cost_type' => sanitize_key( (string) get_post_meta( $post_id, '_op_table_cost_type', true ) ) ?: 'hour',
			'status'    => (string) $post->post_status,
		];
	}

	tavox_menu_api_log_operational_event(
		'waiter_tables_source_fallback',
		[
			'count' => count( $fallback ),
		]
	);

	return $fallback;
}

/**
 * Construye la lista de mesas/takeaways con su dueño operativo actual.
 *
 * @return array<string, mixed>
 */
function tavox_menu_api_get_waiter_tables_payload( ?WP_User $current_waiter = null ): array {
	global $wpdb, $op_table;

	try {
		tavox_menu_api_boot_openpos_services();
		$shared_tables = tavox_menu_api_are_shared_tables_enabled();

		if (
			! tavox_menu_api_is_openpos_ready() ||
			! isset( $op_table ) ||
			! is_object( $op_table ) ||
			! method_exists( $op_table, 'tables' ) ||
			! method_exists( $op_table, 'takeawayTables' )
		) {
			return [ 'items' => [] ];
		}

		tavox_menu_api_cleanup_request_states();

		$requests_table = tavox_menu_api_get_table_requests_table_name();
		$requests       = $wpdb->get_results(
			"SELECT * FROM {$requests_table}
			WHERE status IN ('pending', 'claimed', 'error', 'pushed', 'delivered', 'expired')
			ORDER BY id DESC",
			ARRAY_A
		);
		$current_request_map = [];
		$latest_request_map  = [];
		foreach ( (array) $requests as $row ) {
			$table_row_key = (string) ( $row['table_type'] ?? 'dine_in' ) . ':' . absint( $row['table_id'] ?? 0 );
			$formatted     = tavox_menu_api_format_request_row( $row );

			if ( ! isset( $latest_request_map[ $table_row_key ] ) ) {
				$latest_request_map[ $table_row_key ] = $formatted;
			}

			if ( in_array( (string) ( $row['status'] ?? '' ), [ 'pending', 'claimed', 'error' ], true ) && ! isset( $current_request_map[ $table_row_key ] ) ) {
				$current_request_map[ $table_row_key ] = $formatted;
			}
		}

		$tables = tavox_menu_api_get_waiter_service_tables_source();

		$items = [];
		foreach ( $tables as $table ) {
			if ( ! is_array( $table ) ) {
				continue;
			}

			try {
				$table_type      = ! empty( $table['dine_type'] ) && 'takeaway' === $table['dine_type'] ? 'takeaway' : 'dine_in';
				$table_id        = absint( $table['id'] ?? 0 );
				$request_key     = $table_type . ':' . $table_id;
				$current_request = $current_request_map[ $request_key ] ?? null;
				$latest_request  = $latest_request_map[ $request_key ] ?? null;

				$context = tavox_menu_api_get_openpos_table_context_by_identity(
					$table_id,
					$table_type,
					absint( $table['register_id'] ?? 0 ),
					absint( $table['warehouse'] ?? 0 ),
					(string) ( $table['name'] ?? '' ),
					$table
				);

				$context     = is_wp_error( $context ) ? null : $context;
				$table_token = is_array( $context ) ? tavox_menu_api_build_table_token( $context ) : '';
				$pickup      = is_array( $context ) ? tavox_menu_api_build_openpos_pickup_summary( (array) ( $context['current_data'] ?? [] ) ) : [];
				$consumption = is_array( $context )
					? tavox_menu_api_build_table_consumption_summary( $context )
					: [
						'items_count'   => 0,
						'lines_count'   => 0,
						'total_amount'  => 0.0,
						'total_qty'     => 0.0,
						'served_qty'    => 0.0,
						'currency_code' => get_woocommerce_currency(),
						'customer'      => [],
						'seller'        => [],
						'items'         => [],
					];

				if ( tavox_menu_api_should_ignore_shadowed_open_request( $current_request, $latest_request, $consumption ) ) {
					$current_request = null;
				}

				$customer_label           = tavox_menu_api_get_waiter_customer_label( $consumption, $latest_request, $pickup );
				$customer_secondary_label = tavox_menu_api_get_waiter_customer_secondary_label( $consumption, $pickup );
				$display_name             = tavox_menu_api_get_waiter_display_name( $table, is_array( $context ) ? $context : null );
				$shared_staff_display_names = tavox_menu_api_get_waiter_shared_staff_display_names(
					$current_request,
					$latest_request,
					$consumption
				);

				$resolved = tavox_menu_api_resolve_waiter_table_operability(
					'' !== $table_token,
					$current_request,
					$latest_request,
					$consumption,
					$current_waiter
				);
				$service_stage  = tavox_menu_api_resolve_waiter_service_stage(
					$current_request,
					$latest_request,
					$consumption
				);
				$service_counts = tavox_menu_api_get_waiter_service_counts( $consumption );

				$items[] = [
					'id'              => $table_id,
					'name'            => $display_name,
					'type'            => $table_type,
					'desk_ref'        => ( 'takeaway' === $table_type ? 'takeaway-' : 'desk-' ) . $table_id,
					'warehouse_id'    => absint( $table['warehouse'] ?? 0 ),
					'register_id'     => is_array( $context ) ? absint( $context['register_id'] ?? 0 ) : 0,
					'table_key'       => is_array( $context ) ? (string) ( $context['key'] ?? '' ) : '',
					'table_token'     => $table_token,
					'can_direct_order'=> (bool) $resolved['can_direct_order'],
					'consumption'     => $consumption,
					'current_request' => $current_request,
					'latest_request'  => $latest_request,
					'pickup'          => $pickup,
					'customer_label'  => $customer_label,
					'customer_display_name' => $customer_label,
					'customer_secondary_label' => $customer_secondary_label,
					'availability'    => (string) $resolved['availability'],
					'availability_reason' => (string) $resolved['availability_reason'],
					'managed_by'      => (string) $resolved['managed_by'],
					'owner_display_name' => (string) $resolved['managed_by'],
					'shared_mode'     => 'shared' === (string) $resolved['availability'],
					'is_shared'       => 'shared' === (string) $resolved['availability'],
					'shared_staff_display_names' => $shared_staff_display_names,
					'shared_tables_enabled' => $shared_tables,
					'service_stage'   => (string) $service_stage['service_stage'],
					'service_label'   => (string) $service_stage['service_label'],
					'service_note'    => (string) $service_stage['service_note'],
					'service_counts'  => $service_counts,
					'pending_count'   => (int) $service_counts['pending_count'],
					'ready_count'     => (int) $service_counts['ready_count'],
					'delivered_count' => (int) $service_counts['delivered_count'],
					'ready_mode'      => (string) $service_counts['ready_mode'],
					'desk_version'    => (int) ( $consumption['desk_version'] ?? 0 ),
					'desk_updated_at' => (int) ( $consumption['desk_updated_at'] ?? 0 ),
					'can_mark_delivered' => ! empty( $service_stage['can_mark_delivered'] ),
				];
			} catch ( Throwable $error ) {
				tavox_menu_api_log_operational_event(
					'waiter_table_row_skipped',
					[
						'table_id'   => absint( $table['id'] ?? 0 ),
						'table_name' => sanitize_text_field( (string) ( $table['name'] ?? '' ) ),
						'table_type' => ! empty( $table['dine_type'] ) && 'takeaway' === $table['dine_type'] ? 'takeaway' : 'dine_in',
						'error'      => $error->getMessage(),
					]
				);
			}
		}

		return [ 'items' => $items ];
	} catch ( Throwable $error ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[Tavox Menu API] waiter tables endpoint error: ' . $error->getMessage() . ' @ ' . $error->getFile() . ':' . $error->getLine() );
		}

		return [ 'items' => [] ];
	}
}

/**
 * Devuelve la vista operativa para cocina, horno o barra.
 *
 * @return array<string, mixed>
 */
function tavox_menu_api_get_waiter_production_payload( string $station, ?WP_User $current_waiter = null ): array {
	$station = tavox_menu_api_sanitize_production_station( $station, 'kitchen' );
	$tables  = tavox_menu_api_get_waiter_tables_payload( $current_waiter );
	$items   = [];

	foreach ( (array) ( $tables['items'] ?? [] ) as $table ) {
		$station_lines = array_values(
			array_filter(
				(array) ( $table['consumption']['items'] ?? [] ),
				static function ( $line ) use ( $station ): bool {
					return is_array( $line ) && $station === sanitize_key( (string) ( $line['station'] ?? 'kitchen' ) );
				}
			)
		);

		if ( empty( $station_lines ) ) {
			continue;
		}

		$pending_count = 0;
		$preparing_count = 0;
		$ready_count   = 0;
		$delivered_count = 0;
		$lots = [];
		foreach ( $station_lines as &$line ) {
			$service_state = sanitize_key( (string) ( $line['service_state'] ?? '' ) );
			$line['can_mark_preparing'] = 'pending' === $service_state;
			$line['can_mark_ready']     = 'preparing' === $service_state;
			if ( 'ready' === $service_state ) {
				$ready_count++;
			} elseif ( 'delivered' === $service_state ) {
				$delivered_count++;
			} elseif ( 'preparing' === $service_state ) {
				$preparing_count++;
			} else {
				$pending_count++;
			}

			$lot_key = sanitize_text_field( (string) ( $line['lot_key'] ?? '' ) );
			if ( '' !== $lot_key ) {
				if ( ! isset( $lots[ $lot_key ] ) ) {
					$lots[ $lot_key ] = [
						'id'              => $lot_key,
						'name'            => sanitize_text_field( (string) ( $line['name'] ?? '' ) ),
						'note'            => sanitize_text_field( (string) ( $line['note'] ?? '' ) ),
						'line_ids'        => [],
						'count'           => 0,
						'pending_count'   => 0,
						'preparing_count' => 0,
						'ready_count'     => 0,
					];
				}

				$lots[ $lot_key ]['line_ids'][] = sanitize_text_field( (string) ( $line['id'] ?? '' ) );
				$lots[ $lot_key ]['count']++;

				if ( 'pending' === $service_state ) {
					$lots[ $lot_key ]['pending_count']++;
				} elseif ( 'preparing' === $service_state ) {
					$lots[ $lot_key ]['preparing_count']++;
				} elseif ( 'ready' === $service_state ) {
					$lots[ $lot_key ]['ready_count']++;
				}
			}
		}
		unset( $line );

		if ( $pending_count < 1 && $preparing_count < 1 && $ready_count < 1 ) {
			continue;
		}

		$ready_mode = 'none';
		$remaining_count = $pending_count + $preparing_count;
		if ( $ready_count > 0 && $remaining_count > 0 ) {
			$ready_mode = 'partial';
		} elseif ( $ready_count > 0 ) {
			$ready_mode = 'complete';
		}

		$table['lines'] = $station_lines;
		$table['summary'] = [
			'pending_count'   => $pending_count,
			'preparing_count' => $preparing_count,
			'ready_count'     => $ready_count,
			'delivered_count' => $delivered_count,
			'ready_mode'      => $ready_mode,
		];
		$table['lots'] = array_values(
			array_filter(
				$lots,
				static fn( array $lot ): bool => absint( $lot['count'] ?? 0 ) > 1
			)
		);
		$table['can_mark_preparing'] = $pending_count > 0;
		$table['can_mark_ready'] = $preparing_count > 0;
		$items[] = $table;
	}

	return [
		'station' => $station,
		'items'   => $items,
	];
}

/**
 * Construye una huella liviana de la vista operativa del equipo.
 *
 * @return array<string, mixed>
 */
function tavox_menu_api_get_waiter_live_scope_state( string $scope, ?WP_User $current_waiter = null ): array {
	$scope    = sanitize_key( $scope );
	$state    = [];
	$items    = [];
	$time_now = time();

	if ( 'queue' === $scope ) {
		$queue = tavox_menu_api_get_waiter_queue_payload( $current_waiter );

		foreach ( (array) ( $queue['items'] ?? [] ) as $request ) {
			$items[] = [
				'id'         => absint( $request['id'] ?? 0 ),
				'table_name' => sanitize_text_field( (string) ( $request['table_name'] ?? '' ) ),
				'status'     => sanitize_key( (string) ( $request['status'] ?? '' ) ),
				'waiter_id'  => absint( $request['waiter_user_id'] ?? 0 ),
				'updated_at' => sanitize_text_field( (string) ( $request['updated_at'] ?? '' ) ),
			];
		}

		$state = [
			'scope'      => 'queue',
			'hash'       => md5( (string) wp_json_encode( $items ) ),
			'count'      => count( $items ),
			'server_now' => $time_now,
		];
	} elseif ( in_array( $scope, tavox_menu_api_get_production_station_values(), true ) ) {
		$production = tavox_menu_api_get_waiter_production_payload( $scope, $current_waiter );

		foreach ( (array) ( $production['items'] ?? [] ) as $card ) {
			$line_state = [];
			foreach ( (array) ( $card['lines'] ?? [] ) as $line ) {
				$line_state[] = [
					'id'               => sanitize_text_field( (string) ( $line['id'] ?? '' ) ),
					'qty'              => (float) ( $line['qty'] ?? 0 ),
					'total'            => (float) ( $line['total'] ?? 0 ),
					'state'            => sanitize_key( (string) ( $line['service_state'] ?? '' ) ),
					'version'          => (int) ( $line['order_time'] ?? 0 ),
					'lot_key'          => sanitize_text_field( (string) ( $line['lot_key'] ?? '' ) ),
					'fulfillment_mode' => tavox_menu_api_sanitize_fulfillment_mode( (string) ( $line['fulfillment_mode'] ?? '' ) ),
					'preparing_started_at' => (int) ( $line['preparing_started_at'] ?? 0 ),
				];
			}

			$items[] = [
				'id'            => absint( $card['id'] ?? 0 ),
				'type'          => sanitize_key( (string) ( $card['type'] ?? 'dine_in' ) ),
				'desk_version'  => (int) ( $card['desk_version'] ?? 0 ),
				'desk_updated'  => (int) ( $card['desk_updated_at'] ?? 0 ),
				'service_stage' => sanitize_key( (string) ( $card['service_stage'] ?? '' ) ),
				'summary'       => [
					'pending'    => absint( $card['summary']['pending_count'] ?? 0 ),
					'preparing'  => absint( $card['summary']['preparing_count'] ?? 0 ),
					'ready'      => absint( $card['summary']['ready_count'] ?? 0 ),
					'delivered'  => absint( $card['summary']['delivered_count'] ?? 0 ),
					'mode'       => sanitize_key( (string) ( $card['summary']['ready_mode'] ?? '' ) ),
				],
				'lines'         => $line_state,
			];
		}

		$state = [
			'scope'      => $scope,
			'hash'       => md5( (string) wp_json_encode( $items ) ),
			'count'      => count( $items ),
			'server_now' => $time_now,
		];
	} else {
		$tables = tavox_menu_api_get_waiter_tables_payload( $current_waiter );

		foreach ( (array) ( $tables['items'] ?? [] ) as $table ) {
			$line_state = [];
			foreach ( (array) ( $table['consumption']['items'] ?? [] ) as $line ) {
				$line_state[] = [
					'id'               => sanitize_text_field( (string) ( $line['id'] ?? '' ) ),
					'qty'              => (float) ( $line['qty'] ?? 0 ),
					'total'            => (float) ( $line['total'] ?? 0 ),
					'state'            => sanitize_key( (string) ( $line['service_state'] ?? '' ) ),
					'station'          => sanitize_key( (string) ( $line['station'] ?? '' ) ),
					'lot_key'          => sanitize_text_field( (string) ( $line['lot_key'] ?? '' ) ),
					'fulfillment_mode' => tavox_menu_api_sanitize_fulfillment_mode( (string) ( $line['fulfillment_mode'] ?? '' ) ),
				];
			}

			$items[] = [
				'id'            => absint( $table['id'] ?? 0 ),
				'type'          => sanitize_key( (string) ( $table['type'] ?? 'dine_in' ) ),
				'desk_version'  => (int) ( $table['desk_version'] ?? 0 ),
				'desk_updated'  => (int) ( $table['desk_updated_at'] ?? 0 ),
				'availability'  => sanitize_key( (string) ( $table['availability'] ?? '' ) ),
				'managed_by'    => sanitize_text_field( (string) ( $table['managed_by'] ?? '' ) ),
				'service_stage' => sanitize_key( (string) ( $table['service_stage'] ?? '' ) ),
				'total'         => (float) ( $table['consumption']['total_amount'] ?? 0 ),
				'lines_count'   => absint( $table['consumption']['lines_count'] ?? 0 ),
				'items_count'   => absint( $table['consumption']['items_count'] ?? 0 ),
				'lines'         => $line_state,
			];
		}

		$state = [
			'scope'      => 'service',
			'hash'       => md5( (string) wp_json_encode( $items ) ),
			'count'      => count( $items ),
			'server_now' => $time_now,
		];
	}

	return $state;
}

/**
 * Emite un evento SSE.
 *
 * @param array<string, mixed> $payload
 */
function tavox_menu_api_emit_waiter_live_event( string $event, array $payload ): void {
	echo 'event: ' . $event . "\n";
	echo 'data: ' . wp_json_encode( $payload ) . "\n\n";

	@ob_flush();
	@flush();
}

/**
 * Stream SSE liviano para sincronizar vistas del equipo sin depender sólo de polling pesado.
 *
 * @return WP_REST_Response|WP_Error
 */
function tavox_menu_api_rest_waiter_live( WP_REST_Request $request ) {
	$raw_token = sanitize_text_field( (string) $request->get_param( 'session_token' ) );
	$session   = tavox_menu_api_require_waiter_session( $request, $raw_token );
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	$scope = sanitize_key( (string) $request->get_param( 'scope' ) );
	if ( ! in_array( $scope, array_merge( [ 'service', 'queue' ], tavox_menu_api_get_production_station_values() ), true ) ) {
		$scope = 'service';
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

		$state = tavox_menu_api_get_waiter_live_scope_state( $scope, $session['user'] );
		$event = $state['hash'] !== $last_hash ? 'sync' : 'ping';
		tavox_menu_api_emit_waiter_live_event( $event, $state );
		$last_hash = (string) $state['hash'];

		if ( 0 === $index ) {
			sleep( 1 );
		}
	}

	exit;
}

/**
 * Valida una sesión de equipo para el sidecar realtime.
 *
 * @return WP_REST_Response|WP_Error
 */
function tavox_menu_api_rest_waiter_realtime_auth( WP_REST_Request $request ) {
	if ( ! tavox_menu_api_is_realtime_enabled() ) {
		return new WP_Error( 'realtime_unavailable', __( 'El realtime externo no está activo.', 'tavox-menu-api' ), [ 'status' => 503 ] );
	}

	$configured_secret = tavox_menu_api_get_realtime_shared_secret();
	$provided_secret   = sanitize_text_field( (string) $request->get_header( 'x-tavox-realtime-secret' ) );

	if ( '' === $configured_secret || '' === $provided_secret || ! hash_equals( $configured_secret, $provided_secret ) ) {
		return new WP_Error( 'realtime_forbidden', __( 'No autorizamos esta conexión realtime.', 'tavox-menu-api' ), [ 'status' => 403 ] );
	}

	$session_token = sanitize_text_field( (string) $request->get_param( 'session_token' ) );
	$session       = tavox_menu_api_require_waiter_session( null, $session_token );
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	return tavox_menu_api_no_store_rest_response(
		[
			'ok'                   => true,
			'session_token'        => $session_token,
			'waiter'               => [
				'id'           => $session['user']->ID,
				'display_name' => tavox_menu_api_get_waiter_staff_name( $session['user'] ),
				'login'        => $session['user']->user_login,
			],
			'shared_tables_enabled'=> tavox_menu_api_are_shared_tables_enabled(),
			'allowed_channels'     => [
				'scope:queue',
				'scope:service',
				'scope:kitchen',
				'scope:bar',
				'scope:horno',
				'user:' . $session['user']->ID,
			],
		]
	);
}

/**
 * Registra rutas REST de meseros.
 */
function tavox_menu_api_register_waiter_routes(): void {
	register_rest_route(
		'tavox/v1',
		'/waiter/live',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'tavox_menu_api_rest_waiter_live',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'tavox/v1',
		'/waiter/login',
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'tavox_menu_api_rest_waiter_login',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'tavox/v1',
		'/realtime/waiter-auth',
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'tavox_menu_api_rest_waiter_realtime_auth',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'tavox/v1',
		'/waiter/heartbeat',
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'tavox_menu_api_rest_waiter_heartbeat',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'tavox/v1',
		'/waiter/logout',
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'tavox_menu_api_rest_waiter_logout',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'tavox/v1',
		'/waiter/queue',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'tavox_menu_api_rest_waiter_queue',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'tavox/v1',
		'/waiter/request-history',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'tavox_menu_api_rest_waiter_request_history',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'tavox/v1',
		'/waiter/request',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'tavox_menu_api_rest_waiter_request',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'tavox/v1',
		'/waiter/request/claim',
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'tavox_menu_api_rest_waiter_claim',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'tavox/v1',
		'/waiter/request/accept',
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'tavox_menu_api_rest_waiter_accept',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'tavox/v1',
		'/waiter/request/release',
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'tavox_menu_api_rest_waiter_release',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'tavox/v1',
		'/waiter/tables',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'tavox_menu_api_rest_waiter_tables',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'tavox/v1',
		'/waiter/direct-order',
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'tavox_menu_api_rest_waiter_direct_order',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'tavox/v1',
		'/waiter/table/deliver-ready',
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'tavox_menu_api_rest_waiter_deliver_ready',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'tavox/v1',
		'/waiter/production',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'tavox_menu_api_rest_waiter_production',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'tavox/v1',
		'/waiter/production/ready',
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'tavox_menu_api_rest_waiter_production_ready',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'tavox/v1',
		'/waiter/production/preparing',
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'tavox_menu_api_rest_waiter_production_preparing',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'tavox/v1',
		'/waiter/table/fulfillment',
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'tavox_menu_api_rest_waiter_table_fulfillment',
			'permission_callback' => '__return_true',
		]
	);
}
add_action( 'rest_api_init', 'tavox_menu_api_register_waiter_routes' );

/**
 * Login de mesero por usuario + PIN.
 *
 * @return WP_REST_Response|WP_Error
 */
function tavox_menu_api_rest_waiter_login( WP_REST_Request $request ) {
	$pin   = preg_replace( '/\D+/', '', (string) $request->get_param( 'pin' ) );
	$login = sanitize_text_field( (string) $request->get_param( 'login' ) );
	$user  = '' !== $login ? tavox_menu_api_find_waiter_user( $login ) : tavox_menu_api_find_waiter_user_by_pin( (string) $pin );

	if ( is_wp_error( $user ) ) {
		return $user;
	}

	if ( ! $user instanceof WP_User || ! tavox_menu_api_user_can_act_as_waiter( $user ) ) {
		return new WP_Error( 'waiter_not_found', __( 'No encontramos un acceso con ese PIN.', 'tavox-menu-api' ), [ 'status' => 404 ] );
	}

	$expected_pin = tavox_menu_api_get_waiter_pin( $user->ID );
	if ( '' === $expected_pin || $expected_pin !== (string) $pin ) {
		return new WP_Error( 'invalid_pin', __( 'El PIN no es correcto.', 'tavox-menu-api' ), [ 'status' => 401 ] );
	}

	$session = tavox_menu_api_create_waiter_session( $user, sanitize_text_field( (string) $request->get_param( 'device_label' ) ) );
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	return tavox_menu_api_no_store_rest_response( $session );
}

/**
 * Heartbeat de mesero.
 *
 * @return WP_REST_Response|WP_Error
 */
function tavox_menu_api_rest_waiter_heartbeat( WP_REST_Request $request ) {
	$session = tavox_menu_api_require_waiter_session( $request );
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	return tavox_menu_api_no_store_rest_response(
		[
			'ok'     => true,
			'session_token' => (string) ( $session['session']['session_token'] ?? '' ),
			'waiter' => [
				'id'           => $session['user']->ID,
				'display_name' => tavox_menu_api_get_waiter_staff_name( $session['user'] ),
				'login'        => $session['user']->user_login,
			],
			'shared_tables_enabled' => tavox_menu_api_are_shared_tables_enabled(),
			'realtime'             => tavox_menu_api_get_waiter_realtime_config(),
		]
	);
}

/**
 * Cierra la sesión del equipo.
 *
 * @return WP_REST_Response|WP_Error
 */
function tavox_menu_api_rest_waiter_logout( WP_REST_Request $request ) {
	global $wpdb;

	$session = tavox_menu_api_require_waiter_session( $request );
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	$sessions_table = tavox_menu_api_get_waiter_sessions_table_name();
	$wpdb->update(
		$sessions_table,
		[
			'status'    => 'inactive',
			'last_seen' => tavox_menu_api_now_mysql(),
		],
		[ 'session_token' => (string) ( $session['session']['session_token'] ?? '' ) ],
		[ '%s', '%s' ],
		[ '%s' ]
	);

	if ( function_exists( 'tavox_menu_api_deactivate_waiter_push_subscription' ) ) {
		tavox_menu_api_deactivate_waiter_push_subscription( (string) ( $session['session']['session_token'] ?? '' ) );
	}

	return rest_ensure_response( [ 'ok' => true ] );
}

/**
 * Cola de solicitudes visibles para meseros.
 *
 * @return WP_REST_Response|WP_Error
 */
function tavox_menu_api_rest_waiter_queue( WP_REST_Request $request ) {
	$session = tavox_menu_api_require_waiter_session( $request );
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	return tavox_menu_api_no_store_rest_response( tavox_menu_api_get_waiter_queue_payload( $session['user'] ) );
}

/**
 * Historial reciente de solicitudes ya cerradas o vencidas.
 *
 * @return WP_REST_Response|WP_Error
 */
function tavox_menu_api_rest_waiter_request_history( WP_REST_Request $request ) {
	$session = tavox_menu_api_require_waiter_session( $request );
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	$limit = absint( $request->get_param( 'limit' ) ?: 24 );

	return tavox_menu_api_no_store_rest_response( tavox_menu_api_get_waiter_request_history_payload( $limit ) );
}

/**
 * Devuelve el detalle actual de una solicitud concreta.
 *
 * @return WP_REST_Response|WP_Error
 */
function tavox_menu_api_rest_waiter_request( WP_REST_Request $request ) {
	$session = tavox_menu_api_require_waiter_session( $request );
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	$request_id = absint( $request->get_param( 'request_id' ) );
	if ( $request_id < 1 ) {
		return new WP_Error( 'request_id_invalid', __( 'Falta identificar el pedido.', 'tavox-menu-api' ), [ 'status' => 400 ] );
	}

	$row = tavox_menu_api_get_request_row_by_id( $request_id );
	if ( ! is_array( $row ) ) {
		return tavox_menu_api_no_store_rest_response(
			[
				'available'   => false,
				'id'          => $request_id,
				'status'      => '',
				'status_label'=> '',
				'item_count'  => 0,
				'payload'     => [ 'items' => [] ],
			]
		);
	}

	return tavox_menu_api_no_store_rest_response(
		tavox_menu_api_build_waiter_request_detail_payload( $row, $session['user'] )
	);
}

/**
 * Claim atómico de una solicitud.
 *
 * @return WP_REST_Response|WP_Error
 */
function tavox_menu_api_rest_waiter_claim( WP_REST_Request $request ) {
	$session = tavox_menu_api_require_waiter_session( $request );
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	$result = tavox_menu_api_claim_waiter_request( absint( $request->get_param( 'request_id' ) ), $session['user'] );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return tavox_menu_api_no_store_rest_response(
		[
			'request' => tavox_menu_api_build_waiter_request_detail_payload( $result, $session['user'] ),
		]
	);
}

/**
 * Acepta y empuja la solicitud al desk real de OpenPOS.
 *
 * @return WP_REST_Response|WP_Error
 */
function tavox_menu_api_rest_waiter_accept( WP_REST_Request $request ) {
	$session = tavox_menu_api_require_waiter_session( $request );
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	$result = tavox_menu_api_accept_waiter_request( absint( $request->get_param( 'request_id' ) ), $session['user'] );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return tavox_menu_api_no_store_rest_response( $result );
}

/**
 * Libera una solicitud tomada por el mismo mesero.
 *
 * @return WP_REST_Response|WP_Error
 */
function tavox_menu_api_rest_waiter_release( WP_REST_Request $request ) {
	global $wpdb;

	$session = tavox_menu_api_require_waiter_session( $request );
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	$request_id     = absint( $request->get_param( 'request_id' ) );
	$requests_table = tavox_menu_api_get_table_requests_table_name();
	$row            = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$requests_table} WHERE id = %d LIMIT 1", $request_id ),
		ARRAY_A
	);

	if ( ! is_array( $row ) ) {
		return new WP_Error( 'request_not_found', __( 'Ese pedido ya no está disponible.', 'tavox-menu-api' ), [ 'status' => 404 ] );
	}

	if ( ! in_array( sanitize_key( (string) ( $row['status'] ?? '' ) ), [ 'claimed', 'error' ], true ) || absint( $row['waiter_user_id'] ?? 0 ) !== $session['user']->ID ) {
		return new WP_Error( 'release_forbidden', __( 'Sólo quien tomó el pedido puede devolverlo.', 'tavox-menu-api' ), [ 'status' => 403 ] );
	}

	$wpdb->update(
		$requests_table,
		[
			'status'         => 'pending',
			'waiter_user_id' => 0,
			'waiter_name'    => '',
			'error_message'  => '',
			'updated_at'     => tavox_menu_api_now_mysql(),
		],
		[ 'id' => $request_id ],
		[ '%s', '%d', '%s', '%s', '%s' ],
		[ '%d' ]
	);

	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$requests_table} SET claimed_at = NULL WHERE id = %d",
			$request_id
		)
	);

	tavox_menu_api_publish_realtime_event(
		[
			'event'   => 'queue.sync',
			'targets' => [ 'scope:queue', 'scope:service' ],
			'meta'    => [
				'request_id' => $request_id,
				'action'     => 'released',
			],
		]
	);
	tavox_menu_api_publish_realtime_event(
		[
			'event'   => 'notifications.sync',
			'targets' => [ 'scope:queue', 'scope:service' ],
			'meta'    => [
				'request_id' => $request_id,
				'action'     => 'released',
			],
		]
	);

	return rest_ensure_response( [ 'ok' => true ] );
}

/**
 * Devuelve mesas + consumo + solicitud visible para el panel de meseros.
 *
 * @return WP_REST_Response|WP_Error
 */
function tavox_menu_api_rest_waiter_tables( WP_REST_Request $request ) {
	$session = tavox_menu_api_require_waiter_session( $request );
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	return tavox_menu_api_no_store_rest_response( tavox_menu_api_get_waiter_tables_payload( $session['user'] ) );
}

/**
 * Vista operativa de cocina o barra.
 *
 * @return WP_REST_Response|WP_Error
 */
function tavox_menu_api_rest_waiter_production( WP_REST_Request $request ) {
	$session = tavox_menu_api_require_waiter_session( $request );
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	$station = (string) $request->get_param( 'station' );
	return tavox_menu_api_no_store_rest_response( tavox_menu_api_get_waiter_production_payload( $station, $session['user'] ) );
}

/**
 * Marca líneas de cocina/barra como en preparación.
 *
 * @return WP_REST_Response|WP_Error
 */
function tavox_menu_api_rest_waiter_production_preparing( WP_REST_Request $request ) {
	$session = tavox_menu_api_require_waiter_session( $request );
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	$payload       = $request->get_json_params();
	$payload       = is_array( $payload ) ? $payload : [];
	$table_token   = (string) ( $payload['table_token'] ?? $request->get_param( 'table_token' ) ?? '' );
	$station       = (string) ( $payload['station'] ?? $request->get_param( 'station' ) ?? '' );
	$mode          = (string) ( $payload['mode'] ?? $request->get_param( 'mode' ) ?? 'all_pending' );
	$line_ids_raw  = $payload['line_ids'] ?? $request->get_param( 'line_ids' ) ?? [];
	$line_ids      = is_array( $line_ids_raw ) ? $line_ids_raw : wp_parse_list( (string) $line_ids_raw );
	$table_context = tavox_menu_api_get_openpos_table_context_from_token( $table_token );
	if ( is_wp_error( $table_context ) ) {
		return $table_context;
	}

	$result = tavox_menu_api_mark_station_items_preparing(
		$table_context,
		$station,
		[
			'id'   => $session['user']->ID,
			'name' => tavox_menu_api_get_waiter_staff_name( $session['user'] ),
		],
		[
			'mode'     => $mode,
			'line_ids' => $line_ids,
		]
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$targets = [ 'scope:service', 'scope:' . sanitize_key( $station ) ];
	tavox_menu_api_publish_realtime_event(
		[
			'event'       => 'service.sync',
			'targets'     => $targets,
			'table_token' => $table_token,
			'scope'       => sanitize_key( $station ),
			'meta'        => [
				'action'        => 'preparing',
				'updated_lines' => absint( $result['updated_lines'] ?? 0 ),
				'line_ids'      => array_values( (array) ( $result['updated_line_ids'] ?? [] ) ),
			],
		]
	);
	tavox_menu_api_publish_realtime_event(
		[
			'event'       => 'production.sync',
			'targets'     => $targets,
			'table_token' => $table_token,
			'scope'       => sanitize_key( $station ),
			'meta'        => [
				'action'        => 'preparing',
				'updated_lines' => absint( $result['updated_lines'] ?? 0 ),
			],
		]
	);

	return rest_ensure_response( $result );
}

/**
 * Ajusta el modo mesa/para llevar de líneas activas.
 *
 * @return WP_REST_Response|WP_Error
 */
function tavox_menu_api_rest_waiter_table_fulfillment( WP_REST_Request $request ) {
	$session = tavox_menu_api_require_waiter_session( $request );
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	$payload           = $request->get_json_params();
	$payload           = is_array( $payload ) ? $payload : [];
	$table_token       = (string) ( $payload['table_token'] ?? $request->get_param( 'table_token' ) ?? '' );
	$fulfillment_mode  = (string) ( $payload['fulfillment_mode'] ?? $request->get_param( 'fulfillment_mode' ) ?? '' );
	$mode              = (string) ( $payload['mode'] ?? $request->get_param( 'mode' ) ?? 'all' );
	$line_ids_raw      = $payload['line_ids'] ?? $request->get_param( 'line_ids' ) ?? [];
	$line_ids          = is_array( $line_ids_raw ) ? $line_ids_raw : wp_parse_list( (string) $line_ids_raw );
	$table_context     = tavox_menu_api_get_openpos_table_context_from_token( $table_token );
	if ( is_wp_error( $table_context ) ) {
		return $table_context;
	}

	$result = tavox_menu_api_update_table_items_fulfillment(
		$table_context,
		$fulfillment_mode,
		[
			'mode'     => $mode,
			'line_ids' => $line_ids,
		]
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$targets = [ 'scope:service' ];
	foreach ( (array) ( $result['affected_stations'] ?? [] ) as $affected_station ) {
		$affected_station = sanitize_key( (string) $affected_station );
		if ( in_array( $affected_station, tavox_menu_api_get_production_station_values(), true ) ) {
			$targets[] = 'scope:' . $affected_station;
		}
	}
	$targets = array_values( array_unique( array_filter( $targets ) ) );

	tavox_menu_api_publish_realtime_event(
		[
			'event'       => 'service.sync',
			'targets'     => $targets,
			'table_token' => $table_token,
			'meta'        => [
				'action'           => 'fulfillment-updated',
				'fulfillment_mode' => tavox_menu_api_sanitize_fulfillment_mode( $fulfillment_mode ),
				'updated_lines'    => absint( $result['updated_lines'] ?? 0 ),
			],
		]
	);
	tavox_menu_api_publish_realtime_event(
		[
			'event'       => 'production.sync',
			'targets'     => $targets,
			'table_token' => $table_token,
			'meta'        => [
				'action'           => 'fulfillment-updated',
				'fulfillment_mode' => tavox_menu_api_sanitize_fulfillment_mode( $fulfillment_mode ),
			],
		]
	);

	return rest_ensure_response( $result );
}

/**
 * Pedido directo desde el menú del mesero a una mesa específica.
 *
 * @return WP_REST_Response|WP_Error
 */
function tavox_menu_api_rest_waiter_direct_order( WP_REST_Request $request ) {
	$session = tavox_menu_api_require_waiter_session( $request );
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	$table_token   = (string) $request->get_param( 'table_token' );
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
		'waiter',
		$session['user']->ID,
		tavox_menu_api_get_waiter_staff_name( $session['user'] )
	);

	if ( is_wp_error( $request_row ) ) {
		return $request_row;
	}

	$claimed = tavox_menu_api_claim_waiter_request( absint( $request_row['id'] ?? 0 ), $session['user'] );
	if ( is_wp_error( $claimed ) ) {
		return $claimed;
	}

	$result = tavox_menu_api_accept_waiter_request( absint( $request_row['id'] ?? 0 ), $session['user'] );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( function_exists( 'tavox_menu_api_push_team_notification' ) ) {
		$refreshed_context = tavox_menu_api_get_openpos_table_context_from_token( $table_token );
		if ( ! is_wp_error( $refreshed_context ) ) {
			$consumption    = tavox_menu_api_build_table_consumption_summary( $refreshed_context );
			$service_counts = tavox_menu_api_get_waiter_service_counts( $consumption );
			$table_name     = (string) ( $refreshed_context['table_name'] ?? 'Esta cuenta' );
			$ready_count    = absint( $service_counts['ready_count'] ?? 0 );
			$pending_count  = absint( $service_counts['pending_count'] ?? 0 );
			$is_complete    = 'complete' === (string) ( $service_counts['ready_mode'] ?? '' );
			$account_ref    = tavox_menu_api_build_waiter_account_ref(
				(string) ( $refreshed_context['table_type'] ?? 'dine_in' ),
				absint( $refreshed_context['table_id'] ?? 0 )
			);

			if ( $ready_count > 0 ) {
				if ( function_exists( 'tavox_menu_api_resolve_waiter_notifications' ) ) {
					tavox_menu_api_resolve_waiter_notifications(
						[
							'event_types'  => [ 'service_partial_ready', 'service_ready' ],
							'account_refs' => [ $account_ref ],
						]
					);
				}

				tavox_menu_api_push_team_notification(
					[
						'type'  => $is_complete ? 'service_ready' : 'service_partial_ready',
						'title' => $is_complete ? 'Listo para entregar' : 'Hay productos listos',
						'body'  => $is_complete
							? sprintf( '%s ya quedó listo para entregar.', $table_name )
							: sprintf( '%1$s tiene %2$d producto(s) listo(s) y %3$d por preparar.', $table_name, $ready_count, $pending_count ),
						'url'   => tavox_menu_api_get_team_frontend_url( '/equipo/servicio' ),
						'tag'   => 'service-ready-' . sanitize_title( $table_name ),
						'meta'  => [
							'table_name'    => $table_name,
							'ready_count'   => $ready_count,
							'pending_count' => $pending_count,
							'account_ref'   => $account_ref,
						],
					],
					[
						'audiences'              => [ 'service' ],
						'target_waiter_user_ids' => tavox_menu_api_get_waiter_owner_user_ids_for_table_context( $refreshed_context ),
					]
				);
			}
		}
	}

	return rest_ensure_response( $result );
}

/**
 * Marca como entregado lo que ya está listo en una mesa o pedido para llevar.
 *
 * @return WP_REST_Response|WP_Error
 */
function tavox_menu_api_rest_waiter_deliver_ready( WP_REST_Request $request ) {
	$session = tavox_menu_api_require_waiter_session( $request );
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	$table_token = (string) $request->get_param( 'table_token' );
	$result      = tavox_menu_api_mark_waiter_table_delivered( $table_token, $session['user'] );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$table_context = tavox_menu_api_get_openpos_table_context_from_token( $table_token );
	if ( ! is_wp_error( $table_context ) && function_exists( 'tavox_menu_api_push_team_notification' ) ) {
		$account_ref = tavox_menu_api_build_waiter_account_ref(
			(string) ( $table_context['table_type'] ?? 'dine_in' ),
			absint( $table_context['table_id'] ?? 0 )
		);
		$table_name = (string) ( $table_context['table_name'] ?? 'Esta cuenta' );

		if ( function_exists( 'tavox_menu_api_resolve_waiter_notifications' ) ) {
			tavox_menu_api_resolve_waiter_notifications(
				[
					'event_types'  => [ 'service_partial_ready', 'service_ready', 'request_added' ],
					'account_refs' => [ $account_ref ],
				]
			);
		}

		tavox_menu_api_push_team_notification(
			[
				'type'  => 'service_delivered',
				'title' => 'Entregado',
				'body'  => sprintf( '%s ya quedó entregado.', $table_name ),
				'url'   => tavox_menu_api_get_team_frontend_url( '/equipo/servicio' ),
				'tag'   => 'service-delivered-' . sanitize_title( $table_name ),
				'meta'  => [
					'table_name'  => $table_name,
					'account_ref' => $account_ref,
				],
			],
			[
				'audiences'              => [ 'service' ],
				'target_waiter_user_ids' => tavox_menu_api_get_waiter_owner_user_ids_for_table_context( $table_context ),
			]
		);
	}

	return rest_ensure_response( $result );
}

/**
 * Marca como listos los productos pendientes de una estación.
 *
 * @return WP_REST_Response|WP_Error
 */
function tavox_menu_api_rest_waiter_production_ready( WP_REST_Request $request ) {
	$session = tavox_menu_api_require_waiter_session( $request );
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	$payload      = $request->get_json_params();
	$payload      = is_array( $payload ) ? $payload : [];
	$table_token  = (string) ( $payload['table_token'] ?? $request->get_param( 'table_token' ) ?? '' );
	$station      = (string) ( $payload['station'] ?? $request->get_param( 'station' ) ?? '' );
	$mode         = (string) ( $payload['mode'] ?? $request->get_param( 'mode' ) ?? 'all_pending' );
	$line_ids_raw = $payload['line_ids'] ?? $request->get_param( 'line_ids' ) ?? [];
	$line_ids     = is_array( $line_ids_raw ) ? $line_ids_raw : wp_parse_list( (string) $line_ids_raw );
	$table_context = tavox_menu_api_get_openpos_table_context_from_token( $table_token );
	if ( is_wp_error( $table_context ) ) {
		return $table_context;
	}

	$result = tavox_menu_api_mark_station_items_ready(
		$table_context,
		$station,
		[
			'id'   => $session['user']->ID,
			'name' => tavox_menu_api_get_waiter_staff_name( $session['user'] ),
		],
		[
			'mode'     => $mode,
			'line_ids' => $line_ids,
		]
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$realtime_targets = [ 'scope:service', 'scope:' . sanitize_key( $station ) ];
	tavox_menu_api_publish_realtime_event(
		[
			'event'       => 'service.sync',
			'targets'     => $realtime_targets,
			'table_token' => $table_token,
			'scope'       => sanitize_key( $station ),
			'meta'        => [
				'action'        => 'ready',
				'updated_lines' => absint( $result['updated_lines'] ?? 0 ),
				'line_ids'      => array_values( (array) ( $result['updated_line_ids'] ?? [] ) ),
			],
		]
	);
	tavox_menu_api_publish_realtime_event(
		[
			'event'       => 'production.sync',
			'targets'     => $realtime_targets,
			'table_token' => $table_token,
			'scope'       => sanitize_key( $station ),
			'meta'        => [
				'action'        => 'ready',
				'updated_lines' => absint( $result['updated_lines'] ?? 0 ),
			],
		]
	);

	$refreshed_context = tavox_menu_api_get_openpos_table_context_from_token( $table_token );
	if ( ! is_wp_error( $refreshed_context ) && function_exists( 'tavox_menu_api_push_team_notification' ) ) {
		$consumption    = tavox_menu_api_build_table_consumption_summary( $refreshed_context );
		$service_counts = tavox_menu_api_get_waiter_service_counts( $consumption );
		$ready_count    = absint( $service_counts['ready_count'] ?? 0 );
		$pending_count  = absint( $service_counts['pending_count'] ?? 0 );
		$ready_mode     = (string) ( $service_counts['ready_mode'] ?? 'none' );
		$table_name     = (string) ( $refreshed_context['table_name'] ?? 'Esta cuenta' );
		$account_ref    = tavox_menu_api_build_waiter_account_ref(
			(string) ( $refreshed_context['table_type'] ?? 'dine_in' ),
			absint( $refreshed_context['table_id'] ?? 0 )
		);
		$station_pending_count = 0;
		$updated_line_ids      = array_values(
			array_filter(
				array_map(
					static fn( $value ): string => sanitize_text_field( (string) $value ),
					(array) ( $result['updated_line_ids'] ?? [] )
				)
			)
		);
		$ready_line_names = [];

		foreach ( (array) ( $consumption['items'] ?? [] ) as $line ) {
			if ( ! is_array( $line ) || sanitize_key( (string) ( $line['station'] ?? '' ) ) !== sanitize_key( $station ) ) {
				continue;
			}

			if ( in_array( sanitize_key( (string) ( $line['service_state'] ?? '' ) ), [ 'pending', 'preparing' ], true ) ) {
				$station_pending_count++;
			}

			if ( ! empty( $updated_line_ids ) && in_array( sanitize_text_field( (string) ( $line['id'] ?? '' ) ), $updated_line_ids, true ) ) {
				$ready_line_names[] = sanitize_text_field( (string) ( $line['display_name'] ?? $line['name'] ?? '' ) );
			}
		}

		$ready_line_names = array_values( array_unique( array_filter( $ready_line_names ) ) );
		$station_label    = function_exists( 'mb_strtolower' )
			? mb_strtolower( tavox_menu_api_get_service_station_label( $station ) )
			: strtolower( tavox_menu_api_get_service_station_label( $station ) );
		$first_ready_name = $ready_line_names[0] ?? '';

		if ( $station_pending_count < 1 && function_exists( 'tavox_menu_api_resolve_waiter_notifications' ) ) {
			tavox_menu_api_resolve_waiter_notifications(
				[
					'event_types'  => [ 'request_added' ],
					'account_refs' => [ $account_ref ],
					'stations'     => [ sanitize_key( $station ) ],
				]
			);
		}

		if ( $ready_count > 0 ) {
			if ( function_exists( 'tavox_menu_api_resolve_waiter_notifications' ) ) {
				tavox_menu_api_resolve_waiter_notifications(
					[
						'event_types'  => [ 'service_partial_ready', 'service_ready' ],
						'account_refs' => [ $account_ref ],
					]
				);
			}

			tavox_menu_api_push_team_notification(
				[
					'type'  => 'complete' === $ready_mode ? 'service_ready' : 'service_partial_ready',
					'title' => 'complete' === $ready_mode ? 'Listo para entregar' : 'Hay productos listos',
					'body'  => $first_ready_name
						? sprintf( '%1$s · %2$s listo en %3$s.', $table_name, $first_ready_name, $station_label )
						: ( 'complete' === $ready_mode
							? sprintf( '%s ya quedó listo para entregar.', $table_name )
							: sprintf( '%1$s tiene %2$d producto(s) listo(s) y %3$d por preparar.', $table_name, $ready_count, $pending_count ) ),
					'url'   => tavox_menu_api_get_team_frontend_url( '/equipo/servicio' ),
					'tag'   => 'service-ready-' . sanitize_title( $table_name ),
					'meta'  => [
						'table_name'    => $table_name,
						'ready_count'   => $ready_count,
						'pending_count' => $pending_count,
						'account_ref'   => $account_ref,
						'station'       => sanitize_key( $station ),
						'line_names'    => $ready_line_names,
					],
				],
				[
					'audiences'              => [ 'service' ],
					'target_waiter_user_ids' => tavox_menu_api_get_waiter_owner_user_ids_for_table_context( $refreshed_context ),
				]
			);
		}
	}

	return rest_ensure_response( $result );
}
