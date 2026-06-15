// ══════════════════════════════════════════════════
//  DDG del Valle · Inventario y Agenda de Clases
//  Modelo: activos ← asignaciones → clases  (M:M)
// ══════════════════════════════════════════════════

'use strict';

const API_URL = 'inventario_api.php';

// ── Estado global ─────────────────────────────────
let activos      = [];
let clases       = [];
let asignaciones = [];

let currentTab          = 'inventario';
let editingActivoId     = null;
let editingClaseId      = null;
let managingClaseId     = null;   // clase abierta en modal de asignaciones
let deleteTarget        = null;   // { type: 'activo'|'clase', id }
let toastTimer          = null;


// ══════════════════════════════════════════════════
//  MOCK DATA — fechas relativas a HOY
// ══════════════════════════════════════════════════
function dStr(delta = 0) {
  const d = new Date();
  d.setDate(d.getDate() + delta);
  return d.toISOString().split('T')[0];
}

const MOCK_ACTIVOS = [
  { id:1, codigo:'TAB-001', nombre:'Tablet Samsung A7 Lite',  categoria:'Tecnología',       cantidad_total:4, estado:'Bueno',         descripcion:'Tablets para uso en sala' },
  { id:2, codigo:'PRY-001', nombre:'Proyector Epson EB-X51',  categoria:'Tecnología',       cantidad_total:2, estado:'Bueno',         descripcion:'Proyectores HDMI/VGA' },
  { id:3, codigo:'PLU-001', nombre:'Set de Plumones Pizarra', categoria:'Material Didáctico',cantidad_total:6, estado:'Bueno',         descripcion:'Sets 4 colores para pizarra blanca' },
  { id:4, codigo:'EXT-001', nombre:'Cable HDMI 3m',           categoria:'Tecnología',       cantidad_total:3, estado:'Regular',       descripcion:'Cables extensores para proyector' },
  { id:5, codigo:'PNT-001', nombre:'Puntero Láser',           categoria:'Tecnología',       cantidad_total:2, estado:'Bueno',         descripcion:'Con funciones de presentación' },
  { id:6, codigo:'BOT-001', nombre:'Botiquín de Primeros Aux',categoria:'Equipamiento',     cantidad_total:2, estado:'Bueno',         descripcion:'Equipado con materiales básicos' },
];

const MOCK_CLASES = [
  { id:1, curso:'Primeros Auxilios Básicos',              relator:'María González',   fecha: dStr(0),  hora_inicio:'10:00', hora_fin:'13:00', sala:'Sala A', estado:'En Curso' },
  { id:2, curso:'Seguridad Industrial y Prev. de Riesgos',relator:'Carlos Pérez',    fecha: dStr(0),  hora_inicio:'14:00', hora_fin:'17:00', sala:'Sala B', estado:'Programada' },
  { id:3, curso:'Excel Avanzado para Gestión',            relator:'Ana Martínez',    fecha: dStr(1),  hora_inicio:'09:00', hora_fin:'12:00', sala:'Sala A', estado:'Programada' },
  { id:4, curso:'Atención al Cliente',                    relator:'Roberto Silva',   fecha: dStr(3),  hora_inicio:'10:00', hora_fin:'13:00', sala:'Sala C', estado:'Programada' },
  { id:5, curso:'Liderazgo y Trabajo en Equipo',          relator:'Patricia Flores', fecha: dStr(-1), hora_inicio:'14:00', hora_fin:'17:00', sala:'Sala B', estado:'Finalizada' },
];

// Asignaciones — clase1: 2 tablets + 1 proyector + 1 botiquín
//               clase2: 1 tablet + 1 cable HDMI
//               clase5 (Finalizada): 2 tablets → NO cuentan como "en uso"
const MOCK_ASIGNACIONES = [
  { id:1, clase_id:1, activo_id:1, cantidad:2 },
  { id:2, clase_id:1, activo_id:2, cantidad:1 },
  { id:3, clase_id:1, activo_id:6, cantidad:1 },
  { id:4, clase_id:2, activo_id:1, cantidad:1 },
  { id:5, clase_id:2, activo_id:4, cantidad:1 },
  { id:6, clase_id:5, activo_id:1, cantidad:2 }, // clase finalizada → no en uso
];

// ══════════════════════════════════════════════════
//  API
// ══════════════════════════════════════════════════
async function apiRequest(action, method = 'GET', payload = null) {
  const opts = {
    method,
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
  };

  if (payload) opts.body = JSON.stringify(payload);

  const res = await fetch(`${API_URL}?action=${encodeURIComponent(action)}`, opts);
  const json = await res.json().catch(() => null);

  if (!res.ok || !json || json.ok !== true) {
    const msg = json?.error || `Error HTTP ${res.status}`;
    throw new Error(msg);
  }
  return json.data;
}

