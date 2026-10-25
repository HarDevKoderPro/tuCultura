# CLAUDE.md - Contexto operativo de TuCultura

Contexto minimo para implementar nuevas funcionalidades sin romper lo existente, con foco en `05-tienda` y sus integraciones. Este documento reemplaza al antiguo `AGENTS.md`.

## 1) Objetivo y regla principal

- Objetivo: extender la tienda virtual de forma incremental y segura.
- Regla innegociable: no modificar ni destruir comportamiento ya funcional.
- Todo cambio debe ser compatible hacia atras en: rutas HTML existentes, contratos JSON consumidos por el frontend, flujos de sesion (cliente/admin) y estructura visual base.
- Idioma del proyecto: espanol (codigo, comentarios, commits y documentacion).

## 2) Stack y estructura

- PHP 8.3 procedural + mysqli/PDO, HTML/CSS/JS vanilla (sin build, sin Composer, sin npm). SweetAlert2 para dialogos.
- Modulos por carpeta numerada en la raiz:
  - `00-enConstruccion/`, `01-principal/` (assets compartidos), `index.html` (landing).
  - `02-iniciarSesion/`: login (`php/iniciarSesion.php`), recuperacion de clave (PHPMailer).
  - `03-registrarse/`: registro con email de referente (arbol de referidos). Con invitacion (`?ref=` o codigo guardado por `05-tienda/js/invitacion.js`) muestra "Te invito: X" en lugar del campo y envia `ref`; `validarEmails.php` y `registrarse.php` traducen el codigo al email (`emailReferentePorCodigo`, incluye `05-tienda/php/invitaciones-helpers.php`). Con `?volver=` (aviso de la tienda) al terminar va al login con `origen=tienda&returnUrl=producto.html?id=N` (permitido en `iniciarSesion.php`).
  - `04-contenido/`: zona de usuarios (`php/contenido.php`), niveles de referidos, video y libros.
  - `05-tienda/`: tienda virtual (ver seccion 3).

## 3) Modulo de tienda (`05-tienda/`)

### Frontend cliente
- `index.html` (catalogo, filtros, destacados, modal detalle) - `js/tienda.js` (al vaciar el buscador despues de una busqueda vuelve al listado con los filtros activos); `js/tienda-filtros.js` (en celular <768px la barra de filtros es un panel inferior con precio y "Solo destacados", y las categorias van en el desplegable `#select-categoria`). La fila `#nav-categorias` ya no se muestra en ningun tamano (escritorio usa la columna lateral) pero sigue en la pagina porque tienda.js la arma y el desplegable de celular se construye con ella. El grupo de radios "Ordenar por" de la columna esta oculto (`hidden`) pero se conserva: tienda.js lee el orden de esos radios y `#select-orden` (con marcador "Ordenar por") los sincroniza. En celular los productos van en dos columnas.
- `producto.html` - `js/producto.js`; `carrito.html` - `js/carrito.js`; `checkout.html` - `js/checkout.js`.
- `confirmacion.html`; `mis-pedidos.html` - `js/pedidos.js`; `js/sesion-ui.js` (menu "Mi cuenta" del icono de usuario).
- `js/compartir.js`: boton "Compartir" (estilo Mercado Libre) en el modal del catalogo (`#btn-modal-compartir`) y en `producto.html`; en celular abre el menu nativo del telefono, en computador una ventana con WhatsApp/Facebook/X/Telegram/Correo y enlace para copiar. Comparte `php/compartir.php?id=N`: pagina con etiquetas Open Graph (nombre, precio, descripcion, foto) que redirige a `producto.html?id=N`; `&imagen=1` entrega la foto en JPEG para la vista previa.
- `js/invitacion.js` (en todas las paginas con `sesion-ui.js`): guarda `?ref=` 30 dias en localStorage `tcp_invitacion` (gana el ultimo) y, sin sesion, muestra bajo la cabecera el aviso `#aviso-invitacion` "X te invito... Registrate" con boton a `03-registrarse/registrarse.html?ref=&volver=`; cerrarlo lo oculta en esa sesion del navegador. `window.TCPInvitacion.codigo()`.
- `js/galeria-producto.js` (`montarGaleriaProducto`, recibe `medios` o `imagenes`): foto grande + tira de miniaturas (los videos tienen miniatura con icono de reproducir y solo se ven en el visor, con controles; la tarjeta siempre muestra una foto) (solo si hay 2 o mas fotos; con mouse, pasar sobre una miniatura cambia la foto; en celular se desliza la foto) y visor a pantalla completa al hacer clic en una miniatura o en la foto grande (flechas, contador, miniaturas, Esc/teclado/deslizar; `.visor-fotos`, z-index 3000) en el modal del catalogo (`#detalle-miniaturas`, fotos de `productos.php?action=imagenes`) y en `producto.html` (`#producto-miniaturas`, de `producto.imagenes` del detalle).

### Frontend admin
- `admin/index.html` (dashboard, bajo stock, umbral, tarjeta "Puntos Asociados" con valor del punto en COP, "Puntos por compra" (tabla de rangos) y "Puntos por bono"), `admin/productos.html` + `admin/admin-productos.js` (CRUD, imagen, fotos adicionales que se suben/quitan al momento y la estrella las vuelve principal, un video por producto con barra de avance, tarjetas), `admin/categorias.html`, `admin/pedidos.html` (medio de pago legible bajo el total, p. ej. "MercadoPago · PSE"; boton QR "Enlace de cobro" en pedidos contra entrega sin pagar: QR, copiar enlace, WhatsApp al cliente y al transportista; al cambiar estado avisa los puntos dados/quitados), `admin/correos.html` (historial de correos de pedidos enviados, con vista del contenido y error si fallo), `admin/bonos.html` (reporte de bonos por puntos: tarjetas clicables Pendientes/Entregados/Asociados con bono/Eliminados que muestran su lista, vista por asociado con "Ver sus bonos", marcar entregado con nota o deshacer, eliminar con motivo y restaurar, exportar CSV de la lista visible, proximos a bono).

