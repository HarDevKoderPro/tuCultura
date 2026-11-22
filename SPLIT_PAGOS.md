# Especificación técnica: Split de pagos con Mercado Pago (Colombia)

> **Para Claude Code:** este documento es la especificación de la funcionalidad de pagos de la tienda.
> Léelo completo antes de proponer cambios. La funcionalidad **sí es implementable**: Mercado Pago ofrece
> oficialmente "Split de Pagos 1:N" en Colombia (ver Fuentes). Las restricciones reales son **comerciales y de
> cuentas**, no técnicas, y están listadas en la sección 2. No bloquees el desarrollo por ellas: construye todo
> detrás de una interfaz de proveedor (sección 5) con un proveedor simulado (`MockProvider`) mientras llegan las
> credenciales habilitadas. Donde este documento diga **[CONFIRMAR]**, el dato exacto debe tomarse de la
> documentación oficial de la Orders API cuando la cuenta esté habilitada; no inventes nombres de campos.

---

## 1. Objetivo

La tienda (desarrollo propio) cobra al cliente **un solo pago** y ese dinero se reparte **automáticamente, en la
misma transacción**, entre varios actores, sin pasar primero por la cuenta del dueño de la tienda:

| Actor | Recibe |
|---|---|
| Proveedor | Costo del producto |
| Mensajería | Costo del envío |
| Tienda (dueño) | El resto (margen) |

Se pueden agregar más actores en el futuro (máximo 10 secundarios por orden).

**Fuera de alcance / NO hacer:**
- NO cobrar todo a la cuenta de la tienda y luego transferir manualmente o por "pagos a terceros" (eso es
  dispersión posterior, no split, y genera 4x1000 por cada movimiento).
- NO usar Stripe Connect: Stripe no abre cuentas a empresas colombianas.

---

## 2. Hechos verificados (documentación oficial de Mercado Pago)

1. **Producto:** "Split Payments 1:N" — distribuye un pago entre un vendedor principal y uno o más vendedores
   secundarios, por **monto fijo o porcentaje**, con validación automática y conciliación por `external_reference`.
2. **Disponible en Colombia** (la página del producto lista Colombia entre los países).
3. **Integración:** vía **Orders API** (Checkout Transparente / Checkout API). El comprador hace un solo pago y cada
   participante recibe su parte en **su propia cuenta**.
4. **Reglas del split:**
   - Arreglo `splits` con, por cada destinatario: `user_id`, monto (o porcentaje) y `description`.
   - La suma de montos/porcentajes **debe ser igual al total de la orden**.
   - **Máximo 10 vendedores secundarios** por orden.
   - Validación de destinatarios, montos, usuarios y **KYC**. Errores en formato **RFC 7807**.
5. **Reembolsos:** parciales o totales, especificados **en los splits**.
6. **Conciliación:** reporte de ventas de Split + Release Report, cruzando por `external_reference`. Notificaciones
   en tiempo real por **Webhooks**.
7. **Restricciones reales (no técnicas):**
   - **Requiere contacto comercial:** solo se puede integrar cuando el asesor comercial de Mercado Pago habilita
     el producto y comparte la información necesaria. → *El dueño de la tienda gestiona esto; tú no lo bloquees.*
   - Cada actor (tienda, proveedor, mensajería) necesita **cuenta de vendedor en Mercado Pago** con KYC aprobado.
   - En producción, las páginas de checkout deben servirse por **HTTPS** con certificado SSL válido.

### Pasos oficiales de integración
1. Crear aplicación con la Orders API en "Tus integraciones".
2. Configurar entorno de desarrollo y credenciales.
3. Crear **cuentas de prueba** para cada participante del split.
4. Crear la orden con splits.
5. Verificar la orden: estado y arreglo `splits`.
6. Implementar reembolsos con splits.
7. Configurar notificaciones (webhooks).
8. Revisar reportes y conciliar.
9. Probar y pasar a producción.

---

## 3. Arquitectura

```
[Frontend checkout]
      │  (solo envía pedido_id + token/datos de pago; NUNCA montos del split)
      ▼
[Backend tienda]
  1. Carga pedido desde BD y recalcula totales (no confiar en el cliente)
  2. calcularSplit(pedido)  → valida suma == total
  3. PaymentProvider.crearOrden(pedido, splits)   (idempotente por pedido)
  4. Guarda orden_proveedor_id + estado "pendiente"
      │
      ▼
[Mercado Pago Orders API] ── cobra y reparte ──► cuentas MP de proveedor / mensajería / tienda
      │
      ▼  webhook
[Backend /webhooks/mercadopago]
  - Verifica firma/origen [CONFIRMAR mecanismo de firma en doc oficial]
  - Consulta la orden a la API (no confiar solo en el cuerpo del webhook)
  - Actualiza estado del pedido (idempotente)
```

Regla de oro: **el pedido se marca "pagado" solo por webhook + consulta a la API**, nunca por la redirección del
navegador.

---

## 4. Modelo de datos

