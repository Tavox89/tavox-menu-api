<?php

defined( 'ABSPATH' ) || exit;

/**
 * Registra el menú principal y los accesos del plugin.
 */
function tavox_menu_api_register_admin_menu(): void {
	add_menu_page(
		__( 'Menú y servicio', 'tavox-menu-api' ),
		__( 'Menú y servicio', 'tavox-menu-api' ),
		'manage_woocommerce',
		'tavox-menu',
		'tavox_menu_api_render_access_page',
		'dashicons-food',
		56
	);

	add_submenu_page(
		'tavox-menu',
		__( 'Accesos', 'tavox-menu-api' ),
		__( 'Accesos', 'tavox-menu-api' ),
		'manage_woocommerce',
		'tavox-menu',
		'tavox_menu_api_render_access_page'
	);

	add_submenu_page(
		'tavox-menu',
		__( 'Ajustes', 'tavox-menu-api' ),
		__( 'Ajustes', 'tavox-menu-api' ),
		'manage_woocommerce',
		'tavox-menu-settings',
		'tavox_menu_api_render_settings_page'
	);

	add_submenu_page(
		'tavox-menu',
		__( 'Categorías', 'tavox-menu-api' ),
		__( 'Categorías', 'tavox-menu-api' ),
		'manage_woocommerce',
		'tavox-menu-categories',
		'tavox_menu_api_render_categories_page'
	);

	add_submenu_page(
		'tavox-menu',
		__( 'Promociones', 'tavox-menu-api' ),
		__( 'Promociones', 'tavox-menu-api' ),
		'manage_woocommerce',
		'tavox-menu-promotions',
		'tavox_menu_api_render_promotions_page'
	);
}
add_action( 'admin_menu', 'tavox_menu_api_register_admin_menu' );

/**
 * Construye la lista de accesos visibles del ecosistema moderno.
 *
 * @return array<int, array<string, string>>
 */
function tavox_menu_api_get_access_links(): array {
	$settings    = tavox_menu_api_get_settings();
	$base_url    = tavox_menu_api_get_frontend_base_url();
	$frontend_set = '' !== trim( (string) ( $settings['menu_frontend_url'] ?? '' ) );
	$site_url    = untrailingslashit( site_url() );

	return [
		[
			'group' => __( 'Público', 'tavox-menu-api' ),
			'label' => __( 'Menú público', 'tavox-menu-api' ),
			'url'   => untrailingslashit( $base_url ),
			'note'  => __( 'Entrada principal del menú para clientes.', 'tavox-menu-api' ),
		],
		[
			'group' => __( 'Mesas', 'tavox-menu-api' ),
			'label' => __( 'Entrada por QR o NFC', 'tavox-menu-api' ),
			'url'   => add_query_arg( 'key', 'TU-MESA', $site_url . '/wp-content/plugins/woocommerce-openpos/customer/index.php' ),
			'note'  => __( 'Esta es la dirección que usan los códigos de mesa para abrir la experiencia moderna.', 'tavox-menu-api' ),
		],
		[
			'group' => __( 'Mesas', 'tavox-menu-api' ),
			'label' => __( 'Entrada de mesa', 'tavox-menu-api' ),
			'url'   => add_query_arg( 'key', 'TU-MESA', untrailingslashit( $base_url ) . '/mesa' ),
			'note'  => __( 'Úsalo para pruebas directas del frontend o revisión local.', 'tavox-menu-api' ),
		],
		[
			'group' => __( 'Mesas', 'tavox-menu-api' ),
			'label' => __( 'Menú de mesa', 'tavox-menu-api' ),
			'url'   => add_query_arg( 'table_token', 'TU-MESA-ACTIVA', untrailingslashit( $base_url ) . '/mesa/menu' ),
			'note'  => __( 'Abre la carta cuando la mesa ya fue reconocida y está lista para pedir.', 'tavox-menu-api' ),
		],
		[
			'group' => __( 'Equipo', 'tavox-menu-api' ),
			'label' => __( 'Panel del equipo', 'tavox-menu-api' ),
			'url'   => untrailingslashit( $base_url ) . '/equipo',
			'note'  => __( 'Entrada rápida para PIN, pedidos y servicio.', 'tavox-menu-api' ),
		],
		[
			'group' => __( 'Equipo', 'tavox-menu-api' ),
			'label' => __( 'Pedidos', 'tavox-menu-api' ),
			'url'   => untrailingslashit( $base_url ) . '/equipo/pedidos',
			'note'  => __( 'Revisión de pedidos nuevos antes de agregarlos.', 'tavox-menu-api' ),
		],
		[
			'group' => __( 'Equipo', 'tavox-menu-api' ),
			'label' => __( 'Servicio', 'tavox-menu-api' ),
			'url'   => untrailingslashit( $base_url ) . '/equipo/servicio',
			'note'  => __( 'Mesas y para llevar en una sola vista operativa.', 'tavox-menu-api' ),
		],
		[
			'group' => __( 'Equipo', 'tavox-menu-api' ),
			'label' => __( 'Menú directo del equipo', 'tavox-menu-api' ),
			'url'   => untrailingslashit( $base_url ) . '/equipo/menu',
			'note'  => __( 'Permite abrir la carta desde una mesa o para llevar libre.', 'tavox-menu-api' ),
		],
		[
			'group' => __( 'Producción', 'tavox-menu-api' ),
			'label' => __( 'Cocina', 'tavox-menu-api' ),
			'url'   => untrailingslashit( $base_url ) . '/equipo/cocina',
			'note'  => __( 'Vista moderna para comida y preparación.', 'tavox-menu-api' ),
		],
		[
			'group' => __( 'Producción', 'tavox-menu-api' ),
			'label' => __( 'Barra', 'tavox-menu-api' ),
			'url'   => untrailingslashit( $base_url ) . '/equipo/barra',
			'note'  => __( 'Vista moderna para bebidas y entregas listas.', 'tavox-menu-api' ),
		],
		[
			'group' => __( 'Producción', 'tavox-menu-api' ),
			'label' => __( 'Horno', 'tavox-menu-api' ),
			'url'   => untrailingslashit( $base_url ) . '/equipo/horno',
			'note'  => __( 'Vista moderna para pizzas, horno y preparaciones de ISOLA.', 'tavox-menu-api' ),
		],
		[
			'group' => __( 'Compatibilidad', 'tavox-menu-api' ),
			'label' => __( 'Ruta anterior del equipo', 'tavox-menu-api' ),
			'url'   => untrailingslashit( $base_url ) . '/mesero',
			'note'  => __( 'Se mantiene como redirección para enlaces viejos.', 'tavox-menu-api' ),
		],
		[
			'group' => __( 'Compatibilidad', 'tavox-menu-api' ),
			'label' => __( 'Pedidos anteriores', 'tavox-menu-api' ),
			'url'   => untrailingslashit( $base_url ) . '/mesero/cola',
			'note'  => __( 'Redirección automática al panel nuevo.', 'tavox-menu-api' ),
		],
		[
			'group' => __( 'Compatibilidad', 'tavox-menu-api' ),
			'label' => __( 'Servicio anterior', 'tavox-menu-api' ),
			'url'   => untrailingslashit( $base_url ) . '/mesero/mesas',
			'note'  => __( 'Redirección automática a la vista nueva de servicio.', 'tavox-menu-api' ),
		],
		[
			'group' => __( 'Compatibilidad', 'tavox-menu-api' ),
			'label' => __( 'Menú anterior del equipo', 'tavox-menu-api' ),
			'url'   => untrailingslashit( $base_url ) . '/mesero/menu',
			'note'  => __( 'Redirección automática al flujo nuevo.', 'tavox-menu-api' ),
		],
		'frontend_configured' => $frontend_set ? 'yes' : 'no',
	];
}

