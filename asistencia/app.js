(() => {
  const API = 'api.php';
  const $ = (id) => document.getElementById(id);
  const state = {
    user: null,
    cursos: [],
    curso: null,
    alumnos: [],
    sesiones: [],
    sesion: null,
    lista: [],
    resumen: null,
  };

  async function api(action, opts = {}) {
    const method = opts.method || (opts.body ? 'POST' : 'GET');
    const url = `${API}?action=${encodeURIComponent(action)}${opts.query || ''}`;
    const res = await fetch(url, {
      method,
      credentials: 'same-origin',
      headers: opts.body ? { 'Content-Type': 'application/json' } : undefined,
      body: opts.body ? JSON.stringify(opts.body) : undefined,
    });
    const json = await res.json().catch(() => ({ ok: false, error: 'Respuesta inválida' }));
    if (!json.ok) {
      const err = new Error(json.error || 'Error de API');
      err.status = res.status;
      throw err;
    }
    return json.data;
  }

  function toast(msg) {
    const el = $('toast');
    el.textContent = msg;
    el.hidden = false;
    clearTimeout(toast._t);
    toast._t = setTimeout(() => { el.hidden = true; }, 2400);
  }

  function show(view) {
    $('view-login').hidden = view !== 'login';
    $('view-app').hidden = view === 'login';
    if (view === 'login') return;
    $('panel-cursos').hidden = view !== 'cursos';
    $('panel-curso').hidden = view !== 'curso';
    $('panel-lista').hidden = view !== 'lista';
  }

  function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
  }

  /* ── Auth ── */
  async function boot() {
    try {
      const me = await api('me');
      if (me.auth) {
        state.user = me.usuario;
        $('who').textContent = me.usuario;
        show('cursos');
        await loadCursos();
      } else {
        show('login');
      }
    } catch {
      show('login');
    }
  }

  $('form-login').addEventListener('submit', async (e) => {
    e.preventDefault();
    const err = $('login-error');
    err.hidden = true;
    try {
      const data = await api('login', {
        method: 'POST',
        body: { usuario: $('login-user').value.trim(), clave: $('login-pass').value },
      });
      state.user = data.usuario;
      $('who').textContent = data.usuario;
      show('cursos');
      await loadCursos();
    } catch (ex) {
      err.textContent = ex.message;
      err.hidden = false;
    }
  });

  $('btn-logout').addEventListener('click', async () => {
    try { await api('logout', { method: 'POST', body: {} }); } catch {}
    state.user = null;
    show('login');
  });

  /* ── Cursos ── */
  async function loadCursos() {
    state.cursos = await api('cursos');
    const grid = $('cursos-grid');
    if (!state.cursos.length) {
      grid.innerHTML = '<p class="muted">No hay cursos. Crea el primero.</p>';
      return;
    }
    grid.innerHTML = state.cursos.map((c) => `
      <article class="card" data-curso-id="${c.id}">
        <h3>${esc(c.nombre)}</h3>
        <p>${esc(c.codigo)} · ${esc(c.modalidad)}</p>
        <div class="meta">
          <span class="pill">${Number(c.alumnos)} alumnos</span>
          <span class="pill">${Number(c.sesiones)} sesiones</span>
        </div>
      </article>
    `).join('');
  }

  $('cursos-grid').addEventListener('click', (e) => {
    const card = e.target.closest('[data-curso-id]');
    if (!card) return;
    openCurso(Number(card.dataset.cursoId));
  });

  $('btn-nuevo-curso').addEventListener('click', () => {
    $('form-curso').hidden = false;
    $('curso-nombre').focus();
  });
  $('btn-cancel-curso').addEventListener('click', () => {
    $('form-curso').hidden = true;
    $('form-curso').reset();
  });
  $('form-curso').addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      await api('curso_crear', {
        method: 'POST',
        body: {
          nombre: $('curso-nombre').value.trim(),
          modalidad: $('curso-modalidad').value,
        },
      });
      $('form-curso').hidden = true;
      $('form-curso').reset();
      toast('Curso creado');
      await loadCursos();
    } catch (ex) {
      toast(ex.message);
    }
  });

  async function openCurso(id) {
    state.curso = state.cursos.find((c) => Number(c.id) === id) || null;
    if (!state.curso) {
      await loadCursos();
      state.curso = state.cursos.find((c) => Number(c.id) === id) || null;
    }
    if (!state.curso) return;
    $('curso-titulo').textContent = state.curso.nombre;
    $('curso-meta').textContent = `${state.curso.codigo} · ${state.curso.modalidad}`;
    setTab('nomina');
    show('curso');
    await Promise.all([loadAlumnos(), loadSesiones()]);
  }

  document.querySelectorAll('[data-back]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const to = btn.dataset.back;
      if (to === 'cursos') {
        show('cursos');
        await loadCursos();
      } else if (to === 'curso' && state.curso) {
        show('curso');
        await loadSesiones();
      }
    });
  });

  /* ── Tabs ── */
  document.querySelectorAll('.tab').forEach((tab) => {
    tab.addEventListener('click', () => setTab(tab.dataset.tab));
  });
  function setTab(name) {
    document.querySelectorAll('.tab').forEach((t) => t.classList.toggle('is-active', t.dataset.tab === name));
    $('tab-nomina').hidden = name !== 'nomina';
    $('tab-sesiones').hidden = name !== 'sesiones';
  }

  /* ── Nómina ── */
  async function loadAlumnos() {
    state.alumnos = await api('alumnos', { query: `&curso_id=${state.curso.id}` });
    const tb = $('tbody-alumnos');
    if (!state.alumnos.length) {
      tb.innerHTML = '<tr><td colspan="5" class="muted">Sin alumnos. Agrega la nómina del curso.</td></tr>';
      return;
    }
    tb.innerHTML = state.alumnos.map((a) => `
      <tr>
        <td>${esc(a.rut)}</td>
        <td>${esc(a.nombre)}</td>
        <td>${esc(a.email || '—')}</td>
        <td><span class="pill">${Number(a.activo) ? 'Activo' : 'Inactivo'}</span></td>
        <td>
          <button type="button" class="btn btn-mini btn-ghost" data-toggle-alumno="${a.id}" data-activo="${Number(a.activo) ? 0 : 1}">
            ${Number(a.activo) ? 'Desactivar' : 'Activar'}
          </button>
        </td>
      </tr>
    `).join('');
  }

  $('form-alumno').addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      await api('alumno_crear', {
        method: 'POST',
        body: {
          curso_id: Number(state.curso.id),
          rut: $('alumno-rut').value.trim(),
          nombre: $('alumno-nombre').value.trim(),
          email: $('alumno-email').value.trim(),
        },
      });
      $('form-alumno').reset();
      toast('Alumno agregado');
      await loadAlumnos();
      await loadCursos();
    } catch (ex) {
      toast(ex.message);
    }
  });

  $('tbody-alumnos').addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-toggle-alumno]');
    if (!btn) return;
    try {
      await api('alumno_toggle', {
        method: 'POST',
        body: { id: Number(btn.dataset.toggleAlumno), activo: Number(btn.dataset.activo) === 1 },
      });
      await loadAlumnos();
    } catch (ex) {
      toast(ex.message);
    }
  });

  /* ── Sesiones ── */
  async function loadSesiones() {
    state.sesiones = await api('sesiones', { query: `&curso_id=${state.curso.id}` });
    const tb = $('tbody-sesiones');
    if (!state.sesiones.length) {
      tb.innerHTML = '<tr><td colspan="6" class="muted">Aún no hay sesiones. Crea la primera clase.</td></tr>';
      return;
    }
    tb.innerHTML = state.sesiones.map((s) => {
      const online = s.enlace_online
        ? `<a href="${esc(s.enlace_online)}" target="_blank" rel="noopener">Abrir</a>`
        : '—';
      const estado = Number(s.cerrada) ? 'Cerrada' : 'Abierta';
      return `
        <tr>
          <td>${esc(s.fecha)}</td>
          <td>${esc(String(s.hora_inicio).slice(0, 5))}</td>
          <td>${esc(s.titulo)} <span class="pill">${estado}</span></td>
          <td>${online}</td>
          <td>${Number(s.marcados)} · P:${Number(s.presentes)} A:${Number(s.ausentes)}</td>
          <td><button type="button" class="btn btn-mini btn-primary" data-open-sesion="${s.id}">Pasar lista</button></td>
        </tr>
      `;
    }).join('');
  }

  $('btn-nueva-sesion').addEventListener('click', () => {
    $('form-sesion').hidden = false;
    if (!$('sesion-fecha').value) {
      $('sesion-fecha').value = new Date().toISOString().slice(0, 10);
    }
  });
  $('btn-cancel-sesion').addEventListener('click', () => {
    $('form-sesion').hidden = true;
  });

  $('form-sesion').addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const data = await api('sesion_crear', {
        method: 'POST',
        body: {
          curso_id: Number(state.curso.id),
          fecha: $('sesion-fecha').value,
          hora_inicio: $('sesion-hora').value,
          titulo: $('sesion-titulo').value.trim() || 'Clase',
          enlace_online: $('sesion-enlace').value.trim(),
          notas: $('sesion-notas').value.trim(),
        },
      });
      $('form-sesion').hidden = true;
      $('form-sesion').reset();
      $('sesion-hora').value = '09:00';
      toast('Sesión creada');
      await openLista(data.id);
    } catch (ex) {
      toast(ex.message);
    }
  });

  $('tbody-sesiones').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-open-sesion]');
    if (!btn) return;
    openLista(Number(btn.dataset.openSesion));
  });

  /* ── Lista ── */
  async function openLista(sesionId) {
    const data = await api('sesion', { query: `&id=${sesionId}` });
    state.sesion = data.sesion;
    state.lista = data.lista;
    state.resumen = data.resumen;
    renderLista();
    show('lista');
  }

  function renderLista() {
    const s = state.sesion;
    $('lista-meta').textContent = `${s.curso_nombre} · ${s.fecha} · ${String(s.hora_inicio).slice(0, 5)}`;
    $('lista-titulo').textContent = s.titulo;
    $('lista-enlace').innerHTML = s.enlace_online
      ? `Enlace clase: <a href="${esc(s.enlace_online)}" target="_blank" rel="noopener">${esc(s.enlace_online)}</a>`
      : 'Sin enlace online cargado.';

    const r = state.resumen || {};
    $('resumen').innerHTML = ['total', 'presente', 'ausente', 'tardanza', 'justificado', 'sin_marcar']
      .map((k) => `<div><strong>${Number(r[k] || 0)}</strong><span>${k.replace('_', ' ')}</span></div>`)
      .join('');

    const closed = Number(s.cerrada) === 1;
    $('btn-todos-presentes').disabled = closed;
    $('btn-cerrar-sesion').disabled = closed;
    $('btn-cerrar-sesion').textContent = closed ? 'Sesión cerrada' : 'Cerrar sesión';

    const tb = $('tbody-lista');
    if (!state.lista.length) {
      tb.innerHTML = '<tr><td colspan="4" class="muted">No hay alumnos activos en la nómina de este curso.</td></tr>';
      return;
    }

    tb.innerHTML = state.lista.map((a) => `
      <tr data-alumno-id="${a.alumno_id}">
        <td>${esc(a.nombre)}</td>
        <td>${esc(a.rut)}</td>
        <td>
          <div class="estado-btns" data-alumno-id="${a.alumno_id}">
            ${['presente', 'ausente', 'tardanza', 'justificado'].map((est) => `
              <button type="button" class="btn ${a.estado === est ? 'is-active' : ''}" data-estado="${est}" ${closed ? 'disabled' : ''}>
                ${est}
              </button>
            `).join('')}
          </div>
        </td>
        <td>
          <input type="text" class="obs" data-obs-alumno="${a.alumno_id}" value="${esc(a.observacion || '')}" placeholder="—" ${closed ? 'disabled' : ''} style="min-width:120px">
        </td>
      </tr>
    `).join('');
  }

  $('tbody-lista').addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-estado]');
    if (!btn || btn.disabled) return;
    const wrap = btn.closest('[data-alumno-id]');
    const alumnoId = Number(wrap.dataset.alumnoId);
    const estado = btn.dataset.estado;
    const obsInput = document.querySelector(`[data-obs-alumno="${alumnoId}"]`);
    try {
      await api('marcar', {
        method: 'POST',
        body: {
          sesion_id: Number(state.sesion.id),
          alumno_id: alumnoId,
          estado,
          observacion: obsInput ? obsInput.value.trim() : '',
        },
      });
      const row = state.lista.find((x) => Number(x.alumno_id) === alumnoId);
      if (row) row.estado = estado;
      // refresh resumen local
      const resumen = { total: state.lista.length, presente: 0, ausente: 0, justificado: 0, tardanza: 0, sin_marcar: 0 };
      state.lista.forEach((x) => { resumen[x.estado] = (resumen[x.estado] || 0) + 1; });
      state.resumen = resumen;
      renderLista();
    } catch (ex) {
      toast(ex.message);
    }
  });

  $('tbody-lista').addEventListener('change', async (e) => {
    const input = e.target.closest('[data-obs-alumno]');
    if (!input || input.disabled) return;
    const alumnoId = Number(input.dataset.obsAlumno);
    const row = state.lista.find((x) => Number(x.alumno_id) === alumnoId);
    if (!row || row.estado === 'sin_marcar') return;
    try {
      await api('marcar', {
        method: 'POST',
        body: {
          sesion_id: Number(state.sesion.id),
          alumno_id: alumnoId,
          estado: row.estado,
          observacion: input.value.trim(),
        },
      });
      row.observacion = input.value.trim();
    } catch (ex) {
      toast(ex.message);
    }
  });

  $('btn-todos-presentes').addEventListener('click', async () => {
    if (Number(state.sesion.cerrada)) return;
    const items = state.lista.map((a) => ({
      alumno_id: Number(a.alumno_id),
      estado: 'presente',
      observacion: a.observacion || '',
    }));
    try {
      await api('marcar_lote', {
        method: 'POST',
        body: { sesion_id: Number(state.sesion.id), items },
      });
      await openLista(Number(state.sesion.id));
      toast('Todos marcados presentes');
    } catch (ex) {
      toast(ex.message);
    }
  });

  $('btn-cerrar-sesion').addEventListener('click', async () => {
    if (Number(state.sesion.cerrada)) return;
    if (!confirm('¿Cerrar esta sesión? No se podrá editar la lista después.')) return;
    try {
      await api('sesion_actualizar', {
        method: 'POST',
        body: { id: Number(state.sesion.id), cerrada: true },
      });
      await openLista(Number(state.sesion.id));
      toast('Sesión cerrada');
    } catch (ex) {
      toast(ex.message);
    }
  });

  $('btn-export-csv').addEventListener('click', () => {
    const s = state.sesion;
    const lines = [['Curso', 'Fecha', 'Hora', 'Sesión', 'RUT', 'Nombre', 'Estado', 'Observación'].join(';')];
    state.lista.forEach((a) => {
      lines.push([
        s.curso_nombre, s.fecha, String(s.hora_inicio).slice(0, 5), s.titulo,
        a.rut, a.nombre, a.estado, a.observacion || '',
      ].map((v) => `"${String(v).replace(/"/g, '""')}"`).join(';'));
    });
    const blob = new Blob(['\uFEFF' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `asistencia_${s.curso_codigo || 'curso'}_${s.fecha}.csv`;
    a.click();
    URL.revokeObjectURL(a.href);
  });

  boot();
})();
