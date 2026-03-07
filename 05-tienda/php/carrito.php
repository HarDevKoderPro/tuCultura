<?php
/**
 * API del Carrito de Compras
 * Tu Cultura es Progreso - Tienda Virtual
 */

require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE');

$action = isset($_GET['action']) ? sanitizar($_GET['action']) : '';

switch ($action) {
    case 'ver':
        verCarrito($conn);
        break;
    case 'agregar':
        agregarAlCarrito($conn);
        break;
    case 'actualizar':
        actualizarCantidad($conn);
        break;
    case 'eliminar':
        eliminarDelCarrito($conn);
        break;
    case 'vaciar':
        vaciarCarrito($conn);
        break;
    case 'contar':
        contarItems($conn);
        break;
    default:
        jsonResponse(['error' => 'Acción no válida'], 400);
}

/**
 * Obtener identificador del carrito (usuario_id o session_id)
 */
function getCarritoIdentifier() {
    if (usuarioLogueado()) {
        global $conn;
        return ['tipo' => 'usuario', 'id' => obtenerUsuarioId($conn, $_SESSION['email'])];
    }
    return ['tipo' => 'session', 'id' => getSessionId()];
}

/**
 * Ver contenido del carrito
 */
function verCarrito($conn) {
    $identifier = getCarritoIdentifier();
    
    if ($identifier['tipo'] === 'usuario') {
        $sql = "SELECT c.id, c.cantidad, p.id as producto_id, p.nombre, p.precio, 
                       p.precio_oferta, p.imagen, p.stock, cat.nombre as categoria
                FROM carrito c
                JOIN productos p ON c.producto_id = p.id
                LEFT JOIN categorias cat ON p.categoria_id = cat.id
                WHERE c.usuario_id = ? AND p.activo = 1
                ORDER BY c.fecha_agregado DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $identifier['id']);
    } else {
        $sql = "SELECT c.id, c.cantidad, p.id as producto_id, p.nombre, p.precio, 
                       p.precio_oferta, p.imagen, p.stock, cat.nombre as categoria
                FROM carrito c
                JOIN productos p ON c.producto_id = p.id
                LEFT JOIN categorias cat ON p.categoria_id = cat.id
                WHERE c.session_id = ? AND p.activo = 1
                ORDER BY c.fecha_agregado DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $identifier['id']);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $items = [];
    $subtotal = 0;
    
    while ($row = $result->fetch_assoc()) {
        $precio = $row['precio_oferta'] ?? $row['precio'];
        $row['precio_final'] = $precio;
        $row['precio_formateado'] = formatearPrecio($row['precio']);
        $row['precio_final_formateado'] = formatearPrecio($precio);
        if ($row['precio_oferta']) {
            $row['precio_oferta_formateado'] = formatearPrecio($row['precio_oferta']);
        }
        $row['subtotal_item'] = $precio * $row['cantidad'];
        $row['subtotal_item_formateado'] = formatearPrecio($row['subtotal_item']);
        
        // Verificar disponibilidad de stock
        $row['disponible'] = $row['cantidad'] <= $row['stock'];
        
        $subtotal += $row['subtotal_item'];
        $items[] = $row;
    }
    
    // Calcular totales
    $costo_envio = $subtotal >= TIENDA_ENVIO_GRATIS_MINIMO ? 0 : TIENDA_COSTO_ENVIO_BASE;
    $impuestos = $subtotal * TIENDA_IVA;
    $total = $subtotal + $costo_envio + $impuestos;
    
    jsonResponse([
        'success' => true,
        'carrito' => [
            'items' => $items,
            'cantidad_items' => count($items),
            'subtotal' => $subtotal,
            'subtotal_formateado' => formatearPrecio($subtotal),
            'costo_envio' => $costo_envio,
            'costo_envio_formateado' => formatearPrecio($costo_envio),
            'envio_gratis_minimo' => TIENDA_ENVIO_GRATIS_MINIMO,
            'envio_gratis_minimo_formateado' => formatearPrecio(TIENDA_ENVIO_GRATIS_MINIMO),
            'falta_envio_gratis' => max(0, TIENDA_ENVIO_GRATIS_MINIMO - $subtotal),
            'falta_envio_gratis_formateado' => formatearPrecio(max(0, TIENDA_ENVIO_GRATIS_MINIMO - $subtotal)),
            'impuestos' => $impuestos,
            'impuestos_formateado' => formatearPrecio($impuestos),
            'total' => $total,
            'total_formateado' => formatearPrecio($total)
        ]
    ]);
}

