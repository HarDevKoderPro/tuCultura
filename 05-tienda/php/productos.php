<?php

/**
 * API de Productos para la Tienda (Frontend)
 * Maneja listado, detalle, búsqueda y destacados
 */

require_once 'config.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? 'listar';
$response = ['success' => false];

// Obtener umbral de bajo stock desde configuración
$umbralBajoStock = 10; // valor por defecto
try {
  $stmtUmbral = $pdo->prepare("SELECT valor FROM configuracion_tienda WHERE clave = 'umbral_bajo_stock'");
  $stmtUmbral->execute();
  $rowUmbral = $stmtUmbral->fetch();
  if ($rowUmbral) $umbralBajoStock = intval($rowUmbral['valor']);
} catch (Exception $e) {
  // tabla puede no existir, usar valor por defecto
}

try {
  switch ($action) {
    case 'listar':
      $categoria = isset($_GET['categoria']) ? (int)$_GET['categoria'] : 0;
      $pagina = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
      $orden = $_GET['orden'] ?? 'relevancia';
      $por_pagina = 12;
      $offset = ($pagina - 1) * $por_pagina;

      // Filtros de precio
      $precio_min = (isset($_GET['precio_min']) && $_GET['precio_min'] !== '') ? (float)$_GET['precio_min'] : null;
      $precio_max = (isset($_GET['precio_max']) && $_GET['precio_max'] !== '') ? (float)$_GET['precio_max'] : null;

      $where = ["p.activo = 1"];
      $params = [];

      if ($categoria > 0) {
        $where[] = "p.categoria_id = ?";
        $params[] = $categoria;
      }

      // LÓGICA DE PRECIO CORREGIDA: 
      // Filtramos por el precio final (considerando oferta si existe)
      if ($precio_min !== null) {
        $where[] = "CASE WHEN p.precio_oferta > 0 THEN p.precio_oferta ELSE p.precio END >= ?";
        $params[] = $precio_min;
      }
      if ($precio_max !== null) {
        $where[] = "CASE WHEN p.precio_oferta > 0 THEN p.precio_oferta ELSE p.precio END <= ?";
        $params[] = $precio_max;
      }

      $sql_where = implode(" AND ", $where);

      // Orden
      $sql_orden = "p.destacado DESC, p.id DESC";
      if ($orden === 'precio_asc') $sql_orden = "CASE WHEN p.precio_oferta > 0 THEN p.precio_oferta ELSE p.precio END ASC";
      if ($orden === 'precio_desc') $sql_orden = "CASE WHEN p.precio_oferta > 0 THEN p.precio_oferta ELSE p.precio END DESC";
      if ($orden === 'nombre_asc') $sql_orden = "p.nombre ASC";

      // Contar total para paginación
      $sql_count = "SELECT COUNT(*) as total FROM productos p WHERE $sql_where";
      $stmt_count = $pdo->prepare($sql_count);
      $stmt_count->execute($params);
      $total_productos = $stmt_count->fetch()['total'];
      $total_paginas = ceil($total_productos / $por_pagina);

      // Obtener productos
      $sql = "SELECT p.*, c.nombre as categoria_nombre 
                    FROM productos p 
                    LEFT JOIN categorias c ON p.categoria_id = c.id 
                    WHERE $sql_where 
                    ORDER BY $sql_orden 
                    LIMIT $por_pagina OFFSET $offset";

      $stmt = $pdo->prepare($sql);
      $stmt->execute($params);
      $productos = $stmt->fetchAll();

      $response = [
        'success' => true,
        'productos' => array_map('formatearProducto', $productos),
        'umbral_bajo_stock' => $umbralBajoStock,
        'paginacion' => [
          'total' => (int)$total_productos,
          'pagina' => (int)$pagina,
          'total_paginas' => (int)$total_paginas,
          'por_pagina' => $por_pagina
        ]
      ];
      break;

    case 'detalle':
      $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
      $stmt = $pdo->prepare("SELECT p.*, c.nombre as categoria_nombre 
                                  FROM productos p 
                                  LEFT JOIN categorias c ON p.categoria_id = c.id 
                                  WHERE p.id = ? AND p.activo = 1");
      $stmt->execute([$id]);
      $producto = $stmt->fetch();

      if ($producto) {
        // Incrementar vistas
        $pdo->prepare("UPDATE productos SET vistas = vistas + 1 WHERE id = ?")->execute([$id]);
        $response = [
          'success' => true,
          'producto' => formatearProducto($producto),
          'umbral_bajo_stock' => $umbralBajoStock
        ];
      } else {
        $response['error'] = 'Producto no encontrado';
      }
      break;

    case 'buscar':
      $q = isset($_GET['q']) ? trim($_GET['q']) : '';
      if (strlen($q) < 2) {
        $response['error'] = 'Término de búsqueda demasiado corto';
        break;
      }

      $stmt = $pdo->prepare("SELECT p.*, c.nombre as categoria_nombre 
                                  FROM productos p 
                                  LEFT JOIN categorias c ON p.categoria_id = c.id 
                                  WHERE p.activo = 1 AND (p.nombre LIKE ? OR p.descripcion LIKE ? OR p.sku LIKE ?)
                                  ORDER BY p.destacado DESC, p.id DESC LIMIT 20");
      $stmt->execute(["%$q%", "%$q%", "%$q%"]);
      $productos = $stmt->fetchAll();

      $response = [
        'success' => true,
        'productos' => array_map('formatearProducto', $productos),
        'umbral_bajo_stock' => $umbralBajoStock
      ];
      break;

    case 'destacados':
      $limite = isset($_GET['limite']) ? (int)$_GET['limite'] : 4;
      $stmt = $pdo->prepare("SELECT p.*, c.nombre as categoria_nombre 
                                  FROM productos p 
                                  LEFT JOIN categorias c ON p.categoria_id = c.id 
                                  WHERE p.activo = 1 AND p.destacado = 1 
                                  ORDER BY RAND() LIMIT ?");
      $stmt->execute([$limite]);
      $productos = $stmt->fetchAll();

      $response = [
        'success' => true,
        'productos' => array_map('formatearProducto', $productos),
        'umbral_bajo_stock' => $umbralBajoStock
      ];
      break;
  }
} catch (PDOException $e) {
  $response['error'] = 'Error de base de datos: ' . $e->getMessage();
}

echo json_encode($response);

/**
 * Formatea los datos de un producto para el frontend
 */
function formatearProducto($p)
{
  $precio = (float)$p['precio'];
  $oferta = (float)$p['precio_oferta'];
  $precio_final = ($oferta > 0 && $oferta < $precio) ? $oferta : $precio;

  $descuento = 0;
  if ($oferta > 0 && $oferta < $precio) {
    $descuento = round((($precio - $oferta) / $precio) * 100);
  }

  return [
    'id' => (int)$p['id'],
    'nombre' => $p['nombre'],
    'descripcion' => $p['descripcion'],
    'precio' => $precio,
    'precio_formateado' => '$ ' . number_format($precio, 0, ',', '.'),
    'precio_oferta' => $oferta,
    'precio_oferta_formateado' => '$ ' . number_format($oferta, 0, ',', '.'),
    'precio_final' => $precio_final,
    'descuento_porcentaje' => $descuento,
    'imagen' => $p['imagen'],
    'categoria_id' => (int)$p['categoria_id'],
    'categoria_nombre' => $p['categoria_nombre'],
    'stock' => (int)$p['stock'],
    'sku' => $p['sku'],
    'destacado' => (bool)$p['destacado']
  ];
}
