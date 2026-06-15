<?php
/**
 * admin.php — Panel interno consolidado.
 *
 * Lee datos/resultados.json y muestra promedios históricos globales,
 * por sección y por pregunta, con la misma UI premium de la encuesta.
 *
 * SEGURIDAD:
 *  - Contraseña hardcodeada (cambiar abajo). Para producción real se
 *    recomienda pasar a variable de entorno + password_hash().
 *  - Sesión con cookie httpOnly + SameSite=Lax.
 */

declare(strict_types=1);

/* ──────────────────────────────────────────────────────────────────────────
 * CONFIG — CAMBIA ESTA CONTRASEÑA
 * ────────────────────────────────────────────────────────────────────────── */
const ADMIN_PASSWORD = 'ddg-2026-admin'; // ⚠️  Cambia esto antes de subir a producción
const SESSION_NAME   = 'ddg_admin_session';

/* ──────────────────────────────────────────────────────────────────────────
 * SESIÓN
 * ────────────────────────────────────────────────────────────────────────── */
session_name(SESSION_NAME);
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

$loginError = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['password'])) {
    if (hash_equals(ADMIN_PASSWORD, (string)$_POST['password'])) {
        session_regenerate_id(true);
        $_SESSION['auth'] = true;
        header('Location: admin.php');
        exit;
    }
    $loginError = 'Contraseña incorrecta.';
}

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: admin.php');
    exit;
}

$isAuthed = !empty($_SESSION['auth']);

/* ──────────────────────────────────────────────────────────────────────────
 * AGREGACIÓN DE DATOS (sólo si autenticado)
 * ────────────────────────────────────────────────────────────────────────── */
$total = 0;
$globalSum = 0; $globalCount = 0;
$sectionSum = ['I' => 0, 'II' => 0, 'III' => 0];
$sectionCnt = ['I' => 0, 'II' => 0, 'III' => 0];
$questionSum = []; // qN => sum
$questionCnt = []; // qN => count
$questionMeta = []; // qN => ['numero','pregunta','seccion']
$comentarios = []; // [{recibido_en, texto}]
$timeline   = []; // YYYY-MM-DD => count
$lastUpdate = null;

$SECTIONS_DEF = [
    'I'   => 'Programa de Capacitación',
    'II'  => 'Organización de la Actividad',
    'III' => 'Relator N° 1',
];

if ($isAuthed) {
    $file = __DIR__ . '/datos/resultados.json';
    $list = [];
    if (is_file($file)) {
        $raw = @file_get_contents($file);
        if ($raw !== false && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $list = $decoded;
        }
    }
    $total = count($list);

    foreach ($list as $r) {
        if (!isset($r['secciones']) || !is_array($r['secciones'])) continue;

        if (!empty($r['recibido_en'])) {
            $day = substr((string)$r['recibido_en'], 0, 10);
            $timeline[$day] = ($timeline[$day] ?? 0) + 1;
            if ($lastUpdate === null || $r['recibido_en'] > $lastUpdate) {
                $lastUpdate = $r['recibido_en'];
            }
        }

        foreach (['I','II','III'] as $sid) {
            if (!isset($r['secciones'][$sid]['preguntas']) || !is_array($r['secciones'][$sid]['preguntas'])) continue;
            foreach ($r['secciones'][$sid]['preguntas'] as $qkey => $q) {
                $v = $q['valor'] ?? null;
                if (!is_int($v) || $v < 1 || $v > 5) continue;
                $globalSum += $v; $globalCount++;
                $sectionSum[$sid] += $v; $sectionCnt[$sid]++;

                $key = is_string($qkey) ? $qkey : ('q' . ($q['numero'] ?? '?'));
                $questionSum[$key] = ($questionSum[$key] ?? 0) + $v;
                $questionCnt[$key] = ($questionCnt[$key] ?? 0) + 1;
                if (!isset($questionMeta[$key])) {
                    $questionMeta[$key] = [
                        'numero'   => $q['numero']   ?? null,
                        'pregunta' => $q['pregunta'] ?? '(sin texto)',
                        'seccion'  => $sid,
                    ];
                }
            }
        }

        if (!empty($r['comentarios']) && is_string($r['comentarios'])) {
            $comentarios[] = [
                'recibido_en' => $r['recibido_en'] ?? null,
                'texto'       => $r['comentarios'],
            ];
        }
    }

    krsort($comentarios);
    ksort($timeline);
}

