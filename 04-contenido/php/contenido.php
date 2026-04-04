<?php
session_start();

if (!isset($_SESSION['user_id'])) {
  header("Location: ../02-iniciarSesion/iniciarSesion.html");
  exit;
}

include './conexion.php';

$totalRegistros         = contarRegistros($conn);
$totalUsuario           = contarRegistrosUsuario($conn, $_SESSION['email']);
$totalReferidosDirectos = contarRegistrosDeReferidos($conn, $_SESSION['email']);
$totalReferidosNivel3   = contarReferidosNivel3($conn, $_SESSION['email']);

// Carga inicial: Nivel 1
$registrosIniciales = obtenerRegistrosNivel1($conn, $_SESSION['email']);
?>

<!DOCTYPE html>
<html lang='es'>

<head>
  <meta charset='UTF-8'>
  <meta name="format-detection" content="telephone=no, email=no, address=no">
  <meta http-equiv='X-UA-Compatible' content='IE=edge'>
  <meta name='viewport' content='width=device-width, initial-scale=1.0, minimum-scale=1.0,maximum-scale=1.0'>
  <title>Contenido Usuarios</title>
  <link rel='stylesheet' href='../css/contenidoDesktop.css'>
  <link rel="stylesheet" href="../css/contenidoMobile.css">
  <link rel="stylesheet" href="../01-principal/css/fonts.css">
  <link rel="shortcut icon" href="../../01-principal/imagenes/LogoTCPCircle.png" type="image/x-icon">
</head>

