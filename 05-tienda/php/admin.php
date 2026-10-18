<?php
/**
 * API de Administración
 * Tu Cultura es Progreso - Tienda Virtual
 */

require_once 'config.php';
require_once 'imagenes-productos.php';
require_once 'bonos-helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');

// Verificar permisos de administrador
if (!esAdmin()) {
    jsonResponse(['error' => 'Acceso denegado. Se requieren permisos de administrador.'], 403);
}

$action = isset($_GET['action']) ? sanitizar($_GET['action']) : '';

switch ($action) {
    // Dashboard
    case 'dashboard':
        obtenerDashboard($conn);
        break;
    
    // Productos
    case 'productos_listar':
        listarProductosAdmin($conn);
        break;
    case 'producto_crear':
        crearProducto($conn);
        break;
    case 'producto_actualizar':
        actualizarProducto($conn);
        break;
    case 'producto_eliminar':
        eliminarProducto($conn);
        break;
    case 'producto_imagenes':
        listarImagenesProductoAdmin($pdo);
        break;
    case 'producto_imagenes_subir':
        subirImagenesProducto($pdo);
        break;
    case 'producto_video_subir':
        subirVideoProducto($pdo);
        break;
    case 'producto_imagen_eliminar':
        eliminarImagenProducto($pdo);
        break;
    case 'producto_imagen_principal':
        usarImagenComoPrincipal($pdo);
        break;
    case 'producto_detalle':
        detalleProductoAdmin($conn);
        break;
    
    // Categorías
    case 'categorias_listar':
        listarCategoriasAdmin($conn);
        break;
    case 'categoria_crear':
        crearCategoria($conn);
        break;
    case 'categoria_actualizar':
        actualizarCategoria($conn);
        break;
    case 'categoria_eliminar':
        eliminarCategoria($conn);
        break;
    
    // Configuración
    case 'obtenerUmbral':
        obtenerUmbral($conn);
        break;
    case 'actualizarUmbral':
        actualizarUmbralBajoStock($conn);
        break;
    case 'obtenerValorPunto':
        obtenerValorPunto($conn);
        break;
    case 'actualizarValorPunto':
        actualizarValorPunto($conn);
        break;
    
    // Bajo Stock
    case 'listarProductosBajoStock':
        listarProductosBajoStock($conn);
        break;
    case 'actualizarStockMasivo':
        actualizarStockMasivo($conn);
        break;
    
    // Pedidos
    case 'pedidos_listar':
        listarPedidosAdmin($conn);
        break;
    case 'pedido_detalle':
        detallePedidoAdmin($conn);
        break;
    case 'pedido_actualizar_estado':
        actualizarEstadoPedido($conn);
        break;
    
    // Correos enviados por la tienda
    case 'bonos_listar':
        listarBonosAdmin($conn);
        break;
    case 'bono_actualizar':
        actualizarBonoAdmin($conn);
        break;
    case 'bono_eliminar':
        eliminarBonoAdmin($conn);
        break;
    case 'bono_restaurar':
        restaurarBonoAdmin($conn);
        break;
    case 'bonos_asociados':
        listarAsociadosConBonoAdmin($conn);
        break;

    case 'correos_listar':
        listarCorreosAdmin($conn);
        break;
    case 'correo_detalle':
        detalleCorreoAdmin($conn);
        break;
    
    default:
        jsonResponse(['error' => 'Acción no válida'], 400);
}

// ==================== CONFIGURACIÓN ====================

/**
 * Obtiene un valor de configuración de la tabla configuracion_tienda.
 * Crea la tabla automáticamente si no existe.
 */
function obtenerConfiguracion($conn, $clave, $valorDefault = null) {
    // Asegurar que la tabla existe
    crearTablaConfiguracion($conn);
    
    $stmt = $conn->prepare("SELECT valor FROM configuracion_tienda WHERE clave = ?");
    $stmt->bind_param("s", $clave);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        return $row['valor'];
    }
    return $valorDefault;
}

/**
 * Actualiza un valor de configuración.
 */
function actualizarConfiguracion($conn, $clave, $valor) {
    crearTablaConfiguracion($conn);
    
    $stmt = $conn->prepare("INSERT INTO configuracion_tienda (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = ?, fecha_modificacion = CURRENT_TIMESTAMP");
    $stmt->bind_param("sss", $clave, $valor, $valor);
    return $stmt->execute();
}

/**
 * Crea la tabla configuracion_tienda si no existe (auto-migración).
 */
