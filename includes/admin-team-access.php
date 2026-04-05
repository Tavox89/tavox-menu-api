<?php

defined( 'ABSPATH' ) || exit;

/**
 * Devuelve el payload admin de un usuario del equipo.
 *
 * @return array<string, mixed>
 */
function tavox_menu_api_get_waiter_access_admin_user_payload( WP_User $user ): array {
	$explicit_scopes      = tavox_menu_api_normalize_waiter_access_scopes(
		get_user_meta( $user->ID, '_tavox_waiter_access_scopes', true ),
		false
	);
	$managed_enabled      = ! empty( get_user_meta( $user->ID, '_tavox_waiter_enabled', true ) );
	$implicit_full_access = ! $managed_enabled && empty( $explicit_scopes ) && ( user_can( $user, 'tavox_waiter' ) || user_can( $user, 'manage_woocommerce' ) );
	$scopes               = ! empty( $explicit_scopes )
		? $explicit_scopes
		: ( $managed_enabled ? tavox_menu_api_get_waiter_access_scopes( $user ) : [] );

	return [
		'id'                   => $user->ID,
		'display_name'         => tavox_menu_api_get_waiter_staff_name( $user ),
		'login'                => (string) $user->user_login,
		'email'                => (string) $user->user_email,
		'enabled'              => $managed_enabled,
		'pin'                  => tavox_menu_api_get_waiter_pin( $user->ID ),
		'scopes'               => $scopes,
		'implicit_full_access' => $implicit_full_access,
	];
}

/**
 * Lista los usuarios ya gestionados desde Tavox.
 *
 * @return array<int, WP_User>
 */
function tavox_menu_api_get_managed_waiter_access_users(): array {
	$users = get_users(
		[
			'orderby'     => 'display_name',
			'order'       => 'ASC',
			'number'      => 500,
			'count_total' => false,
		]
	);

	return array_values(
		array_filter(
			(array) $users,
			static function ( $user ): bool {
				if ( ! $user instanceof WP_User ) {
					return false;
				}

				$user_id = $user->ID;
				$pin     = trim( (string) get_user_meta( $user_id, '_tavox_waiter_pin', true ) );
				$scopes  = tavox_menu_api_normalize_waiter_access_scopes(
					get_user_meta( $user_id, '_tavox_waiter_access_scopes', true ),
					false
				);

				return ! empty( get_user_meta( $user_id, '_tavox_waiter_enabled', true ) )
					|| '' !== $pin
					|| ! empty( $scopes );
			}
		)
	);
}

/**
 * Determina si un PIN entra en conflicto con otra cuenta.
 */
