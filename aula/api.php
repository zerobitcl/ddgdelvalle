<?php
declare(strict_types=1);

/**
 * DDG Del Valle · API Aula Virtual
 *
 * Auth:
 *   POST ?action=login   {usuario|rut, clave}
 *   POST ?action=logout
 *   GET  ?action=me
 *
 * Cursos:
 *   GET  ?action=cursos              → todos (con flag inscrito / rol)
 *   GET  ?action=curso&id=
 *   POST ?action=curso_crear         (admin)
 *   POST ?action=curso_enlace        {curso_id, enlace_clase} (admin|profesor del curso)
 *
 * Usuarios:
 *   GET  ?action=usuarios&rol=       (admin|profesor: alumnos activos)
 *   POST ?action=usuario_crear       (admin) {rut, nombre, email?, clave, rol}
 *
 * Inscripciones:
 *   GET  ?action=inscritos&curso_id=
 *   POST ?action=inscribir           {curso_id, usuario_id, rol_en_curso?}
 *   POST ?action=desinscribir        {curso_id, usuario_id}
 *   GET  ?action=log&curso_id?&limit?
 */

session_name('ddg_aula');
session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require __DIR__ . '/db.php';

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

function rutNormalizar(string $rut): string
{
    $r = strtoupper(preg_replace('/[^0-9kK]/', '', $rut) ?? '');
    if (strlen($r) < 2) {
        return clean($rut);
    }
    $dv  = substr($r, -1);
    $num = substr($r, 0, -1);
    return number_format((int) $num, 0, '', '.') . '-' . $dv;
}

function slugify(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    return trim($s, '-') ?: ('curso-' . bin2hex(random_bytes(3)));
}

function modalidadOk(string $m): string
{
    return in_array($m, ['presencial', 'online', 'mixta'], true) ? $m : 'online';
}

function rolOk(string $r): string
{
    return in_array($r, ['admin', 'profesor', 'alumno'], true) ? $r : 'alumno';
}

function rolCursoOk(string $r): string
{
    return $r === 'profesor' ? 'profesor' : 'alumno';
}

function currentUser(): ?array
{
    return $_SESSION['aula_user'] ?? null;
}

function requireAuth(): array
{
    $u = currentUser();
    if (!$u) {
        respond(false, null, 'Sesión no autorizada.', 401);
    }
    return $u;
}

function requireAdmin(): array
{
    $u = requireAuth();
    if (($u['rol'] ?? '') !== 'admin') {
        respond(false, null, 'Solo administración.', 403);
    }
    return $u;
}

function ensureSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS `aula_usuarios` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `rut` VARCHAR(20) NOT NULL,
        `nombre` VARCHAR(255) NOT NULL,
        `email` VARCHAR(255) NOT NULL DEFAULT '',
        `password_hash` VARCHAR(255) NOT NULL,
        `rol` ENUM('admin','profesor','alumno') NOT NULL DEFAULT 'alumno',
        `activo` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_rut` (`rut`),
        KEY `idx_rol` (`rol`),
        KEY `idx_activo` (`activo`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS `aula_cursos` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `codigo` VARCHAR(64) NOT NULL,
        `nombre` VARCHAR(255) NOT NULL,
        `descripcion` VARCHAR(1000) NOT NULL DEFAULT '',
        `modalidad` ENUM('presencial','online','mixta') NOT NULL DEFAULT 'online',
        `enlace_clase` VARCHAR(1000) NOT NULL DEFAULT '',
        `aula_url` VARCHAR(500) NOT NULL DEFAULT '',
        `activo` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_codigo` (`codigo`),
        KEY `idx_activo` (`activo`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS `aula_inscripciones` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `curso_id` INT UNSIGNED NOT NULL,
        `usuario_id` INT UNSIGNED NOT NULL,
        `rol_en_curso` ENUM('profesor','alumno') NOT NULL DEFAULT 'alumno',
        `inscrito_por` INT UNSIGNED NULL DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_curso_usuario` (`curso_id`, `usuario_id`),
        KEY `idx_usuario` (`usuario_id`),
        KEY `idx_rol_curso` (`curso_id`, `rol_en_curso`),
        CONSTRAINT `fk_aula_ins_curso` FOREIGN KEY (`curso_id`) REFERENCES `aula_cursos` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_aula_ins_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `aula_usuarios` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_aula_ins_por` FOREIGN KEY (`inscrito_por`) REFERENCES `aula_usuarios` (`id`) ON DELETE SET NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS `aula_inscripciones_log` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `curso_id` INT UNSIGNED NOT NULL,
        `usuario_id` INT UNSIGNED NOT NULL,
        `accion` ENUM('inscribir','desinscribir') NOT NULL,
        `rol_en_curso` ENUM('profesor','alumno') NOT NULL,
        `realizado_por` INT UNSIGNED NULL DEFAULT NULL,
        `detalle` VARCHAR(500) NOT NULL DEFAULT '',
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_log_curso` (`curso_id`),
        KEY `idx_log_fecha` (`created_at`),
        CONSTRAINT `fk_aula_log_curso` FOREIGN KEY (`curso_id`) REFERENCES `aula_cursos` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_aula_log_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `aula_usuarios` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_aula_log_por` FOREIGN KEY (`realizado_por`) REFERENCES `aula_usuarios` (`id`) ON DELETE SET NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $n = (int) $pdo->query('SELECT COUNT(*) FROM aula_usuarios')->fetchColumn();
    if ($n === 0) {
        seedDemo($pdo);
    }
}

function seedDemo(PDO $pdo): void
{
    $users = [
        ['11.111.111-1', 'Administrador DDG', 'admin@ddgdelvalle.cl', 'Admin2026*', 'admin'],
        ['12.222.222-2', 'Relator Demo', 'profesor@ddgdelvalle.cl', 'Profe2026*', 'profesor'],
        ['15.432.876-5', 'Alumno Demo', 'alumno@ddgdelvalle.cl', 'Alumno2026*', 'alumno'],
        ['16.543.987-6', 'Alumna Precargada', 'alumna2@ddgdelvalle.cl', 'Alumno2026*', 'alumno'],
    ];

    $insU = $pdo->prepare(
        'INSERT INTO aula_usuarios (rut, nombre, email, password_hash, rol) VALUES (?,?,?,?,?)'
    );
    foreach ($users as [$rut, $nombre, $email, $clave, $rol]) {
        $insU->execute([$rut, $nombre, $email, password_hash($clave, PASSWORD_DEFAULT), $rol]);
    }

    $stmt = $pdo->query('SELECT id, rol FROM aula_usuarios ORDER BY id');
    $byRole = ['admin' => null, 'profesor' => null, 'alumnos' => []];
    foreach ($stmt as $row) {
        if ($row['rol'] === 'alumno') {
            $byRole['alumnos'][] = (int) $row['id'];
        } else {
            $byRole[$row['rol']] = (int) $row['id'];
        }
    }

    $cursos = [
        [
            'riego-tecnificado',
            'Riego Tecnificado y Evapotranspiración',
            'Módulos PPTX, evaluaciones y foro. Curso demo activo.',
            'mixta',
            '../modulo-alumno.php',
        ],
        [
            'gastronomia-online',
            'Gastronomía Online',
            'Curso operativo. Material en habilitación.',
            'online',
            '',
        ],
        [
            'aplicador-plaguicidas',
            'Aplicador de Plaguicidas',
            'Formación normativa. Material en habilitación.',
            'mixta',
            '',
        ],
    ];

    $insC = $pdo->prepare(
        'INSERT INTO aula_cursos (codigo, nombre, descripcion, modalidad, aula_url) VALUES (?,?,?,?,?)'
    );
    $cursoIds = [];
    foreach ($cursos as $c) {
        $insC->execute($c);
        $cursoIds[$c[0]] = (int) $pdo->lastInsertId();
    }

    $adminId = $byRole['admin'];
    $profeId = $byRole['profesor'];
    $riegoId = $cursoIds['riego-tecnificado'];

    $insI = $pdo->prepare(
        'INSERT INTO aula_inscripciones (curso_id, usuario_id, rol_en_curso, inscrito_por) VALUES (?,?,?,?)'
    );
    $log = $pdo->prepare(
        'INSERT INTO aula_inscripciones_log (curso_id, usuario_id, accion, rol_en_curso, realizado_por, detalle)
         VALUES (?,?,?,?,?,?)'
    );

    $insI->execute([$riegoId, $profeId, 'profesor', $adminId]);
    $log->execute([$riegoId, $profeId, 'inscribir', 'profesor', $adminId, 'Asignación inicial demo']);

    foreach ($byRole['alumnos'] as $i => $alumnoId) {
        // Solo el primer alumno queda inscrito; el segundo queda precargado para que el profe lo inscriba
        if ($i === 0) {
            $insI->execute([$riegoId, $alumnoId, 'alumno', $adminId]);
            $log->execute([$riegoId, $alumnoId, 'inscribir', 'alumno', $adminId, 'Inscripción inicial demo']);
        }
    }
}

