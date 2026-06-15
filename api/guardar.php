<?php
/**
 * api/guardar.php — Endpoint receptor de respuestas de encuesta.
 *
 * Diseñado para hosting compartido. Almacena cada respuesta en
 * datos/resultados.json como un array JSON válido, con bloqueo
 * exclusivo de archivo para evitar corrupción ante envíos concurrentes.
 *
 * NOTA DEL CTO: Para >500 respuestas migrar a SQLite (mismo PHP, sin
 * dependencias). El formato de cada registro está pensado para que la
 * migración sea un INSERT 1:1.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function out(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    out(405, ['ok' => false, 'error' => 'Método no permitido.']);
}

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '' || strlen($raw) > 65536) {
    out(400, ['ok' => false, 'error' => 'Carga inválida.']);
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    out(400, ['ok' => false, 'error' => 'JSON malformado.']);
}

/* ── Validación estructural mínima ───────────────────────────────────────── */
$expectedSections = ['I', 'II', 'III'];
$expectedCounts   = ['I' => 5, 'II' => 4, 'III' => 10];

if (!isset($data['secciones']) || !is_array($data['secciones'])) {
    out(422, ['ok' => false, 'error' => 'Falta el bloque "secciones".']);
}

foreach ($expectedSections as $sid) {
    if (!isset($data['secciones'][$sid]['preguntas']) || !is_array($data['secciones'][$sid]['preguntas'])) {
        out(422, ['ok' => false, 'error' => "Sección $sid inválida."]);
    }
    if (count($data['secciones'][$sid]['preguntas']) !== $expectedCounts[$sid]) {
        out(422, ['ok' => false, 'error' => "Sección $sid: número de preguntas incorrecto."]);
    }
    foreach ($data['secciones'][$sid]['preguntas'] as $q) {
        $v = $q['valor'] ?? null;
        if (!is_int($v) || $v < 1 || $v > 5) {
            out(422, ['ok' => false, 'error' => "Valor fuera de rango en sección $sid."]);
        }
    }
}

/* ── Sanitización ────────────────────────────────────────────────────────── */
$comentarios = isset($data['comentarios']) && is_string($data['comentarios'])
    ? mb_substr(trim($data['comentarios']), 0, 1000)
    : null;
if ($comentarios === '') $comentarios = null;

$record = [
    'id'             => bin2hex(random_bytes(8)),
    'recibido_en'    => gmdate('c'),
    'ip_hash'        => hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|ddg-salt'),
    'user_agent'     => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250),
    'cliente'        => [
        'timestamp'      => $data['timestamp']      ?? null,
        'promedioGlobal' => $data['promedioGlobal'] ?? null,
    ],
    'secciones'      => $data['secciones'],
    'comentarios'    => $comentarios,
];

/* ── Persistencia con flock exclusivo ────────────────────────────────────── */
$dir  = __DIR__ . '/../datos';
$file = $dir . '/resultados.json';

if (!is_dir($dir)) {
    if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
        out(500, ['ok' => false, 'error' => 'No se pudo crear el directorio de datos.']);
    }
}

$fp = @fopen($file, 'c+');
if (!$fp) {
    out(500, ['ok' => false, 'error' => 'No se pudo abrir el archivo de datos.']);
}

try {
    if (!flock($fp, LOCK_EX)) {
        out(500, ['ok' => false, 'error' => 'No se pudo bloquear el archivo de datos.']);
    }

    $contents = stream_get_contents($fp);
    $list = [];
    if ($contents !== false && trim($contents) !== '') {
        $decoded = json_decode($contents, true);
        if (is_array($decoded)) $list = $decoded;
    }

    $list[] = $record;

    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($list, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
} finally {
    fclose($fp);
}

out(200, ['ok' => true, 'id' => $record['id']]);
