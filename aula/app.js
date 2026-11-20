(() => {
  'use strict';

  const SITE = 'https://ddgdelvalle.cl/';

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
    foroCurso: 0,
  };

  const viewLogin = $('#view-login');
  const viewApp = $('#view-app');

  function escapeHtml(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function fecha(s) {
    if (!s) return 'Sin registro';
    const d = new Date(String(s).replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return s;
    return d.toLocaleString('es-CL', { day: 'numeric', month: 'short', year: 'numeric' });
  }

  function coverFor(c) {
    if (c.portada) return c.portada;
    const n = `${c.nombre} ${c.codigo}`.toLowerCase();
    const base = 'https://ddgdelvalle.cl/wp-content/uploads/2026/04/';
    if (n.includes('riego') || n.includes('hidr')) return base + 'Riego-.webp';
    if (n.includes('plaguic')) return base + 'Plaguicida-.webp';
    if (n.includes('maq')) return base + 'Mantencion-Maquinaria-.webp';
    if (n.includes('ia') || n.includes('ofim')) return base + 'IA-.webp';
    if (n.includes('labor') || n.includes('gastr')) return base + 'Apresto-Laboral-.webp';
    return 'https://ddgdelvalle.cl/wp-content/uploads/2026/03/Banner-DDG.jpg';
  }

  function stars(n) {
    const full = Math.max(0, Math.min(5, Number(n) || 0));
    if (!full) return '';
    return `<p class="stars" aria-label="${full} de 5">${'★'.repeat(full)}${'☆'.repeat(5 - full)}</p>`;
  }

  function resolveVideoSrc(url) {
    if (!url) return '';
    if (url.startsWith('stream.php') || /^[\w.-]+\.mp4$/i.test(url)) {
      const file = url.startsWith('stream.php')
        ? (new URL(url, location.href)).searchParams.get('f')
        : url;
      return `stream.php?f=${encodeURIComponent(file || url)}`;
    }
    return url;
  }

  function showLogin() {
    viewLogin.classList.remove('hidden');
    viewApp.classList.add('hidden');
  }

  function showApp() {
    viewLogin.classList.add('hidden');
    viewApp.classList.remove('hidden');
    const nombre = state.user.nombre || 'Alumno';
    $('#who').textContent = nombre;
    $('#who-mobile').textContent = nombre;
    const staff = state.user.rol === 'admin' || state.user.rol === 'profesor';
    const isAdmin = state.user.rol === 'admin';
    $('#nav-gestion').classList.toggle('hidden', !staff);
    $$('.admin-only').forEach((el) => el.classList.toggle('hidden', !isAdmin));
    if (!location.hash) location.replace('#/cursos');
    route();
  }

  function setNav(name) {
    $$('[data-nav]').forEach((el) => {
      el.classList.toggle('is-active', el.dataset.nav === name);
    });
    $$('.panel').forEach((p) => p.classList.add('hidden'));
    const panel = $('#panel-' + name);
    if (panel) panel.classList.remove('hidden');
  }

  function parseHash() {
    const parts = location.hash.replace(/^#\/?/, '').split('/').filter(Boolean);
    return { a: parts[0] || 'cursos', b: parts[1] || '', c: parts[2] || '' };
  }

  function go(hash) {
    if (location.hash === '#' + hash) route();
    else location.hash = hash;
  }

  async function route() {
    if (!state.user) return;
    const { a, b, c } = parseHash();
    if (a === 'curso' && b) {
      setNav('curso');
      try {
        await ensureCurso(Number(b));
        showCourseTab(c || 'presentacion');
      } catch (e) {
        alert(e.message || 'Sin acceso');
        go('/cursos');
      }
      return;
    }
    const player = $('#video-player');
    if (player && !player.paused) player.pause();
    if (a === 'suscripcion') {
      setNav('suscripcion');
      await loadSuscripcion();
      return;
    }
    if (a === 'actividad') {
      setNav('actividad');
      await loadActividad();
      return;
    }
    if (a === 'gestion') {
      if (state.user.rol !== 'admin' && state.user.rol !== 'profesor') {
        go('/cursos');
        return;
      }
      setNav('gestion');
      if (state.user.rol === 'admin') {
        loadUsuarios();
        loadSolicitudesAdmin();
      }
      loadLog();
      return;
    }
    setNav('cursos');
    await loadCursos();
  }

  function misCursos() {
    if (state.user.rol === 'admin') return state.cursos;
    return state.cursos.filter((c) => c.puede_acceder);
  }

  function renderCursos() {
    const grid = $('#cursos-grid');
    const list = misCursos();
    if (!list.length) {
      grid.innerHTML = `<div class="empty"><p>Todavía no tienes cursos inscritos.</p><p><a class="btn btn-dark" href="${SITE}#cursos">Ver cursos publicados</a></p></div>`;
      return;
    }
    grid.innerHTML = list.map((c) => `
      <article class="card">
        <img class="cover" src="${escapeHtml(coverFor(c))}" alt="">
        <div class="card-body">
          <h3>${escapeHtml(c.nombre)}</h3>
          <p class="relator">${escapeHtml(c.relator || 'DDG Del Valle')}</p>
          ${stars(c.valoracion)}
          <button type="button" class="btn btn-dark" data-open="${c.id}">Entrar</button>
        </div>
      </article>`).join('');
    grid.querySelectorAll('[data-open]').forEach((btn) => {
      btn.addEventListener('click', () => go('/curso/' + btn.dataset.open));
    });
  }

  async function loadCursos() {
    const data = await api('cursos');
    state.cursos = data.cursos || [];
    renderCursos();
  }

  async function ensureCurso(id) {
    if (state.cursoActual?.curso?.id === id) return;
    state.cursoActual = await api('curso', { params: { id } });
    state.foroCurso = 0;
    renderCurso();
  }

  function renderCurso() {
    const { curso, modulos, inscritos } = state.cursoActual;
    $('#curso-titulo').textContent = curso.nombre;
    $('#curso-crumb').textContent = curso.nombre;
    $('#curso-bajada').textContent = curso.bajada || '';
    $('#curso-bajada').classList.toggle('hidden', !curso.bajada);
    $('#curso-desc-view').textContent = curso.descripcion || '';
    $('#tab-vivo').classList.toggle('hidden', !curso.es_sincrono);

    const prog = $('#programa-row');
    if (curso.programa_pdf) {
      const nombre = curso.programa_pdf.split('/').pop() || 'Programa del curso';
      prog.innerHTML = `<button type="button" class="linkish" id="btn-programa">${escapeHtml(nombre)}</button>`;
      $('#btn-programa').addEventListener('click', () => openPdf(curso.programa_pdf, nombre));
    } else {
      prog.innerHTML = '<span class="muted">Programa no publicado.</span>';
    }

    renderVideo(curso);
    renderModulos(modulos || [], curso);
    renderVivo(curso);

    const gestiona = !!curso.puede_gestionar;
    $('#box-gestion').classList.toggle('hidden', !gestiona);
    if (gestiona) {
      $('#ficha-bajada').value = curso.bajada || '';
      $('#ficha-desc').value = curso.descripcion || '';
      $('#ficha-relator').value = curso.relator || '';
      $('#ficha-valor').value = curso.valoracion || 0;
      $('#ficha-portada').value = curso.portada || '';
      $('#ficha-pdf').value = curso.programa_pdf || '';
      $('#ficha-sincrono').checked = !!curso.es_sincrono;
      $('#input-enlace').value = curso.enlace_clase || '';
      $('#input-video').value = curso.video_url || '';
      $('#input-video-titulo').value = curso.video_titulo || '';
      $('#box-asignar-profe').classList.toggle('hidden', state.user.rol !== 'admin');
      loadDisponibles(curso.id);
      renderInscritos(inscritos || []);
      if (state.user.rol === 'admin') loadProfesDisponibles(inscritos || []);
    }
  }

  function showCourseTab(name) {
    const curso = state.cursoActual?.curso;
    if (name === 'vivo' && curso && !curso.es_sincrono) name = 'presentacion';
    $$('#course-tabs [data-ctab]').forEach((btn) => {
      btn.setAttribute('aria-selected', btn.dataset.ctab === name ? 'true' : 'false');
    });
    $$('.ctab').forEach((p) => p.classList.add('hidden'));
    const panel = $('#ctab-' + name);
    if (panel) panel.classList.remove('hidden');
    const player = $('#video-player');
    if (name !== 'presentacion' && player && !player.paused) player.pause();
    if (name === 'comunidad') loadForo();
    const { a, b } = parseHash();
    const want = name === 'presentacion' ? `/curso/${b}` : `/curso/${b}/${name}`;
    if (a === 'curso' && location.hash !== '#' + want) {
      history.replaceState(null, '', '#' + want);
    }
  }

  function renderVideo(curso) {
    const player = $('#video-player');
    const empty = $('#stage-empty');
    const err = $('#video-error');
    err.classList.add('hidden');
    const has = !!curso.video_url;
    player.classList.toggle('hidden', !has);
    empty.classList.toggle('hidden', has);
    if (!has) {
      player.removeAttribute('src');
      player.load();
      return;
    }
    const src = resolveVideoSrc(curso.video_url);
    player.oncontextmenu = (e) => e.preventDefault();
    player.onerror = () => err.classList.remove('hidden');
    if (player.src !== new URL(src, location.href).href) {
      player.src = src;
      player.load();
    }
  }

  function renderModulos(modulos, curso) {
    const box = $('#modulos');
    const items = modulos.slice();
    if (!items.length && curso.video_url) {
      items.push({
        id: 0,
        titulo: curso.video_titulo || 'Clase grabada',
        tipo: 'video',
        url: curso.video_url,
        completado: false,
        virtual: true,
      });
    }
    if (curso.aula_url) {
      items.push({
        id: 0,
        titulo: 'Material del curso',
        tipo: 'enlace',
        url: curso.aula_url,
        completado: false,
        virtual: true,
      });
    }
    if (!items.length) {
      box.innerHTML = '<div class="empty">El contenido de este curso todavía no está publicado.</div>';
      return;
    }
    box.innerHTML = items.map((m, i) => `
      <article class="modulo">
        <header>
          <h3>${escapeHtml(m.titulo)}</h3>
          <span class="pill ${m.completado ? '' : 'wait'}">${m.completado ? 'Finalizado' : (m.tipo === 'archivo' ? 'Descargable' : 'Pendiente')}</span>
        </header>
        <div class="modulo-body">
          ${m.tipo === 'video' && m.url
            ? `<video controls controlsList="nodownload" playsinline preload="none" src="${escapeHtml(resolveVideoSrc(m.url))}"></video>`
            : ''}
          ${m.tipo !== 'video' && m.url
            ? `<a class="btn btn-ghost" href="${escapeHtml(m.url)}" target="_blank" rel="noopener" ${m.tipo === 'archivo' ? 'download' : ''}>${m.tipo === 'archivo' ? 'Descargar' : 'Abrir'}</a>`
            : ''}
          ${m.id
            ? `<button type="button" class="btn btn-dark" data-done="${m.id}" ${m.completado ? 'disabled' : ''}>${m.completado ? 'Módulo finalizado' : 'Marcar como finalizado'}</button>`
            : ''}
          ${curso.puede_gestionar && m.id
            ? `<button type="button" class="btn btn-ghost" data-delmod="${m.id}">Quitar módulo</button>`
            : ''}
        </div>
      </article>`).join('');

    box.querySelectorAll('[data-done]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        try {
          await api('progreso', { method: 'POST', body: { modulo_id: Number(btn.dataset.done) } });
          state.cursoActual = null;
          await ensureCurso(curso.id);
          showCourseTab('contenido');
        } catch (e) {
          alert(e.message);
        }
      });
    });
    box.querySelectorAll('[data-delmod]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        if (!confirm('¿Quitar este módulo?')) return;
        try {
          await api('modulo_borrar', { method: 'POST', body: { id: Number(btn.dataset.delmod) } });
          state.cursoActual = null;
          await ensureCurso(curso.id);
          showCourseTab('contenido');
        } catch (e) {
          alert(e.message);
        }
      });
    });
  }

  function renderVivo(curso) {
    const tiene = !!curso.enlace_clase;
    $('#enlace-estado').textContent = tiene
      ? 'La sesión ya está publicada. Entra con el mismo nombre con el que te inscribiste.'
      : 'La conexión en vivo se habilita cuando el relator publica el enlace.';
    const btn = $('#btn-unirse');
    btn.classList.toggle('hidden', !tiene);
    if (tiene) btn.href = curso.enlace_clase;
    const mat = $('#btn-material');
    mat.classList.toggle('hidden', !curso.aula_url);
    if (curso.aula_url) mat.href = curso.aula_url;
  }

  async function loadForo() {
    const curso = state.cursoActual?.curso;
    if (!curso) return;
    const list = $('#foro-list');
    if (state.foroCurso !== curso.id) list.innerHTML = '<p class="muted">Cargando comentarios…</p>';
    try {
      const data = await api('foro', { params: { curso_id: curso.id } });
      state.foroCurso = curso.id;
      const msgs = data.mensajes || [];
      list.innerHTML = msgs.length
        ? msgs.map((m) => `
            <article class="comment">
              <strong>${escapeHtml(m.nombre)}</strong>
              <time>${escapeHtml(fecha(m.created_at))}</time>
              <p>${escapeHtml(m.mensaje)}</p>
            </article>`).join('')
        : '<p class="muted">Todavía no hay comentarios. Sé el primero.</p>';
    } catch (e) {
      list.innerHTML = `<p class="error">${escapeHtml(e.message)}</p>`;
    }
  }

  async function loadSuscripcion() {
    const box = $('#suscripcion-body');
    box.innerHTML = '<p class="muted">Cargando…</p>';
    try {
      const data = await api('suscripcion');
      const s = data.suscripcion;
      if (!s) {
        box.innerHTML = '<p>No tienes una suscripción activa. Si contrataste un plan y no aparece, escríbelo en Mi Actividad.</p>';
        return;
      }
      const tipo = s.tipo === 'diario' ? 'Ingreso diario' : `Mensual · ${s.meses} ${Number(s.meses) === 1 ? 'mes' : 'meses'}`;
      const vig = s.vigente_desde || s.vigente_hasta
        ? `<p>Vigencia: ${escapeHtml(s.vigente_desde || '—')} a ${escapeHtml(s.vigente_hasta || '—')}</p>`
        : '';
      box.innerHTML = `<p class="lead">${tipo}</p>${vig}<p>${escapeHtml(s.detalle || '')}</p>`;
    } catch (e) {
      box.innerHTML = `<p class="error">${escapeHtml(e.message)}</p>`;
    }
  }

  async function loadActividad() {
    const box = $('#actividad-body');
    box.innerHTML = '<p class="muted">Cargando…</p>';
    try {
      const data = await api('actividad');
      const cursos = data.cursos || [];
      if (!cursos.length) {
        box.innerHTML = '<div class="empty">Cuando entres a un curso, el avance queda registrado aquí.</div>';
      } else {
        box.innerHTML = cursos.map((c) => `
          <article class="activity-row">
            <strong>${escapeHtml(c.nombre)}</strong>
            <p class="muted">Último acceso: ${escapeHtml(fecha(c.ultimo_acceso))}</p>
            <div class="progress" aria-label="${c.avance}%"><span style="width:${c.avance}%"></span></div>
            <p>${c.finalizado ? 'Curso finalizado.' : `${c.completados} de ${c.modulos_n} módulos finalizados.`}</p>
            ${c.certificado ? `<a class="btn btn-dark" href="certificado.php?curso_id=${c.id}" target="_blank" rel="noopener">Descargar certificado</a>` : '<p class="muted">El certificado aparece al terminar todos los módulos.</p>'}
          </article>`).join('');
      }
      const prev = (data.solicitudes || []).map((s) =>
        `<article class="comment"><strong>${s.estado === 'revisada' ? 'Revisada' : 'Pendiente'}</strong><time>${escapeHtml(fecha(s.created_at))}</time><p>${escapeHtml(s.mensaje)}</p></article>`
      ).join('');
      if (prev) box.insertAdjacentHTML('beforeend', `<h2>Tus solicitudes</h2>${prev}`);
    } catch (e) {
      box.innerHTML = `<p class="error">${escapeHtml(e.message)}</p>`;
    }
  }

  function openPdf(url, title) {
    const frame = $('#pdf-frame');
    const glue = url.includes('#') ? '&' : '#';
    frame.src = url + glue + 'toolbar=0&navpanes=0&scrollbar=1';
    $('#pdf-title').textContent = title || 'Programa del curso';
    $('#pdf-dialog').showModal();
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
        <td>${r.rol_en_curso === 'alumno' || state.user.rol === 'admin'
          ? `<button type="button" class="btn btn-ghost" data-baja="${r.usuario_id}">Quitar</button>` : ''}</td>
      </tr>`).join('');
    tb.querySelectorAll('[data-baja]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        if (!confirm('¿Desinscribir a esta persona del curso?')) return;
        try {
          await api('desinscribir', {
            method: 'POST',
            body: { curso_id: state.cursoActual.curso.id, usuario_id: Number(btn.dataset.baja) },
          });
          state.cursoActual = null;
          await ensureCurso(Number(parseHash().b));
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
      sel.innerHTML = list.length
        ? '<option value="">Selecciona un alumno…</option>' + list.map((a) => `<option value="${a.id}">${escapeHtml(a.nombre)} · ${escapeHtml(a.rut)}</option>`).join('')
        : '<option value="">No hay alumnos disponibles</option>';
    } catch (e) {
      sel.innerHTML = `<option value="">${escapeHtml(e.message)}</option>`;
    }
  }

  async function loadProfesDisponibles(inscritos) {
    const sel = $('#select-profe');
    if (!sel) return;
    try {
      const data = await api('usuarios', { params: { rol: 'profesor' } });
      const ya = new Set((inscritos || []).map((i) => Number(i.usuario_id)));
      const list = (data.usuarios || []).filter((u) => u.activo && !ya.has(Number(u.id)));
      sel.innerHTML = list.length
        ? '<option value="">Selecciona un profesor…</option>' + list.map((a) => `<option value="${a.id}">${escapeHtml(a.nombre)} · ${escapeHtml(a.rut)}</option>`).join('')
        : '<option value="">No hay profesores disponibles</option>';
    } catch (e) {
      sel.innerHTML = `<option value="">${escapeHtml(e.message)}</option>`;
    }
  }

  async function loadUsuarios() {
    if (state.user.rol !== 'admin') {
      $('#form-usuario').classList.add('hidden');
      $('#form-suscripcion-admin').classList.add('hidden');
      return;
    }
    const data = await api('usuarios');
    $('#tbody-usuarios').innerHTML = (data.usuarios || []).map((u) => `
      <tr>
        <td>${escapeHtml(u.nombre)}</td>
        <td>${escapeHtml(u.rut)}</td>
        <td>${escapeHtml(u.rol)}</td>
        <td>${escapeHtml(u.email || '—')}</td>
      </tr>`).join('');
  }

  async function loadLog() {
    const data = await api('log', { params: { limit: 100 } });
    const rows = data.log || [];
    $('#tbody-log').innerHTML = rows.length ? rows.map((r) => `
      <tr>
        <td>${escapeHtml(fecha(r.created_at))}</td>
        <td>${r.accion === 'inscribir' ? 'Alta' : 'Baja'}</td>
        <td>${escapeHtml(r.curso_nombre)}</td>
        <td>${escapeHtml(r.usuario_nombre)}<br><small>${escapeHtml(r.usuario_rut)}</small></td>
        <td>${escapeHtml(r.rol_en_curso)}</td>
        <td>${escapeHtml(r.realizado_por)}</td>
      </tr>`).join('') : '<tr><td colspan="6">Sin movimientos aún.</td></tr>';
  }

  async function loadSolicitudesAdmin() {
    const box = $('#solicitudes-admin');
    try {
      const data = await api('solicitudes');
      const rows = data.solicitudes || [];
      box.innerHTML = rows.length ? rows.map((s) => `
        <article class="comment">
          <strong>${escapeHtml(s.nombre)} · ${escapeHtml(s.rut)}</strong>
          <time>${escapeHtml(fecha(s.created_at))} · ${escapeHtml(s.estado)}</time>
          <p>${escapeHtml(s.mensaje)}</p>
          ${s.estado === 'pendiente' ? `<button type="button" class="btn btn-ghost" data-rev="${s.id}">Marcar revisada</button>` : ''}
        </article>`).join('') : '<p class="muted">Sin solicitudes.</p>';
      box.querySelectorAll('[data-rev]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          await api('solicitud_estado', { method: 'POST', body: { id: Number(btn.dataset.rev), estado: 'revisada' } });
          loadSolicitudesAdmin();
        });
      });
    } catch (e) {
      box.innerHTML = `<p class="error">${escapeHtml(e.message)}</p>`;
    }
  }

  function msg(el, text, ok) {
    el.textContent = text;
    el.className = 'status-msg ' + (ok ? 'ok' : 'err');
  }

  $('#form-login').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const err = $('#login-error');
    err.classList.add('hidden');
    try {
      const data = await api('login', {
        method: 'POST',
        body: { usuario: $('#login-user').value.trim(), clave: $('#login-pass').value },
      });
      state.user = data.user;
      showApp();
    } catch (e) {
      err.textContent = e.message || 'No se pudo iniciar sesión';
      err.classList.remove('hidden');
    }
  });

  async function logout() {
    try { await api('logout', { method: 'POST', body: {} }); } catch (_) {}
    state.user = null;
    state.cursoActual = null;
    history.replaceState(null, '', location.pathname);
    showLogin();
  }
  $('#btn-logout').addEventListener('click', logout);
  $('#btn-logout-mobile').addEventListener('click', logout);

  $$('[data-nav]').forEach((el) => {
    el.addEventListener('click', () => go('/' + el.dataset.nav));
  });

  $$('#course-tabs [data-ctab]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const id = state.cursoActual?.curso?.id;
      if (!id) return;
      const tab = btn.dataset.ctab;
      go(tab === 'presentacion' ? `/curso/${id}` : `/curso/${id}/${tab}`);
    });
  });

  $('#pdf-close').addEventListener('click', () => {
    $('#pdf-frame').src = 'about:blank';
    $('#pdf-dialog').close();
  });

  $('#form-foro').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const note = $('#foro-msg');
    const curso = state.cursoActual?.curso;
    if (!curso) return;
    try {
      await api('foro', {
        method: 'POST',
        params: { curso_id: curso.id },
        body: { mensaje: $('#foro-texto').value.trim() },
      });
      $('#foro-texto').value = '';
      msg(note, 'Comentario publicado.', true);
      state.foroCurso = 0;
      loadForo();
    } catch (e) {
      msg(note, e.message, false);
    }
  });

  $('#form-solicitud').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const note = $('#solicitud-msg');
    try {
      await api('solicitud', { method: 'POST', body: { mensaje: $('#solicitud-texto').value.trim() } });
      $('#solicitud-texto').value = '';
      msg(note, 'Solicitud enviada. La OTEC te contactará.', true);
      loadActividad();
    } catch (e) {
      msg(note, e.message, false);
    }
  });

  $('#btn-nuevo-curso').addEventListener('click', () => $('#form-curso').classList.toggle('hidden'));
  $('#btn-cancel-curso').addEventListener('click', () => $('#form-curso').classList.add('hidden'));
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
      state.cursos = [];
      go('/cursos');
    } catch (e) {
      alert(e.message);
    }
  });

  $('#form-ficha').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const note = $('#ficha-msg');
    try {
      await api('curso_ficha', {
        method: 'POST',
        body: {
          curso_id: state.cursoActual.curso.id,
          bajada: $('#ficha-bajada').value.trim(),
          descripcion: $('#ficha-desc').value.trim(),
          relator: $('#ficha-relator').value.trim(),
          valoracion: Number($('#ficha-valor').value || 0),
          portada: $('#ficha-portada').value.trim(),
          programa_pdf: $('#ficha-pdf').value.trim(),
          sincrono: $('#ficha-sincrono').checked ? 1 : 0,
        },
      });
      msg(note, 'Ficha guardada.', true);
      state.cursoActual = null;
      await ensureCurso(Number(parseHash().b));
    } catch (e) {
      msg(note, e.message, false);
    }
  });

  $('#form-enlace').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const note = $('#enlace-msg');
    try {
      await api('curso_enlace', {
        method: 'POST',
        body: { curso_id: state.cursoActual.curso.id, enlace_clase: $('#input-enlace').value.trim() },
      });
      msg(note, 'Enlace guardado.', true);
      state.cursoActual = null;
      await ensureCurso(Number(parseHash().b));
    } catch (e) {
      msg(note, e.message, false);
    }
  });

  $('#btn-limpiar-enlace').addEventListener('click', async () => {
    try {
      await api('curso_enlace', { method: 'POST', body: { curso_id: state.cursoActual.curso.id, enlace_clase: '' } });
      state.cursoActual = null;
      await ensureCurso(Number(parseHash().b));
    } catch (e) { alert(e.message); }
  });

  $('#form-video').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const note = $('#video-msg');
    try {
      await api('curso_video', {
        method: 'POST',
        body: {
          curso_id: state.cursoActual.curso.id,
          video_url: $('#input-video').value.trim(),
          video_titulo: $('#input-video-titulo').value.trim(),
        },
      });
      msg(note, 'Video guardado.', true);
      state.cursoActual = null;
      await ensureCurso(Number(parseHash().b));
    } catch (e) {
      msg(note, e.message, false);
    }
  });

  $('#btn-limpiar-video').addEventListener('click', async () => {
    try {
      await api('curso_video', { method: 'POST', body: { curso_id: state.cursoActual.curso.id, video_url: '', video_titulo: '' } });
      state.cursoActual = null;
      await ensureCurso(Number(parseHash().b));
    } catch (e) { alert(e.message); }
  });

  $('#form-modulo').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const note = $('#mod-msg');
    try {
      await api('modulo_crear', {
        method: 'POST',
        body: {
          curso_id: state.cursoActual.curso.id,
          titulo: $('#mod-titulo').value.trim(),
          tipo: $('#mod-tipo').value,
          url: $('#mod-url').value.trim(),
        },
      });
      $('#mod-titulo').value = '';
      $('#mod-url').value = '';
      msg(note, 'Módulo agregado.', true);
      state.cursoActual = null;
      await ensureCurso(Number(parseHash().b));
      showCourseTab('contenido');
    } catch (e) {
      msg(note, e.message, false);
    }
  });

  $('#form-inscribir').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const note = $('#inscribir-msg');
    const usuarioId = Number($('#select-alumno').value);
    if (!usuarioId) { msg(note, 'Selecciona un alumno.', false); return; }
    try {
      await api('inscribir', {
        method: 'POST',
        body: { curso_id: state.cursoActual.curso.id, usuario_id: usuarioId, rol_en_curso: 'alumno' },
      });
      msg(note, 'Alumno inscrito.', true);
      state.cursoActual = null;
      await ensureCurso(Number(parseHash().b));
    } catch (e) { msg(note, e.message, false); }
  });

  $('#form-asignar-profe').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const note = $('#profe-msg');
    const usuarioId = Number($('#select-profe').value);
    if (!usuarioId) { msg(note, 'Selecciona un profesor.', false); return; }
    try {
      await api('inscribir', {
        method: 'POST',
        body: { curso_id: state.cursoActual.curso.id, usuario_id: usuarioId, rol_en_curso: 'profesor' },
      });
      msg(note, 'Profesor asignado.', true);
      state.cursoActual = null;
      await ensureCurso(Number(parseHash().b));
    } catch (e) { msg(note, e.message, false); }
  });

  $('#form-usuario').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const note = $('#usuario-msg');
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
      msg(note, 'Usuario cargado.', true);
      $('#form-usuario').reset();
      loadUsuarios();
    } catch (e) { msg(note, e.message, false); }
  });

  $('#form-suscripcion-admin').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const note = $('#sus-msg');
    try {
      await api('suscripcion', {
        method: 'POST',
        body: {
          rut: $('#sus-rut').value.trim(),
          tipo: $('#sus-tipo').value,
          meses: Number($('#sus-meses').value || 0),
          detalle: $('#sus-detalle').value.trim(),
          vigente_desde: $('#sus-desde').value,
          vigente_hasta: $('#sus-hasta').value,
        },
      });
      msg(note, 'Suscripción guardada.', true);
      $('#form-suscripcion-admin').reset();
    } catch (e) { msg(note, e.message, false); }
  });

  $('#btn-refresh-log').addEventListener('click', () => loadLog());
  window.addEventListener('hashchange', () => route());

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
