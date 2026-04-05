<?php

defined( 'ABSPATH' ) || exit;

/**
 * Renderiza la página de ajustes generales.
 */
function tavox_menu_api_render_settings_page(): void {
	$settings = tavox_menu_api_get_settings();
	$updated  = isset( $_GET['updated'] ) && '1' === (string) $_GET['updated'];

	echo '<div class="wrap">';
	echo '<h1>' . esc_html__( 'Ajustes del menú', 'tavox-menu-api' ) . '</h1>';
	echo '<p>' . esc_html__( 'Aquí se guardan los datos generales del menú, la operación de mesas y el panel del equipo.', 'tavox-menu-api' ) . '</p>';

	if ( $updated ) {
		echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Los ajustes se guardaron correctamente.', 'tavox-menu-api' ) . '</p></div>';
	}

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:820px;">';
	wp_nonce_field( 'tavox_menu_api_save_settings' );
	echo '<input type="hidden" name="action" value="tavox_menu_api_save_settings" />';
	echo '<table class="form-table" role="presentation"><tbody>';

	echo '<tr><th scope="row"><label for="tavox-whatsapp-phone">' . esc_html__( 'Número destino', 'tavox-menu-api' ) . '</label></th><td>';
	echo '<input id="tavox-whatsapp-phone" name="whatsapp_phone" type="text" class="regular-text" value="' . esc_attr( $settings['whatsapp_phone'] ) . '" placeholder="584141234567" />';
	echo '<p class="description">' . esc_html__( 'Usa sólo el número con código de país. El botón del menú lo usará automáticamente cuando el pedido salga por WhatsApp.', 'tavox-menu-api' ) . '</p>';
	echo '</td></tr>';

	echo '<tr><th scope="row">' . esc_html__( 'Modo multi menú', 'tavox-menu-api' ) . '</th><td>';
	echo '<label style="display:inline-flex; align-items:center; gap:8px;">';
	echo '<input id="tavox-multi-menu-enabled" name="multi_menu_enabled" type="checkbox" value="1" ' . checked( ! empty( $settings['multi_menu_enabled'] ), true, false ) . ' />';
	echo '<span>' . esc_html__( 'Activar Zona B + ISOLA', 'tavox-menu-api' ) . '</span>';
	echo '</label>';
	echo '<p class="description">' . esc_html__( 'Muestra la pantalla inicial para elegir entre las dos áreas visuales y usa la configuración por alcance en categorías y promociones.', 'tavox-menu-api' ) . '</p>';
	echo '</td></tr>';

	echo '<tr><th scope="row">' . esc_html__( 'Pedidos en mesa', 'tavox-menu-api' ) . '</th><td>';
	echo '<label style="display:inline-flex; align-items:center; gap:8px;">';
	echo '<input id="tavox-table-order-enabled" name="table_order_enabled" type="checkbox" value="1" ' . checked( ! empty( $settings['table_order_enabled'] ), true, false ) . ' />';
	echo '<span>' . esc_html__( 'Activar servicio por mesa', 'tavox-menu-api' ) . '</span>';
	echo '</label>';
	echo '<p class="description">' . esc_html__( 'Cuando está activo, los códigos de mesa abren la experiencia moderna y el cliente envía pedidos para revisión del equipo.', 'tavox-menu-api' ) . '</p>';
	echo '</td></tr>';

	echo '<tr><th scope="row">' . esc_html__( 'Panel del equipo', 'tavox-menu-api' ) . '</th><td>';
	echo '<label style="display:inline-flex; align-items:center; gap:8px;">';
	echo '<input id="tavox-waiter-console-enabled" name="waiter_console_enabled" type="checkbox" value="1" ' . checked( ! empty( $settings['waiter_console_enabled'] ), true, false ) . ' />';
	echo '<span>' . esc_html__( 'Activar acceso con PIN y vistas operativas', 'tavox-menu-api' ) . '</span>';
	echo '</label>';
	echo '<p class="description">' . esc_html__( 'Habilita pedidos, servicio, cocina, horno y barra dentro del frontend moderno.', 'tavox-menu-api' ) . '</p>';
	echo '</td></tr>';

	echo '<tr><th scope="row">' . esc_html__( 'Mesas compartidas', 'tavox-menu-api' ) . '</th><td>';
	echo '<label style="display:inline-flex; align-items:center; gap:8px;">';
	echo '<input id="tavox-shared-tables-enabled" name="shared_tables_enabled" type="checkbox" value="1" ' . checked( ! empty( $settings['shared_tables_enabled'] ), true, false ) . ' />';
	echo '<span>' . esc_html__( 'Permitir que más de un mesero atienda la misma cuenta', 'tavox-menu-api' ) . '</span>';
	echo '</label>';
	echo '<p class="description">' . esc_html__( 'Si está apagado, cada mesa queda a cargo de una sola persona del equipo. Si lo enciendes, las cuentas pueden compartirse.', 'tavox-menu-api' ) . '</p>';
	echo '</td></tr>';

	echo '<tr><th scope="row"><label for="tavox-menu-frontend-url">' . esc_html__( 'Frontend del menú', 'tavox-menu-api' ) . '</label></th><td>';
	echo '<input id="tavox-menu-frontend-url" name="menu_frontend_url" type="url" class="regular-text code" value="' . esc_attr( $settings['menu_frontend_url'] ) . '" placeholder="https://menu.zonabclub.com" />';
	echo '<p class="description">' . esc_html__( 'Dirección pública donde vive la experiencia moderna. Si la dejas vacía, se usará /menu dentro del mismo WordPress.', 'tavox-menu-api' ) . '</p>';
	echo '</td></tr>';

	echo '<tr><th scope="row">' . esc_html__( 'Realtime del equipo', 'tavox-menu-api' ) . '</th><td>';
	echo '<label style="display:inline-flex; align-items:center; gap:8px;">';
	echo '<input id="tavox-realtime-enabled" name="realtime_enabled" type="checkbox" value="1" ' . checked( ! empty( $settings['realtime_enabled'] ), true, false ) . ' />';
	echo '<span>' . esc_html__( 'Activar WebSocket externo para el panel del equipo', 'tavox-menu-api' ) . '</span>';
	echo '</label>';
	echo '<p class="description">' . esc_html__( 'Usa un proceso aparte, por ejemplo wss://realtime.zonabclub.com/socket, para sincronizar servicio, cocina, horno y barra sin dejar workers PHP pegados.', 'tavox-menu-api' ) . '</p>';
	echo '<div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:12px; max-width:760px; margin-top:12px;">';
	echo '<label><span style="display:block; margin-bottom:4px;">' . esc_html__( 'Socket URL', 'tavox-menu-api' ) . '</span><input name="realtime_socket_url" type="text" class="regular-text code" value="' . esc_attr( (string) ( $settings['realtime_socket_url'] ?? '' ) ) . '" placeholder="wss://realtime.zonabclub.com/socket" /></label>';
	echo '<label><span style="display:block; margin-bottom:4px;">' . esc_html__( 'Publish URL interno', 'tavox-menu-api' ) . '</span><input name="realtime_publish_url" type="text" class="regular-text code" value="' . esc_attr( (string) ( $settings['realtime_publish_url'] ?? '' ) ) . '" placeholder="http://127.0.0.1:4100/publish" /></label>';
	echo '<label><span style="display:block; margin-bottom:4px;">' . esc_html__( 'Secreto compartido', 'tavox-menu-api' ) . '</span><input name="realtime_shared_secret" type="text" class="regular-text code" value="' . esc_attr( (string) ( $settings['realtime_shared_secret'] ?? '' ) ) . '" placeholder="' . esc_attr__( 'Se genera si lo dejas vacío', 'tavox-menu-api' ) . '" /></label>';
	echo '</div>';
	echo '</td></tr>';

	echo '<tr><th scope="row">' . esc_html__( 'Wi‑Fi visible en mesa', 'tavox-menu-api' ) . '</th><td>';
	echo '<div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; max-width:760px;">';
	echo '<label><span style="display:block; margin-bottom:4px;">' . esc_html__( 'Etiqueta', 'tavox-menu-api' ) . '</span><input name="wifi_label" type="text" class="regular-text" value="' . esc_attr( $settings['wifi_label'] ) . '" placeholder="Wi‑Fi de la casa" /></label>';
	echo '<label><span style="display:block; margin-bottom:4px;">' . esc_html__( 'Red', 'tavox-menu-api' ) . '</span><input name="wifi_name" type="text" class="regular-text" value="' . esc_attr( $settings['wifi_name'] ) . '" placeholder="ZonaB-Guest" /></label>';
	echo '<label><span style="display:block; margin-bottom:4px;">' . esc_html__( 'Clave', 'tavox-menu-api' ) . '</span><input name="wifi_password" type="text" class="regular-text" value="' . esc_attr( $settings['wifi_password'] ) . '" placeholder="******" /></label>';
	echo '</div>';
	echo '<p class="description">' . esc_html__( 'Se mostrará en la entrada de la mesa para que el cliente pueda conectarse sin pedir ayuda.', 'tavox-menu-api' ) . '</p>';
	echo '</td></tr>';

	echo '<tr><th scope="row"><label for="tavox-request-hold-minutes">' . esc_html__( 'Vigencia del pedido', 'tavox-menu-api' ) . '</label></th><td>';
	echo '<input id="tavox-request-hold-minutes" name="request_hold_minutes" type="number" min="1" max="240" class="small-text" value="' . esc_attr( (string) $settings['request_hold_minutes'] ) . '" /> ';
	echo '<span class="description">' . esc_html__( 'minutos antes de que un pedido por revisar venza si nadie lo toma.', 'tavox-menu-api' ) . '</span>';
	echo '</td></tr>';

	echo '<tr><th scope="row"><label for="tavox-claim-timeout-seconds">' . esc_html__( 'Tiempo de reserva', 'tavox-menu-api' ) . '</label></th><td>';
	echo '<input id="tavox-claim-timeout-seconds" name="claim_timeout_seconds" type="number" min="15" max="3600" class="small-text" value="' . esc_attr( (string) $settings['claim_timeout_seconds'] ) . '" /> ';
	echo '<span class="description">' . esc_html__( 'segundos antes de liberar un pedido tomado si no se continúa con él.', 'tavox-menu-api' ) . '</span>';
	echo '</td></tr>';

	echo '<tr><th scope="row"><label for="tavox-session-idle-timeout-minutes">' . esc_html__( 'Tiempo de inactividad del equipo', 'tavox-menu-api' ) . '</label></th><td>';
	echo '<input id="tavox-session-idle-timeout-minutes" name="session_idle_timeout_minutes" type="number" min="15" max="720" class="small-text" value="' . esc_attr( (string) $settings['session_idle_timeout_minutes'] ) . '" /> ';
	echo '<span class="description">' . esc_html__( 'minutos sin actividad antes de cerrar el acceso del equipo automáticamente.', 'tavox-menu-api' ) . '</span>';
	echo '</td></tr>';

	echo '<tr><th scope="row">' . esc_html__( 'Avisos sonoros', 'tavox-menu-api' ) . '</th><td>';
	echo '<label style="display:inline-flex; align-items:center; gap:8px;">';
	echo '<input id="tavox-notification-sound-enabled" name="notification_sound_enabled" type="checkbox" value="1" ' . checked( ! empty( $settings['notification_sound_enabled'] ), true, false ) . ' />';
	echo '<span>' . esc_html__( 'Permitir sonido local en las pantallas del equipo', 'tavox-menu-api' ) . '</span>';
	echo '</label>';
	echo '</td></tr>';

	echo '<tr><th scope="row">' . esc_html__( 'Avisos en tablet', 'tavox-menu-api' ) . '</th><td>';
	echo '<label style="display:inline-flex; align-items:center; gap:8px;">';
	echo '<input id="tavox-push-notifications-enabled" name="push_notifications_enabled" type="checkbox" value="1" ' . checked( ! empty( $settings['push_notifications_enabled'] ), true, false ) . ' />';
	echo '<span>' . esc_html__( 'Activar avisos push del sistema para las tablets del equipo', 'tavox-menu-api' ) . '</span>';
	echo '</label>';
	echo '<p class="description">' . esc_html__( 'Sirve para que la tablet avise aunque la app no esté en primer plano.', 'tavox-menu-api' ) . '</p>';
	echo '</td></tr>';

	echo '<tr><th scope="row"><label for="tavox-push-vapid-subject">' . esc_html__( 'Contacto para avisos', 'tavox-menu-api' ) . '</label></th><td>';
	echo '<input id="tavox-push-vapid-subject" name="push_vapid_subject" type="text" class="regular-text" value="' . esc_attr( (string) ( $settings['push_vapid_subject'] ?? '' ) ) . '" placeholder="correo@zonabclub.com" />';
	echo '<p class="description">' . esc_html__( 'Correo o dirección usada como contacto técnico del canal de avisos. Si la dejas vacía, se usará el correo principal del sitio.', 'tavox-menu-api' ) . '</p>';
	if ( ! empty( $settings['push_vapid_public_key'] ) && ! empty( $settings['push_vapid_private_key'] ) ) {
		echo '<p class="description">' . esc_html__( 'Las claves de avisos ya están preparadas para la tablet.', 'tavox-menu-api' ) . '</p>';
	}
	echo '</td></tr>';

	echo '</tbody></table>';
	submit_button( __( 'Guardar ajustes', 'tavox-menu-api' ) );
	echo '</form>';

	if ( function_exists( 'tavox_menu_api_render_team_access_module' ) ) {
		tavox_menu_api_render_team_access_module();
	}

	echo '</div>';
}

