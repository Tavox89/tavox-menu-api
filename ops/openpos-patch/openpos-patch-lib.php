<?php
declare(strict_types=1);

function openpos_patch_now_utc(): string {
	return gmdate('c');
}

function openpos_patch_load_manifest(string $manifest_path): array {
	if ( ! is_file($manifest_path) ) {
		throw new RuntimeException("Manifest no encontrado: {$manifest_path}");
	}

	$raw = file_get_contents($manifest_path);
	if ( false === $raw ) {
		throw new RuntimeException("No se pudo leer el manifest: {$manifest_path}");
	}

	$data = json_decode($raw, true);
	if ( ! is_array($data) ) {
		throw new RuntimeException("Manifest inválido: {$manifest_path}");
	}

	return $data;
}

function openpos_patch_manifest_hashes(array $manifest, string $kind): array {
	$items = $manifest['supported_fingerprints'][ $kind ] ?? array();
	$hashes = array();

	foreach ( $items as $item ) {
		if ( ! empty($item['sha256']) ) {
			$hashes[] = strtolower((string) $item['sha256']);
		}
	}

	return $hashes;
}

function openpos_patch_operations(array $manifest): array {
	$operations = $manifest['patch']['operations'] ?? null;
	if ( is_array($operations) && ! empty($operations) ) {
		$result = array();
		foreach ( $operations as $index => $operation ) {
			if ( ! is_array($operation) ) {
				continue;
			}
			$search = (string) ($operation['search'] ?? '');
			$replace = (string) ($operation['replace'] ?? '');
			if ( '' === $search ) {
				throw new RuntimeException("Operación de parche inválida en índice {$index}: falta search.");
			}
			$result[] = array(
				'search' => $search,
				'replace' => $replace,
			);
		}
		if ( ! empty($result) ) {
			return $result;
		}
	}

	$search = (string) ($manifest['patch']['search'] ?? '');
	if ( '' === $search ) {
		throw new RuntimeException('Manifest inválido: no define patch.search ni patch.operations.');
	}

	return array(
		array(
			'search' => $search,
			'replace' => (string) ($manifest['patch']['replace'] ?? ''),
		),
	);
}

function openpos_patch_resolve_target(array $manifest, ?string $explicit_path = null): string {
	if ( $explicit_path ) {
		return $explicit_path;
	}

	$candidates = $manifest['target']['candidate_paths'] ?? array();
	foreach ( $candidates as $candidate ) {
		if ( is_string($candidate) && is_file($candidate) ) {
			return $candidate;
		}
	}

	$relative = $manifest['target']['relative_path'] ?? '';
	throw new RuntimeException("No se encontró archivo objetivo. Pasa --file o crea {$relative}.");
}

function openpos_patch_detect(string $target_path, array $manifest): array {
	if ( ! is_file($target_path) ) {
		return array(
			'ok'                 => false,
			'status'             => 'file_missing',
			'target_path'        => $target_path,
			'sha256'             => null,
			'detected_at'        => openpos_patch_now_utc(),
			'search_anchor_found'=> false,
			'replace_anchor_found'=> false,
			'manual_action'      => 'Verifica la ruta objetivo antes de aplicar el parche.',
		);
	}

	$contents = file_get_contents($target_path);
	if ( false === $contents ) {
		throw new RuntimeException("No se pudo leer el archivo objetivo: {$target_path}");
	}

	$operations = openpos_patch_operations($manifest);
	$sha256  = hash('sha256', $contents);

	$pristine_hashes = openpos_patch_manifest_hashes($manifest, 'pristine');
	$patched_hashes  = openpos_patch_manifest_hashes($manifest, 'patched');

	$search_found  = false;
	$replace_found = false;
	foreach ( $operations as $operation ) {
		if ( false !== strpos($contents, (string) $operation['search']) ) {
			$search_found = true;
		}
		if ( '' !== (string) $operation['replace'] && false !== strpos($contents, (string) $operation['replace']) ) {
			$replace_found = true;
		}
	}

	if ( in_array($sha256, $patched_hashes, true) ) {
		$status = 'patched_supported';
	} elseif ( in_array($sha256, $pristine_hashes, true) ) {
		$status = 'pristine_supported';
	} elseif ( $search_found || $replace_found ) {
		$status = 'patch_drift';
	} else {
		$status = 'unknown_upstream';
	}

	$manual_action = '';
	if ( 'unknown_upstream' === $status || 'patch_drift' === $status ) {
		$manual_action = 'Detener la aplicación automática. Revisar diff del vendor, actualizar manifest y volver a validar el parche manualmente.';
	} elseif ( 'file_missing' === $status ) {
		$manual_action = 'La ruta no existe. Revisa target_path o pasa --file.';
	}

	return array(
		'ok'                  => in_array($status, array('pristine_supported', 'patched_supported'), true),
		'status'              => $status,
		'target_path'         => $target_path,
		'sha256'              => $sha256,
		'detected_at'         => openpos_patch_now_utc(),
		'search_anchor_found' => $search_found,
		'replace_anchor_found'=> $replace_found,
		'manual_action'       => $manual_action,
		'patch_id'            => $manifest['patch_id'] ?? null,
		'patch_version'       => $manifest['patch_version'] ?? null,
		'expected_change'     => array(
			'operations' => $operations,
		),
	);
}

function openpos_patch_exit_code(string $status): int {
	switch ( $status ) {
		case 'pristine_supported':
		case 'patched_supported':
			return 0;
		case 'file_missing':
			return 2;
		case 'unknown_upstream':
			return 3;
		case 'patch_drift':
			return 4;
		default:
			return 1;
	}
}

function openpos_patch_write_report(?string $report_dir, array $report): ?string {
	if ( empty($report_dir) ) {
		return null;
	}

	if ( ! is_dir($report_dir) && ! mkdir($report_dir, 0775, true) && ! is_dir($report_dir) ) {
		throw new RuntimeException("No se pudo crear report_dir: {$report_dir}");
	}

	$filename = sprintf(
		'%s-%s-%s.json',
		$report['patch_id'] ?? 'openpos-patch',
		$report['status'] ?? 'report',
		gmdate('Ymd-His')
	);
	$path = rtrim($report_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
	$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	if ( false === file_put_contents($path, $json . PHP_EOL) ) {
		throw new RuntimeException("No se pudo escribir el reporte: {$path}");
	}

	return $path;
}

function openpos_patch_backup_file(string $target_path, string $backup_dir): string {
	if ( ! is_dir($backup_dir) && ! mkdir($backup_dir, 0775, true) && ! is_dir($backup_dir) ) {
		throw new RuntimeException("No se pudo crear backup_dir: {$backup_dir}");
	}

	$backup_path = rtrim($backup_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($target_path) . '.bak.' . gmdate('Ymd-His');
	if ( ! copy($target_path, $backup_path) ) {
		throw new RuntimeException("No se pudo crear backup: {$backup_path}");
	}

	return $backup_path;
}
