/* =====================================================================
   Shared list behaviour for content modules (talks to ajax/crud.php)
   - .js-toggle switches        data-resource, data-id, data-field
   - .js-delete buttons         data-resource, data-id, data-name
   - [data-sortable] containers data-resource; children carry data-id
   - input[type=file][data-preview] shows the chosen image before saving
   ===================================================================== */
(function ($) {
  'use strict';

  $(document).on('change', '.js-toggle', function () {
    const el = this;
    VC.post('ajax/crud.php', { action: 'toggle', resource: el.dataset.resource, id: el.dataset.id, field: el.dataset.field, value: el.checked ? 1 : 0 })
      .then((res) => {
        VC.toast(res.message);
        $(el).closest('[data-row]').toggleClass('row-off', el.dataset.field.match(/is_(active|available|visible|published)/) ? !el.checked : false);
      })
      .catch(() => { el.checked = !el.checked; });
  });

  $(document).on('click', '.js-delete', async function () {
    const { resource, id, name, body } = this.dataset;
    const ok = await VC.confirm({ title: `Delete ${name}?`, body: body || 'This cannot be undone.', confirmText: 'Delete', danger: true });
    if (!ok) return;
    VC.post('ajax/crud.php', { action: 'delete', resource, id }).then((res) => {
      VC.toast(res.message);
      const $row = $(this).closest('[data-row]');
      if ($row.length && !this.dataset.redirect) $row.fadeOut(200, () => $row.remove());
      else location.href = this.dataset.redirect || location.href;
    });
  });

  if (window.Sortable) {
    document.querySelectorAll('[data-sortable]').forEach((list) => {
      Sortable.create(list, {
        handle: '.drag-handle', animation: 150, ghostClass: 'sortable-ghost',
        onEnd: () => {
          const ids = Array.from(list.querySelectorAll(':scope > [data-id]')).map((el) => el.dataset.id);
          VC.post('ajax/crud.php', { action: 'reorder', resource: list.dataset.sortable, ids });
        },
      });
    });
  }

  $(document).on('change', 'input[type=file][data-preview]', function () {
    const file = this.files && this.files[0];
    const $preview = $(this).closest('.image-field').find('.image-field-preview');
    if (!file || !file.type.startsWith('image/')) return;
    const url = URL.createObjectURL(file);
    $preview.replaceWith(`<img class="image-field-preview" src="${url}" alt="Selected image preview">`);
  });
})(jQuery);