function publicUser(array $row): array
{
    return [
        'id'     => (int) $row['id'],
        'rut'    => $row['rut'],
        'nombre' => $row['nombre'],
        'email'  => $row['email'] ?? '',
        'rol'    => $row['rol'],
    ];
}

function puedeGestionarCurso(PDO $pdo, array $user, int $cursoId): bool
{
    if (($user['rol'] ?? '') === 'admin') {
        return true;
    }
    if (($user['rol'] ?? '') !== 'profesor') {
        return false;
    }
    $st = $pdo->prepare(
        "SELECT 1 FROM aula_inscripciones
         WHERE curso_id = ? AND usuario_id = ? AND rol_en_curso = 'profesor' LIMIT 1"
    );
    $st->execute([$cursoId, (int) $user['id']]);
    return (bool) $st->fetchColumn();
}

function estaInscrito(PDO $pdo, int $cursoId, int $usuarioId): ?array
{
    $st = $pdo->prepare(
        'SELECT id, rol_en_curso, created_at FROM aula_inscripciones
         WHERE curso_id = ? AND usuario_id = ? LIMIT 1'
    );
    $st->execute([$cursoId, $usuarioId]);
    $row = $st->fetch();
    return $row ?: null;
}

function registrarLog(
    PDO $pdo,
    int $cursoId,
    int $usuarioId,
    string $accion,
    string $rolCurso,
    ?int $por,
    string $detalle = ''
): void {
    $st = $pdo->prepare(
        'INSERT INTO aula_inscripciones_log
         (curso_id, usuario_id, accion, rol_en_curso, realizado_por, detalle)
         VALUES (?,?,?,?,?,?)'
    );
    $st->execute([$cursoId, $usuarioId, $accion, $rolCurso, $por, $detalle]);
}

