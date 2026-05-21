<?php
/**
 * api/bodegas/index.php
 * GET  /api/bodegas
 * GET  /api/bodegas/{codigo}
 */

Auth::require();

if ($method === 'GET' && !$subaction) {
    $rows = Database::fetchAll(
        "SELECT * FROM bodegas WHERE activa = 1 ORDER BY ciudad, descripcion"
    );
    Response::json($rows);
}

if ($method === 'GET' && $subaction) {
    $bodega = Database::fetchOne(
        "SELECT * FROM bodegas WHERE codigo = ?", [strtoupper($subaction)]
    );
    if (!$bodega) Response::notFound("Bodega $subaction no encontrada");
    Response::json($bodega);
}

Response::notFound();