async function loadAll() {
  const data = await apiRequest('state', 'GET');
  if (!data || !Array.isArray(data.activos) || !Array.isArray(data.clases) || !Array.isArray(data.asignaciones)) {
    throw new Error('Estado remoto inválido.');
  }
  activos = data.activos;
  clases = data.clases;
  asignaciones = data.asignaciones;
}

async function persist() {
  const nextState = { activos, clases, asignaciones };
  const data = await apiRequest('save_state', 'POST', { state: nextState });
  activos = data.activos;
  clases = data.clases;
  asignaciones = data.asignaciones;
}

function nextId(arr) {
  return arr.length > 0 ? Math.max(...arr.map(x => x.id)) + 1 : 1;
}


// ══════════════════════════════════════════════════
//  DISPONIBILIDAD — núcleo del negocio
// ══════════════════════════════════════════════════

/**
 * Calcula cuántas unidades de un activo están en uso
 * en clases ACTIVAS (no Finalizada ni Cancelada).
 * excludeClaseId: excluye esa clase del cálculo
 *   → útil para saber "cuánto queda disponible si quiero asignar a esta clase"
 */
function getEnUso(activoId, excludeClaseId = null) {
  const activasIds = new Set(
    clases
      .filter(c => c.estado !== 'Finalizada' && c.estado !== 'Cancelada')
      .map(c => c.id)
  );
  return asignaciones
    .filter(a =>
      a.activo_id === activoId &&
      activasIds.has(a.clase_id) &&
      a.clase_id !== excludeClaseId
    )
    .reduce((sum, a) => sum + a.cantidad, 0);
}

function getDisponible(activoId, excludeClaseId = null) {
  const activo = activos.find(a => a.id === activoId);
  if (!activo) return 0;
  return Math.max(0, activo.cantidad_total - getEnUso(activoId, excludeClaseId));
}

function getAsignacionesClase(claseId) {
  return asignaciones.filter(a => a.clase_id === claseId);
}


// ══════════════════════════════════════════════════
//  INIT
// ══════════════════════════════════════════════════
async function init() {
  await loadAll();

  const dateEl = $('currentDate');
  if (dateEl) {
    dateEl.textContent = new Date().toLocaleDateString('es-CL', {
      day: 'numeric', month: 'long', year: 'numeric',
    });
  }

  switchTab('inventario');
}


// ══════════════════════════════════════════════════
//  AUTH
// ══════════════════════════════════════════════════
async function handleLogin() {
  const user  = $('loginUser').value.trim();
  const pass  = $('loginPass').value.trim();
  const errEl = $('loginError');

  try {
    await apiRequest('login', 'POST', { user, pass });
    $('loginScreen').style.display = 'none';
    const dash = $('dashboard');
    dash.style.display       = 'flex';
    dash.style.flexDirection = 'column';
    errEl.classList.add('hidden');
    await init();
  } catch (e) {
    errEl.style.display = 'flex';
    $('loginPass').value = '';
    $('loginPass').focus();
    setTimeout(() => { errEl.style.display = 'none'; }, 4000);
  }
}

async function handleLogout() {
  try { await apiRequest('logout', 'POST'); } catch (_) {}
  $('dashboard').style.display   = 'none';
  $('loginScreen').style.display = 'flex';
  $('loginUser').value = '';
  $('loginPass').value = '';
}


// ══════════════════════════════════════════════════
//  TABS
// ══════════════════════════════════════════════════
function switchTab(tab) {
  currentTab = tab;

  const tabInventario = $('tab-inventario');
  const tabAgenda     = $('tab-agenda');
  const viewInventario = $('viewInventario');
  const viewAgenda    = $('viewAgenda');

  const activeClass   = 'text-brand-600 border-brand-600';
  const inactiveClass = 'text-slate-500 border-transparent hover:text-slate-700 hover:border-slate-300';

  if (tab === 'inventario') {
    tabInventario.className = `flex items-center gap-2 pb-3 text-sm font-semibold border-b-2 transition-all ${activeClass}`;
    tabAgenda.className     = `flex items-center gap-2 pb-3 text-sm font-medium border-b-2 transition-all ${inactiveClass}`;
    viewInventario.classList.remove('hidden');
    viewAgenda.classList.add('hidden');
    renderInventario();
  } else {
    tabAgenda.className     = `flex items-center gap-2 pb-3 text-sm font-semibold border-b-2 transition-all ${activeClass}`;
    tabInventario.className = `flex items-center gap-2 pb-3 text-sm font-medium border-b-2 transition-all ${inactiveClass}`;
    viewAgenda.classList.remove('hidden');
    viewInventario.classList.add('hidden');
    renderAgenda();
  }
}