/* ──────────────────────────────────────────────────────────────────────────
 * HELPERS
 * ────────────────────────────────────────────────────────────────────────── */
function avg(int $sum, int $count): ?float {
    return $count > 0 ? round($sum / $count, 2) : null;
}
function scoreColor(?float $v): string {
    if ($v === null) return '#94a3b8';
    if ($v >= 4.5)  return '#0d6632';
    if ($v >= 3.5)  return '#1a7a4a';
    if ($v >= 2.5)  return '#b8891a';
    if ($v >= 1.5)  return '#d35400';
    return '#c0392b';
}
function scoreLabel(?float $v): string {
    if ($v === null) return 'Sin datos';
    if ($v >= 4.5)  return 'Excelente';
    if ($v >= 3.5)  return 'Bueno';
    if ($v >= 2.5)  return 'Regular';
    if ($v >= 1.5)  return 'Deficiente';
    return 'Muy deficiente';
}
function e(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$globalAvg = avg($globalSum, $globalCount);
?><!doctype html>
<html lang="es-CL">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
  <meta name="theme-color" content="#2C4753"/>
  <title>Panel Interno · Resultados Encuesta | DDG</title>
  <meta name="robots" content="noindex,nofollow"/>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@500;600;700;800&display=swap" rel="stylesheet">
  <link rel="icon" href="https://ddgdelvalle.cl/wp-content/uploads/2026/03/icono-ddg.png"/>

  <style>
    :root {
      --primary:#2C4753; --primary-d:#1e3540;
      --secondary:#81A896; --accent:#D5CC3C;
      --bg:#F4F7F6; --bg2:#F2EEDB; --surface:#fff;
      --text:#0f172a; --muted:rgba(15,23,42,.60);
      --border:rgba(15,23,42,.09);
      --shadow-sm:0 4px 14px rgba(15,23,42,.06);
      --shadow-md:0 14px 36px rgba(15,23,42,.09);
      --shadow-lg:0 24px 56px rgba(15,23,42,.12);
      --r-sm:12px; --r-md:16px; --r-lg:24px;
    }
    *,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
    body {
      font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
      color:var(--text);
      background:linear-gradient(170deg,var(--bg) 0%,var(--bg2) 100%);
      min-height:100vh; line-height:1.6; -webkit-font-smoothing:antialiased;
    }
    a { color:inherit; text-decoration:none; }
    .container { width:min(1080px,calc(100% - 48px)); margin:0 auto; }

    /* Header */
    .site-header {
      position:sticky; top:0; z-index:100;
      background:rgba(255,255,255,.93);
      backdrop-filter:saturate(1.3) blur(12px);
      border-bottom:1px solid var(--border);
    }
    .header-inner {
      display:flex; align-items:center; justify-content:space-between;
      gap:16px; padding:13px 0;
    }
    .brand { display:flex; align-items:center; gap:12px; }
    .brand img { height:50px; width:auto; }
    .header-tools { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
    .header-badge {
      display:inline-flex; align-items:center; gap:6px;
      padding:5px 14px; border-radius:999px;
      background:rgba(213,204,60,.14); border:1px solid rgba(213,204,60,.30);
      color:#7d761b; font-size:11px; font-weight:700;
      font-family:Montserrat,Inter; letter-spacing:.3px; white-space:nowrap;
    }
    .btn-logout {
      display:inline-flex; align-items:center; gap:6px;
      padding:7px 14px; border-radius:999px;
      border:1px solid var(--border); background:#fff;
      color:var(--primary); font-family:Montserrat,Inter;
      font-size:11px; font-weight:700; cursor:pointer;
      transition:border-color .2s,background .2s;
    }
    .btn-logout:hover { border-color:var(--primary); background:rgba(44,71,83,.05); }

    /* Hero */
    .admin-hero {
      background:linear-gradient(135deg,var(--primary) 0%,var(--primary-d) 100%);
      padding:54px 0 84px; position:relative; overflow:hidden; color:#fff;
    }
    .admin-hero::before {
      content:''; position:absolute; top:-25%; left:-6%;
      width:480px; height:480px;
      background:radial-gradient(circle,rgba(213,204,60,.18) 0%,transparent 65%);
    }
    .admin-hero::after {
      content:''; position:absolute; bottom:-20%; right:-5%;
      width:380px; height:380px;
      background:radial-gradient(circle,rgba(129,168,150,.22) 0%,transparent 65%);
    }
    .admin-hero .container { position:relative; z-index:2; }
    .hero-eyebrow {
      display:inline-flex; align-items:center; gap:8px;
      padding:5px 14px; border-radius:999px;
      background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.20);
      color:rgba(255,255,255,.82); font-size:10px; font-weight:700;
      font-family:Montserrat,Inter; letter-spacing:.6px;
      text-transform:uppercase; margin-bottom:18px;
    }
    .admin-hero h1 {
      font-family:Montserrat,Inter;
      font-size:clamp(28px,4.5vw,44px); font-weight:800;
      line-height:1.08; letter-spacing:-.6px; color:#fff; margin-bottom:14px;
    }
    .admin-hero p { color:rgba(255,255,255,.76); font-size:14px; max-width:580px; line-height:1.7; }
    .hero-wave { position:absolute; bottom:-1px; left:0; right:0; height:68px; z-index:3; }
    .hero-wave svg { width:100%; height:100%; display:block; }

    /* Body */
    .admin-body { padding:48px 0 80px; }

    /* Login */
    .login-wrap { padding:80px 0 120px; }
    .login-card {
      max-width:420px; margin:0 auto;
      background:var(--surface); border:1px solid var(--border);
      border-radius:var(--r-lg); box-shadow:var(--shadow-lg);
      padding:42px 38px;
    }
    .login-card h2 {
      font-family:Montserrat,Inter; font-size:22px; font-weight:800;
      color:var(--primary); margin-bottom:6px;
    }
    .login-card p { font-size:13px; color:var(--muted); margin-bottom:26px; }
    .login-card label {
      display:block; font-size:12px; font-weight:700;
      color:var(--primary); margin-bottom:8px;
      text-transform:uppercase; letter-spacing:.4px;
    }
    .login-card input[type=password] {
      width:100%; padding:14px 16px; font-family:Inter; font-size:14px;
      border:2px solid var(--border); border-radius:var(--r-md);
      background:var(--bg); color:var(--text); outline:none;
      transition:border-color .2s,box-shadow .2s;
    }
    .login-card input[type=password]:focus {
      border-color:var(--primary); background:var(--surface);
      box-shadow:0 0 0 3px rgba(44,71,83,.09);
    }
    .login-error {
      margin-top:14px; padding:11px 14px;
      background:rgba(192,57,43,.07); border:1px solid rgba(192,57,43,.20);
      border-left:3px solid #c0392b; border-radius:10px;
      font-size:12.5px; font-weight:600; color:#8a2a20;
    }
    .btn-primary {
      display:inline-flex; align-items:center; justify-content:center; gap:10px;
      width:100%; margin-top:22px;
      padding:15px 28px; border-radius:999px; border:none;
      background:var(--primary); color:#fff;
      font-family:Montserrat,Inter; font-size:13px; font-weight:800;
      letter-spacing:.2px; cursor:pointer;
      box-shadow:0 10px 28px rgba(44,71,83,.22);
      transition:background .2s,box-shadow .2s,transform .1s;
    }
    .btn-primary:hover { background:var(--primary-d); transform:translateY(-2px); box-shadow:0 16px 38px rgba(44,71,83,.30); }

    /* KPI strip */
    .kpi-grid {
      display:grid; grid-template-columns:repeat(4,1fr);
      gap:16px; margin-bottom:28px;
    }
    .kpi-card {
      background:var(--surface); border:1px solid var(--border);
      border-radius:var(--r-md); padding:20px 22px;
      box-shadow:var(--shadow-sm);
    }
    .kpi-label {
      font-size:10.5px; font-weight:700; color:var(--muted);
      letter-spacing:.5px; text-transform:uppercase; margin-bottom:8px;
    }
    .kpi-value {
      font-family:Montserrat,Inter; font-size:28px; font-weight:800;
      color:var(--primary); line-height:1;
    }
    .kpi-sub { font-size:11px; color:var(--muted); margin-top:6px; font-weight:600; }

    /* Global card */
    .global-card {
      background:var(--primary); border-radius:var(--r-lg);
      padding:36px 40px; display:flex; align-items:center; gap:36px;
      margin-bottom:28px; position:relative; overflow:hidden;
    }
    .global-card::before {
      content:''; position:absolute; top:-25%; left:-4%;
      width:300px; height:300px;
      background:radial-gradient(circle,rgba(213,204,60,.20) 0%,transparent 65%);
    }
    .global-card::after {
      content:''; position:absolute; bottom:-20%; right:-4%;
      width:260px; height:260px;
      background:radial-gradient(circle,rgba(129,168,150,.22) 0%,transparent 65%);
    }
    .score-ring {
      width:120px; height:120px; border-radius:50%;
      border:3px solid rgba(255,255,255,.18);
      background:rgba(255,255,255,.10);
      display:flex; flex-direction:column; align-items:center; justify-content:center;
      flex-shrink:0; position:relative; z-index:1;
    }
    .score-val { font-family:Montserrat,Inter; font-size:38px; font-weight:800; color:#fff; line-height:1; }
    .score-of  { font-size:11px; font-weight:600; color:rgba(255,255,255,.55); margin-top:2px; }
    .global-info { flex:1; position:relative; z-index:1; }
    .global-info h3 { font-family:Montserrat,Inter; font-size:20px; font-weight:800; color:#fff; margin-bottom:6px; }
    .global-info p { color:rgba(255,255,255,.72); font-size:13px; line-height:1.6; }
    .global-tag {
      display:inline-block; margin-top:10px;
      padding:4px 12px; border-radius:999px;
      background:rgba(255,255,255,.14); border:1px solid rgba(255,255,255,.20);
      font-size:10.5px; font-weight:700; letter-spacing:.5px;
      text-transform:uppercase; color:#fff; font-family:Montserrat,Inter;
    }

    /* Section scores */
    .sec-scores-grid {
      display:grid; grid-template-columns:repeat(3,1fr);
      gap:16px; margin-bottom:28px;
    }
    .sec-score-card {
      background:var(--surface); border:1px solid var(--border);
      border-radius:var(--r-md); padding:22px; box-shadow:var(--shadow-sm);
    }
    .ssc-top { display:flex; align-items:center; gap:10px; margin-bottom:14px; }
    .ssc-dot {
      width:30px; height:30px; border-radius:10px;
      background:var(--primary); color:#fff;
      display:flex; align-items:center; justify-content:center;
      font-family:Montserrat,Inter; font-weight:800; font-size:12px;
    }
    .ssc-name { font-size:11px; font-weight:700; color:var(--primary); line-height:1.3; }
    .ssc-score { font-family:Montserrat,Inter; font-size:30px; font-weight:800; line-height:1; margin-bottom:10px; }
    .ssc-score .of-5 { font-size:14px; font-weight:600; color:var(--muted); }
    .bar-track { height:7px; background:var(--bg); border-radius:999px; overflow:hidden; }
    .bar-fill {
      height:100%; border-radius:999px; width:0%;
      transition:width 1.1s cubic-bezier(0.4,0,0.2,1);
    }
    .ssc-lbl { font-size:11px; font-weight:600; margin-top:6px; }

    /* Breakdown */
    .bdown-card {
      background:var(--surface); border:1px solid var(--border);
      border-radius:var(--r-lg); box-shadow:var(--shadow-md);
      overflow:hidden; margin-bottom:20px;
    }
    .bdown-header {
      padding:20px 26px; border-bottom:1px solid var(--border);
      display:flex; align-items:center; gap:12px;
      background:rgba(44,71,83,.025);
    }
    .bdown-header h3 { font-family:Montserrat,Inter; font-size:14px; font-weight:800; color:var(--primary); }
    .bdown-body { padding:0 26px 6px; }
    .bdown-row {
      display:flex; align-items:center; gap:10px;
      padding:13px 0; border-bottom:1px solid rgba(15,23,42,.04);
    }
    .bdown-row:last-child { border-bottom:none; }
    .br-tag {
      width:24px; height:24px; border-radius:7px;
      background:rgba(44,71,83,.06);
      display:flex; align-items:center; justify-content:center;
      font-size:10px; font-weight:800; color:var(--primary);
      flex-shrink:0; font-family:Montserrat,Inter;
    }
    .br-text { flex:1; font-size:12.5px; color:var(--text); line-height:1.45; }
    .br-bar-wrap { display:flex; align-items:center; gap:8px; width:160px; flex-shrink:0; }
    .br-bar-track { flex:1; height:6px; background:var(--bg); border-radius:999px; overflow:hidden; }
    .br-bar-fill { height:100%; border-radius:999px; width:0%; transition:width 1.1s cubic-bezier(0.4,0,0.2,1); }
    .br-val { font-family:Montserrat,Inter; font-size:13px; font-weight:800; width:34px; text-align:right; }
    .br-n { font-size:10px; font-weight:600; color:var(--muted); width:54px; text-align:right; }

    /* Comments list */
    .comments-card {
      background:var(--surface); border:1px solid var(--border);
      border-radius:var(--r-lg); box-shadow:var(--shadow-sm);
      overflow:hidden; margin-bottom:28px;
    }
    .comments-card-header {
      padding:18px 26px; border-bottom:1px solid var(--border);
      display:flex; align-items:center; gap:12px; background:rgba(44,71,83,.025);
    }
    .comments-card-header h3 { font-family:Montserrat,Inter; font-size:14px; font-weight:800; color:var(--primary); }
    .comments-body { padding:8px 26px 18px; max-height:520px; overflow-y:auto; }
    .comment-item {
      padding:14px 0; border-bottom:1px solid rgba(15,23,42,.05);
    }
    .comment-item:last-child { border-bottom:none; }
    .comment-meta {
      font-size:10.5px; font-weight:700; color:var(--muted);
      letter-spacing:.4px; text-transform:uppercase; margin-bottom:6px;
    }
    .comment-text {
      font-size:13.5px; color:var(--text); line-height:1.7;
      font-style:italic; border-left:3px solid var(--accent); padding-left:14px;
    }

    /* Empty state */
    .empty-card {
      background:var(--surface); border:1px dashed var(--border);
      border-radius:var(--r-lg); padding:60px 40px;
      text-align:center;
    }
    .empty-card h3 { font-family:Montserrat,Inter; font-size:18px; color:var(--primary); margin-bottom:8px; }
    .empty-card p { font-size:13px; color:var(--muted); }

    /* Footer */
    .site-footer {
      background:linear-gradient(180deg,#0b1220 0%,#070c14 100%);
      color:rgba(255,255,255,.75);
      padding:24px 0; border-top:1px solid rgba(255,255,255,.06);
      font-size:11px;
    }
    .site-footer .container { display:flex; justify-content:space-between; gap:14px; flex-wrap:wrap; }
    .site-footer strong { color:#fff; font-family:Montserrat,Inter; }

    /* Responsive */
    @media (max-width:820px) {
      .kpi-grid { grid-template-columns:repeat(2,1fr); }
      .sec-scores-grid { grid-template-columns:1fr; }
      .global-card { flex-direction:column; align-items:flex-start; gap:20px; padding:26px 24px; }
      .br-bar-wrap { width:130px; }
    }
    @media (max-width:560px) {
      .container { width:calc(100% - 28px); }
      .kpi-grid { grid-template-columns:1fr 1fr; }
      .br-text { font-size:12px; }
      .br-bar-wrap { width:108px; }
      .br-n { display:none; }
    }
  </style>
</head>
<body>

  <header class="site-header">
    <div class="container header-inner">
      <a class="brand" href="<?= $isAuthed ? 'admin.php' : '#' ?>">
        <img src="https://ddgdelvalle.cl/wp-content/uploads/2026/03/LOGO-DDG_color.png" alt="DDG Del Valle" height="50">
      </a>
      <div class="header-tools">
        <span class="header-badge">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77 5.82 21.02 7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
          Panel Interno
        </span>
        <?php if ($isAuthed): ?>
          <a class="btn-logout" href="admin.php?logout=1">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Cerrar sesión
          </a>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <?php if (!$isAuthed): ?>
    <main class="login-wrap">
      <div class="container">
        <div class="login-card">
          <h2>Acceso restringido</h2>
          <p>Ingresa la contraseña para visualizar los resultados consolidados.</p>
          <form method="post" autocomplete="off">
            <label for="password">Contraseña</label>
            <input id="password" type="password" name="password" required autofocus>
            <?php if ($loginError): ?>
              <div class="login-error"><?= e($loginError) ?></div>
            <?php endif; ?>
            <button class="btn-primary" type="submit">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
              Ingresar
            </button>
          </form>
        </div>
      </div>
    </main>
  <?php else: ?>

    <section class="admin-hero">
      <div class="container">
        <div class="hero-eyebrow">
          <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3h18v18H3z"/><path d="M3 9h18M9 21V9"/></svg>
          Resultados consolidados
        </div>
        <h1>Dashboard de Encuesta<br>Promedio histórico global</h1>
        <p>Vista interna con métricas acumuladas de todas las respuestas recibidas. Datos agregados de forma anónima.</p>
      </div>
      <div class="hero-wave" aria-hidden="true">
        <svg viewBox="0 0 1440 68" preserveAspectRatio="none">
          <path d="M0,34 C360,68 1080,0 1440,34 L1440,68 L0,68 Z" fill="#F4F7F6"/>
        </svg>
      </div>
    </section>

    <main class="admin-body">
      <div class="container">

      <?php if ($total === 0): ?>
        <div class="empty-card">
          <h3>Sin respuestas todavía</h3>
          <p>Cuando los participantes envíen sus encuestas verás aquí el promedio histórico, el desglose por sección y los comentarios.</p>
        </div>
      <?php else: ?>

        <!-- KPIs -->
        <div class="kpi-grid">
          <div class="kpi-card">
            <div class="kpi-label">Respuestas totales</div>
            <div class="kpi-value"><?= number_format($total, 0, ',', '.') ?></div>
            <div class="kpi-sub">Encuestas históricas recibidas</div>
          </div>
          <div class="kpi-card">
            <div class="kpi-label">Promedio global</div>
            <div class="kpi-value" style="color:<?= e(scoreColor($globalAvg)) ?>"><?= $globalAvg !== null ? number_format($globalAvg, 2, ',', '.') : '—' ?></div>
            <div class="kpi-sub"><?= e(scoreLabel($globalAvg)) ?> · sobre 5,00</div>
          </div>
          <div class="kpi-card">
            <div class="kpi-label">Comentarios recibidos</div>
            <div class="kpi-value"><?= count($comentarios) ?></div>
            <div class="kpi-sub"><?= $total > 0 ? round(count($comentarios) / $total * 100) : 0 ?>% de los participantes</div>
          </div>
          <div class="kpi-card">
            <div class="kpi-label">Última actualización</div>
            <div class="kpi-value" style="font-size:18px"><?= $lastUpdate ? e(date('d/m/Y', strtotime($lastUpdate))) : '—' ?></div>
            <div class="kpi-sub"><?= $lastUpdate ? e(date('H:i', strtotime($lastUpdate))) . ' hrs' : 'Sin datos' ?></div>
          </div>
        </div>

        <!-- Global score card -->
        <div class="global-card">
          <div class="score-ring">
            <span class="score-val" style="color:<?= e(scoreColor($globalAvg)) ?>"><?= $globalAvg !== null ? number_format($globalAvg, 1, ',', '.') : '—' ?></span>
            <span class="score-of">/ 5,0</span>
          </div>
          <div class="global-info">
            <h3>Promedio histórico global</h3>
            <p>Promedio ponderado de las <?= count($questionMeta) ?: 19 ?> preguntas evaluadas en las <?= number_format($total, 0, ',', '.') ?> encuesta<?= $total === 1 ? '' : 's' ?> recibida<?= $total === 1 ? '' : 's' ?> hasta la fecha.</p>
            <span class="global-tag"><?= e(scoreLabel($globalAvg)) ?></span>
          </div>
        </div>

        <!-- Section scores -->
        <div class="sec-scores-grid">
          <?php foreach ($SECTIONS_DEF as $sid => $label):
            $av  = avg($sectionSum[$sid], $sectionCnt[$sid]);
            $col = scoreColor($av);
            $pct = $av !== null ? ($av / 5) * 100 : 0;
          ?>
            <div class="sec-score-card">
              <div class="ssc-top">
                <div class="ssc-dot"<?= $sid === 'III' ? '' : '' ?>><?= e($sid) ?></div>
                <div class="ssc-name"><?= e($label) ?></div>
              </div>
              <div class="ssc-score" style="color:<?= e($col) ?>">
                <?= $av !== null ? number_format($av, 2, ',', '.') : '—' ?><span class="of-5"> / 5</span>
              </div>
              <div class="bar-track"><div class="bar-fill" data-w="<?= number_format($pct, 1, '.', '') ?>" style="background:<?= e($col) ?>"></div></div>
              <div class="ssc-lbl" style="color:<?= e($col) ?>"><?= e(scoreLabel($av)) ?></div>
            </div>
          <?php endforeach; ?>
        </div>

        <!-- Breakdown per section -->
        <?php foreach ($SECTIONS_DEF as $sid => $label):
          $secAvg = avg($sectionSum[$sid], $sectionCnt[$sid]);
          // Filtrar y ordenar preguntas de esta sección
          $qs = [];
          foreach ($questionMeta as $key => $meta) {
            if ($meta['seccion'] === $sid) $qs[$key] = $meta;
          }
          uasort($qs, fn($a, $b) => ($a['numero'] ?? 0) <=> ($b['numero'] ?? 0));
        ?>
          <div class="bdown-card">
            <div class="bdown-header">
              <div class="ssc-dot"><?= e($sid) ?></div>
              <h3><?= e($label) ?> — Promedio: <span style="color:<?= e(scoreColor($secAvg)) ?>"><?= $secAvg !== null ? number_format($secAvg, 2, ',', '.') : '—' ?></span></h3>
            </div>
            <div class="bdown-body">
              <?php foreach ($qs as $key => $meta):
                $qAvg = avg($questionSum[$key] ?? 0, $questionCnt[$key] ?? 0);
                $qCol = scoreColor($qAvg);
                $qPct = $qAvg !== null ? ($qAvg / 5) * 100 : 0;
                $n    = $questionCnt[$key] ?? 0;
              ?>
                <div class="bdown-row">
                  <div class="br-tag"><?= e((string)($meta['numero'] ?? '?')) ?></div>
                  <div class="br-text"><?= e($meta['pregunta']) ?></div>
                  <div class="br-bar-wrap">
                    <div class="br-bar-track"><div class="br-bar-fill" data-w="<?= number_format($qPct, 1, '.', '') ?>" style="background:<?= e($qCol) ?>"></div></div>
                    <div class="br-val" style="color:<?= e($qCol) ?>"><?= $qAvg !== null ? number_format($qAvg, 2, ',', '.') : '—' ?></div>
                    <div class="br-n">n=<?= (int)$n ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>

        <!-- Comments -->
        <?php if (!empty($comentarios)): ?>
          <div class="comments-card">
            <div class="comments-card-header">
              <div class="ssc-dot" style="background:var(--accent); color:#1b2b33;">IV</div>
              <h3>Comentarios recibidos (<?= count($comentarios) ?>)</h3>
            </div>
            <div class="comments-body">
              <?php foreach ($comentarios as $c): ?>
                <div class="comment-item">
                  <div class="comment-meta">
                    <?= $c['recibido_en'] ? e(date('d/m/Y · H:i', strtotime($c['recibido_en']))) . ' hrs' : 'Sin fecha' ?>
                  </div>
                  <p class="comment-text">“<?= e($c['texto']) ?>”</p>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

      <?php endif; ?>

      </div>
    </main>

    <script>
      requestAnimationFrame(() => {
        setTimeout(() => {
          document.querySelectorAll('[data-w]').forEach(el => {
            el.style.width = el.dataset.w + '%';
          });
        }, 150);
      });
    </script>

  <?php endif; ?>

  <footer class="site-footer">
    <div class="container">
      <div><strong>DDG Del Valle</strong> · Panel interno · uso restringido</div>
      <div>© <?= date('Y') ?> · Todos los derechos reservados</div>
    </div>
  </footer>

</body>
</html>
