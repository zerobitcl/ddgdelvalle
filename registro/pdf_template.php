<?php
/**
 * Template HTML — renderizado por Chromium headless (API2PDF)
 *
 * Variables ($data): otec, rut, correo, relator, profesion, region,
 * taller, modalidad, duracion, descripcion, objetivos, impacto,
 * metodologia (array), contenido (array de {tema, duracion, actividad}),
 * images (array base64), asistencia (base64|null)
 */

function pdf_escape(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function pdf_nl2br(?string $s): string {
    return nl2br(pdf_escape($s));
}
function pdf_format_date(?string $d): string {
    static $m = [1=>'enero',2=>'febrero',3=>'marzo',4=>'abril',5=>'mayo',6=>'junio',
                 7=>'julio',8=>'agosto',9=>'septiembre',10=>'octubre',11=>'noviembre',12=>'diciembre'];
    if (!$d) return '—';
    $ts = strtotime($d);
    if (!$ts) return pdf_escape($d);
    return (int)date('j',$ts).' de '.$m[(int)date('n',$ts)].' de '.date('Y',$ts);
}

$meses       = [1=>'enero',2=>'febrero',3=>'marzo',4=>'abril',5=>'mayo',6=>'junio',
                7=>'julio',8=>'agosto',9=>'septiembre',10=>'octubre',11=>'noviembre',12=>'diciembre'];
$fecha       = (int)date('j').' de '.$meses[(int)date('n')].' de '.date('Y');
$metodologia = is_array($data['metodologia'] ?? null) ? $data['metodologia'] : [];
$contenido   = is_array($data['contenido']   ?? null) ? $data['contenido']   : [];
$images      = is_array($data['images']      ?? null) ? $data['images']      : [];
$asistencia  = $data['asistencia'] ?? null;
$logoUrl     = $data['logo_url']        ?? 'https://ddgdelvalle.cl/wp-content/uploads/2026/03/cropped-LOGO-DDG_color.png';
$iconUrl     = 'https://ddgdelvalle.cl/wp-content/uploads/2026/03/icono-ddg.png';
$colorPri    = $data['color_primary']   ?? '#2C4855';
$colorSec    = $data['color_secondary'] ?? '#F1EAAF';
$tallerHdr   = mb_strlen($data['taller'] ?? '') > 58
             ? mb_substr($data['taller'] ?? '', 0, 58).'…'
             : ($data['taller'] ?? '');
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,400;1,500&display=swap" rel="stylesheet">
<style>

/* ══════════════════════════════════════════════════════════════════
   DDG Del Valle · PDF Stylesheet v5 · Chromium / API2PDF
   Anti-Plantilla: Cards + Grid. Cero tablas de layout.
   Solo <table> para contenido del programa.
══════════════════════════════════════════════════════════════════ */

:root {
  --pri:    <?= pdf_escape($colorPri) ?>;
  --sec:    <?= pdf_escape($colorSec) ?>;
  --text:   #1e293b;
  --muted:  #64748b;
  --light:  #f8fafc;
  --border: #e2e8f0;
}

/* Configura el margen real de impresión para las páginas de contenido */
@page {
  size: A4;
  margin: 35mm 0 25mm 0;
}

/* La portada se mantiene en cero márgenes */
@page cover-pg {
  size: A4;
  margin: 0;
}

*, *::before, *::after {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
}

html, body {
  width: 210mm;
}

body {
  font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
  color: var(--text);
  font-size: 9pt;
  line-height: 1.5;
  -webkit-print-color-adjust: exact;
  print-color-adjust: exact;
  background: #ffffff;
  margin-bottom: 0;
  padding-bottom: 0;
  overflow-wrap: break-word;
  word-wrap: break-word;
  word-break: break-word;
}

/* ─── MARCA DE AGUA ───────────────────────────────────────────── */

.watermark {
  position: fixed;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  width: 340px;
  height: 340px;
  z-index: -1;
  opacity: 0.04;
  pointer-events: none;
}

.watermark img {
  width: 100%;
  height: 100%;
  object-fit: contain;
  display: block;
}

/* ─── PORTADA ─────────────────────────────────────────────────── */

.cover {
  /* Página nombrada: margin: 0 en portada, sin franja blanca */
  page: cover-pg;
  /* Altura exacta de A4 para no desbordarse a página 2 */
  height: 297mm;
  width: 100%;
  /* z-index > 9999 del header/footer para taparlo físicamente en página 1 */
  position: relative;
  z-index: 10000;
  isolation: isolate;
  overflow: hidden;
  background: var(--pri);
  display: flex;
  flex-direction: column;
  padding: 52px 60px 40px;
  box-sizing: border-box;
  margin: 0;
  page-break-after: always;
}

.cover-top-bar {
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 6px;
  background: var(--sec);
}

.cover-circle-lg {
  position: absolute;
  top: -110px; right: -110px;
  width: 440px; height: 440px;
  border-radius: 50%;
  background: rgba(255,255,255,0.035);
  pointer-events: none;
}

.cover-circle-sm {
  position: absolute;
  top: 200px; right: 50px;
  width: 160px; height: 160px;
  border-radius: 50%;
  border: 1.5px solid rgba(255,255,255,0.055);
  pointer-events: none;
}

.cover-triangle {
  position: absolute;
  bottom: 0; right: 0;
  width: 260px; height: 260px;
  background: rgba(241,234,175,0.055);
  clip-path: polygon(100% 0, 100% 100%, 0 100%);
  pointer-events: none;
}

.cover-side-accent {
  position: absolute;
  left: 0; top: 120px; bottom: 0;
  width: 4px;
  background: linear-gradient(to bottom, transparent, rgba(241,234,175,0.35), transparent);
}

.cover-logo-area {
  position: relative;
  z-index: 2;
  margin-bottom: 52px;
  text-align: center;
}

.cover-logo-box {
  display: inline-flex;
  align-items: center;
  margin: 0 auto;
  background: rgba(255,255,255,0.97);
  padding: 13px 22px;
  border-radius: 9px;
  box-shadow: 0 10px 40px rgba(0,0,0,0.28);
}

.cover-logo-box img {
  height: 100px;
  max-width: 250px;
  width: auto;
  display: block;
}

.cover-body {
  flex: 1;
  display: flex;
  flex-direction: column;
  justify-content: center;
  position: relative;
  z-index: 2;
}

.cover-eyebrow {
  font-size: 7pt;
  font-weight: 700;
  letter-spacing: 3.5px;
  text-transform: uppercase;
  color: var(--sec);
  margin-bottom: 14px;
}

.cover-rule {
  width: 44px;
  height: 3px;
  background: var(--sec);
  border-radius: 2px;
  margin-bottom: 20px;
}

.cover-title {
  font-size: 33pt;
  font-weight: 900;
  line-height: 1.07;
  color: white;
  letter-spacing: -1.5px;
  margin-bottom: 18px;
  max-width: 490px;
}

.cover-subtitle {
  font-size: 10.5pt;
  color: rgba(255,255,255,0.62);
  line-height: 1.65;
  max-width: 420px;
}

.cover-subtitle strong {
  color: rgba(255,255,255,0.92);
  font-weight: 600;
}

.cover-meta {
  position: relative;
  z-index: 2;
  margin-top: 50px;
  border-top: 1px solid rgba(255,255,255,0.1);
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
}

.cover-meta-item {
  padding: 15px 0;
  border-bottom: 1px solid rgba(255,255,255,0.06);
}

.cover-meta-item + .cover-meta-item {
  padding-left: 22px;
  border-left: 1px solid rgba(255,255,255,0.08);
}

.cover-meta-label {
  font-size: 6.5pt;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 1.5px;
  color: rgba(255,255,255,0.38);
  margin-bottom: 5px;
}

.cover-meta-value {
  font-size: 9.5pt;
  font-weight: 700;
  color: white;
  line-height: 1.3;
}

.cover-footer-bar {
  position: relative;
  z-index: 2;
  margin-top: 26px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 7pt;
  color: rgba(255,255,255,0.28);
  letter-spacing: 0.8px;
  text-transform: uppercase;
}

/* ─── HEADER FIJO ─────────────────────────────────────────────── */

/* El header se ancla al borde absoluto superior del papel (0) y mide 70px (~18mm).
   Como el margen de la hoja es de 35mm, queda un espacio limpio de protección. */
.page-header {
  position: fixed;
  top: 0;
  left: 0; right: 0;
  height: 70px;
  padding: 0 60px;
  background: white;
  border-bottom: 1.5px solid var(--border);
  display: flex;
  align-items: center;
  justify-content: space-between;
  z-index: 9000;
}

.ph-logo img {
  max-height: 40px;
  width: auto;
  display: block;
}

.ph-taller {
  font-size: 7pt;
  font-weight: 600;
  color: var(--pri);
  text-align: right;
  max-width: 58%;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  letter-spacing: 0.3px;
}

/* ─── FOOTER FIJO ─────────────────────────────────────────────── */

/* El footer se ancla al borde inferior absoluto del papel */
.page-footer {
  position: fixed;
  bottom: 0;
  left: 0; right: 0;
  height: 50px;
  padding: 0 60px;
  background: white;
  border-top: 1px solid var(--border);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9000;
}

.pf-phrase {
  font-size: 7.5pt;
  font-style: italic;
  color: var(--muted);
  font-weight: 400;
  letter-spacing: 0.2px;
  text-align: center;
}

/* ─── CONTENIDO ───────────────────────────────────────────────── */

/* Eliminamos paddings verticales extras porque el @page ya maneja los espacios */
.content {
  padding: 0 60px;
}

/* ─── SECCIÓN HEADER ──────────────────────────────────────────── */

.section {
  margin-bottom: 22px;
  page-break-inside: auto !important;
  break-inside: auto !important;
}

.sec-eyebrow {
  font-size: 6pt;
  font-weight: 800;
  color: var(--pri);
  letter-spacing: 3px;
  text-transform: uppercase;
  margin-bottom: 2px;
  page-break-after: avoid !important;
  break-after: avoid !important;
}

.sec-title {
  font-size: 13pt;
  font-weight: 800;
  color: var(--pri);
  letter-spacing: -0.3px;
  line-height: 1.15;
  page-break-after: avoid !important;
  break-after: avoid !important;
}

.sec-sub {
  font-size: 7.5pt;
  color: var(--muted);
  margin-top: 3px;
  margin-bottom: 10px;
  line-height: 1.4;
  page-break-after: avoid !important;
  break-after: avoid !important;
}

.sec-bar {
  width: 28px;
  height: 2.5px;
  background: var(--pri);
  border-radius: 2px;
  margin-bottom: 12px;
  page-break-after: avoid !important;
  break-after: avoid !important;
}

/* ─── GRID DE 2 COLUMNAS (layout general) ─────────────────────── */

.grid-2 {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 15px;
  margin-bottom: 22px;
  page-break-inside: avoid;
}

/* ─── CARD: unidad atómica de información ─────────────────────── */

.card {
  background: #f8fafc;
  border-radius: 10px;
  padding: 16px 18px;
  border: 1px solid var(--border);
  page-break-inside: avoid;
}

.card-header {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 14px;
  padding-bottom: 10px;
  border-bottom: 1.5px solid var(--border);
}

.card-icon {
  width: 30px;
  height: 30px;
  border-radius: 8px;
  background: var(--pri);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.card-icon svg {
  width: 15px;
  height: 15px;
  fill: white;
}

.card-title {
  font-size: 9pt;
  font-weight: 700;
  color: var(--pri);
  letter-spacing: -0.1px;
}

.card-num {
  font-size: 6pt;
  font-weight: 700;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 1.5px;
  margin-bottom: 2px;
}

/* ─── CAMPO KV dentro de card ─────────────────────────────────── */

.field {
  display: flex;
  flex-direction: column;
  margin-bottom: 9px;
  page-break-inside: avoid;
}

.field:last-child {
  margin-bottom: 0;
}

.field-label {
  font-size: 6.5pt;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.9px;
  color: var(--muted);
  margin-bottom: 2px;
}

.field-value {
  font-size: 9pt;
  font-weight: 600;
  color: var(--text);
  line-height: 1.35;
}

/* ─── KV CARDS (stats/fechas) ─────────────────────────────────── */

.kv-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
  margin-top: 10px;
}

.kv-card {
  background: white;
  border: 1px solid var(--border);
  border-left: 3px solid var(--pri);
  border-radius: 0 8px 8px 0;
  padding: 10px 14px;
  page-break-inside: avoid;
}

.kv-card .k {
  font-size: 6.5pt;
  text-transform: uppercase;
  color: var(--muted);
  letter-spacing: 0.9px;
  font-weight: 600;
  margin-bottom: 3px;
}

.kv-card .v {
  font-size: 10pt;
  color: var(--text);
  font-weight: 700;
  line-height: 1.2;
}

/* ─── TEXTO LIBRE ─────────────────────────────────────────────── */

.tb {
  font-size: 9pt;
  line-height: 1.72;
  color: #334155;
  text-align: justify;
  overflow-wrap: break-word;
  word-wrap: break-word;
  word-break: break-word;
}

/* ─── SALTO DE SECCIÓN CONTROLADO ────────────────────────────── */

.page-break-section, .photo-section, .annex-section {
  page-break-before: always !important;
  break-before: page !important;
}

/* ─── PILLS / METODOLOGÍA ─────────────────────────────────────── */

.pills {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  page-break-inside: avoid !important;
  break-inside: avoid !important;
}

.pill {
  background: var(--sec);
  border: 1px solid rgba(44,72,85,0.15);
  color: var(--pri);
  padding: 5px 16px;
  border-radius: 100px;
  font-size: 8pt;
  font-weight: 700;
  letter-spacing: 0.1px;
}

/* ─── TABLA DE CONTENIDO DEL PROGRAMA ────────────────────────── */

.program-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 8.5pt;
  margin-top: 2px;
}

