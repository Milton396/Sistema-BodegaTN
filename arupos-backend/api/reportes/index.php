<?php
/**
 * api/reportes/index.php
 *
 * GET /api/reportes/faltantes?bodega=&año=&mes=
 * GET /api/reportes/ciudad?ciudad=&año=
 * GET /api/reportes/periodo?desde=&hasta=&bodega=
 * GET /api/reportes/historico?año=
 */

Auth::require();

$perPage = min((int)($_GET['per_page'] ?? 200), 1000);
$page    = max((int)($_GET['page']     ??   1), 1);
$offset  = ($page - 1) * $perPage;

match ($subaction) {

    'faltantes' => (function () use ($page, $perPage, $offset) {
        $bodega = $_GET['bodega'] ?? null;
        $anio   = (int)($_GET['año'] ?? date('Y'));
        $mes    = (int)($_GET['mes'] ?? date('n'));

        $where  = ['h.año = ?', 'h.mes = ?'];
        $params = [$anio, $mes];
        if ($bodega) { $where[] = 'h.bodega_codigo = ?'; $params[] = $bodega; }

        $rows = Database::fetchAll("
            SELECT
              d.articulo_codigo, a.descripcion, a.tipo, a.unidad_medida,
              b.ciudad, b.descripcion AS bodega_nombre,
              d.cantidad_sistema, d.cantidad_contada, d.diferencia,
              CASE
                WHEN d.diferencia < 0 THEN 'FALTANTE'
                WHEN d.diferencia > 0 THEN 'SOBRANTE'
                ELSE 'CUADRADO'
              END AS resultado
            FROM inventarios_historial h
            INNER JOIN inventarios_toma   t  ON t.id      = h.id_toma
            INNER JOIN inventarios_detalle d  ON d.id_toma = t.id
            INNER JOIN articulos          a  ON a.codigo  = d.articulo_codigo
            INNER JOIN bodegas            b  ON b.codigo  = t.bodega_codigo
            WHERE " . implode(' AND ', $where) . "
              AND d.estado = 'CONTADO'
            ORDER BY ABS(d.diferencia) DESC
            LIMIT ? OFFSET ?",
            array_merge($params, [$perPage, $offset])
        );

        $total = Database::fetchOne(
            "SELECT COUNT(*) AS n
             FROM inventarios_historial h
             INNER JOIN inventarios_toma   t ON t.id       = h.id_toma
             INNER JOIN inventarios_detalle d ON d.id_toma  = t.id
             WHERE " . implode(' AND ', $where) . " AND d.estado = 'CONTADO'",
            $params
        )['n'];

        Response::paginated($rows, (int)$total, $page, $perPage);
    })(),

    'ciudad' => (function () {
        $ciudad = $_GET['ciudad'] ?? null;
        $anio   = (int)($_GET['año'] ?? date('Y'));

        $where  = ['h.año = ?'];
        $params = [$anio];
        if ($ciudad) { $where[] = 'h.ciudad = ?'; $params[] = $ciudad; }

        $rows = Database::fetchAll("
            SELECT h.ciudad, h.mes,
                   SUM(h.total_items)    AS items,
                   SUM(h.total_faltante) AS faltante,
                   SUM(h.total_sobrante) AS sobrante,
                   SUM(h.total_cuadrado) AS cuadrado,
                   SUM(h.valor_faltante) AS valor_faltante,
                   SUM(h.valor_sobrante) AS valor_sobrante
            FROM inventarios_historial h
            WHERE " . implode(' AND ', $where) . "
            GROUP BY h.ciudad, h.mes
            ORDER BY h.ciudad, h.mes",
            $params
        );
        Response::json($rows);
    })(),

    'periodo' => (function () use ($page, $perPage, $offset) {
        $desde  = $_GET['desde']  ?? date('Y-m-01');
        $hasta  = $_GET['hasta']  ?? date('Y-m-d');
        $bodega = $_GET['bodega'] ?? null;

        $where  = ['m.fecha_mov BETWEEN ? AND ?'];
        $params = [$desde . ' 00:00:00', $hasta . ' 23:59:59'];
        if ($bodega) {
            $where[]  = '(m.bodega_origen = ? OR m.bodega_destino = ?)';
            $params[] = $bodega;
            $params[] = $bodega;
        }
        $whereStr = implode(' AND ', $where);

        $total = Database::fetchOne(
            "SELECT COUNT(*) AS n FROM movimientos_stock m WHERE $whereStr", $params
        )['n'];

        $rows = Database::fetchAll("
            SELECT m.*, a.descripcion AS articulo_nombre,
                   bo.descripcion AS origen_nombre, bo.ciudad AS ciudad_origen,
                   bd.descripcion AS destino_nombre, bd.ciudad AS ciudad_destino,
                   u.nombres AS usuario_nombre
            FROM movimientos_stock m
            INNER JOIN articulos a  ON a.codigo  = m.articulo_codigo
            LEFT  JOIN bodegas   bo ON bo.codigo = m.bodega_origen
            LEFT  JOIN bodegas   bd ON bd.codigo = m.bodega_destino
            LEFT  JOIN usuarios  u  ON u.id      = m.usuario_id
            WHERE $whereStr
            ORDER BY m.fecha_mov DESC
            LIMIT ? OFFSET ?",
            array_merge($params, [$perPage, $offset])
        );
        Response::paginated($rows, (int)$total, $page, $perPage);
    })(),

    'historico' => (function () {
        $anio = (int)($_GET['año'] ?? date('Y'));
        $rows = Database::fetchAll("
            SELECT h.*, b.descripcion AS bodega_nombre,
                   t.tipo, t.categoria_abc, t.fecha_inicio, t.fecha_cierre
            FROM inventarios_historial h
            INNER JOIN inventarios_toma t ON t.id     = h.id_toma
            INNER JOIN bodegas          b ON b.codigo = h.bodega_codigo
            WHERE h.año = ?
            ORDER BY h.mes DESC, h.ciudad",
            [$anio]
        );
        Response::json($rows);
    })(),

    default => Response::notFound("Reporte '$subaction' no existe"),
};
