<?php
/**
 * Logout de Cliente (Tienda)
 * Tu Cultura es Progreso - Tienda Virtual
 * 
 * Destruye la sesión del cliente y redirige a la tienda principal.
 * El carrito se mantiene en localStorage del navegador.
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

// Redirigir a la tienda principal
header("Location: ../index.html");
exit;