try {
    $pdo = aula_pdo();
    ensureSchema($pdo);

    $action = clean((string) ($_GET['action'] ?? $_POST['action'] ?? ''));
    if ($action === '') {
        respond(false, null, 'Acción requerida.', 400);
    }

    // ── Auth ──────────────────────────────────────────
    if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $b = body();
        $userIn = clean((string) ($b['usuario'] ?? $b['rut'] ?? ''));
        $clave  = (string) ($b['clave'] ?? '');
        if ($userIn === '' || $clave === '') {
            respond(false, null, 'Usuario y contraseña requeridos.', 400);
        }

        $rutTry = rutNormalizar($userIn);
        $st = $pdo->prepare(
            'SELECT * FROM aula_usuarios
             WHERE activo = 1 AND (rut = ? OR rut = ? OR email = ? OR LOWER(nombre) = LOWER(?))
             LIMIT 1'
        );
        $st->execute([$userIn, $rutTry, $userIn, $userIn]);
        $row = $st->fetch();

        // Alias cortos demo
        if (!$row) {
            $alias = [
                'admin' => '11.111.111-1',
                'profesor' => '12.222.222-2',
                'profe' => '12.222.222-2',
                'alumno' => '15.432.876-5',
            ];
            $key = mb_strtolower($userIn, 'UTF-8');
            if (isset($alias[$key])) {
                $st = $pdo->prepare('SELECT * FROM aula_usuarios WHERE rut = ? AND activo = 1 LIMIT 1');
                $st->execute([$alias[$key]]);
                $row = $st->fetch();
            }
        }

        if (!$row || !password_verify($clave, $row['password_hash'])) {
            respond(false, null, 'Credenciales incorrectas.', 401);
        }

        $pub = publicUser($row);
        $_SESSION['aula_user'] = $pub;
        respond(true, ['user' => $pub]);
    }

    if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
        }
        session_destroy();
        respond(true, ['logout' => true]);
    }

    if ($action === 'me') {
        $u = currentUser();
        if (!$u) {
            respond(false, null, 'Sin sesión.', 401);
        }
        respond(true, ['user' => $u]);
    }

    // ── Cursos ────────────────────────────────────────
    if ($action === 'cursos') {
        $u = requireAuth();
        $rows = $pdo->query(
            'SELECT c.*,
                    (SELECT COUNT(*) FROM aula_inscripciones i WHERE i.curso_id = c.id AND i.rol_en_curso = \'alumno\') AS alumnos_n,
                    (SELECT COUNT(*) FROM aula_inscripciones i WHERE i.curso_id = c.id AND i.rol_en_curso = \'profesor\') AS profesores_n
             FROM aula_cursos c
             WHERE c.activo = 1
             ORDER BY c.nombre'
        )->fetchAll();

        $out = [];
        foreach ($rows as $c) {
            $ins = estaInscrito($pdo, (int) $c['id'], (int) $u['id']);
            $esAdmin = ($u['rol'] === 'admin');
            $puede = $esAdmin || (bool) $ins;
            $item = [
                'id'            => (int) $c['id'],
                'codigo'        => $c['codigo'],
                'nombre'        => $c['nombre'],
                'descripcion'   => $c['descripcion'],
                'modalidad'     => $c['modalidad'],
                'alumnos_n'     => (int) $c['alumnos_n'],
                'profesores_n'  => (int) $c['profesores_n'],
                'inscrito'      => (bool) $ins || $esAdmin,
                'rol_en_curso'  => $esAdmin ? 'admin' : ($ins['rol_en_curso'] ?? null),
                'puede_acceder' => $puede,
                'tiene_enlace'  => $c['enlace_clase'] !== '',
            ];
            // Solo inscritos/admin ven el link
            if ($puede) {
                $item['enlace_clase'] = $c['enlace_clase'];
                $item['aula_url'] = $c['aula_url'];
            }
            $out[] = $item;
        }
        respond(true, ['cursos' => $out]);
    }

    if ($action === 'curso') {
        $u = requireAuth();
        $id = (int) ($_GET['id'] ?? 0);
        if ($id < 1) {
            respond(false, null, 'curso_id inválido.', 400);
        }
        $st = $pdo->prepare('SELECT * FROM aula_cursos WHERE id = ? AND activo = 1 LIMIT 1');
        $st->execute([$id]);
        $c = $st->fetch();
        if (!$c) {
            respond(false, null, 'Curso no encontrado.', 404);
        }

        $ins = estaInscrito($pdo, $id, (int) $u['id']);
        $esAdmin = ($u['rol'] === 'admin');
        $puede = $esAdmin || (bool) $ins;
        if (!$puede) {
            respond(false, [
                'curso' => [
                    'id' => (int) $c['id'],
                    'nombre' => $c['nombre'],
                    'puede_acceder' => false,
                    'motivo' => 'No estás inscrito en este curso.',
                ],
            ], 'Sin acceso al curso.', 403);
        }

        $inscritos = $pdo->prepare(
            'SELECT i.id, i.rol_en_curso, i.created_at, u.id AS usuario_id, u.rut, u.nombre, u.email, u.rol
             FROM aula_inscripciones i
             JOIN aula_usuarios u ON u.id = i.usuario_id
             WHERE i.curso_id = ?
             ORDER BY i.rol_en_curso DESC, u.nombre'
        );
        $inscritos->execute([$id]);

        respond(true, [
            'curso' => [
                'id'            => (int) $c['id'],
                'codigo'        => $c['codigo'],
                'nombre'        => $c['nombre'],
                'descripcion'   => $c['descripcion'],
                'modalidad'     => $c['modalidad'],
                'enlace_clase'  => $c['enlace_clase'],
                'aula_url'      => $c['aula_url'],
                'puede_acceder' => true,
                'rol_en_curso'  => $esAdmin ? 'admin' : $ins['rol_en_curso'],
                'puede_gestionar' => puedeGestionarCurso($pdo, $u, $id),
            ],
            'inscritos' => array_map(static function ($r) {
                return [
                    'inscripcion_id' => (int) $r['id'],
                    'usuario_id'     => (int) $r['usuario_id'],
                    'rut'            => $r['rut'],
                    'nombre'         => $r['nombre'],
                    'email'          => $r['email'],
                    'rol_en_curso'   => $r['rol_en_curso'],
                    'created_at'     => $r['created_at'],
                ];
            }, $inscritos->fetchAll()),
        ]);
    }

    if ($action === 'curso_crear' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requireAdmin();
        $b = body();
        $nombre = clean((string) ($b['nombre'] ?? ''));
        if ($nombre === '') {
            respond(false, null, 'Nombre del curso requerido.', 400);
        }
        $codigo = clean((string) ($b['codigo'] ?? ''));
        if ($codigo === '') {
            $codigo = slugify($nombre);
        }
        $desc = clean((string) ($b['descripcion'] ?? ''));
        $mod  = modalidadOk((string) ($b['modalidad'] ?? 'online'));
        $aula = clean((string) ($b['aula_url'] ?? ''));

        try {
            $st = $pdo->prepare(
                'INSERT INTO aula_cursos (codigo, nombre, descripcion, modalidad, aula_url) VALUES (?,?,?,?,?)'
            );
            $st->execute([$codigo, $nombre, $desc, $mod, $aula]);
        } catch (PDOException $e) {
            respond(false, null, 'No se pudo crear (código duplicado?).', 409);
        }
        respond(true, ['id' => (int) $pdo->lastInsertId()]);
    }

    if ($action === 'curso_enlace' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $u = requireAuth();
        $b = body();
        $cursoId = (int) ($b['curso_id'] ?? 0);
        $enlace  = clean((string) ($b['enlace_clase'] ?? ''));
        if ($cursoId < 1) {
            respond(false, null, 'curso_id inválido.', 400);
        }
        if ($enlace !== '' && !preg_match('#^https?://#i', $enlace)) {
            respond(false, null, 'El enlace debe empezar con https://', 400);
        }
        if (!puedeGestionarCurso($pdo, $u, $cursoId)) {
            respond(false, null, 'No puedes editar el enlace de este curso.', 403);
        }
        $st = $pdo->prepare('UPDATE aula_cursos SET enlace_clase = ? WHERE id = ?');
        $st->execute([$enlace, $cursoId]);
        respond(true, ['curso_id' => $cursoId, 'enlace_clase' => $enlace]);
    }

    // ── Usuarios ──────────────────────────────────────
    if ($action === 'usuarios') {
        $u = requireAuth();
        if (!in_array($u['rol'], ['admin', 'profesor'], true)) {
            respond(false, null, 'Sin permiso.', 403);
        }
        $rol = clean((string) ($_GET['rol'] ?? ''));
        if ($u['rol'] === 'profesor') {
            // El profesor solo lista alumnos precargados
            $rol = 'alumno';
        }
        if ($rol !== '' && !in_array($rol, ['admin', 'profesor', 'alumno'], true)) {
            respond(false, null, 'rol inválido.', 400);
        }

        if ($rol === '') {
            $rows = $pdo->query(
                'SELECT id, rut, nombre, email, rol, activo, created_at FROM aula_usuarios ORDER BY rol, nombre'
            )->fetchAll();
        } else {
            $st = $pdo->prepare(
                'SELECT id, rut, nombre, email, rol, activo, created_at FROM aula_usuarios WHERE rol = ? ORDER BY nombre'
            );
            $st->execute([$rol]);
            $rows = $st->fetchAll();
        }
        respond(true, [
            'usuarios' => array_map(static function ($r) {
                return [
                    'id'     => (int) $r['id'],
                    'rut'    => $r['rut'],
                    'nombre' => $r['nombre'],
                    'email'  => $r['email'],
                    'rol'    => $r['rol'],
                    'activo' => (bool) $r['activo'],
                    'created_at' => $r['created_at'],
                ];
            }, $rows),
        ]);
    }

    if ($action === 'usuario_crear' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requireAdmin();
        $b = body();
        $rut    = rutNormalizar((string) ($b['rut'] ?? ''));
        $nombre = clean((string) ($b['nombre'] ?? ''));
        $email  = clean((string) ($b['email'] ?? ''));
        $clave  = (string) ($b['clave'] ?? '');
        $rol    = rolOk((string) ($b['rol'] ?? 'alumno'));

        if ($rut === '' || $nombre === '' || strlen($clave) < 6) {
            respond(false, null, 'RUT, nombre y clave (mín. 6) son requeridos.', 400);
        }

        try {
            $st = $pdo->prepare(
                'INSERT INTO aula_usuarios (rut, nombre, email, password_hash, rol) VALUES (?,?,?,?,?)'
            );
            $st->execute([$rut, $nombre, $email, password_hash($clave, PASSWORD_DEFAULT), $rol]);
        } catch (PDOException $e) {
            respond(false, null, 'RUT ya existe en el sistema.', 409);
        }
        respond(true, ['id' => (int) $pdo->lastInsertId(), 'rut' => $rut, 'rol' => $rol]);
    }

    // ── Inscripciones ─────────────────────────────────
    if ($action === 'inscritos') {
        $u = requireAuth();
        $cursoId = (int) ($_GET['curso_id'] ?? 0);
        if ($cursoId < 1) {
            respond(false, null, 'curso_id inválido.', 400);
        }
        if (!puedeGestionarCurso($pdo, $u, $cursoId) && $u['rol'] !== 'admin') {
            // alumno inscrito puede ver lista básica
            if (!estaInscrito($pdo, $cursoId, (int) $u['id'])) {
                respond(false, null, 'Sin acceso.', 403);
            }
        }
        $st = $pdo->prepare(
            'SELECT i.id, i.rol_en_curso, i.created_at, u.id AS usuario_id, u.rut, u.nombre, u.email
             FROM aula_inscripciones i
             JOIN aula_usuarios u ON u.id = i.usuario_id
             WHERE i.curso_id = ?
             ORDER BY i.rol_en_curso DESC, u.nombre'
        );
        $st->execute([$cursoId]);
        respond(true, ['inscritos' => $st->fetchAll()]);
    }

    if ($action === 'inscribir' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $u = requireAuth();
        $b = body();
        $cursoId   = (int) ($b['curso_id'] ?? 0);
        $usuarioId = (int) ($b['usuario_id'] ?? 0);
        $rolCurso  = rolCursoOk((string) ($b['rol_en_curso'] ?? 'alumno'));

        if ($cursoId < 1 || $usuarioId < 1) {
            respond(false, null, 'curso_id y usuario_id requeridos.', 400);
        }
        if (!puedeGestionarCurso($pdo, $u, $cursoId)) {
            respond(false, null, 'No puedes inscribir en este curso.', 403);
        }

        // Profesor solo puede inscribir alumnos (no asignar otros profesores)
        if ($u['rol'] === 'profesor' && $rolCurso !== 'alumno') {
            respond(false, null, 'El profesor solo puede inscribir alumnos.', 403);
        }

        $tu = $pdo->prepare('SELECT id, rol, nombre, activo FROM aula_usuarios WHERE id = ? LIMIT 1');
        $tu->execute([$usuarioId]);
        $target = $tu->fetch();
        if (!$target || !(int) $target['activo']) {
            respond(false, null, 'Usuario no encontrado o inactivo.', 404);
        }
        if ($rolCurso === 'alumno' && $target['rol'] !== 'alumno') {
            respond(false, null, 'El usuario debe estar cargado como alumno.', 400);
        }
        if ($rolCurso === 'profesor' && !in_array($target['rol'], ['profesor', 'admin'], true)) {
            respond(false, null, 'El usuario debe ser profesor.', 400);
        }

        if (estaInscrito($pdo, $cursoId, $usuarioId)) {
            respond(false, null, 'Ya está inscrito en este curso.', 409);
        }

        $st = $pdo->prepare(
            'INSERT INTO aula_inscripciones (curso_id, usuario_id, rol_en_curso, inscrito_por) VALUES (?,?,?,?)'
        );
        $st->execute([$cursoId, $usuarioId, $rolCurso, (int) $u['id']]);
        registrarLog(
            $pdo,
            $cursoId,
            $usuarioId,
            'inscribir',
            $rolCurso,
            (int) $u['id'],
            'Inscrito por ' . $u['nombre']
        );

        respond(true, [
            'inscripcion_id' => (int) $pdo->lastInsertId(),
            'curso_id' => $cursoId,
            'usuario_id' => $usuarioId,
            'rol_en_curso' => $rolCurso,
        ]);
    }

    if ($action === 'desinscribir' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $u = requireAuth();
        $b = body();
        $cursoId   = (int) ($b['curso_id'] ?? 0);
        $usuarioId = (int) ($b['usuario_id'] ?? 0);
        if ($cursoId < 1 || $usuarioId < 1) {
            respond(false, null, 'curso_id y usuario_id requeridos.', 400);
        }
        if (!puedeGestionarCurso($pdo, $u, $cursoId)) {
            respond(false, null, 'No puedes desinscribir en este curso.', 403);
        }

        $ins = estaInscrito($pdo, $cursoId, $usuarioId);
        if (!$ins) {
            respond(false, null, 'No está inscrito.', 404);
        }
        if ($u['rol'] === 'profesor' && $ins['rol_en_curso'] === 'profesor') {
            respond(false, null, 'El profesor no puede desasignar profesores.', 403);
        }

        $st = $pdo->prepare('DELETE FROM aula_inscripciones WHERE curso_id = ? AND usuario_id = ?');
        $st->execute([$cursoId, $usuarioId]);
        registrarLog(
            $pdo,
            $cursoId,
            $usuarioId,
            'desinscribir',
            $ins['rol_en_curso'],
            (int) $u['id'],
            'Baja por ' . $u['nombre']
        );
        respond(true, ['removed' => true]);
    }

    if ($action === 'log') {
        $u = requireAuth();
        if (!in_array($u['rol'], ['admin', 'profesor'], true)) {
            respond(false, null, 'Sin permiso.', 403);
        }
        $cursoId = (int) ($_GET['curso_id'] ?? 0);
        $limit   = min(200, max(10, (int) ($_GET['limit'] ?? 50)));

        if ($u['rol'] === 'profesor') {
            // Solo logs de cursos donde es profesor
            $sql = "SELECT l.*, c.nombre AS curso_nombre, u.nombre AS usuario_nombre, u.rut AS usuario_rut,
                           p.nombre AS realizado_por_nombre
                    FROM aula_inscripciones_log l
                    JOIN aula_cursos c ON c.id = l.curso_id
                    JOIN aula_usuarios u ON u.id = l.usuario_id
                    LEFT JOIN aula_usuarios p ON p.id = l.realizado_por
                    WHERE l.curso_id IN (
                      SELECT curso_id FROM aula_inscripciones
                      WHERE usuario_id = ? AND rol_en_curso = 'profesor'
                    )";
            $params = [(int) $u['id']];
            if ($cursoId > 0) {
                $sql .= ' AND l.curso_id = ?';
                $params[] = $cursoId;
            }
            $sql .= ' ORDER BY l.created_at DESC LIMIT ' . $limit;
            $st = $pdo->prepare($sql);
            $st->execute($params);
        } else {
            $sql = "SELECT l.*, c.nombre AS curso_nombre, u.nombre AS usuario_nombre, u.rut AS usuario_rut,
                           p.nombre AS realizado_por_nombre
                    FROM aula_inscripciones_log l
                    JOIN aula_cursos c ON c.id = l.curso_id
                    JOIN aula_usuarios u ON u.id = l.usuario_id
                    LEFT JOIN aula_usuarios p ON p.id = l.realizado_por";
            $params = [];
            if ($cursoId > 0) {
                $sql .= ' WHERE l.curso_id = ?';
                $params[] = $cursoId;
            }
            $sql .= ' ORDER BY l.created_at DESC LIMIT ' . $limit;
            $st = $pdo->prepare($sql);
            $st->execute($params);
        }

        respond(true, [
            'log' => array_map(static function ($r) {
                return [
                    'id' => (int) $r['id'],
                    'curso_id' => (int) $r['curso_id'],
                    'curso_nombre' => $r['curso_nombre'],
                    'usuario_id' => (int) $r['usuario_id'],
                    'usuario_nombre' => $r['usuario_nombre'],
                    'usuario_rut' => $r['usuario_rut'],
                    'accion' => $r['accion'],
                    'rol_en_curso' => $r['rol_en_curso'],
                    'realizado_por' => $r['realizado_por_nombre'] ?? '—',
                    'detalle' => $r['detalle'],
                    'created_at' => $r['created_at'],
                ];
            }, $st->fetchAll()),
        ]);
    }

    if ($action === 'alumnos_disponibles') {
        $u = requireAuth();
        $cursoId = (int) ($_GET['curso_id'] ?? 0);
        if ($cursoId < 1) {
            respond(false, null, 'curso_id inválido.', 400);
        }
        if (!puedeGestionarCurso($pdo, $u, $cursoId)) {
            respond(false, null, 'Sin permiso.', 403);
        }
        $st = $pdo->prepare(
            "SELECT u.id, u.rut, u.nombre, u.email
             FROM aula_usuarios u
             WHERE u.rol = 'alumno' AND u.activo = 1
               AND u.id NOT IN (
                 SELECT usuario_id FROM aula_inscripciones WHERE curso_id = ?
               )
             ORDER BY u.nombre"
        );
        $st->execute([$cursoId]);
        respond(true, ['alumnos' => $st->fetchAll()]);
    }

    respond(false, null, 'Acción desconocida: ' . $action, 400);
} catch (Throwable $e) {
    respond(false, null, 'Error del servidor: ' . $e->getMessage(), 500);
}
