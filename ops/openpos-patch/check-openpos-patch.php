#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/openpos-patch-lib.php';

$options = getopt('', array('file::', 'manifest::', 'report-dir::', 'help'));

if ( isset($options['help']) ) {
	fwrite(STDOUT, "Uso: php check-openpos-patch.php [--file=/ruta/class-op-table.php] [--manifest=/ruta/manifest.json] [--report-dir=/ruta/reportes]\n");
	exit(0);
}

$manifest_path = isset($options['manifest']) ? (string) $options['manifest'] : __DIR__ . '/manifest.json';

try {
	$manifest    = openpos_patch_load_manifest($manifest_path);
	$target_path = openpos_patch_resolve_target($manifest, isset($options['file']) ? (string) $options['file'] : null);
	$report      = openpos_patch_detect($target_path, $manifest);
	$report_path = openpos_patch_write_report(isset($options['report-dir']) ? (string) $options['report-dir'] : null, $report);
	if ( $report_path ) {
		$report['report_path'] = $report_path;
	}

	fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
	exit(openpos_patch_exit_code((string) $report['status']));
} catch ( Throwable $e ) {
	$report = array(
		'ok'          => false,
		'status'      => 'error',
		'message'     => $e->getMessage(),
		'detected_at' => openpos_patch_now_utc(),
	);
	fwrite(STDERR, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
	exit(1);
}
