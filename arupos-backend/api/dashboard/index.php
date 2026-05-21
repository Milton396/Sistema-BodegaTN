<?php
/**
 * api/dashboard/index.php
 *
 * GET /api/dashboard/kpis
 * GET /api/dashboard/grafica-mensual?año=2025
 * GET /api/dashboard/alertas
 * GET /api/dashboard/tomas-activas
 * GET /api/dashboard/actividad
 */

Auth::require();

match ($subaction) {

    /* ── Tarjetas KPI principales ── */
    'kpis' => (function () {
        $bodega  = $_GET['bodega'] ?? null;
        $where   = $bodega ? 'WHERE s.bodega_codigo = ?' : '';
        $params  = $bodega ? [$bodega] : [];

        $stock = Database::fetchOne("
            SELECT
              COUNT(DISTINCT s.articulo_codigo)                          AS total_articulos,
              COALESCE(SUM(s.stock), 0)                                  AS total_stock,
              COALESCE(SUM(s.costo_total), 0)                            AS valor_total,
              SUM(CASE WHEN s.stock = 0           THEN 1 ELSE 0 END)     AS agotados,
              SUM(CASE WHEN s.dias_stock <= 7
                        AND s.stock > 0           THEN 1 ELSE 0 END)     AS criticos,
              SUM(CASE WHEN s.dias_stock BETWEEN 8
                        AND 30                    THEN 1 ELSE 0 END)     AS bajos
            FROM stock_nacional s
            $where",
            $params
        );

        // Últimas tomas del mes actual
        $tomas = Database::fetchOne("
            SELECT
              COUNT(*)                                                  AS tomas_mes,
              SUM(CASE WHEN estado='CERRADO'    THEN 1 ELSE 0 END)     AS cerradas,
              SUM(CASE WHEN estado='EN_PROCESO' THEN 1 ELSE 0 END)     AS en_proceso
            FROM inventarios_toma
            WHERE YEAR(fecha_inicio) = YEAR(NOW())
              AND MONTH(fecha_inicio) = MONTH(NOW())"
        );

        // Resumen del último historial cerrado
        $historial = Database::fetchOne("
            SELECT
              COALESCE(SUM(total_faltante), 0) AS faltante,
              COALESCE(SUM(total_sobrante), 0) AS sobrante,
              COALESCE(SUM(total_cuadrado), 0) AS cuadrado,
              COALESCE(SUM(valor_faltante), 0) AS valor_faltante
            FROM inventarios_historial
            WHERE año = YEAR(NOW()) AND mes = MONTH(NOW())"
        );

        // Distribuciones pendientes de aprobación
        $dist = Database::fetchOne("
            SELECT COUNT(*) AS dist_pendientes
            FROM solicitudes_distribucion
            WHERE estado = 'SOLICITADO'"
        );

        Response::json(array_merge(
            $stock    ?? [],
            $tomas    ?? [],
            $historial ?? [],
            $dist     ?? []
        ));
    })(),

    /* ── Datos para la gráfica de barras mensual ── */
    'grafica-mensual' => (function () {
        $anio   = (int)($_GET['año']    ?? date('Y'));
        $ciudad = $_GET['ciudad'] ?? null;

        $where  = ['h.año = ?'];
        $params = [$anio];
        if ($ciudad) { $where[] = 'h.ciudad = ?'; $params[] = $ciudad; }

        $rows = Database::fetchAll("
            SELECT
              h.mes,
              COALESCE(SUM(h.total_faltante), 0) AS faltante,
              COALESCE(SUM(h.total_sobrante), 0) AS sobrante,
              COALESCE(SUM(h.total_cuadrado), 0) AS cuadrado,
              COALESCE(SUM(h.total_items),    0) AS items,
              COALESCE(SUM(h.valor_faltante), 0) AS valor_faltante
            FROM inventarios_historial h
            WHERE " . implode(' AND ', $where) . "
            GROUP BY h.mes
            ORDER BY h.mes",
            $params
        );

        // Rellenar los 12 meses aunque no haya datos
        $meses  = [];
        $nombres = ['', 'Ene','Feb','Mar','Abr','May','Jun',
                        'Jul','Ago','Sep','Oct','Nov','Dic'];
        $indexed = array_column($rows, null, 'mes');

        for ($m = 1; $m <= 12; $m++) {
            $meses[] = [
                'mes'           => $m,
                'mes_nombre'    => $nombres[$m],
                'faltante'      => (int)($indexed[$m]['faltante']      ?? 0),
                'sobrante'      => (int)($indexed[$m]['sobrante']      ?? 0),
                'cuadrado'      => (int)($indexed[$m]['cuadrado']      ?? 0),
                'items'         => (int)($indexed[$m]['items']         ?? 0),
                'valor_faltante'=> (float)($indexed[$m]['valor_faltante'] ?? 0),
            ];
        }

        Response::json($meses);
    })(),

    /* ── Alertas de stock crítico ── */
    'alertas' => (function () {
        $limite = (int)($_GET['limite'] ?? 20);
        $bodega = $_GET['bodega'] ?? null;

        $where  = ['(s.stock = 0 OR s.dias_stock <= 30)'];
        $params = [];
        if ($bodega) { $where[] = 's.bodega_codigo = ?'; $params[] = $bodega; }

        $rows = Database::fetchAll("
            SELECT
              s.bodega_codigo, b.descripcion AS bodega_nombre, b.ciudad,
              a.codigo, a.descripcion, a.tipo, a.unidad_medida,
              s.stock, s.dias_stock, s.costo_total,
              CASE
                WHEN s.stock = 0        THEN 'AGOTADO'
                WHEN s.dias_stock <= 7  THEN 'CRITICO'
                ELSE 'BAJO'
              END AS nivel
            FROM stock_nacional s
            INNER JOIN articulos a ON a.codigo = s.articulo_codigo
            INNER JOIN bodegas   b ON b.codigo = s.bodega_codigo
            WHERE " . implode(' AND ', $where) . "
            ORDER BY s.stock ASC, s.dias_stock ASC
            LIMIT ?",
            array_merge($params, [$limite])
        );

        Response::json($rows);
    })(),

    /* ── Tomas activas en este momento ── */
    'tomas-activas' => (function () {
        $rows = Database::fetchAll("
            SELECT
              t.id, t.bodega_codigo, b.descripcion AS bodega_nombre, b.ciudad,
              t.estado, t.tipo, t.fecha_inicio,
              (SELECT COUNT(*) FROM inventarios_detalle d WHERE d.id_toma = t.id) AS total,
              (SELECT COUNT(*) FROM inventarios_detalle d
               WHERE d.id_toma = t.id AND d.estado = 'CONTADO') AS contados,
              u.nombres AS responsable
            FROM inventarios_toma t
            INNER JOIN bodegas  b ON b.codigo = t.bodega_codigo
            LEFT  JOIN usuarios u ON u.id     = t.creado_por
            WHERE t.estado IN ('ABIERTO','EN_PROCESO')
            ORDER BY t.fecha_inicio DESC"
        );
        Response::json($rows);
    })(),

    /* ── Últimas acciones del log ── */
    'actividad' => (function () {
        $rows = Database::fetchAll("
            SELECT l.accion, l.modulo, l.descripcion,
                   l.creado_en, u.nombres AS usuario
            FROM log_actividad l
            LEFT JOIN usuarios u ON u.id = l.usuario_id
            ORDER BY l.creado_en DESC
            LIMIT 30"
        );
        Response::json($rows);
    })(),

    default => Response::notFound("Acción '$subaction' no existe en /dashboard"),
};
