// ══════════════════════════════════════════════════
//  DDG del Valle · Panel de Relatores
//  Capa de datos: fetch() → api.php → MySQL (PDO)
// ══════════════════════════════════════════════════

'use strict';

// ── Configuración ─────────────────────────────────
// Opcional: en relatores.html, antes de app.js →
//   <script>window.__DDG_API_BASE__ = 'http://localhost:8080';</script>
// Misma carpeta que api.php (barra final opcional).
const API_BASE =
  typeof window !== 'undefined' && typeof window.__DDG_API_BASE__ === 'string'
    ? window.__DDG_API_BASE__.replace(/\/?$/, '/')
    : '';
const API = `${API_BASE}api.php`;

// ── Estado en memoria ─────────────────────────────
let relatores      = [];   // caché local para filtros sin round-trip
let deleteTargetId = null;
let editingId      = null;
let toastTimer     = null;
let isSaving       = false;


// ══════════════════════════════════════════════════
//  CAPA DE API  (abstracción sobre fetch)
// ══════════════════════════════════════════════════

/**
 * Wrapper central: lanza fetch, parsea JSON, lanza Error si ok === false.
 */
async function apiFetch(url, options = {}) {
  const res = await fetch(url, {
    headers: { 'Content-Type': 'application/json' },
    ...options,
  });

  let json;
  try {
    json = await res.json();
  } catch {
    throw new Error(`El servidor devolvió una respuesta inválida (HTTP ${res.status}).`);
  }

  if (!json.ok) {
    throw new Error(json.error || `Error del servidor (HTTP ${res.status}).`);
  }

  return json.data;
}

// Verbos semánticos —————————————————————————————————
const apiGetAll = ()        => apiFetch(API);
const apiCreate = (body)    => apiFetch(API,                 { method: 'POST',   body: JSON.stringify(body) });
const apiUpdate = (id, body) => apiFetch(`${API}?id=${id}`,  { method: 'PUT',    body: JSON.stringify(body) });
const apiDelete = (id)      => apiFetch(`${API}?id=${id}`,   { method: 'DELETE' });


// ══════════════════════════════════════════════════
//  INIT
// ══════════════════════════════════════════════════
async function init() {
  setCurrentDate();
  await loadRelatores();
}

function setCurrentDate() {
  const el = $('currentDate');
  if (el) {
    el.textContent = new Date().toLocaleDateString('es-CL', {
      day: 'numeric', month: 'long', year: 'numeric',
    });
  }
}

/**
 * Mensaje útil según el error (file://, PHP caído, BD, HTML en vez de JSON).
 */
function humanizeLoadError(err) {
  const raw = err && err.message ? String(err.message) : '';

  if (/Failed to fetch|Load failed|NetworkError|networkerror/i.test(raw)) {
    return (
      '<strong>No se pudo contactar a api.php.</strong> Suele pasar si abres el panel con doble clic ' +
      '(<code class="text-[11px] bg-red-100/80 px-1 rounded">file://</code>): el navegador no ejecuta PHP. ' +
      'Levanta un servidor en la carpeta del proyecto y entra por HTTP, por ejemplo ' +
      '<code class="text-[11px] bg-red-100/80 px-1 rounded">php -S localhost:8080</code> y abre ' +
      '<code class="text-[11px] bg-red-100/80 px-1 rounded">http://localhost:8080/relatores.html</code>.'
    );
  }

  if (raw) {
    return (
      'No se pudo cargar los datos. ' +
      '<span class="block mt-2 text-red-800/90 font-mono text-[11px] leading-relaxed">' +
      h(raw) +
      '</span><span class="block mt-2 text-red-700/90">Revisa credenciales en <strong>api.php</strong> y que hayas importado <strong>database.sql</strong> (base <code class="text-[11px] bg-red-100/80 px-1 rounded">ddg_otec</code>, tabla <code class="text-[11px] bg-red-100/80 px-1 rounded">relatores</code>).</span>'
    );
  }

  return (
    'No se pudo conectar con el servidor. Verifica que <strong>api.php</strong> esté accesible ' +
    'y la base de datos esté configurada.'
  );
}

