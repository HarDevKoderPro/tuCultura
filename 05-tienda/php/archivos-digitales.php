<?php
/**
 * Archivos descargables de los productos digitales (subida desde el admin).
 *
 * Un producto puede tener varios archivos (p. ej. un libro en PDF y en EPUB), cada uno
 * con una etiqueta opcional ("Versión PDF"). Se guardan FUERA de la carpeta publica
 * (volumen del contenedor /var/lib/tucultura/archivos-digitales, o
 * TIENDA_ARCHIVOS_DIGITALES_DIR) con un nombre aleatorio sin extension: nadie puede
 * descargarlos por URL directa. La descarga para compradores (con pago aprobado) se
 * entrega por PHP en un paso posterior.
 *
 * La relacion producto -> archivos vive en una tabla aparte (producto_archivo) para que
 * ninguna consulta publica con productos.* la exponga.
 *
 * Usa PDO ($pdo). Lo incluye admin.php.
 */

const ARCHIVO_DIGITAL_MAX_BYTES = 45 * 1024 * 1024; // por archivo; limite de subida de PHP (tucultura.ini)
const ARCHIVOS_DIGITALES_MAX_POR_PRODUCTO = 10;
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
    id INT PRIMARY KEY AUTO_INCREMENT,
    producto_id INT NOT NULL,
    nombre_original VARCHAR(255) NOT NULL,
    etiqueta VARCHAR(80) NOT NULL DEFAULT '',
    archivo VARCHAR(64) NOT NULL,
    tamano BIGINT NOT NULL DEFAULT 0,
    orden INT NOT NULL DEFAULT 0,
    fecha_subida TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_producto_archivo_producto (producto_id, orden)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // Tabla creada con un solo archivo por producto (producto_id era la llave): pasa a varios
  $columnaId = $pdo->query("SHOW COLUMNS FROM producto_archivo LIKE 'id'")->fetch();
  if (!$columnaId) {
    $pdo->exec("ALTER TABLE producto_archivo
      DROP PRIMARY KEY,
      ADD COLUMN id INT NOT NULL AUTO_INCREMENT FIRST,
      ADD PRIMARY KEY (id),
      ADD COLUMN etiqueta VARCHAR(80) NOT NULL DEFAULT '' AFTER nombre_original,
      ADD COLUMN orden INT NOT NULL DEFAULT 0 AFTER tamano,
      ADD KEY idx_producto_archivo_producto (producto_id, orden)");
  }

  $verificada = true;
}

function textoTamanoArchivo(int $bytes): string
{
  if ($bytes >= 1048576) return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
  return number_format(max(1, $bytes) / 1024, 0, ',', '.') . ' KB';
}

/**
 * Archivos de un producto en orden de subida (nunca incluye el nombre guardado en disco).
 */
function listarArchivosDigitales(PDO $pdo, int $productoId): array
{
  asegurarTablaArchivosDigitales($pdo);
  $stmt = $pdo->prepare("SELECT id, nombre_original, etiqueta, tamano, fecha_subida
                         FROM producto_archivo WHERE producto_id = ? ORDER BY orden, id");
  $stmt->execute([$productoId]);
  $archivos = [];
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
    $archivos[] = [
      'id' => (int)$fila['id'],
      'nombre' => $fila['nombre_original'],
      'etiqueta' => $fila['etiqueta'],
      'tamano' => (int)$fila['tamano'],
      'tamano_texto' => textoTamanoArchivo((int)$fila['tamano']),
      'fecha' => $fila['fecha_subida']
    ];
  }
  return $archivos;
}

/**
 * Valida y guarda un archivo subido ($_FILES[...]) como uno mas del producto.
 * Devuelve ['ok' => true, 'archivos' => [...]] o ['ok' => false, 'error' => '...'].
 */
function guardarArchivoDigital(PDO $pdo, int $productoId, array $archivo, string $etiqueta = ''): array
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

  $actuales = listarArchivosDigitales($pdo, $productoId);
  if (count($actuales) >= ARCHIVOS_DIGITALES_MAX_POR_PRODUCTO) {
    return ['ok' => false, 'error' => 'El producto ya tiene ' . ARCHIVOS_DIGITALES_MAX_POR_PRODUCTO . ' archivos. Quita alguno para subir otro.'];
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

  $etiqueta = preg_replace('/[\x00-\x1F\x7F<>]/u', '', trim($etiqueta));
  $etiqueta = mb_substr($etiqueta === null ? '' : $etiqueta, 0, 80);

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

  $orden = $actuales ? (int)$pdo->query("SELECT COALESCE(MAX(orden), 0) FROM producto_archivo WHERE producto_id = " . (int)$productoId)->fetchColumn() + 1 : 1;
  $pdo->prepare("INSERT INTO producto_archivo (producto_id, nombre_original, etiqueta, archivo, tamano, orden)
                 VALUES (?, ?, ?, ?, ?, ?)")
      ->execute([$productoId, $nombre, $etiqueta, $guardado, (int)$archivo['size'], $orden]);

  return ['ok' => true, 'archivos' => listarArchivosDigitales($pdo, $productoId)];
}

/**
 * Quita un archivo (fila y archivo en disco). Devuelve el id del producto al que
 * pertenecia o 0 si no existe.
 */
function eliminarArchivoDigital(PDO $pdo, int $archivoId): int
{
  asegurarTablaArchivosDigitales($pdo);
  $stmt = $pdo->prepare("SELECT producto_id, archivo FROM producto_archivo WHERE id = ?");
  $stmt->execute([$archivoId]);
  $fila = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$fila) return 0;

  $pdo->prepare("DELETE FROM producto_archivo WHERE id = ?")->execute([$archivoId]);
  if (preg_match('/^[a-f0-9]{32}$/', $fila['archivo'])) {
    @unlink(carpetaArchivosDigitales() . '/' . $fila['archivo']);
  }
  return (int)$fila['producto_id'];
}
