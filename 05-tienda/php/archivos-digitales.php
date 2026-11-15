<?php
/**
 * Archivos descargables de los productos digitales (paso 1: subida desde el admin).
 *
 * El archivo se guarda FUERA de la carpeta publica (volumen del contenedor
 * /var/lib/tucultura/archivos-digitales, o TIENDA_ARCHIVOS_DIGITALES_DIR) con un nombre
 * aleatorio sin extension: nadie puede descargarlo por URL directa. La descarga para
 * compradores (con pago aprobado) se entrega por PHP en un paso posterior.
 *
 * La relacion producto -> archivo vive en una tabla aparte (producto_archivo) para que
 * ninguna consulta publica con productos.* la exponga. Un archivo por producto.
 *
 * Usa PDO ($pdo). Lo incluye admin.php.
 */

const ARCHIVO_DIGITAL_MAX_BYTES = 45 * 1024 * 1024; // limite de subida de PHP (tucultura.ini)
const ARCHIVO_DIGITAL_EXTENSIONES = [
  'pdf', 'zip', 'rar', '7z', 'epub', 'mobi',
  'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
  'txt', 'csv', 'mp3', 'mp4'
];

function carpetaArchivosDigitales(): string
{
  $carpeta = getenv('TIENDA_ARCHIVOS_DIGITALES_DIR') ?: '/var/lib/tucultura/archivos-digitales';
  return rtrim($carpeta, '/');
}

function asegurarTablaArchivosDigitales(PDO $pdo): void
{
  static $verificada = false;
  if ($verificada) return;

  $pdo->exec("CREATE TABLE IF NOT EXISTS producto_archivo (
    producto_id INT PRIMARY KEY,
    nombre_original VARCHAR(255) NOT NULL,
    archivo VARCHAR(64) NOT NULL,
    tamano BIGINT NOT NULL DEFAULT 0,
    fecha_subida TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $verificada = true;
}

function textoTamanoArchivo(int $bytes): string
{
  if ($bytes >= 1048576) return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
  return number_format(max(1, $bytes) / 1024, 0, ',', '.') . ' KB';
}

/**
 * Datos del archivo de un producto o null si no tiene (nunca incluye el nombre guardado).
 */
function infoArchivoDigital(PDO $pdo, int $productoId): ?array
{
  asegurarTablaArchivosDigitales($pdo);
  $stmt = $pdo->prepare("SELECT nombre_original, tamano, fecha_subida FROM producto_archivo WHERE producto_id = ?");
  $stmt->execute([$productoId]);
  $fila = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$fila) return null;
  return [
    'nombre' => $fila['nombre_original'],
    'tamano' => (int)$fila['tamano'],
    'tamano_texto' => textoTamanoArchivo((int)$fila['tamano']),
    'fecha' => $fila['fecha_subida']
  ];
}

/**
 * Valida y guarda el archivo subido ($_FILES[...]) para un producto, reemplazando el
 * anterior. Devuelve ['ok' => true, 'info' => [...]] o ['ok' => false, 'error' => '...'].
 */
function guardarArchivoDigital(PDO $pdo, int $productoId, array $archivo): array
{
  asegurarTablaArchivosDigitales($pdo);

  if ($archivo['error'] !== UPLOAD_ERR_OK) {
    $msg = in_array($archivo['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE])
      ? 'El archivo supera el tamaño máximo permitido (45 MB).'
      : 'No se pudo subir el archivo. Intenta de nuevo.';
    return ['ok' => false, 'error' => $msg];
  }
  if ($archivo['size'] > ARCHIVO_DIGITAL_MAX_BYTES) {
    return ['ok' => false, 'error' => 'El archivo supera el tamaño máximo permitido (45 MB).'];
  }

  // Nombre que vera el comprador al descargar: sin rutas ni caracteres de control
  $nombre = basename(str_replace('\\', '/', (string)$archivo['name']));
  $nombre = preg_replace('/[\x00-\x1F\x7F"<>|:*?]/u', '', $nombre);
  if ($nombre === '' || $nombre === null) $nombre = 'archivo';
  if (mb_strlen($nombre) > 200) {
    $ext = pathinfo($nombre, PATHINFO_EXTENSION);
    $nombre = mb_substr(pathinfo($nombre, PATHINFO_FILENAME), 0, 190) . ($ext !== '' ? '.' . $ext : '');
  }
  $extension = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
  if (!in_array($extension, ARCHIVO_DIGITAL_EXTENSIONES, true)) {
    return ['ok' => false, 'error' => 'Formato no permitido. Usa: ' . strtoupper(implode(', ', ARCHIVO_DIGITAL_EXTENSIONES)) . '.'];
  }

  $carpeta = carpetaArchivosDigitales();
  if (!is_dir($carpeta) || !is_writable($carpeta)) {
    error_log('archivos-digitales: carpeta no disponible o sin permisos: ' . $carpeta);
    return ['ok' => false, 'error' => 'El almacenamiento de archivos digitales no está disponible en el servidor.'];
  }

  do {
    $guardado = bin2hex(random_bytes(16));
  } while (file_exists($carpeta . '/' . $guardado));

  if (!move_uploaded_file($archivo['tmp_name'], $carpeta . '/' . $guardado)) {
    return ['ok' => false, 'error' => 'Error al guardar el archivo en el servidor.'];
  }
  @chmod($carpeta . '/' . $guardado, 0640);

  $anterior = $pdo->prepare("SELECT archivo FROM producto_archivo WHERE producto_id = ?");
  $anterior->execute([$productoId]);
  $archivoAnterior = $anterior->fetchColumn();

  $pdo->prepare("INSERT INTO producto_archivo (producto_id, nombre_original, archivo, tamano)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE nombre_original = VALUES(nombre_original),
                   archivo = VALUES(archivo), tamano = VALUES(tamano), fecha_subida = CURRENT_TIMESTAMP")
      ->execute([$productoId, $nombre, $guardado, (int)$archivo['size']]);

  if ($archivoAnterior && preg_match('/^[a-f0-9]{32}$/', $archivoAnterior)) {
    @unlink($carpeta . '/' . $archivoAnterior);
  }

  return ['ok' => true, 'info' => infoArchivoDigital($pdo, $productoId)];
}

function eliminarArchivoDigital(PDO $pdo, int $productoId): void
{
  asegurarTablaArchivosDigitales($pdo);
  $stmt = $pdo->prepare("SELECT archivo FROM producto_archivo WHERE producto_id = ?");
  $stmt->execute([$productoId]);
  $guardado = $stmt->fetchColumn();
  if (!$guardado) return;

  $pdo->prepare("DELETE FROM producto_archivo WHERE producto_id = ?")->execute([$productoId]);
  if (preg_match('/^[a-f0-9]{32}$/', $guardado)) {
    @unlink(carpetaArchivosDigitales() . '/' . $guardado);
  }
}