### Backend API (`php/`)
- `config.php`: conexion, helpers, sesion, roles, constantes de negocio.
- `productos.php`, `categorias.php`, `carrito.php`, `pedidos.php`, `admin.php`, `admin-tarjetas.php`, `sesion.php`, `logout.php`, `logout_admin.php`.
- Pagos/puntos: `pagos.php`, `puntos-pagos-helpers.php` (incluye `validarRangosPuntos` / `rangosPuntosCompra`: puntos por rangos del valor de la compra; y el reparto de puntos de compra, antes en `pagos.php`: `PUNTOS_POR_COMPRA`, `puntosPorValorCompra`, `asignarPuntosCompra`, `revertirPuntosCompra`, `sincronizarPuntosPedido`), `migrar_pagos_puntos.php`.
- `cobros-helpers.php`: enlace de cobro de pedidos contra entrega (`urlCobroPedido`, token HMAC con `TIENDA_COBRO_SECRET` o `DB_PASSWORD`), `pagoAprobadoPedido` y `textoMedioPagoMP` (tarjeta, PSE, efectivo...). Lo incluyen `pagos.php`, `admin.php` y `correo-pedidos.php`.
- `bonos-helpers.php` (mysqli; lo incluyen `admin.php`, `pedidos.php`, `pagos.php`, `cancelar_pedidos_abandonados.php` y `04-contenido/php/conexion.php`): bonos pendientes = floor(saldo / `puntosPorBono()`: config `puntos_por_bono`, por defecto `PUNTOS_POR_BONO` (50)); cada bono guarda sus puntos en `puntos_meta` y descuenta/devuelve esos (`puntosDelBono`); si el admin cambia los puntos por bono, la sincronizacion ajusta los pendientes libres (sin pedido ni puntos descontados) y borra los que sobren; el bono vale puntos por bono x `valor_punto_cop` y se redime en una compra (`aplicarBonoAPedido`): descuenta del total, queda `entregado` con el pedido y resta sus puntos del saldo (que vuelve a acumular). Entrega manual o eliminar en el admin tambien restan los puntos; deshacer/restaurar los devuelven (`cambiarEstadoBono`, flag `puntos_descontados`, movimientos `canje_bono`/`anulacion_bono`/`reverso_bono`). Pedido cancelado (admin, cron o error de preferencia) -> `liberarBonosDePedidosCancelados` devuelve bono y puntos. Errores de regla: `BonoException`. Preparar tablas (`asegurarTablaBonos`, hace ALTER) ANTES de abrir transacciones.
- `correo-pedidos.php`: correo al comprador + aviso a la tienda (contra entrega: boton "Pagar ahora con MercadoPago" con el enlace de cobro) (reusa PHPMailer de `02-iniciarSesion/php/PHPMailer`); cada envio queda en `correos_tienda`.
- `compartir.php`: vista previa para redes sociales de un producto (HTML, no JSON; ver `js/compartir.js`). Acepta `&ref=CODIGO` (invitacion del asociado que comparte) y lo pasa a `producto.html?id=N&ref=CODIGO`.
- `invitaciones.php` + `invitaciones-helpers.php`: codigos de invitacion de asociados (`action=mi_codigo`: codigo del usuario con sesion, '' sin sesion; `action=resolver&ref=`: `valido`, `nombre` corto, nunca el email). `compartir.js` lo agrega a los enlaces que comparte un asociado con sesion.
- `cancelar_pedidos_abandonados.php`: solo CLI (por web responde 404), lo ejecuta el cron.
- `imagenes-productos.php`: `optimizarImagenProducto()` genera un WebP optimizado (lado mayor 1200 px, calidad 82, conserva transparencia) de cada foto subida en el admin; el producto usa el WebP y el original queda en disco. Requiere GD con WebP (Dockerfile). `optimizar_imagenes_productos.php`: solo CLI, aplica lo mismo a las fotos existentes (`--simular` para ver sin cambiar).

### Archivos legacy (no tocar salvo migracion explicita)
Variantes `*Org*`, `*Org2*`, `* copy*` en `05-tienda/`, `php/`, `js/`, `02-iniciarSesion/php/` y `04-contenido/php/`. No usarlos como base, no borrarlos en cambios funcionales.

## 4) Flujo funcional (cliente)

1. Catalogo (`js/tienda.js`) carga categorias + productos + destacados.
2. Agregar al carrito: `php/carrito.php?action=agregar`.
3. Carrito (`js/carrito.js`) consulta `action=ver`; el resumen viene calculado del backend.
4. Checkout (`js/checkout.js`) valida con `pedidos.php?action=verificar_checkout`.
5. Crea pedido con `pedidos.php?action=crear` y redirige a `confirmacion.html?pedido=...`.
6. `mis-pedidos.html` consume `pedidos.php?action=historial` y `action=detalle`.

Comportamiento heredado conocido: el carrito anonimo (por `session_id`) no se migra al carrito del usuario al iniciar sesion.

## 5) Sesion y roles (punto critico)

- Login en `02-iniciarSesion/php/iniciarSesion.php`: guarda `$_SESSION['email']`, `nombres`, `apellidos`, `rol` (y `user_id`, que usa `04-contenido`).
- `esAdmin()`: lista de emails en `config.php`. `esClienteLogueado()`: bloquea compras con sesion admin.
- `js/sesion-ui.js` convierte el icono de usuario de la cabecera en el menu "Mi cuenta" (todas las paginas de la tienda): sin sesion Mis pedidos / Mi carrito / Iniciar sesion; cliente logueado: saludo, Mis pedidos, Mi carrito y Cerrar sesion (con confirmacion). Si la pagina no tiene `#icono-usuario` (checkout, mis pedidos) lo agrega.

## 6) Integracion `05-tienda` <-> `04-contenido`

- Las tarjetas giratorias de `04-contenido/php/contenido.php` leen `05-tienda/php/tarjetas-contenido.json` (clave `productos_vinculados`) y consultan la BD de tienda. En la cara trasera tienen un boton de carrito (`.tarjeta-carrito`, `04-contenido/js/contenido.js`) que llama a `05-tienda/php/carrito.php?action=agregar` con la misma sesion; para cuentas admin solo muestra un aviso; agotado = deshabilitado.
- Cambios en `admin-tarjetas.php` afectan a `04-contenido`.
- `tarjetas-contenido.json` es estado de runtime: en el VPS vive montado desde el host; no versionarlo ni sobrescribirlo en despliegues.

## 7) Modelo de datos en uso (no romper)

