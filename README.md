# Tavox Menu API

Plugin WordPress para exponer el menú de WooCommerce al frontend de Zona B y sostener la capa operativa Tavox sobre OpenPOS: mesa pública, pedidos, servicio, cocina, horno, barra, mensajes de mesa, push y realtime.

## Identidad del plugin

- Nombre: `Tavox Menu API`
- Versión actual: `2.9.19`
- Desarrollado por: `ASD Labs`
- Uso principal: backend editorial, API REST del menú y lógica operativa Tavox de Zona B

## Qué hace

- Expone sólo las categorías visibles del menú configuradas en el admin.
- Expone productos publicados del menú con filtro real por categoría y búsqueda `q`.
- Normaliza la búsqueda para que funcione sin tildes, sin importar mayúsculas/minúsculas y también por nombre de categoría visible.
- Devuelve extras Tavox con identificadores estables para soportar repetición de pedidos.
- Expone una secuencia de promociones activas lista para consumir desde el frontend.
- Soporta promociones regulares y promos tipo evento con agenda e invitados.
- Invalida caché por versión cuando cambia catálogo, stock, relaciones de categorías, grupos Tavox o configuración editorial.

## Endpoints

### `GET /wp-json/tavox/v1/categories`

Devuelve las categorías visibles del menú en el orden configurado.

Campos principales:

- `id`
- `name`
- `slug`
- `aliases`
- `enabled`
- `order`
- `image`

### `GET /wp-json/tavox/v1/products?category=<id|slug>&q=<buscar>`

Devuelve productos visibles del menú.

Comportamiento:

- `category` acepta `id`, `slug`, vacío o `0`.
- `q` busca por nombre de producto, categorías visibles del menú y coincidencias editoriales (`aliases`).
- La respuesta mantiene compatibilidad con `categories`, pero añade información editorial del menú para no depender de categorías internas de WooCommerce.

Campos principales:

- `id`
- `sku`
- `slug`
- `name`
- `short_description`
- `description`
- `price_usd`
- `in_stock`
- `stock_qty`
- `image`
- `categories`
- `menu_category_ids`
- `primary_menu_category_id`
- `extras`

Cada grupo en `extras` incluye:

- `group_id`
- `label`
- `multiple`
- `options`

Cada opción incluye:

- `id`
- `option_id`
- `group_id`
- `label`
- `price`

### `GET /wp-json/tavox/v1/promotions`

Devuelve sólo promociones activas y ordenadas.

Campos principales:

- `id`
- `product_id`
- `order`
- `badge`
- `title`
- `copy`
- `promo_style`
- `event_meta`
- `event_guests`
- `show_in_search`
- `image`
- `starts_at`
- `ends_at`
- `product`

`product` usa el mismo shape de `GET /products`.

## Admin

El plugin agrega el menú `Menú Tavox` con dos pantallas:

- `Categorías`: orden y visibilidad de la barra superior y agrupación editorial del menú.
- `Promociones`: secuencia promocional con producto, estilo regular/evento, badge, título, copy, agenda, invitados, búsqueda, imagen override e intervalo de vigencia.

Los guardados son idempotentes: guardar dos veces la misma configuración deja el mismo estado, sin duplicados.

## Caché

Las respuestas REST usan claves derivadas de:

- recurso
- parámetros relevantes
- versión actual del catálogo

La versión sube automáticamente cuando cambia:

- un producto
- el stock o estado de stock
- la relación producto/categoría
- una categoría `product_cat`
- la meta `_tavox_groups`
- la configuración de categorías visibles
- la configuración de promociones

## Instalación

1. Copia `tavox-menu-api` dentro de `wp-content/plugins/`.
2. Activa el plugin desde WordPress.
3. Verifica que WooCommerce y Tavox Extras estén activos.

## Operación y upgrades

- El flujo seguro de actualización está documentado en [docs/upgrade-runbook.md](docs/upgrade-runbook.md).
- El parche defensivo de OpenPOS se administra desde [ops/openpos-patch/README.md](ops/openpos-patch/README.md).
- El zip limpio del plugin se genera con:

```bash
./ops/build-plugin-zip.sh
```

Regla importante: no dejes `.bak.*` dentro de `wp-content/plugins/tavox-menu-api`. Los backups operativos deben ir fuera del árbol live del plugin.

## Ejemplos

```bash
curl -sS https://tusitio.com/wp-json/tavox/v1/categories
curl -sS "https://tusitio.com/wp-json/tavox/v1/products?category=335&q=crispy"
curl -sS https://tusitio.com/wp-json/tavox/v1/promotions
```
