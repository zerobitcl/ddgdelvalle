<?php
require_once __DIR__ . '/config.php';
ddg_require_auth_json();

$history = [];
if (file_exists(DDG_HISTORY_FILE)) {
    $raw     = file_get_contents(DDG_HISTORY_FILE);
    $history = json_decode($raw, true) ?: [];
}

ddg_json($history);