/**
 * Recarga todos los datos desde el servidor y re-renderiza la UI.
 */
async function loadRelatores() {
  setTableLoading(true);
  hideApiError();

  try {
    relatores = await apiGetAll();
    renderTable($('searchInput').value);
    updateStats();
  } catch (err) {
    showApiError(humanizeLoadError(err));
    console.error('[DDG API]', err);
  } finally {
    setTableLoading(false);
  }
}


// ══════════════════════════════════════════════════
//  AUTH  (login falso — sin cambios de UI)
// ══════════════════════════════════════════════════
function handleLogin() {
  const user  = $('loginUser').value.trim();
  const pass  = $('loginPass').value.trim();
  const errEl = $('loginError');

  if (user === 'admin' && pass === 'admin') {
    $('loginScreen').style.display = 'none';
    const dash = $('dashboard');
    dash.style.display       = 'flex';
    dash.style.flexDirection = 'column';
    errEl.classList.add('hidden');
    init();
  } else {
    errEl.classList.remove('hidden');
    $('loginPass').value = '';
    $('loginPass').focus();
    setTimeout(() => errEl.classList.add('hidden'), 4000);
  }
}

function handleLogout() {
  $('dashboard').style.display         = 'none';
  $('loginScreen').style.display       = 'flex';
  $('loginScreen').style.alignItems    = 'center';
  $('loginScreen').style.justifyContent = 'center';
  $('loginUser').value = '';
  $('loginPass').value = '';
  relatores = [];
}


// ══════════════════════════════════════════════════
//  RENDER TABLE  (sin cambios — trabaja sobre el caché)
// ══════════════════════════════════════════════════
function renderTable(query = '') {
  const tbody    = $('tableBody');
  const empty    = $('emptyState');
  const countEl  = $('tableCount');

  const q = query.trim().toLowerCase();
  const filtered = q
    ? relatores.filter(r =>
        r.nombre.toLowerCase().includes(q) ||
        r.rut.toLowerCase().includes(q)    ||
        r.curso.toLowerCase().includes(q)
      )
    : [...relatores];

  countEl.textContent = `${filtered.length} relator${filtered.length !== 1 ? 'es' : ''}`;

  if (filtered.length === 0) {
    tbody.innerHTML = '';
    empty.classList.remove('hidden');
    return;
  }
  empty.classList.add('hidden');
  tbody.innerHTML = filtered.map(buildRow).join('');
}

function buildRow(r) {
  const badge = vigenciaBadge(r.vigencia);
  return `
    <tr class="border-b border-slate-100 transition-colors">
      <td class="px-4 py-3.5 font-medium text-slate-700 whitespace-nowrap max-w-[200px] truncate" title="${h(r.curso)}">${h(r.curso)}</td>
      <td class="px-4 py-3.5 whitespace-nowrap">
        <span class="font-medium text-slate-800">${h(r.nombre)}</span>
      </td>
      <td class="px-4 py-3.5 font-mono text-xs text-slate-500 whitespace-nowrap">${h(r.rut)}</td>
      <td class="px-4 py-3.5 text-slate-500 whitespace-nowrap text-xs">${h(r.carrera)}</td>
      <td class="px-4 py-3.5 text-xs">
        <a href="mailto:${h(r.correo)}" class="text-brand-500 hover:text-brand-700 hover:underline underline-offset-2">${h(r.correo)}</a>
      </td>
      <td class="px-4 py-3.5 whitespace-nowrap">${badge}</td>
      <td class="px-4 py-3.5">
        <a href="${h(r.carpeta)}" target="_blank" rel="noopener"
          class="inline-flex items-center gap-1 text-xs text-brand-500 hover:text-brand-700 hover:underline underline-offset-2">
          <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>
          </svg>
          Abrir
        </a>
      </td>
      <td class="px-4 py-3.5">
        <button onclick="openTransferModal(${r.id})"
          class="inline-flex items-center gap-1.5 text-[11px] font-medium text-slate-600 hover:text-brand-600 border border-slate-200 hover:border-brand-200 hover:bg-brand-50 px-2.5 py-1 rounded-md transition-all">
          <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/>
          </svg>
          Ver datos
        </button>
      </td>
      <td class="px-4 py-3.5 text-xs text-slate-500 whitespace-nowrap">${h(r.telefono)}</td>
      <td class="px-4 py-3.5">
        <div class="flex items-center gap-0.5">
          <button onclick="openEditModal(${r.id})" title="Editar"
            class="w-7 h-7 flex items-center justify-center text-slate-400 hover:text-brand-600 hover:bg-brand-50 rounded-lg transition-all">
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
            </svg>
          </button>
          <button onclick="openDeleteModal(${r.id})" title="Eliminar"
            class="w-7 h-7 flex items-center justify-center text-slate-400 hover:text-red-500 hover:bg-red-50 rounded-lg transition-all">
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/>
            </svg>
          </button>
        </div>
      </td>
    </tr>
  `;
}

