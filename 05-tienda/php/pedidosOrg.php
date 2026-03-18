<?php
/**
 * API de Pedidos y Checkout
 * Tu Cultura es Progreso - Tienda Virtual
 */

require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

$action = isset($_GET['action']) ? sanitizar($_GET['action']) : '';

switch ($action) {
    case 'crear':
        crearPedido($conn);
        break;
    case 'historial':
        historialPedidos($conn);
        break;
    case 'detalle':
        detallePedido($conn);
        break;
    case 'verificar_checkout':
        verificarCheckout($conn);
        break;
    default:
        jsonResponse(['error' => 'Acción no válida'], 400);
}

/**
 * Verificar si se puede proceder al checkout
 */
function verificarCheckout($conn) {
    if (!usuarioLogueado()) {
        jsonResponse(['success' => false, 'requiere_login' => true, 'mensaje' => 'Debe iniciar sesión para continuar']);
    }
    
    $usuario_id = obtenerUsuarioId($conn, $_SESSION['email']);
    
    // Verificar que hay items en el carrito
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM carrito WHERE usuario_id = ?");
    $stmt->bind_param("i", $usuario_id);
    $stmt->execute();
    $total = $stmt->get_result()->fetch_assoc()['total'];
    
    if ($total == 0) {
        jsonResponse(['success' => false, 'carrito_vacio' => true, 'mensaje' => 'El carrito está vacío']);
    }
    
    // Obtener datos del usuario
    $usuario = obtenerDatosUsuario($conn, $_SESSION['email']);
    
    jsonResponse([
        'success' => true,
        'usuario' => [
            'nombres' => $usuario['nombres'] ?? '',
            'apellidos' => $usuario['apellidos'] ?? '',
            'email' => $usuario['email'] ?? '',
            'telefono' => $usuario['telefono'] ?? ''
        ]
    ]);
}

/**
 * Crear un nuevo pedido
 */
