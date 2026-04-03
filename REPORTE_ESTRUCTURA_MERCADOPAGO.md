# Reporte de Estructura Actual - Integración MercadoPago
## Tu Cultura es Progreso

---

### 1. Estructura de Base de Datos

**BD:** `tucultur_asociados` | **Host:** `190.8.176.115` | **Motor:** MySQL (PDO + MySQLi)

#### Tablas identificadas:

| Tabla | Campos clave | Propósito |
|-------|-------------|-----------|
| `registros` | `id`, `nombres`, `apellidos`, `documento`, `telefono`, `email`, `pass`, `referente`, `fecha` | Usuarios (clientes + admins) |
| `productos` | `id`, `nombre`, `descripcion`, `descripcion_corta`, `precio`, `precio_oferta`, `categoria_id`, `stock`, `sku`, `marca`, `destacado`, `activo`, `imagen`, `vistas` | Catálogo de productos |
| `categorias` | `id`, `nombre`, `descripcion`, `imagen`, `activo` | Categorías de productos |
| `pedidos` | `id`, `numero_pedido`, `usuario_id`, `subtotal`, `costo_envio`, `impuestos`, `total`, `metodo_pago`, `nombre_envio`, `apellido_envio`, `telefono_envio`, `email_envio`, `direccion_envio`, `ciudad_envio`, `departamento_envio`, `codigo_postal`, `notas`, `estado`, `fecha_creacion` | Pedidos de compra |
| `detalle_pedido` | `id`, `pedido_id`, `producto_id`, `nombre_producto`, `cantidad`, `precio_unitario`, `subtotal` | Items de cada pedido |
| `carrito` | `id`, `usuario_id`, `session_id`, `producto_id`, `cantidad` | Carrito temporal |
| `configuracion_tienda` | `id`, `clave`, `valor`, `descripcion`, `fecha_modificacion` | Config global (ej: umbral_bajo_stock) |

---

### 2. Flujo Actual de Checkout (paso a paso)

1. **Cliente accede a `checkout.html`** → JS llama `pedidos.php?action=verificar_checkout`
2. **Verificación:** sesión activa + es cliente (no admin) + carrito no vacío
3. **Se cargan datos del carrito** vía `carrito.php?action=ver`
4. **Se renderiza formulario** con datos del usuario pre-llenados (nombre, email, teléfono)
5. **Métodos de pago disponibles:** Solo `contraentrega` y `transferencia` (radio buttons, sin procesamiento real)
6. **Al confirmar:** `POST` a `pedidos.php?action=crear` con FormData
7. **Backend crea pedido** en transacción:
   - Valida stock de cada producto
   - `INSERT INTO pedidos` con totales calculados
   - `INSERT INTO detalle_pedido` por cada item
   - `UPDATE productos` (descuenta stock)
   - `DELETE FROM carrito` (vacía carrito del usuario)
8. **Redirección a `confirmacion.html`** con número de pedido

**⚠️ NO hay integración de pagos online actual. Los métodos son solo indicativos.**

---

### 3. Relación Producto-Proveedor

**❌ NO EXISTE.** La tabla `productos` NO tiene campo `proveedor_id` ni similar.

**❌ NO existe tabla `proveedores`.**

Los admins se identifican por email hardcodeado en `config.php`:
```php
$admins = ['luisfer5428@gmail.com', 'haroldvaldes@yahoo.com'];
```

No hay concepto de roles, proveedores ni multivendedor en la BD actual.

---

### 4. Cómo se Almacenan los Pedidos

- **Pedido principal:** tabla `pedidos` con totales globales (subtotal, envío, impuestos, total)
- **Detalle:** tabla `detalle_pedido` con producto_id, cantidad, precio_unitario, subtotal por item
- **Estados:** campo `estado` en pedidos (valores: `pendiente`, `cancelado`, otros posibles)
- **No hay:** tabla de pagos, transacciones, ni registros de métodos de pago procesados
- **Moneda:** COP (Pesos Colombianos), sin IVA configurado (TIENDA_IVA = 0)
- **Envío gratis** desde $20.000 COP, si no $15.000 COP

---

### 5. Qué Falta para Implementar Pagos Fraccionados con MercadoPago

#### Tablas nuevas necesarias:
1. **`proveedores`** — id, nombre, email, telefono, mercadopago_access_token, mercadopago_user_id, comision_plataforma (%), activo, fecha_creacion
2. **`pagos`** — id, pedido_id, monto_total, monto_plataforma, monto_proveedor, estado_pago, mp_preference_id, mp_payment_id, mp_merchant_order_id, metodo_pago, fecha_pago
3. **`pagos_proveedor`** — id, pago_id, proveedor_id, monto, comision, neto, estado_transferencia, mp_transfer_id, fecha_transferencia
4. **`facturas`** — id, pedido_id, proveedor_id, numero_factura, monto, pdf_path, email_enviado, fecha_emision

#### Modificaciones a tablas existentes:
- **`productos`** → agregar `proveedor_id` (FK a proveedores)
- **`pedidos`** → agregar `mp_preference_id`, `mp_payment_id`, `estado_pago`

#### Funcionalidades a implementar:
1. **SDK MercadoPago PHP** — Crear preferencias de pago con split payment (Marketplace)
2. **Webhook/IPN** — Endpoint para recibir notificaciones de pago de MercadoPago
3. **Panel Admin: Gestión de Proveedores** — CRUD + configuración de tokens MP
4. **Facturación PDF** — Generador con librería (TCPDF/FPDF/Dompdf)
5. **Envío de emails** — PHPMailer o similar para envío de facturas
6. **Flujo de checkout modificado** — Redirigir a MercadoPago en lugar de confirmar directamente

#### Modelo de MercadoPago recomendado:
- **MercadoPago Marketplace (Split Payment)** — Permite que la plataforma reciba el pago y distribuya automáticamente entre proveedores, reteniendo comisión.
- Requiere: cuenta de Marketplace aprobada + OAuth de cada proveedor conectado.

---

### Resumen Ejecutivo

| Aspecto | Estado actual |
|---------|--------------|
| Pagos online | ❌ No implementado |
| Proveedores | ❌ No existen en BD |
| Relación producto-proveedor | ❌ No existe |
| Facturación | ❌ No existe |
| Envío de emails transaccionales | ❌ No existe |
| Estructura de pedidos | ✅ Funcional (pedidos + detalle_pedido) |
| Carrito funcional | ✅ Funcional (BD server-side) |
| Checkout flow | ✅ Funcional (pero sin pago real) |
| Panel admin | ✅ Funcional (productos, categorías, pedidos) |