function vigenciaBadge(v) {
  const map = {
    'Activo':    'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200',
    'Inactivo':  'bg-slate-100  text-slate-500  ring-1 ring-inset ring-slate-200',
    'Pendiente': 'bg-amber-50   text-amber-700  ring-1 ring-inset ring-amber-200',
  };
  const cls = map[v] ?? map['Pendiente'];
  return `<span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold tracking-wide ${cls}">${h(v)}</span>`;
}

function updateStats() {
  $('statTotal').textContent      = relatores.length;
  $('statActivos').textContent    = relatores.filter(r => r.vigencia === 'Activo').length;
  $('statPendientes').textContent = relatores.filter(r => r.vigencia === 'Pendiente').length;
  $('statInactivos').textContent  = relatores.filter(r => r.vigencia === 'Inactivo').length;
}

function filterTable() {
  renderTable($('searchInput').value);
}


// ══════════════════════════════════════════════════
//  MODAL: ADD / EDIT
// ══════════════════════════════════════════════════
function openAddModal() {
  editingId = null;
  $('modalTitle').textContent = 'Agregar Relator';
  clearForm();
  showModal('formModal');
}

function openEditModal(id) {
  const r = relatores.find(x => x.id === id);
  if (!r) return;
  editingId = id;
  $('modalTitle').textContent = 'Editar Relator';
  clearForm();

  $('fCurso').value        = r.curso;
  $('fNombre').value       = r.nombre;
  $('fRut').value          = r.rut;
  $('fCarrera').value      = r.carrera;
  $('fCorreo').value       = r.correo;
  $('fTelefono').value     = r.telefono;
  $('fVigencia').value     = r.vigencia;
  $('fCarpeta').value      = r.carpeta;
  $('fBanco').value        = r.transferencia.banco;
  $('fTipoCuenta').value   = r.transferencia.tipoCuenta;
  $('fNumeroCuenta').value = r.transferencia.numeroCuenta;
  $('fCorreoTransf').value = r.transferencia.correo;

  showModal('formModal');
}

function closeFormModal() {
  hideModal('formModal');
  clearForm();
  editingId = null;
}

function clearForm() {
  [
    'fCurso', 'fNombre', 'fRut', 'fCarrera', 'fCorreo',
    'fTelefono', 'fCarpeta', 'fBanco', 'fNumeroCuenta', 'fCorreoTransf',
  ].forEach(id => { $(id).value = ''; });
  $('fVigencia').value   = 'Activo';
  $('fTipoCuenta').value = 'Cuenta Corriente';
  setFormError('');
}

/**
 * Guarda (crea o edita) un relator via API.
 * Maneja loading state del botón para evitar doble envío.
 */