.program-table thead tr {
  background: var(--pri);
}

.program-table thead th {
  color: white;
  font-weight: 600;
  font-size: 7.5pt;
  text-transform: uppercase;
  letter-spacing: 0.8px;
  padding: 12px 14px;
  text-align: left;
  border: none;
}

.program-table tbody tr {
  page-break-inside: avoid;
}

.program-table tbody tr:nth-child(even) {
  background: #f8fafc;
}

.program-table tbody td {
  padding: 11px 14px;
  color: var(--text);
  vertical-align: top;
  border-bottom: 1px solid #e2e8f0;
  border-left: none;
  border-right: none;
  line-height: 1.45;
}

.program-table tbody td:first-child {
  font-weight: 600;
  color: var(--pri);
  width: 46%;
}

.program-table tbody td:nth-child(2) {
  color: var(--muted);
  font-weight: 500;
  width: 20%;
  white-space: nowrap;
}

.program-table tbody td:last-child {
  color: #475569;
  width: 34%;
}

/* ─── REGISTRO FOTOGRÁFICO ────────────────────────────────────── */

.photo-grid {
  display: block;
  font-size: 0; /* Remueve el espacio en blanco fantasma de los elementos inline-block */
  margin-top: 10px;
}

.photo-item {
  display: inline-block;
  width: calc(50% - 6px);
  margin-bottom: 12px;
  vertical-align: top;
  font-size: 9pt; /* Restaura el tamaño de fuente para el texto del pie de foto */
  box-sizing: border-box;
  /* Blindaje anti-corte de página */
  page-break-inside: avoid !important;
  break-inside: avoid !important;
  -webkit-column-break-inside: avoid;
  overflow: hidden;
}

