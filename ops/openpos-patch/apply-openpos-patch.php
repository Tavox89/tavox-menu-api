#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/openpos-patch-lib.php';

$options = getopt('', array('file::', 'manifest::', 'backup-dir:', 'report-dir::', 'help'));

if ( isset($options['help']) ) {
	fwrite(STDOUT, "Uso: php apply-openpos-patch.php --backup-dir=/ruta/backups [--file=/ruta/class-op-table.php] [--manifest=/ruta/manifest.json] [--report-dir=/ruta/reportes]\n");
	exit(0);
}

if ( empty($options['backup-dir']) ) {
	fwrite(STDERR, "Falta --backup-dir. El backup debe vivir fuera del árbol live del plugin.\n");
	exit(1);
}

$manifest_path = isset($options['manifest']) ? (string) $options['manifest'] : __DIR__ . '/manifest.json';

try {
	$manifest    = openpos_patch_load_manifest($manifest_path);
	$target_path = openpos_patch_resolve_target($manifest, isset($options['file']) ? (string) $options['file'] : null);
	$report      = openpos_patch_detect($target_path, $manifest);

	if ( 'patched_supported' === $report['status'] ) {
		$report['mutated']    = false;
		$report['backup_path'] = null;
		$report['message']    = 'El archivo ya está parcheado y soportado.';
		$report_path = openpos_patch_write_report(isset($options['report-dir']) ? (string) $options['report-dir'] : null, $report);
		if ( $report_path ) {
			$report['report_path'] = $report_path;
		}
		fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
		exit(0);
	}

	if ( 'pristine_supported' !== $report['status'] ) {
		$report['mutated'] = false;
		$report['message'] = 'El archivo no está en un estado soportado para aplicar el parche.';
		$report_path = openpos_patch_write_report(isset($options['report-dir']) ? (string) $options['report-dir'] : null, $report);
		if ( $report_path ) {
			$report['report_path'] = $report_path;
		}
		fwrite(STDERR, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
		exit(openpos_patch_exit_code((string) $report['status']));
	}

	$contents = file_get_contents($target_path);
	if ( false === $contents ) {
		throw new RuntimeException("No se pudo leer el archivo objetivo: {$target_path}");
	}

	$operations = openpos_patch_operations($manifest);
	$updated = $contents;
	foreach ( $operations as $index => $operation ) {
		$updated = str_replace((string) $operation['search'], (string) $operation['replace'], $updated, $replace_count);
		if ( 1 !== $replace_count ) {
			throw new RuntimeException("No se pudo aplicar la operación {$index} exactamente una vez. replace_count={$replace_count}");
		}
	}

	$backup_path = openpos_patch_backup_file($target_path, (string) $options['backup-dir']);
	$permissions = fileperms($target_path) & 0777;
	$temp_path   = $target_path . '.tmp.' . getmypid();
	if ( false === file_put_contents($temp_path, $updated) ) {
		throw new RuntimeException("No se pudo escribir archivo temporal: {$temp_path}");
	}
	chmod($temp_path, $permissions);
	if ( ! rename($temp_path, $target_path) ) {
		@unlink($temp_path);
		throw new RuntimeException("No se pudo reemplazar el archivo objetivo: {$target_path}");
	}

	$final_report = openpos_patch_detect($target_path, $manifest);
	if ( 'patched_supported' !== $final_report['status'] ) {
		throw new RuntimeException('El parche se escribió, pero el hash final no coincide con un estado patched_supported.');
	}

	$final_report['mutated']     = true;
	$final_report['backup_path'] = $backup_path;
	$final_report['message']     = 'Parche aplicado correctamente.';
	$report_path = openpos_patch_write_report(isset($options['report-dir']) ? (string) $options['report-dir'] : null, $final_report);
	if ( $report_path ) {
		$final_report['report_path'] = $report_path;
	}

	fwrite(STDOUT, json_encode($final_report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
	exit(0);
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