/**
 * Renderiza la página de accesos.
 */
function tavox_menu_api_render_access_page(): void {
	$settings           = tavox_menu_api_get_settings();
	$frontend_configured = '' !== trim( (string) ( $settings['menu_frontend_url'] ?? '' ) );
	$links              = tavox_menu_api_get_access_links();

	wp_register_script(
		'tavox-menu-api-access-admin',
		TAVOX_MENU_API_URL . 'assets/access-admin.js',
		[],
		filemtime( TAVOX_MENU_API_PATH . 'assets/access-admin.js' ),
		true
	);
	wp_enqueue_script( 'tavox-menu-api-access-admin' );

	echo '<div class="wrap">';
	echo '<h1>' . esc_html__( 'Accesos del menú y del equipo', 'tavox-menu-api' ) . '</h1>';
	echo '<p>' . esc_html__( 'Aquí tienes todos los enlaces útiles del frontend moderno para menú, mesas, equipo y producción.', 'tavox-menu-api' ) . '</p>';

	if ( ! $frontend_configured ) {
		echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Todavía no has indicado la dirección pública del frontend. Mientras tanto, estos enlaces usarán la ruta /menu del mismo WordPress.', 'tavox-menu-api' ) . '</p></div>';
	}

	$current_group = '';
	foreach ( $links as $link ) {
		if ( ! is_array( $link ) ) {
			continue;
		}

		if ( $current_group !== $link['group'] ) {
			if ( '' !== $current_group ) {
				echo '</tbody></table>';
			}

			$current_group = $link['group'];
			echo '<h2 style="margin-top:22px;">' . esc_html( $current_group ) . '</h2>';
			echo '<table class="widefat striped" style="max-width:980px;"><thead><tr>';
			echo '<th style="width:220px;">' . esc_html__( 'Interfaz', 'tavox-menu-api' ) . '</th>';
			echo '<th>' . esc_html__( 'Enlace', 'tavox-menu-api' ) . '</th>';
			echo '<th style="width:150px;">' . esc_html__( 'Acción', 'tavox-menu-api' ) . '</th>';
			echo '</tr></thead><tbody>';
		}

		echo '<tr>';
		echo '<td><strong>' . esc_html( $link['label'] ) . '</strong><p class="description" style="margin:6px 0 0;">' . esc_html( $link['note'] ) . '</p></td>';
		echo '<td>';
		echo '<input type="text" readonly class="large-text code tavox-copy-source" value="' . esc_attr( $link['url'] ) . '" />';
		echo '</td>';
		echo '<td>';
		echo '<a class="button button-secondary tavox-copy-link" href="#" data-copy="' . esc_attr( $link['url'] ) . '">' . esc_html__( 'Copiar', 'tavox-menu-api' ) . '</a> ';
		echo '<a class="button button-primary" href="' . esc_url( $link['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Abrir', 'tavox-menu-api' ) . '</a>';
		echo '</td>';
		echo '</tr>';
	}

	if ( '' !== $current_group ) {
		echo '</tbody></table>';
	}

	echo '</div>';
}