/* Simula las 2 columnas del antiguo grid aplicando margen solo al elemento izquierdo */
.photo-item:nth-child(odd) {
  margin-right: 12px;
}

.photo-item img {
  width: 100%;
  height: 164px;
  object-fit: cover;
  border-radius: 8px;
  border: 1px solid var(--border);
  display: block;
  /* Refuerzo en la propia imagen */
  page-break-inside: avoid !important;
  break-inside: avoid !important;
}

.photo-caption {
  font-size: 7pt;
  color: #94a3b8;
  text-align: center;
  margin-top: 5px;
  font-style: italic;
}

/* ─── ANEXO ───────────────────────────────────────────────────── */

.annex-img-wrap {
  margin-top: 14px;
  margin-bottom: 0;
  text-align: center;
}

.annex-img-wrap img {
  max-width: 100%;
  max-height: 175mm; /* Límite estricto para encajar en A4 con headers */
  object-fit: contain;
  border: 1px solid var(--border);
  border-radius: 8px;
  page-break-inside: avoid !important;
  break-inside: avoid !important;
}

/* ─── SECCIÓN FOTO / ANEXO: padding inferior controlado ──────── */

.photo-section .content {
  padding-bottom: 16px;
}

.annex-section .content {
  padding-bottom: 16px;
}

