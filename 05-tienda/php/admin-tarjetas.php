<?php
/**
 * Admin Tarjetas Giratorias - Gestión de vinculación de productos
 * Almacena IDs de hasta 3 productos vinculados a las tarjetas giratorias de contenido.php
 * Archivo JSON: tarjetas-contenido.json
 */

require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');

// Verificar que sea administrador
if (!esAdmin()) {
    jsonResponse(['success' => false, 'error' => 'Acceso denegado. Solo administradores.'], 403);
}

// Ruta del archivo JSON
define('JSON_PATH', __DIR__ . '/tarjetas-contenido.json');
define('MAX_VINCULADOS', 3);

$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {
    case 'listar':
        listarVinculados($conn);
        break;
    case 'vincular':
        vincularProducto($conn);
        break;
    case 'desvincular':
        desvincularProducto($conn);
        break;
    default:
        jsonResponse(['success' => false, 'error' => 'Acción no válida'], 400);
}

/**
 * Lee el archivo JSON y devuelve el array de IDs vinculados
 */
function leerJSON() {
    if (!file_exists(JSON_PATH)) {
        return [];
    }
    $contenido = file_get_contents(JSON_PATH);
    $data = json_decode($contenido, true);
    if (!is_array($data) || !isset($data['productos_vinculados'])) {
        return [];
    }
    return $data['productos_vinculados'];
}

/**
 * Escribe el array de IDs vinculados al archivo JSON
 */
function escribirJSON($ids) {
    $data = [
        'productos_vinculados' => array_values($ids),
        'actualizado' => date('Y-m-d H:i:s')
    ];
    $resultado = file_put_contents(JSON_PATH, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return $resultado !== false;
}

/**
 * Lista los productos vinculados con sus datos completos
 */
function listarVinculados($conn) {
    $ids = leerJSON();
    $productosVinculados = [];

    if (!empty($ids)) {
        // Obtener datos de productos vinculados desde la BD
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        
        $stmt = $conn->prepare("
            SELECT p.id, p.nombre, p.imagen, p.precio, p.precio_oferta, p.stock, p.activo,
                   c.nombre as categoria_nombre
            FROM productos p
            LEFT JOIN categorias c ON p.categoria_id = c.id
            WHERE p.id IN ($placeholders)
        ");
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $result = $stmt->get_result();
        
        // Mantener el orden del JSON
        $productosMap = [];
        while ($row = $result->fetch_assoc()) {
            $row['precio_formateado'] = formatearPrecio($row['precio']);
            if ($row['precio_oferta']) {
                $row['precio_oferta_formateado'] = formatearPrecio($row['precio_oferta']);
            }
            $productosMap[$row['id']] = $row;
        }
        
        foreach ($ids as $id) {
            if (isset($productosMap[$id])) {
                $productosVinculados[] = $productosMap[$id];
            }
        }
    }

    jsonResponse([
        'success' => true,
        'vinculados' => $productosVinculados,
        'ids' => $ids,
        'total' => count($ids),
        'max' => MAX_VINCULADOS
    ]);
}

/**
 * Vincula un producto a las tarjetas giratorias
 */
function vincularProducto($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $idProducto = isset($input['id_producto']) ? intval($input['id_producto']) : 0;

    if (!$idProducto) {
        jsonResponse(['success' => false, 'error' => 'ID de producto requerido'], 400);
    }

    // Verificar que el producto existe y está activo
    $stmt = $conn->prepare("SELECT id, nombre, activo FROM productos WHERE id = ?");
    $stmt->bind_param("i", $idProducto);
    $stmt->execute();
    $producto = $stmt->get_result()->fetch_assoc();

    if (!$producto) {
        jsonResponse(['success' => false, 'error' => 'Producto no encontrado'], 404);
    }

    if (!$producto['activo']) {
        jsonResponse(['success' => false, 'error' => 'No se puede vincular un producto inactivo'], 400);
    }

    $ids = leerJSON();

    // Verificar que no esté ya vinculado
    if (in_array($idProducto, $ids)) {
        jsonResponse(['success' => false, 'error' => 'Este producto ya está vinculado a una tarjeta'], 400);
    }

    // Verificar límite de 3
    if (count($ids) >= MAX_VINCULADOS) {
        $max = MAX_VINCULADOS;
        jsonResponse([
            'success' => false, 
            'error' => "Ya hay {$max} productos vinculados. Desvincula uno primero.",
            'limite_alcanzado' => true
        ], 400);
    }

    $ids[] = $idProducto;

    if (escribirJSON($ids)) {
        jsonResponse([
            'success' => true,
            'mensaje' => "Producto \"{$producto['nombre']}\" vinculado a tarjeta giratoria.",
            'total' => count($ids),
            'max' => MAX_VINCULADOS
        ]);
    } else {
        jsonResponse(['success' => false, 'error' => 'Error al guardar la vinculación'], 500);
    }
}

/**
 * Desvincula un producto de las tarjetas giratorias
 */
function desvincularProducto($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $idProducto = isset($input['id_producto']) ? intval($input['id_producto']) : 0;

    if (!$idProducto) {
        jsonResponse(['success' => false, 'error' => 'ID de producto requerido'], 400);
    }

    $ids = leerJSON();

    if (!in_array($idProducto, $ids)) {
        jsonResponse(['success' => false, 'error' => 'Este producto no está vinculado'], 400);
    }

    $ids = array_values(array_filter($ids, function($id) use ($idProducto) {
        return $id !== $idProducto;
    }));

    if (escribirJSON($ids)) {
        jsonResponse([
            'success' => true,
            'mensaje' => 'Producto desvinculado correctamente.',
            'total' => count($ids),
            'max' => MAX_VINCULADOS
        ]);
    } else {
        jsonResponse(['success' => false, 'error' => 'Error al guardar los cambios'], 500);
    }
}
