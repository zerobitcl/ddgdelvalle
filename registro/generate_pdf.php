<?php
/**
 * Endpoint de generación de PDF con API2PDF (Chromium headless).
 *
 * Recibe JSON con los datos del formulario + imágenes base64.
 * Renderiza pdf_template.php a HTML, lo envía a API2PDF,
 * descarga el binario resultante, lo guarda en /pdfs/ y actualiza history.json.
 * Devuelve JSON con { ok, id, filename } para que el cliente descargue.
 */

require_once __DIR__ . '/config.php';
ddg_require_auth_json();

@ini_set('memory_limit', '512M');
@ini_set('max_execution_time', '180');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ddg_json(['error' => 'Method not allowed'], 405);
}

// ── 1) Parsear y validar entrada JSON ────────────────────────────────
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    ddg_json(['error' => 'Payload inválido (no JSON).'], 400);
}

$required = ['otec', 'rut', 'correo', 'relator', 'profesion', 'region',
             'taller', 'modalidad', 'duracion', 'fecha_inicio', 'fecha_termino', 'horario',
             'descripcion', 'objetivos', 'impacto'];
foreach ($required as $f) {
    if (empty(trim($data[$f] ?? ''))) {
        ddg_json(['error' => "Campo obligatorio faltante: $f"], 400);
    }
}

$asistencia = null;
$rawAsistencia = $data['asistencia'] ?? '';
if (is_string($rawAsistencia) && strpos($rawAsistencia, 'data:image/') === 0) {
    $asistencia = $rawAsistencia;
}

$images = [];
$rawImages = $data['images'] ?? [];
if (is_array($rawImages)) {
    foreach ($rawImages as $i => $img) {
        $dataUrl = $img['dataUrl'] ?? '';
        $name    = $img['name']    ?? '';
        if (strpos($dataUrl, 'data:image/') === 0) {
            $images[] = [
                'dataUrl' => $dataUrl,
                'name'    => $name,
                'idx'     => $i + 1,
            ];
        }
    }
}

// ── 2) Renderizar template a HTML ────────────────────────────────────
while (ob_get_level() > 0) {
    ob_end_clean();
}

$tplData = [
    'otec'         => $data['otec'],
    'rut'          => $data['rut'],
    'correo'       => $data['correo'],
    'relator'      => $data['relator'],
    'profesion'    => $data['profesion'],
    'region'       => $data['region'],
    'taller'       => $data['taller'],
    'modalidad'    => $data['modalidad'],
    'duracion'     => $data['duracion'],
    'fecha_inicio' => $data['fecha_inicio'],
    'fecha_termino'=> $data['fecha_termino'],
    'horario'      => $data['horario'],
    'descripcion'  => $data['descripcion'],
    'objetivos'    => $data['objetivos'],
    'impacto'      => $data['impacto'],
    'metodologia'  => is_array($data['metodologia'] ?? null) ? $data['metodologia'] : [],
    'images'       => $images,
    'asistencia'   => $asistencia,
    'logo_url'     => DDG_LOGO_URL,
    'color_primary'   => DDG_COLOR_PRIMARY,
    'color_secondary' => DDG_COLOR_SECONDARY,
];

ob_start();
$data = $tplData;
require __DIR__ . '/pdf_template.php';
$html = trim(ob_get_clean());

// ── 3) Llamar a API2PDF (Chromium headless) ───────────────────────────
$payload = json_encode([
    'html'    => $html,
    'inline'  => true,
    'options' => [
        'marginTop'       => 0,
        'marginBottom'    => 0,
        'marginLeft'      => 0,
        'marginRight'     => 0,
        'printBackground' => true,
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$ch = curl_init('https://v2.api2pdf.com/chrome/pdf/html');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 120,
    CURLOPT_HTTPHEADER     => [
        'Authorization: ' . DDG_API2PDF_KEY,
        'Content-Type: application/json',
    ],
]);

$apiRaw   = curl_exec($ch);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($apiRaw === false || $curlErr) {
    ddg_json(['error' => 'Error de red al contactar API2PDF.', 'detail' => $curlErr], 500);
}

$response = json_decode($apiRaw, true);

if (!isset($response['FileUrl'])) {
    ddg_json([
        'error'  => 'API2PDF no devolvió FileUrl.',
        'detail' => $response['Error'] ?? $apiRaw,
    ], 500);
}

$pdfBinary = file_get_contents($response['FileUrl']);
if ($pdfBinary === false || strlen($pdfBinary) < 100) {
    ddg_json(['error' => 'No se pudo descargar el PDF desde API2PDF.'], 500);
}

// ── 4) Guardar archivo físico ────────────────────────────────────────
$id       = bin2hex(random_bytes(8));
$safeName = preg_replace('/[^a-zA-Z0-9\-_]/', '_', $tplData['taller']);
$safeName = substr($safeName, 0, 40);
$filename = $id . '_' . $safeName . '.pdf';
$dest     = DDG_PDFS_DIR . '/' . $filename;

if (file_put_contents($dest, $pdfBinary) === false) {
    ddg_json(['error' => 'No se pudo guardar el archivo en el servidor.'], 500);
}

// ── 5) Actualizar history.json ───────────────────────────────────────
$history = [];
if (file_exists(DDG_HISTORY_FILE)) {
    $history = json_decode(file_get_contents(DDG_HISTORY_FILE), true) ?: [];
}

array_unshift($history, [
    'id'         => $id,
    'filename'   => $filename,
    'taller'     => $tplData['taller'],
    'relator'    => $tplData['relator'],
    'otec'       => $tplData['otec'],
    'created_at' => date('Y-m-d H:i:s'),
    'size_bytes' => filesize($dest),
]);

file_put_contents(DDG_HISTORY_FILE, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// ── 6) Responder al cliente con URL de descarga ──────────────────────
ddg_json([
    'ok'           => true,
    'id'           => $id,
    'filename'     => $filename,
    'download_url' => 'download_pdf.php?id=' . $id,
]);