/* ─── DOC FOOTER ──────────────────────────────────────────────── */

.doc-footer {
  margin-top: 16px;
  margin-bottom: 0;
  padding-top: 8px;
  padding-bottom: 0;
  border-top: 1.5px solid var(--pri);
  font-size: 7pt;
  color: #94a3b8;
  text-align: center;
  letter-spacing: 0.4px;
}

.doc-footer .brand {
  color: var(--pri);
  font-weight: 700;
}

</style>
</head>
<body>

<!-- ═══════════════════════════════════════════════════════════
     MARCA DE AGUA SUTIL
════════════════════════════════════════════════════════════ -->
<div class="watermark">
  <img src="<?= pdf_escape($iconUrl) ?>" alt="">
</div>

<!-- ═══════════════════════════════════════════════════════════
     PORTADA
════════════════════════════════════════════════════════════ -->
<div class="cover" style="position:relative;z-index:10000;isolation:isolate;">
  <div class="cover-top-bar"></div>
  <div class="cover-circle-lg"></div>
  <div class="cover-circle-sm"></div>
  <div class="cover-triangle"></div>
  <div class="cover-side-accent"></div>

  <div class="cover-logo-area">
    <div class="cover-logo-box">
      <img src="<?= pdf_escape($logoUrl) ?>" alt="DDG Del Valle">
    </div>
  </div>

  <div class="cover-body">
    <div class="cover-eyebrow">Informe Oficial de Capacitación</div>
    <div class="cover-rule"></div>
    <h1 class="cover-title"><?= pdf_escape($data['taller'] ?? 'Sin nombre') ?></h1>
    <p class="cover-subtitle">
      Documento oficial de registro y respaldo técnico del programa de capacitación
      ejecutado por la OTEC&nbsp;<strong><?= pdf_escape($data['otec'] ?? '') ?></strong>.
    </p>
  </div>

  <div class="cover-meta">
    <div class="cover-meta-item">
      <div class="cover-meta-label">Relator</div>
      <div class="cover-meta-value"><?= pdf_escape($data['relator'] ?? '') ?></div>
    </div>
    <div class="cover-meta-item">
      <div class="cover-meta-label">Modalidad</div>
      <div class="cover-meta-value"><?= pdf_escape($data['modalidad'] ?? '') ?></div>
    </div>
    <div class="cover-meta-item">
      <div class="cover-meta-label">Duración</div>
      <div class="cover-meta-value"><?= pdf_escape($data['duracion'] ?? '') ?></div>
    </div>
    <div class="cover-meta-item">
      <div class="cover-meta-label">Inicio</div>
      <div class="cover-meta-value"><?= pdf_format_date($data['fecha_inicio'] ?? '') ?></div>
    </div>
    <div class="cover-meta-item">
      <div class="cover-meta-label">Término</div>
      <div class="cover-meta-value"><?= pdf_format_date($data['fecha_termino'] ?? '') ?></div>
    </div>
    <div class="cover-meta-item">
      <div class="cover-meta-label">Fecha de emisión</div>
      <div class="cover-meta-value"><?= pdf_escape($fecha) ?></div>
    </div>
  </div>

  <div class="cover-footer-bar">
    <span>Informe Oficial · Confidencial</span>
    <span>DDG Del Valle Capacitaciones &copy; <?= date('Y') ?></span>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     HEADER FIJO — logo izquierda · taller derecha
