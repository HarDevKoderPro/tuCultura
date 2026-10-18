<?php

/**
 * Codigos de invitacion de asociados
 * Tu Cultura es Progreso - Tienda Virtual
 *
 * Cada asociado (fila de `registros`) tiene un codigo corto y opaco que viaja en
 * los enlaces que comparte (compartir.php?id=N&ref=CODIGO). Asi el enlace no
 * expone su email y quien lo recibe puede registrarse con el ya como referente.
 */

// Sin letras/numeros que se confunden (0/O, 1/I/L)
const INVITACION_ALFABETO = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
const INVITACION_LONGITUD = 6;

function asegurarTablaInvitaciones(PDO $pdo): void
{
  static $verificada = false;
  if ($verificada) return;

  $pdo->exec("CREATE TABLE IF NOT EXISTS codigos_invitacion (
    usuario_id INT PRIMARY KEY,
    codigo VARCHAR(12) NOT NULL,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_codigos_invitacion_codigo (codigo)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $verificada = true;
}

/**
 * Normaliza un codigo recibido por URL; '' si no tiene el formato esperado.
 */
function normalizarCodigoInvitacion($codigo): string
{
  $codigo = strtoupper(trim((string)$codigo));
  return preg_match('/^[' . INVITACION_ALFABETO . ']{' . INVITACION_LONGITUD . '}$/', $codigo) ? $codigo : '';
}

/**
 * Codigo de invitacion del asociado con ese email (lo crea la primera vez).
 * @return string Codigo, o '' si el email no esta en `registros`.
 */
function obtenerCodigoInvitacion(PDO $pdo, string $email): string
{
  asegurarTablaInvitaciones($pdo);

  $stmt = $pdo->prepare("SELECT r.id, c.codigo
                         FROM registros r
                         LEFT JOIN codigos_invitacion c ON c.usuario_id = r.id
                         WHERE r.email = ? LIMIT 1");
  $stmt->execute([$email]);
  $fila = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$fila) return '';
  if (!empty($fila['codigo'])) return $fila['codigo'];

  // INSERT IGNORE: si otra peticion lo creo al mismo tiempo, se usa el suyo
  $insertar = $pdo->prepare("INSERT IGNORE INTO codigos_invitacion (usuario_id, codigo) VALUES (?, ?)");
  for ($intento = 0; $intento < 5; $intento++) {
    $codigo = '';
    for ($i = 0; $i < INVITACION_LONGITUD; $i++) {
      $codigo .= INVITACION_ALFABETO[random_int(0, strlen(INVITACION_ALFABETO) - 1)];
    }
    $insertar->execute([(int)$fila['id'], $codigo]);
    $stmt = $pdo->prepare("SELECT codigo FROM codigos_invitacion WHERE usuario_id = ?");
    $stmt->execute([(int)$fila['id']]);
    $guardado = $stmt->fetchColumn();
    if ($guardado) return $guardado;
  }
  return '';
}

/**
 * Asociado dueno de un codigo: ['email' => .., 'nombre_corto' => 'Maria G.'] o null.
 */
function resolverCodigoInvitacion(PDO $pdo, $codigo): ?array
{
  $codigo = normalizarCodigoInvitacion($codigo);
  if ($codigo === '') return null;
  asegurarTablaInvitaciones($pdo);

  $stmt = $pdo->prepare("SELECT r.email, r.nombres, r.apellidos
                         FROM codigos_invitacion c
                         JOIN registros r ON r.id = c.usuario_id
                         WHERE c.codigo = ? LIMIT 1");
  $stmt->execute([$codigo]);
  $fila = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$fila) return null;

  // Solo el primer nombre y la inicial del apellido (el email nunca se muestra)
  $nombre = trim(preg_split('/\s+/', trim((string)$fila['nombres']))[0] ?? '');
  $inicial = mb_substr(trim((string)$fila['apellidos']), 0, 1);
  $nombreCorto = mb_convert_case(mb_strtolower($nombre), MB_CASE_TITLE, 'UTF-8')
    . ($inicial !== '' ? ' ' . mb_strtoupper($inicial) . '.' : '');

  return ['email' => $fila['email'], 'nombre_corto' => $nombreCorto !== '' ? $nombreCorto : 'Un asociado'];
}