/**
 * Agregar producto al carrito
 */
function agregarAlCarrito($conn) {
    $producto_id = isset($_POST['producto_id']) ? intval($_POST['producto_id']) : 0;
    $cantidad = isset($_POST['cantidad']) ? max(1, intval($_POST['cantidad'])) : 1;
    
    if (!$producto_id) {
        jsonResponse(['error' => 'Producto no especificado'], 400);
    }
    
    // Verificar que el producto existe y está activo
    $stmtProd = $conn->prepare("SELECT id, nombre, stock FROM productos WHERE id = ? AND activo = 1");
    $stmtProd->bind_param("i", $producto_id);
    $stmtProd->execute();
    $producto = $stmtProd->get_result()->fetch_assoc();
    
    if (!$producto) {
        jsonResponse(['error' => 'Producto no encontrado'], 404);
    }
    
    // Verificar stock
    if ($producto['stock'] < $cantidad) {
        jsonResponse(['error' => 'Stock insuficiente', 'stock_disponible' => $producto['stock']], 400);
    }
    
    $identifier = getCarritoIdentifier();
    
    // Verificar si el producto ya está en el carrito
    if ($identifier['tipo'] === 'usuario') {
        $stmtCheck = $conn->prepare("SELECT id, cantidad FROM carrito WHERE usuario_id = ? AND producto_id = ?");
        $stmtCheck->bind_param("ii", $identifier['id'], $producto_id);
    } else {
        $stmtCheck = $conn->prepare("SELECT id, cantidad FROM carrito WHERE session_id = ? AND producto_id = ?");
        $stmtCheck->bind_param("si", $identifier['id'], $producto_id);
    }
    $stmtCheck->execute();
    $existente = $stmtCheck->get_result()->fetch_assoc();
    
    if ($existente) {
        // Actualizar cantidad
        $nuevaCantidad = $existente['cantidad'] + $cantidad;
        if ($nuevaCantidad > $producto['stock']) {
            $nuevaCantidad = $producto['stock'];
        }
        $stmtUpdate = $conn->prepare("UPDATE carrito SET cantidad = ? WHERE id = ?");
        $stmtUpdate->bind_param("ii", $nuevaCantidad, $existente['id']);
        $stmtUpdate->execute();
        $mensaje = 'Cantidad actualizada en el carrito';
    } else {
        // Insertar nuevo item
        if ($identifier['tipo'] === 'usuario') {
            $stmtInsert = $conn->prepare("INSERT INTO carrito (usuario_id, producto_id, cantidad) VALUES (?, ?, ?)");
            $stmtInsert->bind_param("iii", $identifier['id'], $producto_id, $cantidad);
        } else {
            $stmtInsert = $conn->prepare("INSERT INTO carrito (session_id, producto_id, cantidad) VALUES (?, ?, ?)");
            $stmtInsert->bind_param("sii", $identifier['id'], $producto_id, $cantidad);
        }
        $stmtInsert->execute();
        $mensaje = 'Producto agregado al carrito';
    }
    
    // Obtener cantidad total de items
    $totalItems = contarItemsCarrito($conn, $identifier);
    
    jsonResponse([
        'success' => true,
        'mensaje' => $mensaje,
        'producto_nombre' => $producto['nombre'],
        'total_items' => $totalItems
    ]);
}

/**
 * Actualizar cantidad de un item
 */
