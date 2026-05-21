<?php
/**
 * api/inventarios/index.php
 *
 * GET  /api/inventarios/total
 * GET  /api/inventarios/total?bodega=BPRG&tipo=MATERIALES&page=1
 * GET  /api/inventarios/categoria?cat=A&bodega=BPRG
 * GET  /api/inventarios/bodegas
 * GET  /api/inventarios/historial?bodega=BPRG&año=2025
 * GET  /api/inventarios/resumen-mes?año=2025
 * GET  /api/inventarios/kpis          (para el dashboard)
 */

$payload = Auth::require();

$perPage = min((int)($_GET['per_page'] ?? 50), 500);
$page    = max((int)($_GET['page']     ??  1), 1);
$offset  = ($page - 1) * $perPage;

match ($subaction) {

    /* ── KPIs globales (tarjetas del dashboard) ── */
    'kpis' => (function () {
        $bodega = $_GET['bodega'] ?? null;
        $where  = $bodega ? 'WHERE s.bodega_codigo = ?' : '';
        $params = $bodega ? [$bodega] : [];

        $kpi = Database::fetchOne("
            SELECT
              COUNT(DISTINCT s.articulo_codigo)            AS total_articulos,
              COALESCE(SUM(s.stock),0)                     AS total_stock,
              COALESCE(SUM(s.costo_total),0)               AS valor_total,
              COALESCE(SUM(CASE WHEN s.stock = 0 THEN 1 ELSE 0 END),0) AS agotados,
              COALESCE(SUM(CASE WHEN s.dias_stock <= 7  AND s.stock > 0 THEN 1 ELSE 0 END),0) AS criticos,
              COALESCE(SUM(CASE WHEN s.dias_stock <= 30 AND s.dias_stock > 7 THEN 1 ELSE 0 END),0) AS bajos
            FROM stock_nacional s $where", $params
        );

        // Último historial cerrado
        $hist = Database::fetchOne("
            SELECT
              COALESCE(SUM(total_faltante),0) AS faltante,
              COALESCE(SUM(total_sobrante),0) AS sobrante,
              COALESCE(SUM(total_cuadrado),0) AS cuadrado
            FROM inventarios_historial
            WHERE año = YEAR(NOW()) AND mes = MONTH(NOW())"
        );

        Response::json(array_merge($kpi ?? [], $hist ?? []));
    })(),

    /* ── Inventario total paginado ── */
    'total' => (function () use ($page, $perPage, $offset) {
        $bodega = $_GET['bodega'] ?? null;
        $tipo   = $_GET['tipo']   ?? null;
        $search = $_GET['q']      ?? null;

        $where  = ['1=1'];
        $params = [];

        if ($bodega) { $where[] = 's.bodega_codigo = ?'; $params[] = $bodega; }
        if ($tipo)   { $where[] = 'a.tipo = ?';          $params[] = $tipo; }
        if ($search) { $where[] = 'a.descripcion LIKE ?'; $params[] = "%$search%"; }

        $whereStr = implode(' AND ', $where);

        $total = Database::fetchOne(
            "SELECT COUNT(*) AS n FROM stock_nacional s
             INNER JOIN articulos a ON a.codigo = s.articulo_codigo
             WHERE $whereStr",
            $params
        )['n'];

        $rows = Database::fetchAll("
            SELECT
              s.bodega_codigo, b.descripcion AS bodega_nombre, b.ciudad,
              a.codigo, a.descripcion, a.tipo, a.unidad_medida,
              a.tiene_serie, a.tiene_mac,
              s.stock, s.dias_stock, s.costo_unitario, s.costo_total,
              s.ubicacion, s.modelo, s.marca,
              CASE
                WHEN s.stock = 0         THEN 'AGOTADO'
                WHEN s.dias_stock <= 7   THEN 'CRITICO'
                WHEN s.dias_stock <= 30  THEN 'BAJO'
                ELSE 'NORMAL'
              END AS nivel_alerta
            FROM stock_nacional s
            INNER JOIN articulos a ON a.codigo  = s.articulo_codigo
            INNER JOIN bodegas   b ON b.codigo  = s.bodega_codigo
            WHERE $whereStr
            ORDER BY a.descripcion
            LIMIT ? OFFSET ?",
            array_merge($params, [$perPage, $offset])
        );

        Response::paginated($rows, (int)$total, $page, $perPage);
    })(),

    /* ── Por categoría A/B/C/S ── */
    'categoria' => (function () use ($page, $perPage, $offset) {
        $cat    = strtoupper($_GET['cat']    ?? '');
        $bodega = $_GET['bodega'] ?? null;

        if (!in_array($cat, ['A','B','C','S'], true)) {
            Response::error('Categoría inválida. Use A, B, C o S', 422);
        }

        $where  = ['1=1'];
        $params = [];

        if ($bodega) { $where[] = 's.bodega_codigo = ?'; $params[] = $bodega; }

        // Categoría S = tiene_serie, A-B-C vienen del campo id de categorias_abc
        // Filtramos por prefijo del código del artículo o por tipo real del negocio
        if ($cat === 'S') {
            $where[] = 'a.tiene_serie = 1';
        } else {
            // A=alta rotación (dias_stock<=30), B=media, C=baja
            match ($cat) {
                'A' => $where[] = 's.dias_stock <= 30',
                'B' => $where[] = 's.dias_stock BETWEEN 31 AND 90',
                'C' => $where[] = 's.dias_stock > 90',
            };
        }

        $whereStr = implode(' AND ', $where);

        $total = Database::fetchOne(
            "SELECT COUNT(*) AS n FROM stock_nacional s
             INNER JOIN articulos a ON a.codigo = s.articulo_codigo
             WHERE $whereStr", $params
        )['n'];

        $rows = Database::fetchAll("
            SELECT a.codigo, a.descripcion, a.tipo, a.unidad_medida,
                   a.tiene_serie, s.bodega_codigo, b.ciudad,
                   s.stock, s.dias_stock, s.costo_unitario, s.costo_total
            FROM stock_nacional s
            INNER JOIN articulos a ON a.codigo = s.articulo_codigo
            INNER JOIN bodegas   b ON b.codigo = s.bodega_codigo
            WHERE $whereStr
            ORDER BY s.costo_total DESC
            LIMIT ? OFFSET ?",
            array_merge($params, [$perPage, $offset])
        );

        Response::paginated($rows, (int)$total, $page, $perPage);
    })(),

    /* ── Stock por bodega (resumen) ── */
    'bodegas' => (function () {
        $rows = Database::fetchAll("
            SELECT
              b.codigo, b.descripcion, b.ciudad, b.region,
              COUNT(DISTINCT s.articulo_codigo)       AS articulos,
              COALESCE(SUM(s.stock),0)                AS stock_total,
              COALESCE(SUM(s.costo_total),0)          AS valor_total,
              SUM(CASE WHEN s.stock=0 THEN 1 ELSE 0 END) AS agotados
            FROM bodegas b
            LEFT JOIN stock_nacional s ON s.bodega_codigo = b.codigo
            WHERE b.activa = 1
            GROUP BY b.codigo, b.descripcion, b.ciudad, b.region
            ORDER BY b.ciudad, b.descripcion"
        );
        Response::json($rows);
    })(),

    /* ── Historial de tomas cerradas ── */
    'historial' => (function () use ($page, $perPage, $offset) {
        $bodega = $_GET['bodega'] ?? null;
        $anio   = $_GET['año']    ?? null;

        $where  = ['1=1'];
        $params = [];

        if ($bodega) { $where[] = 'h.bodega_codigo = ?'; $params[] = $bodega; }
        if ($anio)   { $where[] = 'h.año = ?';           $params[] = (int)$anio; }

        $whereStr = implode(' AND ', $where);

        $total = Database::fetchOne(
            "SELECT COUNT(*) AS n FROM inventarios_historial h WHERE $whereStr",
            $params
        )['n'];

        $rows = Database::fetchAll("
            SELECT h.*, t.estado AS estado_toma,
                   t.tipo AS tipo_toma, t.categoria_abc
            FROM inventarios_historial h
            INNER JOIN inventarios_toma t ON t.id = h.id_toma
            WHERE $whereStr
            ORDER BY h.año DESC, h.mes DESC
            LIMIT ? OFFSET ?",
            array_merge($params, [$perPage, $offset])
        );

        Response::paginated($rows, (int)$total, $page, $perPage);
    })(),

    /* ── Resumen mensual para gráfica ── */
    'resumen-mes' => (function () {
        $anio   = (int)($_GET['año']    ?? date('Y'));
        $ciudad = $_GET['ciudad'] ?? null;

        $where  = ['h.año = ?'];
        $params = [$anio];

        if ($ciudad) { $where[] = 'h.ciudad = ?'; $params[] = $ciudad; }

        $rows = Database::fetchAll("
            SELECT h.mes, h.ciudad,
                   SUM(h.total_items)    AS items,
                   SUM(h.total_faltante) AS faltante,
                   SUM(h.total_sobrante) AS sobrante,
                   SUM(h.total_cuadrado) AS cuadrado,
                   SUM(h.valor_faltante) AS valor_faltante
            FROM inventarios_historial h
            WHERE " . implode(' AND ', $where) . "
            GROUP BY h.mes, h.ciudad
            ORDER BY h.mes",
            $params
        );

        Response::json($rows);
    })(),

    default => Response::notFound("Acción '$subaction' no existe en /inventarios"),
};
