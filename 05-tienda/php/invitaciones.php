<?php

/**
 * API de invitaciones de asociados
 * Tu Cultura es Progreso - Tienda Virtual
 *
 * - action=mi_codigo: codigo de invitacion del usuario con sesion (para los
 *   enlaces de "Compartir"). Sin sesion: success true y codigo ''.
 * - action=resolver&ref=CODIGO: nombre corto del asociado que invita
 *   ({valido, nombre}); nunca devuelve su email.
 */

require_once 'config.php';
require_once 'invitaciones-helpers.php';

$action = $_GET['action'] ?? '';

try {
  switch ($action) {
    case 'mi_codigo':
      $codigo = usuarioLogueado() ? obtenerCodigoInvitacion($pdo, $_SESSION['email']) : '';
      jsonResponse(['success' => true, 'codigo' => $codigo]);
      break;

    case 'resolver':
      $asociado = resolverCodigoInvitacion($pdo, $_GET['ref'] ?? '');
      jsonResponse([
        'success' => true,
        'valido' => $asociado !== null,
        'nombre' => $asociado ? $asociado['nombre_corto'] : ''
      ]);
      break;

    default:
      jsonResponse(['success' => false, 'error' => 'Acción no válida'], 400);
  }
} catch (PDOException $e) {
  error_log('invitaciones.php: ' . $e->getMessage());
  jsonResponse(['success' => false, 'error' => 'Error de base de datos'], 500);
}
