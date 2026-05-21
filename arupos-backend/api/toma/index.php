<?php
/**
 * api/toma/index.php
 *
 * GET    /api/toma                            (listar tomas)
 * POST   /api/toma/iniciar                    (crear nueva toma)
 * GET    /api/toma/{id}                       (cabecera + progreso)
 * GET    /api/toma/{id}/detalle               (líneas de detalle)
 * POST   /api/toma/{id}/registrar             (ingresar conteo)
 * POST   /api/toma/{id}/cerrar                (cerrar toma)
 * GET    /api/toma/{id}/resumen               (faltante/sobrante/cuadrado)
 */

$payload = Auth::require();

// El $id puede venir como tercer segmento
// /api/toma/5/detalle  → parts: ['api','toma','5','detalle']
global $parts;
$tomaId  = is_numeric($subaction) ? (int)$subaction : null;
$action  = $tomaId ? ($parts[3] ?? null) : $subaction;

/* ── Listar tomas ── */
if (!$tomaId && $method === 'GET' && !$subaction) {
    $bodega = $_GET['bodega'] ?? null;
    $estado = $_GET['estado'] ?? null;
    $where  = ['1=1'];
    $params = [];

    // Bodegueros solo ven su bodega
    if ($payload['rol'] === 'BODEGUERO') {
        $where[]  = 't.bodega_codigo = ?';
        $params[] = $payload['bodega'];
    } elseif ($bodega) {
        $where[]  = 't.bodega_codigo = ?';
        $params[] = $bodega;
    }
    if ($estado) { $where[] = 't.estado = ?'; $params[] = strtoupper($estado); }

    $rows = Database::fetchAll("
        SELECT t.*, b.descripcion AS bodega_nombre, b.ciudad,
               u.nombres AS creado_por_nombre,
               (SELECT COUNT(*) FROM inventarios_detalle d WHERE d.id_toma = t.id) AS total_items,
               (SELECT COUNT(*) FROM inventarios_detalle d WHERE d.id_toma = t.id AND d.estado = 'CONTADO') AS contados
        FROM inventarios_toma t
        INNER JOIN bodegas  b ON b.codigo = t.bodega_codigo
        LEFT JOIN  usuarios u ON u.id     = t.creado_por
        WHERE " . implode(' AND ', $where) . "
        ORDER BY t.creado_en DESC",
        $params
    );
    Response::json($rows);
}

/* ── Iniciar nueva toma ── */
if ($subaction === 'iniciar' && $method === 'POST') {
    Auth::requireRole(['ADMIN','SUPERVISOR','BODEGUERO']);
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    foreach (['bodega_codigo','tipo'] as $f) {
        if (empty($body[$f])) Response::error("Campo requerido: $f", 422);
    }

    // Verificar que no haya toma abierta para esa bodega
    $abierta = Database::fetchOne(
        "SELECT id FROM inventarios_toma
         WHERE bodega_codigo = ? AND estado IN ('ABIERTO','EN_PROCESO')",
        [$body['bodega_codigo']]
    );
    if ($abierta) {
        Response::error("Ya existe una toma activa (ID: {$abierta['id']}) para esa bodega", 409);
    }

    // Obtener ciudad de la bodega
    $bodega = Database::fetchOne(
        "SELECT ciudad FROM bodegas WHERE codigo = ?", [$body['bodega_codigo']]
    );
    if (!$bodega) Response::notFound("Bodega {$body['bodega_codigo']} no encontrada");

    Database::beginTransaction();
    try {
        // Crear cabecera
        Database::execute("
            INSERT INTO inventarios_toma
              (bodega_codigo, ciudad, fecha_inicio, estado, tipo, categoria_abc, creado_por, observacion)
            VALUES (?,?,CURDATE(),'ABIERTO',?,?,?,?)",
            [
                $body['bodega_codigo'],
                $bodega['ciudad'],
                $body['tipo'],
                $body['categoria_abc'] ?? null,
                $payload['sub'],
                $body['observacion']   ?? null,
            ]
        );
        $tomaId = (int)Database::lastInsertId();

        // Cargar detalle automático desde stock_nacional
        $whereStock = 'WHERE s.bodega_codigo = ?';
        $stockParams = [$body['bodega_codigo']];

        if ($body['tipo'] === 'CATEGORIA' && !empty($body['categoria_abc'])) {
            if ($body['categoria_abc'] === 'S') {
                $whereStock .= ' AND a.tiene_serie = 1';
            }
        }

        $stockItems = Database::fetchAll("
            SELECT s.articulo_codigo, s.stock
            FROM stock_nacional s
            INNER JOIN articulos a ON a.codigo = s.articulo_codigo
            $whereStock",
            $stockParams
        );

        foreach ($stockItems as $item) {
            Database::execute("
                INSERT INTO inventarios_detalle
                  (id_toma, articulo_codigo, cantidad_sistema, estado)
                VALUES (?,?,?,'PENDIENTE')",
                [$tomaId, $item['articulo_codigo'], $item['stock']]
            );
        }

        // Actualizar estado a EN_PROCESO
        Database::execute(
            "UPDATE inventarios_toma SET estado = 'EN_PROCESO' WHERE id = ?", [$tomaId]
        );

        Database::commit();
        Response::json(['id' => $tomaId, 'items_cargados' => count($stockItems)], 201);
    } catch (Throwable $e) {
        Database::rollback();
        throw $e;
    }
}

