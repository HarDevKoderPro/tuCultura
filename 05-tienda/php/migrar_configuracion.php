<?php
/**
 * Script de migración: Crear tabla configuracion_tienda
 * Ejecutar una sola vez desde el navegador o CLI para crear la tabla.
 * URL: /05-tienda/php/migrar_configuracion.php
 */

require_once 'config.php';

header('Content-Type: text/html; charset=utf-8');

echo "<h2>Migración: configuracion_tienda</h2>";

// Crear tabla
$sql = "CREATE TABLE IF NOT EXISTS configuracion_tienda (
    id INT PRIMARY KEY AUTO_INCREMENT,
    clave VARCHAR(50) UNIQUE NOT NULL,
    valor VARCHAR(255) NOT NULL,
    descripcion TEXT,
    fecha_modificacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sql)) {
    echo "<p style='color:green;'>✅ Tabla <strong>configuracion_tienda</strong> creada/verificada correctamente.</p>";
} else {
    echo "<p style='color:red;'>❌ Error al crear tabla: " . $conn->error . "</p>";
    exit;
}

// Insertar valor por defecto
$sqlInsert = "INSERT INTO configuracion_tienda (clave, valor, descripcion)
VALUES ('umbral_bajo_stock', '10', 'Cantidad mínima de stock para considerar un producto como bajo stock')
ON DUPLICATE KEY UPDATE clave = clave";

if ($conn->query($sqlInsert)) {
    echo "<p style='color:green;'>✅ Valor por defecto <strong>umbral_bajo_stock = 10</strong> insertado/verificado.</p>";
} else {
    echo "<p style='color:red;'>❌ Error al insertar valor: " . $conn->error . "</p>";
}

// Verificar
$result = $conn->query("SELECT * FROM configuracion_tienda");
echo "<h3>Contenido de la tabla:</h3><table border='1' cellpadding='8'><tr><th>ID</th><th>Clave</th><th>Valor</th><th>Descripción</th><th>Fecha</th></tr>";
while ($row = $result->fetch_assoc()) {
    echo "<tr><td>{$row['id']}</td><td>{$row['clave']}</td><td>{$row['valor']}</td><td>{$row['descripcion']}</td><td>{$row['fecha_modificacion']}</td></tr>";
}
echo "</table>";
echo "<br><p><strong>Migración completada.</strong> Puedes eliminar este archivo.</p>";
