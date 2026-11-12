(() => {
  'use strict';

  const api = async (action, { method = 'GET', body, params } = {}) => {
    const qs = new URLSearchParams({ action, ...(params || {}) });
    const opts = {
      method,
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    };
    if (body !== undefined) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    const res = await fetch(`api.php?${qs}`, opts);
    const json = await res.json().catch(() => ({ ok: false, error: 'Respuesta inválida' }));
    if (!json.ok) {
      const err = new Error(json.error || 'Error');
      err.status = res.status;
      err.payload = json.data;
      throw err;
    }
    return json.data;
  };

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];

  const state = {
    user: null,
    cursos: [],
    cursoActual: null,
  };

  const viewLogin = $('#view-login');
  const viewApp = $('#view-app');

  function showLogin() {
    viewLogin.classList.remove('hidden');
    viewApp.classList.add('hidden');
  }

  function showApp() {
    viewLogin.classList.add('hidden');
    viewApp.classList.remove('hidden');
    const isStaff = state.user.rol === 'admin' || state.user.rol === 'profesor';
    $('#tab-log').classList.toggle('hidden', !isStaff);
    $('#tab-usuarios').classList.toggle('hidden', state.user.rol !== 'admin');
    $('#btn-nuevo-curso').classList.toggle('hidden', state.user.rol !== 'admin');
    $('#who').textContent = `${state.user.nombre} · ${state.user.rol}`;
    switchTab('cursos');
    loadCursos();
  }

  function switchTab(name) {
    $$('.tab').forEach((t) => t.setAttribute('aria-selected', t.dataset.tab === name ? 'true' : 'false'));
    $$('[data-panel]').forEach((p) => {
      const show = p.dataset.panel === name;
      p.classList.toggle('hidden', !show);
    });
    if (name === 'usuarios') loadUsuarios();
    if (name === 'log') loadLog();
    if (name === 'cursos') {
      state.cursoActual = null;
    }
  }

  function modalityLabel(m) {
    return ({ online: 'Online', presencial: 'Presencial', mixta: 'Mixta' })[m] || m;
  }

  function renderCursos() {
    const grid = $('#cursos-grid');
    if (!state.cursos.length) {
      grid.innerHTML = '<div class="empty">No hay cursos activos.</div>';
      return;
    }
    grid.innerHTML = state.cursos.map((c) => {
      const locked = !c.puede_acceder;
      const rol = c.rol_en_curso
        ? `<span class="pill ok">${c.rol_en_curso}</span>`
        : `<span class="pill lock">Sin inscripción</span>`;
      return `
        <article class="card ${locked ? 'locked' : ''}" data-id="${c.id}">
          <div class="meta">
            <span class="pill">${modalityLabel(c.modalidad)}</span>
            ${rol}
            ${c.tiene_enlace ? '<span class="pill live">Clase con link</span>' : ''}
          </div>
          <h3>${escapeHtml(c.nombre)}</h3>
          <p>${escapeHtml(c.descripcion || 'Sin descripción.')}</p>
          <p style="font-size:.8rem">${c.alumnos_n} alumnos · ${c.profesores_n} profesores</p>
          <div class="card-actions">
            ${locked
              ? '<button type="button" class="btn btn-ghost" disabled>Bloqueado</button>'
              : `<button type="button" class="btn btn-primary" data-open="${c.id}">Entrar</button>`}
          </div>
        </article>`;
    }).join('');

    grid.querySelectorAll('[data-open]').forEach((btn) => {
      btn.addEventListener('click', () => openCurso(Number(btn.dataset.open)));
    });
  }

  async function loadCursos() {
    const data = await api('cursos');
    state.cursos = data.cursos || [];
    renderCursos();
  }

  async function openCurso(id) {
    try {
      const data = await api('curso', { params: { id } });
      state.cursoActual = data;
      switchTab('curso');
      renderCursoDetalle();
    } catch (e) {
      alert(e.message || 'Sin acceso');
    }
  }

  function renderCursoDetalle() {
    const { curso, inscritos } = state.cursoActual;
    $('#curso-meta').textContent = `${modalityLabel(curso.modalidad)} · ${curso.codigo}`;
    $('#curso-titulo').textContent = curso.nombre;
    $('#curso-desc-view').textContent = curso.descripcion || '';

    const tiene = !!curso.enlace_clase;
    $('#enlace-estado').textContent = tiene
      ? `Enlace publicado: ${curso.enlace_clase}`
      : 'Sin enlace publicado todavía.';
    const btnUnirse = $('#btn-unirse');
    btnUnirse.classList.toggle('hidden', !tiene);
    if (tiene) btnUnirse.href = curso.enlace_clase;

    const btnMat = $('#btn-material');
    const hasMat = !!curso.aula_url;
    btnMat.classList.toggle('hidden', !hasMat);
    if (hasMat) btnMat.href = curso.aula_url;

    const gestiona = !!curso.puede_gestionar;
    $('#form-enlace').classList.toggle('hidden', !gestiona);
    $('#box-gestion').classList.toggle('hidden', !gestiona);
    $('#input-enlace').value = curso.enlace_clase || '';
    $('#box-asignar-profe').classList.toggle('hidden', state.user.rol !== 'admin');

    if (gestiona) {
      loadDisponibles(curso.id);
      renderInscritos(inscritos || []);
      if (state.user.rol === 'admin') loadProfesDisponibles(inscritos || []);
    }
  }

  function renderInscritos(rows) {
    const tb = $('#tbody-inscritos');
    if (!rows.length) {
      tb.innerHTML = '<tr><td colspan="4">Sin inscritos aún.</td></tr>';
      return;
    }
    tb.innerHTML = rows.map((r) => `
      <tr>
        <td>${escapeHtml(r.nombre)}</td>
        <td>${escapeHtml(r.rut)}</td>
        <td>${escapeHtml(r.rol_en_curso)}</td>
        <td>
          ${r.rol_en_curso === 'alumno' || state.user.rol === 'admin'
            ? `<button type="button" class="btn btn-danger" data-baja="${r.usuario_id}">Quitar</button>`
            : ''}
        </td>
      </tr>
    `).join('');

    tb.querySelectorAll('[data-baja]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        if (!confirm('¿Desinscribir a esta persona del curso?')) return;
        try {
          await api('desinscribir', {
            method: 'POST',
            body: { curso_id: state.cursoActual.curso.id, usuario_id: Number(btn.dataset.baja) },
          });
          await openCurso(state.cursoActual.curso.id);
        } catch (e) {
          alert(e.message);
        }
      });
    });
  }

  async function loadDisponibles(cursoId) {
    const sel = $('#select-alumno');
    sel.innerHTML = '<option value="">Cargando…</option>';
    try {
      const data = await api('alumnos_disponibles', { params: { curso_id: cursoId } });
      const list = data.alumnos || [];
      if (!list.length) {
        sel.innerHTML = '<option value="">No hay alumnos precargados disponibles</option>';
        return;
      }
      sel.innerHTML = '<option value="">Selecciona un alumno…</option>' +
        list.map((a) => `<option value="${a.id}">${escapeHtml(a.nombre)} · ${escapeHtml(a.rut)}</option>`).join('');
    } catch (e) {
      sel.innerHTML = `<option value="">${escapeHtml(e.message)}</option>`;
    }
  }

  async function loadProfesDisponibles(inscritos) {
    const sel = $('#select-profe');
    if (!sel) return;
    sel.innerHTML = '<option value="">Cargando…</option>';
    try {
      const data = await api('usuarios', { params: { rol: 'profesor' } });
      const ya = new Set((inscritos || []).map((i) => Number(i.usuario_id)));
      const list = (data.usuarios || []).filter((u) => u.activo && !ya.has(Number(u.id)));
      if (!list.length) {
        sel.innerHTML = '<option value="">No hay profesores disponibles</option>';
        return;
      }
      sel.innerHTML = '<option value="">Selecciona un profesor…</option>' +
        list.map((a) => `<option value="${a.id}">${escapeHtml(a.nombre)} · ${escapeHtml(a.rut)}</option>`).join('');
    } catch (e) {
      sel.innerHTML = `<option value="">${escapeHtml(e.message)}</option>`;
    }
  }

  async function loadUsuarios() {
    const data = await api('usuarios');
    const tb = $('#tbody-usuarios');
    tb.innerHTML = (data.usuarios || []).map((u) => `
      <tr>
        <td>${escapeHtml(u.nombre)}</td>
        <td>${escapeHtml(u.rut)}</td>
        <td>${escapeHtml(u.rol)}</td>
        <td>${escapeHtml(u.email || '—')}</td>
      </tr>
    `).join('');
  }

  async function loadLog() {
    const data = await api('log', { params: { limit: 100 } });
    const tb = $('#tbody-log');
    const rows = data.log || [];
    if (!rows.length) {
      tb.innerHTML = '<tr><td colspan="6">Sin movimientos aún.</td></tr>';
      return;
    }
    tb.innerHTML = rows.map((r) => `
      <tr>
        <td>${escapeHtml(r.created_at)}</td>
        <td>${r.accion === 'inscribir' ? 'Alta' : 'Baja'}</td>
        <td>${escapeHtml(r.curso_nombre)}</td>
        <td>${escapeHtml(r.usuario_nombre)}<br><small>${escapeHtml(r.usuario_rut)}</small></td>
        <td>${escapeHtml(r.rol_en_curso)}</td>
        <td>${escapeHtml(r.realizado_por)}</td>
      </tr>
    `).join('');
  }

  function escapeHtml(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  // Events
  $('#form-login').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const err = $('#login-error');
    err.classList.add('hidden');
    try {
      const data = await api('login', {
        method: 'POST',
        body: {
          usuario: $('#login-user').value.trim(),
          clave: $('#login-pass').value,
        },
      });
      state.user = data.user;
      showApp();
    } catch (e) {
      err.textContent = e.message || 'No se pudo iniciar sesión';
      err.classList.remove('hidden');
    }
  });

  $('#btn-logout').addEventListener('click', async () => {
    try { await api('logout', { method: 'POST', body: {} }); } catch (_) {}
    state.user = null;
    showLogin();
  });

  $$('.tab').forEach((t) => {
    t.addEventListener('click', () => switchTab(t.dataset.tab));
  });

  $('#btn-back-cursos').addEventListener('click', () => {
    switchTab('cursos');
    loadCursos();
  });

  $('#btn-nuevo-curso').addEventListener('click', () => {
    $('#form-curso').classList.toggle('hidden');
  });
  $('#btn-cancel-curso').addEventListener('click', () => {
    $('#form-curso').classList.add('hidden');
  });

  $('#form-curso').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    try {
      await api('curso_crear', {
        method: 'POST',
        body: {
          nombre: $('#curso-nombre').value.trim(),
          descripcion: $('#curso-desc').value.trim(),
          modalidad: $('#curso-modalidad').value,
        },
      });
      $('#form-curso').reset();
      $('#form-curso').classList.add('hidden');
      await loadCursos();
    } catch (e) {
      alert(e.message);
    }
  });

  $('#form-enlace').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const msg = $('#enlace-msg');
    msg.textContent = '';
    msg.className = 'status-msg';
    try {
      await api('curso_enlace', {
        method: 'POST',
        body: {
          curso_id: state.cursoActual.curso.id,
          enlace_clase: $('#input-enlace').value.trim(),
        },
      });
      msg.textContent = 'Enlace guardado.';
      msg.classList.add('ok');
      await openCurso(state.cursoActual.curso.id);
    } catch (e) {
      msg.textContent = e.message;
      msg.classList.add('err');
    }
  });

  $('#btn-limpiar-enlace').addEventListener('click', async () => {
    try {
      await api('curso_enlace', {
        method: 'POST',
        body: { curso_id: state.cursoActual.curso.id, enlace_clase: '' },
      });
      await openCurso(state.cursoActual.curso.id);
    } catch (e) {
      alert(e.message);
    }
  });

  $('#form-inscribir').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const msg = $('#inscribir-msg');
    msg.textContent = '';
    msg.className = 'status-msg';
    const usuarioId = Number($('#select-alumno').value);
    if (!usuarioId) {
      msg.textContent = 'Selecciona un alumno.';
      msg.classList.add('err');
      return;
    }
    try {
      await api('inscribir', {
        method: 'POST',
        body: {
          curso_id: state.cursoActual.curso.id,
          usuario_id: usuarioId,
          rol_en_curso: 'alumno',
        },
      });
      msg.textContent = 'Alumno inscrito. Quedó registrado en el log.';
      msg.classList.add('ok');
      await openCurso(state.cursoActual.curso.id);
    } catch (e) {
      msg.textContent = e.message;
      msg.classList.add('err');
    }
  });

  const formProfe = $('#form-asignar-profe');
  if (formProfe) {
    formProfe.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const msg = $('#profe-msg');
      msg.textContent = '';
      msg.className = 'status-msg';
      const usuarioId = Number($('#select-profe').value);
      if (!usuarioId) {
        msg.textContent = 'Selecciona un profesor.';
        msg.classList.add('err');
        return;
      }
      try {
        await api('inscribir', {
          method: 'POST',
          body: {
            curso_id: state.cursoActual.curso.id,
            usuario_id: usuarioId,
            rol_en_curso: 'profesor',
          },
        });
        msg.textContent = 'Profesor asignado. Quedó en el log.';
        msg.classList.add('ok');
        await openCurso(state.cursoActual.curso.id);
      } catch (e) {
        msg.textContent = e.message;
        msg.classList.add('err');
      }
    });
  }

  $('#form-usuario').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const msg = $('#usuario-msg');
    msg.textContent = '';
    msg.className = 'status-msg';
    try {
      await api('usuario_crear', {
        method: 'POST',
        body: {
          rut: $('#u-rut').value.trim(),
          nombre: $('#u-nombre').value.trim(),
          email: $('#u-email').value.trim(),
          rol: $('#u-rol').value,
          clave: $('#u-clave').value,
        },
      });
      msg.textContent = 'Usuario cargado.';
      msg.classList.add('ok');
      $('#form-usuario').reset();
      await loadUsuarios();
    } catch (e) {
      msg.textContent = e.message;
      msg.classList.add('err');
    }
  });

  $('#btn-refresh-log').addEventListener('click', () => loadLog());

  // Boot
  (async () => {
    try {
      const data = await api('me');
      state.user = data.user;
      showApp();
    } catch (_) {
      showLogin();
    }
  })();
})();