function actualizarCantidad($conn) {
    $item_id = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;
    $cantidad = isset($_POST['cantidad']) ? max(1, intval($_POST['cantidad'])) : 1;
    
    if (!$item_id) {
        jsonResponse(['error' => 'Item no especificado'], 400);
    }
    
    $identifier = getCarritoIdentifier();
    
    // Verificar que el item pertenece al usuario
    if ($identifier['tipo'] === 'usuario') {
        $stmtCheck = $conn->prepare("SELECT c.id, p.stock FROM carrito c JOIN productos p ON c.producto_id = p.id WHERE c.id = ? AND c.usuario_id = ?");
        $stmtCheck->bind_param("ii", $item_id, $identifier['id']);
    } else {
        $stmtCheck = $conn->prepare("SELECT c.id, p.stock FROM carrito c JOIN productos p ON c.producto_id = p.id WHERE c.id = ? AND c.session_id = ?");
        $stmtCheck->bind_param("is", $item_id, $identifier['id']);
    }
    $stmtCheck->execute();
    $item = $stmtCheck->get_result()->fetch_assoc();
    
    if (!$item) {
        jsonResponse(['error' => 'Item no encontrado'], 404);
    }
    
    // Verificar stock
    if ($cantidad > $item['stock']) {
        $cantidad = $item['stock'];
    }
    
    $stmtUpdate = $conn->prepare("UPDATE carrito SET cantidad = ? WHERE id = ?");
    $stmtUpdate->bind_param("ii", $cantidad, $item_id);
    $stmtUpdate->execute();
    
    jsonResponse(['success' => true, 'mensaje' => 'Cantidad actualizada', 'nueva_cantidad' => $cantidad]);
}

/**
 * Eliminar item del carrito
 */
function eliminarDelCarrito($conn) {
    $item_id = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;
    
    if (!$item_id) {
        jsonResponse(['error' => 'Item no especificado'], 400);
    }
    
    $identifier = getCarritoIdentifier();
    
    if ($identifier['tipo'] === 'usuario') {
        $stmt = $conn->prepare("DELETE FROM carrito WHERE id = ? AND usuario_id = ?");
        $stmt->bind_param("ii", $item_id, $identifier['id']);
    } else {
        $stmt = $conn->prepare("DELETE FROM carrito WHERE id = ? AND session_id = ?");
        $stmt->bind_param("is", $item_id, $identifier['id']);
    }
    $stmt->execute();
    
    if ($stmt->affected_rows > 0) {
        $totalItems = contarItemsCarrito($conn, $identifier);
        jsonResponse(['success' => true, 'mensaje' => 'Producto eliminado del carrito', 'total_items' => $totalItems]);
    } else {
        jsonResponse(['error' => 'No se pudo eliminar el item'], 400);
    }
}

/**
 * Vaciar carrito
 */
function vaciarCarrito($conn) {
    $identifier = getCarritoIdentifier();
    
    if ($identifier['tipo'] === 'usuario') {
        $stmt = $conn->prepare("DELETE FROM carrito WHERE usuario_id = ?");
        $stmt->bind_param("i", $identifier['id']);
    } else {
        $stmt = $conn->prepare("DELETE FROM carrito WHERE session_id = ?");
        $stmt->bind_param("s", $identifier['id']);
    }
    $stmt->execute();
    
    jsonResponse(['success' => true, 'mensaje' => 'Carrito vaciado']);
}

/**
 * Contar items en el carrito
 */
function contarItems($conn) {
    $identifier = getCarritoIdentifier();
    $total = contarItemsCarrito($conn, $identifier);
    jsonResponse(['success' => true, 'total_items' => $total]);
}

/**
 * Helper para contar items
 */
function contarItemsCarrito($conn, $identifier) {
    if ($identifier['tipo'] === 'usuario') {
        $stmt = $conn->prepare("SELECT COALESCE(SUM(cantidad), 0) as total FROM carrito WHERE usuario_id = ?");
        $stmt->bind_param("i", $identifier['id']);
    } else {
        $stmt = $conn->prepare("SELECT COALESCE(SUM(cantidad), 0) as total FROM carrito WHERE session_id = ?");
        $stmt->bind_param("s", $identifier['id']);
    }
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['total'];
}
