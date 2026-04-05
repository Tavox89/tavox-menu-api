<?php
/**
 * Plugin Name: Tavox Menu API
 * Description: API REST y capa orquestadora del menú Tavox para Zona B, con categorías visibles, promociones, multi menú, mesas y flujo de meseros.
 * Version: 2.9.20
 * Author: ASD Labs
 * License: GPLv2 or later
 * Text Domain: tavox-menu-api
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'TAVOX_MENU_API_VERSION', '2.9.20' );
define( 'TAVOX_MENU_API_FILE', __FILE__ );
define( 'TAVOX_MENU_API_PATH', plugin_dir_path( __FILE__ ) );
define( 'TAVOX_MENU_API_URL', plugin_dir_url( __FILE__ ) );

/**
 * Devuelve la firma editorial del plugin para el admin.
 */
function tavox_menu_api_get_signature(): string {
	return sprintf(
		/* translators: 1: plugin version 2: studio */
		__( 'Tavox Menu API v%1$s · desarrollado por %2$s.', 'tavox-menu-api' ),
		TAVOX_MENU_API_VERSION,
		'ASD Labs'
	);
}

require_once TAVOX_MENU_API_PATH . 'includes/cache.php';
require_once TAVOX_MENU_API_PATH . 'includes/helpers.php';
require_once TAVOX_MENU_API_PATH . 'includes/push-service.php';
require_once TAVOX_MENU_API_PATH . 'includes/install.php';
require_once TAVOX_MENU_API_PATH . 'includes/openpos-bridge.php';
require_once TAVOX_MENU_API_PATH . 'includes/table-service.php';
require_once TAVOX_MENU_API_PATH . 'includes/waiter-service.php';
require_once TAVOX_MENU_API_PATH . 'includes/admin-access.php';
require_once TAVOX_MENU_API_PATH . 'includes/admin-settings.php';
require_once TAVOX_MENU_API_PATH . 'includes/admin-team-access.php';
require_once TAVOX_MENU_API_PATH . 'includes/admin-categories.php';
require_once TAVOX_MENU_API_PATH . 'includes/admin-promotions.php';
require_once TAVOX_MENU_API_PATH . 'includes/rest.php';
