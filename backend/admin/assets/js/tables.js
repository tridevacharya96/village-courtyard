/* Table List: live floor grid, table detail modal, add/edit/delete */
(function ($) {
  'use strict';
  const $floor = $('#floor');
  const modalEl = document.getElementById('tableModal');
  const modal = new bootstrap.Modal(modalEl);
  const formModalEl = document.getElementById('tableFormModal');
  const formModal = formModalEl ? new bootstrap.Modal(formModalEl) : null;
  let openTableId = null;

  function refreshFloor() {
    $.ajax({ url: 'tables.php', data: { partial: 1 }, dataType: 'html' }).done((html) => $floor.html(html));
  }
  function loadTable(id) {
    openTableId = id;
    return $.ajax({ url: 'ajax/table-detail.php', data: { id }, dataType: 'html' })
      .done((html) => $('#tableModalContent').html(html));
  }
  const refreshAll = () => { refreshFloor(); if (openTableId && modalEl.classList.contains('show')) loadTable(openTableId); };

  setInterval(refreshFloor, 20000);
  document.addEventListener('vc:new-orders', refreshAll);

  $floor.on('click', '.dining-table', function () {
    loadTable(this.dataset.table).done(() => modal.show());
  });
  modalEl.addEventListener('hidden.bs.modal', () => { openTableId = null; });

  // Table status
  $(modalEl).on('click', '.js-table-status', function () {
    VC.post('ajax/table-action.php', { action: 'status', id: openTableId, status: this.dataset.status })
      .then((res) => { VC.toast(res.message); refreshAll(); });
  });

  // Move an order along from the table modal
  $(modalEl).on('click', '.js-order-next', function () {
    const $b = $(this).prop('disabled', true);
    VC.post('ajax/order-action.php', { action: 'status', id: $b.data('id'), status: $b.data('status') })
      .then((res) => { VC.toast(res.message); refreshAll(); })
      .catch(() => $b.prop('disabled', false));
  });

  // ---------- Manage (Super Admin) ----------
  if (!formModal) return;
  const $form = $('#tableForm');

  function openForm(data) {
    $form[0].reset();
    $form.find('.is-invalid').removeClass('is-invalid');
    $('#tableFormTitle').text(data ? `Edit table ${data.table_number}` : 'Add table');
    $('#tf_id').val(data ? data.id : '');
    $('#tf_number').val(data ? data.table_number : '');
    $('#tf_capacity').val(data ? data.capacity : 4);
    $('#tf_location').val(data ? data.location || '' : '');
    $('#tf_active').prop('checked', data ? !!data.is_active : true);
    formModal.show();
  }

  $('#addTable').on('click', () => openForm(null));
  $(modalEl).on('click', '.js-table-edit', function () {
    const data = JSON.parse(this.dataset.json);
    modal.hide();
    openForm(data);
  });

  $form.on('submit', function (e) {
    e.preventDefault();
    $form.find('.is-invalid').removeClass('is-invalid');
    const data = $form.serializeArray();
    if (!$('#tf_active').is(':checked')) data.push({ name: 'is_active', value: '0' });
    data.push({ name: 'action', value: 'save' });
    VC.post('ajax/table-action.php', $.param(data))
      .then((res) => { VC.toast(res.message); formModal.hide(); refreshFloor(); })
      .catch((res) => {
        const map = { table_number: '#tf_number', capacity: '#tf_capacity', location: '#tf_location' };
        Object.entries(res.errors || {}).forEach(([k, msg]) => {
          $(map[k]).addClass('is-invalid').siblings('.invalid-feedback').text(msg);
        });
      });
  });

  $(modalEl).on('click', '.js-table-delete', async function () {
    const number = this.dataset.number;
    const ok = await VC.confirm({ title: `Delete table ${number}?`, body: 'Only tables that were never used can be deleted. Used tables can be switched out of use instead.', confirmText: 'Delete table', danger: true });
    if (!ok) return;
    VC.post('ajax/table-action.php', { action: 'delete', id: openTableId })
      .then((res) => { VC.toast(res.message); modal.hide(); refreshFloor(); });
  });
})(jQuery);
