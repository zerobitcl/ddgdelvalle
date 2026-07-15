<?php
require_once __DIR__ . '/config.php';
ddg_require_auth();

$id = trim($_GET['id'] ?? '');
if (!preg_match('/^[a-f0-9]{16}$/', $id)) {
    http_response_code(400);
    exit('ID inválido.');
}

// Leer historial para encontrar el filename
$history = [];
if (file_exists(DDG_HISTORY_FILE)) {
    $history = json_decode(file_get_contents(DDG_HISTORY_FILE), true) ?: [];
}

$found = null;
foreach ($history as $item) {
    if ($item['id'] === $id) { $found = $item; break; }
}

if (!$found) {
    http_response_code(404);
    exit('Archivo no encontrado.');
}

$filepath = DDG_PDFS_DIR . '/' . $found['filename'];
if (!file_exists($filepath)) {
    http_response_code(404);
    exit('El archivo PDF no existe en el servidor.');
}

// Nombre de descarga legible
$downloadName = 'Informe_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $found['taller']) . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($filepath));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

readfile($filepath);
exit;