function crearTablaConfiguracion($conn) {
    static $verificada = false;
    if ($verificada) return;
    
    $conn->query("CREATE TABLE IF NOT EXISTS configuracion_tienda (
        id INT PRIMARY KEY AUTO_INCREMENT,
        clave VARCHAR(50) UNIQUE NOT NULL,
        valor VARCHAR(255) NOT NULL,
        descripcion TEXT,
        fecha_modificacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // Insertar valor por defecto si no existe
    $conn->query("INSERT IGNORE INTO configuracion_tienda (clave, valor, descripcion) VALUES ('umbral_bajo_stock', '10', 'Cantidad mínima de stock para considerar un producto como bajo stock')");
    
    $verificada = true;
}

/**
 * Endpoint: Obtener umbral de bajo stock actual.
 */
function obtenerUmbral($conn) {
    $umbral = obtenerConfiguracion($conn, 'umbral_bajo_stock', '10');
    jsonResponse(['success' => true, 'umbral' => intval($umbral)]);
}

/**
 * Endpoint: Actualizar umbral de bajo stock.
 */
function actualizarUmbralBajoStock($conn) {
    $umbral = isset($_POST['umbral']) ? intval($_POST['umbral']) : 0;
    
    if ($umbral < 1 || $umbral > 100) {
        jsonResponse(['error' => 'El umbral debe estar entre 1 y 100'], 400);
    }
    
    if (actualizarConfiguracion($conn, 'umbral_bajo_stock', strval($umbral))) {
        jsonResponse(['success' => true, 'mensaje' => 'Umbral actualizado a ' . $umbral . ' unidades', 'umbral' => $umbral]);
    } else {
        jsonResponse(['error' => 'Error al actualizar el umbral'], 500);
    }
}

/**
 * Total de puntos acumulados por todos los asociados (tabla puntos_saldo).
 */
function totalPuntosEmitidos($conn) {
    try {
        $row = $conn->query("SELECT COALESCE(SUM(puntos_acumulados), 0) AS total FROM puntos_saldo")->fetch_assoc();
        return intval($row['total']);
    } catch (Throwable $e) {
        // La tabla puede no existir sin la migracion de pagos/puntos
        return 0;
    }
}

/**
 * Endpoint: Valor de cada punto en COP (0 = sin definir) y total de puntos emitidos.
 */
function obtenerValorPunto($conn) {
    $valor = intval(obtenerConfiguracion($conn, 'valor_punto_cop', '0'));
    $puntos = totalPuntosEmitidos($conn);
    jsonResponse([
        'success' => true,
        'valor_punto' => $valor,
        'valor_punto_formateado' => formatearPrecio($valor),
        'puntos_emitidos' => $puntos,
        'equivalente_total' => $puntos * $valor,
        'equivalente_total_formateado' => formatearPrecio($puntos * $valor)
    ]);
}

/**
 * Endpoint: Actualizar el valor en COP de cada punto (0 deja el valor sin definir).
 */
function actualizarValorPunto($conn) {
    $entrada = isset($_POST['valor']) ? trim((string)$_POST['valor']) : '';
    
    if (!preg_match('/^\d{1,7}$/', $entrada)) {
        jsonResponse(['error' => 'El valor debe ser un número entero de pesos, entre 0 y 1.000.000'], 400);
    }
    $valor = intval($entrada);
    if ($valor > 1000000) {
        jsonResponse(['error' => 'El valor debe estar entre 0 y 1.000.000 pesos'], 400);
    }
    
    if (actualizarConfiguracion($conn, 'valor_punto_cop', strval($valor))) {
        jsonResponse([
            'success' => true,
            'mensaje' => 'Valor del punto actualizado a ' . formatearPrecio($valor),
            'valor_punto' => $valor,
            'valor_punto_formateado' => formatearPrecio($valor)
        ]);
    } else {
        jsonResponse(['error' => 'Error al actualizar el valor del punto'], 500);
    }
}

// ==================== DASHBOARD ====================

function obtenerDashboard($conn) {
    // Obtener umbral de bajo stock dinámico
    $umbralBajoStock = intval(obtenerConfiguracion($conn, 'umbral_bajo_stock', '10'));
    
    // Puntos de asociados y su valor en COP (0 = sin definir)
    $valorPunto = intval(obtenerConfiguracion($conn, 'valor_punto_cop', '0'));
    $puntosEmitidos = totalPuntosEmitidos($conn);
    
    // Total productos
    $totalProductos = $conn->query("SELECT COUNT(*) as total FROM productos WHERE activo = 1")->fetch_assoc()['total'];
    
    // Total pedidos
    $totalPedidos = $conn->query("SELECT COUNT(*) as total FROM pedidos")->fetch_assoc()['total'];
    
    // Pedidos pendientes
    $pedidosPendientes = $conn->query("SELECT COUNT(*) as total FROM pedidos WHERE estado = 'pendiente'")->fetch_assoc()['total'];
    
    // Ventas totales
    $ventasTotales = $conn->query("SELECT COALESCE(SUM(total), 0) as total FROM pedidos WHERE estado NOT IN ('cancelado')")->fetch_assoc()['total'];
    
    // Ventas del mes
    $ventasMes = $conn->query("
        SELECT COALESCE(SUM(total), 0) as total 
        FROM pedidos 
        WHERE estado NOT IN ('cancelado') 
        AND MONTH(fecha_creacion) = MONTH(CURRENT_DATE()) 
        AND YEAR(fecha_creacion) = YEAR(CURRENT_DATE())
    ")->fetch_assoc()['total'];
    
    // Productos con bajo stock (umbral dinámico)
    $stmtBajoStock = $conn->prepare("SELECT COUNT(*) as total FROM productos WHERE activo = 1 AND stock < ?");
    $stmtBajoStock->bind_param("i", $umbralBajoStock);
    $stmtBajoStock->execute();
    $productosBajoStock = $stmtBajoStock->get_result()->fetch_assoc()['total'];
    
    // Últimos 5 pedidos
    $ultimosPedidos = [];
    $result = $conn->query("
        SELECT p.numero_pedido, p.total, p.estado, p.fecha_creacion, r.nombres, r.apellidos
        FROM pedidos p
        LEFT JOIN registros r ON p.usuario_id = r.id
        ORDER BY p.fecha_creacion DESC
        LIMIT 5
    ");
    while ($row = $result->fetch_assoc()) {
        $row['total_formateado'] = formatearPrecio($row['total']);
        $row['fecha_formateada'] = date('d/m/Y H:i', strtotime($row['fecha_creacion']));
        $ultimosPedidos[] = $row;
    }
    
    // Productos más vendidos
    $productosPopulares = [];
    $result = $conn->query("
        SELECT p.nombre, SUM(dp.cantidad) as total_vendido
        FROM detalle_pedido dp
        JOIN productos p ON dp.producto_id = p.id
        JOIN pedidos ped ON dp.pedido_id = ped.id
        WHERE ped.estado NOT IN ('cancelado')
        GROUP BY p.id
        ORDER BY total_vendido DESC
        LIMIT 5
    ");
    while ($row = $result->fetch_assoc()) {
        $productosPopulares[] = $row;
    }
    
    jsonResponse([
        'success' => true,
        'dashboard' => [
            'total_productos' => intval($totalProductos),
            'total_pedidos' => intval($totalPedidos),
            'pedidos_pendientes' => intval($pedidosPendientes),
            'ventas_totales' => floatval($ventasTotales),
            'ventas_totales_formateado' => formatearPrecio($ventasTotales),
            'ventas_mes' => floatval($ventasMes),
            'ventas_mes_formateado' => formatearPrecio($ventasMes),
            'productos_bajo_stock' => intval($productosBajoStock),
            'umbral_bajo_stock' => $umbralBajoStock,
            'valor_punto' => $valorPunto,
            'valor_punto_formateado' => formatearPrecio($valorPunto),
            'puntos_emitidos' => $puntosEmitidos,
            'puntos_equivalente_formateado' => formatearPrecio($puntosEmitidos * $valorPunto),
            'ultimos_pedidos' => $ultimosPedidos,
            'productos_populares' => $productosPopulares
        ]
    ]);
}

// ==================== BAJO STOCK ====================

/**
 * Lista productos con stock menor al umbral configurado.
 */
function listarProductosBajoStock($conn) {
    $umbral = intval(obtenerConfiguracion($conn, 'umbral_bajo_stock', '10'));
    
    $stmt = $conn->prepare("
        SELECT p.id, p.nombre, p.stock, p.precio, p.imagen, c.nombre as categoria_nombre
        FROM productos p
        LEFT JOIN categorias c ON p.categoria_id = c.id
        WHERE p.activo = 1 AND p.stock < ?
        ORDER BY p.nombre ASC
    ");
    $stmt->bind_param("i", $umbral);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $productos = [];
    while ($row = $result->fetch_assoc()) {
        $row['precio_formateado'] = formatearPrecio($row['precio']);
        $productos[] = $row;
    }
    
    jsonResponse([
        'success' => true,
        'productos' => $productos,
        'umbral' => $umbral,
        'total' => count($productos)
    ]);
}

/**
 * Actualiza el stock de múltiples productos a la vez.
 * Recibe JSON con array de {id, stock}.
 */
function actualizarStockMasivo($conn) {
    // Leer JSON del body
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['productos']) || !is_array($input['productos'])) {
        jsonResponse(['error' => 'Datos inválidos. Se espera un array de productos.'], 400);
    }
    
    $productos = $input['productos'];
    $actualizados = 0;
    $errores = [];
    
    foreach ($productos as $item) {
        $id = isset($item['id']) ? intval($item['id']) : 0;
        $stock = isset($item['stock']) ? intval($item['stock']) : -1;
        
        if ($id <= 0) {
            $errores[] = "ID inválido: {$item['id']}";
            continue;
        }
        if ($stock < 0) {
            $errores[] = "Stock negativo para producto ID {$id}";
            continue;
        }
        
        $stmt = $conn->prepare("UPDATE productos SET stock = ? WHERE id = ? AND activo = 1");
        $stmt->bind_param("ii", $stock, $id);
        
        if ($stmt->execute() && $stmt->affected_rows >= 0) {
            $actualizados++;
        } else {
            $errores[] = "Error al actualizar producto ID {$id}";
        }
    }
    
    jsonResponse([
        'success' => true,
        'mensaje' => "Se actualizaron {$actualizados} producto(s).",
        'actualizados' => $actualizados,
        'errores' => $errores
    ]);
}

// ==================== PRODUCTOS ====================

function listarProductosAdmin($conn) {
    // Ordenar productos alfabéticamente por nombre (A → Z)
    $result = $conn->query("
        SELECT p.*, c.nombre as categoria_nombre
        FROM productos p
        LEFT JOIN categorias c ON p.categoria_id = c.id
        ORDER BY p.nombre ASC
    ");
    
    $productos = [];
    while ($row = $result->fetch_assoc()) {
        $row['precio_formateado'] = formatearPrecio($row['precio']);
        if ($row['precio_oferta']) {
            $row['precio_oferta_formateado'] = formatearPrecio($row['precio_oferta']);
        }
        $productos[] = $row;
    }
    
    jsonResponse(['success' => true, 'productos' => $productos]);
}

function detalleProductoAdmin($conn) {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    
    if (!$id) {
        jsonResponse(['error' => 'ID requerido'], 400);
    }
    
    $stmt = $conn->prepare("SELECT * FROM productos WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $producto = $stmt->get_result()->fetch_assoc();
    
    if ($producto) {
        jsonResponse(['success' => true, 'producto' => $producto]);
    } else {
        jsonResponse(['error' => 'Producto no encontrado'], 404);
    }
}

function crearProducto($conn) {
    // Validar campos requeridos
    if (empty($_POST['nombre']) || empty($_POST['precio'])) {
        jsonResponse(['error' => 'Nombre y precio son requeridos'], 400);
    }
    
    $nombre = sanitizar($_POST['nombre']);
    $descripcion = sanitizar($_POST['descripcion'] ?? '');
    $descripcion_corta = sanitizar($_POST['descripcion_corta'] ?? '');
    $precio = floatval($_POST['precio']);
    $precio_oferta = !empty($_POST['precio_oferta']) ? floatval($_POST['precio_oferta']) : null;
    $categoria_id = !empty($_POST['categoria_id']) ? intval($_POST['categoria_id']) : null;
    $stock = intval($_POST['stock'] ?? 0);
    $sku = sanitizar($_POST['sku'] ?? '');
    $marca = sanitizar($_POST['marca'] ?? '');
    $destacado = isset($_POST['destacado']) ? 1 : 0;
    $activo = isset($_POST['activo']) ? 1 : 0;
    
    // Procesar imagen subida
    $imagen = procesarImagenSubida();
    
    $stmt = $conn->prepare("
        INSERT INTO productos (nombre, descripcion, descripcion_corta, precio, precio_oferta, 
                              categoria_id, stock, sku, marca, destacado, activo, imagen)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        "sssddiissiis",
        $nombre, $descripcion, $descripcion_corta, $precio, $precio_oferta,
        $categoria_id, $stock, $sku, $marca, $destacado, $activo, $imagen
    );
    
    if ($stmt->execute()) {
        jsonResponse(['success' => true, 'mensaje' => 'Producto creado exitosamente', 'id' => $conn->insert_id]);
    } else {
        jsonResponse(['error' => 'Error al crear el producto'], 500);
    }
}

function actualizarProducto($conn) {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    
    if (!$id) {
        jsonResponse(['error' => 'ID requerido'], 400);
    }
    
    if (empty($_POST['nombre']) || empty($_POST['precio'])) {
        jsonResponse(['error' => 'Nombre y precio son requeridos'], 400);
    }
    
    $nombre = sanitizar($_POST['nombre']);
    $descripcion = sanitizar($_POST['descripcion'] ?? '');
    $descripcion_corta = sanitizar($_POST['descripcion_corta'] ?? '');
    $precio = floatval($_POST['precio']);
    $precio_oferta = !empty($_POST['precio_oferta']) ? floatval($_POST['precio_oferta']) : null;
    $categoria_id = !empty($_POST['categoria_id']) ? intval($_POST['categoria_id']) : null;
    $stock = intval($_POST['stock'] ?? 0);
    $sku = sanitizar($_POST['sku'] ?? '');
    $marca = sanitizar($_POST['marca'] ?? '');
    $destacado = isset($_POST['destacado']) && $_POST['destacado'] ? 1 : 0;
    $activo = isset($_POST['activo']) && $_POST['activo'] ? 1 : 0;
    
    // Procesar imagen: nueva subida o mantener actual
    $imagenNueva = procesarImagenSubida();
    if ($imagenNueva) {
        $imagen = $imagenNueva;
    } elseif (!empty($_POST['imagen_actual'])) {
        $imagen = sanitizar($_POST['imagen_actual']);
    } else {
        $imagen = '';
    }
    
    $stmt = $conn->prepare("
        UPDATE productos SET 
            nombre = ?, descripcion = ?, descripcion_corta = ?, precio = ?, precio_oferta = ?,
            categoria_id = ?, stock = ?, sku = ?, marca = ?, destacado = ?, activo = ?, imagen = ?
        WHERE id = ?
    ");
    $stmt->bind_param(
        "sssddiissiisi",
        $nombre, $descripcion, $descripcion_corta, $precio, $precio_oferta,
        $categoria_id, $stock, $sku, $marca, $destacado, $activo, $imagen, $id
    );
    
    if ($stmt->execute()) {
        jsonResponse(['success' => true, 'mensaje' => 'Producto actualizado exitosamente']);
    } else {
        jsonResponse(['error' => 'Error al actualizar el producto'], 500);
    }
}

function eliminarProducto($conn) {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    
    if (!$id) {
        jsonResponse(['error' => 'ID requerido'], 400);
    }
    
    // Soft delete
    $stmt = $conn->prepare("UPDATE productos SET activo = 0 WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        jsonResponse(['success' => true, 'mensaje' => 'Producto eliminado exitosamente']);
    } else {
        jsonResponse(['error' => 'Error al eliminar el producto'], 500);
    }
}

// ==================== FOTOS ADICIONALES ====================

function listarImagenesProductoAdmin($pdo) {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if (!$id) {
        jsonResponse(['error' => 'ID requerido'], 400);
    }
    jsonResponse([
        'success' => true,
        'imagenes' => listarImagenesAdicionales($pdo, $id),
        'maximo' => IMAGENES_PRODUCTO_MAX_ADICIONALES
    ]);
}

function subirImagenesProducto($pdo) {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if (!$id) {
        jsonResponse(['error' => 'ID requerido'], 400);
    }

    $stmt = $pdo->prepare("SELECT id FROM productos WHERE id = ?");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        jsonResponse(['error' => 'Producto no encontrado'], 404);
    }

    // Fotos y videos comparten el limite de adicionales
    // $_FILES['imagenes'] llega con un arreglo por campo (name[], tmp_name[], ...)
    $archivos = [];
    if (isset($_FILES['imagenes']) && is_array($_FILES['imagenes']['name'])) {
        foreach ($_FILES['imagenes']['name'] as $i => $nombre) {
            if ($_FILES['imagenes']['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
            $archivos[] = [
                'name' => $nombre,
                'type' => $_FILES['imagenes']['type'][$i],
                'tmp_name' => $_FILES['imagenes']['tmp_name'][$i],
                'error' => $_FILES['imagenes']['error'][$i],
                'size' => $_FILES['imagenes']['size'][$i],
            ];
        }
    }
    if (!$archivos) {
        jsonResponse(['error' => 'No se recibieron fotos'], 400);
    }

    $actuales = listarImagenesAdicionales($pdo, $id);
    $disponibles = IMAGENES_PRODUCTO_MAX_ADICIONALES - count($actuales);
    if (count($archivos) > $disponibles) {
        jsonResponse(['error' => 'Cada producto admite hasta ' . IMAGENES_PRODUCTO_MAX_ADICIONALES
            . " fotos adicionales. Puedes subir {$disponibles} más."], 400);
    }

    $stmtOrden = $pdo->prepare("SELECT COALESCE(MAX(orden), 0) FROM producto_imagenes WHERE producto_id = ?");
    $stmtOrden->execute([$id]);
    $orden = (int)$stmtOrden->fetchColumn();
    $insertar = $pdo->prepare("INSERT INTO producto_imagenes (producto_id, imagen, orden) VALUES (?, ?, ?)");
    foreach ($archivos as $archivo) {
        $imagen = guardarArchivoImagen($archivo);
        $insertar->execute([$id, $imagen, ++$orden]);
    }

    jsonResponse([
        'success' => true,
        'mensaje' => count($archivos) === 1 ? 'Foto agregada' : count($archivos) . ' fotos agregadas',
        'imagenes' => listarImagenesAdicionales($pdo, $id)
    ]);
}

function subirVideoProducto($pdo) {
    // Si el archivo supera post_max_size, PHP descarta todo el POST
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        jsonResponse(['error' => 'El video supera el tamaño máximo permitido (40 MB).'], 400);
    }

    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if (!$id) {
        jsonResponse(['error' => 'ID requerido'], 400);
    }

    $stmt = $pdo->prepare("SELECT id FROM productos WHERE id = ?");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        jsonResponse(['error' => 'Producto no encontrado'], 404);
    }

    if (!isset($_FILES['video']) || $_FILES['video']['error'] === UPLOAD_ERR_NO_FILE) {
        jsonResponse(['error' => 'No se recibió el video'], 400);
    }

    $actuales = listarImagenesAdicionales($pdo, $id);
    $videos = count(array_filter($actuales, function ($f) { return $f['tipo'] === 'video'; }));
    if ($videos >= VIDEOS_PRODUCTO_MAX) {
        jsonResponse(['error' => 'Este producto ya tiene video. Quita el actual para subir otro.'], 400);
    }
    if (count($actuales) >= IMAGENES_PRODUCTO_MAX_ADICIONALES) {
        jsonResponse(['error' => 'El producto ya tiene ' . IMAGENES_PRODUCTO_MAX_ADICIONALES . ' fotos y videos adicionales.'], 400);
    }

    $video = guardarArchivoVideo($_FILES['video']);

    $stmtOrden = $pdo->prepare("SELECT COALESCE(MAX(orden), 0) FROM producto_imagenes WHERE producto_id = ?");
    $stmtOrden->execute([$id]);
    $orden = (int)$stmtOrden->fetchColumn() + 1;
    $pdo->prepare("INSERT INTO producto_imagenes (producto_id, imagen, tipo, orden) VALUES (?, ?, 'video', ?)")
        ->execute([$id, $video, $orden]);

    jsonResponse([
        'success' => true,
        'mensaje' => 'Video agregado',
        'imagenes' => listarImagenesAdicionales($pdo, $id)
    ]);
}

/**
 * Valida y guarda un video (MP4, WebM o MOV, hasta VIDEO_PRODUCTO_MAX_BYTES) en
 * ../imagenes/productos/. Se guarda tal cual (no hay conversion en el servidor).
 */
function guardarArchivoVideo($archivo) {
    if ($archivo['error'] !== UPLOAD_ERR_OK) {
        $msg = in_array($archivo['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE])
            ? 'El video supera el tamaño máximo permitido (40 MB).'
            : 'No se pudo subir el video. Intenta de nuevo.';
        jsonResponse(['error' => $msg], 400);
    }

    if ($archivo['size'] > VIDEO_PRODUCTO_MAX_BYTES) {
        jsonResponse(['error' => 'El video supera el tamaño máximo permitido (40 MB).'], 400);
    }

    $extensiones = ['mp4' => ['video/mp4'], 'webm' => ['video/webm'], 'mov' => ['video/quicktime', 'video/mp4']];
    $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeReal = $finfo->file($archivo['tmp_name']);
    if (!isset($extensiones[$extension]) || !in_array($mimeReal, $extensiones[$extension])) {
        jsonResponse(['error' => 'Formato de video no permitido. Usa MP4 (recomendado), WebM o MOV.'], 400);
    }

    $carpetaDestino = __DIR__ . '/../imagenes/productos/';
    if (!is_dir($carpetaDestino)) {
        mkdir($carpetaDestino, 0755, true);
    }
    do {
        $nombreUnico = time() . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
    } while (file_exists($carpetaDestino . $nombreUnico));

    if (!move_uploaded_file($archivo['tmp_name'], $carpetaDestino . $nombreUnico)) {
        jsonResponse(['error' => 'Error al guardar el video en el servidor.'], 500);
    }
    return $nombreUnico;
}

function eliminarImagenProducto($pdo) {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if (!$id) {
        jsonResponse(['error' => 'ID requerido'], 400);
    }
    asegurarTablaImagenesProducto($pdo);

    // Solo se quita de la galeria; el archivo queda en disco (igual que la foto principal)
    $stmt = $pdo->prepare("SELECT producto_id FROM producto_imagenes WHERE id = ?");
    $stmt->execute([$id]);
    $productoId = $stmt->fetchColumn();
    if (!$productoId) {
        jsonResponse(['error' => 'Foto no encontrada'], 404);
    }
    $pdo->prepare("DELETE FROM producto_imagenes WHERE id = ?")->execute([$id]);

    jsonResponse([
        'success' => true,
        'mensaje' => 'Foto quitada',
        'imagenes' => listarImagenesAdicionales($pdo, (int)$productoId)
    ]);
}

/**
 * Intercambia una foto adicional con la principal del producto.
 */
function usarImagenComoPrincipal($pdo) {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if (!$id) {
        jsonResponse(['error' => 'ID requerido'], 400);
    }
    asegurarTablaImagenesProducto($pdo);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT pi.producto_id, pi.imagen, pi.tipo, p.imagen AS principal
                               FROM producto_imagenes pi
                               JOIN productos p ON p.id = pi.producto_id
                               WHERE pi.id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$fila) {
            $pdo->rollBack();
            jsonResponse(['error' => 'Foto no encontrada'], 404);
        }
        if ($fila['tipo'] === 'video') {
            $pdo->rollBack();
            jsonResponse(['error' => 'Un video no puede ser la foto principal'], 400);
        }

        $pdo->prepare("UPDATE productos SET imagen = ? WHERE id = ?")->execute([$fila['imagen'], $fila['producto_id']]);
        if (!empty($fila['principal'])) {
            $pdo->prepare("UPDATE producto_imagenes SET imagen = ? WHERE id = ?")->execute([$fila['principal'], $id]);
        } else {
            $pdo->prepare("DELETE FROM producto_imagenes WHERE id = ?")->execute([$id]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonResponse(['error' => 'No se pudo cambiar la foto principal'], 500);
    }

    jsonResponse([
        'success' => true,
        'mensaje' => 'Foto principal actualizada',
        'principal' => $fila['imagen'],
        'imagenes' => listarImagenesAdicionales($pdo, (int)$fila['producto_id'])
    ]);
}

// ==================== CATEGORÍAS ====================

function listarCategoriasAdmin($conn) {
    $result = $conn->query("
        SELECT c.*, COUNT(p.id) as total_productos
        FROM categorias c
        LEFT JOIN productos p ON c.id = p.categoria_id AND p.activo = 1
        GROUP BY c.id
        ORDER BY c.nombre ASC
    ");
    
    $categorias = [];
    while ($row = $result->fetch_assoc()) {
        $categorias[] = $row;
    }
    
    jsonResponse(['success' => true, 'categorias' => $categorias]);
}

function crearCategoria($conn) {
    if (empty($_POST['nombre'])) {
        jsonResponse(['error' => 'Nombre es requerido'], 400);
    }
    
    $nombre = sanitizar($_POST['nombre']);
    $descripcion = sanitizar($_POST['descripcion'] ?? '');
    $imagen = sanitizar($_POST['imagen'] ?? '');
    $activo = isset($_POST['activo']) ? 1 : 0;
    
    $stmt = $conn->prepare("INSERT INTO categorias (nombre, descripcion, imagen, activo) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("sssi", $nombre, $descripcion, $imagen, $activo);
    
    if ($stmt->execute()) {
        jsonResponse(['success' => true, 'mensaje' => 'Categoría creada exitosamente', 'id' => $conn->insert_id]);
    } else {
        jsonResponse(['error' => 'Error al crear la categoría'], 500);
    }
}

function actualizarCategoria($conn) {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    
    if (!$id || empty($_POST['nombre'])) {
        jsonResponse(['error' => 'ID y nombre son requeridos'], 400);
    }
    
    $nombre = sanitizar($_POST['nombre']);
    $descripcion = sanitizar($_POST['descripcion'] ?? '');
    $imagen = sanitizar($_POST['imagen'] ?? '');
    $activo = isset($_POST['activo']) && $_POST['activo'] ? 1 : 0;
    
    $stmt = $conn->prepare("UPDATE categorias SET nombre = ?, descripcion = ?, imagen = ?, activo = ? WHERE id = ?");
    $stmt->bind_param("sssii", $nombre, $descripcion, $imagen, $activo, $id);
    
    if ($stmt->execute()) {
        jsonResponse(['success' => true, 'mensaje' => 'Categoría actualizada exitosamente']);
    } else {
        jsonResponse(['error' => 'Error al actualizar la categoría'], 500);
    }
}

function eliminarCategoria($conn) {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    
    if (!$id) {
        jsonResponse(['error' => 'ID requerido'], 400);
    }
    
    // Verificar si hay productos asociados
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM productos WHERE categoria_id = ? AND activo = 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $total = $stmt->get_result()->fetch_assoc()['total'];
    
    if ($total > 0) {
        jsonResponse(['error' => "No se puede eliminar. Hay $total productos asociados a esta categoría."], 400);
    }
    
    // Soft delete
    $stmt = $conn->prepare("UPDATE categorias SET activo = 0 WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        jsonResponse(['success' => true, 'mensaje' => 'Categoría eliminada exitosamente']);
    } else {
        jsonResponse(['error' => 'Error al eliminar la categoría'], 500);
    }
}

// ==================== PEDIDOS ====================

function listarPedidosAdmin($conn) {
    $estado = isset($_GET['estado']) ? sanitizar($_GET['estado']) : '';
    
    $sql = "
        SELECT p.*, r.nombres, r.apellidos, r.email as usuario_email,
               (SELECT COUNT(*) FROM detalle_pedido WHERE pedido_id = p.id) as total_items
        FROM pedidos p
        LEFT JOIN registros r ON p.usuario_id = r.id
    ";
    
    if ($estado) {
        $sql .= " WHERE p.estado = '$estado'";
    }
    
    $sql .= " ORDER BY p.fecha_creacion DESC";
    
    $result = $conn->query($sql);
    
    $pedidos = [];
    while ($row = $result->fetch_assoc()) {
        $row['total_formateado'] = formatearPrecio($row['total']);
        $row['fecha_formateada'] = date('d/m/Y H:i', strtotime($row['fecha_creacion']));
        $row['estado_texto'] = ucfirst($row['estado']);
        $pedidos[] = $row;
    }
    
    jsonResponse(['success' => true, 'pedidos' => $pedidos]);
}

function detallePedidoAdmin($conn) {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    
    if (!$id) {
        jsonResponse(['error' => 'ID requerido'], 400);
    }
    
    // Obtener pedido
    $stmt = $conn->prepare("
        SELECT p.*, r.nombres, r.apellidos, r.email as usuario_email, r.telefono as usuario_telefono
        FROM pedidos p
        LEFT JOIN registros r ON p.usuario_id = r.id
        WHERE p.id = ?
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $pedido = $stmt->get_result()->fetch_assoc();
    
    if (!$pedido) {
        jsonResponse(['error' => 'Pedido no encontrado'], 404);
    }
    
    // Obtener detalles
    $stmtDetalles = $conn->prepare("
        SELECT dp.*, prod.imagen
        FROM detalle_pedido dp
        LEFT JOIN productos prod ON dp.producto_id = prod.id
        WHERE dp.pedido_id = ?
    ");
    $stmtDetalles->bind_param("i", $id);
    $stmtDetalles->execute();
    $resultDetalles = $stmtDetalles->get_result();
    
    $detalles = [];
    while ($row = $resultDetalles->fetch_assoc()) {
        $row['precio_unitario_formateado'] = formatearPrecio($row['precio_unitario']);
        $row['subtotal_formateado'] = formatearPrecio($row['subtotal']);
        $detalles[] = $row;
    }
    
    // Formatear datos
    $pedido['subtotal_formateado'] = formatearPrecio($pedido['subtotal']);
    $pedido['costo_envio_formateado'] = formatearPrecio($pedido['costo_envio']);
    $pedido['impuestos_formateado'] = formatearPrecio($pedido['impuestos']);
    $pedido['total_formateado'] = formatearPrecio($pedido['total']);
    $pedido['descuento_bono'] = (float)($pedido['descuento_bono'] ?? 0);
    $pedido['descuento_bono_formateado'] = formatearPrecio($pedido['descuento_bono']);
    $pedido['fecha_formateada'] = date('d/m/Y H:i', strtotime($pedido['fecha_creacion']));
    $pedido['estado_texto'] = ucfirst($pedido['estado']);
    $pedido['detalles'] = $detalles;
    
    jsonResponse(['success' => true, 'pedido' => $pedido]);
}

// ==================== UTILIDAD: SUBIDA DE IMÁGENES ====================

/**
 * Procesa la imagen subida desde el formulario de producto.
 * Valida tipo MIME, extensión real, tamaño y que sea una imagen válida.
 * Guarda en ../imagenes/productos/ con nombre único y genera una versión WebP
 * optimizada (imagenes-productos.php); el original se conserva en disco.
 * @return string Nombre del archivo a usar (el WebP si se generó), o cadena vacía si no hay imagen.
 */
function procesarImagenSubida() {
    if (!isset($_FILES['imagen_file']) || $_FILES['imagen_file']['error'] === UPLOAD_ERR_NO_FILE) {
        return '';
    }

    return guardarArchivoImagen($_FILES['imagen_file']);
}

/**
 * Valida y guarda un archivo de $_FILES (uno solo). Responde con error JSON si
 * no es valido. @return string Nombre del archivo a usar (el WebP si se genero).
 */
function guardarArchivoImagen($archivo) {
    // Verificar errores de subida
    if ($archivo['error'] !== UPLOAD_ERR_OK) {
        $errores = [
            UPLOAD_ERR_INI_SIZE   => 'El archivo excede el tamaño máximo del servidor.',
            UPLOAD_ERR_FORM_SIZE  => 'El archivo excede el tamaño máximo del formulario.',
            UPLOAD_ERR_PARTIAL    => 'El archivo se subió parcialmente.',
            UPLOAD_ERR_NO_TMP_DIR => 'Falta la carpeta temporal del servidor.',
            UPLOAD_ERR_CANT_WRITE => 'No se pudo escribir el archivo en disco.',
            UPLOAD_ERR_EXTENSION  => 'Una extensión de PHP detuvo la subida.',
        ];
        $msg = $errores[$archivo['error']] ?? 'Error desconocido al subir la imagen.';
        jsonResponse(['error' => $msg], 400);
    }
    
    // Validar tamaño (máximo 5 MB)
    $maxSize = 5 * 1024 * 1024;
    if ($archivo['size'] > $maxSize) {
        jsonResponse(['error' => 'La imagen excede el tamaño máximo de 5 MB.'], 400);
    }
    
    // Validar tipo MIME real (no confiar solo en el nombre)
    $mimePermitidos = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeReal = $finfo->file($archivo['tmp_name']);
    
    if (!in_array($mimeReal, $mimePermitidos)) {
        jsonResponse(['error' => 'Tipo de archivo no permitido. Usa: JPG, PNG, WebP, GIF o AVIF.'], 400);
    }
    
    // Validar que sea realmente una imagen
    $infoImagen = @getimagesize($archivo['tmp_name']);
    if ($infoImagen === false) {
        jsonResponse(['error' => 'El archivo no es una imagen válida.'], 400);
    }
    
    // Validar extensión del nombre original
    $extensionesPermitidas = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];
    $nombreOriginal = $archivo['name'];
    $extension = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
    
    if (!in_array($extension, $extensionesPermitidas)) {
        jsonResponse(['error' => 'Extensión de archivo no permitida.'], 400);
    }
    
    // Generar nombre único: timestamp_random.ext
    $nombreUnico = time() . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
    
    // Crear carpeta de destino si no existe
    $carpetaDestino = __DIR__ . '/../imagenes/productos/';
    if (!is_dir($carpetaDestino)) {
        mkdir($carpetaDestino, 0755, true);
    }
    
    $rutaDestino = $carpetaDestino . $nombreUnico;
    
    // Prevenir sobrescritura (aunque el nombre es único, por seguridad)
    while (file_exists($rutaDestino)) {
        $nombreUnico = time() . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
        $rutaDestino = $carpetaDestino . $nombreUnico;
    }
    
    // Mover archivo
    if (!move_uploaded_file($archivo['tmp_name'], $rutaDestino)) {
        jsonResponse(['error' => 'Error al guardar la imagen en el servidor.'], 500);
    }
    
    $optimizada = optimizarImagenProducto($carpetaDestino, $nombreUnico);
    return $optimizada !== '' ? $optimizada : $nombreUnico;
}

function actualizarEstadoPedido($conn) {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $estado = isset($_POST['estado']) ? sanitizar($_POST['estado']) : '';
    
    $estados_validos = ['pendiente', 'confirmado', 'procesando', 'enviado', 'entregado', 'cancelado'];
    
    if (!$id || !in_array($estado, $estados_validos)) {
        jsonResponse(['error' => 'ID y estado válido son requeridos'], 400);
    }
    
    $stmt = $conn->prepare("UPDATE pedidos SET estado = ? WHERE id = ?");
    $stmt->bind_param("si", $estado, $id);

    if ($stmt->execute()) {
        // Pedido cancelado: si se pago con bono, el bono y sus puntos vuelven al asociado
        if ($estado === 'cancelado') {
            liberarBonosDePedidosCancelados($conn);
        }
        jsonResponse(['success' => true, 'mensaje' => 'Estado actualizado exitosamente']);
    } else {
        jsonResponse(['error' => 'Error al actualizar el estado'], 500);
    }
}

// ==================== CORREOS ====================

/**
 * Endpoint: Lista los correos de pedidos enviados (sin el cuerpo).
 * Filtros opcionales: pedido (numero de pedido, parcial), tipo (cliente|tienda).
 */
// ==================== BONOS POR PUNTOS ====================

/**
 * Endpoint: reporte de bonos (uno cada PUNTOS_POR_BONO puntos de saldo, ver
 * bonos-helpers.php) para el seguimiento. Filtros: estado (pendiente/entregado/anulado;
 * vacio = todos), buscar (nombre, apellido o email). Incluye los asociados proximos
 * a su siguiente bono.
 */
function listarBonosAdmin($conn) {
    sincronizarBonosTodos($conn);

    $estado = isset($_GET['estado']) ? trim($_GET['estado']) : '';
    $buscar = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';

    $sql = "SELECT b.id, b.usuario_id, b.numero, b.puntos_meta, b.fecha_obtenido, b.estado,
                   b.fecha_entrega, b.nota, b.entregado_por,
                   b.fecha_anulado, b.anulado_por, b.motivo_anulado,
                   b.pedido_id, b.valor_cop, pe.numero_pedido, pe.estado AS estado_pedido,
                   r.nombres, r.apellidos, r.email, r.telefono, r.referente,
                   COALESCE(s.puntos_acumulados, 0) AS puntos_actuales
            FROM bonos_puntos b
            JOIN registros r ON r.id = b.usuario_id
            LEFT JOIN puntos_saldo s ON s.usuario_id = b.usuario_id
            LEFT JOIN pedidos pe ON pe.id = b.pedido_id
            WHERE 1 = 1";
    $tipos = '';
    $params = [];
    if (in_array($estado, ['pendiente', 'entregado', 'anulado'], true)) {
        $sql .= " AND b.estado = ?";
        $tipos .= 's';
        $params[] = $estado;
    }
    if ($buscar !== '') {
        $sql .= " AND (r.nombres LIKE ? OR r.apellidos LIKE ? OR r.email LIKE ?)";
        $tipos .= 'sss';
        $like = '%' . $buscar . '%';
        array_push($params, $like, $like, $like);
    }
    // Pendientes primero (los mas antiguos arriba), luego entregados y eliminados recientes
    $sql .= " ORDER BY FIELD(b.estado, 'pendiente', 'entregado', 'anulado'),
              CASE WHEN b.estado = 'pendiente' THEN b.fecha_obtenido END ASC,
              COALESCE(b.fecha_anulado, b.fecha_entrega) DESC LIMIT 500";

    $stmt = $conn->prepare($sql);
    if ($params) {
        $stmt->bind_param($tipos, ...$params);
    }
    $stmt->execute();
    $bonos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($bonos as &$b) {
        $b['id'] = (int)$b['id'];
        $b['numero'] = (int)$b['numero'];
        $b['puntos_meta'] = (int)$b['puntos_meta'];
        $b['puntos_actuales'] = (int)$b['puntos_actuales'];
        $b['valor_cop'] = $b['valor_cop'] !== null ? (int)$b['valor_cop'] : null;
        $b['valor_formateado'] = $b['valor_cop'] !== null ? formatearPrecio($b['valor_cop']) : '';
        $b['fecha_obtenido_formateada'] = date('d/m/Y', strtotime($b['fecha_obtenido']));
        $b['fecha_entrega_formateada'] = $b['fecha_entrega'] ? date('d/m/Y H:i', strtotime($b['fecha_entrega'])) : '';
        $b['fecha_anulado_formateada'] = $b['fecha_anulado'] ? date('d/m/Y H:i', strtotime($b['fecha_anulado'])) : '';
    }
    unset($b);

    $valorBono = valorBonoCop($conn);
    $resumen = [
        'pendientes' => 0, 'entregados' => 0, 'anulados' => 0, 'asociados' => 0,
        'puntos_por_bono' => PUNTOS_POR_BONO,
        'valor_bono' => $valorBono,
        'valor_bono_formateado' => $valorBono > 0 ? formatearPrecio($valorBono) : 'Sin definir'
    ];
    $claves = ['pendiente' => 'pendientes', 'entregado' => 'entregados', 'anulado' => 'anulados'];
    $res = $conn->query("SELECT estado, COUNT(*) AS total FROM bonos_puntos GROUP BY estado");
    while ($fila = $res->fetch_assoc()) {
        if (isset($claves[$fila['estado']])) $resumen[$claves[$fila['estado']]] = (int)$fila['total'];
    }
    $resumen['asociados'] = (int)$conn->query("SELECT COUNT(DISTINCT usuario_id) FROM bonos_puntos WHERE estado <> 'anulado'")->fetch_row()[0];

    // Proximos: les faltan 10 puntos o menos para su siguiente bono
    $proximos = [];
    $res = $conn->query("SELECT s.usuario_id, s.puntos_acumulados, r.nombres, r.apellidos, r.email
                         FROM puntos_saldo s JOIN registros r ON r.id = s.usuario_id
                         WHERE s.puntos_acumulados > 0
                           AND " . (int)PUNTOS_POR_BONO . " - MOD(s.puntos_acumulados, " . (int)PUNTOS_POR_BONO . ") <= 10
                         ORDER BY MOD(s.puntos_acumulados, " . (int)PUNTOS_POR_BONO . ") DESC LIMIT 50");
    while ($fila = $res->fetch_assoc()) {
        $puntos = (int)$fila['puntos_acumulados'];
        $proximos[] = [
            'nombre' => trim($fila['nombres'] . ' ' . $fila['apellidos']),
            'email' => $fila['email'],
            'puntos' => $puntos,
            'puntos_faltantes' => PUNTOS_POR_BONO - ($puntos % PUNTOS_POR_BONO),
        ];
    }

    jsonResponse(['success' => true, 'bonos' => $bonos, 'resumen' => $resumen, 'proximos' => $proximos]);
}

/**
 * Endpoint (POST id, estado entregado|pendiente, nota): entrega manual de un bono
 * (descuenta sus puntos) o la deshace (los devuelve). Los bonos usados en un pedido
 * se devuelven cancelando el pedido.
 */
function actualizarBonoAdmin($conn) {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $estado = isset($_POST['estado']) ? trim($_POST['estado']) : '';
    $nota = mb_substr(trim($_POST['nota'] ?? ''), 0, 255);
    if (!$id || !in_array($estado, ['pendiente', 'entregado'], true)) {
        jsonResponse(['error' => 'Datos inválidos'], 400);
    }

    $error = cambiarEstadoBono($conn, $id, $estado === 'entregado' ? 'entregar' : 'deshacer', $_SESSION['email'] ?? '', $nota);
    if ($error !== '') {
        jsonResponse(['error' => $error], 400);
    }
    jsonResponse([
        'success' => true,
        'mensaje' => $estado === 'entregado' ? 'Bono marcado como entregado' : 'Bono vuelto a pendiente'
    ]);
}

/**
 * Endpoint (POST id, motivo): elimina un bono (estado 'anulado' con motivo, fecha y
 * admin, para la trazabilidad) y descuenta sus puntos. Se puede restaurar.
 */
function eliminarBonoAdmin($conn) {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $motivo = mb_substr(trim($_POST['motivo'] ?? ''), 0, 255);
    if (!$id || $motivo === '') {
        jsonResponse(['error' => 'Indica el motivo para eliminar el bono'], 400);
    }

    $error = cambiarEstadoBono($conn, $id, 'anular', $_SESSION['email'] ?? '', $motivo);
    if ($error !== '') {
        jsonResponse(['error' => $error], 400);
    }
    jsonResponse(['success' => true, 'mensaje' => 'Bono eliminado (queda en Eliminados)']);
}

/**
 * Endpoint (POST id): restaura un bono eliminado a su estado anterior
 * (entregado si tenia fecha de entrega, si no pendiente devolviendo los puntos).
 */
function restaurarBonoAdmin($conn) {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if (!$id) {
        jsonResponse(['error' => 'ID requerido'], 400);
    }

    $error = cambiarEstadoBono($conn, $id, 'restaurar', $_SESSION['email'] ?? '', '');
    if ($error !== '') {
        jsonResponse(['error' => $error], 400);
    }
    jsonResponse(['success' => true, 'mensaje' => 'Bono restaurado']);
}

/**
 * Endpoint: asociados con bono (sin contar eliminados), con sus totales. Filtro buscar.
 */
function listarAsociadosConBonoAdmin($conn) {
    sincronizarBonosTodos($conn);
    $buscar = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';

    $sql = "SELECT r.id AS usuario_id, r.nombres, r.apellidos, r.email, r.telefono,
                   COALESCE(s.puntos_acumulados, 0) AS puntos,
                   SUM(b.estado = 'pendiente') AS pendientes,
                   SUM(b.estado = 'entregado') AS entregados,
                   SUM(b.estado = 'anulado') AS anulados,
                   MAX(b.fecha_obtenido) AS ultimo_bono
            FROM bonos_puntos b
            JOIN registros r ON r.id = b.usuario_id
            LEFT JOIN puntos_saldo s ON s.usuario_id = b.usuario_id
            WHERE 1 = 1";
    $params = [];
    if ($buscar !== '') {
        $sql .= " AND (r.nombres LIKE ? OR r.apellidos LIKE ? OR r.email LIKE ?)";
        $like = '%' . $buscar . '%';
        $params = [$like, $like, $like];
    }
    $sql .= " GROUP BY r.id, r.nombres, r.apellidos, r.email, r.telefono, s.puntos_acumulados
              HAVING pendientes + entregados > 0
              ORDER BY pendientes DESC, puntos DESC LIMIT 500";

    $stmt = $conn->prepare($sql);
    if ($params) {
        $stmt->bind_param('sss', ...$params);
    }
    $stmt->execute();
    $asociados = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($asociados as &$a) {
        foreach (['usuario_id', 'puntos', 'pendientes', 'entregados', 'anulados'] as $campo) {
            $a[$campo] = (int)$a[$campo];
        }
        $a['puntos_faltantes'] = PUNTOS_POR_BONO - ($a['puntos'] % PUNTOS_POR_BONO);
        $a['ultimo_bono_formateado'] = date('d/m/Y', strtotime($a['ultimo_bono']));
    }
    unset($a);

    jsonResponse(['success' => true, 'asociados' => $asociados]);
}

function listarCorreosAdmin($conn) {
    require_once __DIR__ . '/correo-pedidos.php';
    asegurarTablaCorreos($conn);
    
    $pedido = isset($_GET['pedido']) ? trim($_GET['pedido']) : '';
    $tipo = isset($_GET['tipo']) ? trim($_GET['tipo']) : '';
    
    $sql = "SELECT c.id, c.pedido_id, p.numero_pedido, c.tipo, c.destinatario, c.asunto,
                   c.enviado, c.error, c.fecha_envio
            FROM correos_tienda c
            LEFT JOIN pedidos p ON p.id = c.pedido_id
            WHERE 1 = 1";
    $tipos = '';
    $params = [];
    
    if ($pedido !== '') {
        $sql .= " AND p.numero_pedido LIKE ?";
        $tipos .= 's';
        $params[] = '%' . $pedido . '%';
    }
    if (in_array($tipo, ['cliente', 'tienda'], true)) {
        $sql .= " AND c.tipo = ?";
        $tipos .= 's';
        $params[] = $tipo;
    }
    $sql .= " ORDER BY c.id DESC LIMIT 200";
    
    $stmt = $conn->prepare($sql);
    if ($params) {
        $stmt->bind_param($tipos, ...$params);
    }
    $stmt->execute();
    $correos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    foreach ($correos as &$c) {
        $c['enviado'] = (bool)$c['enviado'];
        $c['fecha_formateada'] = date('d/m/Y H:i', strtotime($c['fecha_envio']));
    }
    unset($c);
    
    jsonResponse(['success' => true, 'correos' => $correos]);
}

/**
 * Endpoint: Detalle de un correo enviado (incluye el cuerpo HTML).
 */
function detalleCorreoAdmin($conn) {
    require_once __DIR__ . '/correo-pedidos.php';
    asegurarTablaCorreos($conn);
    
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    $stmt = $conn->prepare(
        "SELECT c.*, p.numero_pedido FROM correos_tienda c
         LEFT JOIN pedidos p ON p.id = c.pedido_id
         WHERE c.id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $correo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$correo) {
        jsonResponse(['success' => false, 'error' => 'Correo no encontrado'], 404);
    }
    
    $correo['enviado'] = (bool)$correo['enviado'];
    $correo['fecha_formateada'] = date('d/m/Y H:i', strtotime($correo['fecha_envio']));
    jsonResponse(['success' => true, 'correo' => $correo]);
}