// ══════════════════════════════════════════════════
//  RENDER: INVENTARIO
// ══════════════════════════════════════════════════
function renderInventario(query = '') {
  const q = query.trim().toLowerCase();
  const filtered = q
    ? activos.filter(a =>
        a.nombre.toLowerCase().includes(q) ||
        a.codigo.toLowerCase().includes(q) ||
        a.categoria.toLowerCase().includes(q)
      )
    : [...activos];

  $('countActivos').textContent = `${filtered.length} activo${filtered.length !== 1 ? 's' : ''}`;

  if (filtered.length === 0) {
    $('tbodyActivos').innerHTML = '';
    $('emptyActivos').classList.remove('hidden');
  } else {
    $('emptyActivos').classList.add('hidden');
    $('tbodyActivos').innerHTML = filtered.map(buildActivoRow).join('');
  }

  updateStatsInventario();
}

function buildActivoRow(a) {
  const enUso      = getEnUso(a.id);
  const disponible = a.cantidad_total - enUso;
  const pct        = a.cantidad_total > 0 ? Math.round((disponible / a.cantidad_total) * 100) : 0;

  let barColor = 'bg-emerald-500';
  if (disponible === 0)     barColor = 'bg-red-400';
  else if (pct < 34)        barColor = 'bg-amber-400';

  const estadoBadge = estadoActivoBadge(a.estado);

  return `
    <tr class="border-b border-slate-100 transition-colors">
      <td class="px-4 py-3.5 font-mono text-xs text-slate-400 whitespace-nowrap">${h(a.codigo)}</td>
      <td class="px-4 py-3.5">
        <p class="font-medium text-slate-800 text-sm">${h(a.nombre)}</p>
        ${a.descripcion ? `<p class="text-xs text-slate-400 mt-0.5 truncate max-w-[220px]">${h(a.descripcion)}</p>` : ''}
      </td>
      <td class="px-4 py-3.5 text-xs text-slate-500 whitespace-nowrap">${h(a.categoria)}</td>
      <td class="px-4 py-3.5 whitespace-nowrap">${estadoBadge}</td>
      <td class="px-4 py-3.5 min-w-[160px]">
        <div class="flex items-center justify-between text-xs mb-1.5">
          <span class="${disponible === 0 ? 'text-red-500 font-semibold' : 'text-slate-700 font-medium'}">${disponible} disponible${disponible !== 1 ? 's' : ''}</span>
          <span class="text-slate-400">${enUso}/${a.cantidad_total} en uso</span>
        </div>
        <div class="avail-bar">
          <div class="avail-bar-fill ${barColor}" style="width:${pct}%"></div>
        </div>
      </td>
      <td class="px-4 py-3.5">
        <div class="flex items-center gap-0.5">
          <button onclick="openEditActivoModal(${a.id})" title="Editar"
            class="w-7 h-7 flex items-center justify-center text-slate-400 hover:text-brand-600 hover:bg-brand-50 rounded-lg transition-all">
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
          </button>
          <button onclick="openDeleteModal('activo', ${a.id})" title="Eliminar"
            class="w-7 h-7 flex items-center justify-center text-slate-400 hover:text-red-500 hover:bg-red-50 rounded-lg transition-all">
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>
          </button>
        </div>
      </td>
    </tr>
  `;
}

function estadoActivoBadge(estado) {
  const map = {
    'Bueno':          'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200',
    'Regular':        'bg-amber-50   text-amber-700   ring-1 ring-inset ring-amber-200',
    'En Reparación':  'bg-red-50     text-red-600     ring-1 ring-inset ring-red-200',
    'De Baja':        'bg-slate-100  text-slate-500   ring-1 ring-inset ring-slate-200',
  };
  const cls = map[estado] ?? map['Regular'];
  return `<span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold ${cls}">${h(estado)}</span>`;
}

function updateStatsInventario() {
  const totalTipos  = activos.length;
  const totalUnits  = activos.reduce((s, a) => s + a.cantidad_total, 0);
  const totalEnUso  = activos.reduce((s, a) => s + getEnUso(a.id), 0);
  const totalDisp   = totalUnits - totalEnUso;

  $('si-tipos').textContent      = totalTipos;
  $('si-total').textContent      = totalUnits;
  $('si-enuso').textContent      = totalEnUso;
  $('si-disponible').textContent = totalDisp;
}

function filterActivos() {
  renderInventario($('searchActivo').value);
}


