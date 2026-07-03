/* ============================================================
   LeadPro LMS — Main JavaScript
   All UI interactions, AJAX, search, notifications, theme
   ============================================================ */

'use strict';

const LMS = {

  /* ── Theme Toggle ──────────────────────────────────────────── */
  initTheme() {
    const saved = localStorage.getItem('lms-theme') || 'light';
    document.documentElement.setAttribute('data-bs-theme', saved);
    this._updateThemeIcon(saved);

    document.getElementById('themeToggle')?.addEventListener('click', () => {
      const curr = document.documentElement.getAttribute('data-bs-theme');
      const next = curr === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-bs-theme', next);
      localStorage.setItem('lms-theme', next);
      this._updateThemeIcon(next);
    });
  },
  _updateThemeIcon(theme) {
    const icon = document.getElementById('themeIcon');
    if (icon) icon.className = theme === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-fill';
  },

  /* ── Sidebar ───────────────────────────────────────────────── */
  initSidebar() {
    const sidebar  = document.getElementById('sidebar');
    const toggle   = document.getElementById('sidebarToggle');
    const closeBtn = document.getElementById('sidebarClose');
    if (!sidebar) return;

    // Create overlay once
    let overlay = document.getElementById('sidebarOverlay');
    if (!overlay) {
      overlay = document.createElement('div');
      overlay.id = 'sidebarOverlay';
      overlay.className = 'sidebar-overlay';
      document.body.appendChild(overlay);
    }

    const open  = () => { sidebar.classList.add('open');    overlay.classList.add('show'); document.body.style.overflow = 'hidden'; };
    const close = () => { sidebar.classList.remove('open'); overlay.classList.remove('show'); document.body.style.overflow = ''; };

    toggle?.addEventListener('click', () => sidebar.classList.contains('open') ? close() : open());
    closeBtn?.addEventListener('click', close);
    overlay.addEventListener('click', close);

    // Close on resize to desktop
    window.addEventListener('resize', () => {
      if (window.innerWidth >= 1200) close();
    });
  },

  /* ── Notifications ─────────────────────────────────────────── */
  initNotifications() {
    const btn = document.getElementById('notifBtn');
    if (!btn) return;
    btn.addEventListener('show.bs.dropdown', () => this._loadNotifications());
  },

  async _loadNotifications() {
    const list = document.getElementById('notifList');
    if (!list) return;

    // Show spinner while loading
    list.innerHTML = '<div class="notif-loading py-4 text-center text-muted small"><span class="spinner-border spinner-border-sm me-2"></span>Loading…</div>';

    try {
      const res  = await fetch('/lms/api/notifications.php?action=list');
      const data = await res.json();

      if (!data.length) {
        list.innerHTML = '<div class="notif-empty"><i class="bi bi-bell-slash" style="font-size:2rem;opacity:.3;display:block;margin-bottom:.5rem;"></i>No notifications yet</div>';
        return;
      }

      const typeIcons = {
        success: 'check-circle-fill text-success',
        danger:  'x-circle-fill text-danger',
        warning: 'exclamation-circle-fill text-warning',
        info:    'info-circle-fill text-primary'
      };

      list.innerHTML = data.map(function(n) {
        const icon = typeIcons[n.type] || typeIcons.info;
        const href = (n.link && n.link.trim() !== '') ? escHtml(n.link) : '#';
        const dot  = n.is_read == 0 ? '<span class="notif-unread-dot"></span>' : '';
        return '<a class="notif-item ' + (n.is_read == 0 ? 'unread' : '') + '" href="' + href + '" data-id="' + n.id + '" onclick="LMS.markRead(' + n.id + ')">'
          + '<div class="d-flex align-items-start gap-2">'
          + '<i class="bi bi-' + icon + ' flex-shrink-0 mt-1" style="font-size:.9rem;"></i>'
          + '<div class="flex-fill overflow-hidden">'
          + '<div class="notif-item-title">' + escHtml(n.title) + '</div>'
          + '<div class="notif-item-body">' + escHtml(n.message) + '</div>'
          + '<div class="notif-item-time"><i class="bi bi-clock me-1"></i>' + timeAgo(n.created_at) + '</div>'
          + '</div>' + dot + '</div></a>';
      }).join('');

    } catch(err) {
      list.innerHTML = '<div class="notif-loading py-3 text-center text-danger small"><i class="bi bi-exclamation-triangle me-1"></i>Failed to load</div>';
    }
  },

  async markRead(id) {
    const fd = new FormData();
    fd.append('id', id);
    await fetch('/lms/api/notifications.php?action=mark-read', { method: 'POST', body: fd });
  },

  initMarkAllRead() {
    document.addEventListener('click', async e => {
      if (!e.target.closest('.mark-all-read')) return;
      e.preventDefault();
      await fetch('/lms/api/notifications.php?action=mark-all-read', { method: 'POST' });
      document.querySelectorAll('.notif-badge').forEach(b => b.remove());
      document.querySelectorAll('.notif-item.unread').forEach(i => i.classList.remove('unread'));
      showToast('success', 'All notifications marked as read');
    });
  },

  /* ── Inline Status Update ──────────────────────────────────── */
  initStatusChange() {
    document.querySelectorAll('.status-select').forEach(sel => {
      sel.addEventListener('change', async function () {
        const fd = new FormData();
        fd.append('action', 'update-status');
        fd.append('id', this.dataset.id);
        fd.append('status', this.value);
        try {
          const res  = await fetch('/lms/api/leads.php', { method: 'POST', body: fd });
          const data = await res.json();
          showToast(data.success ? 'success' : 'danger', data.message || 'Updated');
          // Update badge in the same row if present
          const badge = this.closest('tr')?.querySelector('.badge-status');
          if (badge) {
            badge.textContent = this.value;
            badge.className   = `badge-status status-${this.value.replace(/ /g, '-')}`;
          }
        } catch {
          showToast('danger', 'Network error. Please try again.');
        }
      });
    });
  },

  /* ── Follow-up Form (AJAX submit) ─────────────────────────── */
  initFollowupForm() {
    const form = document.getElementById('followupForm');
    if (!form) return;
    form.addEventListener('submit', async e => {
      e.preventDefault();
      const btn = form.querySelector('[type="submit"]');
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving…';
      try {
        const res  = await fetch('/lms/api/followups.php', { method: 'POST', body: new FormData(form) });
        const data = await res.json();
        if (data.success) {
          showToast('success', 'Follow-up saved!');
          const modal = document.getElementById('followupModal');
          if (modal) bootstrap.Modal.getInstance(modal)?.hide();
          setTimeout(() => location.reload(), 900);
        } else {
          showToast('danger', data.message || 'Failed to save follow-up.');
        }
      } catch {
        showToast('danger', 'Network error.');
      } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Save Follow-up';
      }
    });
  },

  /* ── Bulk Select ───────────────────────────────────────────── */
  initBulkSelect() {
    const selectAll = document.getElementById('selectAll');
    if (!selectAll) return;
    selectAll.addEventListener('change', function () {
      document.querySelectorAll('.row-check').forEach(c => c.checked = this.checked);
      updateBulkBar();
    });
    document.addEventListener('change', e => {
      if (e.target.classList.contains('row-check')) updateBulkBar();
    });
    function updateBulkBar() {
      const checked = [...document.querySelectorAll('.row-check:checked')].map(c => c.value);
      const btn     = document.getElementById('bulkDeleteBtn');
      const input   = document.getElementById('bulkIdsInput');
      const count   = document.getElementById('bulkCount');
      if (btn)   btn.disabled = !checked.length;
      if (input) input.value  = checked.join(',');
      if (count) count.textContent = checked.length;
    }
  },

  /* ── Live Global Search ────────────────────────────────────── */
  initLiveSearch() {
    const input = document.getElementById('globalSearch');
    const box   = document.getElementById('searchResults');
    if (!input || !box) return;

    const doSearch = debounce(async q => {
      if (q.length < 2) { box.style.display = 'none'; return; }
      try {
        const res  = await fetch(`/lms/api/search.php?q=${encodeURIComponent(q)}`);
        const data = await res.json();
        if (!data.length) {
          box.innerHTML   = '<div class="search-empty"><i class="bi bi-search me-2 opacity-50"></i>No results for "' + escHtml(q) + '"</div>';
        } else {
          const statusColors = {
            New:'#3b82f6',Contacted:'#6366f1','Follow-up':'#f59e0b',
            Interested:'#10b981',Converted:'#22c55e',Closed:'#94a3b8',Rejected:'#ef4444'
          };
          box.innerHTML = data.map(r => `
            <a class="search-item" href="${escHtml(r.url)}">
              <div class="search-item-avatar">${escHtml(r.name.charAt(0).toUpperCase())}</div>
              <div>
                <div class="search-item-name">${escHtml(r.name)}</div>
                <div class="search-item-meta d-flex align-items-center gap-2">
                  ${r.phone ? escHtml(r.phone) : ''}
                  <span style="display:inline-block;padding:.1em .55em;border-radius:20px;
                    font-size:.65rem;font-weight:700;background:${statusColors[r.status]||'#94a3b8'}20;
                    color:${statusColors[r.status]||'#94a3b8'};">${escHtml(r.status)}</span>
                  <span class="priority-${escHtml(r.priority)}" style="font-size:.7rem;">${escHtml(r.priority)}</span>
                </div>
              </div>
            </a>`).join('');
        }
        box.style.display = 'block';
      } catch {
        box.style.display = 'none';
      }
    }, 280);

    input.addEventListener('input',  e => doSearch(e.target.value.trim()));
    input.addEventListener('focus',  e => { if (e.target.value.length >= 2) box.style.display = 'block'; });
    document.addEventListener('click', e => {
      if (!input.contains(e.target) && !box.contains(e.target)) box.style.display = 'none';
    });
    // Keyboard shortcut: Ctrl+K or /
    document.addEventListener('keydown', e => {
      const tag = document.activeElement.tagName;
      if ((e.ctrlKey && e.key === 'k') ||
          (e.key === '/' && tag !== 'INPUT' && tag !== 'TEXTAREA' && tag !== 'SELECT')) {
        e.preventDefault();
        input.focus();
        input.select();
      }
      if (e.key === 'Escape') { box.style.display = 'none'; input.blur(); }
    });
  },

  /* ── Confirm Delete Helpers ────────────────────────────────── */
  initDeleteButtons() {
    document.querySelectorAll('.delete-lead').forEach(btn => {
      btn.addEventListener('click', () => {
        const modal = document.getElementById('deleteModal');
        if (!modal) return;
        document.getElementById('deleteName').textContent = btn.dataset.name || '';
        document.getElementById('deleteConfirmBtn').href  =
          '/lms/api/leads.php?action=delete&id=' + btn.dataset.id;
        new bootstrap.Modal(modal).show();
      });
    });
  },

  /* ── Assign Employee (inline) ──────────────────────────────── */
  initAssignSelect() {
    document.querySelectorAll('.assign-select').forEach(sel => {
      sel.addEventListener('change', async function () {
        const fd = new FormData();
        fd.append('action', 'assign');
        fd.append('id', this.dataset.id);
        fd.append('employee_id', this.value);
        try {
          const res  = await fetch('/lms/api/leads.php', { method: 'POST', body: fd });
          const data = await res.json();
          showToast(data.success ? 'success' : 'danger', data.message);
        } catch {
          showToast('danger', 'Network error.');
        }
      });
    });
  },

  /* ── Bootstrap tooltips ────────────────────────────────────── */
  initTooltips() {
    document.querySelectorAll('[title]').forEach(el => {
      new bootstrap.Tooltip(el, { trigger: 'hover', placement: 'top' });
    });
  },

  /* ── Initialise all ────────────────────────────────────────── */
  init() {
    this.initTheme();
    this.initSidebar();
    this.initNotifications();
    this.initMarkAllRead();
    this.initStatusChange();
    this.initFollowupForm();
    this.initBulkSelect();
    this.initLiveSearch();
    this.initDeleteButtons();
    this.initAssignSelect();
  }
};