- `categorias` (id, nombre, descripcion, imagen, activo).
- `productos` (id, nombre, descripcion, descripcion_corta, precio, precio_oferta, categoria_id, stock, sku, marca, destacado, activo, imagen, vistas).
- `carrito` (id, usuario_id/session_id, producto_id, cantidad, fecha_agregado).
- `pedidos` (id, numero_pedido, usuario_id, subtotal, costo_envio, impuestos, total, estado, metodo_pago, datos de envio, notas, fecha_creacion).
- `detalle_pedido` (pedido_id, producto_id, nombre_producto, cantidad, precio_unitario, subtotal).
- `configuracion_tienda` (clave, valor VARCHAR(255)) para `umbral_bajo_stock`, `valor_punto_cop` (pesos por punto para el canje; 0 = sin definir), `puntos_rangos` (JSON `[[desde, hasta|null, puntos], ...]`, max 8 rangos, montos hasta 999.999.999, puntos 0-1000) y `puntos_por_bono` (1-10.000; sin definir = 50).
- `registros` (autenticacion; email -> id, datos de cliente, columna `referente` = email del patrocinador).
- `producto_imagenes` (id, producto_id, imagen, tipo `imagen`/`video`, orden, fecha_creacion): fotos y video adicionales (max 10 por producto, 1 video MP4/WebM/MOV de max 40 MB, guardado tal cual en `imagenes/productos/`; `tipo` se agrega solo a tablas viejas); la principal sigue en `productos.imagen`. Se crea sola (`asegurarTablaImagenesProducto` en `imagenes-productos.php`). Quitar una foto borra la fila, no el archivo.
- `codigos_invitacion` (usuario_id PK = `registros.id`, codigo unico de 6 caracteres sin 0/O/1/I/L, fecha_creacion): codigo opaco del asociado para enlaces de invitacion (el email nunca va en el enlace). Se crea sola; el codigo se genera la primera vez que se pide.
- `bonos_puntos` (id, usuario_id, numero, puntos_meta, fecha_obtenido, estado `pendiente`/`entregado`/`anulado`, fecha_entrega, nota, entregado_por, fecha_anulado, anulado_por, motivo_anulado, pedido_id, valor_cop, puntos_descontados; unico usuario_id+numero). `pedidos.descuento_bono` (DECIMAL, 0 = sin bono; `total` ya viene con el descuento). "Eliminar" = `anulado` (no se borra la fila: la sincronizacion la recrearia); restaurar vuelve a entregado si tenia fecha_entrega, si no a pendiente. Se crea sola (`asegurarTablaBonos`).
- `correos_tienda` (id, pedido_id, tipo `cliente`/`tienda`, destinatario, asunto, cuerpo, enviado, error, fecha_envio): copia de cada correo de pedido; se crea sola (`asegurarTablaCorreos`).
- Nuevas (pagos/puntos): `pagos_tienda` (unicos: payment_id, preference_id, idempotency_key), `puntos_saldo` (usuario_id PK), `puntos_movimientos` (unico: clave_idempotencia).

## 8) Contratos API que el frontend ya espera

- `productos.php`: `success`, `productos[]`, `umbral_bajo_stock`, `paginacion`. `action=listar` acepta el filtro opcional `destacados=1` (casilla "Solo destacados"). `action=detalle` agrega `producto.imagenes[]` (solo fotos, principal primero) y `producto.medios[{tipo, archivo}]` (fotos y videos); `action=imagenes&id=` devuelve `imagenes[]` y `medios[]` sin sumar vistas.
- `carrito.php?action=ver`: `carrito.items`, `subtotal`, `costo_envio`, `total` y sus `*_formateado`.
- `pedidos.php?action=verificar_checkout`: flags `requiere_login`, `es_admin`, `carrito_vacio`; `usuario{}` y `bono{disponibles, valor, valor_formateado}` en exito.
- `pedidos.php?action=crear`: `pedido.numero`, `pedido.id`, `pedido.total(_formateado)`, `pedido.descuento_bono(_formateado)`. POST opcional `usar_bono=1` (error 400 con `bono_no_disponible`). `action=detalle` agrega `descuento_bono(_formateado)`.
- `admin.php`: acciones por query string (`action=...`) usadas directamente por las vistas admin. Puntos por compra: `obtenerRangosPuntos` (`rangos[{desde, hasta, puntos, *_formateado}]`, `maximo_rangos`) y `actualizarRangosPuntos` (POST `rangos` = JSON; `[]` borra). Puntos por bono: `obtenerPuntosPorBono` y `actualizarPuntosPorBono` (POST `valor` 1-10.000; recalcula los bonos pendientes de todos). `dashboard` agrega `puntos_rangos_total` y `puntos_por_bono`. Valor del punto: `obtenerValorPunto` (`valor_punto`, `puntos_emitidos`, `equivalente_total(_formateado)`) y `actualizarValorPunto` (POST `valor`, entero 0-1.000.000); `dashboard` agrega `valor_punto(_formateado)`, `puntos_emitidos`, `puntos_equivalente_formateado`. Fotos adicionales: `producto_imagenes&id=` (`imagenes[{id, imagen}]`, `maximo`), `producto_imagenes_subir` (POST `id`, `imagenes[]`), `producto_video_subir` (POST `id`, `video`), listados con `tipo`, `producto_imagen_eliminar` y `producto_imagen_principal` (POST `id` de la foto; intercambia con la principal y devuelve `principal`). Bonos: `bonos_listar` (filtros `estado`, `buscar`; `bonos[]`, `resumen{pendientes, entregados, asociados, puntos_por_bono}`, `proximos[]` a 10 pts o menos) (acepta `estado=anulado`; `resumen.anulados`), `bono_actualizar` (POST `id`, `estado` entregado/pendiente, `nota`; no aplica a eliminados), `bono_eliminar` (POST `id`, `motivo` obligatorio), `bono_restaurar` (POST `id`) y `bonos_asociados` (filtro `buscar`; totales por asociado). Pedidos: `pedidos_listar` y `pedido_detalle` agregan `pagado_mp`, `medio_pago_texto`, `cobro_disponible`; `pedido_actualizar_estado` aplica los puntos (bloquea el pedido) y agrega `puntos{asignados[], revertidos[]}`; `pedido_enlace_cobro&id=` (`url`, `numero`, `total(_formateado)`, `nombre`, `telefono`, `pagado`, `medio_pago_texto`, `estado`). Correos: `correos_listar` (filtros `pedido`, `tipo`; sin cuerpo) y `correo_detalle&id=` (con `cuerpo` HTML).
- `pagos.php?action=crear_preferencia` (POST, JSON o form, mismos campos que `pedidos.php?action=crear`, incluido `usar_bono`; con bono MercadoPago cobra un solo item por el total con descuento; si el bono cubre todo responde `bono_cubre_total`; `resumen.descuento_bono`): crea el pedido `pendiente` (metodo `mercadopago`) y devuelve `pago{}`, `pedido{id, numero, estado}`, `preferencia{id, init_point, sandbox_init_point}`, `resumen{}`; errores con flags `requiere_login`, `es_admin`, `carrito_vacio`, `requiere_configuracion`.
- `pagos.php?action=webhook_mercadopago`: notificaciones de MercadoPago (Webhooks `data.id`/`type` e IPN `id`/`topic`). Responde 5xx solo cuando conviene que MercadoPago reintente.
- `pagos.php?action=cobrar&pedido=NUM&t=TOKEN`: pagina HTML (no JSON) del enlace de cobro de un pedido contra entrega; `&ir=1` crea la preferencia (registro `pagos_tienda.metodo_pago = 'cobro_mp'`, `external_reference` = numero) y redirige a MercadoPago; `&retorno=1` verifica el pago. Pago aprobado de un contra entrega (webhook o retorno): no toca stock ni carrito, `pendiente` -> `confirmado`, da puntos; sin correo.
- `pagos.php?action=estado_pedido&pedido=...&payment_id=...` (cliente dueno del pedido): `pedido{numero, estado, estado_texto, metodo_pago, total(_formateado)}`, `pago{estado, detalle}`. Lo usa `confirmacion.html?pedido=...&mp=1`.