// ══════════════════════════════════════════════════
//  RENDER: AGENDA
// ══════════════════════════════════════════════════
function renderAgenda() {
  const q       = ($('searchClase')?.value ?? '').trim().toLowerCase();
  const periodo = $('filterPeriodo')?.value ?? 'todas';
  const todayStr = todayISO();
  const weekEnd  = weekEndISO();

  let filtered = [...clases];

  // Filtro por período
  if (periodo === 'hoy') {
    filtered = filtered.filter(c => c.fecha === todayStr);
  } else if (periodo === 'semana') {
    filtered = filtered.filter(c => c.fecha >= todayStr && c.fecha <= weekEnd);
  } else if (periodo === 'proximas') {
    filtered = filtered.filter(c => c.fecha > todayStr && c.estado !== 'Cancelada');
  } else if (periodo === 'finalizadas') {
    filtered = filtered.filter(c => c.estado === 'Finalizada');
  }

  // Filtro por texto
  if (q) {
    filtered = filtered.filter(c =>
      c.curso.toLowerCase().includes(q) ||
      c.relator.toLowerCase().includes(q) ||
      c.sala.toLowerCase().includes(q)
    );
  }

  // Ordenar por fecha + hora
  filtered.sort((a, b) => {
    const da = a.fecha + a.hora_inicio;
    const db = b.fecha + b.hora_inicio;
    return da.localeCompare(db);
  });

  $('countClases').textContent = `${filtered.length} clase${filtered.length !== 1 ? 's' : ''}`;

  if (filtered.length === 0) {
    $('tbodyClases').innerHTML = '';
    $('emptyClases').classList.remove('hidden');
  } else {
    $('emptyClases').classList.add('hidden');
    $('tbodyClases').innerHTML = filtered.map(buildClaseRow).join('');
  }

  updateStatsAgenda();
}

function buildClaseRow(c) {
  const esHoy   = c.fecha === todayISO();
  const asigs   = getAsignacionesClase(c.id);
  const badgesHTML = asigs.length === 0
    ? `<span class="text-xs text-slate-300 italic">Sin asignar</span>`
    : asigs.map(a => {
        const act = activos.find(x => x.id === a.activo_id);
        if (!act) return '';
        const nombre = act.nombre.length > 18 ? act.nombre.slice(0, 18) + '…' : act.nombre;
        return `<span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-brand-50 text-brand-700 ring-1 ring-inset ring-brand-200 whitespace-nowrap">${h(nombre)} ×${a.cantidad}</span>`;
      }).join(' ');

  const estadoCls = estadoClaseBadge(c.estado);
  const rowCls = esHoy ? 'is-today' : '';
  const horario = `${c.hora_inicio}${c.hora_fin ? ' – ' + c.hora_fin : ''}`;

  return `
    <tr class="border-b border-slate-100 transition-colors ${rowCls}">
      <td class="px-4 py-3.5 whitespace-nowrap">
        <div class="text-xs font-semibold ${esHoy ? 'text-amber-700' : 'text-slate-700'}">
          ${esHoy ? '<span class="inline-block bg-amber-100 text-amber-700 text-[9px] font-bold px-1.5 py-0.5 rounded mr-1 uppercase tracking-wide">Hoy</span>' : ''}
          ${formatFecha(c.fecha)}
        </div>
      </td>
      <td class="px-4 py-3.5 text-xs text-slate-500 font-mono whitespace-nowrap">${h(horario)}</td>
      <td class="px-4 py-3.5">
        <p class="font-medium text-slate-800 text-sm whitespace-nowrap">${h(c.curso)}</p>
      </td>
      <td class="px-4 py-3.5 text-xs text-slate-500 whitespace-nowrap">${h(c.relator)}</td>
      <td class="px-4 py-3.5 text-xs text-slate-500 whitespace-nowrap">${h(c.sala)}</td>
      <td class="px-4 py-3.5">
        <div class="flex flex-wrap gap-1 items-center min-w-[160px]">
          ${badgesHTML}
        </div>
      </td>
      <td class="px-4 py-3.5 whitespace-nowrap">${estadoCls}</td>
      <td class="px-4 py-3.5">
        <div class="flex items-center gap-0.5">
          <button onclick="openAsignacionesModal(${c.id})" title="Gestionar activos"
            class="flex items-center gap-1 px-2 py-1 text-[10px] font-medium text-slate-600 hover:text-emerald-700 border border-slate-200 hover:border-emerald-200 hover:bg-emerald-50 rounded-md transition-all whitespace-nowrap">
            <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
            Activos
          </button>
          <button onclick="openEditClaseModal(${c.id})" title="Editar"
            class="w-7 h-7 flex items-center justify-center text-slate-400 hover:text-brand-600 hover:bg-brand-50 rounded-lg transition-all">
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
          </button>
          <button onclick="openDeleteModal('clase', ${c.id})" title="Eliminar"
            class="w-7 h-7 flex items-center justify-center text-slate-400 hover:text-red-500 hover:bg-red-50 rounded-lg transition-all">
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>
          </button>
        </div>
      </td>
    </tr>
  `;
}

