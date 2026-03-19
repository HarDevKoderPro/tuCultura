<?php
/**
 * Verificar estado de sesión (API JSON)
 * Tu Cultura es Progreso - Tienda Virtual
 * 
 * Devuelve info de sesión para que el JS pueda mostrar/ocultar
 * el botón de logout dinámicamente.
 */

require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');

if (usuarioLogueado()) {
    $nombre = isset($_SESSION['nombres']) ? $_SESSION['nombres'] : '';
    $esAdminUser = esAdmin();
    echo json_encode([
        'logueado' => true,
        'nombre'   => $nombre,
        'email'    => $_SESSION['email'],
        'esAdmin'  => $esAdminUser
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode([
        'logueado' => false
    ]);
}
exit;
