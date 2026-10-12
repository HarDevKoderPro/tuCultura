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
  - `03-registrarse/`: registro con email de referente (arbol de referidos).
  - `04-contenido/`: zona de usuarios (`php/contenido.php`), niveles de referidos, video y libros.
  - `05-tienda/`: tienda virtual (ver seccion 3).

## 3) Modulo de tienda (`05-tienda/`)

### Frontend cliente
- `index.html` (catalogo, filtros, destacados, modal detalle) - `js/tienda.js`.
- `producto.html` - `js/producto.js`; `carrito.html` - `js/carrito.js`; `checkout.html` - `js/checkout.js`.
- `confirmacion.html`; `mis-pedidos.html` - `js/pedidos.js`; `js/sesion-ui.js` (boton cuenta/logout para cliente).

### Frontend admin
- `admin/index.html` (dashboard, bajo stock, umbral, tarjeta "Puntos Asociados" con valor del punto en COP), `admin/productos.html` + `admin/admin-productos.js` (CRUD, imagen, tarjetas), `admin/categorias.html`, `admin/pedidos.html`, `admin/correos.html` (historial de correos de pedidos enviados, con vista del contenido y error si fallo).

### Backend API (`php/`)
- `config.php`: conexion, helpers, sesion, roles, constantes de negocio.
- `productos.php`, `categorias.php`, `carrito.php`, `pedidos.php`, `admin.php`, `admin-tarjetas.php`, `sesion.php`, `logout.php`, `logout_admin.php`.
- Pagos/puntos: `pagos.php`, `puntos-pagos-helpers.php`, `migrar_pagos_puntos.php`.
- `correo-pedidos.php`: correo al comprador + aviso a la tienda (reusa PHPMailer de `02-iniciarSesion/php/PHPMailer`); cada envio queda en `correos_tienda`.
- `cancelar_pedidos_abandonados.php`: solo CLI (por web responde 404), lo ejecuta el cron.

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
- `js/sesion-ui.js` reemplaza el boton "Cuenta" por info + logout solo para cliente logueado.

## 6) Integracion `05-tienda` <-> `04-contenido`

- Las tarjetas giratorias de `04-contenido/php/contenido.php` leen `05-tienda/php/tarjetas-contenido.json` (clave `productos_vinculados`) y consultan la BD de tienda.
- Cambios en `admin-tarjetas.php` afectan a `04-contenido`.
- `tarjetas-contenido.json` es estado de runtime: en el VPS vive montado desde el host; no versionarlo ni sobrescribirlo en despliegues.

## 7) Modelo de datos en uso (no romper)

- `categorias` (id, nombre, descripcion, imagen, activo).
- `productos` (id, nombre, descripcion, descripcion_corta, precio, precio_oferta, categoria_id, stock, sku, marca, destacado, activo, imagen, vistas).
- `carrito` (id, usuario_id/session_id, producto_id, cantidad, fecha_agregado).
- `pedidos` (id, numero_pedido, usuario_id, subtotal, costo_envio, impuestos, total, estado, metodo_pago, datos de envio, notas, fecha_creacion).
- `detalle_pedido` (pedido_id, producto_id, nombre_producto, cantidad, precio_unitario, subtotal).
- `configuracion_tienda` (clave, valor) para `umbral_bajo_stock` y `valor_punto_cop` (pesos por punto para el canje; 0 = sin definir).
- `registros` (autenticacion; email -> id, datos de cliente, columna `referente` = email del patrocinador).
- `correos_tienda` (id, pedido_id, tipo `cliente`/`tienda`, destinatario, asunto, cuerpo, enviado, error, fecha_envio): copia de cada correo de pedido; se crea sola (`asegurarTablaCorreos`).
- Nuevas (pagos/puntos): `pagos_tienda` (unicos: payment_id, preference_id, idempotency_key), `puntos_saldo` (usuario_id PK), `puntos_movimientos` (unico: clave_idempotencia).

## 8) Contratos API que el frontend ya espera