Si se agrega informacion, hacerlo sin quitar campos existentes.

## 9) Configuracion y constantes (fuente de verdad: `05-tienda/php/config.php`)

- Moneda `COP`; `TIENDA_COSTO_ENVIO_BASE`; `TIENDA_ENVIO_GRATIS_MINIMO`; `TIENDA_IVA`. No asumir valores del README si difieren del codigo.
- MercadoPago: `pagos.php` lee `MERCADOPAGO_ACCESS_TOKEN` (o `MP_ACCESS_TOKEN`) desde constante o variable de entorno. Las credenciales de prueba actuales empiezan por `APP_USR-` (cuenta vendedora de prueba); el sandbox lo define la cuenta, no el prefijo. Opcional: `MERCADOPAGO_WEBHOOK_SECRET` para validar `x-signature`.
- Puntos: `PUNTOS_POR_COMPRA` (1, se usa si la compra no cae en ningun rango o no hay rangos) y `PUNTOS_NIVELES_REFERIDOS` (2) en `pagos.php`. Rangos y puntos por bono se configuran en el dashboard admin.

## 10) Reglas para implementar

### Backend
- Validar y sanitizar entradas (`sanitizar`, `intval`, `floatval`), responder siempre con `jsonResponse`.
- Mantener control de permisos en endpoints admin.
- Operaciones criticas (pedido/stock/pagos/puntos): transacciones e idempotencia.
- Nuevos endpoints: patron `php/<modulo>.php?action=<accion>`. No mover endpoints sin compatibilidad.

### Frontend
- Consumo por `fetch` + `action=...`. No romper IDs/clases usados por JS. Preservar `css/tienda.css`.
- Paginas nuevas: incluir `js/sesion-ui.js` cuando aplique.

### Cambios
- Pequenos y acotados por feature; no mezclar refactors grandes con features. Evitar cambios masivos en `config.php`.

### Secretos (obligatorio)
- El repositorio es PUBLICO. Nunca commitear credenciales, tokens de MercadoPago ni `.env`.
- Credenciales via variables de entorno `DB_*` / `MERCADOPAGO_*` (en el VPS: `/opt/apps/tucultura/.env`, permisos 600).
- Hay credenciales historicas del hosting en el codigo versionado: no replicarlas en archivos nuevos.

## 11) Checklist de no-regresion

1. Catalogo carga productos/categorias sin errores JS.
2. Agregar al carrito desde catalogo y detalle.
3. Carrito: actualizar/eliminar/vaciar.
4. Checkout: bloquea no logueado, bloquea admin, crea pedido con cliente.
5. Mis pedidos: historial y detalle.
6. Admin: dashboard, CRUD productos/categorias, estado de pedido, bajo stock y umbral.
7. Si se tocan tarjetas: confirmar impacto en `04-contenido/php/contenido.php`.

## 12) Entorno VPS (entorno principal de trabajo)

