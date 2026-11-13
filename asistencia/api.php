<?php
declare(strict_types=1);

/**
 * DDG Del Valle · API de Asistencia
 *
 * Auth (sesión PHP):
 *   POST ?action=login   {usuario, clave}
 *   POST ?action=logout
 *   GET  ?action=me
 *
 * Admin (solo rol admin):
 *   GET  ?action=profesores
 *   POST ?action=profesor_crear      {usuario, nombre, clave, email?}
 *   POST ?action=profesor_toggle     {id, activo}
 *   GET  ?action=curso_profesores&curso_id=
 *   POST ?action=curso_asignar       {curso_id, usuario_id}
 *   POST ?action=curso_desasignar    {curso_id, usuario_id}
 *
 * Cursos:
 *   GET  ?action=cursos              → todos; flag puede_entrar
 *   POST ?action=curso_crear         (admin) {codigo?, nombre, modalidad}
 *
 * Nómina / sesiones / asistencia: requieren estar asignado al curso (admin siempre).
 */

session_start();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . (isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Misma BD operativa que relatores (Hostinger). Ajustar si cambia el entorno.
$host    = 'localhost';
$db      = 'u739953844_DDGDELVALLE';
$user    = 'u739953844_DDGADMIN';
$pass    = 'dDGDELVALLE2026!!!';
$charset = 'utf8mb4';

function respond(bool $ok, mixed $data = null, string $error = '', int $status = 200): never
{
    http_response_code($status);
    echo json_encode(
        ['ok' => $ok, 'data' => $data, 'error' => $error],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function body(): array
{
    $raw = file_get_contents('php://input');
    return (array) (json_decode($raw ?: '[]', true) ?? []);
}

function clean(string $v): string
{
    return trim(strip_tags($v));
}

function authUser(): ?array
{
    if (empty($_SESSION['asis_auth']) || empty($_SESSION['asis_user_id'])) {
        return null;
    }
    return [
        'id' => (int) $_SESSION['asis_user_id'],
        'usuario' => (string) ($_SESSION['asis_user'] ?? ''),
        'nombre' => (string) ($_SESSION['asis_nombre'] ?? ''),
        'rol' => (string) ($_SESSION['asis_rol'] ?? 'profesor'),
    ];
}

function requireAuth(): array
{
    $u = authUser();
    if (!$u) {
        respond(false, null, 'Sesión no autorizada.', 401);
    }
    return $u;
}

function requireAdmin(): array
{
    $u = requireAuth();
    if ($u['rol'] !== 'admin') {
        respond(false, null, 'Solo administración puede hacer esto.', 403);
    }
    return $u;
}

function isAdmin(): bool
{
    return (authUser()['rol'] ?? '') === 'admin';
}

function who(): string
{
    $u = authUser();
    if (!$u) {
        return 'relator';
    }
    return $u['nombre'] !== '' ? $u['nombre'] : $u['usuario'];
}

function puedeEntrarCurso(PDO $pdo, int $cursoId): bool
{
    $u = authUser();
    if (!$u) {
        return false;
    }
    if ($u['rol'] === 'admin') {
        return true;
    }
    $st = $pdo->prepare(
        'SELECT 1 FROM asis_curso_asignaciones WHERE curso_id = ? AND usuario_id = ? LIMIT 1'
    );
    $st->execute([$cursoId, $u['id']]);
    return (bool) $st->fetchColumn();
}

function requireCursoAccess(PDO $pdo, int $cursoId): array
{
    $u = requireAuth();
    if ($cursoId < 1) {
        respond(false, null, 'curso_id requerido.', 422);
    }
    if (!puedeEntrarCurso($pdo, $cursoId)) {
        respond(false, null, 'No estás asignado a este curso. Pide acceso a administración.', 403);
    }
    return $u;
}

function cursoIdDeSesion(PDO $pdo, int $sesionId): int
{
    $st = $pdo->prepare('SELECT curso_id FROM asis_sesiones WHERE id = ? LIMIT 1');
    $st->execute([$sesionId]);
    return (int) ($st->fetchColumn() ?: 0);
}

function setSessionUser(array $row): void
{
    session_regenerate_id(true);
    $_SESSION['asis_auth'] = true;
    $_SESSION['asis_user_id'] = (int) $row['id'];
    $_SESSION['asis_user'] = (string) $row['usuario'];
    $_SESSION['asis_nombre'] = (string) $row['nombre'];
    $_SESSION['asis_rol'] = (string) $row['rol'];
}

function slugify(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    return trim($s, '-') ?: ('curso-' . bin2hex(random_bytes(3)));
}

function rutNormalizar(string $rut): string
{
    $r = strtoupper(preg_replace('/[^0-9kK]/', '', $rut) ?? '');
    if (strlen($r) < 2) {
        return $rut;
    }
    $dv = substr($r, -1);
    $num = substr($r, 0, -1);
    return number_format((int) $num, 0, '', '.') . '-' . $dv;
}

function estadoOk(string $e): string
{
    $ok = ['presente', 'ausente', 'justificado', 'tardanza', 'sin_marcar'];
    return in_array($e, $ok, true) ? $e : 'sin_marcar';
}

function modalidadOk(string $m): string
{
    return in_array($m, ['presencial', 'online', 'mixta'], true) ? $m : 'online';
}

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$db};charset={$charset}",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    respond(false, null, 'No se pudo conectar a la base de datos.', 503);
}

function ensureSchema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS `asis_usuarios` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `usuario` VARCHAR(80) NOT NULL,
      `nombre` VARCHAR(255) NOT NULL,
      `email` VARCHAR(255) NOT NULL DEFAULT '',
      `password_hash` VARCHAR(255) NOT NULL,
      `rol` ENUM('admin','profesor') NOT NULL DEFAULT 'profesor',
      `activo` TINYINT(1) NOT NULL DEFAULT 1,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_usuario` (`usuario`),
      KEY `idx_rol` (`rol`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `asis_curso_asignaciones` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `curso_id` INT UNSIGNED NOT NULL,
      `usuario_id` INT UNSIGNED NOT NULL,
      `asignado_por` INT UNSIGNED NULL DEFAULT NULL,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_curso_usuario` (`curso_id`,`usuario_id`),
      KEY `idx_usuario` (`usuario_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $andrea = $pdo->query("SELECT id FROM asis_usuarios WHERE usuario = 'andrea' LIMIT 1")->fetch();
    if (!$andrea) {
        $ins = $pdo->prepare(
            'INSERT INTO asis_usuarios (usuario, nombre, email, password_hash, rol) VALUES (?,?,?,?,?)'
        );
        $ins->execute([
            'andrea',
            'Andrea',
            'andrea@ddgdelvalle.cl',
            password_hash('AndreaDDG2026*', PASSWORD_DEFAULT),
            'admin',
        ]);
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS `asis_cursos` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `codigo` VARCHAR(64) NOT NULL,
      `nombre` VARCHAR(255) NOT NULL,
      `modalidad` ENUM('presencial','online','mixta') NOT NULL DEFAULT 'online',
      `activo` TINYINT(1) NOT NULL DEFAULT 1,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_codigo` (`codigo`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `asis_alumnos` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `curso_id` INT UNSIGNED NOT NULL,
      `rut` VARCHAR(20) NOT NULL,
      `nombre` VARCHAR(255) NOT NULL,
      `email` VARCHAR(255) NOT NULL DEFAULT '',
      `activo` TINYINT(1) NOT NULL DEFAULT 1,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_curso_rut` (`curso_id`,`rut`),
      KEY `idx_curso` (`curso_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `asis_sesiones` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `curso_id` INT UNSIGNED NOT NULL,
      `fecha` DATE NOT NULL,
      `hora_inicio` TIME NOT NULL DEFAULT '09:00:00',
      `titulo` VARCHAR(255) NOT NULL DEFAULT 'Clase',
      `enlace_online` VARCHAR(1000) NOT NULL DEFAULT '',
      `notas` VARCHAR(1000) NOT NULL DEFAULT '',
      `creado_por` VARCHAR(120) NOT NULL DEFAULT 'relator',
      `cerrada` TINYINT(1) NOT NULL DEFAULT 0,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_curso_fecha` (`curso_id`,`fecha`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `asis_registros` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `sesion_id` INT UNSIGNED NOT NULL,
      `alumno_id` INT UNSIGNED NOT NULL,
      `estado` ENUM('presente','ausente','justificado','tardanza','sin_marcar') NOT NULL DEFAULT 'sin_marcar',
      `observacion` VARCHAR(500) NOT NULL DEFAULT '',
      `marcado_por` VARCHAR(120) NOT NULL DEFAULT '',
      `marcado_en` TIMESTAMP NULL DEFAULT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_sesion_alumno` (`sesion_id`,`alumno_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $n = (int) $pdo->query('SELECT COUNT(*) FROM asis_cursos')->fetchColumn();
    if ($n === 0) {
        $ins = $pdo->prepare('INSERT INTO asis_cursos (codigo, nombre, modalidad) VALUES (?,?,?)');
        $ins->execute(['gastronomia-online', 'Gastronomía Online', 'online']);
        $ins->execute(['riego-tecnificado', 'Riego Tecnificado', 'mixta']);
    }
}

ensureSchema($pdo);

$action = clean($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];
$b = $method === 'POST' ? body() : [];

try {
    switch ($action) {

        case 'login':
            if ($method !== 'POST') {
                respond(false, null, 'Método no permitido.', 405);
            }
            $loginUser = mb_strtolower(clean((string) ($b['usuario'] ?? '')), 'UTF-8');
            $clave = (string) ($b['clave'] ?? '');
            if ($loginUser === '' || $clave === '') {
                respond(false, null, 'Usuario y contraseña requeridos.', 422);
            }
            $st = $pdo->prepare(
                'SELECT * FROM asis_usuarios WHERE LOWER(usuario) = ? AND activo = 1 LIMIT 1'
            );
            $st->execute([$loginUser]);
            $row = $st->fetch();
            if (!$row || !password_verify($clave, $row['password_hash'])) {
                respond(false, null, 'Credenciales incorrectas.', 401);
            }
            setSessionUser($row);
            respond(true, [
                'id' => (int) $row['id'],
                'usuario' => $row['usuario'],
                'nombre' => $row['nombre'],
                'rol' => $row['rol'],
            ]);

        case 'logout':
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
            }
            session_destroy();
            respond(true, ['logout' => true]);

        case 'me':
            $u = authUser();
            if (!$u) {
                respond(true, ['auth' => false]);
            }
            respond(true, [
                'auth' => true,
                'id' => $u['id'],
                'usuario' => $u['usuario'],
                'nombre' => $u['nombre'],
                'rol' => $u['rol'],
            ]);

        case 'profesores':
            requireAdmin();
            $rows = $pdo->query(
                "SELECT u.id, u.usuario, u.nombre, u.email, u.rol, u.activo, u.created_at,
                        (SELECT COUNT(*) FROM asis_curso_asignaciones a WHERE a.usuario_id = u.id) AS cursos_n
                 FROM asis_usuarios u
                 WHERE u.rol = 'profesor'
                 ORDER BY u.nombre ASC"
            )->fetchAll();
            respond(true, $rows);

        case 'profesor_crear':
            requireAdmin();
            if ($method !== 'POST') {
                respond(false, null, 'Método no permitido.', 405);
            }
            $usuario = mb_strtolower(clean((string) ($b['usuario'] ?? '')), 'UTF-8');
            $nombre = clean((string) ($b['nombre'] ?? ''));
            $email = clean((string) ($b['email'] ?? ''));
            $clave = (string) ($b['clave'] ?? '');
            if ($usuario === '' || $nombre === '' || $clave === '') {
                respond(false, null, 'usuario, nombre y clave son obligatorios.', 422);
            }
            if (!preg_match('/^[a-z0-9._-]{3,40}$/', $usuario)) {
                respond(false, null, 'Usuario inválido (3-40: letras, números, . _ -).', 422);
            }
            if (strlen($clave) < 6) {
                respond(false, null, 'La clave debe tener al menos 6 caracteres.', 422);
            }
            $st = $pdo->prepare(
                'INSERT INTO asis_usuarios (usuario, nombre, email, password_hash, rol) VALUES (?,?,?,?,\'profesor\')'
            );
            try {
                $st->execute([$usuario, $nombre, $email, password_hash($clave, PASSWORD_DEFAULT)]);
            } catch (PDOException $e) {
                respond(false, null, 'Ese usuario ya existe.', 409);
            }
            respond(true, [
                'id' => (int) $pdo->lastInsertId(),
                'usuario' => $usuario,
                'nombre' => $nombre,
                'email' => $email,
                'rol' => 'profesor',
            ]);

        case 'profesor_toggle':
            requireAdmin();
            if ($method !== 'POST') {
                respond(false, null, 'Método no permitido.', 405);
            }
            $id = (int) ($b['id'] ?? 0);
            $activo = !empty($b['activo']) ? 1 : 0;
            if ($id < 1) {
                respond(false, null, 'id requerido.', 422);
            }
            $st = $pdo->prepare("UPDATE asis_usuarios SET activo = ? WHERE id = ? AND rol = 'profesor'");
            $st->execute([$activo, $id]);
            if ($st->rowCount() < 1) {
                respond(false, null, 'Profesor no encontrado.', 404);
            }
            respond(true, ['id' => $id, 'activo' => $activo]);

        case 'curso_profesores':
            requireAdmin();
            $cursoId = (int) ($_GET['curso_id'] ?? 0);
            if ($cursoId < 1) {
                respond(false, null, 'curso_id requerido.', 422);
            }
            $st = $pdo->prepare(
                "SELECT u.id, u.usuario, u.nombre, u.email, u.activo,
                        CASE WHEN a.id IS NULL THEN 0 ELSE 1 END AS asignado
                 FROM asis_usuarios u
                 LEFT JOIN asis_curso_asignaciones a
                   ON a.usuario_id = u.id AND a.curso_id = ?
                 WHERE u.rol = 'profesor'
                 ORDER BY u.nombre ASC"
            );
            $st->execute([$cursoId]);
            respond(true, $st->fetchAll());

        case 'curso_asignar':
            requireAdmin();
            if ($method !== 'POST') {
                respond(false, null, 'Método no permitido.', 405);
            }
            $admin = authUser();
            $cursoId = (int) ($b['curso_id'] ?? 0);
            $usuarioId = (int) ($b['usuario_id'] ?? 0);
            if ($cursoId < 1 || $usuarioId < 1) {
                respond(false, null, 'curso_id y usuario_id requeridos.', 422);
            }
            $profe = $pdo->prepare("SELECT id FROM asis_usuarios WHERE id = ? AND rol = 'profesor' LIMIT 1");
            $profe->execute([$usuarioId]);
            if (!$profe->fetch()) {
                respond(false, null, 'Profesor no válido.', 404);
            }
            $st = $pdo->prepare(
                'INSERT IGNORE INTO asis_curso_asignaciones (curso_id, usuario_id, asignado_por) VALUES (?,?,?)'
            );
            $st->execute([$cursoId, $usuarioId, $admin['id'] ?? null]);
            respond(true, ['curso_id' => $cursoId, 'usuario_id' => $usuarioId, 'asignado' => true]);

        case 'curso_desasignar':
            requireAdmin();
            if ($method !== 'POST') {
                respond(false, null, 'Método no permitido.', 405);
            }
            $cursoId = (int) ($b['curso_id'] ?? 0);
            $usuarioId = (int) ($b['usuario_id'] ?? 0);
            if ($cursoId < 1 || $usuarioId < 1) {
                respond(false, null, 'curso_id y usuario_id requeridos.', 422);
            }
            $pdo->prepare(
                'DELETE FROM asis_curso_asignaciones WHERE curso_id = ? AND usuario_id = ?'
            )->execute([$cursoId, $usuarioId]);
            respond(true, ['curso_id' => $cursoId, 'usuario_id' => $usuarioId, 'asignado' => false]);

        case 'cursos':
            $u = requireAuth();
            $rows = $pdo->query(
                'SELECT c.*,
                        (SELECT COUNT(*) FROM asis_alumnos a WHERE a.curso_id = c.id AND a.activo = 1) AS alumnos,
                        (SELECT COUNT(*) FROM asis_sesiones s WHERE s.curso_id = c.id) AS sesiones
                 FROM asis_cursos c
                 WHERE c.activo = 1
                 ORDER BY c.nombre ASC'
            )->fetchAll();
            $asignados = [];
            if ($u['rol'] !== 'admin') {
                $st = $pdo->prepare(
                    'SELECT curso_id FROM asis_curso_asignaciones WHERE usuario_id = ?'
                );
                $st->execute([$u['id']]);
                foreach ($st->fetchAll() as $a) {
                    $asignados[(int) $a['curso_id']] = true;
                }
            }
            $out = array_map(static function ($c) use ($u, $asignados) {
                $id = (int) $c['id'];
                $puede = $u['rol'] === 'admin' || !empty($asignados[$id]);
                $c['puede_entrar'] = $puede;
                $c['asignado'] = $puede;
                return $c;
            }, $rows);
            respond(true, $out);

        case 'curso_crear':
            requireAdmin();
            if ($method !== 'POST') {
                respond(false, null, 'Método no permitido.', 405);
            }
            $nombre = clean((string) ($b['nombre'] ?? ''));
            if ($nombre === '') {
                respond(false, null, 'El nombre del curso es obligatorio.', 422);
            }
            $codigo = clean((string) ($b['codigo'] ?? ''));
            if ($codigo === '') {
                $codigo = slugify($nombre);
            } else {
                $codigo = slugify($codigo);
            }
            $modalidad = modalidadOk(clean((string) ($b['modalidad'] ?? 'online')));
            $st = $pdo->prepare('INSERT INTO asis_cursos (codigo, nombre, modalidad) VALUES (?,?,?)');
            try {
                $st->execute([$codigo, $nombre, $modalidad]);
            } catch (PDOException $e) {
                respond(false, null, 'Ya existe un curso con ese código.', 409);
            }
            respond(true, ['id' => (int) $pdo->lastInsertId(), 'codigo' => $codigo, 'nombre' => $nombre, 'modalidad' => $modalidad]);

        case 'alumnos':
            $cursoId = (int) ($_GET['curso_id'] ?? 0);
            requireCursoAccess($pdo, $cursoId);
            $st = $pdo->prepare(
                'SELECT id, curso_id, rut, nombre, email, activo, created_at
                 FROM asis_alumnos WHERE curso_id = ? ORDER BY nombre ASC'
            );
            $st->execute([$cursoId]);
            respond(true, $st->fetchAll());

        case 'alumno_crear':
            if ($method !== 'POST') {
                respond(false, null, 'Método no permitido.', 405);
            }
            $cursoId = (int) ($b['curso_id'] ?? 0);
            requireCursoAccess($pdo, $cursoId);
            $nombre = clean((string) ($b['nombre'] ?? ''));
            $rut = rutNormalizar(clean((string) ($b['rut'] ?? '')));
            $email = clean((string) ($b['email'] ?? ''));
            if ($nombre === '' || $rut === '') {
                respond(false, null, 'curso_id, rut y nombre son obligatorios.', 422);
            }
            $st = $pdo->prepare(
                'INSERT INTO asis_alumnos (curso_id, rut, nombre, email) VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), email = VALUES(email), activo = 1'
            );
            $st->execute([$cursoId, $rut, $nombre, $email]);
            // Recuperar fila
            $q = $pdo->prepare('SELECT * FROM asis_alumnos WHERE curso_id = ? AND rut = ? LIMIT 1');
            $q->execute([$cursoId, $rut]);
            respond(true, $q->fetch());

        case 'alumno_toggle':
            if ($method !== 'POST') {
                respond(false, null, 'Método no permitido.', 405);
            }
            $id = (int) ($b['id'] ?? 0);
            $activo = !empty($b['activo']) ? 1 : 0;
            if ($id < 1) {
                respond(false, null, 'id requerido.', 422);
            }
            $al = $pdo->prepare('SELECT curso_id FROM asis_alumnos WHERE id = ? LIMIT 1');
            $al->execute([$id]);
            $cursoId = (int) ($al->fetchColumn() ?: 0);
            requireCursoAccess($pdo, $cursoId);
            $st = $pdo->prepare('UPDATE asis_alumnos SET activo = ? WHERE id = ?');
            $st->execute([$activo, $id]);
            respond(true, ['id' => $id, 'activo' => $activo]);

        case 'alumno_eliminar':
            if ($method !== 'POST') {
                respond(false, null, 'Método no permitido.', 405);
            }
            $id = (int) ($b['id'] ?? 0);
            if ($id < 1) {
                respond(false, null, 'id requerido.', 422);
            }
            $exists = $pdo->prepare('SELECT id, curso_id FROM asis_alumnos WHERE id = ? LIMIT 1');
            $exists->execute([$id]);
            $alumnoRow = $exists->fetch();
            if (!$alumnoRow) {
                respond(false, null, 'Alumno no encontrado.', 404);
            }
            requireCursoAccess($pdo, (int) $alumnoRow['curso_id']);
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM asis_registros WHERE alumno_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM asis_alumnos WHERE id = ?')->execute([$id]);
            $pdo->commit();
            respond(true, ['id' => $id, 'eliminado' => true]);

        case 'alumno_importar':
            if ($method !== 'POST') {
                respond(false, null, 'Método no permitido.', 405);
            }
            $cursoId = (int) ($b['curso_id'] ?? 0);
            requireCursoAccess($pdo, $cursoId);
            $items = isset($b['alumnos']) && is_array($b['alumnos']) ? $b['alumnos'] : [];
            if (!$items) {
                respond(false, null, 'curso_id y alumnos[] son obligatorios.', 422);
            }
            $cursoOk = $pdo->prepare('SELECT id FROM asis_cursos WHERE id = ? LIMIT 1');
            $cursoOk->execute([$cursoId]);
            if (!$cursoOk->fetch()) {
                respond(false, null, 'Curso no encontrado.', 404);
            }

            $ins = $pdo->prepare(
                'INSERT INTO asis_alumnos (curso_id, rut, nombre, email) VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), email = IF(VALUES(email) = \'\', email, VALUES(email)), activo = 1'
            );
            $creados = 0;
            $actualizados = 0;
            $omitidos = 0;
            $seq = 1;

            $pdo->beginTransaction();
            foreach ($items as $it) {
                if (!is_array($it)) {
                    $omitidos++;
                    continue;
                }
                $nombre = clean((string) ($it['nombre'] ?? ''));
                $email = clean((string) ($it['email'] ?? ''));
                $rutRaw = clean((string) ($it['rut'] ?? ''));
                if ($nombre === '') {
                    $omitidos++;
                    continue;
                }
                if ($rutRaw === '') {
                    $slug = preg_replace('/[^A-Z0-9]+/', '', strtoupper(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nombre) ?: $nombre)) ?: 'ALUMNO';
                    $slug = substr($slug, 0, 12);
                    $rutRaw = 'NR-' . $cursoId . '-' . $slug . '-' . $seq;
                    $seq++;
                    $rut = $rutRaw;
                } else {
                    $rut = rutNormalizar($rutRaw);
                }

                $before = $pdo->prepare('SELECT id FROM asis_alumnos WHERE curso_id = ? AND rut = ? LIMIT 1');
                $before->execute([$cursoId, $rut]);
                $existia = (bool) $before->fetch();
                $ins->execute([$cursoId, $rut, $nombre, $email]);
                if ($existia) {
                    $actualizados++;
                } else {
                    $creados++;
                }
            }
            $pdo->commit();
            respond(true, [
                'creados' => $creados,
                'actualizados' => $actualizados,
                'omitidos' => $omitidos,
                'total' => $creados + $actualizados,
            ]);

        case 'sesiones':
            $cursoId = (int) ($_GET['curso_id'] ?? 0);
            requireCursoAccess($pdo, $cursoId);
            $st = $pdo->prepare(
                'SELECT s.*,
                        (SELECT COUNT(*) FROM asis_registros r WHERE r.sesion_id = s.id AND r.estado = \'presente\') AS presentes,
                        (SELECT COUNT(*) FROM asis_registros r WHERE r.sesion_id = s.id AND r.estado = \'ausente\') AS ausentes,
                        (SELECT COUNT(*) FROM asis_registros r WHERE r.sesion_id = s.id AND r.estado != \'sin_marcar\') AS marcados
                 FROM asis_sesiones s
                 WHERE s.curso_id = ?
                 ORDER BY
                   (s.fecha < CURDATE()) ASC,
                   CASE WHEN s.fecha >= CURDATE() THEN s.fecha END ASC,
                   CASE WHEN s.fecha >= CURDATE() THEN s.hora_inicio END ASC,
                   CASE WHEN s.fecha < CURDATE() THEN s.fecha END DESC,
                   CASE WHEN s.fecha < CURDATE() THEN s.hora_inicio END DESC,
                   s.id DESC'
            );
            $st->execute([$cursoId]);
            respond(true, $st->fetchAll());

        case 'sesion_crear':
            if ($method !== 'POST') {
                respond(false, null, 'Método no permitido.', 405);
            }
            $cursoId = (int) ($b['curso_id'] ?? 0);
            requireCursoAccess($pdo, $cursoId);
            $fecha = clean((string) ($b['fecha'] ?? ''));
            $hora = clean((string) ($b['hora_inicio'] ?? '09:00'));
            $titulo = clean((string) ($b['titulo'] ?? 'Clase'));
            $enlace = clean((string) ($b['enlace_online'] ?? ''));
            $notas = clean((string) ($b['notas'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
                respond(false, null, 'curso_id y fecha (YYYY-MM-DD) son obligatorios.', 422);
            }
            if (!preg_match('/^\d{2}:\d{2}/', $hora)) {
                $hora = '09:00';
            }
            if (strlen($hora) === 5) {
                $hora .= ':00';
            }
            if ($titulo === '') {
                $titulo = 'Clase';
            }

            $pdo->beginTransaction();
            $st = $pdo->prepare(
                'INSERT INTO asis_sesiones (curso_id, fecha, hora_inicio, titulo, enlace_online, notas, creado_por)
                 VALUES (?,?,?,?,?,?,?)'
            );
            $st->execute([$cursoId, $fecha, $hora, $titulo, $enlace, $notas, who()]);
            $sesionId = (int) $pdo->lastInsertId();

            // Pre-carga nómina activa en la sesión
            $al = $pdo->prepare('SELECT id FROM asis_alumnos WHERE curso_id = ? AND activo = 1');
            $al->execute([$cursoId]);
            $ins = $pdo->prepare(
                'INSERT IGNORE INTO asis_registros (sesion_id, alumno_id, estado) VALUES (?,?,\'sin_marcar\')'
            );
            foreach ($al->fetchAll() as $row) {
                $ins->execute([$sesionId, (int) $row['id']]);
            }
            $pdo->commit();

            respond(true, ['id' => $sesionId]);

        case 'sesion_actualizar':
            if ($method !== 'POST') {
                respond(false, null, 'Método no permitido.', 405);
            }
            $id = (int) ($b['id'] ?? 0);
            if ($id < 1) {
                respond(false, null, 'id requerido.', 422);
            }
            requireCursoAccess($pdo, cursoIdDeSesion($pdo, $id));
            $fields = [];
            $params = [];
            foreach (['titulo', 'enlace_online', 'notas'] as $k) {
                if (array_key_exists($k, $b)) {
                    $fields[] = "$k = ?";
                    $params[] = clean((string) $b[$k]);
                }
            }
            if (array_key_exists('fecha', $b)) {
                $fecha = clean((string) $b['fecha']);
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
                    respond(false, null, 'fecha inválida (YYYY-MM-DD).', 422);
                }
                $fields[] = 'fecha = ?';
                $params[] = $fecha;
            }
            if (array_key_exists('hora_inicio', $b)) {
                $hora = clean((string) $b['hora_inicio']);
                if (!preg_match('/^\d{2}:\d{2}/', $hora)) {
                    respond(false, null, 'hora_inicio inválida (HH:MM).', 422);
                }
                if (strlen($hora) === 5) {
                    $hora .= ':00';
                }
                $fields[] = 'hora_inicio = ?';
                $params[] = $hora;
            }
            if (array_key_exists('cerrada', $b)) {
                $fields[] = 'cerrada = ?';
                $params[] = !empty($b['cerrada']) ? 1 : 0;
            }
            if (!$fields) {
                respond(false, null, 'Nada que actualizar.', 422);
            }
            $params[] = $id;
            $pdo->prepare('UPDATE asis_sesiones SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
            respond(true, ['id' => $id]);

        case 'sesion_eliminar':
            if ($method !== 'POST') {
                respond(false, null, 'Método no permitido.', 405);
            }
            $id = (int) ($b['id'] ?? 0);
            if ($id < 1) {
                respond(false, null, 'id requerido.', 422);
            }
            $exists = $pdo->prepare('SELECT id, curso_id FROM asis_sesiones WHERE id = ? LIMIT 1');
            $exists->execute([$id]);
            $sesionRow = $exists->fetch();
            if (!$sesionRow) {
                respond(false, null, 'Sesión no encontrada.', 404);
            }
            requireCursoAccess($pdo, (int) $sesionRow['curso_id']);
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM asis_registros WHERE sesion_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM asis_sesiones WHERE id = ?')->execute([$id]);
            $pdo->commit();
            respond(true, ['id' => $id, 'eliminado' => true]);

        case 'sesion':
            $id = (int) ($_GET['id'] ?? 0);
            if ($id < 1) {
                respond(false, null, 'id requerido.', 422);
            }
            $st = $pdo->prepare(
                'SELECT s.*, c.nombre AS curso_nombre, c.codigo AS curso_codigo, c.modalidad
                 FROM asis_sesiones s
                 INNER JOIN asis_cursos c ON c.id = s.curso_id
                 WHERE s.id = ? LIMIT 1'
            );
            $st->execute([$id]);
            $sesion = $st->fetch();
            if (!$sesion) {
                respond(false, null, 'Sesión no encontrada.', 404);
            }
            requireCursoAccess($pdo, (int) $sesion['curso_id']);

            // Sincroniza alumnos activos nuevos a la lista (sin borrar marcados)
            $sync = $pdo->prepare(
                'INSERT IGNORE INTO asis_registros (sesion_id, alumno_id, estado)
                 SELECT ?, a.id, \'sin_marcar\' FROM asis_alumnos a
                 WHERE a.curso_id = ? AND a.activo = 1'
            );
            $sync->execute([$id, (int) $sesion['curso_id']]);

            $lista = $pdo->prepare(
                'SELECT r.id AS registro_id, r.estado, r.observacion, r.marcado_por, r.marcado_en,
                        a.id AS alumno_id, a.rut, a.nombre, a.email, a.activo
                 FROM asis_registros r
                 INNER JOIN asis_alumnos a ON a.id = r.alumno_id
                 WHERE r.sesion_id = ?
                 ORDER BY a.nombre ASC'
            );
            $lista->execute([$id]);
            $alumnos = $lista->fetchAll();

            $resumen = [
                'total' => count($alumnos),
                'presente' => 0,
                'ausente' => 0,
                'justificado' => 0,
                'tardanza' => 0,
                'sin_marcar' => 0,
            ];
            foreach ($alumnos as $a) {
                $e = $a['estado'] ?? 'sin_marcar';
                if (isset($resumen[$e])) {
                    $resumen[$e]++;
                }
            }

            respond(true, ['sesion' => $sesion, 'lista' => $alumnos, 'resumen' => $resumen]);

        case 'marcar':
            if ($method !== 'POST') {
                respond(false, null, 'Método no permitido.', 405);
            }
            $sesionId = (int) ($b['sesion_id'] ?? 0);
            $alumnoId = (int) ($b['alumno_id'] ?? 0);
            $estado = estadoOk(clean((string) ($b['estado'] ?? '')));
            $obs = clean((string) ($b['observacion'] ?? ''));
            if ($sesionId < 1 || $alumnoId < 1) {
                respond(false, null, 'sesion_id y alumno_id requeridos.', 422);
            }
            $cerrada = $pdo->prepare('SELECT cerrada, curso_id FROM asis_sesiones WHERE id = ?');
            $cerrada->execute([$sesionId]);
            $row = $cerrada->fetch();
            if (!$row) {
                respond(false, null, 'Sesión no encontrada.', 404);
            }
            requireCursoAccess($pdo, (int) $row['curso_id']);
            if ((int) $row['cerrada'] === 1) {
                respond(false, null, 'La sesión está cerrada.', 409);
            }
            $st = $pdo->prepare(
                'INSERT INTO asis_registros (sesion_id, alumno_id, estado, observacion, marcado_por, marcado_en)
                 VALUES (?,?,?,?,?,NOW())
                 ON DUPLICATE KEY UPDATE
                   estado = VALUES(estado),
                   observacion = VALUES(observacion),
                   marcado_por = VALUES(marcado_por),
                   marcado_en = NOW()'
            );
            $st->execute([$sesionId, $alumnoId, $estado, $obs, who()]);
            respond(true, ['sesion_id' => $sesionId, 'alumno_id' => $alumnoId, 'estado' => $estado]);

        case 'marcar_lote':
            if ($method !== 'POST') {
                respond(false, null, 'Método no permitido.', 405);
            }
            $sesionId = (int) ($b['sesion_id'] ?? 0);
            $items = isset($b['items']) && is_array($b['items']) ? $b['items'] : [];
            if ($sesionId < 1 || !$items) {
                respond(false, null, 'sesion_id e items requeridos.', 422);
            }
            $cerrada = $pdo->prepare('SELECT cerrada, curso_id FROM asis_sesiones WHERE id = ?');
            $cerrada->execute([$sesionId]);
            $row = $cerrada->fetch();
            if (!$row) {
                respond(false, null, 'Sesión no encontrada.', 404);
            }
            requireCursoAccess($pdo, (int) $row['curso_id']);
            if ((int) $row['cerrada'] === 1) {
                respond(false, null, 'La sesión está cerrada.', 409);
            }
            $st = $pdo->prepare(
                'INSERT INTO asis_registros (sesion_id, alumno_id, estado, observacion, marcado_por, marcado_en)
                 VALUES (?,?,?,?,?,NOW())
                 ON DUPLICATE KEY UPDATE
                   estado = VALUES(estado),
                   observacion = VALUES(observacion),
                   marcado_por = VALUES(marcado_por),
                   marcado_en = NOW()'
            );
            $pdo->beginTransaction();
            foreach ($items as $it) {
                $alumnoId = (int) ($it['alumno_id'] ?? 0);
                if ($alumnoId < 1) {
                    continue;
                }
                $estado = estadoOk(clean((string) ($it['estado'] ?? '')));
                $obs = clean((string) ($it['observacion'] ?? ''));
                $st->execute([$sesionId, $alumnoId, $estado, $obs, who()]);
            }
            $pdo->commit();
            respond(true, ['sesion_id' => $sesionId, 'marcados' => count($items)]);

        default:
            respond(false, null, 'Acción no reconocida.', 400);
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    respond(false, null, 'Error interno: ' . $e->getMessage(), 500);
}
