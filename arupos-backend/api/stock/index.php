<?php
/**
 * api/stock/index.php
 *
 * GET    /api/stock/global?q=monitor&bodega=BPRG
 * GET    /api/stock/modelo?codigo=10-03-04-005
 * GET    /api/stock/reservas?bodega=BPRG
 * POST   /api/stock/reservas          (crear reserva)
 * DELETE /api/stock/reservas/{id}     (liberar reserva)
 * GET    /api/stock/series?articulo=10-03-04-005
 * POST   /api/stock/series            (registrar serie)
 */

$payload = Auth::require();
$perPage = min((int)($_GET['per_page'] ?? 50), 500);
$page    = max((int)($_GET['page']     ??  1), 1);
$offset  = ($page - 1) * $perPage;

match ($subaction) {

    /* ── Consulta global de existencias ── */
    'global' => (function () use ($page, $perPage, $offset) {
        $q      = $_GET['q']      ?? null;
        $bodega = $_GET['bodega'] ?? null;
        $tipo   = $_GET['tipo']   ?? null;

        $where  = ['1=1'];
        $params = [];

        if ($q)      { $where[] = '(a.codigo LIKE ? OR a.descripcion LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }
        if ($bodega) { $where[] = 's.bodega_codigo = ?';  $params[] = $bodega; }
        if ($tipo)   { $where[] = 'a.tipo = ?';           $params[] = $tipo; }

        $whereStr = implode(' AND ', $where);

        $total = Database::fetchOne(
            "SELECT COUNT(*) AS n FROM stock_nacional s
             INNER JOIN articulos a ON a.codigo = s.articulo_codigo
             WHERE $whereStr", $params
        )['n'];

        $rows = Database::fetchAll("
            SELECT
              a.codigo, a.descripcion, a.tipo, a.unidad_medida,
              a.tiene_serie, a.tiene_mac, a.costo_unitario AS costo_ref,
              s.bodega_codigo,  b.descripcion AS bodega_nombre, b.ciudad,
              s.stock, s.dias_stock, s.costo_total, s.ubicacion,
              s.modelo, s.marca,
              COALESCE((
                SELECT SUM(r.cantidad)
                FROM reservas_equipo r
                WHERE r.articulo_codigo = a.codigo
                  AND r.bodega_codigo   = s.bodega_codigo
                  AND r.estado = 'ACTIVA'
              ), 0) AS reservado,
              (s.stock - COALESCE((
                SELECT SUM(r.cantidad)
                FROM reservas_equipo r
                WHERE r.articulo_codigo = a.codigo
                  AND r.bodega_codigo   = s.bodega_codigo
                  AND r.estado = 'ACTIVA'
              ), 0)) AS disponible
            FROM stock_nacional s
            INNER JOIN articulos a ON a.codigo = s.articulo_codigo
            INNER JOIN bodegas   b ON b.codigo = s.bodega_codigo
            WHERE $whereStr
            ORDER BY a.descripcion
            LIMIT ? OFFSET ?",
            array_merge($params, [$perPage, $offset])
        );

        Response::paginated($rows, (int)$total, $page, $perPage);
    })(),

    /* ── Consulta por modelo/código ── */
    'modelo' => (function () {
        $codigo = $_GET['codigo'] ?? null;
        if (!$codigo) Response::error('Parámetro codigo es requerido', 422);

        $articulo = Database::fetchOne(
            "SELECT * FROM articulos WHERE codigo = ?", [$codigo]
        );
        if (!$articulo) Response::notFound("Artículo $codigo no encontrado");

        $stock = Database::fetchAll("
            SELECT s.bodega_codigo, b.descripcion AS bodega_nombre,
                   b.ciudad, b.region,
                   s.stock, s.dias_stock, s.costo_unitario, s.costo_total,
                   s.ubicacion, s.modelo, s.marca,
                   COALESCE((
                     SELECT SUM(r.cantidad) FROM reservas_equipo r
                     WHERE r.articulo_codigo = s.articulo_codigo
                       AND r.bodega_codigo   = s.bodega_codigo
                       AND r.estado = 'ACTIVA'
                   ), 0) AS reservado
            FROM stock_nacional s
            INNER JOIN bodegas b ON b.codigo = s.bodega_codigo
            WHERE s.articulo_codigo = ?
            ORDER BY b.ciudad",
            [$codigo]
        );

        $series = [];
        if ($articulo['tiene_serie']) {
            $series = Database::fetchAll(
                "SELECT serie, mac, estado, bodega_codigo, fecha_ingreso
                 FROM articulos_serie
                 WHERE articulo_codigo = ?
                 ORDER BY estado, fecha_ingreso DESC",
                [$codigo]
            );
        }

        Response::json([
            'articulo' => $articulo,
            'stock'    => $stock,
            'series'   => $series,
            'totales'  => [
                'stock_total'    => array_sum(array_column($stock, 'stock')),
                'valor_total'    => array_sum(array_column($stock, 'costo_total')),
                'num_bodegas'    => count($stock),
            ],
        ]);
    })(),

    /* ── Reservas ── */
    'reservas' => (function () use ($method, $id, $payload, $page, $perPage, $offset) {

        if ($method === 'GET') {
            $bodega   = $_GET['bodega']   ?? null;
            $articulo = $_GET['articulo'] ?? null;

            $where  = ["r.estado = 'ACTIVA'"];
            $params = [];

            if ($bodega)   { $where[] = 'r.bodega_codigo   = ?'; $params[] = $bodega; }
            if ($articulo) { $where[] = 'r.articulo_codigo = ?'; $params[] = $articulo; }

            $whereStr = implode(' AND ', $where);

            $total = Database::fetchOne(
                "SELECT COUNT(*) AS n FROM reservas_equipo r WHERE $whereStr", $params
            )['n'];

            $rows = Database::fetchAll("
                SELECT r.*, a.descripcion AS articulo_nombre,
                       b.descripcion AS bodega_nombre, b.ciudad,
                       u.nombres AS reservado_por_nombre
                FROM reservas_equipo r
                INNER JOIN articulos a ON a.codigo = r.articulo_codigo
                INNER JOIN bodegas   b ON b.codigo = r.bodega_codigo
                LEFT JOIN  usuarios  u ON u.id     = r.reservado_por
                WHERE $whereStr
                ORDER BY r.fecha_reserva DESC
                LIMIT ? OFFSET ?",
                array_merge($params, [$perPage, $offset])
            );
            Response::paginated($rows, (int)$total, $page, $perPage);
        }

        if ($method === 'POST') {
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $required = ['articulo_codigo','bodega_codigo','cantidad'];
            foreach ($required as $f) {
                if (empty($body[$f])) Response::error("Campo requerido: $f", 422);
            }

            // Verificar stock disponible
            $stock = Database::fetchOne(
                "SELECT stock FROM stock_nacional
                 WHERE articulo_codigo = ? AND bodega_codigo = ?",
                [$body['articulo_codigo'], $body['bodega_codigo']]
            );
            if (!$stock || $stock['stock'] < $body['cantidad']) {
                Response::error('Stock insuficiente para la reserva', 409);
            }

            Database::execute("
                INSERT INTO reservas_equipo
                  (articulo_codigo, bodega_codigo, cantidad, serie, estado,
                   reservado_por, fecha_expiracion, motivo)
                VALUES (?,?,?,?,  'ACTIVA', ?,?,?)",
                [
                    $body['articulo_codigo'],
                    $body['bodega_codigo'],
                    $body['cantidad'],
                    $body['serie']            ?? null,
                    $payload['sub'],
                    $body['fecha_expiracion'] ?? null,
                    $body['motivo']           ?? null,
                ]
            );
            Response::json(['id' => Database::lastInsertId()], 201);
        }

        if ($method === 'DELETE' && $id) {
            $affected = Database::execute(
                "UPDATE reservas_equipo SET estado = 'LIBERADA'
                 WHERE id = ? AND estado = 'ACTIVA'",
                [(int)$id]
            );
            if (!$affected) Response::notFound("Reserva $id no encontrada o ya liberada");
            Response::json(['liberada' => true]);
        }

        Response::notFound('Acción de reservas no válida');
    })(),

    /* ── Series de artículos ── */
    'series' => (function () use ($method, $page, $perPage, $offset) {
        if ($method === 'GET') {
            $articulo = $_GET['articulo'] ?? null;
            $bodega   = $_GET['bodega']   ?? null;
            $estado   = $_GET['estado']   ?? null;

            $where  = ['1=1'];
            $params = [];

            if ($articulo) { $where[] = 'ar.articulo_codigo = ?'; $params[] = $articulo; }
            if ($bodega)   { $where[] = 'ar.bodega_codigo   = ?'; $params[] = $bodega; }
            if ($estado)   { $where[] = 'ar.estado          = ?'; $params[] = strtoupper($estado); }

            $whereStr = implode(' AND ', $where);

            $total = Database::fetchOne(
                "SELECT COUNT(*) AS n FROM articulos_serie ar WHERE $whereStr", $params
            )['n'];

            $rows = Database::fetchAll("
                SELECT ar.*, a.descripcion AS articulo_nombre,
                       b.descripcion AS bodega_nombre, b.ciudad
                FROM articulos_serie ar
                INNER JOIN articulos a ON a.codigo = ar.articulo_codigo
                INNER JOIN bodegas   b ON b.codigo = ar.bodega_codigo
                WHERE $whereStr
                ORDER BY ar.estado, ar.fecha_ingreso DESC
                LIMIT ? OFFSET ?",
                array_merge($params, [$perPage, $offset])
            );
            Response::paginated($rows, (int)$total, $page, $perPage);
        }

        if ($method === 'POST') {
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            foreach (['articulo_codigo','bodega_codigo','serie'] as $f) {
                if (empty($body[$f])) Response::error("Campo requerido: $f", 422);
            }

            try {
                Database::execute("
                    INSERT INTO articulos_serie
                      (articulo_codigo, bodega_codigo, serie, mac, estado, fecha_ingreso, observacion)
                    VALUES (?,?,?,?,'DISPONIBLE',?,?)",
                    [
                        $body['articulo_codigo'],
                        $body['bodega_codigo'],
                        $body['serie'],
                        $body['mac']           ?? null,
                        $body['fecha_ingreso'] ?? date('Y-m-d'),
                        $body['observacion']   ?? null,
                    ]
                );
                Response::json(['id' => Database::lastInsertId()], 201);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') Response::error("La serie {$body['serie']} ya existe", 409);
                throw $e;
            }
        }

        Response::notFound();
    })(),

    default => Response::notFound("Acción '$subaction' no existe en /stock"),
};