- `productos.php`: `success`, `productos[]`, `umbral_bajo_stock`, `paginacion`.
- `carrito.php?action=ver`: `carrito.items`, `subtotal`, `costo_envio`, `total` y sus `*_formateado`.
- `pedidos.php?action=verificar_checkout`: flags `requiere_login`, `es_admin`, `carrito_vacio`; `usuario{}` en exito.
- `pedidos.php?action=crear`: `pedido.numero`, `pedido.id`, `pedido.total(_formateado)`.
- `admin.php`: acciones por query string (`action=...`) usadas directamente por las vistas admin. Valor del punto: `obtenerValorPunto` (`valor_punto`, `puntos_emitidos`, `equivalente_total(_formateado)`) y `actualizarValorPunto` (POST `valor`, entero 0-1.000.000); `dashboard` agrega `valor_punto(_formateado)`, `puntos_emitidos`, `puntos_equivalente_formateado`. Correos: `correos_listar` (filtros `pedido`, `tipo`; sin cuerpo) y `correo_detalle&id=` (con `cuerpo` HTML).
- `pagos.php?action=crear_preferencia` (POST, JSON o form, mismos campos que `pedidos.php?action=crear`): crea el pedido `pendiente` (metodo `mercadopago`) y devuelve `pago{}`, `pedido{id, numero, estado}`, `preferencia{id, init_point, sandbox_init_point}`, `resumen{}`; errores con flags `requiere_login`, `es_admin`, `carrito_vacio`, `requiere_configuracion`.
- `pagos.php?action=webhook_mercadopago`: notificaciones de MercadoPago (Webhooks `data.id`/`type` e IPN `id`/`topic`). Responde 5xx solo cuando conviene que MercadoPago reintente.
- `pagos.php?action=estado_pedido&pedido=...&payment_id=...` (cliente dueno del pedido): `pedido{numero, estado, estado_texto, metodo_pago, total(_formateado)}`, `pago{estado, detalle}`. Lo usa `confirmacion.html?pedido=...&mp=1`.

Si se agrega informacion, hacerlo sin quitar campos existentes.

## 9) Configuracion y constantes (fuente de verdad: `05-tienda/php/config.php`)

- Moneda `COP`; `TIENDA_COSTO_ENVIO_BASE`; `TIENDA_ENVIO_GRATIS_MINIMO`; `TIENDA_IVA`. No asumir valores del README si difieren del codigo.
- MercadoPago: `pagos.php` lee `MERCADOPAGO_ACCESS_TOKEN` (o `MP_ACCESS_TOKEN`) desde constante o variable de entorno. Las credenciales de prueba actuales empiezan por `APP_USR-` (cuenta vendedora de prueba); el sandbox lo define la cuenta, no el prefijo. Opcional: `MERCADOPAGO_WEBHOOK_SECRET` para validar `x-signature`.
- Puntos: `PUNTOS_POR_COMPRA` (1) y `PUNTOS_NIVELES_REFERIDOS` (2) en `pagos.php`.

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
- Infraestructura versionada en el repo: `Dockerfile`, `compose.yml`, `docker/php/tucultura.ini` (zona horaria Bogota, errores solo a log, OPcache, sesiones), `docker/apache/tucultura.conf` (HTTPS detras de Caddy via `X-Forwarded-Proto`).
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

Regla de negocio: con pago APROBADO se suma +1 punto al comprador, +1 al referente nivel 1 y +1 al referente nivel 2. Cada usuario ve su acumulado en `04-contenido/php/contenido.php`, con el equivalente en COP segun `valor_punto_cop` (definido por el admin en el dashboard; el canje por dinero al cierre se hace con ese valor). Idempotencia en BD contra reintentos/webhooks repetidos. Caso de prueba: `haroldvaldes@yahoo.com` -> `mary@gmail.com` -> `gaby@gmail.com` (compra `gaby@gmail.com`).

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

1. [ ] Pruebas ficticias completas en sandbox con asociados ficticios (cadena de referidos de 3 niveles): contraentrega, transferencia, MercadoPago aprobado y rechazado; verificar puntos, correos (`admin/correos.html`), stock, carrito, pedidos y valor del punto en `04-contenido`. Modo: el usuario elige cada comprador y metodo; se analizan beneficiados, detalle y flujo admin, y se espera su indicacion antes de cada paso. Avance: ventas de prueba reiniciadas en BD el 2026-10-11 (pedidos, pagos, puntos, correos y carritos en cero; stock devuelto; respaldo en `~/backups/tucultura/antes-reset-ventas-*.sql.gz`).
2. [ ] Revision del usuario al resumen para el dueno (doc "Tienda TuCultura: pagos, puntos y siguientes pasos"); agregar lo que pida.
3. [ ] Explicacion detallada para el cliente de lo que debe hacer para poner a funcionar el sistema (cuenta MercadoPago, credenciales, dominio, valor del punto, canje, operacion diaria).
4. [ ] Solo cuando el usuario lo indique: crear la cuenta MercadoPago real y hacer transacciones pequenas de prueba (ver pendiente 8 de la seccion 13).

