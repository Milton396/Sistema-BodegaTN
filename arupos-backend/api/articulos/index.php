<?php
/**
 * api/articulos/index.php
 *
 * GET    /api/articulos?q=monitor&tipo=EQUIPOS&page=1
 * GET    /api/articulos/{codigo}
 * POST   /api/articulos
 * PATCH  /api/articulos/{codigo}
 */

$payload = Auth::require();

$perPage  = min((int)($_GET['per_page'] ?? 50), 500);
$page     = max((int)($_GET['page']     ??  1), 1);
$offset   = ($page - 1) * $perPage;
$artCodigo = is_numeric($subaction) ? null : ($subaction ?: null);

/* ── Listar / Buscar ── */
if ($method === 'GET' && !$artCodigo) {
    $q     = $_GET['q']    ?? null;
    $tipo  = $_GET['tipo'] ?? null;
    $serie = isset($_GET['serie']) ? (int)$_GET['serie'] : null;

    $where  = ['a.activo = 1'];
    $params = [];

    if ($q) {
        $where[]  = '(a.codigo LIKE ? OR a.descripcion LIKE ?)';
        $params[] = "%$q%";
        $params[] = "%$q%";
    }
    if ($tipo)  { $where[] = 'a.tipo = ?';        $params[] = $tipo; }
    if ($serie !== null) { $where[] = 'a.tiene_serie = ?'; $params[] = $serie; }

    $whereStr = implode(' AND ', $where);

    $total = Database::fetchOne(
        "SELECT COUNT(*) AS n FROM articulos a WHERE $whereStr", $params
    )['n'];

    $rows = Database::fetchAll("
        SELECT a.*,
               COALESCE(SUM(s.stock),      0) AS stock_total,
               COALESCE(SUM(s.costo_total),0) AS valor_total,
               COUNT(s.id)                    AS num_bodegas
        FROM articulos a
        LEFT JOIN stock_nacional s ON s.articulo_codigo = a.codigo
        WHERE $whereStr
        GROUP BY a.codigo
        ORDER BY a.descripcion
        LIMIT ? OFFSET ?",
        array_merge($params, [$perPage, $offset])
    );

    Response::paginated($rows, (int)$total, $page, $perPage);
}

/* ── Obtener artículo por código ── */
if ($method === 'GET' && $artCodigo) {
    $art = Database::fetchOne(
        "SELECT * FROM articulos WHERE codigo = ?", [$artCodigo]
    );
    if (!$art) Response::notFound("Artículo $artCodigo no encontrado");

    // Stock por bodega
    $stock = Database::fetchAll("
        SELECT s.bodega_codigo, b.descripcion AS bodega_nombre, b.ciudad,
               s.stock, s.dias_stock, s.costo_unitario, s.costo_total,
               s.ubicacion, s.modelo, s.marca
        FROM stock_nacional s
        INNER JOIN bodegas b ON b.codigo = s.bodega_codigo
        WHERE s.articulo_codigo = ?
        ORDER BY b.ciudad",
        [$artCodigo]
    );

    // Series si aplica
    $series = $art['tiene_serie'] ? Database::fetchAll(
        "SELECT serie, mac, estado, bodega_codigo, fecha_ingreso
         FROM articulos_serie
         WHERE articulo_codigo = ?
         ORDER BY estado, fecha_ingreso DESC",
        [$artCodigo]
    ) : [];

    // Últimos movimientos
    $movimientos = Database::fetchAll("
        SELECT m.tipo, m.cantidad, m.bodega_origen, m.bodega_destino,
               m.referencia, m.fecha_mov, u.nombres AS usuario
        FROM movimientos_stock m
        LEFT JOIN usuarios u ON u.id = m.usuario_id
        WHERE m.articulo_codigo = ?
        ORDER BY m.fecha_mov DESC
        LIMIT 10",
        [$artCodigo]
    );

    Response::json([
        'articulo'    => $art,
        'stock'       => $stock,
        'series'      => $series,
        'movimientos' => $movimientos,
    ]);
}

/* ── Crear artículo ── */
if ($method === 'POST') {
    Auth::requireRole(['ADMIN','SUPERVISOR']);
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    foreach (['codigo','descripcion','tipo','unidad_medida'] as $f) {
        if (empty($body[$f])) Response::error("Campo requerido: $f", 422);
    }

    try {
        Database::execute("
            INSERT INTO articulos
              (codigo, descripcion, tipo, unidad_medida, costo_unitario,
               recurrente, tiene_serie, tiene_mac, id_categoria, categoria_desc)
            VALUES (?,?,?,?,?,?,?,?,?,?)",
            [
                strtoupper(trim($body['codigo'])),
                $body['descripcion'],
                $body['tipo'],
                $body['unidad_medida'],
                $body['costo_unitario']  ?? 0,
                $body['recurrente']      ?? 0,
                $body['tiene_serie']     ?? 0,
                $body['tiene_mac']       ?? 0,
                $body['id_categoria']    ?? null,
                $body['categoria_desc']  ?? null,
            ]
        );
        Response::json(['codigo' => strtoupper($body['codigo'])], 201);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') Response::error("El código {$body['codigo']} ya existe", 409);
        throw $e;
    }
}

/* ── Actualizar artículo ── */
if ($method === 'PATCH' && $artCodigo) {
    Auth::requireRole(['ADMIN','SUPERVISOR']);
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $campos = ['descripcion','tipo','unidad_medida','costo_unitario',
               'recurrente','tiene_serie','tiene_mac','activo'];

    $set    = [];
    $params = [];
    foreach ($campos as $c) {
        if (array_key_exists($c, $body)) {
            $set[]    = "$c = ?";
            $params[] = $body[$c];
        }
    }
    if (!$set) Response::error('No hay campos para actualizar', 422);

    $params[] = $artCodigo;
    Database::execute(
        "UPDATE articulos SET " . implode(', ', $set) . " WHERE codigo = ?", $params
    );
    Response::json(['actualizado' => true]);
}

Response::notFound();
