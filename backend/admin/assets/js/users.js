/* Users & Roles: staff accounts and the Staff permission switches */
(function ($) {
  'use strict';

  // ---------- Permission matrix ----------
  $(document).on('change', '.js-perm', function () {
    const el = this;
    VC.post('ajax/user-action.php', { action: 'perm', permission: el.dataset.perm, granted: el.checked ? 1 : 0 })
      .then((res) => VC.toast(res.message))
      .catch(() => { el.checked = !el.checked; });
  });

  // ---------- Staff accounts ----------
  const userModalEl = document.getElementById('userModal');
  if (!userModalEl) return;
  const userModal = new bootstrap.Modal(userModalEl);
  const pwModal = new bootstrap.Modal(document.getElementById('pwModal'));
  const $form = $('#userForm');

  function makePassword() {
    const words = ['Mango', 'Lotus', 'Saffron', 'Tamarind', 'Jasmine', 'Cardamom', 'Lantern', 'Banyan'];
    const pick = () => words[crypto.getRandomValues(new Uint32Array(1))[0] % words.length];
    return `${pick()}-${1000 + (crypto.getRandomValues(new Uint32Array(1))[0] % 9000)}-${pick()}`;
  }

  function openForm(user) {
    $form[0].reset();
    $form.find('.is-invalid').removeClass('is-invalid');
    $('#u_password_err').text('');
    $('#userModalTitle').text(user ? `Edit ${user.name}` : 'Add staff member');
    $('#u_id').val(user ? user.id : '');
    $('#u_name').val(user ? user.name : '');
    $('#u_email').val(user ? user.email : '');
    $('#u_phone').val(user ? user.phone || '' : '');
    $('#u_pw_wrap').prop('hidden', !!user);
    if (!user) $('#u_password').val(makePassword());
    userModal.show();
  }

  $('#addUser').on('click', () => openForm(null));
  $(document).on('click', '.js-edit', function () { openForm(JSON.parse(this.dataset.user)); });
  $('#genPw').on('click', () => $('#u_password').val(makePassword()));

  $form.on('submit', function (e) {
    e.preventDefault();
    $form.find('.is-invalid').removeClass('is-invalid');
    $('#u_password_err').text('');
    VC.post('ajax/user-action.php', $form.serialize() + '&action=save')
      .then(() => location.reload())
      .catch((res) => {
        Object.entries(res.errors || {}).forEach(([k, msg]) => {
          if (k === 'password') { $('#u_password_err').text(msg); return; }
          $('#u_' + k).addClass('is-invalid').siblings('.invalid-feedback').text(msg);
        });
      });
  });

  $(document).on('click', '.js-reset', async function () {
    const { id, name } = this.dataset;
    const ok = await VC.confirm({ title: `Reset ${name}'s password?`, body: 'Their current password stops working right away. You will get a temporary password to give them.', confirmText: 'Reset password' });
    if (!ok) return;
    VC.post('ajax/user-action.php', { action: 'reset', id }).then((res) => {
      $('#pwFor').text(`New temporary password for ${res.data.name}:`);
      $('#pwValue').val(res.data.password);
      pwModal.show();
    });
  });

  $('#copyPw').on('click', function () {
    const input = document.getElementById('pwValue');
    navigator.clipboard.writeText(input.value).then(() => VC.toast('Password copied.'), () => { input.select(); VC.toast('Press Ctrl+C to copy.', 'info'); });
  });

  $(document).on('click', '.js-toggle', async function () {
    const { id, name, active } = this.dataset;
    const deactivating = active === '1';
    const ok = await VC.confirm({
      title: deactivating ? `Deactivate ${name}?` : `Reactivate ${name}?`,
      body: deactivating ? 'They will be signed out immediately and will not be able to sign in.' : 'They will be able to sign in again with their current password.',
      confirmText: deactivating ? 'Deactivate' : 'Reactivate', danger: deactivating,
    });
    if (!ok) return;
    VC.post('ajax/user-action.php', { action: 'toggle', id }).then((res) => { VC.toast(res.message); setTimeout(() => location.reload(), 700); });
  });
})(jQuery);