function estadoClaseBadge(estado) {
  const map = {
    'Programada': 'bg-brand-50   text-brand-700  ring-1 ring-inset ring-brand-200',
    'En Curso':   'bg-amber-50   text-amber-700  ring-1 ring-inset ring-amber-200',
    'Finalizada': 'bg-slate-100  text-slate-500  ring-1 ring-inset ring-slate-200',
    'Cancelada':  'bg-red-50     text-red-600    ring-1 ring-inset ring-red-200',
  };
  const cls = map[estado] ?? map['Programada'];
  return `<span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold ${cls}">${h(estado)}</span>`;
}

function updateStatsAgenda() {
  const todayStr = todayISO();
  const weekEnd  = weekEndISO();

  $('sa-hoy').textContent        = clases.filter(c => c.fecha === todayStr).length;
  $('sa-semana').textContent     = clases.filter(c => c.fecha >= todayStr && c.fecha <= weekEnd).length;
  $('sa-programadas').textContent= clases.filter(c => c.estado === 'Programada' || c.estado === 'En Curso').length;
  $('sa-finalizadas').textContent= clases.filter(c => c.estado === 'Finalizada').length;
}

function filterClases() {
  renderAgenda();
}


// ══════════════════════════════════════════════════
//  CRUD: ACTIVOS
// ══════════════════════════════════════════════════
function openAddActivoModal() {
  editingActivoId = null;
  $('activoModalTitle').textContent = 'Agregar Activo';
  clearActivoForm();
  showModal('activoModal');
}

function openEditActivoModal(id) {
  const a = activos.find(x => x.id === id);
  if (!a) return;
  editingActivoId = id;
  $('activoModalTitle').textContent = 'Editar Activo';
  clearActivoForm();

  $('aCodigo').value      = a.codigo;
  $('aNombre').value      = a.nombre;
  $('aCategoria').value   = a.categoria;
  $('aEstado').value      = a.estado;
  $('aCantidad').value    = a.cantidad_total;
  $('aDescripcion').value = a.descripcion;

  showModal('activoModal');
}

function closeActivoModal() {
  hideModal('activoModal');
  clearActivoForm();
  editingActivoId = null;
}

function clearActivoForm() {
  ['aCodigo','aNombre','aDescripcion'].forEach(id => $(id).value = '');
  $('aCategoria').value = 'Tecnología';
  $('aEstado').value    = 'Bueno';
  $('aCantidad').value  = '';
  setActivoError('');
}

async function saveActivo() {
  const nombre    = $('aNombre').value.trim();
  const cantStr   = $('aCantidad').value.trim();
  const cantidad  = parseInt(cantStr, 10);

  if (!nombre) { setActivoError('El nombre del activo es obligatorio.'); return; }
  if (!cantStr || isNaN(cantidad) || cantidad < 0) { setActivoError('La cantidad total debe ser un número válido (≥ 0).'); return; }

  // Si se reduce la cantidad, verificar que no quede por debajo de lo en uso
  if (editingActivoId !== null) {
    const enUso = getEnUso(editingActivoId);
    if (cantidad < enUso) {
      setActivoError(`No puedes reducir a ${cantidad}: actualmente hay ${enUso} unidades asignadas a clases activas.`);
      return;
    }
  }

  const data = {
    codigo:         $('aCodigo').value.trim(),
    nombre,
    categoria:      $('aCategoria').value,
    estado:         $('aEstado').value,
    cantidad_total: cantidad,
    descripcion:    $('aDescripcion').value.trim(),
  };

  if (editingActivoId !== null) {
    const idx = activos.findIndex(a => a.id === editingActivoId);
    activos[idx] = { ...activos[idx], ...data };
    showToast('success', 'Activo actualizado correctamente');
  } else {
    activos.push({ id: nextId(activos), ...data });
    showToast('success', 'Activo agregado al inventario');
  }
  try {
    await persist();
  } catch (e) {
    setActivoError(e.message || 'No se pudo guardar en servidor.');
    return;
  }
  renderInventario($('searchActivo').value);
  closeActivoModal();
}

function setActivoError(msg) {
  const el = $('activoError');
  $('activoErrorMsg').textContent = msg;
  msg ? el.classList.remove('hidden') : el.classList.add('hidden');
}


// ══════════════════════════════════════════════════
//  CRUD: CLASES
// ══════════════════════════════════════════════════
function openAddClaseModal() {
  editingClaseId = null;
  $('claseModalTitle').textContent = 'Agregar Clase';
  clearClaseForm();
  // Precargar la fecha con hoy
  $('cFecha').value = todayISO();
  showModal('claseModal');
}

