<?php
/**
 * API de Categorías
 * Tu Cultura es Progreso - Tienda Virtual
 */

require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$action = isset($_GET['action']) ? sanitizar($_GET['action']) : 'listar';

switch ($action) {
    case 'listar':
        listarCategorias($conn);
        break;
    case 'detalle':
        obtenerCategoria($conn);
        break;
    default:
        jsonResponse(['error' => 'Acción no válida'], 400);
}

/**
 * Listar todas las categorías activas
 */
function listarCategorias($conn) {
    $stmt = $conn->prepare("
        SELECT c.*, COUNT(p.id) as total_productos
        FROM categorias c
        LEFT JOIN productos p ON c.id = p.categoria_id AND p.activo = 1
        WHERE c.activo = 1
        GROUP BY c.id
        ORDER BY c.nombre ASC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    
    $categorias = [];
    while ($row = $result->fetch_assoc()) {
        $categorias[] = $row;
    }
    
    jsonResponse(['success' => true, 'categorias' => $categorias]);
}

/**
 * Obtener detalle de una categoría
 */
function obtenerCategoria($conn) {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    
    if (!$id) {
        jsonResponse(['error' => 'ID de categoría requerido'], 400);
    }
    
    $stmt = $conn->prepare("
        SELECT c.*, COUNT(p.id) as total_productos
        FROM categorias c
        LEFT JOIN productos p ON c.id = p.categoria_id AND p.activo = 1
        WHERE c.id = ? AND c.activo = 1
        GROUP BY c.id
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $categoria = $stmt->get_result()->fetch_assoc();
    
    if ($categoria) {
        jsonResponse(['success' => true, 'categoria' => $categoria]);
    } else {
        jsonResponse(['error' => 'Categoría no encontrada'], 404);
    }
}