async function saveRelator() {
  if (isSaving) return;

  const curso  = $('fCurso').value.trim();
  const nombre = $('fNombre').value.trim();
  const rut    = $('fRut').value.trim();

  if (!curso || !nombre || !rut) {
    setFormError('Los campos Curso, Nombre y RUT son obligatorios.');
    return;
  }

  const payload = {
    curso,
    nombre,
    rut,
    carrera:  $('fCarrera').value.trim(),
    correo:   $('fCorreo').value.trim(),
    telefono: $('fTelefono').value.trim(),
    vigencia: $('fVigencia').value,
    carpeta:  $('fCarpeta').value.trim() || '',
    transferencia: {
      banco:        $('fBanco').value.trim(),
      tipoCuenta:   $('fTipoCuenta').value,
      numeroCuenta: $('fNumeroCuenta').value.trim(),
      correo:       $('fCorreoTransf').value.trim(),
    },
  };

  setSaving(true);

  try {
    if (editingId !== null) {
      await apiUpdate(editingId, payload);
      showToast('success', 'Relator actualizado correctamente');
    } else {
      await apiCreate(payload);
      showToast('success', 'Relator agregado correctamente');
    }
    closeFormModal();
    await loadRelatores();          // refresca tabla y stats
  } catch (err) {
    setFormError(err.message || 'Error al guardar. Intenta nuevamente.');
    console.error('[DDG API]', err);
  } finally {
    setSaving(false);
  }
}

function setFormError(msg) {
  const err = $('formError');
  const txt = $('formErrorMsg');
  if (msg) {
    txt.textContent = msg;
    err.classList.remove('hidden');
  } else {
    err.classList.add('hidden');
  }
}

/**
 * Cambia el estado visual del botón "Guardar" durante la petición.
 */
function setSaving(saving) {
  isSaving = saving;
  const btn = document.querySelector('[onclick="saveRelator()"]');
  if (!btn) return;
  if (saving) {
    btn.disabled = true;
    btn.innerHTML = `
      <svg class="w-3.5 h-3.5 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" opacity=".25"/><path d="M21 12a9 9 0 00-9-9"/>
      </svg>
      Guardando…`;
  } else {
    btn.disabled = false;
    btn.textContent = 'Guardar Relator';
  }
}


// ══════════════════════════════════════════════════
//  MODAL: TRANSFERENCIA
// ══════════════════════════════════════════════════
function openTransferModal(id) {
  const r = relatores.find(x => x.id === id);
  if (!r) return;
  const t = r.transferencia;

  $('transferContent').innerHTML = `
    <div class="pb-3 mb-1 border-b border-slate-100">
      <p class="text-[10px] font-semibold text-slate-400 uppercase tracking-widest mb-1.5">Titular</p>
      <p class="text-sm font-semibold text-slate-800">${h(r.nombre)}</p>
      <p class="text-xs font-mono text-slate-400 mt-0.5">${h(r.rut)}</p>
    </div>
    ${transferRow('Banco', t.banco)}
    ${transferRow('Tipo de Cuenta', t.tipoCuenta)}
    ${transferRow('N° de Cuenta', t.numeroCuenta, 'font-mono')}
    ${transferRow('Correo', t.correo)}
  `;

  showModal('transferModal');
}

function transferRow(label, value, extraCls = '') {
  return `
    <div class="flex items-center justify-between py-2.5 border-b border-slate-50 last:border-0">
      <span class="text-[11px] text-slate-400">${label}</span>
      <span class="text-xs font-medium text-slate-800 ${extraCls}">${h(value || '—')}</span>
    </div>
  `;
}

function closeTransferModal() { hideModal('transferModal'); }


// ══════════════════════════════════════════════════
//  MODAL: DELETE
// ══════════════════════════════════════════════════
function openDeleteModal(id) {
  deleteTargetId = id;
  showModal('deleteModal');
}

function closeDeleteModal() {
  deleteTargetId = null;
  hideModal('deleteModal');
}

/**
 * Confirma y ejecuta el DELETE via API.
 * Cierra el modal antes de la petición para dar feedback inmediato.
 */
async function confirmDelete() {
  if (deleteTargetId === null) return;
  const id = deleteTargetId;
  closeDeleteModal();

  try {
    await apiDelete(id);
    showToast('warning', 'Relator eliminado del sistema');
    await loadRelatores();
  } catch (err) {
    showToast('warning', err.message || 'No se pudo eliminar. Intenta nuevamente.');
    console.error('[DDG API]', err);
  }
}


// ══════════════════════════════════════════════════
//  MODAL HELPERS
// ══════════════════════════════════════════════════
function showModal(id) {
  const el = $(id);
  el.classList.remove('hidden');
  const inner = el.querySelector('.modal-enter');
  if (inner) { inner.style.animation = 'none'; inner.offsetHeight; inner.style.animation = ''; }
  document.body.style.overflow = 'hidden';
}