════════════════════════════════════════════════════════════ -->
<div class="page-header">
  <div class="ph-logo">
    <img src="<?= pdf_escape($logoUrl) ?>" alt="DDG Del Valle">
  </div>
  <div class="ph-taller"><?= pdf_escape($tallerHdr) ?></div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     FOOTER FIJO — frase institucional centrada
════════════════════════════════════════════════════════════ -->
<div class="page-footer">
</div>

<!-- ═══════════════════════════════════════════════════════════
     CONTENIDO — Secciones 01–08
════════════════════════════════════════════════════════════ -->
<div class="content">

  <!-- 01 + 02: cards en grid de 2 columnas -->
  <div class="grid-2">

    <!-- 01 · Entidad Capacitadora -->
    <div class="card">
      <div class="card-header">
        <div class="card-icon">
          <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 3L1 9l11 6 9-4.91V17h2V9L12 3zM5 13.18v4L12 21l7-3.82v-4L12 17l-7-3.82z"/></svg>
        </div>
        <div>
          <div class="card-num">01</div>
          <div class="card-title">Entidad Capacitadora</div>
        </div>
      </div>
      <div class="field">
        <span class="field-label">Nombre OTEC</span>
        <span class="field-value"><?= pdf_escape($data['otec']   ?? '—') ?></span>
      </div>
      <div class="field">
        <span class="field-label">RUT</span>
        <span class="field-value"><?= pdf_escape($data['rut']    ?? '—') ?></span>
      </div>
      <div class="field">
        <span class="field-label">Correo</span>
        <span class="field-value"><?= pdf_escape($data['correo'] ?? '—') ?></span>
      </div>
      <div class="field">
        <span class="field-label">Región</span>
        <span class="field-value"><?= pdf_escape($data['region'] ?? '—') ?></span>
      </div>
    </div>

    <!-- 02 · Relator / Facilitador -->
    <div class="card">
      <div class="card-header">
        <div class="card-icon">
          <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/></svg>
        </div>
        <div>
          <div class="card-num">02</div>
          <div class="card-title">Relator / Facilitador</div>
        </div>
      </div>
      <div class="field">
        <span class="field-label">Nombre</span>
        <span class="field-value"><?= pdf_escape($data['relator']   ?? '—') ?></span>
      </div>
      <div class="field">
        <span class="field-label">Profesión</span>
        <span class="field-value"><?= pdf_escape($data['profesion'] ?? '—') ?></span>
      </div>
    </div>

  </div>

  <!-- 03 · Detalles del Taller — cards grid, sin tablas -->
  <div class="section">
    <div class="sec-eyebrow">03</div>
    <div class="sec-title">Detalles de la Capacitación</div>
    <div class="sec-sub">Parámetros generales del programa de capacitación.</div>
    <div class="sec-bar"></div>

    <div class="grid-2" style="margin-bottom: 0;">
      <div class="card">
        <div class="field">
          <span class="field-label">Nombre del taller</span>
          <span class="field-value"><?= pdf_escape($data['taller']  ?? '—') ?></span>
        </div>
        <div class="field">
          <span class="field-label">Horario</span>
          <span class="field-value"><?= pdf_escape($data['horario'] ?? '—') ?></span>
        </div>
      </div>
      <div class="kv-grid" style="margin-top: 0; align-content: start;">
        <div class="kv-card">
          <div class="k">Modalidad</div>
          <div class="v"><?= pdf_escape($data['modalidad'] ?? '—') ?></div>
        </div>
        <div class="kv-card">
          <div class="k">Duración total</div>
          <div class="v"><?= pdf_escape($data['duracion'] ?? '—') ?></div>
        </div>
        <div class="kv-card">
          <div class="k">Fecha de inicio</div>
          <div class="v"><?= pdf_format_date($data['fecha_inicio'] ?? '') ?></div>
        </div>
        <div class="kv-card">
          <div class="k">Fecha de término</div>
          <div class="v"><?= pdf_format_date($data['fecha_termino'] ?? '') ?></div>
        </div>
      </div>
    </div>
  </div>

  <!-- 04 · Descripción General -->
  <div class="section">
    <div class="sec-eyebrow">04</div>
    <div class="sec-title">Descripción General</div>
    <div class="sec-sub">Contexto y propósito del programa.</div>
    <div class="sec-bar"></div>
    <p class="tb"><?= pdf_nl2br($data['descripcion'] ?? '') ?></p>
  </div>

  <!-- 05 · Objetivos -->
  <div class="section page-break-section">
    <div class="sec-eyebrow">05</div>
    <div class="sec-title">Objetivos Logrados</div>
    <div class="sec-sub">Resultados de aprendizaje alcanzados durante el taller.</div>
    <div class="sec-bar"></div>
    <p class="tb"><?= pdf_nl2br($data['objetivos'] ?? '') ?></p>
  </div>

  <!-- 06 · Metodología — pills -->
  <div class="section">
    <div class="sec-eyebrow">06</div>
    <div class="sec-title">Metodología</div>
    <div class="sec-sub">Estrategias pedagógicas aplicadas durante el taller.</div>
    <div class="sec-bar"></div>
    <div class="pills">
      <?php if (count($metodologia) > 0): ?>
        <?php foreach ($metodologia as $met): ?>
          <span class="pill"><?= pdf_escape($met) ?></span>
        <?php endforeach; ?>
      <?php else: ?>
        <span style="font-size:8.5pt;color:#94a3b8;font-style:italic;">No se especificó metodología.</span>
      <?php endif; ?>
    </div>
  </div>

  <!-- 07 · Impacto -->
  <div class="section">
    <div class="sec-eyebrow">07</div>
    <div class="sec-title">Impacto para la Institución</div>
    <div class="sec-sub">Efecto del programa en la institución y sus colaboradores.</div>
    <div class="sec-bar"></div>
    <p class="tb"><?= pdf_nl2br($data['impacto'] ?? '') ?></p>
  </div>

  <!-- 08 · Contenido del Programa — única <table> real del documento -->
  <?php if (count($contenido) > 0): ?>
  <div class="section">
    <div class="sec-eyebrow">08</div>
    <div class="sec-title">Contenido del Programa</div>
    <div class="sec-sub">Estructura temática y distribución horaria del taller.</div>
    <div class="sec-bar"></div>
    <table class="program-table" cellpadding="0" cellspacing="0">
      <thead>
        <tr>
          <th>Tema / Unidad</th>
          <th>Duración</th>
          <th>Actividad</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($contenido as $item): ?>
        <tr>
          <td><?= pdf_escape($item['tema']      ?? '') ?></td>
          <td><?= pdf_escape($item['duracion']  ?? '') ?></td>
          <td><?= pdf_escape($item['actividad'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php if (count($images) === 0 && empty($asistencia)): ?>
  <div class="doc-footer">
    Documento generado automáticamente ·
    <span class="brand">DDG Del Valle Capacitaciones</span>
    &copy; <?= date('Y') ?>
  </div>
  <?php endif; ?>

</div>

<!-- ═══════════════════════════════════════════════════════════
     REGISTRO FOTOGRÁFICO
════════════════════════════════════════════════════════════ -->
<?php if (count($images) > 0): ?>
<div class="photo-section">
  <div class="content">

    <div class="section">
      <div class="sec-eyebrow"><?= count($contenido) > 0 ? '09' : '08' ?></div>
      <div class="sec-title">Registro Fotográfico</div>
      <div class="sec-sub">Evidencia visual de la ejecución del programa.</div>
      <div class="sec-bar"></div>
    </div>

    <div class="photo-grid">
      <?php foreach ($images as $img): ?>
      <div class="photo-item">
        <img src="<?= $img['dataUrl'] ?>"
             alt="Fotografía <?= pdf_escape((string)$img['idx']) ?>">
        <div class="photo-caption">
          Foto <?= pdf_escape((string)$img['idx']) ?><?= !empty($img['name']) ? ' — ' . pdf_escape($img['name']) : '' ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if (empty($asistencia)): ?>
    <div class="doc-footer">
      Documento generado automáticamente ·
      <span class="brand">DDG Del Valle Capacitaciones</span>
      &copy; <?= date('Y') ?>
    </div>
    <?php endif; ?>

  </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════
     ANEXO — Registro de Asistencia
════════════════════════════════════════════════════════════ -->
<?php if (!empty($asistencia)): ?>
<div class="annex-section">
  <div class="content">

    <div class="section">
      <div class="sec-eyebrow">Anexo</div>
      <div class="sec-title">Registro de Asistencia</div>
      <div class="sec-sub">
        Asistencia correspondiente al taller
        «<?= pdf_escape($data['taller'] ?? '') ?>».
      </div>
      <div class="sec-bar"></div>
    </div>

    <div class="annex-img-wrap">
      <img src="<?= $asistencia ?>" alt="Registro de Asistencia">
    </div>

    <div class="doc-footer">
      Documento generado automáticamente ·
      <span class="brand">DDG Del Valle Capacitaciones</span>
      &copy; <?= date('Y') ?>
    </div>

  </div>
</div>
<?php endif; ?>

</body>
</html>