/* ── Acciones sobre una toma específica ── */
if ($tomaId) {
    $toma = Database::fetchOne(
        "SELECT t.*, b.descripcion AS bodega_nombre, b.ciudad
         FROM inventarios_toma t
         INNER JOIN bodegas b ON b.codigo = t.bodega_codigo
         WHERE t.id = ?",
        [$tomaId]
    );
    if (!$toma) Response::notFound("Toma $tomaId no encontrada");

    match ($action) {

        /* Cabecera con progreso */
        null, '' => (function () use ($toma, $tomaId) {
            $prog = Database::fetchOne("
                SELECT
                  COUNT(*)                                           AS total,
                  SUM(CASE WHEN estado='CONTADO'  THEN 1 ELSE 0 END) AS contados,
                  SUM(CASE WHEN estado='PENDIENTE'THEN 1 ELSE 0 END) AS pendientes
                FROM inventarios_detalle WHERE id_toma = ?",
                [$tomaId]
            );
            Response::json(array_merge($toma, ['progreso' => $prog]));
        })(),

        /* Detalle paginado */
        'detalle' => (function () use ($tomaId, $method) {
            global $page, $perPage, $offset;
            $page    = max((int)($_GET['page']     ?? 1), 1);
            $perPage = min((int)($_GET['per_page'] ?? 50), 500);
            $offset  = ($page - 1) * $perPage;
            $estado  = $_GET['estado'] ?? null;

            $where  = ['d.id_toma = ?'];
            $params = [$tomaId];
            if ($estado) { $where[] = 'd.estado = ?'; $params[] = strtoupper($estado); }
            $whereStr = implode(' AND ', $where);

            $total = Database::fetchOne(
                "SELECT COUNT(*) AS n FROM inventarios_detalle d WHERE $whereStr", $params
            )['n'];

            $rows = Database::fetchAll("
                SELECT d.id, d.articulo_codigo, a.descripcion,
                       a.tipo, a.unidad_medida, a.tiene_serie,
                       d.cantidad_sistema, d.cantidad_contada, d.diferencia,
                       d.serie, d.estado, d.observacion,
                       d.contado_en, u.nombres AS contado_por_nombre
                FROM inventarios_detalle d
                INNER JOIN articulos a ON a.codigo = d.articulo_codigo
                LEFT  JOIN usuarios  u ON u.id     = d.contado_por
                WHERE $whereStr
                ORDER BY d.estado, a.descripcion
                LIMIT ? OFFSET ?",
                array_merge($params, [$perPage, $offset])
            );
            Response::paginated($rows, (int)$total, $page, $perPage);
        })(),

        /* Registrar conteo de un artículo */
        'registrar' => (function () use ($toma, $tomaId, $payload) {
            if ($method !== 'POST') Response::error('Método no permitido', 405);
            if (!in_array($toma['estado'], ['ABIERTO','EN_PROCESO'], true)) {
                Response::error('La toma no está activa', 409);
            }

            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            if (!isset($body['articulo_codigo']) || !isset($body['cantidad_contada'])) {
                Response::error('articulo_codigo y cantidad_contada son requeridos', 422);
            }

            $detalle = Database::fetchOne(
                "SELECT id FROM inventarios_detalle
                 WHERE id_toma = ? AND articulo_codigo = ?",
                [$tomaId, $body['articulo_codigo']]
            );
            if (!$detalle) Response::notFound("Artículo no pertenece a esta toma");

            Database::execute("
                UPDATE inventarios_detalle
                SET cantidad_contada = ?,
                    serie            = ?,
                    estado           = 'CONTADO',
                    contado_por      = ?,
                    contado_en       = NOW(),
                    observacion      = ?
                WHERE id = ?",
                [
                    $body['cantidad_contada'],
                    $body['serie']       ?? null,
                    $payload['sub'],
                    $body['observacion'] ?? null,
                    $detalle['id'],
                ]
            );
            Response::json(['registrado' => true]);
        })(),

        /* Cerrar toma y generar historial */
        'cerrar' => (function () use ($toma, $tomaId, $payload) {
            if ($method !== 'POST') Response::error('Método no permitido', 405);
            Auth::requireRole(['ADMIN','SUPERVISOR']);

            if ($toma['estado'] === 'CERRADO') {
                Response::error('La toma ya está cerrada', 409);
            }

            Database::beginTransaction();
            try {
                // Calcular resumen
                $resumen = Database::fetchOne("
                    SELECT
                      COUNT(*)                                                       AS total_items,
                      SUM(CASE WHEN estado='CONTADO' THEN 1 ELSE 0 END)             AS total_contados,
                      SUM(CASE WHEN diferencia < 0   THEN 1 ELSE 0 END)             AS total_faltante,
                      SUM(CASE WHEN diferencia > 0   THEN 1 ELSE 0 END)             AS total_sobrante,
                      SUM(CASE WHEN diferencia = 0 AND estado='CONTADO' THEN 1 ELSE 0 END) AS total_cuadrado,
                      ABS(SUM(CASE WHEN diferencia < 0 THEN diferencia * 0 ELSE 0 END)) AS valor_faltante,
                      ABS(SUM(CASE WHEN diferencia > 0 THEN diferencia * 0 ELSE 0 END)) AS valor_sobrante
                    FROM inventarios_detalle
                    WHERE id_toma = ?",
                    [$tomaId]
                );

                // Cerrar toma
                Database::execute("
                    UPDATE inventarios_toma
                    SET estado = 'CERRADO', fecha_cierre = CURDATE(), cerrado_por = ?
                    WHERE id = ?",
                    [$payload['sub'], $tomaId]
                );

                // Insertar en historial
                Database::execute("
                    INSERT INTO inventarios_historial
                      (id_toma, bodega_codigo, ciudad, año, mes,
                       total_items, total_contados, total_faltante, total_sobrante, total_cuadrado,
                       valor_faltante, valor_sobrante)
                    VALUES (?,?,?,YEAR(CURDATE()),MONTH(CURDATE()),?,?,?,?,?,?,?)",
                    [
                        $tomaId,
                        $toma['bodega_codigo'],
                        $toma['ciudad'],
                        $resumen['total_items'],
                        $resumen['total_contados'],
                        $resumen['total_faltante'],
                        $resumen['total_sobrante'],
                        $resumen['total_cuadrado'],
                        $resumen['valor_faltante'],
                        $resumen['valor_sobrante'],
                    ]
                );

                Database::commit();
                Response::json(['cerrada' => true, 'resumen' => $resumen]);
            } catch (Throwable $e) {
                Database::rollback();
                throw $e;
            }
        })(),

        /* Resumen faltante/sobrante/cuadrado */
        'resumen' => (function () use ($tomaId) {
            $rows = Database::fetchAll("
                SELECT d.articulo_codigo, a.descripcion, a.tipo,
                       d.cantidad_sistema, d.cantidad_contada, d.diferencia,
                       d.estado,
                       CASE
                         WHEN d.diferencia < 0 THEN 'FALTANTE'
                         WHEN d.diferencia > 0 THEN 'SOBRANTE'
                         WHEN d.diferencia = 0 AND d.estado='CONTADO' THEN 'CUADRADO'
                         ELSE 'PENDIENTE'
                       END AS resultado
                FROM inventarios_detalle d
                INNER JOIN articulos a ON a.codigo = d.articulo_codigo
                WHERE d.id_toma = ? AND d.estado = 'CONTADO'
                ORDER BY ABS(d.diferencia) DESC",
                [$tomaId]
            );
            Response::json($rows);
        })(),

        default => Response::notFound("Acción '$action' no existe en /toma"),
    };
}

Response::notFound('Ruta de toma no encontrada');
