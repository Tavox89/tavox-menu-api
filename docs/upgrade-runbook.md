# Upgrade Runbook: Tavox + OpenPOS

Este runbook deja el flujo seguro para futuras actualizaciones de Zona B.

## 1. Regla base

- No guardes backups operativos dentro de `wp-content/plugins/tavox-menu-api`.
- No uses copia directa por SSH a archivos live como flujo normal de release.
- El camino normal de release de Tavox es un zip limpio.

## 2. Dónde van backups y reportes

Ejemplo recomendado en producción:

- `/var/www/vhosts/clubsamsve.com/zonabclub.com/.zonab-ops/backups/tavox-menu-api/`
- `/var/www/vhosts/clubsamsve.com/zonabclub.com/.zonab-ops/backups/openpos/`
- `/var/www/vhosts/clubsamsve.com/zonabclub.com/.zonab-ops/reports/`

Ningún `.bak.*` debe quedar dentro del árbol live del plugin.

## 3. Cómo empaquetar Tavox

Desde el repo local:

```bash
cd "/Users/gustavogonzalez/Documents/proyectos mac/tavox-menu-api"
./ops/build-plugin-zip.sh
```

El script genera un zip limpio en `artifacts/` y excluye:

- `.bak.*`
- `ops/`
- `docs/`
- `.DS_Store`
- artefactos locales

## 4. Cómo actualizar Tavox sin romper el updater nativo

1. Verifica que el árbol live no tenga backups:

```bash
find /var/www/vhosts/clubsamsve.com/zonabclub.com/wp-content/plugins/tavox-menu-api -name '*.bak.*'
```

2. Si aparecen resultados, muévelos fuera del plugin a `.zonab-ops/backups/tavox-menu-api/`.
3. Sube e instala el zip limpio generado por `ops/build-plugin-zip.sh`.
4. Si el updater nativo sigue fallando aun con árbol limpio, documenta el bloqueo como problema de ownership/permisos del árbol live. No vuelvas a dejar backups dentro del plugin.

## 5. Cómo revisar ownership/permisos

Chequeos útiles:

```bash
find /var/www/vhosts/clubsamsve.com/zonabclub.com/wp-content/plugins/tavox-menu-api -maxdepth 2 -printf '%M %u:%g %p\n' | head -n 40
```

Si el árbol quedó mezclado por hotfixes vía SSH, el camino preferido es volver al release nativo desde zip limpio. Si aun así WordPress no logra reescribir el árbol, eso ya es una remediación de infraestructura.

## 6. Cómo actualizar OpenPOS sin pisar el parche

El archivo parcheado es:

- `woocommerce-openpos/lib/class-op-table.php`

Síntomas si se pierde el parche:

- warnings `foreach() argument must be of type array|object, string given`
- mesas con JSON vivo cargado pero tarjetas vacías en el módulo nativo de mesas del POS
- desk sync incoherente por `system_ver` en segundos frente a `ver/online_ver` en milisegundos

### Procedimiento

1. Actualiza OpenPOS.
2. Corre el checker:

```bash
php ops/openpos-patch/check-openpos-patch.php \
  --file=/var/www/vhosts/clubsamsve.com/zonabclub.com/wp-content/plugins/woocommerce-openpos/lib/class-op-table.php \
  --report-dir=/var/www/vhosts/clubsamsve.com/zonabclub.com/.zonab-ops/reports
```

3. Si devuelve `pristine_supported`, aplica el parche manualmente:

```bash
php ops/openpos-patch/apply-openpos-patch.php \
  --file=/var/www/vhosts/clubsamsve.com/zonabclub.com/wp-content/plugins/woocommerce-openpos/lib/class-op-table.php \
  --backup-dir=/var/www/vhosts/clubsamsve.com/zonabclub.com/.zonab-ops/backups/openpos \
  --report-dir=/var/www/vhosts/clubsamsve.com/zonabclub.com/.zonab-ops/reports
```

4. Si devuelve `patched_supported`, no hagas nada.
5. Si devuelve `unknown_upstream` o `patch_drift`, no parchees. Revisa manualmente el diff del vendor y actualiza el manifest antes de tocar producción.

## 7. Validación mínima post-upgrade

Después de actualizar Tavox o OpenPOS, vuelve a validar:

- `pedido -> claim -> accept`
- `Servicio = A tu cargo`
- `Solicitar al mesero`
- `Cocina`
- `Barra`

El objetivo no es sólo que llegue un aviso, sino que Tavox, OpenPOS y Servicio lean la misma verdad operativa.
