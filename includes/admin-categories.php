<?php

defined( 'ABSPATH' ) || exit;

/**
 * Renderiza la página de categorías.
 */
function tavox_menu_api_render_categories_page(): void {
	wp_enqueue_script( 'jquery-ui-sortable' );
	wp_enqueue_script( 'wp-util' );
	wp_register_script(
		'tavox-menu-api-admin',
		TAVOX_MENU_API_URL . 'assets/admin.js',
		[ 'jquery', 'wp-util', 'jquery-ui-sortable' ],
		filemtime( TAVOX_MENU_API_PATH . 'assets/admin.js' ),
		true
	);
	wp_localize_script(
		'tavox-menu-api-admin',
		'tavoxMenu',
		[
			'nonce'    => wp_create_nonce( 'tavox_save_cats' ),
			'config'   => tavox_menu_api_get_category_config(),
			'messages' => [
				'saveSuccess' => __( 'Categorías actualizadas.', 'tavox-menu-api' ),
				'saveError'   => __( 'No se pudieron guardar las categorías.', 'tavox-menu-api' ),
			],
			'labels'   => [
				'aliasesPlaceholder' => __( 'Ej: asado, parrilla, grill', 'tavox-menu-api' ),
				'aliasesHelp'        => __( 'Separadas por coma. Sirven para coincidencias del buscador.', 'tavox-menu-api' ),
				'scopeLabel'         => __( 'Área visual', 'tavox-menu-api' ),
				'scopeHelp'          => __( 'Define si la categoría pertenece a Zona B, ISOLA o es común para ambas.', 'tavox-menu-api' ),
				'scopeZonaB'         => __( 'Zona B', 'tavox-menu-api' ),
				'scopeIsola'         => __( 'ISOLA', 'tavox-menu-api' ),
				'scopeCommon'        => __( 'Común', 'tavox-menu-api' ),
				'stationLabel'       => __( 'Estación', 'tavox-menu-api' ),
				'stationHelp'        => __( 'Úsalo para orientar el menú moderno cuando el producto no tenga área operativa marcada. Si no defines nada, el sistema lo decide automáticamente.', 'tavox-menu-api' ),
				'stationAuto'        => __( 'Automático', 'tavox-menu-api' ),
				'stationKitchen'     => __( 'Cocina', 'tavox-menu-api' ),
				'stationBar'         => __( 'Barra', 'tavox-menu-api' ),
				'stationHorno'       => __( 'Horno', 'tavox-menu-api' ),
			],
		]
	);
	wp_enqueue_script( 'tavox-menu-api-admin' );

	echo '<div class="wrap">';
	echo '<h1>' . esc_html__( 'Categorías del menú', 'tavox-menu-api' ) . '</h1>';
	echo '<p>' . esc_html__( 'Ordena las categorías visibles del menú. Esta configuración controla la barra superior, el agrupado principal del frontend y las coincidencias del buscador.', 'tavox-menu-api' ) . '</p>';
	echo '<table class="widefat fixed striped">';
	echo '<thead><tr>';
	echo '<th style="width:40px;">' . esc_html__( 'Orden', 'tavox-menu-api' ) . '</th>';
	echo '<th>' . esc_html__( 'Categoría', 'tavox-menu-api' ) . '</th>';
	echo '<th>' . esc_html__( 'Coincidencias del buscador', 'tavox-menu-api' ) . '</th>';
	echo '<th style="width:170px;">' . esc_html__( 'Área visual', 'tavox-menu-api' ) . '</th>';
	echo '<th style="width:180px;">' . esc_html__( 'Estación', 'tavox-menu-api' ) . '</th>';
	echo '<th style="width:120px;">' . esc_html__( 'Visible', 'tavox-menu-api' ) . '</th>';
	echo '</tr></thead>';
	echo '<tbody id="tavox-cat-table"></tbody>';
	echo '</table>';
	echo '<p style="margin-top:16px;"><button id="tavox-save-cats" class="button button-primary">' . esc_html__( 'Guardar categorías', 'tavox-menu-api' ) . '</button></p>';
	echo '</div>';
}

/**
 * Guarda la configuración de categorías.
 */
function tavox_menu_api_save_cats(): void {
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'tavox_save_cats' ) ) {
		wp_send_json_error( [ 'message' => __( 'Nonce inválido.', 'tavox-menu-api' ) ], 403 );
	}

	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( [ 'message' => __( 'Permisos insuficientes.', 'tavox-menu-api' ) ], 403 );
	}

	$data = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : '';
	$list = json_decode( $data, true );
	if ( ! is_array( $list ) ) {
		wp_send_json_error( [ 'message' => __( 'Datos inválidos.', 'tavox-menu-api' ) ], 400 );
	}

	$sanitized = [];
	$order     = 1;

	foreach ( $list as $item ) {
		$category_id = absint( $item['id'] ?? 0 );
		if ( $category_id <= 0 ) {
			continue;
		}

		$sanitized[] = [
			'id'      => $category_id,
			'enabled' => ! empty( $item['enabled'] ),
			'order'   => $order,
			'aliases' => tavox_menu_api_parse_search_aliases( $item['aliases'] ?? [] ),
			'menu_scope' => tavox_menu_api_sanitize_menu_scope( (string) ( $item['menu_scope'] ?? 'zona_b' ) ),
			'service_station' => tavox_menu_api_sanitize_service_station( (string) ( $item['service_station'] ?? 'auto' ) ),
		];
		$order++;
	}

	update_option( 'tavox_menu_cats', $sanitized, false );
	tavox_menu_api_bump_cache_version();

	wp_send_json_success( [ 'message' => __( 'Categorías actualizadas.', 'tavox-menu-api' ) ] );
}
add_action( 'wp_ajax_tavox_save_cats', 'tavox_menu_api_save_cats' );

/**
 * Devuelve todas las categorías de WooCommerce para el admin.
 */
function tavox_menu_api_get_all_categories(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( [ 'message' => __( 'Permisos insuficientes.', 'tavox-menu-api' ) ], 403 );
	}

	$terms = get_terms(
		[
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		]
	);

	if ( is_wp_error( $terms ) ) {
		wp_send_json_error( [ 'message' => $terms->get_error_message() ], 500 );
	}

	$items = [];
	foreach ( $terms as $term ) {
		$items[] = [
			'id'   => (int) $term->term_id,
			'name' => html_entity_decode( $term->name ),
		];
	}

	wp_send_json_success( $items );
}
add_action( 'wp_ajax_tavox_get_all_categories', 'tavox_menu_api_get_all_categories' );
