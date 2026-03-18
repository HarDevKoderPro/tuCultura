<?php
/**
 * Logout de Administrador
 * Tu Cultura es Progreso - Tienda Virtual
 * 
 * Destruye completamente la sesión del admin y redirige al login.
 * Esto evita que la sesión de admin persista al navegar a la tienda pública.
 */

session_start();

// Limpiar todas las variables de sesión
$_SESSION = [];

// Destruir la cookie de sesión si existe
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Destruir la sesión completamente
session_destroy();

// Redirigir al login
header("Location: ../../02-iniciarSesion/iniciarSesion.html");
exit;
