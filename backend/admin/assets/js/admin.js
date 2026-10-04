/* =====================================================================
   Village Courtyard admin — shared behaviour
   - AJAX with CSRF token (jQuery)
   - Toasts and confirmation dialogs
   - New-order polling with sound alert
   ===================================================================== */
(function ($) {
  'use strict';

  const base = document.body.dataset.base || '';
  const csrf = $('meta[name="csrf-token"]').attr('content');

  // Every jQuery AJAX call carries the CSRF token and is recognised as AJAX by PHP
  $.ajaxSetup({ headers: { 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest' }, dataType: 'json' });

  const escapeHtml = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const money = (n) => '₹' + Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  // ---------------------------------------------------------------
  //  Toasts
  // ---------------------------------------------------------------
  function toast(message, type = 'success', opts = {}) {
    const area = document.getElementById('toastArea');
    if (!area) return;
    const icon = { success: 'bi-check-circle', danger: 'bi-exclamation-octagon', warning: 'bi-exclamation-triangle', info: 'bi-info-circle', order: 'bi-bell' }[type] || 'bi-info-circle';
    const el = document.createElement('div');
    el.className = 'toast align-items-center border-0 text-bg-' + (type === 'order' ? 'dark' : type);
    el.setAttribute('role', type === 'danger' ? 'alert' : 'status');
    el.innerHTML = `<div class="d-flex"><div class="toast-body"><i class="bi ${icon} me-2"></i>${opts.html ? message : escapeHtml(message)}</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>`;
    area.appendChild(el);
    const t = new bootstrap.Toast(el, { delay: opts.delay || (type === 'danger' ? 7000 : 4000), autohide: opts.autohide !== false });
    el.addEventListener('hidden.bs.toast', () => el.remove());
    t.show();
  }

  // ---------------------------------------------------------------
  //  AJAX helper: VC.post(url, data) → Promise(data)
  // ---------------------------------------------------------------
  function request(method, url, data) {
    return new Promise((resolve, reject) => {
      $.ajax({ url: url.startsWith('http') || url.startsWith('/') ? url : base + url, method, data })
        .done((res) => resolve(res))
        .fail((xhr) => {
          const res = xhr.responseJSON || {};
          if (xhr.status === 401) {
            toast('Your session has ended. Taking you to sign in…', 'warning');
            setTimeout(() => location.reload(), 1500);
          } else {
            const firstError = res.errors ? Object.values(res.errors)[0] : null;
            toast(firstError || res.message || 'Something went wrong. Please try again.', 'danger');
          }
          reject(res);
        });
    });
  }

  // ---------------------------------------------------------------
  //  Confirmation dialog: VC.confirm({title, body, confirmText, danger}) → Promise<bool>
  // ---------------------------------------------------------------
  function confirmDialog({ title = 'Are you sure?', body = '', confirmText = 'Confirm', danger = false } = {}) {
    return new Promise((resolve) => {
      const el = document.createElement('div');
      el.className = 'modal fade';
      el.tabIndex = -1;
      el.innerHTML = `<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><h2 class="modal-title fs-4">${escapeHtml(title)}</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">${escapeHtml(body)}</div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn ${danger ? 'btn-danger' : 'btn-primary'}" data-ok>${escapeHtml(confirmText)}</button></div>
      </div></div>`;
      document.body.appendChild(el);
      const modal = new bootstrap.Modal(el);
      let ok = false;
      el.querySelector('[data-ok]').addEventListener('click', () => { ok = true; modal.hide(); });
      el.addEventListener('hidden.bs.modal', () => { el.remove(); resolve(ok); });
      modal.show();
    });
  }

  // Forms/buttons with data-confirm="message" ask first
  $(document).on('submit', 'form[data-confirm]', async function (e) {
    if (this.dataset.confirmed) return;
    e.preventDefault();
    const ok = await confirmDialog({ title: this.dataset.confirmTitle || 'Please confirm', body: this.dataset.confirm, confirmText: this.dataset.confirmButton || 'Confirm', danger: this.dataset.confirmDanger === '1' });
    if (ok) { this.dataset.confirmed = '1'; this.submit(); }
  });

  // ---------------------------------------------------------------
  //  New-order sound (generated with Web Audio, no file needed)
  // ---------------------------------------------------------------
  const SOUND_KEY = 'vc_admin_sound';
  let audioCtx = null;
  const soundOn = () => { try { return localStorage.getItem(SOUND_KEY) === '1'; } catch (e) { return false; } };

  function ensureAudio() {
    if (!audioCtx) {
      const Ctx = window.AudioContext || window.webkitAudioContext;
      if (!Ctx) return null;
      audioCtx = new Ctx();
    }
    if (audioCtx.state === 'suspended') audioCtx.resume();
    return audioCtx;
  }

  function chime() {
    const ctx = ensureAudio();
    if (!ctx) return;
    // Two soft bell tones, like a counter bell
    [[880, 0], [1318.5, 0.18]].forEach(([freq, delay]) => {
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.type = 'sine';
      osc.frequency.value = freq;
      const t = ctx.currentTime + delay;
      gain.gain.setValueAtTime(0.0001, t);
      gain.gain.exponentialRampToValueAtTime(0.35, t + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.0001, t + 1.1);
      osc.connect(gain).connect(ctx.destination);
      osc.start(t);
      osc.stop(t + 1.2);
    });
  }

  function renderSoundToggle() {
    const btn = document.getElementById('soundToggle');
    if (!btn) return;
    const on = soundOn();
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    btn.title = on ? 'New-order sound is on. Click to mute.' : 'New-order sound is off. Click to turn on.';
    btn.querySelector('i').className = 'bi ' + (on ? 'bi-volume-up' : 'bi-volume-mute');
  }

  $(document).on('click', '#soundToggle', function () {
    const next = !soundOn();
    try { localStorage.setItem(SOUND_KEY, next ? '1' : '0'); } catch (e) { /* storage blocked */ }
    renderSoundToggle();
    if (next) { chime(); toast('New-order sound is on. Keep this tab open to hear alerts.', 'info'); }
  });
  // Browsers only allow audio after a click; unlock it on the first interaction
  document.addEventListener('click', () => { if (soundOn()) ensureAudio(); }, { once: true });

  // ---------------------------------------------------------------
  //  New-order polling
  // ---------------------------------------------------------------
  const POLL_MS = 15000;
  let lastOrderId = parseInt(document.body.dataset.lastOrder || '0', 10);

  function setBadges(unseen) {
    const bell = document.getElementById('bellCount');
    const nav = document.getElementById('navOrderCount');
    [bell, nav].forEach((el) => {
      if (!el) return;
      el.hidden = unseen <= 0;
      el.textContent = unseen > 99 ? '99+' : unseen;
    });
    document.title = document.title.replace(/^\(\d+\+?\) /, '');
    if (unseen > 0) document.title = `(${unseen}) ` + document.title;
  }

  function poll() {
    $.get(base + 'ajax/poll.php', { since: lastOrderId })
      .done((res) => {
        const d = res.data || {};
        setBadges(d.unseen || 0);
        if (d.new_orders && d.new_orders.length) {
          lastOrderId = Math.max(lastOrderId, d.latest_id || 0);
          if (soundOn()) chime();
          d.new_orders.slice(0, 3).forEach((o) => {
            toast(`<strong>New ${escapeHtml(o.type_label)} order ${escapeHtml(o.order_number)}</strong><br>${escapeHtml(o.customer)} · ${money(o.total)}
              <a class="link-light ms-1" href="${base}order.php?id=${o.id}">Open</a>`, 'order', { html: true, autohide: false });
          });
          document.dispatchEvent(new CustomEvent('vc:new-orders', { detail: d.new_orders }));
        }
        document.dispatchEvent(new CustomEvent('vc:poll', { detail: d }));
      })
      .fail((xhr) => { if (xhr.status === 401) location.reload(); });
  }

  if (document.body.dataset.poll === '1') {
    renderSoundToggle();
    poll();
    setInterval(poll, POLL_MS);
  }

  // Public helpers for page scripts
  window.VC = { toast, post: (url, data) => request('POST', url, data), get: (url, data) => request('GET', url, data), confirm: confirmDialog, escapeHtml, money, base };
})(jQuery);