function openEditClaseModal(id) {
  const c = clases.find(x => x.id === id);
  if (!c) return;
  editingClaseId = id;
  $('claseModalTitle').textContent = 'Editar Clase';
  clearClaseForm();

  $('cCurso').value      = c.curso;
  $('cRelator').value    = c.relator;
  $('cSala').value       = c.sala;
  $('cFecha').value      = c.fecha;
  $('cEstado').value     = c.estado;
  $('cHoraInicio').value = c.hora_inicio;
  $('cHoraFin').value    = c.hora_fin;

  showModal('claseModal');
}

function closeClaseModal() {
  hideModal('claseModal');
  clearClaseForm();
  editingClaseId = null;
}

function clearClaseForm() {
  ['cCurso','cRelator','cSala','cFecha','cHoraInicio','cHoraFin'].forEach(id => $(id).value = '');
  $('cEstado').value = 'Programada';
  setClaseError('');
}

async function saveClase() {
  const curso   = $('cCurso').value.trim();
  const relator = $('cRelator').value.trim();
  const fecha   = $('cFecha').value;

  if (!curso)   { setClaseError('El nombre del curso es obligatorio.'); return; }
  if (!relator) { setClaseError('El relator es obligatorio.'); return; }
  if (!fecha)   { setClaseError('La fecha es obligatoria.'); return; }

  const data = {
    curso,
    relator,
    sala:        $('cSala').value.trim(),
    fecha,
    estado:      $('cEstado').value,
    hora_inicio: $('cHoraInicio').value,
    hora_fin:    $('cHoraFin').value,
  };

  if (editingClaseId !== null) {
    const idx = clases.findIndex(c => c.id === editingClaseId);
    clases[idx] = { ...clases[idx], ...data };
    showToast('success', 'Clase actualizada correctamente');
  } else {
    clases.push({ id: nextId(clases), ...data });
    showToast('success', 'Clase agregada a la agenda');
  }
  try {
    await persist();
  } catch (e) {
    setClaseError(e.message || 'No se pudo guardar en servidor.');
    return;
  }
  renderAgenda();
  closeClaseModal();
}

function setClaseError(msg) {
  const el = $('claseError');
  $('claseErrorMsg').textContent = msg;
  msg ? el.classList.remove('hidden') : el.classList.add('hidden');
}


// ══════════════════════════════════════════════════
//  CRUD: ASIGNACIONES
// ══════════════════════════════════════════════════
function openAsignacionesModal(claseId) {
  managingClaseId = claseId;
  const c = clases.find(x => x.id === claseId);
  if (!c) return;

  // Header del modal
  $('asigModalCurso').textContent = c.curso;
  $('asigModalMeta').textContent  =
    `${formatFecha(c.fecha)}  ·  ${c.hora_inicio}${c.hora_fin ? ' – ' + c.hora_fin : ''}  ·  ${c.sala}`;

  // Poblar select de activos
  populateActivoSelect();

  // Renderizar lista de asignaciones actuales
  renderAsignacionesList();

  $('asigError').classList.add('hidden');
  showModal('asignacionesModal');
}

function closeAsignacionesModal() {
  hideModal('asignacionesModal');
  managingClaseId = null;
}

function populateActivoSelect() {
  const sel = $('asigActivoId');
  sel.innerHTML = '<option value="">Seleccionar activo…</option>';
  activos.forEach(a => {
    const disp = getDisponible(a.id, managingClaseId);
    const opt  = document.createElement('option');
    opt.value  = a.id;
    opt.textContent = `${a.nombre} (${disp} disp.)`;
    if (disp === 0) opt.style.color = '#ef4444';
    sel.appendChild(opt);
  });
  $('asigHint').textContent = '';
  $('asigCantidad').value   = '1';
}

function onActivoSelectChange() {
  const activoId   = parseInt($('asigActivoId').value, 10);
  const hintEl     = $('asigHint');
  const cantInput  = $('asigCantidad');

  if (!activoId) { hintEl.textContent = ''; return; }

  const activo    = activos.find(a => a.id === activoId);
  const disponible = getDisponible(activoId, managingClaseId);

  cantInput.max = disponible;
  if (parseInt(cantInput.value, 10) > disponible) cantInput.value = disponible;
  if (disponible === 0) cantInput.value = 0;

  hintEl.textContent = disponible > 0
    ? `${activo.nombre}: ${disponible} disponible${disponible !== 1 ? 's' : ''} de ${activo.cantidad_total}`
    : `⚠ Sin stock disponible (${activo.cantidad_total} asignadas)`;
  hintEl.className = disponible === 0 ? 'text-xs text-red-500 min-h-[1rem]' : 'text-xs text-slate-400 min-h-[1rem]';
}

