<?php
/**
 * index.php — Front controller · Bootstrap del backend ARUPOS
 *
 * Todas las peticiones pasan por aquí vía .htaccess
 */

declare(strict_types=1);

/* ── Zona horaria ── */
date_default_timezone_set('America/Guayaquil');

/* ── Autoload de clases core ── */
spl_autoload_register(function (string $class): void {
    $file = __DIR__ . '/core/' . $class . '.php';
    if (file_exists($file)) require_once $file;
});

/* ── Requerir helpers ── */
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Response.php';
require_once __DIR__ . '/core/Auth.php';

/* ── Headers globales ── */
$appCfg = require __DIR__ . '/config/app.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// CORS
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $appCfg['cors_origins'], true)) {
    header("Access-Control-Allow-Origin: $origin");
}
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/* ── Router simple ── */
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$base   = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$path   = '/' . ltrim(substr($uri, strlen($base)), '/');
$method = strtoupper($_SERVER['REQUEST_METHOD']);

// Normalizar: /api/inventarios/total → partes
$parts  = array_values(array_filter(explode('/', $path)));
// Ejemplo: ['api', 'inventarios', 'total']

if (($parts[0] ?? '') !== 'api') {
    Response::notFound('Ruta no encontrada');
}

$resource  = $parts[1] ?? '';   // inventarios, stock, toma, etc.
$subaction = $parts[2] ?? '';   // total, bodegas, etc.
$id        = $parts[3] ?? null; // ID opcional

/* ── Despachar al controlador ── */
try {
    match ($resource) {
        'auth'          => require __DIR__ . '/api/auth.php',
        'inventarios'   => require __DIR__ . '/api/inventarios/index.php',
        'stock'         => require __DIR__ . '/api/stock/index.php',
        'toma'          => require __DIR__ . '/api/toma/index.php',
        'distribucion'  => require __DIR__ . '/api/distribucion/index.php',
        'reportes'      => require __DIR__ . '/api/reportes/index.php',
        'bodegas'       => require __DIR__ . '/api/bodegas/index.php',
        'articulos'     => require __DIR__ . '/api/articulos/index.php',
        'usuarios'      => require __DIR__ . '/api/usuarios/index.php',
        'dashboard'     => require __DIR__ . '/api/dashboard/index.php',
        default         => Response::notFound("Recurso '$resource' no existe"),
    };
} catch (PDOException $e) {
    $msg = $appCfg['debug'] ? $e->getMessage() : 'Error de base de datos';
    Response::serverError($msg);
} catch (Throwable $e) {
    $msg = $appCfg['debug'] ? $e->getMessage() : 'Error interno';
    Response::serverError($msg);
}
