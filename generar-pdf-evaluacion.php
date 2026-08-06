<?php
/**
 * Genera el PDF de la Evaluación Final vía API2PDF (servidor).
 * La API key nunca se expone al navegador.
 */
session_start();
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['alumno_auth'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'No autorizado. Inicia sesión de nuevo.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
    exit;
}

$configFile = __DIR__ . '/config-api2pdf.php';
if (!is_file($configFile)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Falta configuración de API2PDF en el servidor.']);
    exit;
}

$config = require $configFile;
$apiKey = trim((string) ($config['api_key'] ?? ''));
$endpoint = trim((string) ($config['endpoint'] ?? 'https://v2.api2pdf.com/chrome/pdf/html'));

if ($apiKey === '' || $apiKey === 'TU-API-KEY-AQUI') {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'API key de API2PDF no configurada.']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);
if (!is_array($data)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'JSON inválido.']);
    exit;
}

$htmlBody = (string) ($data['html'] ?? '');
$filename = (string) ($data['filename'] ?? 'Prueba_Riego_Alumno.pdf');

if ($htmlBody === '' || strlen($htmlBody) > 1500000) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Contenido HTML vacío o demasiado grande.']);
    exit;
}

$filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename) ?: 'Prueba_Riego_Alumno.pdf';
if (!preg_match('/\.pdf$/i', $filename)) {
    $filename .= '.pdf';
}

$fullHtml = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">'
    . '<meta name="viewport" content="width=device-width, initial-scale=1">'
    . '<title>' . htmlspecialchars(pathinfo($filename, PATHINFO_FILENAME), ENT_QUOTES, 'UTF-8') . '</title>'
    . '<style>
        @page { margin: 12mm; }
        body { margin: 0; padding: 0; background: #fff; color: #0f172a;
               font-family: Arial, Helvetica, sans-serif; font-size: 12.5px; line-height: 1.45; }
        .pdf-root { max-width: 720px; margin: 0 auto; padding: 8px 4px; }
        .pdf-q { page-break-inside: avoid; break-inside: avoid; margin-bottom: 14px; }
      </style></head><body><div class="pdf-root">'
    . $htmlBody
    . '</div></body></html>';

$payload = json_encode([
    'html' => $fullHtml,
    'inlinePdf' => true,
    'fileName' => $filename,
    'options' => [
        'landscape' => false,
        'printBackground' => true,
        'marginTop' => 0.4,
        'marginBottom' => 0.4,
        'marginLeft' => 0.4,
        'marginRight' => 0.4,
        'preferCSSPageSize' => false,
    ],
], JSON_UNESCAPED_UNICODE);

if ($payload === false) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'No se pudo serializar la solicitud.']);
    exit;
}

/**
 * @return array{body:?string,error:string,code:int}
 */
function http_post_json(string $url, string $json, string $authKey): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $authKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_CONNECTTIMEOUT => 20,
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['body' => ($body === false ? null : $body), 'error' => $err, 'code' => $code];
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Authorization: {$authKey}\r\nContent-Type: application/json\r\nAccept: application/json\r\n",
            'content' => $json,
            'timeout' => 90,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $code = (int) $m[1];
    }
    return ['body' => ($body === false ? null : $body), 'error' => ($body === false ? 'file_get_contents falló' : ''), 'code' => $code];
}

/**
 * @return array{body:?string,error:string,code:int}
 */
function http_get_binary(string $url): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60,
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['body' => ($body === false ? null : $body), 'error' => $err, 'code' => $code];
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 60,
            'follow_location' => 1,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $code = (int) $m[1];
    }
    return ['body' => ($body === false ? null : $body), 'error' => ($body === false ? 'file_get_contents falló' : ''), 'code' => $code];
}

$apiRes = http_post_json($endpoint, $payload, $apiKey);

if ($apiRes['body'] === null) {
    http_response_code(502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Error de conexión con API2PDF: ' . $apiRes['error']]);
    exit;
}

$result = json_decode($apiRes['body'], true);
if (!is_array($result)) {
    http_response_code(502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Respuesta inválida de API2PDF.', 'status' => $apiRes['code']]);
    exit;
}

$success = !empty($result['Success']) || !empty($result['success']);
$fileUrl = $result['FileUrl'] ?? $result['fileUrl'] ?? null;
$errorMsg = $result['Error'] ?? $result['error'] ?? ('HTTP ' . $apiRes['code']);

if (!$success || !$fileUrl) {
    http_response_code(502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'API2PDF: ' . $errorMsg]);
    exit;
}

$pdfRes = http_get_binary($fileUrl);
$pdfBinary = $pdfRes['body'];

if ($pdfBinary === null || $pdfRes['code'] >= 400 || strncmp($pdfBinary, '%PDF', 4) !== 0) {
    http_response_code(502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'No se pudo descargar el PDF generado.']);
    exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdfBinary));
header('Cache-Control: private, no-store');
echo $pdfBinary;
exit;