function renderAsignacionesList() {
  const list  = $('asigList');
  const empty = $('asigEmpty');
  const asigs = getAsignacionesClase(managingClaseId);

  if (asigs.length === 0) {
    list.innerHTML = '';
    empty.classList.remove('hidden');
    return;
  }
  empty.classList.add('hidden');

  list.innerHTML = asigs.map(a => {
    const activo = activos.find(x => x.id === a.activo_id);
    if (!activo) return '';
    const enUso = getEnUso(activo.id);
    const pct   = activo.cantidad_total > 0
      ? Math.round(((activo.cantidad_total - enUso) / activo.cantidad_total) * 100)
      : 0;
    return `
      <div class="flex items-center justify-between py-2.5 border-b border-slate-50 last:border-0 gap-3">
        <div class="flex-1 min-w-0">
          <p class="text-xs font-medium text-slate-800">${h(activo.nombre)}</p>
          <p class="text-[10px] text-slate-400 mt-0.5">${h(activo.categoria)} · ${pct}% libre en inventario</p>
        </div>
        <div class="flex items-center gap-2 shrink-0">
          <span class="text-xs font-semibold text-brand-700 bg-brand-50 border border-brand-200 px-2.5 py-0.5 rounded-full">×${a.cantidad}</span>
          <button onclick="removeAsignacion(${a.id})" title="Quitar" class="w-6 h-6 flex items-center justify-center text-slate-400 hover:text-red-500 hover:bg-red-50 rounded-lg transition-all">
            <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        </div>
      </div>
    `;
  }).join('');
}

async function addAsignacion() {
  const activoId  = parseInt($('asigActivoId').value, 10);
  const cantidad  = parseInt($('asigCantidad').value, 10);
  const errEl     = $('asigError');

  if (!activoId) { setAsigError('Selecciona un activo.'); return; }
  if (isNaN(cantidad) || cantidad < 1) { setAsigError('La cantidad debe ser al menos 1.'); return; }

  const disponible = getDisponible(activoId, managingClaseId);
  if (cantidad > disponible) {
    setAsigError(`Solo hay ${disponible} unidad${disponible !== 1 ? 'es' : ''} disponible${disponible !== 1 ? 's' : ''} para este activo.`);
    return;
  }

  // Si ya existe esta asignación en esta clase, sumar
  const existing = asignaciones.find(a => a.clase_id === managingClaseId && a.activo_id === activoId);
  if (existing) {
    const nuevaCantidad = existing.cantidad + cantidad;
    const dispTotal = getDisponible(activoId, managingClaseId);
    if (cantidad > dispTotal) {
      setAsigError(`No se puede agregar: excede las ${dispTotal} unidades disponibles.`);
      return;
    }
    existing.cantidad = nuevaCantidad;
  } else {
    asignaciones.push({ id: nextId(asignaciones), clase_id: managingClaseId, activo_id: activoId, cantidad });
  }

  errEl.classList.add('hidden');
  try {
    await persist();
  } catch (e) {
    setAsigError(e.message || 'No se pudo guardar la asignación.');
    return;
  }
  renderAsignacionesList();
  populateActivoSelect();

  // Refrescar la tabla de fondo
  if (currentTab === 'agenda')     renderAgenda();
  if (currentTab === 'inventario') renderInventario($('searchActivo').value);

  showToast('success', 'Activo asignado a la clase');
}

async function removeAsignacion(id) {
  asignaciones = asignaciones.filter(a => a.id !== id);
  try {
    await persist();
  } catch (e) {
    setAsigError(e.message || 'No se pudo actualizar el inventario.');
    return;
  }
  renderAsignacionesList();
  populateActivoSelect();

  if (currentTab === 'agenda')     renderAgenda();
  if (currentTab === 'inventario') renderInventario($('searchActivo').value);

  showToast('warning', 'Activo removido de la clase');
}

function setAsigError(msg) {
  const el = $('asigError');
  $('asigErrorMsg').textContent = msg;
  msg ? el.classList.remove('hidden') : el.classList.add('hidden');
}


// ══════════════════════════════════════════════════
//  DELETE
// ══════════════════════════════════════════════════
function openDeleteModal(type, id) {
  deleteTarget = { type, id };

  if (type === 'activo') {
    const a = activos.find(x => x.id === id);
    $('deleteModalTitle').textContent = '¿Eliminar activo?';
    $('deleteModalMsg').textContent   =
      `"${a?.nombre}" será eliminado del inventario junto con todas sus asignaciones a clases.`;
  } else {
    const c = clases.find(x => x.id === id);
    $('deleteModalTitle').textContent = '¿Eliminar clase?';
    $('deleteModalMsg').textContent   =
      `La clase "${c?.curso}" y todos los activos que tiene asignados serán liberados. Esta acción no se puede deshacer.`;
  }

  showModal('deleteModal');
}

