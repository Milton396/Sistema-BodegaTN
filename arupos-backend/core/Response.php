<?php
/**
 * core/Response.php — Helpers para respuestas JSON estandarizadas
 *
 * Formato de respuesta:
 * {
 *   "ok":   true | false,
 *   "data": { ... } | null,
 *   "meta": { "total": int, "page": int, "per_page": int } | null,
 *   "error":{ "code": string, "message": string } | null
 * }
 */

class Response
{
    public static function json(mixed $data, int $status = 200, ?array $meta = null): void
    {
        http_response_code($status);
        echo json_encode([
            'ok'    => $status < 400,
            'data'  => $data,
            'meta'  => $meta,
            'error' => null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function error(string $message, int $status = 400, string $code = 'ERROR'): void
    {
        http_response_code($status);
        echo json_encode([
            'ok'    => false,
            'data'  => null,
            'meta'  => null,
            'error' => ['code' => $code, 'message' => $message],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function notFound(string $message = 'Recurso no encontrado'): void
    {
        self::error($message, 404, 'NOT_FOUND');
    }

    public static function unauthorized(string $message = 'No autorizado'): void
    {
        self::error($message, 401, 'UNAUTHORIZED');
    }

    public static function forbidden(string $message = 'Acceso denegado'): void
    {
        self::error($message, 403, 'FORBIDDEN');
    }

    public static function serverError(string $message = 'Error interno del servidor'): void
    {
        self::error($message, 500, 'SERVER_ERROR');
    }

    /** Paginación estándar */
    public static function paginated(array $rows, int $total, int $page, int $perPage): void
    {
        self::json($rows, 200, [
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $perPage,
            'last_page'  => (int) ceil($total / $perPage),
        ]);
    }
}
