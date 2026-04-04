# OpenPOS Patch: `class-op-table.php`

Este directorio administra el parche vivo que sigue fuera de `tavox-menu-api` sobre `woocommerce-openpos/lib/class-op-table.php`.

## Qué corrige

Corrige tres cosas:

1. El vendor puede entregar `request_takeaway` como `string`. Sin la guarda, OpenPOS genera warnings tipo:

```text
PHP Warning: foreach() argument must be of type array|object, string given
```

2. Algunos desks quedaron con `system_ver` en segundos mientras `ver` y `online_ver` ya estaban en milisegundos. El POS nativo usa esa versión para decidir si vuelve a pedir el desk. Resultado: el JSON vivo tenía items/seller, pero el módulo nativo de mesas seguía mostrándolo vacío.

3. Al escribir un desk, OpenPOS no limpiaba todas las caches runtime relevantes del desk/warehouse. El parche fuerza una invalidación más segura.

Síntomas si se pierde el parche:

- warnings `foreach() argument must be of type array|object, string given`
- mesas cargadas en Tavox/JSON pero vacías en el módulo nativo del POS
- sincronización inconsistente entre el desk vivo y la grilla nativa de mesas

## Política elegida

- `fail hard` si el archivo upstream cambió y ya no coincide con una firma soportada
- chequeo automático
- aplicación manual explícita
- backups fuera del árbol live del plugin

## Estados del checker

- `pristine_supported`: coincide con una versión vendor soportada. Se puede aplicar el parche.
- `patched_supported`: ya está parcheado y no hace falta tocarlo.
- `unknown_upstream`: el archivo cambió y no coincide con ninguna firma soportada. No aplicar.
- `patch_drift`: el archivo contiene anclas conocidas pero no coincide con una firma soportada. No aplicar.

## Comandos

Chequeo:

```bash
php ops/openpos-patch/check-openpos-patch.php \
  --file=/var/www/vhosts/clubsamsve.com/zonabclub.com/wp-content/plugins/woocommerce-openpos/lib/class-op-table.php \
  --report-dir=/var/www/vhosts/clubsamsve.com/zonabclub.com/.zonab-ops/reports
```

Aplicación manual:

```bash
php ops/openpos-patch/apply-openpos-patch.php \
  --file=/var/www/vhosts/clubsamsve.com/zonabclub.com/wp-content/plugins/woocommerce-openpos/lib/class-op-table.php \
  --backup-dir=/var/www/vhosts/clubsamsve.com/zonabclub.com/.zonab-ops/backups/openpos \
  --report-dir=/var/www/vhosts/clubsamsve.com/zonabclub.com/.zonab-ops/reports
```

## Regla operativa

1. Actualiza OpenPOS.
2. Corre `check-openpos-patch.php`.
3. Si devuelve `pristine_supported`, recién ahí corre `apply-openpos-patch.php`.
4. Si devuelve `unknown_upstream` o `patch_drift`, detén el proceso y revisa el diff del vendor manualmente.

No apliques este parche automáticamente sobre un upstream desconocido.