function closeDeleteModal() {
  deleteTarget = null;
  hideModal('deleteModal');
}

async function confirmDelete() {
  if (!deleteTarget) return;
  const { type, id } = deleteTarget;

  if (type === 'activo') {
    activos      = activos.filter(a => a.id !== id);
    asignaciones = asignaciones.filter(a => a.activo_id !== id);
    showToast('warning', 'Activo eliminado del inventario');
  } else {
    clases       = clases.filter(c => c.id !== id);
    asignaciones = asignaciones.filter(a => a.clase_id !== id);
    showToast('warning', 'Clase eliminada de la agenda');
  }

  try {
    await persist();
  } catch (e) {
    showToast('warning', e.message || 'No se pudo eliminar en servidor');
    return;
  }
  closeDeleteModal();

  if (currentTab === 'inventario') renderInventario($('searchActivo').value);
  else                             renderAgenda();
}


// ══════════════════════════════════════════════════
//  MODAL HELPERS
// ══════════════════════════════════════════════════
const ALL_MODALS = ['activoModal','claseModal','asignacionesModal','deleteModal'];

function showModal(id) {
  const el = $(id);
  el.classList.remove('hidden');
  const inner = el.querySelector('.modal-enter');
  if (inner) { inner.style.animation = 'none'; inner.offsetHeight; inner.style.animation = ''; }
  document.body.style.overflow = 'hidden';
}

function hideModal(id) {
  $(id).classList.add('hidden');
  const open = ALL_MODALS.filter(m => !$(m).classList.contains('hidden'));
  if (open.length === 0) document.body.style.overflow = '';
}


// ══════════════════════════════════════════════════
//  TOAST
// ══════════════════════════════════════════════════
const TOAST_ICONS = {
  success: `<svg class="w-3.5 h-3.5 text-emerald-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>`,
  warning: `<svg class="w-3.5 h-3.5 text-amber-400"  viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>`,
  info:    `<svg class="w-3.5 h-3.5 text-brand-400"  viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>`,
};

function showToast(type = 'success', msg = '') {
  $('toastIcon').innerHTML  = TOAST_ICONS[type] ?? TOAST_ICONS.success;
  $('toastMsg').textContent = msg;
  $('toast').classList.remove('hidden');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => $('toast').classList.add('hidden'), 3500);
}


// ══════════════════════════════════════════════════
//  UTILIDADES
// ══════════════════════════════════════════════════
function $(id) { return document.getElementById(id); }

function h(str) {
  if (str == null) return '';
  return String(str)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function todayISO() {
  return new Date().toISOString().split('T')[0];
}

function weekEndISO() {
  const d = new Date();
  const diff = 6 - d.getDay(); // días hasta el domingo
  d.setDate(d.getDate() + diff);
  return d.toISOString().split('T')[0];
}

function formatFecha(dateStr) {
  // dateStr: YYYY-MM-DD
  // Usamos T00:00:00 para evitar desfases de zona horaria
  const date = new Date(dateStr + 'T00:00:00');
  return date.toLocaleDateString('es-CL', { weekday:'short', day:'numeric', month:'short' });
}


// ══════════════════════════════════════════════════
//  EVENT LISTENERS
// ══════════════════════════════════════════════════

['loginUser','loginPass'].forEach(id => {
  $(id)?.addEventListener('keydown', e => { if (e.key === 'Enter') handleLogin(); });
});

window.addEventListener('DOMContentLoaded', async () => {
  try {
    const session = await apiRequest('session', 'GET');
    if (session?.authenticated) {
      $('loginScreen').style.display = 'none';
      const dash = $('dashboard');
      dash.style.display = 'flex';
      dash.style.flexDirection = 'column';
      await init();
    }
  } catch (_) {
    // Si no hay sesión o falla conexión, se queda en login.
  }
});

ALL_MODALS.forEach(id => {
  $(id)?.addEventListener('click', e => {
    if (e.target !== $(id)) return;
    if (id === 'activoModal')      closeActivoModal();
    if (id === 'claseModal')       closeClaseModal();
    if (id === 'asignacionesModal') closeAsignacionesModal();
    if (id === 'deleteModal')      closeDeleteModal();
  });
});

document.addEventListener('keydown', e => {
  if (e.key !== 'Escape') return;
  if (!$('activoModal').classList.contains('hidden'))       closeActivoModal();
  if (!$('claseModal').classList.contains('hidden'))        closeClaseModal();
  if (!$('asignacionesModal').classList.contains('hidden')) closeAsignacionesModal();
  if (!$('deleteModal').classList.contains('hidden'))       closeDeleteModal();
});