- URL de prueba: `https://vps.girabienes.com`. El hosting compartido (`https://www.girabienes.com`) se conserva intacto como respaldo hasta decidir la migracion definitiva.
- Proyecto en `/opt/apps/tucultura` (usuario SSH `deploy`, host Contabo `vmi3527475`).
- Docker Compose: `tucultura-app-1` (PHP 8.3 Apache), `tucultura-db-1` (MariaDB 11.4, BD `tucultura_pruebas` importada del hosting). Caddy (contenedor compartido) es el unico expuesto en 80/443 y enruta `vps.girabienes.com` -> `tucultura-app-1:80` por la red Docker externa `proxy`. No publicar puertos nuevos.
- Infraestructura versionada en el repo: `Dockerfile`, `compose.yml`, `docker/php/tucultura.ini` (zona horaria Bogota, errores solo a log, OPcache, sesiones, subidas hasta 45 MB / POST 50 MB por los videos), `docker/apache/tucultura.conf` (HTTPS detras de Caddy via `X-Forwarded-Proto`).
- Persistencia (fuera de la imagen y de git): BD en volumen `tucultura_db_data`; sesiones PHP en volumen `tucultura_sessions`; bind mounts de `05-tienda/php/tarjetas-contenido.json` y `05-tienda/imagenes/productos/` (subidas del admin; propietario uid 33 = www-data).
- Variables en `.env` del VPS: `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `SMTP_PASSWORD` (opcionales `SMTP_HOST`, `SMTP_USER`) y, para pagos, `MERCADOPAGO_ACCESS_TOKEN` (hoy credencial de prueba de la app "TuCultura Tienda"; opcional `MERCADOPAGO_WEBHOOK_SECRET`). Correos: `TIENDA_EMAIL_PRUEBAS` (mientras exista, el correo del comprador se redirige ahi; hoy activo porque los clientes de prueba usan emails de personas reales; QUITARLO al pasar a produccion) y opcional `TIENDA_EMAIL_NOTIFICACIONES` (aviso de pedidos nuevos; por defecto `SMTP_USER`). Cambiar `.env` requiere `docker compose up -d`.
- Cron de `deploy`: `scripts/backup.sh` diario 08:45 y `cancelar_pedidos_abandonados.php` cada hora (minuto 10, log `~/logs/tucultura-pedidos.log`).

### Despliegue continuo (GitHub Actions -> VPS)
- `/opt/apps/tucultura` es un clon git de `origin/main`. No editar codigo directamente en el VPS: todo cambio entra por commit + push.
- Push a `main` (o "Run workflow" manual) ejecuta `.github/workflows/deploy.yml`: se conecta por SSH con una llave dedicada cuyo `authorized_keys` tiene comando forzado `scripts/deploy.sh`.
- `scripts/deploy.sh`: `git reset --hard origin/main` -> `docker compose up -d --build` -> verifica `categorias.php?action=listar` via Caddy; si falla, vuelve al commit anterior. Despliegue manual: `/opt/apps/tucultura/scripts/deploy.sh`.
- Secrets del repo: `VPS_HOST`, `VPS_USER`, `VPS_SSH_KEY`, `VPS_KNOWN_HOSTS`.
- Un deploy recrea el contenedor `app`; sesiones, BD, imagenes y tarjetas persisten.

### Respaldos
- `scripts/backup.sh` (cron diario de `deploy`): dump de la BD + imagenes de productos + `tarjetas-contenido.json` en `~/backups/tucultura/` (14 dias) y copia cifrada con rclone al remoto `tucultura-crypt:` (Google Drive, 90 dias). Log: `~/backups/tucultura/backup.log`.

### Hosting anterior (en pausa, no tocar hasta terminar la migracion)
- `www.girabienes.com` (Colombia Hosting) queda congelado como respaldo; ya no recibe despliegues.
- Tarea obligatoria al cerrarlo: cambiar o eliminar la BD `tucultur_asociados` del hosting y su usuario `tucultur` (su contrasena quedo expuesta en el historial publico del repo) y revisar Remote MySQL.
- El correo `contacto@tuculturaesprogreso.com` (SMTP de recuperacion de contrasena) vive en ese hosting: migrarlo a otro proveedor antes de cerrarlo. Su contrasena ya fue rotada (2026-10-10).
- Caddy: respaldar `/opt/caddy/Caddyfile`; validar con `sudo docker exec caddy caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile`; recargar con `... caddy reload ...`.

## 13) Estado de la feature activa: MercadoPago + puntos

Regla de negocio: con pago APROBADO (MercadoPago: tarjeta, PSE, efectivo; o enlace de cobro de un contra entrega) o con un pedido contra entrega / transferencia marcado "entregado" en el admin, el comprador, su referente nivel 1 y el nivel 2 reciben cada uno los mismos puntos, segun el rango donde cae el valor de los productos (sin envio y antes del bono; `puntosPorValorCompra` en `pagos.php`, guarda el rango en `metadata_json`); sin rango aplicable, 1 punto; rango con 0 puntos no registra movimientos. Una vez por pedido (neto de movimientos `compra_*` - `reverso_compra`). Pedido cancelado en el admin: se quitan esos puntos (`reverso_compra`) aunque el saldo quede negativo (Mis Puntos muestra "Debes recuperar N puntos"); si se reactiva y vuelve a cumplir la regla, se dan otra vez. Los puntos por bono son configurables (abajo dice 50, el valor por defecto). Cada 50 puntos de saldo = 1 bono (vale 50 x valor del punto) que el asociado redime en el checkout de la tienda; al redimirlo se descuentan 50 puntos y vuelve a acumular. Cada usuario ve su acumulado en `04-contenido/php/contenido.php` (card "Mis Puntos": bonos disponibles, barra y puntos que faltan para el siguiente bono; 1 bono cada 50 puntos), con el equivalente en COP segun `valor_punto_cop` (definido por el admin en el dashboard; el canje por dinero al cierre se hace con ese valor). Idempotencia en BD contra reintentos/webhooks repetidos. Caso de prueba: `haroldvaldes@yahoo.com` -> `mary@gmail.com` -> `gaby@gmail.com` (compra `gaby@gmail.com`).

Flujo implementado (decision: pedido pendiente antes de pagar):
- Checkout con `mercadopago` -> `crear_preferencia` crea pedido `pendiente` + detalle (sin descontar stock ni vaciar carrito); `external_reference` = `numero_pedido`.
- Pago aprobado (webhook o retorno a `confirmacion.html`, ambos verifican el pago en la API `/v1/payments/{id}`): en una transaccion con `FOR UPDATE` el pedido pasa a `confirmado`, se descuenta stock, se quitan del carrito los productos comprados y se suman puntos. Valida monto y moneda; si no coinciden, el pedido queda pendiente para revision.
- Idempotencia: solo se confirma un pedido `pendiente`; `puntos_movimientos.clave_idempotencia` evita sumar dos veces.
- Pago rechazado: el pedido sigue `pendiente` y los productos siguen en el carrito.
- La preferencia y los pagos en efectivo vencen a las 48 h (`MP_HORAS_EXPIRACION`). El cron cancela pedidos `mercadopago` pendientes de mas de 72 h sin pago aprobado/en proceso, o de mas de 10 dias sin pago aprobado. Si llega un pago aprobado para un pedido cancelado, se registra y se deja alerta en el log (revision manual).
- Correo de confirmacion: contraentrega/transferencia al crear el pedido; MercadoPago una sola vez al aprobarse.

Pendientes:
1. [x] Modelo de datos pagos/puntos (saldo + movimientos + idempotencia).
2. [x] `pagos.php?action=crear_preferencia` (sandbox).
3. [x] Integrar `mercadopago` en `js/checkout.js` sin romper `contraentrega`/`transferencia`.
4. [x] Webhook de pago aprobado con idempotencia + asignacion de puntos (comprador + nivel 1 + nivel 2).
5. [x] Card de puntos acumulados en `04-contenido/php/contenido.php`.
6. [x] Limpieza de pedidos `mercadopago` abandonados (vencimiento 48 h + cron).
7. [x] Correo de confirmacion de pedido (comprador + aviso a la tienda).
8. [ ] Paso a produccion:
   - [x] Webhook en el panel de MercadoPago (app "TuCultura Tienda", evento "Pagos (legacy)", URL `https://vps.girabienes.com/05-tienda/php/pagos.php?action=webhook_mercadopago` en modo prueba y productivo) + `MERCADOPAGO_WEBHOOK_SECRET` en `.env`; simulacion con firma valida respondio 200 (2026-10-11). Al cambiar de dominio, actualizar ambas URLs en el panel.
   - [ ] Pruebas completas de correos (contraentrega y MercadoPago) revisando `admin/correos.html`.
   - [ ] Credenciales de produccion: NO activarlas en la cuenta del desarrollador ("Harold David", donde vive la app de prueba). El dinero llega a la cuenta donde se activan; el titular (otra persona o empresa) aun no esta decidido. Cuando se decida: el titular crea su app, activa credenciales con el dominio definitivo, se configura su webhook + clave secreta y se cargan en `.env` con `read -rsp` (nunca por chat).
   - [ ] Dominio definitivo: propuesta de quedarse en el VPS; al elegirlo, agregarlo en Caddy y actualizar las URLs del webhook.
   - [ ] Quitar `TIENDA_EMAIL_PRUEBAS`.
   - [ ] Prueba real con monto pequeno y devolucion.
9. [ ] Fase 2 (fuera del alcance de la pasarela, cotizar aparte): gestion de envios (guia/transportadora o integracion con agregador).

Verificado en sandbox (2026-10-11): pedido TCP-20261011-60304C aprobado; puntos gaby/mary/harold +1 cada uno; stock y carrito actualizados; webhooks repetidos (IPN + Webhook + reenvio manual) sin duplicar.
Sandbox: el comprador debe ser la cuenta "Buyer Test User" (iniciar sesion en el checkout de MercadoPago, ventana de incognito); pagar como invitado con un email real da "Una de las partes ... es de prueba".

## 14) Plan de trabajo acordado (2026-10-11)

Se ejecuta en orden, paso a paso; cada punto se evalua y aprueba con el usuario antes de pasar al siguiente.

**Para retomar (sesion cerrada el 2026-10-12, todo lo de abajo aprobado por el usuario):**
- Siguiente paso: punto 1 del plan (pruebas ficticias de compra en sandbox). Preguntar al usuario con que asociado y metodo empezar; ventas ya en cero.
- Pendiente del usuario: probar desde el admin la subida de una foto de producto (debe quedar en WebP automaticamente).
- Decisiones del usuario: la seccion "Terminos, Ofertas y Condiciones" del home queda como esta (sin fase 2 ni correcciones). En escritorio se conserva el bloque "Productos Destacados" de la tienda (el usuario aprobo escritorio "todo ok").
- Ideas no pedidas aun: las paginas de login y registro siguen usando `OpenSans.ttf` (se podria pasar a WOFF2 como en el home).
- Pruebas de interfaz: se hacen con Edge headless controlado por CDP desde Node (scripts en el scratchpad de la sesion, no versionados) y un servidor local en Python que sirve el repo y reenvia `/05-tienda/php/` e `/05-tienda/imagenes/` al VPS.

