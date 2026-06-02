/**
 * ================================================================
 * Sistema de Gestión de Horas de Servicio Social
 * app.js — Módulo JavaScript universal
 * ================================================================
 *
 * Cubre:
 *  - Config & API client
 *  - Auth (login, registro, JWT, sesión)
 *  - Router de SPA (hash-based)
 *  - Toast notifications
 *  - Modal helpers
 *  - SSE (Server-Sent Events)
 *  - Vistas: Landing, Auth, Home-Estudiante, Admin
 *  - Utilidades (formato, paginación, etc.)
 * ================================================================
 */

/* ================================================================
   1. CONFIGURACIÓN GLOBAL
   ================================================================ */

const CONFIG = {
  API_BASE: '../../api',
  TOKEN_KEY: 'ss_token',
  USER_KEY: 'ss_user',
  SSE_RECONNECT_DELAY: 3000,
};

/* ================================================================
   2. API CLIENT
   ================================================================ */

const Api = (() => {
  function getToken() {
    return localStorage.getItem(CONFIG.TOKEN_KEY);
  }

  async function request(path, options = {}) {
    const token = getToken();
    const headers = { 'Content-Type': 'application/json', ...(options.headers || {}) };
    if (token) headers['Authorization'] = `Bearer ${token}`;

    const url = `${CONFIG.API_BASE}${path}`;
    let body = options.body;
    if (body && typeof body === 'object' && !(body instanceof FormData)) {
      body = JSON.stringify(body);
    } else if (body instanceof FormData) {
      delete headers['Content-Type'];
    }

    try {
      const res = await fetch(url, { ...options, headers, body });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        throw { status: res.status, message: data.error || `Error ${res.status}` };
      }
      return data;
    } catch (err) {
      if (err.status === 401) {
        Auth.logout(false);
        Router.navigate('/auth');
      }
      throw err;
    }
  }

  return {
    get:    (path, params) => {
      const qs = params ? '?' + new URLSearchParams(params).toString() : '';
      return request(path + qs, { method: 'GET' });
    },
    post:   (path, body)   => request(path, { method: 'POST', body }),
    put:    (path, body)   => request(path, { method: 'PUT', body }),
    delete: (path)         => request(path, { method: 'DELETE' }),
  };
})();

/* ================================================================
   3. AUTH
   ================================================================ */

const Auth = (() => {
  function saveSession(token, user) {
    localStorage.setItem(CONFIG.TOKEN_KEY, token);
    localStorage.setItem(CONFIG.USER_KEY, JSON.stringify(user));
  }

  function getUser() {
    try {
      return JSON.parse(localStorage.getItem(CONFIG.USER_KEY));
    } catch {
      return null;
    }
  }

  function isLoggedIn() {
    return !!localStorage.getItem(CONFIG.TOKEN_KEY) && !!getUser();
  }

  function isAdmin() {
    const u = getUser();
    return u && u.role === 'admin';
  }

  function isStudent() {
    const u = getUser();
    return u && u.role === 'student';
  }

  function logout(redirect = true) {
    localStorage.removeItem(CONFIG.TOKEN_KEY);
    localStorage.removeItem(CONFIG.USER_KEY);
    SSEManager.disconnect();
    if (redirect) Router.navigate('/auth');
  }

  async function login(email, password) {
    const data = await Api.post('/auth/login', { email, password });
    saveSession(data.token, data.user);
    return data.user;
  }

  async function register(payload) {
    const data = await Api.post('/auth/register', payload);
    saveSession(data.token, data.user);
    return data.user;
  }

  async function me() {
    const data = await Api.get('/auth/me');
    localStorage.setItem(CONFIG.USER_KEY, JSON.stringify(data));
    return data;
  }

  return { saveSession, getUser, isLoggedIn, isAdmin, isStudent, login, register, me, logout };
})();

/* ================================================================
   4. ROUTER
   ================================================================ */

const Router = (() => {
  const routes = {};

  function register(path, handler) {
    routes[path] = handler;
  }

  function navigate(path) {
    window.location.hash = path;
  }

  function getCurrentPath() {
    return window.location.hash.replace('#', '') || '/';
  }

  function dispatch() {
    const path = getCurrentPath();

    // Auth guard: rutas protegidas
    const publicRoutes = ['/', '/auth', '/auth/login', '/auth/register'];
    if (!publicRoutes.includes(path) && !Auth.isLoggedIn()) {
      return navigate('/auth');
    }

    // Redirigir si ya está autenticado
    if ((path === '/auth' || path === '/') && Auth.isLoggedIn()) {
      return navigate(Auth.isAdmin() ? '/admin' : '/home');
    }

    const handler = routes[path] || routes['*'];
    if (handler) handler(path);
  }

  window.addEventListener('hashchange', dispatch);
  window.addEventListener('load', dispatch);

  return { register, navigate, dispatch, getCurrentPath };
})();

/* ================================================================
   5. TOAST NOTIFICATIONS
   ================================================================ */

const Toast = (() => {
  let container;

  function getContainer() {
    if (!container) {
      container = document.getElementById('toast-container');
      if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'toast-container';
        document.body.appendChild(container);
      }
    }
    return container;
  }

  const icons = {
    success: '✓',
    error:   '✕',
    warning: '⚠',
    info:    'ℹ',
  };

  function show(title, message = '', type = 'info', duration = 4000) {
    const c = getContainer();
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = `
      <span class="toast-icon">${icons[type] || icons.info}</span>
      <div class="flex-1">
        <div class="toast-title">${title}</div>
        ${message ? `<div class="toast-msg">${message}</div>` : ''}
      </div>
      <button class="toast-close" aria-label="Cerrar">×</button>
    `;
    toast.querySelector('.toast-close').addEventListener('click', () => remove(toast));
    c.appendChild(toast);
    if (duration > 0) setTimeout(() => remove(toast), duration);
    return toast;
  }

  function remove(toast) {
    toast.style.animation = 'toastOut .3s ease forwards';
    setTimeout(() => toast.remove(), 300);
  }

  return {
    success: (title, msg, dur)  => show(title, msg, 'success', dur),
    error:   (title, msg, dur)  => show(title, msg, 'error',   dur),
    warning: (title, msg, dur)  => show(title, msg, 'warning', dur),
    info:    (title, msg, dur)  => show(title, msg, 'info',    dur),
  };
})();

/* ================================================================
   6. MODAL HELPERS
   ================================================================ */

const Modal = (() => {
  function open(id) {
    const overlay = document.getElementById(id);
    if (overlay) overlay.classList.add('active');
  }

  function close(id) {
    const overlay = document.getElementById(id);
    if (overlay) overlay.classList.remove('active');
  }

  function closeAll() {
    document.querySelectorAll('.modal-overlay.active').forEach(m => m.classList.remove('active'));
  }

  function create({ id, title, body, footer, size = '' }) {
    const existing = document.getElementById(id);
    if (existing) existing.remove();

    const overlay = document.createElement('div');
    overlay.id = id;
    overlay.className = 'modal-overlay';
    overlay.innerHTML = `
      <div class="modal ${size ? 'modal-' + size : ''}">
        <div class="modal-header">
          <h3 class="modal-title">${title}</h3>
          <button class="modal-close" data-close="${id}">×</button>
        </div>
        <div class="modal-body">${body}</div>
        ${footer ? `<div class="modal-footer">${footer}</div>` : ''}
      </div>
    `;
    document.body.appendChild(overlay);

    overlay.querySelector('.modal-close')?.addEventListener('click', () => close(id));
    overlay.addEventListener('click', e => { if (e.target === overlay) close(id); });

    return overlay;
  }

  // Cerrar modales al hacer clic en botones [data-close]
  document.addEventListener('click', e => {
    const btn = e.target.closest('[data-close]');
    if (btn) close(btn.dataset.close);
    if (e.target.classList.contains('modal-overlay')) closeAll();
  });

  return { open, close, closeAll, create };
})();

