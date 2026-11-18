<?php
declare(strict_types=1);

/**
 * Stream de video protegido por sesión del aula.
 * - Requiere login
 * - Solo sirve archivos bajo /videos
 * - Soporta Range (seek)
 * - Evita listado y hotlink trivial
 */

session_name('ddg_aula');
session_start();

// Videos largos: no cortar por timeout ni bufferizar en RAM
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@set_time_limit(0);
ignore_user_abort(true);
while (ob_get_level() > 0) {
    ob_end_clean();
}

if (empty($_SESSION['aula_user'])) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Debes iniciar sesión en el aula.';
    exit;
}

$file = basename((string) ($_GET['f'] ?? ''));
if ($file === '' || !preg_match('/^[a-zA-Z0-9._-]+\.(mp4|webm|ogg)$/', $file)) {
    http_response_code(400);
    echo 'Archivo inválido.';
    exit;
}

$path = __DIR__ . DIRECTORY_SEPARATOR . 'videos' . DIRECTORY_SEPARATOR . $file;
if (!is_file($path)) {
    http_response_code(404);
    echo 'Video no encontrado.';
    exit;
}

$size = filesize($path);
if ($size === false) {
    http_response_code(500);
    echo 'No se pudo leer el video.';
    exit;
}

$mime = 'video/mp4';
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
if ($ext === 'webm') {
    $mime = 'video/webm';
} elseif ($ext === 'ogg') {
    $mime = 'video/ogg';
}

header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . $file . '"');

$start = 0;
$end = $size - 1;

if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
    if ($m[1] !== '') {
        $start = (int) $m[1];
    }
    if ($m[2] !== '') {
        $end = (int) $m[2];
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header("Content-Range: bytes */{$size}");
        exit;
    }
    $end = min($end, $size - 1);
    http_response_code(206);
    header("Content-Range: bytes {$start}-{$end}/{$size}");
}

$length = $end - $start + 1;
header('Content-Length: ' . $length);

$fp = fopen($path, 'rb');
if ($fp === false) {
    http_response_code(500);
    echo 'No se pudo abrir el video.';
    exit;
}

fseek($fp, $start);
$buf = 8192;
$sent = 0;
while (!feof($fp) && $sent < $length) {
    $read = min($buf, $length - $sent);
    $data = fread($fp, $read);
    if ($data === false) {
        break;
    }
    echo $data;
    $sent += strlen($data);
    if (connection_aborted()) {
        break;
    }
}
fclose($fp);
exit;