function crearPedido($conn) {
    // Verificar autenticación
    if (!usuarioLogueado()) {
        jsonResponse(['error' => 'Debe iniciar sesión para realizar un pedido', 'requiere_login' => true], 401);
    }
    
    $usuario_id = obtenerUsuarioId($conn, $_SESSION['email']);
    
    // Validar datos de envío
    $campos_requeridos = ['nombre', 'apellido', 'telefono', 'email', 'direccion', 'ciudad', 'departamento'];
    foreach ($campos_requeridos as $campo) {
        if (empty($_POST[$campo])) {
            jsonResponse(['error' => "El campo '$campo' es requerido"], 400);
        }
    }
    
    // Sanitizar datos
    $nombre = sanitizar($_POST['nombre']);
    $apellido = sanitizar($_POST['apellido']);
    $telefono = sanitizar($_POST['telefono']);
    $email = sanitizar($_POST['email']);
    $direccion = sanitizar($_POST['direccion']);
    $ciudad = sanitizar($_POST['ciudad']);
    $departamento = sanitizar($_POST['departamento']);
    $codigo_postal = sanitizar($_POST['codigo_postal'] ?? '');
    $notas = sanitizar($_POST['notas'] ?? '');
    $metodo_pago = sanitizar($_POST['metodo_pago'] ?? 'contraentrega');
    
    // Validar email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['error' => 'Email no válido'], 400);
    }
    
    // Obtener items del carrito
    $stmt = $conn->prepare("
        SELECT c.id, c.cantidad, c.producto_id, p.nombre, p.precio, p.precio_oferta, p.stock
        FROM carrito c
        JOIN productos p ON c.producto_id = p.id
        WHERE c.usuario_id = ? AND p.activo = 1
    ");
    $stmt->bind_param("i", $usuario_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $items = [];
    $subtotal = 0;
    
    while ($row = $result->fetch_assoc()) {
        // Verificar stock
        if ($row['cantidad'] > $row['stock']) {
            jsonResponse([
                'error' => "Stock insuficiente para '{$row['nombre']}'. Disponible: {$row['stock']}",
                'producto_sin_stock' => $row['nombre']
            ], 400);
        }
        
        $precio = $row['precio_oferta'] ?? $row['precio'];
        $row['precio_final'] = $precio;
        $row['subtotal_item'] = $precio * $row['cantidad'];
        $subtotal += $row['subtotal_item'];
        $items[] = $row;
    }
    
    if (empty($items)) {
        jsonResponse(['error' => 'El carrito está vacío'], 400);
    }
    
    // Calcular totales
    $costo_envio = $subtotal >= TIENDA_ENVIO_GRATIS_MINIMO ? 0 : TIENDA_COSTO_ENVIO_BASE;
    $impuestos = $subtotal * TIENDA_IVA;
    $total = $subtotal + $costo_envio + $impuestos;
    
    // Generar número de pedido
    $numero_pedido = generarNumeroPedido();
    
    // Iniciar transacción
    $conn->begin_transaction();
    
    try {
        // Crear pedido
        $stmtPedido = $conn->prepare("
            INSERT INTO pedidos (
                numero_pedido, usuario_id, subtotal, costo_envio, impuestos, total,
                metodo_pago, nombre_envio, apellido_envio, telefono_envio, email_envio,
                direccion_envio, ciudad_envio, departamento_envio, codigo_postal, notas
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtPedido->bind_param(
            "siddddsssssssss",
            $numero_pedido, $usuario_id, $subtotal, $costo_envio, $impuestos, $total,
            $metodo_pago, $nombre, $apellido, $telefono, $email,
            $direccion, $ciudad, $departamento, $codigo_postal, $notas
        );
        $stmtPedido->execute();
        $pedido_id = $conn->insert_id;
        
        // Crear detalles del pedido y actualizar stock
        $stmtDetalle = $conn->prepare("
            INSERT INTO detalle_pedido (pedido_id, producto_id, nombre_producto, cantidad, precio_unitario, subtotal)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmtStock = $conn->prepare("UPDATE productos SET stock = stock - ? WHERE id = ?");
        
        foreach ($items as $item) {
            $subtotal_item = $item['precio_final'] * $item['cantidad'];
            $stmtDetalle->bind_param(
                "iisidd",
                $pedido_id, $item['producto_id'], $item['nombre'],
                $item['cantidad'], $item['precio_final'], $subtotal_item
            );
            $stmtDetalle->execute();
            
            // Actualizar stock
            $stmtStock->bind_param("ii", $item['cantidad'], $item['producto_id']);
            $stmtStock->execute();
        }
        
        // Vaciar carrito
        $stmtVaciar = $conn->prepare("DELETE FROM carrito WHERE usuario_id = ?");
        $stmtVaciar->bind_param("i", $usuario_id);
        $stmtVaciar->execute();
        
        // Confirmar transacción
        $conn->commit();
        
        jsonResponse([
            'success' => true,
            'mensaje' => '¡Pedido creado exitosamente!',
            'pedido' => [
                'numero' => $numero_pedido,
                'id' => $pedido_id,
                'total' => $total,
                'total_formateado' => formatearPrecio($total)
            ]
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        jsonResponse(['error' => 'Error al procesar el pedido. Intente nuevamente.'], 500);
    }
}

/**
 * Obtener historial de pedidos del usuario
 */
function historialPedidos($conn) {
    if (!usuarioLogueado()) {
        jsonResponse(['error' => 'Debe iniciar sesión'], 401);
    }
    
    $usuario_id = obtenerUsuarioId($conn, $_SESSION['email']);
    
    $stmt = $conn->prepare("
        SELECT id, numero_pedido, total, estado, fecha_creacion,
               (SELECT COUNT(*) FROM detalle_pedido WHERE pedido_id = pedidos.id) as total_items
        FROM pedidos
        WHERE usuario_id = ?
        ORDER BY fecha_creacion DESC
    ");
    $stmt->bind_param("i", $usuario_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $pedidos = [];
    while ($row = $result->fetch_assoc()) {
        $row['total_formateado'] = formatearPrecio($row['total']);
        $row['fecha_formateada'] = date('d/m/Y H:i', strtotime($row['fecha_creacion']));
        $row['estado_texto'] = ucfirst($row['estado']);
        $pedidos[] = $row;
    }
    
    jsonResponse(['success' => true, 'pedidos' => $pedidos]);
}

/**
 * Obtener detalle de un pedido
 */
function detallePedido($conn) {
    if (!usuarioLogueado()) {
        jsonResponse(['error' => 'Debe iniciar sesión'], 401);
    }
    
    $pedido_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    
    if (!$pedido_id) {
        jsonResponse(['error' => 'ID de pedido requerido'], 400);
    }
    
    $usuario_id = obtenerUsuarioId($conn, $_SESSION['email']);
    
    // Obtener pedido
    $stmt = $conn->prepare("SELECT * FROM pedidos WHERE id = ? AND usuario_id = ?");
    $stmt->bind_param("ii", $pedido_id, $usuario_id);
    $stmt->execute();
    $pedido = $stmt->get_result()->fetch_assoc();
    
    if (!$pedido) {
        jsonResponse(['error' => 'Pedido no encontrado'], 404);
    }
    
    // Obtener detalles
    $stmtDetalles = $conn->prepare("
        SELECT dp.*, p.imagen
        FROM detalle_pedido dp
        LEFT JOIN productos p ON dp.producto_id = p.id
        WHERE dp.pedido_id = ?
    ");
    $stmtDetalles->bind_param("i", $pedido_id);
    $stmtDetalles->execute();
    $resultDetalles = $stmtDetalles->get_result();
    
    $detalles = [];
    while ($row = $resultDetalles->fetch_assoc()) {
        $row['precio_unitario_formateado'] = formatearPrecio($row['precio_unitario']);
        $row['subtotal_formateado'] = formatearPrecio($row['subtotal']);
        $detalles[] = $row;
    }
    
    // Formatear datos del pedido
    $pedido['subtotal_formateado'] = formatearPrecio($pedido['subtotal']);
    $pedido['costo_envio_formateado'] = formatearPrecio($pedido['costo_envio']);
    $pedido['impuestos_formateado'] = formatearPrecio($pedido['impuestos']);
    $pedido['total_formateado'] = formatearPrecio($pedido['total']);
    $pedido['fecha_formateada'] = date('d/m/Y H:i', strtotime($pedido['fecha_creacion']));
    $pedido['estado_texto'] = ucfirst($pedido['estado']);
    $pedido['detalles'] = $detalles;
    
    jsonResponse(['success' => true, 'pedido' => $pedido]);
}