/* ================================================================
   7. SSE (Server-Sent Events)
   ================================================================ */

const SSEManager = (() => {
  let source = null;
  let reconnectTimer = null;
  const handlers = {};

  function on(event, fn) {
    if (!handlers[event]) handlers[event] = [];
    handlers[event].push(fn);
  }

  function off(event, fn) {
    if (!handlers[event]) return;
    handlers[event] = handlers[event].filter(h => h !== fn);
  }

  function emit(event, data) {
    (handlers[event] || []).forEach(fn => fn(data));
  }

  function connect() {
    if (source) return;
    const token = localStorage.getItem(CONFIG.TOKEN_KEY);
    if (!token) return;

    source = new EventSource(`${CONFIG.API_BASE}/sse?token=${token}`);

    const events = [
      'connected', 'stats_updated', 'student_stats', 'unread_notifications',
      'hours_added', 'hours_updated', 'hours_deleted',
      'student_completed', 'student_created', 'student_updated',
      'certificate_requested', 'certificate_uploaded', 'certificate_request_updated',
      'zone_assigned', 'reconnect', 'error',
    ];

    events.forEach(ev => {
      source.addEventListener(ev, e => {
        try { emit(ev, JSON.parse(e.data)); } catch { emit(ev, e.data); }
      });
    });

    source.onerror = () => {
      source.close();
      source = null;
      reconnectTimer = setTimeout(connect, CONFIG.SSE_RECONNECT_DELAY);
    };

    source.addEventListener('reconnect', () => {
      source.close();
      source = null;
      setTimeout(connect, 500);
    });
  }

  function disconnect() {
    if (reconnectTimer) clearTimeout(reconnectTimer);
    if (source) { source.close(); source = null; }
  }

  return { connect, disconnect, on, off };
})();

/* ================================================================
   8. UTILIDADES
   ================================================================ */

const Utils = {
  formatDate(dateStr, options = {}) {
    if (!dateStr) return '—';
    const d = new Date(dateStr.includes('T') ? dateStr : dateStr + 'T00:00:00');
    return d.toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric', ...options });
  },

  formatDateTime(dateStr) {
    if (!dateStr) return '—';
    return new Date(dateStr).toLocaleString('es-CO', {
      day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });
  },

  formatHours(h) {
    const n = parseFloat(h) || 0;
    return `${n % 1 === 0 ? n : n.toFixed(1)} h`;
  },

  timeAgo(dateStr) {
    if (!dateStr) return '';
    const diff = Date.now() - new Date(dateStr).getTime();
    const m = Math.floor(diff / 60000);
    if (m < 1) return 'hace un momento';
    if (m < 60) return `hace ${m} min`;
    const hr = Math.floor(m / 60);
    if (hr < 24) return `hace ${hr} h`;
    const d = Math.floor(hr / 24);
    if (d < 30) return `hace ${d} día${d > 1 ? 's' : ''}`;
    return Utils.formatDate(dateStr);
  },

  statusBadge(status) {
    const map = {
      active:    ['badge-primary', 'Activo'],
      completed: ['badge-success', 'Completado'],
      suspended: ['badge-danger',  'Suspendido'],
      pending:   ['badge-warning', 'Pendiente'],
      approved:  ['badge-success', 'Aprobado'],
      rejected:  ['badge-danger',  'Rechazado'],
    };
    const [cls, label] = map[status] || ['badge-gray', status];
    return `<span class="badge ${cls}">${label}</span>`;
  },

  progressBar(completed, required, showLabel = true) {
    const pct = required > 0 ? Math.min(100, Math.round((completed / required) * 100)) : 0;
    const color = pct >= 100 ? 'success' : pct >= 60 ? '' : pct >= 30 ? 'warning' : 'danger';
    return `
      ${showLabel ? `<div class="progress-label"><span>${Utils.formatHours(completed)} / ${Utils.formatHours(required)}</span><span>${pct}%</span></div>` : ''}
      <div class="progress-wrap"><div class="progress-bar ${color}" style="width:${pct}%"></div></div>
    `;
  },

  avatarInitials(name = '') {
    return name.split(' ').slice(0, 2).map(w => w[0]).join('').toUpperCase();
  },

  debounce(fn, delay = 300) {
    let timer;
    return (...args) => { clearTimeout(timer); timer = setTimeout(() => fn(...args), delay); };
  },

  escapeHtml(str = '') {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  },

  /** Renderiza mensaje de tabla vacía */
  emptyRow(colspan, msg = 'No hay registros') {
    return `<tr><td colspan="${colspan}" class="table-empty"><div class="empty-icon">📋</div><p>${msg}</p></td></tr>`;
  },

  /** Parsea un form a objeto */
  formData(form) {
    return Object.fromEntries(new FormData(form).entries());
  },

  /** Establecer loading en botón */
  btnLoading(btn, loading = true) {
    if (!btn) return;
    if (loading) {
      btn.dataset.originalText = btn.innerHTML;
      btn.innerHTML = '<span class="spinner"></span> Procesando…';
      btn.disabled = true;
    } else {
      btn.innerHTML = btn.dataset.originalText || btn.innerHTML;
      btn.disabled = false;
    }
  },
};

/* ================================================================
   9. COMPONENTE: NOTIFICATIONS DROPDOWN
   ================================================================ */

const NotifDropdown = (() => {
  let unreadCount = 0;

  function render(container) {
    container.innerHTML = `
      <div class="relative">
        <button class="notif-btn" id="notif-toggle" aria-label="Notificaciones">
          🔔
          <span class="notif-badge hidden" id="notif-badge">0</span>
        </button>
        <div class="notif-dropdown" id="notif-dropdown">
          <div class="notif-header">
            <span class="font-medium">Notificaciones</span>
            <button class="btn btn-sm btn-ghost" id="notif-read-all">Marcar todas</button>
          </div>
          <div class="notif-list" id="notif-list">
            <div class="notif-empty">Cargando…</div>
          </div>
          <div class="notif-footer">
            <button class="btn btn-sm btn-link" id="notif-reload">Ver todas</button>
          </div>
        </div>
      </div>
    `;

    document.getElementById('notif-toggle').addEventListener('click', e => {
      e.stopPropagation();
      document.getElementById('notif-dropdown').classList.toggle('open');
      if (document.getElementById('notif-dropdown').classList.contains('open')) load();
    });

    document.getElementById('notif-read-all')?.addEventListener('click', markAll);
    document.getElementById('notif-reload')?.addEventListener('click', load);

    document.addEventListener('click', () => {
      document.getElementById('notif-dropdown')?.classList.remove('open');
    });

    SSEManager.on('unread_notifications', ({ count }) => setCount(count));
  }

  async function load() {
    const list = document.getElementById('notif-list');
    if (!list) return;
    try {
      const data = await Api.get('/notifications');
      setCount(data.unread_count);
      list.innerHTML = data.items.length
        ? data.items.map(n => `
            <div class="notif-item ${!n.read_at ? 'unread' : ''}" data-id="${n.id}">
              <span class="notif-dot ${n.read_at ? 'read' : ''}"></span>
              <div>
                <div class="notif-text">${Utils.escapeHtml(n.title)}</div>
                <div class="notif-time">${Utils.timeAgo(n.created_at)}</div>
              </div>
            </div>`).join('')
        : '<div class="notif-empty">No tienes notificaciones</div>';
    } catch {
      list.innerHTML = '<div class="notif-empty">Error al cargar</div>';
    }
  }

  async function markAll() {
    try {
      await Api.put('/notifications/read-all');
      setCount(0);
      load();
    } catch { /* ignore */ }
  }

  function setCount(n) {
    unreadCount = n;
    const badge = document.getElementById('notif-badge');
    if (!badge) return;
    badge.textContent = n > 99 ? '99+' : n;
    badge.classList.toggle('hidden', n === 0);
  }

  return { render, load, setCount };
})();

