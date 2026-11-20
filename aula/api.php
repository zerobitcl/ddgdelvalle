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
 *   POST ?action=curso_video         {curso_id, video_url, video_titulo?} (admin|profesor del curso)
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

    // Migración suave: columnas de video grabado
    $cols = $pdo->query('SHOW COLUMNS FROM aula_cursos')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('video_url', $cols, true)) {
        $pdo->exec("ALTER TABLE `aula_cursos` ADD COLUMN `video_url` VARCHAR(1500) NOT NULL DEFAULT '' AFTER `aula_url`");
    }
    if (!in_array('video_titulo', $cols, true)) {
        $pdo->exec("ALTER TABLE `aula_cursos` ADD COLUMN `video_titulo` VARCHAR(255) NOT NULL DEFAULT '' AFTER `video_url`");
    }

    $cols = $pdo->query('SHOW COLUMNS FROM aula_cursos')->fetchAll(PDO::FETCH_COLUMN);
    $extra = [
        'portada'      => "VARCHAR(500) NOT NULL DEFAULT ''",
        'relator'      => "VARCHAR(255) NOT NULL DEFAULT ''",
        'programa_pdf' => "VARCHAR(1500) NOT NULL DEFAULT ''",
        'bajada'       => "VARCHAR(500) NOT NULL DEFAULT ''",
        'sincrono'     => "TINYINT(1) NOT NULL DEFAULT 0",
        'valoracion'   => "TINYINT UNSIGNED NOT NULL DEFAULT 0",
    ];
    foreach ($extra as $col => $def) {
        if (!in_array($col, $cols, true)) {
            $pdo->exec("ALTER TABLE `aula_cursos` ADD COLUMN `$col` $def");
        }
    }

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS `aula_modulos` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `curso_id` INT UNSIGNED NOT NULL,
        `titulo` VARCHAR(255) NOT NULL,
        `tipo` ENUM('video','archivo','enlace') NOT NULL DEFAULT 'video',
        `url` VARCHAR(1500) NOT NULL DEFAULT '',
        `orden` INT NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_mod_curso` (`curso_id`, `orden`),
        CONSTRAINT `fk_aula_mod_curso` FOREIGN KEY (`curso_id`) REFERENCES `aula_cursos` (`id`) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS `aula_progreso` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `usuario_id` INT UNSIGNED NOT NULL,
        `modulo_id` INT UNSIGNED NOT NULL,
        `completado_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_prog` (`usuario_id`, `modulo_id`),
        CONSTRAINT `fk_aula_prog_user` FOREIGN KEY (`usuario_id`) REFERENCES `aula_usuarios` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_aula_prog_mod` FOREIGN KEY (`modulo_id`) REFERENCES `aula_modulos` (`id`) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS `aula_foro` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `curso_id` INT UNSIGNED NOT NULL,
        `usuario_id` INT UNSIGNED NOT NULL,
        `mensaje` VARCHAR(1000) NOT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_foro_curso` (`curso_id`, `created_at`),
        CONSTRAINT `fk_aula_foro_curso` FOREIGN KEY (`curso_id`) REFERENCES `aula_cursos` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_aula_foro_user` FOREIGN KEY (`usuario_id`) REFERENCES `aula_usuarios` (`id`) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS `aula_suscripciones` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `usuario_id` INT UNSIGNED NOT NULL,
        `tipo` ENUM('mensual','diario') NOT NULL DEFAULT 'mensual',
        `meses` INT UNSIGNED NOT NULL DEFAULT 0,
        `detalle` VARCHAR(500) NOT NULL DEFAULT '',
        `vigente_desde` DATE NULL DEFAULT NULL,
        `vigente_hasta` DATE NULL DEFAULT NULL,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_sus_user` (`usuario_id`),
        CONSTRAINT `fk_aula_sus_user` FOREIGN KEY (`usuario_id`) REFERENCES `aula_usuarios` (`id`) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS `aula_certificados` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `usuario_id` INT UNSIGNED NOT NULL,
        `curso_id` INT UNSIGNED NOT NULL,
        `emitido_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_cert` (`usuario_id`, `curso_id`),
        CONSTRAINT `fk_aula_cert_user` FOREIGN KEY (`usuario_id`) REFERENCES `aula_usuarios` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_aula_cert_curso` FOREIGN KEY (`curso_id`) REFERENCES `aula_cursos` (`id`) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS `aula_solicitudes` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `usuario_id` INT UNSIGNED NOT NULL,
        `mensaje` VARCHAR(1000) NOT NULL,
        `estado` ENUM('pendiente','revisada') NOT NULL DEFAULT 'pendiente',
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_sol_user` (`usuario_id`, `created_at`),
        CONSTRAINT `fk_aula_sol_user` FOREIGN KEY (`usuario_id`) REFERENCES `aula_usuarios` (`id`) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS `aula_accesos` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `usuario_id` INT UNSIGNED NOT NULL,
        `curso_id` INT UNSIGNED NOT NULL,
        `ultimo_acceso` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `veces` INT UNSIGNED NOT NULL DEFAULT 1,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_acceso` (`usuario_id`, `curso_id`),
        CONSTRAINT `fk_aula_acc_user` FOREIGN KEY (`usuario_id`) REFERENCES `aula_usuarios` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_aula_acc_curso` FOREIGN KEY (`curso_id`) REFERENCES `aula_cursos` (`id`) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $n = (int) $pdo->query('SELECT COUNT(*) FROM aula_usuarios')->fetchColumn();
    if ($n === 0) {
        seedDemo($pdo);
    }

    ensureGastronomiaClase1($pdo);
}

/**
 * Clase 1 Gastronomía · usuario compartido Curso1 / gastr2026
 * Idempotente: se puede llamar en cada request sin duplicar.
 */
function ensureGastronomiaClase1(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    // Video alojado en el aula (sin OneDrive / sin login Microsoft)
    $videoUrl = 'stream.php?f=clase1-gastronomia.mp4';
    $videoTitulo = 'Clase 1 · Tiradito de zapallo italiano y dolmas';

    $st = $pdo->prepare('SELECT id, video_url FROM aula_cursos WHERE codigo = ? LIMIT 1');
    $st->execute(['gastronomia-online']);
    $curso = $st->fetch();

    if (!$curso) {
        $insC = $pdo->prepare(
            'INSERT INTO aula_cursos (codigo, nombre, descripcion, modalidad, aula_url, video_url, video_titulo)
             VALUES (?,?,?,?,?,?,?)'
        );
        $insC->execute([
            'gastronomia-online',
            'Gastronomía Online',
            'Clases grabadas del curso de gastronomía DDG Del Valle 2026.',
            'online',
            '',
            $videoUrl,
            $videoTitulo,
        ]);
        $cursoId = (int) $pdo->lastInsertId();
    } else {
        $cursoId = (int) $curso['id'];
        $current = (string) ($curso['video_url'] ?? '');
        // Migrar OneDrive / vacío → stream local del aula
        if ($current === '' || str_contains($current, '1drv.ms') || str_contains($current, 'onedrive')) {
            $upd = $pdo->prepare('UPDATE aula_cursos SET video_url = ?, video_titulo = ?, descripcion = ? WHERE id = ?');
            $upd->execute([
                $videoUrl,
                $videoTitulo,
                'Clases grabadas del curso de gastronomía DDG Del Valle 2026.',
                $cursoId,
            ]);
        }
    }

    $stU = $pdo->prepare('SELECT id, password_hash FROM aula_usuarios WHERE rut = ? LIMIT 1');
    $stU->execute(['Curso1']);
    $user = $stU->fetch();

    if (!$user) {
        $insU = $pdo->prepare(
            'INSERT INTO aula_usuarios (rut, nombre, email, password_hash, rol) VALUES (?,?,?,?,?)'
        );
        $insU->execute([
            'Curso1',
            'Alumnos Gastronomía · Curso 1',
            'curso1@ddgdelvalle.cl',
            password_hash('gastr2026', PASSWORD_DEFAULT),
            'alumno',
        ]);
        $userId = (int) $pdo->lastInsertId();
    } else {
        $userId = (int) $user['id'];
        if (!password_verify('gastr2026', (string) $user['password_hash'])) {
            $pdo->prepare('UPDATE aula_usuarios SET password_hash = ?, activo = 1 WHERE id = ?')
                ->execute([password_hash('gastr2026', PASSWORD_DEFAULT), $userId]);
        }
    }

    $ins = estaInscrito($pdo, $cursoId, $userId);
    if (!$ins) {
        $adminId = (int) ($pdo->query("SELECT id FROM aula_usuarios WHERE rol = 'admin' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $insI = $pdo->prepare(
            'INSERT INTO aula_inscripciones (curso_id, usuario_id, rol_en_curso, inscrito_por) VALUES (?,?,?,?)'
        );
        $insI->execute([$cursoId, $userId, 'alumno', $adminId ?: null]);
        registrarLog($pdo, $cursoId, $userId, 'inscribir', 'alumno', $adminId ?: null, 'Acceso compartido Curso1');
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

function fechaOk(?string $v): ?string
{
    $v = trim((string) $v);
    if ($v === '') {
        return null;
    }
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : null;
}

function urlMaterialOk(string $url): bool
{
    if ($url === '') {
        return true;
    }
    if (preg_match('#^https?://#i', $url)) {
        return true;
    }
    return (bool) preg_match('#^(stream\.php\?|materiales/)[\w./?=&%-]+$#', $url);
}

function esSincrono(array $c): bool
{
    return (int) ($c['sincrono'] ?? 0) === 1 || trim((string) ($c['enlace_clase'] ?? '')) !== '';
}

function fichaCurso(array $c): array
{
    return [
        'portada'      => $c['portada'] ?? '',
        'relator'      => $c['relator'] ?? '',
        'programa_pdf' => $c['programa_pdf'] ?? '',
        'bajada'       => $c['bajada'] ?? '',
        'valoracion'   => (int) ($c['valoracion'] ?? 0),
        'es_sincrono'  => esSincrono($c),
    ];
}

function registrarAcceso(PDO $pdo, int $cursoId, int $usuarioId): void
{
    $st = $pdo->prepare(
        'INSERT INTO aula_accesos (usuario_id, curso_id, ultimo_acceso, veces)
         VALUES (?,?,NOW(),1)
         ON DUPLICATE KEY UPDATE ultimo_acceso = NOW(), veces = veces + 1'
    );
    $st->execute([$usuarioId, $cursoId]);
}

function modulosDeCurso(PDO $pdo, int $cursoId, int $usuarioId): array
{
    $st = $pdo->prepare(
        'SELECT m.id, m.titulo, m.tipo, m.url, m.orden,
                p.completado_at
         FROM aula_modulos m
         LEFT JOIN aula_progreso p ON p.modulo_id = m.id AND p.usuario_id = ?
         WHERE m.curso_id = ?
         ORDER BY m.orden, m.id'
    );
    $st->execute([$usuarioId, $cursoId]);
    return array_map(static function ($r) {
        return [
            'id'          => (int) $r['id'],
            'titulo'      => $r['titulo'],
            'tipo'        => $r['tipo'],
            'url'         => $r['url'],
            'orden'       => (int) $r['orden'],
            'completado'  => $r['completado_at'] !== null,
        ];
    }, $st->fetchAll());
}

function cursoCompleto(PDO $pdo, int $cursoId, int $usuarioId): bool
{
    $tiene = $pdo->prepare('SELECT 1 FROM aula_certificados WHERE curso_id = ? AND usuario_id = ? LIMIT 1');
    $tiene->execute([$cursoId, $usuarioId]);
    if ($tiene->fetchColumn()) {
        return true;
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM aula_modulos WHERE curso_id = ?');
    $st->execute([$cursoId]);
    $total = (int) $st->fetchColumn();
    if ($total < 1) {
        return false;
    }
    $he = $pdo->prepare(
        'SELECT COUNT(*) FROM aula_progreso p
         JOIN aula_modulos m ON m.id = p.modulo_id
         WHERE m.curso_id = ? AND p.usuario_id = ?'
    );
    $he->execute([$cursoId, $usuarioId]);
    return (int) $he->fetchColumn() >= $total;
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
                'curso1' => 'Curso1',
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
        $uid = (int) $u['id'];
        $stCursos = $pdo->prepare(
            'SELECT c.*,
                    (SELECT COUNT(*) FROM aula_inscripciones i WHERE i.curso_id = c.id AND i.rol_en_curso = \'alumno\') AS alumnos_n,
                    (SELECT COUNT(*) FROM aula_inscripciones i WHERE i.curso_id = c.id AND i.rol_en_curso = \'profesor\') AS profesores_n,
                    (SELECT COUNT(*) FROM aula_modulos m WHERE m.curso_id = c.id) AS modulos_n,
                    (SELECT COUNT(*) FROM aula_progreso p
                       JOIN aula_modulos m ON m.id = p.modulo_id
                      WHERE m.curso_id = c.id AND p.usuario_id = ?) AS hechos
             FROM aula_cursos c
             WHERE c.activo = 1
             ORDER BY c.nombre'
        );
        $stCursos->execute([$uid]);
        $rows = $stCursos->fetchAll();

        $out = [];
        foreach ($rows as $c) {
            $ins = estaInscrito($pdo, (int) $c['id'], $uid);
            $esAdmin = ($u['rol'] === 'admin');
            $puede = $esAdmin || (bool) $ins;
            $total = (int) $c['modulos_n'];
            $hechos = (int) $c['hechos'];
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
                'tiene_video'   => ($c['video_url'] ?? '') !== '',
                'modulos_n'     => $total,
                'completados'   => $hechos,
                'avance'        => $total > 0 ? (int) round($hechos * 100 / $total) : 0,
            ] + fichaCurso($c);
            if ($puede) {
                $item['enlace_clase'] = $c['enlace_clase'];
                $item['aula_url'] = $c['aula_url'];
                $item['video_url'] = $c['video_url'] ?? '';
                $item['video_titulo'] = $c['video_titulo'] ?? '';
            } else {
                unset($item['programa_pdf']);
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
        registrarAcceso($pdo, $id, (int) $u['id']);
        $modulos = modulosDeCurso($pdo, $id, (int) $u['id']);
        $hechos = count(array_filter($modulos, static fn ($m) => $m['completado']));

        respond(true, [
            'curso' => [
                'id'            => (int) $c['id'],
                'codigo'        => $c['codigo'],
                'nombre'        => $c['nombre'],
                'descripcion'   => $c['descripcion'],
                'modalidad'     => $c['modalidad'],
                'enlace_clase'  => $c['enlace_clase'],
                'aula_url'      => $c['aula_url'],
                'video_url'     => $c['video_url'] ?? '',
                'video_titulo'  => $c['video_titulo'] ?? '',
                'puede_acceder' => true,
                'rol_en_curso'  => $esAdmin ? 'admin' : $ins['rol_en_curso'],
                'puede_gestionar' => puedeGestionarCurso($pdo, $u, $id),
                'modulos_n'     => count($modulos),
                'completados'   => $hechos,
                'avance'        => count($modulos) > 0 ? (int) round($hechos * 100 / count($modulos)) : 0,
                'certificado'   => cursoCompleto($pdo, $id, (int) $u['id']),
            ] + fichaCurso($c),
            'modulos' => $modulos,
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

    if ($action === 'curso_video' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $u = requireAuth();
        $b = body();
        $cursoId = (int) ($b['curso_id'] ?? 0);
        $video   = clean((string) ($b['video_url'] ?? ''));
        $titulo  = clean((string) ($b['video_titulo'] ?? ''));
        if ($cursoId < 1) {
            respond(false, null, 'curso_id inválido.', 400);
        }
        if ($video !== '' && !urlMaterialOk($video)) {
            respond(false, null, 'La URL del video debe ser https:// o un archivo del aula.', 400);
        }
        if (!puedeGestionarCurso($pdo, $u, $cursoId)) {
            respond(false, null, 'No puedes editar el video de este curso.', 403);
        }
        $st = $pdo->prepare('UPDATE aula_cursos SET video_url = ?, video_titulo = ? WHERE id = ?');
        $st->execute([$video, $titulo, $cursoId]);
        respond(true, ['curso_id' => $cursoId, 'video_url' => $video, 'video_titulo' => $titulo]);
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

    if ($action === 'curso_ficha' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $u = requireAuth();
        $b = body();
        $cursoId = (int) ($b['curso_id'] ?? 0);
        if ($cursoId < 1 || !puedeGestionarCurso($pdo, $u, $cursoId)) {
            respond(false, null, 'No puedes editar este curso.', 403);
        }
        $portada = clean((string) ($b['portada'] ?? ''));
        $relator = clean((string) ($b['relator'] ?? ''));
        $pdf     = clean((string) ($b['programa_pdf'] ?? ''));
        $bajada  = clean((string) ($b['bajada'] ?? ''));
        $desc    = clean((string) ($b['descripcion'] ?? ''));
        $sinc    = !empty($b['sincrono']) ? 1 : 0;
        $val     = max(0, min(5, (int) ($b['valoracion'] ?? 0)));
        if (!urlMaterialOk($portada) || !urlMaterialOk($pdf)) {
            respond(false, null, 'La portada y el programa deben ser un enlace https o un archivo del aula.', 400);
        }
        $st = $pdo->prepare(
            'UPDATE aula_cursos
             SET portada = ?, relator = ?, programa_pdf = ?, bajada = ?, descripcion = ?, sincrono = ?, valoracion = ?
             WHERE id = ?'
        );
        $st->execute([$portada, $relator, $pdf, $bajada, $desc, $sinc, $val, $cursoId]);
        respond(true, ['curso_id' => $cursoId]);
    }

    if ($action === 'modulo_crear' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $u = requireAuth();
        $b = body();
        $cursoId = (int) ($b['curso_id'] ?? 0);
        $titulo = clean((string) ($b['titulo'] ?? ''));
        $tipo = (string) ($b['tipo'] ?? 'video');
        $url = clean((string) ($b['url'] ?? ''));
        $orden = (int) ($b['orden'] ?? 0);
        if (!in_array($tipo, ['video', 'archivo', 'enlace'], true)) {
            $tipo = 'video';
        }
        if ($cursoId < 1 || $titulo === '') {
            respond(false, null, 'Curso y título son obligatorios.', 400);
        }
        if (!puedeGestionarCurso($pdo, $u, $cursoId)) {
            respond(false, null, 'No puedes editar este curso.', 403);
        }
        if (!urlMaterialOk($url)) {
            respond(false, null, 'La URL del módulo no es válida.', 400);
        }
        $st = $pdo->prepare(
            'INSERT INTO aula_modulos (curso_id, titulo, tipo, url, orden) VALUES (?,?,?,?,?)'
        );
        $st->execute([$cursoId, $titulo, $tipo, $url, $orden]);
        respond(true, ['id' => (int) $pdo->lastInsertId()]);
    }

    if ($action === 'modulo_borrar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $u = requireAuth();
        $b = body();
        $id = (int) ($b['id'] ?? 0);
        $st = $pdo->prepare('SELECT curso_id FROM aula_modulos WHERE id = ?');
        $st->execute([$id]);
        $cursoId = (int) $st->fetchColumn();
        if ($cursoId < 1 || !puedeGestionarCurso($pdo, $u, $cursoId)) {
            respond(false, null, 'No puedes quitar este módulo.', 403);
        }
        $pdo->prepare('DELETE FROM aula_modulos WHERE id = ?')->execute([$id]);
        respond(true, ['id' => $id]);
    }

    if ($action === 'progreso' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $u = requireAuth();
        $b = body();
        $moduloId = (int) ($b['modulo_id'] ?? 0);
        $st = $pdo->prepare('SELECT curso_id FROM aula_modulos WHERE id = ?');
        $st->execute([$moduloId]);
        $cursoId = (int) $st->fetchColumn();
        if ($cursoId < 1) {
            respond(false, null, 'Módulo no encontrado.', 404);
        }
        $esAdmin = ($u['rol'] === 'admin');
        if (!$esAdmin && !estaInscrito($pdo, $cursoId, (int) $u['id'])) {
            respond(false, null, 'Sin acceso al curso.', 403);
        }
        $pdo->prepare(
            'INSERT IGNORE INTO aula_progreso (usuario_id, modulo_id) VALUES (?,?)'
        )->execute([(int) $u['id'], $moduloId]);
        respond(true, [
            'modulo_id' => $moduloId,
            'certificado' => cursoCompleto($pdo, $cursoId, (int) $u['id']),
        ]);
    }

    if ($action === 'foro') {
        $u = requireAuth();
        $cursoId = (int) ($_GET['curso_id'] ?? 0);
        $esAdmin = ($u['rol'] === 'admin');
        if ($cursoId < 1 || (!$esAdmin && !estaInscrito($pdo, $cursoId, (int) $u['id']))) {
            respond(false, null, 'Sin acceso al foro.', 403);
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $b = body();
            $msg = clean((string) ($b['mensaje'] ?? ''));
            if ($msg === '' || mb_strlen($msg) > 1000) {
                respond(false, null, 'Escribe un comentario de hasta 1000 caracteres.', 400);
            }
            $pdo->prepare('INSERT INTO aula_foro (curso_id, usuario_id, mensaje) VALUES (?,?,?)')
                ->execute([$cursoId, (int) $u['id'], $msg]);
        }
        $st = $pdo->prepare(
            'SELECT f.id, f.mensaje, f.created_at, u.nombre
             FROM aula_foro f
             JOIN aula_usuarios u ON u.id = f.usuario_id
             WHERE f.curso_id = ?
             ORDER BY f.created_at ASC
             LIMIT 200'
        );
        $st->execute([$cursoId]);
        respond(true, ['mensajes' => $st->fetchAll()]);
    }

    if ($action === 'suscripcion') {
        $u = requireAuth();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            requireAdmin();
            $b = body();
            $usuarioId = (int) ($b['usuario_id'] ?? 0);
            if ($usuarioId < 1 && !empty($b['rut'])) {
                $stU = $pdo->prepare('SELECT id FROM aula_usuarios WHERE rut = ? OR email = ? LIMIT 1');
                $rut = rutNormalizar((string) $b['rut']);
                $stU->execute([$rut, clean((string) $b['rut'])]);
                $usuarioId = (int) $stU->fetchColumn();
            }
            if ($usuarioId < 1) {
                respond(false, null, 'No encontré a ese alumno.', 404);
            }
            $tipo = (($b['tipo'] ?? '') === 'diario') ? 'diario' : 'mensual';
            $meses = max(0, (int) ($b['meses'] ?? 0));
            $detalle = clean((string) ($b['detalle'] ?? ''));
            $desde = fechaOk($b['vigente_desde'] ?? null);
            $hasta = fechaOk($b['vigente_hasta'] ?? null);
            $pdo->prepare(
                'INSERT INTO aula_suscripciones (usuario_id, tipo, meses, detalle, vigente_desde, vigente_hasta)
                 VALUES (?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE tipo = VALUES(tipo), meses = VALUES(meses), detalle = VALUES(detalle),
                   vigente_desde = VALUES(vigente_desde), vigente_hasta = VALUES(vigente_hasta)'
            )->execute([$usuarioId, $tipo, $meses, $detalle, $desde, $hasta]);
            respond(true, ['usuario_id' => $usuarioId]);
        }
        $st = $pdo->prepare('SELECT tipo, meses, detalle, vigente_desde, vigente_hasta FROM aula_suscripciones WHERE usuario_id = ?');
        $st->execute([(int) $u['id']]);
        $row = $st->fetch() ?: null;
        respond(true, ['suscripcion' => $row]);
    }

    if ($action === 'actividad') {
        $u = requireAuth();
        $uid = (int) $u['id'];
        $st = $pdo->prepare(
            'SELECT c.id, c.nombre, a.ultimo_acceso,
                    (SELECT COUNT(*) FROM aula_modulos m WHERE m.curso_id = c.id) AS modulos_n,
                    (SELECT COUNT(*) FROM aula_progreso p
                       JOIN aula_modulos m ON m.id = p.modulo_id
                      WHERE m.curso_id = c.id AND p.usuario_id = ?) AS hechos,
                    (SELECT 1 FROM aula_certificados k WHERE k.curso_id = c.id AND k.usuario_id = ? LIMIT 1) AS emitido
             FROM aula_inscripciones i
             JOIN aula_cursos c ON c.id = i.curso_id AND c.activo = 1
             LEFT JOIN aula_accesos a ON a.curso_id = c.id AND a.usuario_id = i.usuario_id
             WHERE i.usuario_id = ?
             ORDER BY a.ultimo_acceso IS NULL, a.ultimo_acceso DESC, c.nombre'
        );
        $st->execute([$uid, $uid, $uid]);
        $cursos = array_map(static function ($r) {
            $total = (int) $r['modulos_n'];
            $hechos = (int) $r['hechos'];
            $finalizado = $total > 0 && $hechos >= $total;
            return [
                'id' => (int) $r['id'],
                'nombre' => $r['nombre'],
                'ultimo_acceso' => $r['ultimo_acceso'],
                'modulos_n' => $total,
                'completados' => $hechos,
                'avance' => $total > 0 ? (int) round($hechos * 100 / $total) : 0,
                'finalizado' => $finalizado || (bool) $r['emitido'],
                'certificado' => $finalizado || (bool) $r['emitido'],
            ];
        }, $st->fetchAll());
        $sol = $pdo->prepare('SELECT id, mensaje, estado, created_at FROM aula_solicitudes WHERE usuario_id = ? ORDER BY created_at DESC LIMIT 20');
        $sol->execute([$uid]);
        respond(true, ['cursos' => $cursos, 'solicitudes' => $sol->fetchAll()]);
    }

    if ($action === 'solicitud' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $u = requireAuth();
        $b = body();
        $msg = clean((string) ($b['mensaje'] ?? ''));
        if ($msg === '' || mb_strlen($msg) > 1000) {
            respond(false, null, 'Describe el cambio que necesitas, en hasta 1000 caracteres.', 400);
        }
        $pdo->prepare('INSERT INTO aula_solicitudes (usuario_id, mensaje) VALUES (?,?)')
            ->execute([(int) $u['id'], $msg]);
        respond(true, ['enviada' => true]);
    }

    if ($action === 'solicitudes') {
        requireAdmin();
        $rows = $pdo->query(
            'SELECT s.id, s.mensaje, s.estado, s.created_at, u.nombre, u.rut, u.email
             FROM aula_solicitudes s
             JOIN aula_usuarios u ON u.id = s.usuario_id
             ORDER BY s.estado ASC, s.created_at DESC
             LIMIT 100'
        )->fetchAll();
        respond(true, ['solicitudes' => $rows]);
    }

    if ($action === 'solicitud_estado' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requireAdmin();
        $b = body();
        $id = (int) ($b['id'] ?? 0);
        $estado = (($b['estado'] ?? '') === 'revisada') ? 'revisada' : 'pendiente';
        $pdo->prepare('UPDATE aula_solicitudes SET estado = ? WHERE id = ?')->execute([$estado, $id]);
        respond(true, ['id' => $id, 'estado' => $estado]);
    }

    respond(false, null, 'Acción desconocida: ' . $action, 400);
} catch (Throwable $e) {
    respond(false, null, 'Error del servidor: ' . $e->getMessage(), 500);
}
