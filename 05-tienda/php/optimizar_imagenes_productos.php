<?php

/**
 * Genera versiones WebP optimizadas de las fotos de productos existentes y
 * apunta cada producto a su WebP. Los archivos originales no se borran.
 *
 * Solo CLI. En el VPS (respaldar antes la tabla productos):
 *   docker exec tucultura-app-1 php /var/www/html/05-tienda/php/optimizar_imagenes_productos.php --simular
 *   docker exec -u www-data tucultura-app-1 php /var/www/html/05-tienda/php/optimizar_imagenes_productos.php
 *
 * --simular: solo informa lo que haria (no escribe archivos ni la BD).
 */

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

require_once __DIR__ . '/imagenes-productos.php';

$simular = in_array('--simular', $argv, true);
$carpeta = __DIR__ . '/../imagenes/productos/';

if (!function_exists('imagewebp')) {
  fwrite(STDERR, "PHP no tiene GD con soporte WebP: no se puede optimizar.\n");
  exit(1);
}

$conn = new mysqli(
  getenv('DB_HOST') ?: 'localhost',
  getenv('DB_USER') ?: 'tucultur',
  getenv('DB_PASSWORD') ?: '',
  getenv('DB_NAME') ?: 'tucultur_asociados'
);
$conn->set_charset('utf8mb4');

$productos = $conn->query("SELECT id, nombre, imagen FROM productos WHERE imagen IS NOT NULL AND imagen <> '' ORDER BY id")
  ->fetch_all(MYSQLI_ASSOC);
$actualizar = $conn->prepare('UPDATE productos SET imagen = ? WHERE id = ?');

$totalAntes = 0;
$totalDespues = 0;
$cambiados = 0;
foreach ($productos as $p) {
  $nombre = basename($p['imagen']);
  $ruta = $carpeta . $nombre;
  if (!is_file($ruta)) {
    echo "[{$p['id']}] {$p['nombre']}: falta el archivo {$nombre}, se omite\n";
    continue;
  }
  $peso = filesize($ruta);
  $totalAntes += $peso;

  if ($simular) {
    // Se optimiza en una carpeta temporal para medir sin tocar la real
    $tmp = sys_get_temp_dir() . '/optim_' . getmypid() . '/';
    @mkdir($tmp);
    copy($ruta, $tmp . $nombre);
    $webp = optimizarImagenProducto($tmp, $nombre);
    $pesoNuevo = $webp !== '' ? filesize($tmp . $webp) : $peso;
    @unlink($tmp . $nombre);
    if ($webp !== '') @unlink($tmp . $webp);
  } else {
    $webp = optimizarImagenProducto($carpeta, $nombre);
    $pesoNuevo = $webp !== '' ? filesize($carpeta . $webp) : $peso;
    if ($webp !== '') {
      $actualizar->bind_param('si', $webp, $p['id']);
      $actualizar->execute();
    }
  }

  $totalDespues += $pesoNuevo;
  if ($webp !== '') {
    $cambiados++;
    printf("[%d] %s: %s (%d KB) -> %s (%d KB)\n", $p['id'], $p['nombre'], $nombre, $peso / 1024, $webp, $pesoNuevo / 1024);
  } else {
    printf("[%d] %s: %s (%d KB) se deja igual\n", $p['id'], $p['nombre'], $nombre, $peso / 1024);
  }
}

printf(
  "%s%d de %d productos optimizados. Peso de las fotos en uso: %d KB -> %d KB\n",
  $simular ? '[SIMULACION] ' : '',
  $cambiados,
  count($productos),
  $totalAntes / 1024,
  $totalDespues / 1024
);
