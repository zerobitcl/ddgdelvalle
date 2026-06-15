<?php
// ══════════════════════════════════════════════════════════
//  DDG del Valle · API RESTful de Relatores
//  Archivo: api.php  (debe estar en el mismo directorio que relatores.html)
//
//  Endpoints:
//    GET    api.php          → listar todos
//    POST   api.php          → crear  (body: JSON)
//    PUT    api.php?id=X     → editar (body: JSON)
//    DELETE api.php?id=X     → eliminar
// ══════════════════════════════════════════════════════════

declare(strict_types=1);

// ┌─────────────────────────────────────────────────────┐
// │  CONFIGURACIÓN — Edita estos valores                │
// └─────────────────────────────────────────────────────┘
$host    = 'localhost';
$db      = 'ddg_otec';
$user    = 'root';
$pass    = '';
$charset = 'utf8mb4';
// └─────────────────────────────────────────────────────┘


// ── Headers ──────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');

// Responder al preflight CORS y salir
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}


// ── Conexión PDO ─────────────────────────────────────────
$dsn = "mysql:host={$host};dbname={$db};charset={$charset}";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    respond(false, null, 'No se pudo conectar a la base de datos. Verifica la configuración.', 503);
}


// ── Helpers ───────────────────────────────────────────────

/**
 * Envía respuesta JSON estandarizada y termina el script.
 */