function hideModal(id) {
  $(id).classList.add('hidden');
  const open = ['formModal', 'transferModal', 'deleteModal']
    .filter(m => !$(m).classList.contains('hidden'));
  if (open.length === 0) document.body.style.overflow = '';
}


// ══════════════════════════════════════════════════
//  ESTADOS DE CARGA
// ══════════════════════════════════════════════════

/**
 * Muestra filas skeleton mientras se espera la respuesta del servidor.
 */
function setTableLoading(loading) {
  if (!loading) return;
  $('emptyState').classList.add('hidden');
  $('tableCount').textContent = 'Cargando…';
  $('tableBody').innerHTML = Array.from({ length: 4 }).map(() => `
    <tr class="border-b border-slate-100">
      ${Array.from({ length: 10 }).map(() =>
        '<td class="px-4 py-4"><div class="h-2.5 bg-slate-100 rounded-full animate-pulse"></div></td>'
      ).join('')}
    </tr>
  `).join('');
}


// ══════════════════════════════════════════════════
//  ERROR BANNER  (inyectado dinámicamente en el DOM)
// ══════════════════════════════════════════════════

function showApiError(htmlMsg) {
  let banner = document.getElementById('apiBanner');

  if (!banner) {
    banner = document.createElement('div');
    banner.id = 'apiBanner';
    banner.className = [
      'flex items-start gap-3 bg-red-50 border border-red-200',
      'text-red-700 text-xs px-4 py-3 rounded-xl mb-5',
    ].join(' ');

    banner.innerHTML = `
      <svg class="w-4 h-4 mt-0.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
      </svg>
      <span id="apiBannerMsg"></span>
    `;

    // Insertarlo justo antes del bloque de stats (primer hijo de main)
    const main = document.querySelector('main');
    if (main) main.insertBefore(banner, main.firstChild);
  }

  document.getElementById('apiBannerMsg').innerHTML = htmlMsg;
  banner.style.display = 'flex';
}

function hideApiError() {
  const banner = document.getElementById('apiBanner');
  if (banner) banner.style.display = 'none';
}


// ══════════════════════════════════════════════════
//  TOAST
// ══════════════════════════════════════════════════
const TOAST_ICONS = {
  success: `<svg class="w-3.5 h-3.5 text-emerald-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>`,
  warning: `<svg class="w-3.5 h-3.5 text-amber-400"   viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>`,
  info:    `<svg class="w-3.5 h-3.5 text-brand-400"   viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>`,
};

function showToast(type = 'success', msg = 'Operación completada') {
  const toast = $('toast');
  $('toastIcon').innerHTML  = TOAST_ICONS[type] ?? TOAST_ICONS.success;
  $('toastMsg').textContent = msg;
  toast.classList.remove('hidden');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => toast.classList.add('hidden'), 3500);
}


// ══════════════════════════════════════════════════
//  UTILIDADES
// ══════════════════════════════════════════════════
function $(id) { return document.getElementById(id); }

function h(str) {
  if (str == null) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}


// ══════════════════════════════════════════════════
//  EVENT LISTENERS  (sin cambios)
// ══════════════════════════════════════════════════

['loginUser', 'loginPass'].forEach(id => {
  $(id)?.addEventListener('keydown', e => { if (e.key === 'Enter') handleLogin(); });
});

['formModal', 'transferModal', 'deleteModal'].forEach(id => {
  $(id)?.addEventListener('click', e => {
    if (e.target !== $(id)) return;
    if (id === 'formModal')     closeFormModal();
    if (id === 'transferModal') closeTransferModal();
    if (id === 'deleteModal')   closeDeleteModal();
  });
});

document.addEventListener('keydown', e => {
  if (e.key !== 'Escape') return;
  if (!$('formModal').classList.contains('hidden'))     closeFormModal();
  if (!$('transferModal').classList.contains('hidden')) closeTransferModal();
  if (!$('deleteModal').classList.contains('hidden'))   closeDeleteModal();
});