```sql
CREATE TABLE actores (
  id            SERIAL PRIMARY KEY,
  tipo          VARCHAR(20) NOT NULL CHECK (tipo IN ('tienda','proveedor','mensajeria','otro')),
  nombre        VARCHAR(120) NOT NULL,
  mp_user_id    VARCHAR(40),            -- ID de usuario Mercado Pago del actor
  kyc_ok        BOOLEAN NOT NULL DEFAULT FALSE,
  activo        BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE pedidos (
  id                  SERIAL PRIMARY KEY,
  external_reference  VARCHAR(60) UNIQUE NOT NULL,   -- ej. 'PED-000123'
  total               BIGINT NOT NULL,               -- COP, entero (sin decimales)
  costo_envio         BIGINT NOT NULL,
  estado              VARCHAR(20) NOT NULL DEFAULT 'creado'
                      CHECK (estado IN ('creado','pendiente','pagado','rechazado','reembolsado','reembolso_parcial')),
  proveedor_orden_id  VARCHAR(80),                   -- ID de la orden en Mercado Pago
  creado_en           TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE pedido_items (
  id               SERIAL PRIMARY KEY,
  pedido_id        INT NOT NULL REFERENCES pedidos(id),
  producto_id      INT NOT NULL,
  proveedor_id     INT NOT NULL REFERENCES actores(id),
  cantidad         INT NOT NULL,
  precio_venta     BIGINT NOT NULL,
  costo_proveedor  BIGINT NOT NULL
);

CREATE TABLE pedido_splits (
  id          SERIAL PRIMARY KEY,
  pedido_id   INT NOT NULL REFERENCES pedidos(id),
  actor_id    INT NOT NULL REFERENCES actores(id),
  monto       BIGINT NOT NULL,
  descripcion VARCHAR(120)
);

CREATE TABLE webhook_eventos (            -- para idempotencia y auditoría
  id            SERIAL PRIMARY KEY,
  evento_id     VARCHAR(80) UNIQUE NOT NULL,
  payload       JSONB NOT NULL,
  procesado     BOOLEAN NOT NULL DEFAULT FALSE,
  recibido_en   TIMESTAMP NOT NULL DEFAULT NOW()
);
```

---

## 5. Interfaz de proveedor (desacoplar de Mercado Pago)

Todo el código de negocio depende de esta interfaz, no de Mercado Pago directamente. Así se puede desarrollar y
probar ya, y cambiar a ePayco (plan B) sin reescribir la tienda.

```ts
interface SplitLinea {
  actorId: number;
  mpUserId: string;
  monto: number;        // COP entero
  descripcion: string;
}

interface PaymentProvider {
  crearOrden(pedido: Pedido, splits: SplitLinea[], datosPago: unknown): Promise<{ ordenId: string; estado: string }>;
  consultarOrden(ordenId: string): Promise<{ estado: string; splits: SplitLinea[] }>;
  reembolsar(ordenId: string, splits: SplitLinea[]): Promise<void>;   // parcial o total, por split
  verificarWebhook(headers: Record<string,string>, rawBody: string): boolean;
}

class MockProvider implements PaymentProvider { /* simula aprobación/rechazo; usar en dev y tests */ }
class MercadoPagoProvider implements PaymentProvider { /* implementación real, ver sección 7 */ }
```

Seleccionar proveedor por variable de entorno: `PAYMENT_PROVIDER=mock|mercadopago`.

---

## 6. Cálculo del split (lógica de negocio)

```ts
function calcularSplit(pedido, items, actores): SplitLinea[] {
  // 1. Agrupar costo por proveedor (puede haber varios proveedores en un pedido)
  const porProveedor = new Map<number, number>();
  for (const it of items) {
    porProveedor.set(it.proveedor_id, (porProveedor.get(it.proveedor_id) ?? 0) + it.costo_proveedor * it.cantidad);
  }

  const lineas: SplitLinea[] = [];
  for (const [provId, monto] of porProveedor) {
    lineas.push({ actorId: provId, mpUserId: actores[provId].mp_user_id, monto, descripcion: 'Costo producto' });
  }
  lineas.push({ actorId: MENSAJERIA_ID, mpUserId: actores[MENSAJERIA_ID].mp_user_id,
                monto: pedido.costo_envio, descripcion: 'Envío' });

  const secundarios = lineas.reduce((s, l) => s + l.monto, 0);
  const margenTienda = pedido.total - secundarios;

  // 2. Validaciones obligatorias
  if (lineas.length > 10) throw new Error('Máximo 10 destinatarios secundarios por orden');
  if (margenTienda < 0)   throw new Error('Margen negativo: revisar precios/costos');
  for (const l of lineas) {
    if (!l.mpUserId)           throw new Error(`Actor ${l.actorId} sin cuenta Mercado Pago`);
    if (!actores[l.actorId].kyc_ok) throw new Error(`Actor ${l.actorId} sin KYC aprobado`);
  }

  // 3. La tienda es el vendedor principal y recibe el resto.
  //    [CONFIRMAR] si la doc exige incluir explícitamente la línea de la tienda en `splits`
  //    para que la suma sea == total; si es así, agregarla aquí con monto = margenTienda.
  return lineas;
}
```