1. [ ] Pruebas ficticias completas en sandbox con asociados ficticios (cadena de referidos de 3 niveles): contraentrega, transferencia, MercadoPago aprobado y rechazado; verificar puntos, correos (`admin/correos.html`), stock, carrito, pedidos y valor del punto en `04-contenido`. Modo: el usuario elige cada comprador y metodo; se analizan beneficiados, detalle y flujo admin, y se espera su indicacion antes de cada paso. Avance: ventas de prueba reiniciadas en BD el 2026-10-11 (pedidos, pagos, puntos, correos y carritos en cero; stock devuelto; respaldo en `~/backups/tucultura/antes-reset-ventas-*.sql.gz`). Reiniciadas otra vez el 2026-10-18 tras la prueba de la sesion anterior (pedido TCP-20261018-1692F3), para la demostracion desde cero; `valor_punto_cop` (1000) y umbral se conservan.
2. [ ] Revision del usuario al resumen para el dueno (doc "Tienda TuCultura: pagos, puntos y siguientes pasos"); agregar lo que pida.
3. [ ] Explicacion detallada para el cliente de lo que debe hacer para poner a funcionar el sistema (cuenta MercadoPago, credenciales, dominio, valor del punto, canje, operacion diaria).
4. [ ] Solo cuando el usuario lo indique: crear la cuenta MercadoPago real y hacer transacciones pequenas de prueba (ver pendiente 8 de la seccion 13).

Extra aprobado (2026-10-18): invitacion a afiliarse dentro del enlace de producto. Decisiones del usuario: el invitado solo ve el nombre corto de quien lo invito ("Maria G."), nunca su email; si abre enlaces de dos asociados gana el ultimo; en el registro el patrocinador viene listo pero se puede cambiar ("¿Te invito otra persona?"). Paso a paso con aprobacion:
- [x] 1. El enlace de "Compartir" lleva `&ref=CODIGO` del asociado con sesion (`codigos_invitacion`, `invitaciones.php?action=mi_codigo`); `compartir.php` lo pasa a `producto.html`.
- [x] 2. La tienda guarda el codigo 30 dias (ultimo gana) y, sin sesion, muestra el aviso "X te invito... Registrate para comprar y ganar puntos" con boton Registrarme (`invitaciones.php?action=resolver`).
- [x] 3. Registro (`03-registrarse`) con "Te invito: X" resuelto por codigo (backend traduce codigo -> email de referente), enlace para cambiarlo y regreso al producto al terminar. El registro normal no cambia.
- [ ] 4. Verificar puntos: compra aprobada del invitado suma a su patrocinador (nivel 1) y al de este (nivel 2), sin cambios de codigo.
- [ ] 5. Boton "Mi enlace de invitacion" en `04-contenido` (aprobado para despues de 1-4).

Extra aprobado (2026-10-25): puntos para contra entrega/transferencia y enlace de cobro. Decisiones del usuario: opcion A (contra entrega y transferencia dan puntos al marcar "Entregado"); cancelar quita los puntos y el saldo puede quedar negativo; medio exacto de MercadoPago en el admin; enlace de cobro + QR por pedido contra entrega, enviado al cliente y al transportista (el transportista puede cobrar en efectivo y pagar el enlace); el cliente puede pagar antes de recibir (bajo su riesgo, boton en el correo).
- [x] Implementado (`cobros-helpers.php`, `pagos.php?action=cobrar`, puntos en `pedido_actualizar_estado`, admin Pedidos con QR/WhatsApp, saldo negativo en Mis Puntos).
- [ ] Prueba del usuario: contra entrega -> Entregado (puntos) -> Cancelado (se quitan); enlace de cobro pagado en sandbox (pedido pagado, puntos, sin doble cobro).

Extra aprobado (2026-10-25): puntos por rangos del valor de la compra y puntos por bono configurables (opcion A del usuario: el rango lo decide el valor de la compra completa; se reparten por igual). Decisiones: fuera de rango o sin rangos = 1 punto; base = productos sin envio y antes del bono; cambios aplican a compras nuevas; al cambiar los puntos por bono los pendientes se recalculan y los entregados/usados no cambian; contraentrega/transferencia: ver el extra siguiente (puntos al marcar Entregado). Max 8 rangos por el VARCHAR(255) de `configuracion_tienda` (ampliar la columna solo con OK del usuario).
- [x] Implementado (pasos 1-3 rangos, B1-B4 puntos por bono) y validado con `php -l` + pruebas de `validarRangosPuntos` locales.
- [ ] Prueba del usuario en el VPS (panel, compra MercadoPago con rango, cambio de puntos por bono en Mis Puntos y reporte de bonos).

Extra aprobado (2026-10-18): bonos redimibles en la tienda. Pedido del usuario: al entregar un bono los puntos se reinician; el bono se redime en una compra, se descuenta del total y queda entregado en el admin y en la pagina del asociado. Prueba guiada paso a paso (datos de prueba con `puntos-prueba-bonos.sh` en el scratchpad: mary 50, gaby 45; `revertir` al terminar):
- [ ] 1. mary ve "1 bono disponible, vale $50.000" en Mis Puntos.
- [ ] 2. Checkout de mary (Sudadera $95.000, contraentrega) con "Usar mi bono": total $45.000.
- [ ] 3. Despues: Mis Puntos de mary en 0 y "1 bono usado (pedido ...)"; admin Bonos: entregado con el pedido; Pedidos y correo con la linea del bono.
- [ ] 4. Cancelar ese pedido en el admin: el bono vuelve a pendiente y mary recupera 50 puntos.
- [ ] 5. Revertir datos de prueba.

Extra aprobado (2026-10-11): rediseno del carrusel de inicio (`index.html`, `01-principal/js/main.js`, `01-principal/css/estilosCarrusel.css`): libro + productos destacados de la tienda (`productos.php?action=destacados`, cada uno abre `producto.html?id=`), tarjeta con imagen y texto en dos columnas (apiladas en celular), etiqueta de descuento, boton de accion, puntos indicadores, barra de tiempo, flechas en circulo, deslizamiento tactil (cambia de diapositiva), avance cada 5,5 s con desvanecimiento cruzado de 1,5 s (ease-in-out) (pedido del usuario en vez de desplazamiento) y entrada escalonada del contenido; con "reducir movimiento" sigue avanzando y desvaneciendo, solo sin zoom ni subida del texto. Aprobado.