function tavox_menu_api_waiter_pin_conflicts( int $user_id, string $pin ): bool {
	$pin = preg_replace( '/\D+/', '', trim( $pin ) );
	if ( '' === $pin ) {
		return false;
	}

	foreach ( [ '_tavox_waiter_pin', '_op_pin' ] as $meta_key ) {
		$user_ids = get_users(
			[
				'meta_key'   => $meta_key,
				'meta_value' => $pin,
				'fields'     => 'ids',
				'number'     => 10,
			]
		);

		foreach ( (array) $user_ids as $candidate_id ) {
			if ( absint( $candidate_id ) !== $user_id ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Renderiza el módulo de accesos del equipo dentro de ajustes.
 */
function tavox_menu_api_render_team_access_module(): void {
	wp_register_script(
		'tavox-menu-api-team-access-admin',
		TAVOX_MENU_API_URL . 'assets/team-access-admin.js',
		[ 'jquery' ],
		filemtime( TAVOX_MENU_API_PATH . 'assets/team-access-admin.js' ),
		true
	);

	$scope_choices = [];
	foreach ( tavox_menu_api_get_waiter_access_scope_definitions() as $scope => $definition ) {
		$scope_choices[] = [
			'id'    => $scope,
			'label' => (string) ( $definition['label'] ?? $scope ),
			'path'  => (string) ( $definition['path'] ?? '/equipo' ),
		];
	}

	wp_localize_script(
		'tavox-menu-api-team-access-admin',
		'tavoxTeamAccess',
		[
			'nonce'        => wp_create_nonce( 'tavox_waiter_access_admin' ),
			'scopeChoices' => $scope_choices,
			'messages'     => [
				'loadError'      => __( 'No se pudo cargar la configuración del equipo.', 'tavox-menu-api' ),
				'searchEmpty'    => __( 'Escribe al menos 2 caracteres para buscar.', 'tavox-menu-api' ),
				'searchNoResults'=> __( 'No encontramos usuarios con ese criterio.', 'tavox-menu-api' ),
				'saveSuccess'    => __( 'Accesos del equipo actualizados.', 'tavox-menu-api' ),
				'saveError'      => __( 'No se pudieron guardar los accesos del equipo.', 'tavox-menu-api' ),
				'emptyState'     => __( 'Todavía no hay accesos del equipo configurados.', 'tavox-menu-api' ),
				'addAction'      => __( 'Agregar', 'tavox-menu-api' ),
				'removeAction'   => __( 'Quitar', 'tavox-menu-api' ),
			],
		]
	);
	wp_enqueue_script( 'tavox-menu-api-team-access-admin' );

	echo '<hr style="margin:28px 0 24px;" />';
	echo '<section id="tavox-team-access-module" style="max-width:980px;">';
	echo '<h2>' . esc_html__( 'Accesos del equipo por PIN', 'tavox-menu-api' ) . '</h2>';
	echo '<p>' . esc_html__( 'Asigna usuarios existentes de WordPress al panel operativo sin darles acceso completo al POS. Puedes definir PIN y escoger exactamente qué pantallas puede abrir cada persona.', 'tavox-menu-api' ) . '</p>';
	echo '<p class="description">' . esc_html__( 'Pensado para cocina, barra, horno o pantallas de servicio sin crear protocolos completos dentro de OpenPOS.', 'tavox-menu-api' ) . '</p>';

	echo '<div style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-start; margin:18px 0;">';
	echo '<input id="tavox-team-user-search" type="search" class="regular-text" placeholder="' . esc_attr__( 'Busca por nombre, login o correo', 'tavox-menu-api' ) . '" style="min-width:280px;" />';
	echo '<button id="tavox-team-user-search-button" class="button button-secondary">' . esc_html__( 'Buscar usuario', 'tavox-menu-api' ) . '</button>';
	echo '</div>';

	echo '<div id="tavox-team-user-search-results" style="display:grid; gap:8px; margin:0 0 18px;"></div>';

	echo '<table class="widefat striped" style="max-width:980px;">';
	echo '<thead><tr>';
	echo '<th style="width:240px;">' . esc_html__( 'Usuario', 'tavox-menu-api' ) . '</th>';
	echo '<th style="width:150px;">' . esc_html__( 'PIN', 'tavox-menu-api' ) . '</th>';
	echo '<th>' . esc_html__( 'Pantallas permitidas', 'tavox-menu-api' ) . '</th>';
	echo '<th style="width:90px; text-align:center;">' . esc_html__( 'Activo', 'tavox-menu-api' ) . '</th>';
	echo '<th style="width:90px;">' . esc_html__( 'Acción', 'tavox-menu-api' ) . '</th>';
	echo '</tr></thead>';
	echo '<tbody id="tavox-team-access-table"></tbody>';
	echo '</table>';
	echo '<p style="margin-top:14px;"><button id="tavox-save-team-access" class="button button-primary">' . esc_html__( 'Guardar accesos del equipo', 'tavox-menu-api' ) . '</button></p>';
	echo '</section>';
}

/**
 * AJAX: devuelve usuarios ya configurados.
 */
function tavox_menu_api_get_waiter_access_users(): void {
	check_ajax_referer( 'tavox_waiter_access_admin', 'nonce' );

	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( [ 'message' => __( 'Permisos insuficientes.', 'tavox-menu-api' ) ], 403 );
	}

	$items = array_map(
		'tavox_menu_api_get_waiter_access_admin_user_payload',
		tavox_menu_api_get_managed_waiter_access_users()
	);

	wp_send_json_success( $items );
}
add_action( 'wp_ajax_tavox_get_waiter_access_users', 'tavox_menu_api_get_waiter_access_users' );

/**
 * AJAX: busca usuarios para asignar acceso operativo.
 */
function tavox_menu_api_search_waiter_access_users(): void {
	check_ajax_referer( 'tavox_waiter_access_admin', 'nonce' );

	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( [ 'message' => __( 'Permisos insuficientes.', 'tavox-menu-api' ) ], 403 );
	}

	$term = sanitize_text_field( (string) ( $_POST['term'] ?? '' ) );
	if ( strlen( $term ) < 2 ) {
		wp_send_json_success( [] );
	}

	$users = get_users(
		[
			'search'         => '*' . esc_attr( $term ) . '*',
			'search_columns' => [ 'user_login', 'user_email', 'display_name' ],
			'number'         => 20,
			'orderby'        => 'display_name',
			'order'          => 'ASC',
			'count_total'    => false,
		]
	);

	$items = array_map(
		'tavox_menu_api_get_waiter_access_admin_user_payload',
		array_values(
			array_filter(
				(array) $users,
				static fn( $user ): bool => $user instanceof WP_User
			)
		)
	);

	wp_send_json_success( $items );
}
add_action( 'wp_ajax_tavox_search_waiter_access_users', 'tavox_menu_api_search_waiter_access_users' );

/**
 * AJAX: guarda accesos operativos por usuario.
 */
function tavox_menu_api_save_waiter_access_users(): void {
	check_ajax_referer( 'tavox_waiter_access_admin', 'nonce' );

	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( [ 'message' => __( 'Permisos insuficientes.', 'tavox-menu-api' ) ], 403 );
	}

	$data = isset( $_POST['data'] ) ? wp_unslash( (string) $_POST['data'] ) : '';
	$list = json_decode( $data, true );
	if ( ! is_array( $list ) ) {
		wp_send_json_error( [ 'message' => __( 'Datos inválidos.', 'tavox-menu-api' ) ], 400 );
	}

	$submitted_ids = [];
	$seen_pins     = [];

	foreach ( $list as $item ) {
		$user_id = absint( $item['id'] ?? 0 );
		$user    = get_user_by( 'id', $user_id );
		if ( ! $user instanceof WP_User ) {
			continue;
		}

		$enabled = ! empty( $item['enabled'] );
		$pin     = preg_replace( '/\D+/', '', (string) ( $item['pin'] ?? '' ) );
		$scopes  = tavox_menu_api_normalize_waiter_access_scopes( $item['scopes'] ?? [], false );

		if ( $enabled && empty( $scopes ) ) {
			wp_send_json_error(
				[
					'message' => sprintf(
						/* translators: %s usuario */
						__( 'Debes indicar al menos una pantalla para %s.', 'tavox-menu-api' ),
						tavox_menu_api_get_waiter_staff_name( $user )
					),
				],
				400
			);
		}

		$effective_pin = '' !== $pin ? $pin : preg_replace( '/\D+/', '', (string) get_user_meta( $user_id, '_op_pin', true ) );
		if ( $enabled && '' === $effective_pin ) {
			wp_send_json_error(
				[
					'message' => sprintf(
						/* translators: %s usuario */
						__( 'Debes asignar un PIN para %s.', 'tavox-menu-api' ),
						tavox_menu_api_get_waiter_staff_name( $user )
					),
				],
				400
			);
		}

		if ( $enabled && '' !== $effective_pin ) {
			if ( isset( $seen_pins[ $effective_pin ] ) && $seen_pins[ $effective_pin ] !== $user_id ) {
				wp_send_json_error(
					[
						'message' => sprintf(
							/* translators: %s PIN duplicado */
							__( 'El PIN %s está repetido dentro de la lista.', 'tavox-menu-api' ),
							$effective_pin
						),
					],
					409
				);
			}

			if ( tavox_menu_api_waiter_pin_conflicts( $user_id, $effective_pin ) ) {
				wp_send_json_error(
					[
						'message' => sprintf(
							/* translators: 1: pin 2: usuario */
							__( 'El PIN %1$s ya existe en otra cuenta. Revísalo antes de guardar a %2$s.', 'tavox-menu-api' ),
							$effective_pin,
							tavox_menu_api_get_waiter_staff_name( $user )
						),
					],
					409
				);
			}

			$seen_pins[ $effective_pin ] = $user_id;
		}

		update_user_meta( $user_id, '_tavox_waiter_enabled', $enabled ? 1 : 0 );
		update_user_meta( $user_id, '_tavox_waiter_pin', $pin );

		if ( ! empty( $scopes ) ) {
			update_user_meta( $user_id, '_tavox_waiter_access_scopes', $scopes );
		} else {
			delete_user_meta( $user_id, '_tavox_waiter_access_scopes' );
		}

		$submitted_ids[ $user_id ] = $user_id;
	}

	foreach ( tavox_menu_api_get_managed_waiter_access_users() as $managed_user ) {
		if ( isset( $submitted_ids[ $managed_user->ID ] ) ) {
			continue;
		}

		update_user_meta( $managed_user->ID, '_tavox_waiter_enabled', 0 );
		delete_user_meta( $managed_user->ID, '_tavox_waiter_access_scopes' );
	}

	tavox_menu_api_bump_cache_version();

	$items = array_map(
		'tavox_menu_api_get_waiter_access_admin_user_payload',
		tavox_menu_api_get_managed_waiter_access_users()
	);

	wp_send_json_success(
		[
			'message' => __( 'Accesos del equipo actualizados.', 'tavox-menu-api' ),
			'items'   => $items,
		]
	);
}
add_action( 'wp_ajax_tavox_save_waiter_access_users', 'tavox_menu_api_save_waiter_access_users' );
