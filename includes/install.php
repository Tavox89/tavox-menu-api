<?php

defined( 'ABSPATH' ) || exit;

/**
 * Indica si una tabla ya tiene una columna dada.
 */
function tavox_menu_api_table_has_column( string $table_name, string $column_name ): bool {
	global $wpdb;

	$table_name  = trim( $table_name );
	$column_name = trim( $column_name );

	if ( '' === $table_name || '' === $column_name ) {
		return false;
	}

	$result = $wpdb->get_var(
		$wpdb->prepare(
			'SHOW COLUMNS FROM `' . str_replace( '`', '``', $table_name ) . '` LIKE %s',
			$column_name
		)
	);

	return ! empty( $result );
}

/**
 * Crea o actualiza las tablas operativas del plugin.
 */
function tavox_menu_api_install(): void {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset_collate = $wpdb->get_charset_collate();
	$requests_table  = tavox_menu_api_get_table_requests_table_name();
	$sessions_table  = tavox_menu_api_get_waiter_sessions_table_name();
	$table_messages_table      = tavox_menu_api_get_table_messages_table_name();
	$push_subscriptions_table = tavox_menu_api_get_waiter_push_subscriptions_table_name();
	$push_messages_table      = tavox_menu_api_get_waiter_push_messages_table_name();

	$sql_requests = "CREATE TABLE {$requests_table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		request_key VARCHAR(96) NOT NULL DEFAULT '',
		table_key VARCHAR(191) NOT NULL DEFAULT '',
		table_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		table_type VARCHAR(24) NOT NULL DEFAULT 'dine_in',
		table_name VARCHAR(191) NOT NULL DEFAULT '',
		register_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		warehouse_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		request_source VARCHAR(24) NOT NULL DEFAULT 'customer',
		session_token VARCHAR(512) NOT NULL DEFAULT '',
		client_label VARCHAR(191) NOT NULL DEFAULT '',
		waiter_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		waiter_name VARCHAR(191) NOT NULL DEFAULT '',
		brand_scope VARCHAR(24) NOT NULL DEFAULT 'zona_b',
		status VARCHAR(24) NOT NULL DEFAULT 'pending',
		payload LONGTEXT NULL,
		global_note LONGTEXT NULL,
		error_message LONGTEXT NULL,
		claimed_at DATETIME NULL,
		accepted_at DATETIME NULL,
		pushed_at DATETIME NULL,
		expires_at DATETIME NULL,
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY request_key (request_key),
		KEY table_lookup (table_type, table_id),
		KEY waiter_lookup (waiter_user_id, status),
		KEY status_lookup (status, updated_at)
	) {$charset_collate};";

	$sql_sessions = "CREATE TABLE {$sessions_table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id BIGINT UNSIGNED NOT NULL,
		session_token VARCHAR(96) NOT NULL,
		device_label VARCHAR(191) NOT NULL DEFAULT '',
		status VARCHAR(24) NOT NULL DEFAULT 'active',
		created_at DATETIME NOT NULL,
		last_seen DATETIME NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY session_token (session_token),
		KEY user_lookup (user_id, status),
		KEY active_lookup (status, last_seen)
	) {$charset_collate};";

	$sql_push_subscriptions = "CREATE TABLE {$push_subscriptions_table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		session_token VARCHAR(96) NOT NULL DEFAULT '',
		waiter_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		endpoint_hash VARCHAR(64) NOT NULL DEFAULT '',
		endpoint_url LONGTEXT NULL,
		client_public_key VARCHAR(191) NOT NULL DEFAULT '',
		auth_secret VARCHAR(191) NOT NULL DEFAULT '',
		content_encoding VARCHAR(32) NOT NULL DEFAULT 'aes128gcm',
		device_label VARCHAR(191) NOT NULL DEFAULT '',
		notification_scope VARCHAR(24) NOT NULL DEFAULT 'service',
		status VARCHAR(24) NOT NULL DEFAULT 'active',
		last_notified_at DATETIME NULL,
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY session_token (session_token),
		UNIQUE KEY endpoint_hash (endpoint_hash),
		KEY waiter_lookup (waiter_user_id, status),
		KEY status_lookup (status, updated_at)
	) {$charset_collate};";

	$sql_push_messages = "CREATE TABLE {$push_messages_table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		session_token VARCHAR(96) NOT NULL DEFAULT '',
		waiter_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		event_type VARCHAR(64) NOT NULL DEFAULT 'team_update',
		title VARCHAR(191) NOT NULL DEFAULT '',
		body LONGTEXT NULL,
		link_url TEXT NULL,
		tag VARCHAR(191) NOT NULL DEFAULT '',
		meta_json LONGTEXT NULL,
		delivered_at DATETIME NULL,
		read_at DATETIME NULL,
		resolved_at DATETIME NULL,
		created_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY session_lookup (session_token, delivered_at),
		KEY waiter_lookup (waiter_user_id, delivered_at),
		KEY active_lookup (session_token, resolved_at, read_at),
		KEY created_lookup (created_at)
	) {$charset_collate};";

	$sql_table_messages = "CREATE TABLE {$table_messages_table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		table_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		table_type VARCHAR(24) NOT NULL DEFAULT 'dine_in',
		table_session_token VARCHAR(512) NOT NULL DEFAULT '',
		request_key VARCHAR(96) NOT NULL DEFAULT '',
		sender_role VARCHAR(24) NOT NULL DEFAULT 'customer',
		sender_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		sender_label VARCHAR(191) NOT NULL DEFAULT '',
		message_type VARCHAR(24) NOT NULL DEFAULT 'free_text',
		message_text LONGTEXT NULL,
		status VARCHAR(24) NOT NULL DEFAULT 'open',
		created_at DATETIME NOT NULL,
		read_at DATETIME NULL,
		resolved_at DATETIME NULL,
		PRIMARY KEY  (id),
		KEY table_lookup (table_type, table_id, status),
		KEY session_lookup (table_session_token(191)),
		KEY sender_lookup (sender_role, sender_user_id),
		KEY created_lookup (created_at)
	) {$charset_collate};";

	dbDelta( $sql_requests );
	dbDelta( $sql_sessions );
	dbDelta( $sql_push_subscriptions );
	dbDelta( $sql_push_messages );
	dbDelta( $sql_table_messages );

	// El token firmado de mesa puede superar 191 caracteres.
	$wpdb->query( "ALTER TABLE {$requests_table} MODIFY session_token VARCHAR(512) NOT NULL DEFAULT ''" );
	$wpdb->query( "ALTER TABLE {$table_messages_table} MODIFY table_session_token VARCHAR(512) NOT NULL DEFAULT ''" );
	$wpdb->query( "ALTER TABLE {$push_messages_table} MODIFY link_url TEXT NULL" );

	if ( ! tavox_menu_api_table_has_column( $push_subscriptions_table, 'notification_scope' ) ) {
		$wpdb->query( "ALTER TABLE {$push_subscriptions_table} ADD COLUMN notification_scope VARCHAR(24) NOT NULL DEFAULT 'service' AFTER device_label" );
	}

	if ( ! tavox_menu_api_table_has_column( $push_subscriptions_table, 'client_public_key' ) ) {
		$wpdb->query( "ALTER TABLE {$push_subscriptions_table} ADD COLUMN client_public_key VARCHAR(191) NOT NULL DEFAULT '' AFTER endpoint_url" );
	}

	if ( ! tavox_menu_api_table_has_column( $push_subscriptions_table, 'auth_secret' ) ) {
		$wpdb->query( "ALTER TABLE {$push_subscriptions_table} ADD COLUMN auth_secret VARCHAR(191) NOT NULL DEFAULT '' AFTER client_public_key" );
	}

	if ( ! tavox_menu_api_table_has_column( $push_messages_table, 'read_at' ) ) {
		$wpdb->query( "ALTER TABLE {$push_messages_table} ADD COLUMN read_at DATETIME NULL AFTER delivered_at" );
	}

	if ( ! tavox_menu_api_table_has_column( $push_messages_table, 'resolved_at' ) ) {
		$wpdb->query( "ALTER TABLE {$push_messages_table} ADD COLUMN resolved_at DATETIME NULL AFTER read_at" );
	}

	update_option( 'tavox_menu_api_schema_version', TAVOX_MENU_API_VERSION, false );

	$settings = tavox_menu_api_get_settings();
	$normalized_settings = array_merge(
		$settings,
		[
			'table_order_enabled'         => ! empty( $settings['table_order_enabled'] ),
			'waiter_console_enabled'      => ! empty( $settings['waiter_console_enabled'] ),
			'shared_tables_enabled'       => ! empty( $settings['shared_tables_enabled'] ),
			'realtime_enabled'            => ! empty( $settings['realtime_enabled'] ),
			'realtime_socket_url'         => (string) ( $settings['realtime_socket_url'] ?? '' ),
			'realtime_publish_url'        => (string) ( $settings['realtime_publish_url'] ?? '' ),
			'realtime_shared_secret'      => (string) ( $settings['realtime_shared_secret'] ?? '' ),
			'menu_frontend_url'           => (string) ( $settings['menu_frontend_url'] ?? '' ),
			'wifi_name'                   => (string) ( $settings['wifi_name'] ?? '' ),
			'wifi_password'               => (string) ( $settings['wifi_password'] ?? '' ),
			'wifi_label'                  => (string) ( $settings['wifi_label'] ?? '' ),
			'request_hold_minutes'        => (int) ( $settings['request_hold_minutes'] ?? 15 ),
			'claim_timeout_seconds'       => (int) ( $settings['claim_timeout_seconds'] ?? 90 ),
			'session_idle_timeout_minutes'=> (int) ( $settings['session_idle_timeout_minutes'] ?? 120 ),
			'notification_sound_enabled'  => array_key_exists( 'notification_sound_enabled', $settings ) ? ! empty( $settings['notification_sound_enabled'] ) : true,
			'push_notifications_enabled'  => array_key_exists( 'push_notifications_enabled', $settings ) ? ! empty( $settings['push_notifications_enabled'] ) : true,
			'push_vapid_subject'          => (string) ( $settings['push_vapid_subject'] ?? '' ),
			'push_vapid_public_key'       => (string) ( $settings['push_vapid_public_key'] ?? '' ),
			'push_vapid_private_key'      => (string) ( $settings['push_vapid_private_key'] ?? '' ),
		]
	);

	update_option( 'tavox_menu_settings', tavox_menu_api_prepare_realtime_settings( tavox_menu_api_prepare_push_settings( $normalized_settings, true ), true ), false );
}

/**
 * Ejecuta la instalación/upgrade al activar el plugin.
 */
function tavox_menu_api_activate(): void {
	tavox_menu_api_install();
	tavox_menu_api_register_waiter_capability();
}
register_activation_hook( TAVOX_MENU_API_FILE, 'tavox_menu_api_activate' );

/**
 * Ejecuta upgrades livianos cuando cambia la versión del schema.
 */
function tavox_menu_api_maybe_upgrade(): void {
	$current = (string) get_option( 'tavox_menu_api_schema_version', '' );
	if ( TAVOX_MENU_API_VERSION === $current ) {
		return;
	}

	tavox_menu_api_install();
}
add_action( 'plugins_loaded', 'tavox_menu_api_maybe_upgrade', 30 );