Extra aprobado (2026-10-11): mejora de aspecto y legibilidad del texto del home (`index.html`), por fases con aprobacion de cada una:
- [x] Fase 1 (aprobada) - tipografia y color (solo CSS, en `01-principal/css/estilosLectura.css`, cargada despues de las hojas existentes): fondo claro, tarjetas blancas, texto sin sombra y con buen contraste, ancho de linea legible, titulos Poppins azul marca con acento amarillo, enlaces y destacados legibles, Mision/Vision en dos columnas.
- [x] Imagenes de seccion (2026-10-11): estilo unificado (3:2, velo de marca, sin etiqueta) y fotos `mision.webp`, `vision.webp` y `terminos.webp` aportadas por el usuario (1376x768, reemplazan a las candidatas de Unsplash/Pexels). `Libro` y `whatsapp` pasaron a WebP. Los PNG originales se conservan.
- [-] Fase 2 - estructura de Terminos: DESCARTADA por el usuario (2026-10-12), la seccion queda como esta. Nota para el dueno si se retoma: el texto tiene cifras que parecen contradecirse (autor $110.000 que reserva $40.000 vs. bonificacion de $40.000 = $20.000 + $20.000).
- [x] Fase 3 - contenido (aplicada salvo Terminos, que queda como esta): fechas actualizadas a 2026 en la Vision, pie de pagina con iconos planos conservando el icono de WhatsApp y el correo actual del propietario (por ahora). Mision y Vision reescritas y compactadas (Vision cabe junto a su foto en escritorio, meta 2026) (2026-10-11). Pie de pagina adelantado y publicado (2026-10-11): icono plano de Facebook, correo como enlace, WhatsApp conservado y "© 2026".

Extra aprobado (2026-10-11): rendimiento de carga del home (medido con 4G simulada; antes: cabecera visible a 5,3 s en escritorio y 3,1 s en movil, 3,4 MB):
- [x] 1. Cabeceras en WebP (1.264 KB -> 81 KB y 590 KB -> 39 KB) con `preload` + `fetchpriority="high"` por media query. Despues: ~1,9 s escritorio y movil.
- [x] 2. Carrusel: fotos de destacados con data-src; se descargan despues del evento load, de una en una (o al instante si se navega a esa diapositiva).
- [x] 3. Fuentes: `font-display: swap` + preload de `fonts/OpenSans.woff2` (latino, 19 KB; TTF de respaldo), iconos del menu en `css/iconosHome.css` con subconjunto `fonts/iconos-home.woff2` (1 KB; `fonts.css` completo sigue para 04-contenido), boxicons reemplazado por SVG en linea (clase `.icono`).
- [x] 4. jQuery eliminado (82 KB): menu movil en JS simple con transicion CSS de `left` (0,4 s) en estilosLectura.css.
- [x] 5. Cache del navegador en `docker/apache/tucultura.conf` (mod_headers activado en el Dockerfile): imagenes y fuentes 7 dias; HTML/CSS/JS `no-cache` (revalidan con 304). Al reemplazar una imagen con el mismo nombre puede verse la anterior hasta 7 dias: usar nombre nuevo o `?v=N`. Se quito el registro del service worker inexistente (`sw.js`, daba 404).
- [x] 6. Fotos de productos en WebP: automatico al subir en el admin (`imagenes-productos.php`) y migracion de las existentes con `optimizar_imagenes_productos.php` (respaldo previo de la tabla `productos`).
- [x] 7. Logos con el mismo nombre (sin tocar las 18 paginas que los usan): `LogoTCPCircle.png` 892 KB -> 8 KB (256 px) y `LogoGirabienesCircle.png` 32 KB -> 7 KB; originales en `*-original.png`. La tienda paso de 2,2 MB a 478 KB.

Extra aprobado (2026-10-12): tienda en celular (antes: filtros de 827 px y primer producto a 1.300 px; pagina de 11.381 px):
- [x] 1. Filtros en panel inferior ("Filtrar" + "Ordenar" en una linea; contador de filtros activos). Primer producto a ~420 px.
- [x] 2. Productos en dos columnas con tarjetas compactas (pagina de ~4.100 px). Badge de oferta a la derecha (antes tapaba al de "Destacado", tambien en escritorio).
- [x] 3. (Reemplazado por el ajuste 1 de abajo: en celular ya no hay bloque de destacados.) Se conserva el limite de la columna de productos al ancho de pantalla (`minmax(0, 1fr)`).
- [x] 4. Cabecera compacta en todas las paginas de la tienda: logo e iconos en una linea y buscador debajo (188 -> 102 px; 54 px en paginas sin buscador). Los textos de los iconos quedan solo para lectores de pantalla. Primer producto a ~330 px; catalogo de ~3.600 px.

Ajustes pedidos (2026-10-12), tienda en celular:
- [x] 1. Sin bloque aparte de destacados en celular (el catalogo ya empieza por ellos); filtro "Solo destacados" en la barra/panel de filtros (`productos.php?action=listar&destacados=1`). Corregida la flecha "siguiente" de la paginacion.
- [x] 2. Pie de pagina compacto en celular (sin la seccion de categorias, cuyos enlaces estaban fijos y no filtraban).
- [x] 3. Categorias en un desplegable en la parte superior en celular (`#select-categoria`, replica la fila `#nav-categorias` y al elegir toca su enlace).
- [x] 4. Menu "Mi cuenta" en el icono de usuario (todas las paginas y tamanos): Mis pedidos, Mi carrito, Iniciar/Cerrar sesion.
- [x] 5. Pie de pagina sin las secciones "Categorias" (enlaces fijos que no filtraban) y "Mi cuenta" (esta en el menu del icono de usuario), ni "Ayuda" en producto (mismos enlaces), ni el enlace "Ir al sitio principal" (esta la casita en la cabecera), en celular y escritorio; la grilla del pie se adapta a las secciones que quedan.
- [x] 6. Escritorio: sin la fila superior de categorias (quedan en la columna lateral) ni el grupo "Ordenar por" de la columna; el desplegable de orden muestra "Ordenar por" como marcador. Pie con año 2026.
- [x] 7. Celular: detalle de producto (modal) como tarjeta compacta: margenes de 16 px, imagen de ~170 px sobre fondo gris, titulo en 2 lineas, botones "Agregar al carrito" / "Cancelar" fijos al pie de la tarjeta.

## 15) Mantenimiento de este archivo

Actualizar al cerrar cada iteracion que cambie rutas/paginas, endpoints o contratos, tablas/campos, reglas de negocio, sesion/roles o integraciones. Registrar al final:

## Registro de cambios del contexto
- 2026-06-21: creacion inicial de AGENTS.md (alcance, contratos API, flujos, checklist).
- 2026-06-21: paso 1 pagos/puntos (`migrar_pagos_puntos.php`, `puntos-pagos-helpers.php`), validado en BD.
- 2026-06-29: paso 2, endpoint `pagos.php?action=crear_preferencia` (sandbox) con registro en `pagos_tienda`.
- 2026-09-06: despliegue de prueba en VPS (`/opt/apps/tucultura`, Docker Compose + MariaDB + Caddy); conexiones adaptadas a `DB_*` solo en la copia del VPS.
- 2026-10-10: AGENTS.md migrado a CLAUDE.md.
- 2026-10-10: repo y VPS unificados. Conexiones activas (tienda, login, recuperacion, registro, contenido) y SMTP leen variables de entorno; infraestructura Docker versionada; deploy FTP al hosting reemplazado por deploy SSH al VPS (`scripts/deploy.sh`).
- 2026-10-10: respaldo diario (`scripts/backup.sh`, cron 08:45 hora del VPS) con copia cifrada en `tucultura-crypt:` (gdrive:TuCultura-respaldos); claves de cifrado solo en el gestor del usuario. `validarEmails.php` pasa a consultas preparadas.
- 2026-10-11: MercadoPago completo en sandbox: pedido pendiente + preferencia, checkout con opcion MercadoPago, webhook idempotente con puntos (comprador + nivel 1 + nivel 2), `estado_pedido` para `confirmacion.html` y card "Mis Puntos" en `04-contenido`.
- 2026-10-11: correo de confirmacion de pedidos (`correo-pedidos.php`, modo pruebas con `TIENDA_EMAIL_PRUEBAS`), vencimiento de preferencias a 48 h y cron de limpieza de pedidos abandonados.
- 2026-10-11: diagnostico de correos: no fallaba el SMTP, nunca se habia disparado (el unico pago aprobado fue antes del deploy). Historial de correos en el admin (`admin/correos.html`, tabla `correos_tienda`).
- 2026-10-11: webhook configurado en el panel de MercadoPago con clave secreta (`MERCADOPAGO_WEBHOOK_SECRET`), firma validada con notificacion simulada.
- 2026-10-11: valor del punto en COP configurable desde el dashboard admin (`valor_punto_cop`); la card "Mis Puntos" de `04-contenido` muestra el equivalente en pesos.
- 2026-10-11: plan de trabajo acordado (seccion 14): pruebas ficticias, revision del resumen, guia para el cliente y luego cuenta MercadoPago real.
- 2026-10-11: carrusel de inicio redisenado con el libro y los productos destacados de la tienda (seccion 14, extra).
- 2026-10-11: home: tipografia y color (`estilosLectura.css`), fotos de seccion del usuario en WebP, Mision y Vision reescritas, pie con iconos planos; carrusel con desvanecimiento.
- 2026-10-11: rendimiento del home: cabeceras WebP con preload, fuentes WOFF2, sin jQuery ni boxicons, cache del navegador en Apache (mod_headers, `DeflateAlterETag Remove`), fotos de productos WebP automaticas (GD en el Dockerfile, `imagenes-productos.php`) y logos optimizados.
- 2026-10-12: tienda: filtros en panel inferior y dos columnas en celular, desplegable de categorias, filtro "Solo destacados" (`destacados=1`), menu "Mi cuenta" en el icono de usuario (`sesion-ui.js`), pie simplificado (2026), detalle de producto compacto en celular; en escritorio sin fila de categorias ni "Ordenar por" en la columna.
- 2026-10-17: pruebas para la exposicion del sistema de pagos (seccion 14, punto 1). Mensaje de bloqueo al admin dice "asociado". Carrito en las tarjetas giratorias de `04-contenido`. Buscador de la tienda: al vaciarlo vuelve al listado. Pendiente decidir: `haroldvaldes@yahoo.com` y `luisfer5428@gmail.com` son admins (`$admins` en `config.php` y en `iniciarSesion.php`) y por eso no pueden comprar; propuesta: cuenta admin dedicada antes de la exposicion con luisfer.
- 2026-10-18: compartir producto con un contacto: boton "Compartir" en el modal del catalogo y en `producto.html` (`js/compartir.js`) y vista previa Open Graph con foto JPEG (`php/compartir.php`).
- 2026-10-18: varias fotos por producto: tabla `producto_imagenes`, seccion "Fotos adicionales" en el admin, tira de miniaturas en el modal del catalogo y en `producto.html` (`js/galeria-producto.js`) y visor ampliado al hacer clic.
- 2026-10-18: invitacion en enlaces de producto, paso 1: `codigos_invitacion`, `invitaciones.php?action=mi_codigo`, `compartir.php` acepta `ref`.
- 2026-10-18: invitacion paso 2: `js/invitacion.js` (codigo 30 dias + aviso para visitantes sin sesion), `invitaciones.php?action=resolver`.
- 2026-10-18: invitacion paso 3: registro con patrocinador por codigo (`ref`), regreso al producto tras iniciar sesion (`returnUrl=producto.html?id=N`).
- 2026-10-18: video de producto: `producto_imagenes.tipo`, `admin.php?action=producto_video_subir`, `medios[]` en `productos.php`, reproduccion en el visor de la galeria; PHP acepta subidas de 45 MB y Apache cachea mp4/webm/mov 7 dias.
- 2026-10-18: bonos por puntos (1 cada 50): `bonos-helpers.php`, tabla `bonos_puntos`, card "Mis Puntos" con bonos y faltantes en `04-contenido`, reporte `admin/bonos.html` (`bonos_listar`, `bono_actualizar`).
- 2026-10-18: reporte de bonos con tarjetas clicables, vista por asociado y eliminar (anular con motivo) / restaurar.
- 2026-10-18: bonos redimibles en compras: bono = floor(saldo/50), vale 50 x valor del punto, se aplica en el checkout (`usar_bono`), descuenta 50 puntos y queda entregado con el pedido (`pedidos.descuento_bono`); cancelar el pedido lo devuelve; entrega manual/eliminar restan puntos y deshacer/restaurar los devuelven.
- 2026-10-18: corregido error heredado en `pedidos.php?action=crear` (bind_param con 15 tipos para 16 valores: PHP 8 lanzaba ArgumentCountError). Contraentrega y transferencia nunca habian creado pedidos en el VPS; MercadoPago no estaba afectado.
- 2026-10-25: puntos por compra segun rangos del valor de los productos (`configuracion_tienda.puntos_rangos`, `obtenerRangosPuntos`/`actualizarRangosPuntos`) y puntos por bono configurables (`puntos_por_bono`, `puntosPorBono()`, devoluciones con `puntos_meta`, recalculo de pendientes); ambos en la tarjeta "Puntos Asociados" del dashboard.
- 2026-10-25: contra entrega y transferencia dan puntos al marcar "Entregado"; cancelar un pedido revierte sus puntos (saldo puede ser negativo); enlace de cobro con QR para contra entrega (`cobros-helpers.php`, `pagos.php?action=cobrar`, `admin.php?action=pedido_enlace_cobro`) y medio de pago de MercadoPago en el admin; reparto de puntos movido a `puntos-pagos-helpers.php`.