function respond(bool $ok, mixed $data, string $error = '', int $status = 200): never
{
    http_response_code($status);
    echo json_encode(
        ['ok' => $ok, 'data' => $data, 'error' => $error],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

/**
 * Transforma una fila plana de BD al objeto anidado que espera el frontend.
 */
function normalize(array $row): array
{
    return [
        'id'       => (int) $row['id'],
        'curso'    => $row['curso'],
        'nombre'   => $row['nombre'],
        'rut'      => $row['rut'],
        'carrera'  => $row['carrera'],
        'correo'   => $row['correo'],
        'vigencia' => $row['vigencia'],
        'carpeta'  => $row['carpeta'],
        'telefono' => $row['telefono'],
        'transferencia' => [
            'banco'        => $row['banco'],
            'tipoCuenta'   => $row['tipo_cuenta'],
            'numeroCuenta' => $row['numero_cuenta'],
            'correo'       => $row['correo_transferencia'],
        ],
    ];
}

/**
 * Lee y decodifica el body JSON de la petición.
 */
function getBody(): array
{
    $raw = file_get_contents('php://input');
    return (array) (json_decode($raw, true) ?? []);
}

/**
 * Limpia un string de etiquetas y espacios.
 */
function clean(string $val): string
{
    return trim(strip_tags($val));
}

/**
 * Valida que un string de vigencia sea uno de los valores permitidos.
 */
function validVigencia(string $v): string
{
    return in_array($v, ['Activo', 'Inactivo', 'Pendiente'], true) ? $v : 'Pendiente';
}

/**
 * Construye el array de parámetros comunes para INSERT y UPDATE.
 */
function buildParams(array $b): array
{
    $t = isset($b['transferencia']) && is_array($b['transferencia'])
        ? $b['transferencia']
        : [];

    return [
        ':curso'          => clean($b['curso']    ?? ''),
        ':nombre'         => clean($b['nombre']   ?? ''),
        ':rut'            => clean($b['rut']      ?? ''),
        ':carrera'        => clean($b['carrera']  ?? ''),
        ':correo'         => clean($b['correo']   ?? ''),
        ':vigencia'       => validVigencia($b['vigencia'] ?? ''),
        ':carpeta'        => clean($b['carpeta']  ?? ''),
        ':telefono'       => clean($b['telefono'] ?? ''),
        ':banco'          => clean($t['banco']        ?? ''),
        ':tipo_cuenta'    => clean($t['tipoCuenta']   ?? ''),
        ':numero_cuenta'  => clean($t['numeroCuenta'] ?? ''),
        ':correo_transf'  => clean($t['correo']       ?? ''),
    ];
}


// ── Router principal ──────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;

try {
    switch ($method) {

    // ════════════════════════════════════════════════════
    // GET — Obtener todos los relatores
    // ════════════════════════════════════════════════════
    case 'GET':
        $stmt = $pdo->query('SELECT * FROM relatores ORDER BY id DESC');
        $rows = array_map('normalize', $stmt->fetchAll());
        respond(true, $rows);


    // ════════════════════════════════════════════════════
    // POST — Crear un nuevo relator
    // ════════════════════════════════════════════════════
    case 'POST':
        $b = getBody();

        $curso  = clean($b['curso']  ?? '');
        $nombre = clean($b['nombre'] ?? '');
        $rut    = clean($b['rut']    ?? '');

        if ($curso === '' || $nombre === '' || $rut === '') {
            respond(false, null, 'Los campos Curso, Nombre y RUT son obligatorios.', 422);
        }

        $params = buildParams($b);

        try {
            $stmt = $pdo->prepare('
                INSERT INTO relatores
                    (curso, nombre, rut, carrera, correo, vigencia, carpeta, telefono,
                     banco, tipo_cuenta, numero_cuenta, correo_transferencia)
                VALUES
                    (:curso, :nombre, :rut, :carrera, :correo, :vigencia, :carpeta, :telefono,
                     :banco, :tipo_cuenta, :numero_cuenta, :correo_transf)
            ');
            $stmt->execute($params);

            $newId = (int) $pdo->lastInsertId();
            $row   = $pdo->prepare('SELECT * FROM relatores WHERE id = ?');
            $row->execute([$newId]);
            respond(true, normalize($row->fetch()), '', 201);

        } catch (PDOException $e) {
            // RUT duplicado u otra violación de unicidad
            if ($e->getCode() === '23000') {
                respond(false, null, 'Ya existe un relator con ese RUT.', 409);
            }
            respond(false, null, 'Error al crear el relator.', 500);
        }


    // ════════════════════════════════════════════════════
    // PUT — Actualizar un relator existente
    // ════════════════════════════════════════════════════
    case 'PUT':
        if ($id <= 0) {
            respond(false, null, 'ID no proporcionado o inválido.', 400);
        }

        $b = getBody();

        $curso  = clean($b['curso']  ?? '');
        $nombre = clean($b['nombre'] ?? '');
        $rut    = clean($b['rut']    ?? '');

        if ($curso === '' || $nombre === '' || $rut === '') {
            respond(false, null, 'Los campos Curso, Nombre y RUT son obligatorios.', 422);
        }

        $params       = buildParams($b);
        $params[':id'] = $id;

        try {
            $stmt = $pdo->prepare('
                UPDATE relatores SET
                    curso               = :curso,
                    nombre              = :nombre,
                    rut                 = :rut,
                    carrera             = :carrera,
                    correo              = :correo,
                    vigencia            = :vigencia,
                    carpeta             = :carpeta,
                    telefono            = :telefono,
                    banco               = :banco,
                    tipo_cuenta         = :tipo_cuenta,
                    numero_cuenta       = :numero_cuenta,
                    correo_transferencia = :correo_transf
                WHERE id = :id
            ');
            $stmt->execute($params);

        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                respond(false, null, 'Ya existe otro relator con ese RUT.', 409);
            }
            respond(false, null, 'Error al actualizar el relator.', 500);
        }

        if ($stmt->rowCount() === 0) {
            respond(false, null, 'Relator no encontrado.', 404);
        }

        $row = $pdo->prepare('SELECT * FROM relatores WHERE id = ?');
        $row->execute([$id]);
        respond(true, normalize($row->fetch()));


    // ════════════════════════════════════════════════════
    // DELETE — Eliminar un relator
    // ════════════════════════════════════════════════════
    case 'DELETE':
        if ($id <= 0) {
            respond(false, null, 'ID no proporcionado o inválido.', 400);
        }

        $stmt = $pdo->prepare('DELETE FROM relatores WHERE id = ?');
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            respond(false, null, 'Relator no encontrado.', 404);
        }

        respond(true, ['deleted' => $id]);


    // ════════════════════════════════════════════════════
    // Método no soportado
    // ════════════════════════════════════════════════════
    default:
        respond(false, null, 'Método HTTP no permitido.', 405);
    }
} catch (PDOException $e) {
    respond(
        false,
        null,
        'Error al consultar la base de datos. Importa database.sql (base ddg_otec, tabla relatores) y revisa host/usuario/clave en api.php.',
        500
    );
}
