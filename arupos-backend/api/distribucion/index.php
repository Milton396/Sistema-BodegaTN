<?php
/**
 * api/distribucion/index.php
 *
 * GET    /api/distribucion              (listar solicitudes)
 * POST   /api/distribucion/solicitar   (nueva solicitud)
 * PATCH  /api/distribucion/{id}/estado  (aprobar/rechazar/recibir)
 * GET    /api/distribucion/historial
 */

$payload = Auth::require();
$perPage = min((int)($_GET['per_page'] ?? 50), 500);
$page    = max((int)($_GET['page']     ??  1), 1);
$offset  = ($page - 1) * $perPage;

global $parts;
$solId   = is_numeric($subaction) ? (int)$subaction : null;
$action  = $solId ? ($parts[3] ?? null) : $subaction;

/* ── Listar ── */
if (!$solId && $method === 'GET' && (!$subaction || $subaction === 'historial')) {
    $estado = $_GET['estado'] ?? null;
    $where  = ['1=1'];
    $params = [];

    if ($subaction !== 'historial') {
        $where[] = "sd.estado NOT IN ('RECHAZADO','RECIBIDO')";
    }
    if ($estado) { $where[] = 'sd.estado = ?'; $params[] = strtoupper($estado); }

    if ($payload['rol'] === 'BODEGUERO') {
        $where[]  = '(sd.bodega_origen = ? OR sd.bodega_destino = ?)';
        $params[] = $payload['bodega'];
        $params[] = $payload['bodega'];
    }

    $whereStr = implode(' AND ', $where);

    $total = Database::fetchOne(
        "SELECT COUNT(*) AS n FROM solicitudes_distribucion sd WHERE $whereStr", $params
    )['n'];

    $rows = Database::fetchAll("
        SELECT sd.*,
               a.descripcion AS articulo_nombre,
               bo.descripcion AS bodega_origen_nombre,   bo.ciudad AS ciudad_origen,
               bd.descripcion AS bodega_destino_nombre,  bd.ciudad AS ciudad_destino,
               us.nombres AS solicitado_por_nombre,
               ua.nombres AS aprobado_por_nombre
        FROM solicitudes_distribucion sd
        INNER JOIN articulos a  ON a.codigo  = sd.articulo_codigo
        INNER JOIN bodegas   bo ON bo.codigo = sd.bodega_origen
        INNER JOIN bodegas   bd ON bd.codigo = sd.bodega_destino
        LEFT  JOIN usuarios  us ON us.id     = sd.solicitado_por
        LEFT  JOIN usuarios  ua ON ua.id     = sd.aprobado_por
        WHERE $whereStr
        ORDER BY sd.fecha_solicitud DESC
        LIMIT ? OFFSET ?",
        array_merge($params, [$perPage, $offset])
    );
    Response::paginated($rows, (int)$total, $page, $perPage);
}

/* ── Crear solicitud ── */
if ($subaction === 'solicitar' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    foreach (['articulo_codigo','bodega_origen','bodega_destino','cantidad'] as $f) {
        if (empty($body[$f])) Response::error("Campo requerido: $f", 422);
    }
    if ($body['bodega_origen'] === $body['bodega_destino']) {
        Response::error('Origen y destino no pueden ser la misma bodega', 422);
    }

    // Verificar stock en origen
    $stock = Database::fetchOne(
        "SELECT stock FROM stock_nacional WHERE articulo_codigo = ? AND bodega_codigo = ?",
        [$body['articulo_codigo'], $body['bodega_origen']]
    );
    if (!$stock || $stock['stock'] < $body['cantidad']) {
        Response::error('Stock insuficiente en bodega de origen', 409);
    }

    Database::execute("
        INSERT INTO solicitudes_distribucion
          (articulo_codigo, bodega_origen, bodega_destino, cantidad, estado, solicitado_por, observacion)
        VALUES (?,?,?,?,'SOLICITADO',?,?)",
        [
            $body['articulo_codigo'], $body['bodega_origen'],
            $body['bodega_destino'],  $body['cantidad'],
            $payload['sub'],          $body['observacion'] ?? null,
        ]
    );
    Response::json(['id' => Database::lastInsertId()], 201);
}

/* ── Cambiar estado ── */
if ($solId && $action === 'estado' && $method === 'PATCH') {
    Auth::requireRole(['ADMIN','SUPERVISOR']);
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $estado = strtoupper($body['estado'] ?? '');

    $allowed = ['APROBADO','RECHAZADO','EN_TRANSITO','RECIBIDO'];
    if (!in_array($estado, $allowed, true)) {
        Response::error("Estado inválido. Valores: " . implode(', ', $allowed), 422);
    }

    Database::beginTransaction();
    try {
        $set    = ['estado = ?'];
        $params = [$estado];

        if ($estado === 'APROBADO') {
            $set[]    = 'aprobado_por = ?';
            $set[]    = 'fecha_aprobacion = NOW()';
            $params[] = $payload['sub'];
        }
        if ($estado === 'RECIBIDO') {
            $set[]    = 'fecha_recepcion = NOW()';
            // Mover stock físico
            $sol = Database::fetchOne(
                "SELECT * FROM solicitudes_distribucion WHERE id = ?", [$solId]
            );
            if ($sol) {
                // Restar en origen
                Database::execute(
                    "UPDATE stock_nacional SET stock = stock - ?
                     WHERE bodega_codigo = ? AND articulo_codigo = ?",
                    [$sol['cantidad'], $sol['bodega_origen'], $sol['articulo_codigo']]
                );
                // Sumar en destino (UPSERT)
                Database::execute("
                    INSERT INTO stock_nacional (bodega_codigo, articulo_codigo, stock, costo_unitario, costo_total)
                    SELECT ?, ?, ?, costo_unitario, costo_unitario * ?
                    FROM stock_nacional WHERE bodega_codigo = ? AND articulo_codigo = ?
                    ON DUPLICATE KEY UPDATE stock = stock + VALUES(stock),
                                            costo_total = costo_total + VALUES(costo_total)",
                    [
                        $sol['bodega_destino'], $sol['articulo_codigo'],
                        $sol['cantidad'],        $sol['cantidad'],
                        $sol['bodega_origen'],   $sol['articulo_codigo'],
                    ]
                );
                // Registrar movimiento
                Database::execute("
                    INSERT INTO movimientos_stock
                      (tipo, articulo_codigo, bodega_origen, bodega_destino, cantidad, referencia, usuario_id)
                    VALUES ('TRANSFERENCIA',?,?,?,?,?,?)",
                    [
                        $sol['articulo_codigo'], $sol['bodega_origen'],
                        $sol['bodega_destino'],  $sol['cantidad'],
                        "DIST-$solId",           $payload['sub'],
                    ]
                );
            }
        }

        $setStr = implode(', ', $set);
        Database::execute(
            "UPDATE solicitudes_distribucion SET $setStr WHERE id = ?",
            array_merge($params, [$solId])
        );
        Database::commit();
        Response::json(['actualizado' => true]);
    } catch (Throwable $e) {
        Database::rollback();
        throw $e;
    }
}

Response::notFound('Ruta de distribución no encontrada');
