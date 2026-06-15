<?php
/**
 * DDG del Valle — Configuración Central
 *
 * IMPORTANTE: Cambia DDG_USERNAME y DDG_PASSWORD antes de subir a producción.
 * El password se guarda en texto plano aquí pero se compara con hash_equals
 * para evitar timing-attacks. Para mayor seguridad, reemplaza DDG_PASSWORD
 * con un hash bcrypt usando password_hash() y ajusta el login.php.
 */

define('DDG_USERNAME',  'admin');
define('DDG_PASSWORD',  'DDGvalle2026!');   // ← Cambia esto

define('DDG_SESSION_NAME',    'ddg_sess');
define('DDG_SESSION_LIFETIME', 60 * 60 * 8); // 8 horas

define('DDG_PDFS_DIR',  __DIR__ . '/pdfs');
define('DDG_HISTORY_FILE', DDG_PDFS_DIR . '/history.json');

/** Marca e identidad OTEC (colores e imagen oficiales) */
define('DDG_LOGO_URL',        'https://ddgdelvalle.cl/wp-content/uploads/2026/03/cropped-LOGO-DDG_color.png');
define('DDG_COLOR_PRIMARY',   '#2C4855');
define('DDG_COLOR_SECONDARY', '#F1EAAF');
define('DDG_OTEC_NOMBRE',     'DDG DEL VALLE CAPACITACIONES SPA');
define('DDG_OTEC_RUT',        '77.534.533-0');
define('DDG_OTEC_CORREO',     'daniela.alvarez@ddgdelvalle.cl');

/** API2PDF — renderizado con Chromium headless */
define('DDG_API2PDF_KEY', '5d8dc751-3150-4931-8780-666befd005c1');

// Crea la carpeta pdfs/ si no existe
if (!is_dir(DDG_PDFS_DIR)) {
    mkdir(DDG_PDFS_DIR, 0755, true);
}

// Crea history.json si no existe
if (!file_exists(DDG_HISTORY_FILE)) {
    file_put_contents(DDG_HISTORY_FILE, json_encode([], JSON_PRETTY_PRINT));
}

/* ── Helpers de sesión ──────────────────────────────────────────── */

function ddg_session_start(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name(DDG_SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => DDG_SESSION_LIFETIME,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function ddg_is_authenticated(): bool {
    ddg_session_start();
    return !empty($_SESSION['ddg_auth']) && $_SESSION['ddg_auth'] === true;
}

function ddg_require_auth(): void {
    if (!ddg_is_authenticated()) {
        header('Location: login.php');
        exit;
    }
}

/** Para endpoints JSON (fetch): no redirige, devuelve 401 JSON. */
function ddg_require_auth_json(): void {
    if (!ddg_is_authenticated()) {
        ddg_json(['error' => 'Sesión expirada o no autenticado.', 'code' => 'auth'], 401);
    }
}

function ddg_json(mixed $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
