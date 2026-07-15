<?php
declare(strict_types=1);

session_start();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$host = 'localhost';
$db = 'ddg_otec';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

// Cambiar en producción (ideal: variables de entorno).
$adminUser = getenv('DDG_INVENTARIO_USER') ?: 'admin';
$adminPass = getenv('DDG_INVENTARIO_PASS') ?: 'admin';

function respond(bool $ok, mixed $data = null, string $error = '', int $status = 200): never
{
    http_response_code($status);
    echo json_encode(
        ['ok' => $ok, 'data' => $data, 'error' => $error],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function getBody(): array
{
    $raw = file_get_contents('php://input');
    return (array) (json_decode($raw, true) ?? []);
}

function isLoggedIn(): bool
{
    return !empty($_SESSION['inventario_auth']) && $_SESSION['inventario_auth'] === true;
}

function requireAuth(): void
{
    if (!isLoggedIn()) {
        respond(false, null, 'Sesión no autorizada. Inicia sesión nuevamente.', 401);
    }
}

function sanitizeState(array $state): array
{
    $activos = isset($state['activos']) && is_array($state['activos']) ? array_values($state['activos']) : [];
    $clases = isset($state['clases']) && is_array($state['clases']) ? array_values($state['clases']) : [];
    $asignaciones = isset($state['asignaciones']) && is_array($state['asignaciones']) ? array_values($state['asignaciones']) : [];

    return [
        'activos' => $activos,
        'clases' => $clases,
        'asignaciones' => $asignaciones,
    ];
}

function validateState(array $state): void
{
    $activosById = [];
    foreach ($state['activos'] as $a) {
        if (!isset($a['id']) || !isset($a['cantidad_total'])) {
            respond(false, null, 'Datos de activos inválidos.', 422);
        }
        $activosById[(int) $a['id']] = max(0, (int) $a['cantidad_total']);
    }

    $clasesActivas = [];
    foreach ($state['clases'] as $c) {
        if (!isset($c['id'])) {
            continue;
        }
        $estado = (string) ($c['estado'] ?? '');
        $clasesActivas[(int) $c['id']] = ($estado !== 'Finalizada' && $estado !== 'Cancelada');
    }

    $enUso = [];
    foreach ($state['asignaciones'] as $as) {
        $activoId = (int) ($as['activo_id'] ?? 0);
        $claseId = (int) ($as['clase_id'] ?? 0);
        $cantidad = (int) ($as['cantidad'] ?? 0);

        if ($activoId <= 0 || $claseId <= 0 || $cantidad < 0) {
            respond(false, null, 'Datos de asignaciones inválidos.', 422);
        }
        if (!isset($activosById[$activoId])) {
            respond(false, null, 'Existe una asignación a un activo inexistente.', 422);
        }
        if (!isset($clasesActivas[$claseId])) {
            respond(false, null, 'Existe una asignación a una clase inexistente.', 422);
        }

        if ($clasesActivas[$claseId]) {
            $enUso[$activoId] = ($enUso[$activoId] ?? 0) + $cantidad;
        }
    }

    foreach ($enUso as $activoId => $cantidadUso) {
        if ($cantidadUso > $activosById[$activoId]) {
            respond(
                false,
                null,
                "No hay stock suficiente para el activo ID {$activoId}. En uso: {$cantidadUso}, disponible total: {$activosById[$activoId]}.",
                409
            );
        }
    }
}

$dsn = "mysql:host={$host};dbname={$db};charset={$charset}";
try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    respond(false, null, 'No se pudo conectar a la base de datos.', 503);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? (string) $_GET['action'] : 'state';

if ($method === 'POST' && $action === 'login') {
    $body = getBody();
    $u = trim((string) ($body['user'] ?? ''));
    $p = (string) ($body['pass'] ?? '');

    if ($u === $adminUser && hash_equals($adminPass, $p)) {
        $_SESSION['inventario_auth'] = true;
        respond(true, ['authenticated' => true]);
    }
    respond(false, null, 'Credenciales inválidas.', 401);
}

if ($method === 'POST' && $action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
    respond(true, ['authenticated' => false]);
}

if ($method === 'GET' && $action === 'session') {
    respond(true, ['authenticated' => isLoggedIn()]);
}

requireAuth();

try {
    if ($method === 'GET' && $action === 'state') {
        $stmt = $pdo->query('SELECT state_json FROM inventario_state WHERE id = 1');
        $row = $stmt->fetch();
        if (!$row) {
            $initial = ['activos' => [], 'clases' => [], 'asignaciones' => []];
            $insert = $pdo->prepare('INSERT INTO inventario_state (id, state_json) VALUES (1, :json)');
            $insert->execute([':json' => json_encode($initial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            respond(true, $initial);
        }

        $state = json_decode((string) $row['state_json'], true);
        if (!is_array($state)) {
            $state = ['activos' => [], 'clases' => [], 'asignaciones' => []];
        }
        respond(true, sanitizeState($state));
    }

    if ($method === 'POST' && $action === 'save_state') {
        $body = getBody();
        if (!isset($body['state']) || !is_array($body['state'])) {
            respond(false, null, 'Payload inválido: falta state.', 422);
        }

        $state = sanitizeState($body['state']);
        validateState($state);
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $sql = '
            INSERT INTO inventario_state (id, state_json)
            VALUES (1, :json)
            ON DUPLICATE KEY UPDATE state_json = VALUES(state_json), updated_at = CURRENT_TIMESTAMP
        ';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':json' => $json]);

        respond(true, $state);
    }

    respond(false, null, 'Endpoint no encontrado.', 404);
} catch (PDOException $e) {
    respond(false, null, 'Error al procesar la solicitud en base de datos.', 500);
}

