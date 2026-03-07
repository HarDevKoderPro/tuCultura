<?php
/**
 * API de Administración
 * Tu Cultura es Progreso - Tienda Virtual
 */

require_once 'config.php';

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
    
    default:
        jsonResponse(['error' => 'Acción no válida'], 400);
}

// ==================== DASHBOARD ====================

function obtenerDashboard($conn) {
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
    
    // Productos con bajo stock (menos de 10)
    $productosBajoStock = $conn->query("SELECT COUNT(*) as total FROM productos WHERE activo = 1 AND stock < 10")->fetch_assoc()['total'];
    
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
            'ultimos_pedidos' => $ultimosPedidos,
            'productos_populares' => $productosPopulares
        ]
    ]);
}

// ==================== PRODUCTOS ====================

function listarProductosAdmin($conn) {
    $result = $conn->query("
        SELECT p.*, c.nombre as categoria_nombre
        FROM productos p
        LEFT JOIN categorias c ON p.categoria_id = c.id
        ORDER BY p.fecha_creacion DESC
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
    $imagen = sanitizar($_POST['imagen'] ?? '');
    
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
    $imagen = sanitizar($_POST['imagen'] ?? '');
    
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
    $pedido['fecha_formateada'] = date('d/m/Y H:i', strtotime($pedido['fecha_creacion']));
    $pedido['estado_texto'] = ucfirst($pedido['estado']);
    $pedido['detalles'] = $detalles;
    
    jsonResponse(['success' => true, 'pedido' => $pedido]);
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
        jsonResponse(['success' => true, 'mensaje' => 'Estado actualizado exitosamente']);
    } else {
        jsonResponse(['error' => 'Error al actualizar el estado'], 500);
    }
}