/**
 * Guarda la configuración general desde la página de ajustes.
 */
function tavox_menu_api_save_settings(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'No tienes permisos para guardar estos cambios.', 'tavox-menu-api' ) );
	}

	check_admin_referer( 'tavox_menu_api_save_settings' );

	$raw_settings = [
		'whatsapp_phone'             => (string) ( $_POST['whatsapp_phone'] ?? '' ),
		'multi_menu_enabled'         => ! empty( $_POST['multi_menu_enabled'] ),
		'table_order_enabled'        => ! empty( $_POST['table_order_enabled'] ),
		'waiter_console_enabled'     => ! empty( $_POST['waiter_console_enabled'] ),
		'shared_tables_enabled'      => ! empty( $_POST['shared_tables_enabled'] ),
		'realtime_enabled'           => ! empty( $_POST['realtime_enabled'] ),
		'realtime_socket_url'        => (string) ( $_POST['realtime_socket_url'] ?? '' ),
		'realtime_publish_url'       => (string) ( $_POST['realtime_publish_url'] ?? '' ),
		'realtime_shared_secret'     => (string) ( $_POST['realtime_shared_secret'] ?? '' ),
		'menu_frontend_url'          => (string) ( $_POST['menu_frontend_url'] ?? '' ),
		'wifi_label'                 => (string) ( $_POST['wifi_label'] ?? '' ),
		'wifi_name'                  => (string) ( $_POST['wifi_name'] ?? '' ),
		'wifi_password'              => (string) ( $_POST['wifi_password'] ?? '' ),
		'request_hold_minutes'       => $_POST['request_hold_minutes'] ?? 15,
		'claim_timeout_seconds'      => $_POST['claim_timeout_seconds'] ?? 90,
		'session_idle_timeout_minutes' => $_POST['session_idle_timeout_minutes'] ?? 120,
		'notification_sound_enabled' => ! empty( $_POST['notification_sound_enabled'] ),
		'push_notifications_enabled' => ! empty( $_POST['push_notifications_enabled'] ),
		'push_vapid_subject'         => (string) ( $_POST['push_vapid_subject'] ?? '' ),
	];

	$sanitized = tavox_menu_api_sanitize_settings_payload( $raw_settings );
	update_option( 'tavox_menu_settings', tavox_menu_api_prepare_realtime_settings( tavox_menu_api_prepare_push_settings( $sanitized, true ), true ), false );
	tavox_menu_api_bump_cache_version();

	wp_safe_redirect(
		add_query_arg(
			[
				'page'    => 'tavox-menu-settings',
				'updated' => '1',
			],
			admin_url( 'admin.php' )
		)
	);
	exit;
}
add_action( 'admin_post_tavox_menu_api_save_settings', 'tavox_menu_api_save_settings' );
