<?php
require_once __DIR__ . '/config.php';
ddg_session_start();
$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;
