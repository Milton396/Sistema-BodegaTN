<?php
/**
 * api/usuarios/index.php
 * GET    /api/usuarios
 * POST   /api/usuarios
 * PATCH  /api/usuarios/{id}
 * DELETE /api/usuarios/{id}
 */

$payload = Auth::requireRole(['ADMIN','SUPERVISOR']);

$userId = is_numeric($subaction) ? (int)$subaction : null;

if ($method === 'GET' && !$userId) {
    $rows = Database::fetchAll("
        SELECT u.id, u.codigo_empleado, u.nombres, u.correo,
               u.bodega_codigo, b.descripcion AS bodega_nombre,
               r.nombre AS rol, u.activo, u.ultimo_acceso
        FROM usuarios u
        INNER JOIN roles  r ON r.id     = u.id_rol
        LEFT  JOIN bodegas b ON b.codigo = u.bodega_codigo
        ORDER BY u.nombres"
    );
    Response::json($rows);
}

if ($method === 'POST') {
    Auth::requireRole(['ADMIN']);
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    foreach (['nombres','correo','password','id_rol'] as $f) {
        if (empty($body[$f])) Response::error("Campo requerido: $f", 422);
    }
    if (!filter_var($body['correo'], FILTER_VALIDATE_EMAIL)) {
        Response::error('Correo inválido', 422);
    }

    $hash = password_hash($body['password'], PASSWORD_BCRYPT, ['cost' => 12]);
    try {
        Database::execute("
            INSERT INTO usuarios (codigo_empleado, nombres, correo, password_hash, bodega_codigo, id_rol)
            VALUES (?,?,?,?,?,?)",
            [
                $body['codigo_empleado'] ?? null,
                $body['nombres'], $body['correo'], $hash,
                $body['bodega_codigo'] ?? null,
                $body['id_rol'],
            ]
        );
        Response::json(['id' => Database::lastInsertId()], 201);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') Response::error('El correo ya está registrado', 409);
        throw $e;
    }
}

if ($userId && $method === 'PATCH') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $set  = [];
    $params = [];

    if (isset($body['nombres']))       { $set[] = 'nombres = ?';       $params[] = $body['nombres']; }
    if (isset($body['bodega_codigo'])) { $set[] = 'bodega_codigo = ?'; $params[] = $body['bodega_codigo']; }
    if (isset($body['id_rol']))        { $set[] = 'id_rol = ?';        $params[] = $body['id_rol']; }
    if (isset($body['activo']))        { $set[] = 'activo = ?';        $params[] = (int)$body['activo']; }
    if (!empty($body['password'])) {
        $set[]    = 'password_hash = ?';
        $params[] = password_hash($body['password'], PASSWORD_BCRYPT, ['cost' => 12]);
    }

    if (!$set) Response::error('No hay campos para actualizar', 422);
    $params[] = $userId;

    Database::execute("UPDATE usuarios SET " . implode(', ', $set) . " WHERE id = ?", $params);
    Response::json(['actualizado' => true]);
}

if ($userId && $method === 'DELETE') {
    Auth::requireRole(['ADMIN']);
    Database::execute("UPDATE usuarios SET activo = 0 WHERE id = ?", [$userId]);
    Response::json(['desactivado' => true]);
}

Response::notFound();
