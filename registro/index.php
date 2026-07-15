<?php
require_once __DIR__ . '/config.php';
ddg_require_auth();
$usuario = htmlspecialchars($_SESSION['ddg_user'] ?? 'admin');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Generador de Informes de Taller — DDG Del Valle Capacitaciones</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer"/>
<!-- PDF generation is now server-side via Dompdf (generate_pdf.php) -->

<style>
/* ─── Marca DDG Del Valle ─────────────────────────────────────────── */
:root {
  --ddg-primary: #2C4855;
  --ddg-secondary: #F1EAAF;
  --ddg-primary-hover: #23404a;
  --ddg-accent-soft: rgba(44, 72, 85, 0.1);
}

/* ─── Reset & Base ─────────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

#ddg-app {
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
  -webkit-font-smoothing: antialiased;
}

/* ─── Step Visibility ──────────────────────────────────────────────── */
.ddg-step { display: none; }
.ddg-step.ddg-active {
  display: block;
  animation: ddgFadeUp 0.32s cubic-bezier(0.4, 0, 0.2, 1);
}
@keyframes ddgFadeUp {
  from { opacity: 0; transform: translateY(10px); }
  to   { opacity: 1; transform: translateY(0); }
}

/* ─── Step Indicator ───────────────────────────────────────────────── */
.ddg-step-item {
  display: flex; flex-direction: column;
  align-items: center; gap: 6px;
}
.ddg-step-circle {
  width: 36px; height: 36px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 13px; font-weight: 700;
  transition: all 0.3s ease;
  border: 2px solid #e2e8f0;
  background: white; color: #94a3b8;
  position: relative; z-index: 1;
}
.ddg-step-circle.active {
  background: linear-gradient(135deg, var(--ddg-primary), #3d5f6e);
  border-color: transparent; color: white;
  box-shadow: 0 4px 12px rgba(44, 72, 85, 0.35);
}
.ddg-step-circle.done {
  background: #dcfce7; border-color: #86efac; color: #16a34a;
}
.ddg-step-label {
  font-size: 11px; font-weight: 600;
  color: #94a3b8; transition: color 0.3s; white-space: nowrap;
}
.ddg-step-label.active { color: var(--ddg-primary); }
.ddg-step-label.done   { color: #16a34a; }
.ddg-step-connector {
  height: 2px; width: 80px;
  background: #e2e8f0; margin: 0 4px; margin-bottom: 22px;
  transition: background 0.4s ease; flex-shrink: 0;
}
.ddg-step-connector.done { background: linear-gradient(90deg, #86efac, #4ade80); }

/* ─── Form Fields ──────────────────────────────────────────────────── */
.ddg-field { display: flex; flex-direction: column; gap: 6px; }
.ddg-label {
  font-size: 11px; font-weight: 700; color: #374151;
  text-transform: uppercase; letter-spacing: 0.06em;
}
.ddg-input, .ddg-select, .ddg-textarea {
  width: 100%; padding: 11px 14px;
  background: #f8fafc; border: 1.5px solid #e2e8f0;
  border-radius: 10px; font-size: 14px; color: #0f172a;
  transition: all 0.2s ease; outline: none;
  font-family: inherit; appearance: auto;
}
.ddg-input:hover:not(:focus), .ddg-select:hover:not(:focus), .ddg-textarea:hover:not(:focus) {
  border-color: #94a3b8; background: white;
}
.ddg-input:focus, .ddg-select:focus, .ddg-textarea:focus {
  border-color: var(--ddg-primary); box-shadow: 0 0 0 3px var(--ddg-accent-soft); background: white;
}
.ddg-input.ddg-error, .ddg-select.ddg-error, .ddg-textarea.ddg-error {
  border-color: #f87171; background: #fff5f5;
  box-shadow: 0 0 0 3px rgba(248,113,113,0.1);
}
.ddg-textarea { resize: vertical; min-height: 96px; line-height: 1.6; }

/* ─── Checkbox Pills ───────────────────────────────────────────────── */
.ddg-checkbox-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }
.ddg-checkbox-pill {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 12px; border: 1.5px solid #e2e8f0;
  border-radius: 10px; cursor: pointer; transition: all 0.15s ease;
  background: #f8fafc; font-size: 13px; font-weight: 500;
  color: #374151; user-select: none;
}
.ddg-checkbox-pill:hover { border-color: rgba(44, 72, 85, 0.35); background: var(--ddg-secondary); color: var(--ddg-primary); }
.ddg-checkbox-pill.checked { border-color: var(--ddg-primary); background: var(--ddg-secondary); color: var(--ddg-primary); }
.ddg-checkbox-pill input[type="checkbox"] {
  width: 15px; height: 15px; accent-color: #2C4855; flex-shrink: 0;
}

/* ─── Drag & Drop ──────────────────────────────────────────────────── */
#ddg-dropzone {
  border: 2px dashed #cbd5e1; border-radius: 16px;
  padding: 48px 24px; text-align: center; cursor: pointer;
  transition: all 0.2s ease; background: #f8fafc; position: relative;
}
#ddg-dropzone:hover, #ddg-dropzone.dragover { border-color: var(--ddg-primary); background: var(--ddg-secondary); }
#ddg-file-input { display: none; }
.ddg-preview-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-top: 16px; }
.ddg-preview-item {
  position: relative; aspect-ratio: 4/3;
  border-radius: 10px; overflow: hidden;
  border: 2px solid #e2e8f0; background: #f1f5f9;
}
.ddg-preview-item img { width: 100%; height: 100%; object-fit: cover; display: block; }
.ddg-remove-btn {
  position: absolute; top: 5px; right: 5px;
  width: 24px; height: 24px;
  background: rgba(220,38,38,0.9); color: white;
  border: none; border-radius: 50%; font-size: 11px; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  transition: all 0.15s; line-height: 1;
}
.ddg-remove-btn:hover { background: #dc2626; transform: scale(1.1); }

/* ─── Buttons ──────────────────────────────────────────────────────── */
.ddg-btn-primary {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 11px 24px;
  background: linear-gradient(135deg, var(--ddg-primary), #3d5f6e);
  color: white; font-weight: 600; font-size: 14px;
  border-radius: 10px; border: none; cursor: pointer;
  transition: all 0.2s ease;
  box-shadow: 0 4px 14px rgba(44, 72, 85, 0.3); font-family: inherit;
}
.ddg-btn-primary:hover {
  background: linear-gradient(135deg, var(--ddg-primary-hover), var(--ddg-primary));
  transform: translateY(-1px); box-shadow: 0 6px 20px rgba(44, 72, 85, 0.4);
}
.ddg-btn-secondary {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 11px 20px; background: white; color: #374151;
  font-weight: 600; font-size: 14px; border-radius: 10px;
  border: 1.5px solid #e2e8f0; cursor: pointer;
  transition: all 0.2s ease; font-family: inherit;
}
.ddg-btn-secondary:hover { background: #f8fafc; border-color: #94a3b8; transform: translateY(-1px); }
.ddg-btn-generate {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 12px 28px;
  background: linear-gradient(135deg, var(--ddg-primary), #355a68);
  color: white; font-weight: 700; font-size: 14px;
  border-radius: 10px; border: none; cursor: pointer;
  transition: all 0.2s ease;
  box-shadow: 0 4px 18px rgba(44, 72, 85, 0.38);
  letter-spacing: 0.01em; font-family: inherit;
}
.ddg-btn-generate:hover {
  background: linear-gradient(135deg, var(--ddg-primary-hover), #2a4a56);
  transform: translateY(-1px); box-shadow: 0 6px 24px rgba(44, 72, 85, 0.45);
}
.ddg-btn-generate:disabled { opacity: 0.55; cursor: not-allowed; transform: none; }

/* ─── Loading Overlay ──────────────────────────────────────────────── */
#ddg-loading {
  display: none; position: fixed; inset: 0;
  background: rgba(15,23,42,0.82);
  backdrop-filter: blur(6px);
  z-index: 9999;
  align-items: center; justify-content: center; flex-direction: column; gap: 16px;
}
#ddg-loading.show { display: flex; }
.ddg-spinner {
  width: 52px; height: 52px;
  border: 4px solid rgba(255,255,255,0.2);
  border-top-color: white; border-radius: 50%;
  animation: ddgSpin 0.75s linear infinite;
}
@keyframes ddgSpin { to { transform: rotate(360deg); } }

/* ─── Toast ────────────────────────────────────────────────────────── */
.ddg-toast {
  display: flex; align-items: center; gap: 10px;
  padding: 12px 18px; border-radius: 12px;
  font-size: 13px; font-weight: 500;
  box-shadow: 0 8px 32px rgba(0,0,0,0.12);
  animation: ddgToastIn 0.3s ease;
  max-width: 340px; font-family: inherit;
}
.ddg-toast.error   { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
.ddg-toast.success { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
.ddg-toast.info    { background: var(--ddg-secondary); color: var(--ddg-primary); border: 1px solid rgba(44, 72, 85, 0.2); }
.ddg-toast.hiding  { animation: ddgToastOut 0.3s ease forwards; }
@keyframes ddgToastIn  { from { opacity:0; transform:translateX(16px); } to { opacity:1; transform:translateX(0); } }
@keyframes ddgToastOut { from { opacity:1; transform:translateX(0); } to { opacity:0; transform:translateX(16px); } }

/* ─── History Drawer ───────────────────────────────────────────────── */
#ddg-drawer-backdrop {
  display: none; position: fixed; inset: 0;
  background: rgba(15,23,42,0.45); backdrop-filter: blur(3px);
  z-index: 8000;
}
#ddg-drawer-backdrop.open { display: block; }
#ddg-drawer {
  position: fixed; top: 0; right: -520px; height: 100vh;
  width: 500px; max-width: 95vw;
  background: white; box-shadow: -12px 0 48px rgba(0,0,0,0.18);
  z-index: 8001; display: flex; flex-direction: column;
  transition: right 0.35s cubic-bezier(0.4, 0, 0.2, 1);
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
}
#ddg-drawer.open { right: 0; }
.drawer-header {
  padding: 22px 24px 18px;
  border-bottom: 1px solid #f1f5f9;
  display: flex; align-items: center; justify-content: space-between;
  flex-shrink: 0;
}
.drawer-body { flex: 1; overflow-y: auto; padding: 16px 24px 24px; }
.history-item {
  display: flex; align-items: flex-start; gap: 14px;
  padding: 14px 16px;
  border: 1.5px solid #f1f5f9;
  border-radius: 12px; margin-bottom: 10px;
  background: #f8fafc; transition: border-color 0.15s;
}
.history-item:hover { border-color: #e2e8f0; background: white; }
.history-icon {
  width: 38px; height: 38px; flex-shrink: 0;
  background: #fef2f2; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
}
.history-info { flex: 1; min-width: 0; }
.history-name {
  font-size: 13px; font-weight: 700; color: #0f172a;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  margin-bottom: 3px;
}
.history-meta { font-size: 11px; color: #94a3b8; }
.history-actions { display: flex; gap: 6px; flex-shrink: 0; }
.h-btn {
  width: 32px; height: 32px; border-radius: 8px;
  border: 1.5px solid #e2e8f0; background: white;
  cursor: pointer; display: flex; align-items: center; justify-content: center;
  font-size: 12px; transition: all 0.15s;
}
.h-btn.dl  { color: var(--ddg-primary); }
.h-btn.dl:hover  { background: var(--ddg-secondary); border-color: rgba(44, 72, 85, 0.25); }
.h-btn.del { color: #dc2626; }
.h-btn.del:hover { background: #fef2f2; border-color: #fca5a5; }

/* ─── Responsive ───────────────────────────────────────────────────── */
@media (max-width: 520px) {
  .ddg-grid-2 { grid-template-columns: 1fr !important; }
  .ddg-step-connector { width: 40px; }
  .ddg-checkbox-grid { grid-template-columns: 1fr; }
  .ddg-preview-grid { grid-template-columns: repeat(2, 1fr); }
  #ddg-card .ddg-step { padding: 24px 20px 20px !important; }
  #ddg-nav { padding: 16px 20px !important; }
}

/* ─── Readonly fields ──────────────────────────────────────────────── */
.ddg-input[readonly] {
  background: #f1f5f9 !important;
  color: #64748b;
  cursor: not-allowed;
  border-color: #e2e8f0;
}
.ddg-input[readonly]:hover { background: #f1f5f9 !important; border-color: #e2e8f0; }

/* El estilo del PDF ahora vive en pdf_template.php (renderizado por Dompdf) */
</style>
</head>

<body style="margin:0;padding:0;min-height:100vh;background:linear-gradient(145deg,#F1EAAF 0%,#f8faf7 42%,#eef2f4 100%);">

<!-- ─── Loading Overlay ─────────────────────────────────────────────── -->
<div id="ddg-loading">
  <div class="ddg-spinner"></div>
  <div style="color:white;font-size:15px;font-weight:700;font-family:system-ui,sans-serif;">Generando documento PDF…</div>
  <div style="color:rgba(255,255,255,0.6);font-size:13px;font-family:system-ui,sans-serif;">Comprimiendo imágenes y construyendo el informe</div>
</div>

<!-- ─── Toast Container ─────────────────────────────────────────────── -->
<div id="ddg-toasts" style="position:fixed;bottom:24px;right:24px;z-index:10000;display:flex;flex-direction:column;gap:8px;align-items:flex-end;"></div>

<!-- ─── History Drawer ──────────────────────────────────────────────── -->
<div id="ddg-drawer-backdrop" onclick="ddgCloseHistory()"></div>

<div id="ddg-drawer">
  <div class="drawer-header">
    <div>
      <div style="font-size:16px;font-weight:800;color:#0f172a;margin-bottom:2px;">Historial de Informes</div>
      <div style="font-size:12px;color:#94a3b8;" id="ddg-history-count">Cargando…</div>
    </div>
    <button onclick="ddgCloseHistory()" style="width:36px;height:36px;border-radius:10px;border:1.5px solid #e2e8f0;background:white;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#64748b;font-size:14px;transition:all 0.15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='white'">
      <i class="fa-solid fa-xmark"></i>
    </button>
  </div>
  <div class="drawer-body" id="ddg-history-list">
    <!-- Populated by JS -->
  </div>
</div>

<!-- ─── Main App ────────────────────────────────────────────────────── -->
<div id="ddg-app" style="max-width:700px;margin:0 auto;padding:44px 16px 72px;">

  <!-- Topbar: sesión + historial -->
  <div style="display:flex;align-items:center;justify-content:flex-end;gap:10px;margin-bottom:20px;">
    <span style="font-size:12px;color:#64748b;display:flex;align-items:center;gap:6px;">
      <i class="fa-solid fa-circle-user" style="color:#94a3b8;"></i>
      <span><?= $usuario ?></span>
    </span>
    <button onclick="ddgOpenHistory()" style="display:inline-flex;align-items:center;gap:7px;padding:7px 16px;background:white;border:1.5px solid #e2e8f0;border-radius:100px;font-size:12px;font-weight:600;color:#374151;cursor:pointer;transition:all 0.2s;font-family:inherit;" onmouseover="this.style.borderColor='#94a3b8'" onmouseout="this.style.borderColor='#e2e8f0'">
      <i class="fa-solid fa-clock-rotate-left" style="color:#2C4855;font-size:11px;"></i>
      Historial de PDFs
    </button>
    <a href="logout.php" style="display:inline-flex;align-items:center;gap:7px;padding:7px 16px;background:white;border:1.5px solid #e2e8f0;border-radius:100px;font-size:12px;font-weight:600;color:#374151;text-decoration:none;cursor:pointer;transition:all 0.2s;" onmouseover="this.style.borderColor='#94a3b8'" onmouseout="this.style.borderColor='#e2e8f0'">
      <i class="fa-solid fa-right-from-bracket" style="color:#ef4444;font-size:11px;"></i>
      Salir
    </a>
  </div>

  <!-- App Header -->
  <div style="text-align:center;margin-bottom:36px;">
    <div style="display:inline-flex;align-items:center;gap:12px;background:rgba(255,255,255,0.95);border:1px solid rgba(44,72,85,0.12);border-radius:16px;padding:10px 20px;box-shadow:0 4px 24px rgba(44,72,85,0.08);margin-bottom:18px;">
      <img src="<?= htmlspecialchars(DDG_LOGO_URL, ENT_QUOTES, 'UTF-8') ?>" alt="DDG Del Valle Capacitaciones" width="160" height="48" style="height:48px;width:auto;max-width:200px;display:block;object-fit:contain;">
    </div>
    <h1 style="font-size:26px;font-weight:800;color:#0f172a;margin:0 0 8px;letter-spacing:-0.6px;">Generador de Informes de Taller</h1>
    <p style="font-size:14px;color:#64748b;margin:0;">Completa los datos en 3 pasos y descarga tu informe en PDF</p>
  </div>

  <!-- Steps Indicator -->
  <div style="display:flex;align-items:center;justify-content:center;margin-bottom:20px;">
    <div class="ddg-step-item">
      <div class="ddg-step-circle active" id="ddg-circle-1">
        <i class="fa-solid fa-check" id="ddg-icon-1" style="display:none;font-size:12px;"></i>
        <span id="ddg-num-1">1</span>
      </div>
      <div class="ddg-step-label active" id="ddg-label-1">Datos Generales</div>
    </div>
    <div class="ddg-step-connector" id="ddg-conn-1"></div>
    <div class="ddg-step-item">
      <div class="ddg-step-circle" id="ddg-circle-2">
        <i class="fa-solid fa-check" id="ddg-icon-2" style="display:none;font-size:12px;"></i>
        <span id="ddg-num-2">2</span>
      </div>
      <div class="ddg-step-label" id="ddg-label-2">Detalles del Taller</div>
    </div>
    <div class="ddg-step-connector" id="ddg-conn-2"></div>
    <div class="ddg-step-item">
      <div class="ddg-step-circle" id="ddg-circle-3">
        <i class="fa-solid fa-check" id="ddg-icon-3" style="display:none;font-size:12px;"></i>
        <span id="ddg-num-3">3</span>
      </div>
      <div class="ddg-step-label" id="ddg-label-3">Registro Fotográfico</div>
    </div>
  </div>

  <!-- Progress Bar -->
  <div style="height:3px;background:#e2e8f0;border-radius:100px;margin-bottom:28px;overflow:hidden;">
    <div id="ddg-progress" style="height:100%;width:33.33%;background:linear-gradient(90deg,#2C4855,#4a6b7a);border-radius:100px;transition:width 0.45s cubic-bezier(0.4,0,0.2,1);"></div>
  </div>

  <!-- ─── Wizard Card ──────────────────────────────────────────────── -->
  <div id="ddg-card" style="background:white;border-radius:20px;box-shadow:0 4px 40px rgba(0,0,0,0.09),0 1px 4px rgba(0,0,0,0.04);border:1px solid rgba(0,0,0,0.05);overflow:hidden;">

    <!-- STEP 1: Datos Generales -->
    <div id="ddg-step-1" class="ddg-step ddg-active" style="padding:36px 36px 28px;">
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:28px;">
        <div style="width:38px;height:38px;background:rgba(241,234,175,0.45);border:1px solid rgba(44,72,85,0.1);border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <i class="fa-solid fa-building-columns" style="color:#2C4855;font-size:15px;"></i>
        </div>
        <div>
          <h2 style="font-size:17px;font-weight:800;color:#0f172a;margin:0 0 2px;">Datos Generales</h2>
          <p style="font-size:13px;color:#64748b;margin:0;">Información del organismo capacitador y el relator</p>
        </div>
      </div>
      <div class="ddg-grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="ddg-field" style="grid-column:span 2;">
          <label class="ddg-label">Nombre OTEC <span style="color:#94a3b8;font-weight:400;font-size:10px;text-transform:none;letter-spacing:0;">(solo lectura)</span></label>
          <input type="text" id="f-otec" class="ddg-input" value="<?= htmlspecialchars(DDG_OTEC_NOMBRE, ENT_QUOTES, 'UTF-8') ?>" readonly tabindex="-1">
        </div>
        <div class="ddg-field">
          <label class="ddg-label">RUT de la OTEC <span style="color:#94a3b8;font-weight:400;font-size:10px;text-transform:none;letter-spacing:0;">(solo lectura)</span></label>
          <input type="text" id="f-rut" class="ddg-input" value="<?= htmlspecialchars(DDG_OTEC_RUT, ENT_QUOTES, 'UTF-8') ?>" readonly tabindex="-1">
        </div>
        <div class="ddg-field">
          <label class="ddg-label">Correo Electrónico <span style="color:#94a3b8;font-weight:400;font-size:10px;text-transform:none;letter-spacing:0;">(solo lectura)</span></label>
          <input type="email" id="f-correo" class="ddg-input" value="<?= htmlspecialchars(DDG_OTEC_CORREO, ENT_QUOTES, 'UTF-8') ?>" readonly tabindex="-1">
        </div>
        <div class="ddg-field">
          <label class="ddg-label">Nombre del Relator <span style="color:#ef4444;">*</span></label>
          <input type="text" id="f-relator" class="ddg-input" placeholder="Nombre completo" required>
        </div>
        <div class="ddg-field">
          <label class="ddg-label">Profesión / Especialidad <span style="color:#ef4444;">*</span></label>
          <input type="text" id="f-profesion" class="ddg-input" placeholder="Ej: Ingeniero Civil Industrial" required>
        </div>
        <div class="ddg-field" style="grid-column:span 2;">
          <label class="ddg-label">Región <span style="color:#ef4444;">*</span></label>
          <select id="f-region" class="ddg-select" required>
            <option value="">— Selecciona una región —</option>
            <option>Región de Arica y Parinacota</option>
            <option>Región de Tarapacá</option>
            <option>Región de Antofagasta</option>
            <option>Región de Atacama</option>
            <option>Región de Coquimbo</option>
            <option>Región de Valparaíso</option>
            <option>Región Metropolitana de Santiago</option>
            <option>Región del Libertador Gral. Bernardo O'Higgins</option>
            <option>Región del Maule</option>
            <option>Región de Ñuble</option>
            <option>Región del Biobío</option>
            <option>Región de La Araucanía</option>
            <option>Región de Los Ríos</option>
            <option>Región de Los Lagos</option>
            <option>Región de Aysén del Gral. Carlos Ibáñez del Campo</option>
            <option>Región de Magallanes y de la Antártica Chilena</option>
          </select>
        </div>
      </div>
    </div>

    <!-- STEP 2: Detalles del Taller -->
    <div id="ddg-step-2" class="ddg-step" style="padding:36px 36px 28px;">
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:28px;">
        <div style="width:38px;height:38px;background:rgba(241,234,175,0.45);border:1px solid rgba(44,72,85,0.1);border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <i class="fa-solid fa-chalkboard-user" style="color:#2C4855;font-size:15px;"></i>
        </div>
        <div>
          <h2 style="font-size:17px;font-weight:800;color:#0f172a;margin:0 0 2px;">Detalles del Taller</h2>
          <p style="font-size:13px;color:#64748b;margin:0;">Contenidos, objetivos e impacto del programa</p>
        </div>
      </div>
      <div style="display:flex;flex-direction:column;gap:16px;">
        <div class="ddg-field">
          <label class="ddg-label">Nombre del Taller <span style="color:#ef4444;">*</span></label>
          <input type="text" id="f-taller" class="ddg-input" placeholder="Ej: Taller de Liderazgo Organizacional" required>
        </div>
        <div class="ddg-grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
          <div class="ddg-field">
            <label class="ddg-label">Modalidad <span style="color:#ef4444;">*</span></label>
            <select id="f-modalidad" class="ddg-select" required>
              <option value="">— Selecciona —</option>
              <option>Presencial</option>
              <option>Online (Sincrónica)</option>
              <option>Online (Asincrónica)</option>
              <option>Híbrida</option>
              <option>E-learning</option>
            </select>
          </div>
          <div class="ddg-field">
            <label class="ddg-label">Duración Total <span style="color:#ef4444;">*</span></label>
            <input type="text" id="f-duracion" class="ddg-input" placeholder="Ej: 16 horas / 2 días" required>
          </div>
          <div class="ddg-field">
            <label class="ddg-label">Fecha de Inicio <span style="color:#ef4444;">*</span></label>
            <input type="date" id="f-fecha_inicio" class="ddg-input" required>
          </div>
          <div class="ddg-field">
            <label class="ddg-label">Fecha de Término <span style="color:#ef4444;">*</span></label>
            <input type="date" id="f-fecha_termino" class="ddg-input" required>
          </div>
          <div class="ddg-field" style="grid-column:span 2;">
            <label class="ddg-label">Horario <span style="color:#ef4444;">*</span></label>
            <input type="text" id="f-horario" class="ddg-input" placeholder="Ej: Lunes a Viernes, 09:00 – 13:00 hrs" required>
          </div>
        </div>
        <div class="ddg-field">
          <label class="ddg-label">Descripción General <span style="color:#ef4444;">*</span></label>
          <textarea id="f-descripcion" class="ddg-textarea" placeholder="Describe el contexto y propósito general del taller…" required></textarea>
        </div>
        <div class="ddg-field">
          <label class="ddg-label">Objetivos logrados <span style="color:#ef4444;">*</span></label>
          <textarea id="f-objetivos" class="ddg-textarea" placeholder="• Objetivo logrado 1&#10;• Objetivo logrado 2&#10;• Objetivo logrado 3" required></textarea>
        </div>
        <div class="ddg-field">
          <label class="ddg-label">Impacto para la institución <span style="color:#ef4444;">*</span></label>
          <textarea id="f-impacto" class="ddg-textarea" placeholder="Describe el impacto para la institución y sus colaboradores…" required style="min-height:80px;"></textarea>
        </div>
        <div class="ddg-field">
          <label class="ddg-label" style="margin-bottom:10px;">Metodología Utilizada</label>
          <div class="ddg-checkbox-grid">
            <label class="ddg-checkbox-pill"><input type="checkbox" name="metodologia" value="Movilidad" onchange="ddgTogglePill(this)"><i class="fa-solid fa-person-walking" style="font-size:12px;opacity:0.7;"></i> Movilidad</label>
            <label class="ddg-checkbox-pill"><input type="checkbox" name="metodologia" value="Aprendizaje práctico" onchange="ddgTogglePill(this)"><i class="fa-solid fa-hands-holding" style="font-size:12px;opacity:0.7;"></i> Aprendizaje práctico</label>
            <label class="ddg-checkbox-pill"><input type="checkbox" name="metodologia" value="Trabajo grupal" onchange="ddgTogglePill(this)"><i class="fa-solid fa-people-group" style="font-size:12px;opacity:0.7;"></i> Trabajo grupal</label>
            <label class="ddg-checkbox-pill"><input type="checkbox" name="metodologia" value="Exposición magistral" onchange="ddgTogglePill(this)"><i class="fa-solid fa-person-chalkboard" style="font-size:12px;opacity:0.7;"></i> Exposición magistral</label>
            <label class="ddg-checkbox-pill"><input type="checkbox" name="metodologia" value="Simulación" onchange="ddgTogglePill(this)"><i class="fa-solid fa-diagram-project" style="font-size:12px;opacity:0.7;"></i> Simulación</label>
            <label class="ddg-checkbox-pill"><input type="checkbox" name="metodologia" value="Estudio de casos" onchange="ddgTogglePill(this)"><i class="fa-solid fa-magnifying-glass-chart" style="font-size:12px;opacity:0.7;"></i> Estudio de casos</label>
            <label class="ddg-checkbox-pill"><input type="checkbox" name="metodologia" value="Role playing" onchange="ddgTogglePill(this)"><i class="fa-solid fa-masks-theater" style="font-size:12px;opacity:0.7;"></i> Role playing</label>
            <label class="ddg-checkbox-pill"><input type="checkbox" name="metodologia" value="Gamificación" onchange="ddgTogglePill(this)"><i class="fa-solid fa-gamepad" style="font-size:12px;opacity:0.7;"></i> Gamificación</label>
          </div>
        </div>
      </div>
    </div>

    <!-- STEP 3: Registro Fotográfico -->
    <div id="ddg-step-3" class="ddg-step" style="padding:36px 36px 28px;">
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:28px;">
        <div style="width:38px;height:38px;background:rgba(241,234,175,0.45);border:1px solid rgba(44,72,85,0.1);border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <i class="fa-solid fa-images" style="color:#2C4855;font-size:15px;"></i>
        </div>
        <div>
          <h2 style="font-size:17px;font-weight:800;color:#0f172a;margin:0 0 2px;">Registro Fotográfico</h2>
          <p style="font-size:13px;color:#64748b;margin:0;">Las imágenes se optimizan automáticamente antes de incluirlas</p>
        </div>
      </div>
      <div id="ddg-dropzone" onclick="document.getElementById('ddg-file-input').click()">
        <input type="file" id="ddg-file-input" multiple accept="image/*">
        <i class="fa-solid fa-cloud-arrow-up" style="font-size:40px;color:#94a3b8;margin-bottom:14px;display:block;"></i>
        <div style="font-size:15px;font-weight:700;color:#374151;margin-bottom:6px;">Arrastra tus fotos aquí</div>
        <div style="font-size:13px;color:#94a3b8;">o haz clic para seleccionar — JPG, PNG, WEBP</div>
        <div style="margin-top:14px;display:inline-flex;align-items:center;gap:6px;background:rgba(241,234,175,0.6);border:1px solid rgba(44,72,85,0.15);color:#2C4855;padding:6px 14px;border-radius:8px;font-size:12px;font-weight:600;">
          <i class="fa-solid fa-bolt"></i> Compresión automática activada
        </div>
      </div>
      <div id="ddg-processing" style="display:none;text-align:center;padding:16px 0;font-size:13px;color:#64748b;">
        <i class="fa-solid fa-gear fa-spin" style="margin-right:6px;"></i> Procesando imágenes…
      </div>
      <div id="ddg-img-count" style="display:none;margin-top:14px;padding:10px 14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;font-size:13px;color:#15803d;font-weight:600;align-items:center;gap:8px;">
        <i class="fa-solid fa-circle-check"></i>
        <span id="ddg-img-count-text"></span>
        <button onclick="ddgClearImages()" style="margin-left:auto;background:#fef2f2;border:none;color:#ef4444;cursor:pointer;font-size:12px;font-weight:600;padding:2px 6px;border-radius:6px;">
          <i class="fa-solid fa-trash-can"></i> Eliminar todas
        </button>
      </div>
      <div id="ddg-preview" class="ddg-preview-grid"></div>
      <div style="margin-top:20px;padding:14px;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;font-size:12px;color:#92400e;display:flex;gap:8px;align-items:flex-start;">
        <i class="fa-solid fa-triangle-exclamation" style="flex-shrink:0;margin-top:1px;"></i>
        <span><strong>Recomendación:</strong> Para un PDF fluido, sube máximo 6 fotografías. Las imágenes se comprimirán a 800px y calidad 50% automáticamente.</span>
      </div>

      <!-- Registro de Asistencia (opcional) -->
      <div style="margin-top:24px;border-top:1px solid #f1f5f9;padding-top:20px;">
        <div class="ddg-field">
          <label class="ddg-label" style="display:flex;align-items:center;gap:8px;">
            Registro de Asistencia
            <span style="font-size:10px;font-weight:500;color:#94a3b8;text-transform:none;letter-spacing:0;background:#f1f5f9;padding:2px 8px;border-radius:100px;">Opcional</span>
          </label>
          <p style="font-size:12px;color:#64748b;margin:0 0 10px;">Adjunta una imagen del registro de asistencia. Se incluirá como anexo al final del PDF.</p>
          <label id="ddg-asistencia-label" style="display:flex;align-items:center;gap:10px;padding:11px 14px;background:#f8fafc;border:1.5px dashed #cbd5e1;border-radius:10px;cursor:pointer;font-size:13px;color:#64748b;transition:all 0.2s;" onmouseover="this.style.borderColor='#2C4855';this.style.background='#F1EAAF30';" onmouseout="this.style.borderColor='#cbd5e1';this.style.background='#f8fafc';">
            <i class="fa-solid fa-paperclip" style="color:#94a3b8;font-size:14px;"></i>
            <span id="ddg-asistencia-name">Seleccionar imagen (JPG, PNG, WEBP)</span>
            <input type="file" id="f-asistencia" accept="image/jpeg, image/png, image/webp" style="display:none;">
          </label>
          <div id="ddg-asistencia-preview" style="display:none;margin-top:12px;"></div>
        </div>
      </div>
    </div>

    <!-- ─── Card Footer / Navigation ─────────────────────────────── -->
    <div id="ddg-nav" style="padding:20px 36px;border-top:1px solid #f1f5f9;background:#f8fafc;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
      <button id="ddg-btn-back" class="ddg-btn-secondary" onclick="ddgGoBack()" style="display:none;">
        <i class="fa-solid fa-arrow-left"></i> Anterior
      </button>
      <div id="ddg-nav-spacer"></div>
      <div style="display:flex;align-items:center;gap:10px;">
        <button id="ddg-btn-next" class="ddg-btn-primary" onclick="ddgGoNext()">
          Siguiente <i class="fa-solid fa-arrow-right"></i>
        </button>
        <button id="ddg-btn-generate" class="ddg-btn-generate" onclick="ddgGeneratePDF()" style="display:none;">
          <i class="fa-solid fa-file-pdf"></i> Generar Documento Final
        </button>
      </div>
    </div>

  </div><!-- /ddg-card -->
</div><!-- /ddg-app -->


<!-- El PDF ahora se genera 100% server-side con Dompdf (ver generate_pdf.php + pdf_template.php) -->


<!-- ════════════════════════════════════════════════════════════════════
     JAVASCRIPT ENGINE
═════════════════════════════════════════════════════════════════════ -->
<script>
(function () {
  'use strict';

  /* ── State ─────────────────────────────────────────────────────── */
  const STATE = { step: 1, images: [], asistencia: null };

  /* ── Step Navigation ────────────────────────────────────────────── */
  function goToStep(target) {
    if (target > STATE.step && !validateStep(STATE.step)) return;

    for (let i = 1; i < target; i++) {
      const circle = document.getElementById('ddg-circle-' + i);
      const num    = document.getElementById('ddg-num-' + i);
      const icon   = document.getElementById('ddg-icon-' + i);
      const label  = document.getElementById('ddg-label-' + i);
      const conn   = document.getElementById('ddg-conn-' + i);
      if (circle) { circle.classList.remove('active'); circle.classList.add('done'); }
      if (num)    num.style.display = 'none';
      if (icon)   icon.style.display = 'block';
      if (label)  { label.classList.remove('active'); label.classList.add('done'); }
      if (conn)   conn.classList.add('done');
    }

    for (let i = 1; i <= 3; i++) {
      const circle = document.getElementById('ddg-circle-' + i);
      const num    = document.getElementById('ddg-num-' + i);
      const icon   = document.getElementById('ddg-icon-' + i);
      const label  = document.getElementById('ddg-label-' + i);
      if (!circle) continue;
      if (i === target) {
        circle.classList.add('active'); circle.classList.remove('done');
        num.style.display = ''; icon.style.display = 'none';
        label.classList.add('active'); label.classList.remove('done');
      } else if (i > target) {
        circle.classList.remove('active', 'done');
        num.style.display = ''; icon.style.display = 'none';
        label.classList.remove('active', 'done');
        const conn = document.getElementById('ddg-conn-' + i);
        if (conn) conn.classList.remove('done');
      }
    }

    document.querySelectorAll('.ddg-step').forEach((el, idx) => {
      el.classList.remove('ddg-active');
      el.style.display = 'none';
      if (idx + 1 === target) { el.style.display = 'block'; el.classList.add('ddg-active'); }
    });

    document.getElementById('ddg-progress').style.width = (target / 3 * 100).toFixed(2) + '%';

    const btnBack     = document.getElementById('ddg-btn-back');
    const btnNext     = document.getElementById('ddg-btn-next');
    const btnGenerate = document.getElementById('ddg-btn-generate');
    const spacer      = document.getElementById('ddg-nav-spacer');

    btnBack.style.display     = target > 1 ? 'inline-flex' : 'none';
    btnNext.style.display     = target < 3 ? 'inline-flex' : 'none';
    btnGenerate.style.display = target === 3 ? 'inline-flex' : 'none';
    spacer.style.display      = target === 1 ? 'block' : 'none';

    STATE.step = target;
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  window.ddgGoNext = function () { goToStep(STATE.step + 1); };
  window.ddgGoBack = function () { goToStep(STATE.step - 1); };

  /* ── Validation ─────────────────────────────────────────────────── */
  function validateStep(step) {
    const stepEl = document.getElementById('ddg-step-' + step);
    if (!stepEl) return true;
    const fields = stepEl.querySelectorAll('[required]');
    let ok = true;
    fields.forEach(f => {
      f.classList.remove('ddg-error');
      if (!f.value.trim()) { f.classList.add('ddg-error'); ok = false; }
    });
    if (!ok) showToast('<i class="fa-solid fa-circle-exclamation"></i> Completa todos los campos obligatorios.', 'error');
    return ok;
  }

  document.addEventListener('input',  e => { if (e.target.classList.contains('ddg-error')) e.target.classList.remove('ddg-error'); });
  document.addEventListener('change', e => { if (e.target.classList.contains('ddg-error')) e.target.classList.remove('ddg-error'); });

  /* ── Checkbox Pills ─────────────────────────────────────────────── */
  window.ddgTogglePill = function (input) {
    const pill = input.closest('.ddg-checkbox-pill');
    if (input.checked) pill.classList.add('checked');
    else pill.classList.remove('checked');
  };

  /* ── Image Compression ──────────────────────────────────────────── */
  function compressImage(file) {
    return new Promise(resolve => {
      const reader = new FileReader();
      reader.onload = e => {
        const img = new Image();
        img.onload = () => {
          const MAX = 800;
          let w = img.naturalWidth, h = img.naturalHeight;
          if (w > MAX) { h = Math.round(h * MAX / w); w = MAX; }
          const canvas = document.createElement('canvas');
          canvas.width = w; canvas.height = h;
          canvas.getContext('2d').drawImage(img, 0, 0, w, h);
          resolve({ dataUrl: canvas.toDataURL('image/jpeg', 0.5), name: file.name });
        };
        img.src = e.target.result;
      };
      reader.readAsDataURL(file);
    });
  }

  /* ── Drop Zone ──────────────────────────────────────────────────── */
  const dropzone  = document.getElementById('ddg-dropzone');
  const fileInput = document.getElementById('ddg-file-input');

  dropzone.addEventListener('dragover',  e => { e.preventDefault(); dropzone.classList.add('dragover'); });
  dropzone.addEventListener('dragleave', () => dropzone.classList.remove('dragover'));
  dropzone.addEventListener('drop', e => {
    e.preventDefault();
    dropzone.classList.remove('dragover');
    handleFiles(Array.from(e.dataTransfer.files).filter(f => f.type.startsWith('image/')));
  });
  fileInput.addEventListener('change', () => { handleFiles(Array.from(fileInput.files)); fileInput.value = ''; });

  async function handleFiles(files) {
    if (!files.length) return;
    if (STATE.images.length + files.length > 6) {
      showToast('<i class="fa-solid fa-triangle-exclamation"></i> Máximo 6 imágenes permitidas.', 'error');
      files = files.slice(0, 6 - STATE.images.length);
    }
    document.getElementById('ddg-processing').style.display = 'block';
    const compressed = await Promise.all(files.map(compressImage));
    STATE.images.push(...compressed);
    renderPreviews();
    document.getElementById('ddg-processing').style.display = 'none';
    updateImageCount();
  }

  function renderPreviews() {
    const grid = document.getElementById('ddg-preview');
    grid.innerHTML = '';
    STATE.images.forEach((img, idx) => {
      const item = document.createElement('div');
      item.className = 'ddg-preview-item';
      item.innerHTML = `
        <img src="${img.dataUrl}" alt="${img.name}">
        <button class="ddg-remove-btn" onclick="ddgRemoveImage(${idx})" title="Eliminar">
          <i class="fa-solid fa-xmark"></i>
        </button>`;
      grid.appendChild(item);
    });
  }

  function updateImageCount() {
    const badge = document.getElementById('ddg-img-count');
    const text  = document.getElementById('ddg-img-count-text');
    if (STATE.images.length > 0) {
      badge.style.display = 'flex';
      text.textContent = STATE.images.length + ' imagen' + (STATE.images.length !== 1 ? 'es' : '') + ' lista' + (STATE.images.length !== 1 ? 's' : '') + ' para incluir en el informe';
    } else {
      badge.style.display = 'none';
    }
  }

  window.ddgRemoveImage = function (idx) { STATE.images.splice(idx, 1); renderPreviews(); updateImageCount(); };
  window.ddgClearImages = function () { STATE.images = []; renderPreviews(); updateImageCount(); };

  /* ── Asistencia ─────────────────────────────────────────────────── */
  const asistenciaInput = document.getElementById('f-asistencia');
  asistenciaInput.addEventListener('change', async () => {
    const file = asistenciaInput.files[0];
    if (!file) { STATE.asistencia = null; renderAsistenciaPreview(); return; }
    document.getElementById('ddg-asistencia-name').textContent = 'Procesando…';
    const result = await compressImage(file);
    STATE.asistencia = result.dataUrl;
    asistenciaInput.value = '';
    renderAsistenciaPreview();
  });

  function renderAsistenciaPreview() {
    const preview = document.getElementById('ddg-asistencia-preview');
    const nameEl  = document.getElementById('ddg-asistencia-name');
    if (STATE.asistencia) {
      preview.style.display = 'block';
      preview.innerHTML = `
        <div style="position:relative;display:inline-block;max-width:100%;">
          <img src="${STATE.asistencia}" style="max-width:100%;max-height:180px;border-radius:10px;border:1.5px solid #e2e8f0;display:block;">
          <button onclick="ddgClearAsistencia()" title="Eliminar" style="position:absolute;top:6px;right:6px;width:26px;height:26px;background:rgba(220,38,38,0.9);color:white;border:none;border-radius:50%;cursor:pointer;font-size:12px;display:flex;align-items:center;justify-content:center;">
            <i class="fa-solid fa-xmark"></i>
          </button>
        </div>`;
      nameEl.textContent = 'Imagen adjunta ✓';
    } else {
      preview.style.display = 'none';
      preview.innerHTML = '';
      nameEl.textContent = 'Seleccionar imagen (JPG, PNG, WEBP)';
    }
  }

  window.ddgClearAsistencia = function () { STATE.asistencia = null; renderAsistenciaPreview(); };

  /* ── Collect Form Data ──────────────────────────────────────────── */
  function getData() {
    const methodologies = Array.from(document.querySelectorAll('input[name="metodologia"]:checked')).map(cb => cb.value);
    return {
      otec: val('f-otec'), rut: val('f-rut'), correo: val('f-correo'),
      relator: val('f-relator'), profesion: val('f-profesion'), region: val('f-region'),
      taller: val('f-taller'), modalidad: val('f-modalidad'), duracion: val('f-duracion'),
      fecha_inicio: val('f-fecha_inicio'), fecha_termino: val('f-fecha_termino'),
      horario: val('f-horario'),
      descripcion: val('f-descripcion'), objetivos: val('f-objetivos'),
      impacto: val('f-impacto'), metodologia: methodologies,
      images: STATE.images, asistencia: STATE.asistencia
    };
  }

  function val(id) { const el = document.getElementById(id); return el ? el.value.trim() : ''; }

  function escHtml(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  /* ── Generate PDF (server-side via Dompdf) ──────────────────────── */
  window.ddgGeneratePDF = async function () {
    const data    = getData();
    const loading = document.getElementById('ddg-loading');

    // Validación final
    const required = ['otec','rut','correo','relator','profesion','region',
                      'taller','modalidad','duracion','fecha_inicio','fecha_termino','horario',
                      'descripcion','objetivos','impacto'];
    for (const k of required) {
      if (!data[k]) {
        showToast('<i class="fa-solid fa-circle-exclamation"></i> Completa todos los campos obligatorios.', 'error');
        return;
      }
    }

    loading.classList.add('show');

    try {
      const res = await fetch('generate_pdf.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(data),
        credentials: 'same-origin'
      });

      const raw = await res.text();
      let json = {};
      try {
        json = raw ? JSON.parse(raw) : {};
      } catch (parseErr) {
        console.error('[DDG] Respuesta no JSON:', raw.substring(0, 800));
        throw new Error('El servidor no devolvió JSON (¿error PHP?). Revisa el archivo generate_pdf.php en el hosting.');
      }

      if (!res.ok || !json.ok) {
        const msg = [json.error, json.detail, json.hint].filter(Boolean).join(' — ');
        throw new Error(msg || ('HTTP ' + res.status));
      }

      // Descargar el PDF desde el endpoint seguro
      const a = document.createElement('a');
      a.href = json.download_url;
      a.target = '_blank';
      a.rel = 'noopener';
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);

      showToast('<i class="fa-solid fa-circle-check"></i> PDF generado y guardado en el historial.', 'success');

      // Si el panel de historial está abierto, refrescar
      if (document.getElementById('ddg-drawer').classList.contains('open')) {
        loadHistory();
      }
    } catch (err) {
      console.error('[DDG PDF Error]', err);
      showToast('<i class="fa-solid fa-circle-xmark"></i> Error: ' + (err.message || 'no se pudo generar el PDF.'), 'error');
    } finally {
      loading.classList.remove('show');
    }
  };

  /* ── History Drawer ─────────────────────────────────────────────── */
  window.ddgOpenHistory = async function () {
    document.getElementById('ddg-drawer').classList.add('open');
    document.getElementById('ddg-drawer-backdrop').classList.add('open');
    document.body.style.overflow = 'hidden';
    await loadHistory();
  };

  window.ddgCloseHistory = function () {
    document.getElementById('ddg-drawer').classList.remove('open');
    document.getElementById('ddg-drawer-backdrop').classList.remove('open');
    document.body.style.overflow = '';
  };

  async function loadHistory() {
    const list = document.getElementById('ddg-history-list');
    const count = document.getElementById('ddg-history-count');
    list.innerHTML = '<div style="text-align:center;padding:32px 0;color:#94a3b8;font-size:13px;"><i class="fa-solid fa-gear fa-spin" style="margin-right:6px;"></i> Cargando historial…</div>';
    try {
      const res = await fetch('get_history.php', { credentials: 'same-origin' });
      const raw = await res.text();
      let history = [];
      try { history = raw ? JSON.parse(raw) : []; } catch (e) { throw new Error('Respuesta inválida del servidor.'); }
      if (!res.ok || !Array.isArray(history)) {
        throw new Error((history && history.error) ? history.error : 'No se pudo cargar el historial.');
      }

      count.textContent = history.length + (history.length === 1 ? ' informe guardado' : ' informes guardados');

      if (history.length === 0) {
        list.innerHTML = `
          <div style="text-align:center;padding:48px 24px;">
            <i class="fa-solid fa-folder-open" style="font-size:36px;color:#e2e8f0;margin-bottom:14px;display:block;"></i>
            <div style="font-size:14px;font-weight:600;color:#94a3b8;margin-bottom:6px;">Sin informes todavía</div>
            <div style="font-size:12px;color:#cbd5e1;">Los PDFs que generes aparecerán aquí automáticamente.</div>
          </div>`;
        return;
      }

      list.innerHTML = '';
      history.forEach(item => {
        const sizeKB  = Math.round((item.size_bytes || 0) / 1024);
        const dateStr = item.created_at ? item.created_at.substring(0, 16).replace('T', ' ') : '—';
        const div = document.createElement('div');
        div.className = 'history-item';
        div.id = 'hist-' + item.id;
        div.innerHTML = `
          <div class="history-icon">
            <i class="fa-solid fa-file-pdf" style="color:#dc2626;font-size:16px;"></i>
          </div>
          <div class="history-info">
            <div class="history-name" title="${escHtml(item.taller)}">${escHtml(item.taller || 'Sin nombre')}</div>
            <div class="history-meta">
              <i class="fa-solid fa-user" style="margin-right:4px;font-size:10px;"></i>${escHtml(item.relator || '—')} &nbsp;·&nbsp;
              <i class="fa-regular fa-calendar" style="margin-right:4px;font-size:10px;"></i>${escHtml(dateStr)} &nbsp;·&nbsp;
              ${sizeKB} KB
            </div>
          </div>
          <div class="history-actions">
            <button class="h-btn dl" title="Descargar" onclick="ddgDownloadPDF('${escHtml(item.id)}')">
              <i class="fa-solid fa-download"></i>
            </button>
            <button class="h-btn del" title="Eliminar" onclick="ddgDeletePDF('${escHtml(item.id)}')">
              <i class="fa-solid fa-trash-can"></i>
            </button>
          </div>`;
        list.appendChild(div);
      });
    } catch (e) {
      list.innerHTML = '<div style="text-align:center;padding:32px 0;color:#dc2626;font-size:13px;"><i class="fa-solid fa-circle-xmark"></i> Error al cargar el historial.</div>';
    }
  }

  window.ddgDownloadPDF = function (id) {
    window.open('download_pdf.php?id=' + id, '_blank');
  };

  window.ddgDeletePDF = async function (id) {
    if (!confirm('¿Eliminar este informe del historial?\nEsta acción no se puede deshacer.')) return;
    try {
      const fd = new FormData();
      fd.append('id', id);
      const res = await fetch('delete_pdf.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      const raw = await res.text();
      let json = {};
      try { json = raw ? JSON.parse(raw) : {}; } catch (e) { throw new Error('Respuesta inválida del servidor.'); }
      if (json.ok) {
        const el = document.getElementById('hist-' + id);
        if (el) {
          el.style.opacity = '0'; el.style.transform = 'translateX(20px)';
          el.style.transition = 'all 0.25s ease';
          setTimeout(() => { el.remove(); updateHistoryCount(); }, 260);
        }
        showToast('<i class="fa-solid fa-circle-check"></i> Informe eliminado.', 'success');
      } else {
        showToast('<i class="fa-solid fa-circle-xmark"></i> ' + (json.error || 'Error al eliminar.'), 'error');
      }
    } catch (e) {
      showToast('<i class="fa-solid fa-circle-xmark"></i> Error de conexión.', 'error');
    }
  };

  function updateHistoryCount() {
    const count = document.getElementById('ddg-history-count');
    const items = document.querySelectorAll('.history-item').length;
    count.textContent = items + (items === 1 ? ' informe guardado' : ' informes guardados');
    if (items === 0) {
      document.getElementById('ddg-history-list').innerHTML = `
        <div style="text-align:center;padding:48px 24px;">
          <i class="fa-solid fa-folder-open" style="font-size:36px;color:#e2e8f0;margin-bottom:14px;display:block;"></i>
          <div style="font-size:14px;font-weight:600;color:#94a3b8;margin-bottom:6px;">Sin informes todavía</div>
          <div style="font-size:12px;color:#cbd5e1;">Los PDFs que generes aparecerán aquí automáticamente.</div>
        </div>`;
    }
  }

  function escHtml(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  /* ── Toast ──────────────────────────────────────────────────────── */
  function showToast(htmlMsg, type) {
    const container = document.getElementById('ddg-toasts');
    const toast = document.createElement('div');
    toast.className = 'ddg-toast ' + type;
    toast.innerHTML = htmlMsg;
    container.appendChild(toast);
    setTimeout(() => {
      toast.classList.add('hiding');
      setTimeout(() => toast.remove(), 350);
    }, 3800);
  }

  // Exponer para la eliminación desde el drawer
  window._ddgShowToast = showToast;

})();
</script>
</body>
</html>