/* ================================================================
   10. VISTA: LANDING PAGE (index.html)
   ================================================================ */

const LandingView = (() => {
  function init() {
    initNavScroll();
    initReveal();
    initContactForm();
  }

  function initNavScroll() {
    const nav = document.querySelector('.landing-nav');
    if (!nav) return;
    window.addEventListener('scroll', () => {
      nav.classList.toggle('scrolled', window.scrollY > 40);
    });
  }

  function initReveal() {
    const els = document.querySelectorAll('.reveal');
    const obs = new IntersectionObserver((entries) => {
      entries.forEach(e => e.isIntersecting && e.target.classList.add('visible'));
    }, { threshold: 0.1 });
    els.forEach(el => obs.observe(el));
  }

  function initContactForm() {
    const form = document.getElementById('contact-form');
    if (!form) return;
    form.addEventListener('submit', async e => {
      e.preventDefault();
      Toast.success('Mensaje enviado', 'Nos pondremos en contacto pronto.');
      form.reset();
    });
  }

  return { init };
})();

/* ================================================================
   11. VISTA: AUTH (auth/index.html)
   ================================================================ */

const AuthView = (() => {
  function init() {
    if (Auth.isLoggedIn()) {
      return window.location.replace(Auth.isAdmin() ? '/admin/' : '/home/');
    }
    initTabs();
    initLoginForm();
    initRegisterForm();
    initPasswordToggle();
  }

  function initTabs() {
    document.querySelectorAll('.auth-tab').forEach(btn => {
      btn.addEventListener('click', () => {
        const target = btn.dataset.tab;
        document.querySelectorAll('.auth-tab').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.auth-form').forEach(f => f.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById(target)?.classList.add('active');
      });
    });
  }

  function initLoginForm() {
    const form = document.getElementById('login-form');
    if (!form) return;
    form.addEventListener('submit', async e => {
      e.preventDefault();
      const btn = form.querySelector('[type=submit]');
      Utils.btnLoading(btn);
      try {
        const user = await Auth.login(
          form.querySelector('[name=email]').value,
          form.querySelector('[name=password]').value,
        );
        Toast.success('¡Bienvenido!', user.full_name);
        setTimeout(() => window.location.replace(user.role === 'admin' ? '/admin/' : '/home/'), 600);
      } catch (err) {
        Toast.error('Error al iniciar sesión', err.message);
      } finally {
        Utils.btnLoading(btn, false);
      }
    });
  }

  function initRegisterForm() {
    const form = document.getElementById('register-form');
    if (!form) return;
    form.addEventListener('submit', async e => {
      e.preventDefault();
      const btn = form.querySelector('[type=submit]');

      const password  = form.querySelector('[name=password]').value;
      const password2 = form.querySelector('[name=password2]')?.value;
      if (password2 !== undefined && password !== password2) {
        return Toast.error('Las contraseñas no coinciden');
      }

      Utils.btnLoading(btn);
      try {
        const payload = Utils.formData(form);
        delete payload.password2;
        const user = await Auth.register(payload);
        Toast.success('Cuenta creada', `Bienvenido, ${user.full_name}`);
        setTimeout(() => window.location.replace('/home/'), 700);
      } catch (err) {
        Toast.error('Error al registrarse', err.message);
      } finally {
        Utils.btnLoading(btn, false);
      }
    });
  }

  function initPasswordToggle() {
    document.querySelectorAll('.password-eye').forEach(btn => {
      btn.addEventListener('click', () => {
        const input = btn.closest('.password-toggle').querySelector('input');
        const isHidden = input.type === 'password';
        input.type = isHidden ? 'text' : 'password';
        btn.textContent = isHidden ? '🙈' : '👁';
      });
    });
  }

  return { init };
})();

/* ================================================================
   12. VISTA: HOME ESTUDIANTE (home/index.html)
   ================================================================ */

