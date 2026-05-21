<?php
/**
 * api/auth.php
 * POST /api/auth/login
 * POST /api/auth/refresh
 * GET  /api/auth/me
 */

match (true) {

    /* ── LOGIN ── */
    $method === 'POST' && $subaction === 'login' => (function () {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $correo = trim($body['correo'] ?? '');
        $pass   = trim($body['password'] ?? '');

        if (!$correo || !$pass) {
            Response::error('Correo y contraseña son requeridos', 422, 'VALIDATION');
        }

        $user = Database::fetchOne(
            "SELECT u.*, r.nombre AS rol_nombre
               FROM usuarios u
               INNER JOIN roles r ON r.id = u.id_rol
              WHERE u.correo = ? AND u.activo = 1",
            [$correo]
        );

        if (!$user || !password_verify($pass, $user['password_hash'])) {
            Response::unauthorized('Credenciales incorrectas');
        }

        // Actualizar último acceso
        Database::execute(
            "UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?",
            [$user['id']]
        );

        // Log de actividad
        Database::execute(
            "INSERT INTO log_actividad (usuario_id, accion, modulo, ip)
             VALUES (?, 'LOGIN', 'AUTH', ?)",
            [$user['id'], $_SERVER['REMOTE_ADDR'] ?? '']
        );

        $token = Auth::generateToken([
            'sub'    => $user['id'],
            'correo' => $user['correo'],
            'nombres'=> $user['nombres'],
            'rol'    => $user['rol_nombre'],
            'bodega' => $user['bodega_codigo'],
        ]);

        Response::json([
            'token' => $token,
            'user'  => [
                'id'      => $user['id'],
                'nombres' => $user['nombres'],
                'correo'  => $user['correo'],
                'rol'     => $user['rol_nombre'],
                'bodega'  => $user['bodega_codigo'],
            ],
        ]);
    })(),

    /* ── ME (token info) ── */
    $method === 'GET' && $subaction === 'me' => (function () {
        $payload = Auth::require();
        Response::json($payload);
    })(),

    default => Response::notFound('Acción de auth no válida'),
};