/* ── Utility Functions ─────────────────────────────────────────── */

// Safe HTML escape
function escHtml(str) {
  const d = document.createElement('div');
  d.appendChild(document.createTextNode(String(str)));
  return d.innerHTML;
}

// Toast notification
function showToast(type, msg) {
  const map = { success: '#10b981', danger: '#ef4444', warning: '#f59e0b', info: '#3b82f6' };
  const icons = { success: 'check-circle-fill', danger: 'x-circle-fill', warning: 'exclamation-triangle-fill', info: 'info-circle-fill' };
  const wrap = document.createElement('div');
  wrap.style.cssText = `
    position:fixed; bottom:20px; right:20px; z-index:99999;
    background:${map[type]||'#333'}; color:#fff;
    padding:.7rem 1.1rem; border-radius:10px;
    font-size:.84rem; font-weight:600;
    font-family:var(--font,sans-serif);
    box-shadow:0 8px 24px rgba(0,0,0,.22);
    display:flex; align-items:center; gap:.5rem;
    animation:fadeInUp .25s ease;
    max-width:320px;
  `;
  wrap.innerHTML = `<i class="bi bi-${icons[type]||'bell'}"></i>${escHtml(msg)}`;
  document.body.appendChild(wrap);
  setTimeout(() => {
    wrap.style.animation = 'fadeOut .25s ease forwards';
    setTimeout(() => wrap.remove(), 250);
  }, 3200);
}

// Debounce
function debounce(fn, ms = 300) {
  let t;
  return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
}

// Time ago
function timeAgo(dateStr) {
  const diff = Math.floor((Date.now() - new Date(dateStr)) / 1000);
  if (diff < 60)    return 'Just now';
  if (diff < 3600)  return `${Math.floor(diff/60)}m ago`;
  if (diff < 86400) return `${Math.floor(diff/3600)}h ago`;
  return `${Math.floor(diff/86400)}d ago`;
}

// Inject keyframe CSS
const _style = document.createElement('style');
_style.textContent = `
  @keyframes fadeInUp  { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }
  @keyframes fadeOut   { from{opacity:1;transform:translateY(0)} to{opacity:0;transform:translateY(10px)} }
`;
document.head.appendChild(_style);

// Boot on DOM ready
document.addEventListener('DOMContentLoaded', () => LMS.init());