Extra aprobado (2026-10-11): rediseno del carrusel de inicio (`index.html`, `01-principal/js/main.js`, `01-principal/css/estilosCarrusel.css`): libro + productos destacados de la tienda (`productos.php?action=destacados`, cada uno abre `producto.html?id=`), tarjeta con imagen y texto en dos columnas (apiladas en celular), etiqueta de descuento, boton de accion, puntos indicadores, barra de tiempo, flechas en circulo, deslizamiento tactil (cambia de diapositiva), avance cada 5,5 s con desvanecimiento cruzado de 1,5 s (ease-in-out) (pedido del usuario en vez de desplazamiento) y entrada escalonada del contenido; con "reducir movimiento" sigue avanzando y desvaneciendo, solo sin zoom ni subida del texto. Pendiente: aprobacion visual del usuario.

Extra aprobado (2026-10-11): mejora de aspecto y legibilidad del texto del home (`index.html`), por fases con aprobacion de cada una:
- [ ] Fase 1 (publicada, pendiente aprobacion) - tipografia y color (solo CSS, en `01-principal/css/estilosLectura.css`, cargada despues de las hojas existentes): fondo claro, tarjetas blancas, texto sin sombra y con buen contraste, ancho de linea legible, titulos Poppins azul marca con acento amarillo, enlaces y destacados legibles, Mision/Vision en dos columnas.
- [x] Imagenes de seccion (2026-10-11): estilo unificado (3:2, velo de marca, sin etiqueta) y fotos `mision.webp`, `vision.webp` y `terminos.webp` aportadas por el usuario (1376x768, reemplazan a las candidatas de Unsplash/Pexels). `Libro` y `whatsapp` pasaron a WebP. Los PNG originales se conservan.
- [ ] Fase 2 - estructura de Terminos sin cambiar su redaccion: indice, apartados plegables, tabla de montos de afiliacion y tarjetas de beneficios.
- [ ] Fase 3 - contenido: ortografia y tipeo, fechas actualizadas a 2026 en la Vision, pie de pagina con iconos planos conservando el icono de WhatsApp y el correo actual del propietario (por ahora). Mision y Vision reescritas y compactadas (Vision cabe junto a su foto en escritorio, meta 2026) (2026-10-11). Pie de pagina adelantado y publicado (2026-10-11): icono plano de Facebook, correo como enlace, WhatsApp conservado y "© 2026".

Extra aprobado (2026-10-11): rendimiento de carga del home (medido con 4G simulada; antes: cabecera visible a 5,3 s en escritorio y 3,1 s en movil, 3,4 MB):
- [x] 1. Cabeceras en WebP (1.264 KB -> 81 KB y 590 KB -> 39 KB) con `preload` + `fetchpriority="high"` por media query. Despues: ~1,9 s escritorio y movil.
- [x] 2. Carrusel: fotos de destacados con data-src; se descargan despues del evento load, de una en una (o al instante si se navega a esa diapositiva).
- [x] 3. Fuentes: `font-display: swap` + preload de `fonts/OpenSans.woff2` (latino, 19 KB; TTF de respaldo), iconos del menu en `css/iconosHome.css` con subconjunto `fonts/iconos-home.woff2` (1 KB; `fonts.css` completo sigue para 04-contenido), boxicons reemplazado por SVG en linea (clase `.icono`).
- [x] 4. jQuery eliminado (82 KB): menu movil en JS simple con transicion CSS de `left` (0,4 s) en estilosLectura.css.
- [x] 5. Cache del navegador en `docker/apache/tucultura.conf` (mod_headers activado en el Dockerfile): imagenes y fuentes 7 dias; HTML/CSS/JS `no-cache` (revalidan con 304). Al reemplazar una imagen con el mismo nombre puede verse la anterior hasta 7 dias: usar nombre nuevo o `?v=N`. Se quito el registro del service worker inexistente (`sw.js`, daba 404).
- [ ] 6. (Aparte) Miniaturas WebP automaticas al subir fotos de productos en el admin.

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
