<?php
require_once __DIR__ . '/config.php';
ddg_require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ddg_json(['error' => 'Method not allowed'], 405);
}

// Recibe el archivo PDF via multipart/form-data
$file   = $_FILES['pdf']  ?? null;
$taller = trim($_POST['taller']  ?? 'Sin nombre');
$relator = trim($_POST['relator'] ?? '');
$otec    = trim($_POST['otec']    ?? '');

if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
    ddg_json(['error' => 'No se recibió el archivo PDF.'], 400);
}

// Validar MIME (debe ser application/pdf)
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);
if ($mime !== 'application/pdf') {
    ddg_json(['error' => 'Archivo no válido.'], 400);
}

// Generar nombre único
$id       = bin2hex(random_bytes(8));
$safeName = preg_replace('/[^a-zA-Z0-9\-_]/', '_', $taller);
$safeName = substr($safeName, 0, 40);
$filename = $id . '_' . $safeName . '.pdf';
$dest     = DDG_PDFS_DIR . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    ddg_json(['error' => 'Error al guardar el archivo.'], 500);
}

// Leer historial actual
$history = [];
if (file_exists(DDG_HISTORY_FILE)) {
    $raw = file_get_contents(DDG_HISTORY_FILE);
    $history = json_decode($raw, true) ?: [];
}

// Agregar nuevo registro al inicio
array_unshift($history, [
    'id'         => $id,
    'filename'   => $filename,
    'taller'     => $taller,
    'relator'    => $relator,
    'otec'       => $otec,
    'created_at' => date('Y-m-d H:i:s'),
    'size_bytes' => filesize($dest),
]);

// Guardar historial actualizado
file_put_contents(DDG_HISTORY_FILE, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

ddg_json(['ok' => true, 'id' => $id, 'filename' => $filename]);