<body>

  <div class="container">

    <!-- Encabezado -->
    <section class="encabezado">
      <div class="contenedorLinkSalir">
        <a href="./logout.php" class="linkSalir"><span class="icon-share iconos"></span> Salir</a>
      </div>
      <div class="contenedorDatosLogin">
        <span class="tituloPagina">Zona de Usuarios</span>
        <span class="icon-user"></span>
        <span class="nombreUsuario">
          <?php echo htmlspecialchars($_SESSION['nombres'] . ' ' . $_SESSION['apellidos']); ?>
        </span>
      </div>
      <div class="contenedorLinkEbook">
        <a href="../../04-contenido/libros/Creatividad Motivacional.epub" download="Creatividad Motivacional" class="linkEbook">Ebook <span class="icon-download iconos"></span></a>
        <a href="../../04-contenido/libros/Creatividad-Motivacional.pdf" download="Creatividad Motivacional" class="linkEbook">PDF <span class="icon-download iconos"></span></a>
      </div>
    </section>

    <!-- Sección Promocional -->
    <section class="contenidoPromocional">

      <div class="contenedorVideoPromocional">
        <div class="video">
          <video controls preload="metadata" poster="../video/Thumbnail.png">
            <source src="../video/GiraBienes.mp4" type="video/mp4">
            Tu navegador no soporta el elemento de video.
          </video>
        </div>
      </div>

      <div class="contenedorTarjetas">

        <!-- Tarjeta 1 -->
        <div class="tarjeta">
          <div class="tarjeta-inner">
            <div class="tarjeta-frontal">
              <h3 class="tarjeta-titulo">Auriculares Inalámbricos Pro</h3>
              <div class="tarjeta-imagen">
                <img src="../images/audifonosInalambricos.png" alt="Producto 1">
              </div>
              <div class="tarjeta-footer">Más información ↓</div>
            </div>
            <div class="tarjeta-trasera">
              <div class="tarjeta-back-header">
                <span class="tarjeta-categoria">Audífonos</span>
                <button class="tarjeta-carrito" aria-label="Agregar al carrito">🛒</button>
              </div>
              <div class="tarjeta-back-body">
                <h3 class="tarjeta-nombre">Auriculares Inalámbricos Pro</h3>
                <ul class="tarjeta-specs">
                  <li>Bluetooth 5.3 de baja latencia</li>
                  <li>Cancelación activa de ruido</li>
                  <li>Hasta 24 horas con estuche</li>
                </ul>
              </div>
              <div class="tarjeta-back-footer">
                <span class="tarjeta-precio">$99.99</span>
                <span class="tarjeta-envio">Envío gratis 24-48h</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Tarjeta 2 -->
        <div class="tarjeta">
          <div class="tarjeta-inner">
            <div class="tarjeta-frontal">
              <h3 class="tarjeta-titulo">Reloj Smart Fit X</h3>
              <div class="tarjeta-imagen">
                <img src="../images/smartWatch.png" alt="Producto 2">
              </div>
              <div class="tarjeta-footer">Más información ↓</div>
            </div>
            <div class="tarjeta-trasera">
              <div class="tarjeta-back-header">
                <span class="tarjeta-categoria">Smartwatch</span>
                <button class="tarjeta-carrito" aria-label="Agregar al carrito">🛒</button>
              </div>
              <div class="tarjeta-back-body">
                <h3 class="tarjeta-nombre">Reloj Smart Fit X</h3>
                <ul class="tarjeta-specs">
                  <li>Monitoreo cardíaco continuo</li>
                  <li>Resistencia al agua 5 ATM</li>
                  <li>Notificaciones en tiempo real</li>
                </ul>
              </div>
              <div class="tarjeta-back-footer">
                <span class="tarjeta-precio">$149.99</span>
                <span class="tarjeta-envio">Devolución gratis 30 días</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Tarjeta 3 -->
        <div class="tarjeta">
          <div class="tarjeta-inner">
            <div class="tarjeta-frontal">
              <h3 class="tarjeta-titulo">Speaker Portátil 360º</h3>
              <div class="tarjeta-imagen">
                <img src="../images/bafleInalambrico.png" alt="Producto 3">
              </div>
              <div class="tarjeta-footer">Más información ↓</div>
            </div>
            <div class="tarjeta-trasera">
              <div class="tarjeta-back-header">
                <span class="tarjeta-categoria">Altavoz</span>
                <button class="tarjeta-carrito" aria-label="Agregar al carrito">🛒</button>
              </div>
              <div class="tarjeta-back-body">
                <h3 class="tarjeta-nombre">Speaker Portátil 360º</h3>
                <ul class="tarjeta-specs">
                  <li>Sonido 360° envolvente</li>
                  <li>Batería hasta 12 horas</li>
                  <li>Resistente a salpicaduras IPX5</li>
                </ul>
              </div>
              <div class="tarjeta-back-footer">
                <span class="tarjeta-precio">$199.99</span>
                <span class="tarjeta-envio">Stock limitado</span>
              </div>
            </div>
          </div>
        </div>

      </div>
    </section>

    <!-- Sección de reportes -->
    <section class="reportes">

      <div class="balanceGeneral">

        <!-- No clickeable — Total del sistema -->
        <div class="stat-card">
          <h3>📊 Registros del Sistema</h3>
          <p class="stat-value"><?php echo $totalRegistros; ?></p>
        </div>

        <!-- No clickeable — Total del usuario (suma Nivel 1+2+3, calculada por JS) -->
        <div class="stat-card stat-card--usuario" id="card-total-usuario">
          <h3>👤 Total Registros Usuario</h3>
          <p class="stat-value"><span id="total-usuario-valor">0</span></p>
        </div>

        <!-- Clickeables — Cards por nivel -->
        <div class="stat-card stat-card--clickable stat-card--activa" data-nivel="1">
          <h3>1️⃣ Registros Nivel 1</h3>
          <p class="stat-value"><?php echo $totalUsuario; ?></p>
        </div>

        <div class="stat-card stat-card--clickable" data-nivel="2">
          <h3>2️⃣ Registros Nivel 2</h3>
          <p class="stat-value"><?php echo $totalReferidosDirectos; ?></p>
        </div>

        <div class="stat-card stat-card--clickable" data-nivel="3">
          <h3>3️⃣ Registros Nivel 3</h3>
          <p class="stat-value"><?php echo $totalReferidosNivel3; ?></p>
        </div>

      </div>

      <!-- Tabla de registros -->
      <div class="tablaRegistros">
        <h3 class="tabla-titulo">
          <span id="tituloNivel">📋 Listado de Registros — Nivel 1</span>
        </h3>
        <div class="tabla-wrapper">
          <table class="tabla-reporte" id="tablaReporte">
            <thead>
              <tr>
                <th>ASOCIADO</th>
                <th>DOCUMENTO</th>
                <th>EMAIL</th>
                <th>TELÉFONO</th>
                <th>FECHA</th>
              </tr>
            </thead>
            <tbody id="tablaBody">
              <?php if (empty($registrosIniciales)): ?>
                <tr>
                  <td colspan="5" class="tabla-vacia">No hay registros para mostrar.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($registrosIniciales as $reg): ?>
                  <tr>
                    <td><?php echo htmlspecialchars($reg['nombres'] . ' ' . $reg['apellidos']); ?></td>
                    <td><?php echo htmlspecialchars($reg['documento']); ?></td>
                    <td><?php echo htmlspecialchars($reg['email']); ?></td>
                    <td><?php echo htmlspecialchars($reg['telefono']); ?></td>
                    <td><?php echo formatearFecha($reg['fecha']); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
            <tfoot>
              <tr class="tabla-total">
                <td colspan="4">Total de registros</td>
                <td id="tablaTotalCount"><?php echo count($registrosIniciales); ?></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

    </section>

  </div>

  <script src='../js/contenido.js' defer></script>
</body>

</html>