const HomeView = (() => {
  let student = null;

  function init() {
    if (!Auth.isLoggedIn() || Auth.isAdmin()) {
      return window.location.replace(Auth.isLoggedIn() ? '/admin/' : '/auth/');
    }

    initSidebar();
    loadUser();
    initNavigation();
    SSEManager.connect();
    SSEManager.on('student_stats', updateStats);
    SSEManager.on('hours_added',   () => { loadHours(); loadProfile(); });
    SSEManager.on('zone_assigned', () => loadProfile());
    SSEManager.on('certificate_uploaded', () => loadCertificates());
    SSEManager.on('student_completed', () => { Toast.success('¡Felicitaciones!', 'Has completado tus horas de servicio social.'); loadProfile(); });

    const notifContainer = document.getElementById('notif-container');
    if (notifContainer) NotifDropdown.render(notifContainer);
  }

  function initSidebar() {
    const toggle = document.getElementById('sidebar-toggle');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');

    toggle?.addEventListener('click', () => {
      sidebar?.classList.toggle('open');
      overlay?.classList.toggle('active');
    });
    overlay?.addEventListener('click', () => {
      sidebar?.classList.remove('open');
      overlay?.classList.remove('active');
    });
  }

  function initNavigation() {
    document.querySelectorAll('[data-view]').forEach(btn => {
      btn.addEventListener('click', e => {
        e.preventDefault();
        const view = btn.dataset.view;
        showView(view);

        // Marcar activo
        document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));
        btn.closest('.nav-item')?.classList.add('active');

        // Cerrar sidebar en móvil
        document.getElementById('sidebar')?.classList.remove('open');
        document.getElementById('sidebar-overlay')?.classList.remove('active');
      });
    });
  }

  function showView(name) {
    document.querySelectorAll('.view-section').forEach(s => s.classList.add('hidden'));
    document.getElementById(`view-${name}`)?.classList.remove('hidden');
    const titles = {
      dashboard:    'Panel de Control',
      hours:        'Mis Horas',
      zones:        'Mi Zona',
      certificates: 'Certificados',
      profile:      'Mi Perfil',
    };
    document.getElementById('topbar-title').textContent = titles[name] || name;

    const loaders = {
      dashboard:    loadProfile,
      hours:        loadHours,
      zones:        loadZone,
      certificates: loadCertificates,
      profile:      loadProfile,
    };
    loaders[name]?.();
  }

  async function loadUser() {
    try {
      student = await Api.get('/students/me');
      renderSidebarUser();
      loadProfile();
    } catch {
      Toast.error('Error al cargar perfil');
    }
  }

  function renderSidebarUser() {
    if (!student) return;
    const user = Auth.getUser();
    const name = document.getElementById('sidebar-user-name');
    const avatar = document.getElementById('sidebar-avatar');
    if (name) name.textContent = user?.name || student.full_name;
    if (avatar) avatar.textContent = Utils.avatarInitials(user?.name || student.full_name);
  }

  function updateStats(data) {
    if (!data) return;
    student = { ...student, ...data };
    loadProfile();
  }

  // ── Dashboard / Profile ──────────────────────────────────────

  async function loadProfile() {
    try {
      student = await Api.get('/students/me');
      renderDashboard();
    } catch { /* silencioso */ }
  }

  function renderDashboard() {
    if (!student) return;

    const completedEl  = document.getElementById('stat-completed');
    const requiredEl   = document.getElementById('stat-required');
    const remainingEl  = document.getElementById('stat-remaining');
    const pctEl        = document.getElementById('stat-pct');
    const progressEl   = document.getElementById('main-progress');
    const statusEl     = document.getElementById('student-status');

    if (completedEl)  completedEl.textContent  = Utils.formatHours(student.completed_hours);
    if (requiredEl)   requiredEl.textContent   = Utils.formatHours(student.required_hours);
    if (remainingEl)  remainingEl.textContent  = Utils.formatHours(student.remaining_hours);
    if (pctEl)        pctEl.textContent        = `${student.completion_pct}%`;
    if (progressEl)   progressEl.innerHTML     = Utils.progressBar(student.completed_hours, student.required_hours);
    if (statusEl)     statusEl.innerHTML       = Utils.statusBadge(student.status);

    // Zona actual
    const zoneEl = document.getElementById('current-zone-info');
    if (zoneEl && student.current_zone) {
      zoneEl.innerHTML = `
        <div class="zone-card">
          <div class="zone-header">
            <div class="zone-info">
              <h4>${Utils.escapeHtml(student.current_zone.zone_name)}</h4>
              <p>📍 ${Utils.escapeHtml(student.current_zone.address)}</p>
              <p>👤 ${Utils.escapeHtml(student.current_zone.supervisor)}</p>
            </div>
            ${Utils.statusBadge('active')}
          </div>
          <p class="text-sm text-secondary">${Utils.escapeHtml(student.current_zone.description || '')}</p>
        </div>
      `;
    } else if (zoneEl) {
      zoneEl.innerHTML = '<p class="text-muted text-sm">No tienes zona asignada aún.</p>';
    }
  }

  // ── Horas ────────────────────────────────────────────────────

  async function loadHours() {
    const tbody = document.getElementById('hours-tbody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="4" class="table-empty"><span class="spinner"></span></td></tr>';
    try {
      const hours = await Api.get('/hours');
      tbody.innerHTML = hours.length
        ? hours.map(h => `
            <tr>
              <td>${Utils.formatDate(h.service_date)}</td>
              <td><strong>${Utils.formatHours(h.hours)}</strong></td>
              <td>${Utils.escapeHtml(h.observation || '—')}</td>
              <td><span class="text-muted text-xs">${Utils.escapeHtml(h.admin_name)}</span></td>
            </tr>`).join('')
        : Utils.emptyRow(4, 'No tienes horas registradas todavía');
    } catch {
      tbody.innerHTML = Utils.emptyRow(4, 'Error al cargar horas');
    }
  }

  // ── Zona ─────────────────────────────────────────────────────

  async function loadZone() {
    const container = document.getElementById('zone-history');
    if (!container) return;
    container.innerHTML = '<span class="spinner"></span>';
    try {
      const data = await Api.get(`/zones/student/${student.student_id}/history`);
      container.innerHTML = data.length
        ? data.map(z => `
          <div class="zone-card mb-4">
            <div class="zone-header">
              <div class="zone-info">
                <h5>${Utils.escapeHtml(z.zone_name)}</h5>
                <p>📍 ${Utils.escapeHtml(z.address)}</p>
                <p>👤 ${Utils.escapeHtml(z.supervisor)}</p>
              </div>
              ${z.is_current ? '<span class="badge badge-success">Actual</span>' : '<span class="badge badge-gray">Anterior</span>'}
            </div>
            ${z.notes ? `<p class="text-sm text-secondary mt-2">${Utils.escapeHtml(z.notes)}</p>` : ''}
            <p class="text-xs text-muted mt-2">Asignado ${Utils.formatDate(z.assigned_at)} por ${Utils.escapeHtml(z.assigned_by_name)}</p>
          </div>`).join('')
        : '<p class="text-muted text-sm">No tienes historial de zonas.</p>';
    } catch {
      container.innerHTML = '<p class="text-danger text-sm">Error al cargar zonas.</p>';
    }
  }

  // ── Certificados ─────────────────────────────────────────────

  async function loadCertificates() {
    const container = document.getElementById('cert-container');
    if (!container) return;
    container.innerHTML = '<span class="spinner"></span>';
    try {
      const [certs, requests] = await Promise.all([
        Api.get('/certificates'),
        Api.get('/certificates/requests'),
      ]);

      const cert       = certs[0];
      const pending    = requests.find(r => r.status === 'pending');
      const studentNow = await Api.get('/students/me');
      const canRequest = studentNow.status === 'completed' && !cert && !pending;

      container.innerHTML = `
        <div class="cert-card ${cert ? 'available' : pending ? 'pending' : ''}">
          <div class="cert-icon">${cert ? '🏅' : pending ? '⏳' : '📄'}</div>
          <h4>${cert ? 'Certificado disponible' : pending ? 'Solicitud pendiente' : 'Sin certificado'}</h4>
          <p class="text-sm mt-2">
            ${cert
              ? 'Tu certificado ya está listo para descargar.'
              : pending
              ? 'Tu solicitud está siendo revisada por el administrador.'
              : studentNow.status === 'completed'
              ? 'Has completado tus horas. Ya puedes solicitar tu certificado.'
              : `Necesitas ${Utils.formatHours(studentNow.remaining_hours)} más para poder solicitarlo.`}
          </p>
          <div class="mt-4 flex gap-2 justify-center flex-wrap">
            ${cert    ? `<a href="/${cert.file_path}" class="btn btn-success" target="_blank" download>⬇ Descargar certificado</a>` : ''}
            ${canRequest ? `<button class="btn btn-primary" id="btn-request-cert">Solicitar certificado</button>` : ''}
          </div>
        </div>
        ${requests.length ? `
          <h5 class="mt-6 mb-2">Historial de solicitudes</h5>
          <div class="table-wrapper">
            <table class="table">
              <thead><tr><th>Fecha</th><th>Estado</th><th>Notas</th></tr></thead>
              <tbody>
                ${requests.map(r => `
                  <tr>
                    <td>${Utils.formatDateTime(r.requested_at)}</td>
                    <td>${Utils.statusBadge(r.status)}</td>
                    <td>${Utils.escapeHtml(r.notes || '—')}</td>
                  </tr>`).join('')}
              </tbody>
            </table>
          </div>` : ''}
      `;

      document.getElementById('btn-request-cert')?.addEventListener('click', requestCertificate);
    } catch {
      container.innerHTML = '<p class="text-danger text-sm">Error al cargar certificados.</p>';
    }
  }

  async function requestCertificate() {
    const btn = document.getElementById('btn-request-cert');
    Utils.btnLoading(btn);
    try {
      await Api.post('/certificates/request');
      Toast.success('Solicitud enviada', 'El administrador revisará tu solicitud.');
      loadCertificates();
    } catch (err) {
      Toast.error('No se pudo enviar', err.message);
    } finally {
      Utils.btnLoading(btn, false);
    }
  }

  return { init };
})();

/* ================================================================
   13. VISTA: ADMIN (admin/index.html)
   ================================================================ */

