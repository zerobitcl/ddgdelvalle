<?php
require_once __DIR__ . '/config.php';
ddg_require_auth_json();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ddg_json(['error' => 'Method not allowed'], 405);
}

$id = trim($_POST['id'] ?? '');
if (!preg_match('/^[a-f0-9]{16}$/', $id)) {
    ddg_json(['error' => 'ID inválido.'], 400);
}

// Leer historial
$history = [];
if (file_exists(DDG_HISTORY_FILE)) {
    $history = json_decode(file_get_contents(DDG_HISTORY_FILE), true) ?: [];
}

// Buscar el registro
$found    = null;
$filtered = [];
foreach ($history as $item) {
    if ($item['id'] === $id) {
        $found = $item;
    } else {
        $filtered[] = $item;
    }
}

if (!$found) {
    ddg_json(['error' => 'Registro no encontrado.'], 404);
}

// Eliminar archivo físico
$path = DDG_PDFS_DIR . '/' . $found['filename'];
if (file_exists($path)) {
    unlink($path);
}

// Guardar historial sin ese registro
file_put_contents(DDG_HISTORY_FILE, json_encode($filtered, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

ddg_json(['ok' => true]);