**Decisión de negocio pendiente (parametrizable):** quién asume la comisión de Mercado Pago
(`COMISION_ASUME=tienda|proporcional`). Por defecto: la asume la tienda (se descuenta de su margen).

Todos los montos en **COP enteros** (BIGINT). Nunca usar float para dinero.

---

## 7. MercadoPagoProvider (implementación real)

Esqueleto ilustrativo. **Endpoint, nombres exactos de campos, autenticación de cada vendedor (OAuth/user_id) y
firma del webhook son [CONFIRMAR]** con la documentación de la Orders API / Split 1:N una vez habilitada la cuenta.

```ts
async crearOrden(pedido, splits, datosPago) {
  const res = await fetch('https://api.mercadopago.com/v1/orders', {          // [CONFIRMAR]
    method: 'POST',
    headers: {
      Authorization: `Bearer ${process.env.MP_ACCESS_TOKEN}`,
      'Content-Type': 'application/json',
      'X-Idempotency-Key': `pedido-${pedido.id}`                              // evita cobro doble
    },
    body: JSON.stringify({
      external_reference: pedido.external_reference,
      total_amount: String(pedido.total),                                     // [CONFIRMAR] tipo/campo
      splits: splits.map(s => ({ user_id: s.mpUserId, amount: String(s.monto), description: s.descripcion })), // [CONFIRMAR]
      // ...datos de pago del checkout elegido                                // [CONFIRMAR]
    })
  });
  if (!res.ok) throw await parseProblemJson(res);   // errores RFC 7807: { type, title, status, detail, ... }
  const data = await res.json();
  return { ordenId: data.id, estado: data.status };
}
```

Variables de entorno: `MP_ACCESS_TOKEN`, `MP_PUBLIC_KEY`, `MP_WEBHOOK_SECRET`, `PAYMENT_PROVIDER`.
Nunca subir credenciales al repositorio (`.env` en `.gitignore`).

---

## 8. Webhook

`POST /webhooks/mercadopago`
1. Leer **raw body** y verificar firma/origen con `verificarWebhook` → si falla, 401.
2. Guardar en `webhook_eventos` (si `evento_id` ya existe, responder 200 y no reprocesar).
3. Consultar la orden con `consultarOrden(ordenId)` (fuente de verdad).
4. Actualizar `pedidos.estado` según el estado real. Transiciones válidas solamente.
5. Responder **200 rápido**; trabajo pesado en cola si es necesario.

---

## 9. Reembolsos

- Total o parcial, indicando montos **por split** (qué parte devuelve cada actor).
- Ejemplo: producto llegó dañado → se reembolsa la línea del proveedor y opcionalmente la de la tienda; la
  mensajería conserva su parte si el envío se realizó.
- Registrar cada reembolso y actualizar estado a `reembolsado` o `reembolso_parcial`.

---

## 10. Pruebas

- Unitarias de `calcularSplit`: suma == total, margen negativo, >10 destinatarios, actor sin `mp_user_id`, varios
  proveedores en un pedido.
- Integración con `MockProvider`: flujo completo pedido → orden → webhook → pagado; webhook duplicado; rechazo.
- Sandbox Mercado Pago: **cuentas de prueba para cada participante** (tienda, proveedor, mensajería) + tarjetas de
  prueba. Verificar en cada cuenta de prueba que llegó su parte.

---

## 11. Criterios de aceptación

- [ ] Un pedido con productos de 1+ proveedores y envío genera un split cuya suma es exactamente el total.
- [ ] El frontend nunca envía montos del split; el backend los recalcula.
- [ ] Crear orden es idempotente (reintentar no cobra dos veces).
- [ ] El estado "pagado" solo cambia por webhook verificado + consulta a la API.
- [ ] Webhooks duplicados no duplican efectos.
- [ ] Reembolso total y parcial por split funcionando en sandbox.
- [ ] Funciona con `PAYMENT_PROVIDER=mock` sin credenciales reales.
- [ ] Panel/admin para registrar actores con su `mp_user_id` y marcar KYC.

---

## 12. Plan B: ePayco "Pagos Divididos"

Si Mercado Pago no habilita el producto, ePayco (Colombia, propiedad de Davivienda) ofrece "Pagos Divididos", que
divide un pago entre varios beneficiarios dentro de la misma transacción. Gracias a la interfaz de la sección 5,
solo habría que implementar `EpaycoProvider`.

---

## Fuentes

- Split Payments 1:N (Colombia), resumen: https://www.mercadopago.com.co/developers/en/docs/split-payments-1-n/overview
- Split Payments 1:N (versión markdown, requisitos y límites): https://www.mercadopago.com.co/developers/en/docs/split-payments-1-n/overview.md
- Split de Pagos 1:1 (disponibilidad por país): https://www.mercadopago.com.co/developers/es/docs/split-payments/split-1-1/overview
- Crear aplicación (inicio de integración): https://www.mercadopago.com.co/developers/en/docs/split-payment/split-1-n/create-application
- ePayco Pagos Divididos: https://www.bluradio.com/economia/herramienta-le-evitaria-pagar-el-4x1000-a-empresas-asi-funciona-so35