const AdminView = (() => {
  let currentView = 'dashboard';

  function init() {
    if (!Auth.isLoggedIn() || !Auth.isAdmin()) {
      return window.location.replace(Auth.isLoggedIn() ? '/home/' : '/auth/');
    }

    initSidebar();
    initNavigation();
    loadDashboard();

    const notifContainer = document.getElementById('notif-container');
    if (notifContainer) NotifDropdown.render(notifContainer);

    SSEManager.connect();
    SSEManager.on('stats_updated', updateDashboardStats);
    SSEManager.on('certificate_requested', () => {
      Toast.info('Nueva solicitud de certificado');
      if (currentView === 'certificates') loadCertificateRequests();
    });
    SSEManager.on('student_completed', ({ student_id }) => {
      Toast.success('Estudiante completó horas', `ID: ${student_id}`);
      if (currentView === 'students') loadStudents();
    });

    // Logout
    document.getElementById('btn-logout')?.addEventListener('click', () => Auth.logout());
  }

  function initSidebar() {
    const toggle  = document.getElementById('sidebar-toggle');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    toggle?.addEventListener('click', () => { sidebar?.classList.toggle('open'); overlay?.classList.toggle('active'); });
    overlay?.addEventListener('click', () => { sidebar?.classList.remove('open'); overlay?.classList.remove('active'); });

    const user = Auth.getUser();
    const nameEl   = document.getElementById('sidebar-user-name');
    const avatarEl = document.getElementById('sidebar-avatar');
    if (nameEl)   nameEl.textContent   = user?.name || 'Administrador';
    if (avatarEl) avatarEl.textContent = Utils.avatarInitials(user?.name || 'Admin');
  }

  function initNavigation() {
    document.querySelectorAll('[data-view]').forEach(btn => {
      btn.addEventListener('click', e => {
        e.preventDefault();
        const view = btn.dataset.view;
        currentView = view;
        showView(view);
        document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));
        btn.closest('.nav-item')?.classList.add('active');
        document.getElementById('sidebar')?.classList.remove('open');
        document.getElementById('sidebar-overlay')?.classList.remove('active');
      });
    });
  }

  function showView(name) {
    document.querySelectorAll('.view-section').forEach(s => s.classList.add('hidden'));
    document.getElementById(`view-${name}`)?.classList.remove('hidden');
    const titles = {
      dashboard:    'Panel de Control',
      students:     'Estudiantes',
      hours:        'Horas de Servicio',
      zones:        'Zonas',
      certificates: 'Certificados',
      audit:        'Auditoría',
    };
    document.getElementById('topbar-title').textContent = titles[name] || name;

    const loaders = {
      dashboard:    loadDashboard,
      students:     loadStudents,
      hours:        loadHoursAdmin,
      zones:        loadZones,
      certificates: loadCertificateRequests,
      audit:        loadAuditLogs,
    };
    loaders[name]?.();
  }

  // ── Dashboard ────────────────────────────────────────────────

  async function loadDashboard() {
    try {
      const [students, hours, requests] = await Promise.all([
        Api.get('/students'),
        Api.get('/hours'),
        Api.get('/certificates/requests'),
      ]);

      const totalStudents     = students.length;
      const activeStudents    = students.filter(s => s.status === 'active').length;
      const completedStudents = students.filter(s => s.status === 'completed').length;
      const totalHours        = hours.reduce((a, h) => a + parseFloat(h.hours), 0);
      const pendingCerts      = requests.filter(r => r.status === 'pending').length;

      setStatEl('stat-total',     totalStudents);
      setStatEl('stat-active',    activeStudents);
      setStatEl('stat-completed', completedStudents);
      setStatEl('stat-hours',     Utils.formatHours(totalHours));
      setStatEl('stat-certs',     pendingCerts);

      renderRecentStudents(students.slice(0, 5));
    } catch { /* silencioso */ }
  }

  function setStatEl(id, val) {
    const el = document.getElementById(id);
    if (el) el.textContent = val;
  }

  function updateDashboardStats(data) {
    if (!data) return;
    setStatEl('stat-total',     data.total_students);
    setStatEl('stat-active',    data.active_students);
    setStatEl('stat-completed', data.completed_students);
    setStatEl('stat-hours',     Utils.formatHours(data.total_hours));
    setStatEl('stat-certs',     data.pending_certificates);
  }

  function renderRecentStudents(students) {
    const tbody = document.getElementById('recent-students');
    if (!tbody) return;
    tbody.innerHTML = students.length
      ? students.map(s => `
          <tr>
            <td>
              <div class="flex items-center gap-2">
                <div class="avatar">${Utils.avatarInitials(s.full_name)}</div>
                <div>
                  <div class="font-medium">${Utils.escapeHtml(s.full_name)}</div>
                  <div class="text-xs text-muted">${Utils.escapeHtml(s.enrollment_code)}</div>
                </div>
              </div>
            </td>
            <td>${Utils.escapeHtml(s.career)}</td>
            <td>${Utils.progressBar(s.completed_hours, s.required_hours, true)}</td>
            <td>${Utils.statusBadge(s.status)}</td>
          </tr>`).join('')
      : Utils.emptyRow(4, 'No hay estudiantes registrados');
  }

  // ── Estudiantes ──────────────────────────────────────────────

  let studentsData = [];

  async function loadStudents() {
    const tbody = document.getElementById('students-tbody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="7" class="table-empty"><span class="spinner"></span></td></tr>';
    try {
      studentsData = await Api.get('/students');
      renderStudentsTable(studentsData);
    } catch {
      tbody.innerHTML = Utils.emptyRow(7, 'Error al cargar estudiantes');
    }

    initStudentSearch();
    initStudentFilters();
    document.getElementById('btn-new-student')?.addEventListener('click', openNewStudentModal);
  }

  function renderStudentsTable(data) {
    const tbody = document.getElementById('students-tbody');
    if (!tbody) return;
    tbody.innerHTML = data.length
      ? data.map(s => `
          <tr>
            <td>
              <div class="flex items-center gap-2">
                <div class="avatar">${Utils.avatarInitials(s.full_name)}</div>
                <div>
                  <div class="font-medium">${Utils.escapeHtml(s.full_name)}</div>
                  <div class="text-xs text-muted">${Utils.escapeHtml(s.email)}</div>
                </div>
              </div>
            </td>
            <td><span class="chip">${Utils.escapeHtml(s.enrollment_code)}</span></td>
            <td>${Utils.escapeHtml(s.career)}</td>
            <td>${Utils.progressBar(s.completed_hours, s.required_hours)}</td>
            <td>${s.zone_name ? `<span class="chip">📍 ${Utils.escapeHtml(s.zone_name)}</span>` : '<span class="text-muted text-xs">Sin zona</span>'}</td>
            <td>${Utils.statusBadge(s.status)}</td>
            <td>
              <div class="flex gap-1">
                <button class="btn btn-sm btn-ghost" data-action="view-student" data-id="${s.student_id}" title="Ver">👁</button>
                <button class="btn btn-sm btn-ghost" data-action="add-hours"    data-id="${s.student_id}" data-name="${Utils.escapeHtml(s.full_name)}" title="Agregar horas">+h</button>
                <button class="btn btn-sm btn-ghost" data-action="assign-zone"  data-id="${s.student_id}" data-name="${Utils.escapeHtml(s.full_name)}" title="Asignar zona">📍</button>
                <button class="btn btn-sm btn-danger" data-action="delete-student" data-id="${s.student_id}" data-name="${Utils.escapeHtml(s.full_name)}" title="Eliminar">🗑</button>
              </div>
            </td>
          </tr>`).join('')
      : Utils.emptyRow(7, 'No hay estudiantes registrados');

    // Eventos de la tabla
    tbody.querySelectorAll('[data-action]').forEach(btn => {
      btn.addEventListener('click', handleStudentAction);
    });
  }

  function handleStudentAction(e) {
    const btn    = e.currentTarget;
    const action = btn.dataset.action;
    const id     = parseInt(btn.dataset.id);
    const name   = btn.dataset.name;

    if (action === 'view-student')   openStudentDetailModal(id);
    if (action === 'add-hours')      openAddHoursModal(id, name);
    if (action === 'assign-zone')    openAssignZoneModal(id, name);
    if (action === 'delete-student') confirmDeleteStudent(id, name);
  }

  function initStudentSearch() {
    const input = document.getElementById('student-search');
    if (!input) return;
    input.addEventListener('input', Utils.debounce(() => {
      const q = input.value.toLowerCase();
      const filtered = studentsData.filter(s =>
        s.full_name.toLowerCase().includes(q) ||
        s.email.toLowerCase().includes(q) ||
        s.enrollment_code.toLowerCase().includes(q) ||
        s.career.toLowerCase().includes(q)
      );
      renderStudentsTable(filtered);
    }));
  }

  function initStudentFilters() {
    const select = document.getElementById('student-status-filter');
    if (!select) return;
    select.addEventListener('change', () => {
      const val = select.value;
      const filtered = val ? studentsData.filter(s => s.status === val) : studentsData;
      renderStudentsTable(filtered);
    });
  }

  async function openStudentDetailModal(studentId) {
    try {
      const s = await Api.get(`/students/${studentId}`);
      const hours = await Api.get('/hours', { student_id: studentId });
      Modal.create({
        id: 'modal-student-detail',
        title: s.full_name,
        size: 'lg',
        body: `
          <div class="grid-2 mb-4">
            <div><strong>Email:</strong><br>${Utils.escapeHtml(s.email)}</div>
            <div><strong>Matrícula:</strong><br><span class="chip">${Utils.escapeHtml(s.enrollment_code)}</span></div>
            <div><strong>Carrera:</strong><br>${Utils.escapeHtml(s.career)}</div>
            <div><strong>Semestre:</strong><br>${s.semester}</div>
            <div><strong>Estado:</strong><br>${Utils.statusBadge(s.status)}</div>
            <div><strong>Inicio:</strong><br>${Utils.formatDate(s.start_date)}</div>
          </div>
          <h5 class="mb-2">Progreso de horas</h5>
          ${Utils.progressBar(s.completed_hours, s.required_hours)}
          ${s.current_zone ? `
            <h5 class="mt-4 mb-2">Zona actual</h5>
            <p>📍 ${Utils.escapeHtml(s.current_zone.zone_name)} — ${Utils.escapeHtml(s.current_zone.supervisor)}</p>` : ''}
          <h5 class="mt-4 mb-2">Últimas horas (${hours.length})</h5>
          <div class="table-wrapper">
            <table class="table">
              <thead><tr><th>Fecha</th><th>Horas</th><th>Observación</th></tr></thead>
              <tbody>
                ${hours.slice(0,8).map(h => `
                  <tr>
                    <td>${Utils.formatDate(h.service_date)}</td>
                    <td>${Utils.formatHours(h.hours)}</td>
                    <td>${Utils.escapeHtml(h.observation || '—')}</td>
                  </tr>`).join('') || Utils.emptyRow(3, 'Sin horas registradas')}
              </tbody>
            </table>
          </div>
        `,
      });
      Modal.open('modal-student-detail');
    } catch (err) {
      Toast.error('Error al cargar estudiante', err.message);
    }
  }

  function openNewStudentModal() {
    Modal.create({
      id: 'modal-new-student',
      title: 'Nuevo Estudiante',
      body: `
        <form id="form-new-student">
          <div class="grid-2">
            <div class="form-group"><label class="form-label">Nombre completo *</label><input name="full_name" class="form-control" required></div>
            <div class="form-group"><label class="form-label">Email *</label><input name="email" type="email" class="form-control" required></div>
            <div class="form-group"><label class="form-label">Contraseña *</label><input name="password" type="password" class="form-control" required minlength="6"></div>
            <div class="form-group"><label class="form-label">Código de matrícula *</label><input name="enrollment_code" class="form-control" required></div>
            <div class="form-group"><label class="form-label">Carrera *</label><input name="career" class="form-control" required></div>
            <div class="form-group"><label class="form-label">Semestre</label><input name="semester" type="number" class="form-control" value="1" min="1" max="10"></div>
            <div class="form-group"><label class="form-label">Horas requeridas</label><input name="required_hours" type="number" class="form-control" value="480" min="1"></div>
            <div class="form-group"><label class="form-label">Fecha inicio</label><input name="start_date" type="date" class="form-control"></div>
          </div>
        </form>
      `,
      footer: `
        <button class="btn btn-secondary" data-close="modal-new-student">Cancelar</button>
        <button class="btn btn-primary" id="btn-save-student">Guardar</button>
      `,
    });
    Modal.open('modal-new-student');

    document.getElementById('btn-save-student').addEventListener('click', async () => {
      const form = document.getElementById('form-new-student');
      if (!form.checkValidity()) return form.reportValidity();
      const btn = document.getElementById('btn-save-student');
      Utils.btnLoading(btn);
      try {
        await Api.post('/students', Utils.formData(form));
        Toast.success('Estudiante creado');
        Modal.close('modal-new-student');
        loadStudents();
      } catch (err) {
        Toast.error('Error', err.message);
      } finally {
        Utils.btnLoading(btn, false);
      }
    });
  }

  async function confirmDeleteStudent(id, name) {
    if (!confirm(`¿Eliminar a "${name}"? Esta acción no se puede deshacer.`)) return;
    try {
      await Api.delete(`/students/${id}`);
      Toast.success('Estudiante eliminado');
      loadStudents();
    } catch (err) {
      Toast.error('Error al eliminar', err.message);
    }
  }

  // ── Horas (admin) ────────────────────────────────────────────

  async function loadHoursAdmin() {
    const tbody = document.getElementById('hours-admin-tbody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="6" class="table-empty"><span class="spinner"></span></td></tr>';
    try {
      const hours = await Api.get('/hours');
      tbody.innerHTML = hours.length
        ? hours.map(h => `
            <tr>
              <td>
                <div class="font-medium">${Utils.escapeHtml(h.student_name)}</div>
                <div class="text-xs text-muted">${Utils.escapeHtml(h.enrollment_code)}</div>
              </td>
              <td>${Utils.formatDate(h.service_date)}</td>
              <td><strong>${Utils.formatHours(h.hours)}</strong></td>
              <td>${Utils.escapeHtml(h.observation || '—')}</td>
              <td><span class="text-xs text-muted">${Utils.escapeHtml(h.admin_name)}</span></td>
              <td>
                <div class="flex gap-1">
                  <button class="btn btn-sm btn-ghost" data-action="edit-hours" data-id="${h.id}" data-hours='${JSON.stringify(h)}'>✏</button>
                  <button class="btn btn-sm btn-danger" data-action="delete-hours" data-id="${h.id}">🗑</button>
                </div>
              </td>
            </tr>`).join('')
        : Utils.emptyRow(6, 'No hay horas registradas');

      tbody.querySelectorAll('[data-action]').forEach(btn => {
        btn.addEventListener('click', handleHoursAction);
      });
    } catch {
      tbody.innerHTML = Utils.emptyRow(6, 'Error al cargar horas');
    }

    document.getElementById('btn-new-hours')?.addEventListener('click', openNewHoursModal);
  }

  function handleHoursAction(e) {
    const btn = e.currentTarget;
    if (btn.dataset.action === 'edit-hours') {
      openEditHoursModal(JSON.parse(btn.dataset.hours));
    }
    if (btn.dataset.action === 'delete-hours') {
      confirmDeleteHours(parseInt(btn.dataset.id));
    }
  }

  async function openNewHoursModal() {
    let students = [];
    try { students = await Api.get('/students'); } catch { /* ignore */ }
    Modal.create({
      id: 'modal-new-hours',
      title: 'Registrar Horas',
      body: `
        <form id="form-new-hours">
          <div class="form-group">
            <label class="form-label">Estudiante *</label>
            <select name="student_id" class="form-control" required>
              <option value="">Seleccionar estudiante…</option>
              ${students.map(s => `<option value="${s.student_id}">${Utils.escapeHtml(s.full_name)} (${s.enrollment_code})</option>`).join('')}
            </select>
          </div>
          <div class="grid-2">
            <div class="form-group"><label class="form-label">Horas *</label><input name="hours" type="number" class="form-control" step="0.5" min="0.5" max="24" required></div>
            <div class="form-group"><label class="form-label">Fecha *</label><input name="service_date" type="date" class="form-control" required value="${new Date().toISOString().split('T')[0]}"></div>
          </div>
          <div class="form-group"><label class="form-label">Observación</label><textarea name="observation" class="form-control" rows="3"></textarea></div>
        </form>
      `,
      footer: `
        <button class="btn btn-secondary" data-close="modal-new-hours">Cancelar</button>
        <button class="btn btn-primary" id="btn-save-hours">Guardar</button>
      `,
    });
    Modal.open('modal-new-hours');
    document.getElementById('btn-save-hours').addEventListener('click', async () => {
      const form = document.getElementById('form-new-hours');
      if (!form.checkValidity()) return form.reportValidity();
      const btn = document.getElementById('btn-save-hours');
      Utils.btnLoading(btn);
      try {
        await Api.post('/hours', Utils.formData(form));
        Toast.success('Horas registradas');
        Modal.close('modal-new-hours');
        loadHoursAdmin();
      } catch (err) {
        Toast.error('Error', err.message);
      } finally {
        Utils.btnLoading(btn, false);
      }
    });
  }

  function openEditHoursModal(h) {
    Modal.create({
      id: 'modal-edit-hours',
      title: 'Editar Horas',
      body: `
        <form id="form-edit-hours">
          <div class="grid-2">
            <div class="form-group"><label class="form-label">Horas</label><input name="hours" type="number" class="form-control" step="0.5" min="0.5" max="24" value="${h.hours}"></div>
            <div class="form-group"><label class="form-label">Fecha</label><input name="service_date" type="date" class="form-control" value="${h.service_date}"></div>
          </div>
          <div class="form-group"><label class="form-label">Observación</label><textarea name="observation" class="form-control" rows="3">${Utils.escapeHtml(h.observation || '')}</textarea></div>
        </form>
      `,
      footer: `
        <button class="btn btn-secondary" data-close="modal-edit-hours">Cancelar</button>
        <button class="btn btn-primary" id="btn-update-hours">Actualizar</button>
      `,
    });
    Modal.open('modal-edit-hours');
    document.getElementById('btn-update-hours').addEventListener('click', async () => {
      const btn = document.getElementById('btn-update-hours');
      Utils.btnLoading(btn);
      try {
        await Api.put(`/hours/${h.id}`, Utils.formData(document.getElementById('form-edit-hours')));
        Toast.success('Horas actualizadas');
        Modal.close('modal-edit-hours');
        loadHoursAdmin();
      } catch (err) {
        Toast.error('Error', err.message);
      } finally {
        Utils.btnLoading(btn, false);
      }
    });
  }

  async function confirmDeleteHours(id) {
    if (!confirm('¿Eliminar este registro de horas?')) return;
    try {
      await Api.delete(`/hours/${id}`);
      Toast.success('Registro eliminado');
      loadHoursAdmin();
    } catch (err) {
      Toast.error('Error', err.message);
    }
  }

  // ── Zonas ────────────────────────────────────────────────────

  let zonesData = [];

  async function loadZones() {
    const container = document.getElementById('zones-container');
    if (!container) return;
    container.innerHTML = '<span class="spinner"></span>';
    try {
      zonesData = await Api.get('/zones', { all: '1' });
      container.innerHTML = `
        <div class="grid-3">
          ${zonesData.map(z => `
            <div class="zone-card">
              <div class="zone-header">
                <div class="zone-info">
                  <h5>${Utils.escapeHtml(z.name)}</h5>
                  <p>👤 ${Utils.escapeHtml(z.supervisor)}</p>
                  <p>📍 ${Utils.escapeHtml(z.address)}</p>
                </div>
                <span class="badge ${z.active ? 'badge-success' : 'badge-gray'}">${z.active ? 'Activa' : 'Inactiva'}</span>
              </div>
              <p class="text-sm text-secondary mt-2">${Utils.escapeHtml(z.description || '')}</p>
              <div class="flex gap-2 mt-3">
                <button class="btn btn-sm btn-ghost" data-action="edit-zone" data-zone='${JSON.stringify(z)}'>✏ Editar</button>
              </div>
            </div>`).join('')}
        </div>
      `;
      container.querySelectorAll('[data-action="edit-zone"]').forEach(btn => {
        btn.addEventListener('click', () => openEditZoneModal(JSON.parse(btn.dataset.zone)));
      });
    } catch {
      container.innerHTML = '<p class="text-danger">Error al cargar zonas.</p>';
    }
    document.getElementById('btn-new-zone')?.addEventListener('click', openNewZoneModal);
  }

  function openNewZoneModal() {
    Modal.create({
      id: 'modal-new-zone',
      title: 'Nueva Zona',
      body: `
        <form id="form-new-zone">
          <div class="form-group"><label class="form-label">Nombre *</label><input name="name" class="form-control" required></div>
          <div class="form-group"><label class="form-label">Supervisor</label><input name="supervisor" class="form-control"></div>
          <div class="form-group"><label class="form-label">Dirección</label><input name="address" class="form-control"></div>
          <div class="form-group"><label class="form-label">Descripción</label><textarea name="description" class="form-control" rows="3"></textarea></div>
        </form>
      `,
      footer: `
        <button class="btn btn-secondary" data-close="modal-new-zone">Cancelar</button>
        <button class="btn btn-primary" id="btn-save-zone">Guardar</button>
      `,
    });
    Modal.open('modal-new-zone');
    document.getElementById('btn-save-zone').addEventListener('click', async () => {
      const form = document.getElementById('form-new-zone');
      if (!form.checkValidity()) return form.reportValidity();
      const btn = document.getElementById('btn-save-zone');
      Utils.btnLoading(btn);
      try {
        await Api.post('/zones', Utils.formData(form));
        Toast.success('Zona creada');
        Modal.close('modal-new-zone');
        loadZones();
      } catch (err) {
        Toast.error('Error', err.message);
      } finally {
        Utils.btnLoading(btn, false);
      }
    });
  }

  function openEditZoneModal(z) {
    Modal.create({
      id: 'modal-edit-zone',
      title: 'Editar Zona',
      body: `
        <form id="form-edit-zone">
          <div class="form-group"><label class="form-label">Nombre *</label><input name="name" class="form-control" value="${Utils.escapeHtml(z.name)}" required></div>
          <div class="form-group"><label class="form-label">Supervisor</label><input name="supervisor" class="form-control" value="${Utils.escapeHtml(z.supervisor)}"></div>
          <div class="form-group"><label class="form-label">Dirección</label><input name="address" class="form-control" value="${Utils.escapeHtml(z.address)}"></div>
          <div class="form-group"><label class="form-label">Descripción</label><textarea name="description" class="form-control" rows="3">${Utils.escapeHtml(z.description || '')}</textarea></div>
          <div class="form-group">
            <label class="form-label">Estado</label>
            <select name="active" class="form-control">
              <option value="1" ${z.active ? 'selected' : ''}>Activa</option>
              <option value="0" ${!z.active ? 'selected' : ''}>Inactiva</option>
            </select>
          </div>
        </form>
      `,
      footer: `
        <button class="btn btn-secondary" data-close="modal-edit-zone">Cancelar</button>
        <button class="btn btn-primary" id="btn-update-zone">Actualizar</button>
      `,
    });
    Modal.open('modal-edit-zone');
    document.getElementById('btn-update-zone').addEventListener('click', async () => {
      const btn = document.getElementById('btn-update-zone');
      Utils.btnLoading(btn);
      try {
        await Api.put(`/zones/${z.id}`, Utils.formData(document.getElementById('form-edit-zone')));
        Toast.success('Zona actualizada');
        Modal.close('modal-edit-zone');
        loadZones();
      } catch (err) {
        Toast.error('Error', err.message);
      } finally {
        Utils.btnLoading(btn, false);
      }
    });
  }

  async function openAddHoursModal(studentId, studentName) {
    Modal.create({
      id: 'modal-add-hours-s',
      title: `Agregar horas — ${studentName}`,
      body: `
        <form id="form-add-hours-s">
          <div class="grid-2">
            <div class="form-group"><label class="form-label">Horas *</label><input name="hours" type="number" class="form-control" step="0.5" min="0.5" max="24" required></div>
            <div class="form-group"><label class="form-label">Fecha *</label><input name="service_date" type="date" class="form-control" required value="${new Date().toISOString().split('T')[0]}"></div>
          </div>
          <div class="form-group"><label class="form-label">Observación</label><textarea name="observation" class="form-control" rows="3"></textarea></div>
        </form>
      `,
      footer: `
        <button class="btn btn-secondary" data-close="modal-add-hours-s">Cancelar</button>
        <button class="btn btn-primary" id="btn-save-hours-s">Guardar</button>
      `,
    });
    Modal.open('modal-add-hours-s');
    document.getElementById('btn-save-hours-s').addEventListener('click', async () => {
      const form = document.getElementById('form-add-hours-s');
      if (!form.checkValidity()) return form.reportValidity();
      const btn = document.getElementById('btn-save-hours-s');
      Utils.btnLoading(btn);
      try {
        const data = Utils.formData(form);
        data.student_id = studentId;
        await Api.post('/hours', data);
        Toast.success('Horas registradas');
        Modal.close('modal-add-hours-s');
        loadStudents();
      } catch (err) {
        Toast.error('Error', err.message);
      } finally {
        Utils.btnLoading(btn, false);
      }
    });
  }

  async function openAssignZoneModal(studentId, studentName) {
    let zones = [];
    try { zones = await Api.get('/zones'); } catch { /* ignore */ }
    Modal.create({
      id: 'modal-assign-zone',
      title: `Asignar zona — ${studentName}`,
      body: `
        <form id="form-assign-zone">
          <div class="form-group">
            <label class="form-label">Zona *</label>
            <select name="zone_id" class="form-control" required>
              <option value="">Seleccionar zona…</option>
              ${zones.map(z => `<option value="${z.id}">${Utils.escapeHtml(z.name)}</option>`).join('')}
            </select>
          </div>
          <div class="form-group"><label class="form-label">Notas</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
        </form>
      `,
      footer: `
        <button class="btn btn-secondary" data-close="modal-assign-zone">Cancelar</button>
        <button class="btn btn-primary" id="btn-save-zone-assign">Asignar</button>
      `,
    });
    Modal.open('modal-assign-zone');
    document.getElementById('btn-save-zone-assign').addEventListener('click', async () => {
      const form = document.getElementById('form-assign-zone');
      if (!form.checkValidity()) return form.reportValidity();
      const btn = document.getElementById('btn-save-zone-assign');
      Utils.btnLoading(btn);
      try {
        const data = Utils.formData(form);
        data.student_id = studentId;
        await Api.post('/zones/assign', data);
        Toast.success('Zona asignada');
        Modal.close('modal-assign-zone');
        loadStudents();
      } catch (err) {
        Toast.error('Error', err.message);
      } finally {
        Utils.btnLoading(btn, false);
      }
    });
  }

  // ── Certificados ─────────────────────────────────────────────

  async function loadCertificateRequests() {
    const tbody = document.getElementById('cert-requests-tbody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="6" class="table-empty"><span class="spinner"></span></td></tr>';
    try {
      const requests = await Api.get('/certificates/requests');
      tbody.innerHTML = requests.length
        ? requests.map(r => `
            <tr>
              <td>
                <div class="font-medium">${Utils.escapeHtml(r.student_name)}</div>
                <div class="text-xs text-muted">${Utils.escapeHtml(r.enrollment_code)}</div>
              </td>
              <td>${Utils.formatDateTime(r.requested_at)}</td>
              <td>${Utils.statusBadge(r.status)}</td>
              <td>${r.completed_hours}/${r.required_hours} h</td>
              <td>${Utils.escapeHtml(r.notes || '—')}</td>
              <td>
                ${r.status === 'pending' ? `
                  <div class="flex gap-1">
                    <button class="btn btn-sm btn-success" data-action="approve-cert" data-id="${r.id}">✓ Aprobar</button>
                    <button class="btn btn-sm btn-danger"  data-action="reject-cert"  data-id="${r.id}">✕ Rechazar</button>
                    <button class="btn btn-sm btn-primary" data-action="upload-cert"  data-id="${r.id}" data-sid="${r.student_id}" title="Subir certificado">⬆</button>
                  </div>` : '—'}
              </td>
            </tr>`).join('')
        : Utils.emptyRow(6, 'No hay solicitudes de certificado');

      tbody.querySelectorAll('[data-action]').forEach(btn => {
        btn.addEventListener('click', handleCertAction);
      });
    } catch {
      tbody.innerHTML = Utils.emptyRow(6, 'Error al cargar solicitudes');
    }
  }

  function handleCertAction(e) {
    const btn    = e.currentTarget;
    const action = btn.dataset.action;
    const id     = parseInt(btn.dataset.id);
    if (action === 'approve-cert') approveCert(id);
    if (action === 'reject-cert')  rejectCert(id);
    if (action === 'upload-cert')  openUploadCertModal(parseInt(btn.dataset.sid), id);
  }

  async function approveCert(requestId) {
    if (!confirm('¿Aprobar esta solicitud?')) return;
    try {
      await Api.put('/certificates/approve', { request_id: requestId });
      Toast.success('Solicitud aprobada');
      loadCertificateRequests();
    } catch (err) {
      Toast.error('Error', err.message);
    }
  }

  async function rejectCert(requestId) {
    const notes = prompt('Motivo de rechazo (opcional):');
    if (notes === null) return;
    try {
      await Api.put('/certificates/reject', { request_id: requestId, notes });
      Toast.success('Solicitud rechazada');
      loadCertificateRequests();
    } catch (err) {
      Toast.error('Error', err.message);
    }
  }

  function openUploadCertModal(studentId, requestId) {
    Modal.create({
      id: 'modal-upload-cert',
      title: 'Subir Certificado',
      body: `
        <div class="form-group">
          <label class="form-label">Archivo (PDF, JPG, PNG — máx. 10MB)</label>
          <input type="file" id="cert-file-input" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
        </div>
        <div class="form-group">
          <label class="form-label">Notas</label>
          <textarea id="cert-notes" class="form-control" rows="2"></textarea>
        </div>
      `,
      footer: `
        <button class="btn btn-secondary" data-close="modal-upload-cert">Cancelar</button>
        <button class="btn btn-primary" id="btn-upload-cert">Subir</button>
      `,
    });
    Modal.open('modal-upload-cert');

    document.getElementById('btn-upload-cert').addEventListener('click', async () => {
      const fileInput = document.getElementById('cert-file-input');
      const notes     = document.getElementById('cert-notes').value;
      if (!fileInput.files.length) return Toast.error('Selecciona un archivo');

      const btn = document.getElementById('btn-upload-cert');
      Utils.btnLoading(btn);
      try {
        const file = fileInput.files[0];
        const reader = new FileReader();
        reader.onload = async () => {
          try {
            await Api.post('/certificates/upload', {
              student_id:  studentId,
              request_id:  requestId,
              file_base64: reader.result,
              notes,
            });
            Toast.success('Certificado subido');
            Modal.close('modal-upload-cert');
            loadCertificateRequests();
          } catch (err) {
            Toast.error('Error al subir', err.message);
          } finally {
            Utils.btnLoading(btn, false);
          }
        };
        reader.readAsDataURL(file);
      } catch (err) {
        Toast.error('Error', err.message);
        Utils.btnLoading(btn, false);
      }
    });
  }

  // ── Auditoría (placeholder) ──────────────────────────────────

  async function loadAuditLogs() {
    const container = document.getElementById('audit-container');
    if (!container) return;
    container.innerHTML = '<p class="text-muted text-sm">Módulo de auditoría disponible próximamente.</p>';
  }

  return { init };
})();

/* ================================================================
   14. INICIALIZACIÓN POR PÁGINA
   ================================================================ */

document.addEventListener('DOMContentLoaded', () => {
  const path = window.location.pathname.replace(/\/$/, '');

  // Detectar en qué página estamos por pathname o body data-page
  const page = document.body.dataset.page || (() => {
    if (path.endsWith('/admin') || path.includes('/admin/')) return 'admin';
    if (path.endsWith('/home')  || path.includes('/home/'))  return 'home';
    if (path.endsWith('/auth')  || path.includes('/auth/'))  return 'auth';
    return 'landing';
  })();

  switch (page) {
    case 'landing': LandingView.init(); break;
    case 'auth':    AuthView.init();    break;
    case 'home':    HomeView.init();    break;
    case 'admin':   AdminView.init();   break;
  }
});

/* ================================================================
   15. EXPORTAR (para uso modular opcional)
   ================================================================ */

if (typeof window !== 'undefined') {
  window.SS = { Api, Auth, Router, Toast, Modal, SSEManager, Utils, NotifDropdown };
}