# AGENTS.md - Contexto Operativo para Nuevas Implementaciones

Este archivo define el contexto minimo necesario para implementar nuevas funcionalidades sin romper lo existente, con foco en `05-tienda` y sus integraciones.

## 1) Objetivo y regla principal

- Objetivo: extender la tienda virtual de forma incremental y segura.
- Regla innegociable: no modificar ni destruir comportamiento ya funcional.
- Todo cambio debe ser compatible hacia atras a nivel de:
  - rutas HTML existentes,
  - contratos JSON de APIs consumidas por el frontend,
  - flujos de sesion (cliente/admin),
  - estructura visual base en paginas actuales.

## 2) Alcance real del modulo de tienda

Carpeta principal: `05-tienda/`

### Frontend cliente (paginas activas)

- `05-tienda/index.html`: catalogo, filtros, destacados, modal detalle.
- `05-tienda/producto.html`: detalle producto.
- `05-tienda/carrito.html`: carrito.
- `05-tienda/checkout.html`: checkout.
- `05-tienda/confirmacion.html`: confirmacion.
- `05-tienda/mis-pedidos.html`: historial y detalle de pedidos.

### Frontend admin (paginas activas)

- `05-tienda/admin/index.html`: dashboard, bajo stock, umbral.
- `05-tienda/admin/productos.html` + `05-tienda/admin/admin-productos.js`: CRUD productos, subida de imagen, vinculacion tarjetas contenido.
- `05-tienda/admin/categorias.html`: CRUD categorias.
- `05-tienda/admin/pedidos.html`: gestion de pedidos y estado.

### Backend API (activos)

- `05-tienda/php/config.php`: conexion, helpers, sesion, roles, constantes de negocio.
- `05-tienda/php/productos.php`: listar, detalle, buscar, destacados.
- `05-tienda/php/categorias.php`: listar/detalle categorias publicas.
- `05-tienda/php/carrito.php`: ver/agregar/actualizar/eliminar/vaciar/contar.
- `05-tienda/php/pedidos.php`: verificar checkout, crear pedido, historial, detalle.
- `05-tienda/php/admin.php`: dashboard + CRUD admin + estados + bajo stock.
- `05-tienda/php/admin-tarjetas.php`: vinculos de tarjetas giratorias (JSON).
- `05-tienda/php/sesion.php`: estado de sesion para UI.
- `05-tienda/php/logout.php` y `05-tienda/php/logout_admin.php`.

## 3) Archivos legacy (no tocar salvo migracion explicita)

Existen variantes `*Org*` y `*copy*` en `05-tienda/` y `05-tienda/php/`/`05-tienda/js/`.

- No usarlos como base de nuevas features.
- No borrarlos en cambios funcionales normales.
- Solo intervenirlos si se planifica una limpieza/migracion dedicada.

## 4) Flujo funcional actual (cliente)

1. Catalogo (`js/tienda.js`) carga categorias + productos + destacados.
2. Producto se agrega al carrito via `php/carrito.php?action=agregar`.
3. Carrito (`js/carrito.js`) consulta `action=ver` y calcula resumen (ya viene del backend).
4. Checkout (`js/checkout.js`) valida sesion con `pedidos.php?action=verificar_checkout`.
5. Si procede, crea pedido con `pedidos.php?action=crear` y redirige a `confirmacion.html?pedido=...`.
6. Historial en `mis-pedidos.html` consume `pedidos.php?action=historial` y `action=detalle`.

## 5) Sesion y roles (punto critico)

- Login principal se establece en `02-iniciarSesion/php/iniciarSesion.php`:
  - guarda `$_SESSION['email']`, `$_SESSION['nombres']`, `$_SESSION['apellidos']`, `$_SESSION['rol']`.
- Reglas activas en tienda:
  - `esAdmin()` (lista de emails en `config.php`).
  - `esClienteLogueado()` evita compras con sesion admin.
- `js/sesion-ui.js` reemplaza boton "Cuenta" por info + logout solo para cliente logueado.

## 6) Integracion externa importante (04-contenido)

Las tarjetas giratorias de `04-contenido/php/contenido.php` leen:

- `05-tienda/php/tarjetas-contenido.json` (si existe), clave `productos_vinculados`.
- Luego consultan DB de tienda para renderizar esos productos.

Por lo tanto, cambios en `admin-tarjetas.php` afectan tambien `04-contenido`.

## 7) Modelo de datos inferido en uso (no romper contratos)

Tablas usadas activamente:

- `categorias` (id, nombre, descripcion, imagen, activo).
- `productos` (id, nombre, descripcion, descripcion_corta, precio, precio_oferta, categoria_id, stock, sku, marca, destacado, activo, imagen, vistas).
- `carrito` (id, usuario_id/session_id, producto_id, cantidad, fecha_agregado).
- `pedidos` (id, numero_pedido, usuario_id, subtotal, costo_envio, impuestos, total, estado, metodo_pago, datos_envio..., fecha_creacion).
- `detalle_pedido` (pedido_id, producto_id, nombre_producto, cantidad, precio_unitario, subtotal).
- `configuracion_tienda` (clave, valor) para `umbral_bajo_stock`.
- `registros` (modulo de autenticacion; se usa para mapear email -> id y datos cliente).

## 8) Contratos API que el frontend ya espera

Mantener claves y estructura, especialmente:

- `productos.php`:
  - `success`, `productos[]`, `umbral_bajo_stock`, `paginacion`.
- `carrito.php?action=ver`:
  - `carrito.items`, `subtotal`, `costo_envio`, `total`, valores formateados.
- `pedidos.php?action=verificar_checkout`:
  - flags `requiere_login`, `es_admin`, `carrito_vacio`.
- `admin.php`:
  - acciones por query string (`action=...`) usadas directamente por vistas admin.

Si se agrega informacion nueva, hacerlo sin quitar campos existentes.

## 9) Configuracion y constantes (fuente de verdad)

Tomar siempre como fuente de verdad `05-tienda/php/config.php`:

- Moneda: COP.
- Costo envio base: `TIENDA_COSTO_ENVIO_BASE`.
- Minimo envio gratis: `TIENDA_ENVIO_GRATIS_MINIMO`.
- IVA: `TIENDA_IVA`.

Nota: evitar asumir valores de README si difieren del codigo.

## 10) Reglas para implementar nuevas funcionalidades

### 10.1 Principios

- Cambios pequenos y acotados por feature.
- Mantener compatibilidad de UI y API.
- No mezclar refactors grandes con nuevas funcionalidades.
- Preservar estilos base (`css/tienda.css`) y clases existentes.

### 10.2 Backend

- Validar y sanitizar entradas (`sanitizar`, `intval`, `floatval`, etc.).
- Responder siempre JSON consistente (`jsonResponse`).
- Mantener control de permisos en endpoints admin.
- En operaciones criticas (pedido/stock), usar transacciones como en flujo actual.

### 10.3 Frontend

- Respetar convencion actual de consumo por `fetch` + `action=...`.
- Evitar romper IDs/clases usadas por JS existente.
- Si se agrega pagina nueva, incluir `js/sesion-ui.js` cuando aplique.

### 10.4 Archivos y rutas

- No mover endpoints actuales sin crear compatibilidad.
- Si se crean nuevos endpoints, seguir patron `php/<modulo>.php?action=<accion>`.

## 11) Checklist de no-regresion por cambio

Antes de cerrar una implementacion, validar manualmente:

1. Catalogo carga productos/categorias sin errores JS.
2. Agregar al carrito funciona desde catalogo y detalle.
3. Carrito permite actualizar/eliminar/vaciar.
4. Checkout:
   - bloquea no logueado,
   - bloquea admin,
   - crea pedido con cliente.
5. Mis pedidos lista historial y abre detalle.
6. Admin:
   - dashboard carga,
   - CRUD de productos/categorias,
   - actualizacion de estado de pedido,
   - bajo stock y umbral.
7. Si se toca tarjetas: confirmar impacto en `04-contenido/php/contenido.php`.

## 12) Riesgos conocidos y cuidado especial

- Hay dependencias entre modulos (`05-tienda` <-> `04-contenido`) por tarjetas vinculadas.
- Existen credenciales DB en codigo; no replicarlas en nuevos archivos/documentos.
- Hay varias versiones legacy (`Org/copy`) que pueden confundir; usar archivos activos listados arriba.
- Evitar cambios masivos en `config.php`: afecta todo el modulo.

## 13) Como mantener este AGENTS.md actualizado

Actualizar este archivo en cada feature que cambie alguno de estos puntos:

- nuevas rutas o paginas,
- nuevos endpoints o cambios de contrato,
- nuevas tablas/campos o reglas de negocio,
- cambios en sesion/roles/permisos,
- integraciones con otros modulos.

Formato sugerido para cada actualizacion al final del archivo:

```
## Registro de cambios del contexto
- YYYY-MM-DD: breve descripcion de lo agregado/cambiado y archivos impactados.
```

---

Este documento es obligatorio como contexto base para cualquier nueva implementacion en tienda.

## Registro de cambios del contexto
- 2026-06-21: creacion inicial de AGENTS.md con alcance, contratos API, flujos y checklist de no-regresion para `05-tienda`.
