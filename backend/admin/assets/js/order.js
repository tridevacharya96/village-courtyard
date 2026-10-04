/* Order detail: status buttons, cash received, delete */
(function ($) {
  'use strict';
  const $detail = $('#orderDetail');
  const id = $detail.data('id');
  const number = $detail.data('number');

  $(document).on('click', '.js-status', async function () {
    const status = this.dataset.status;
    const note = $('#statusNote').val() || '';
    if (status === 'cancelled') {
      const ok = await VC.confirm({
        title: `Cancel ${number}?`,
        body: 'The customer will see this order as cancelled. Add the reason in the note field first if you want it recorded.',
        confirmText: 'Cancel order', danger: true,
      });
      if (!ok) return;
    }
    $('.js-status').prop('disabled', true);
    VC.post('ajax/order-action.php', { action: 'status', id, status, note })
      .then((res) => { VC.toast(res.message); setTimeout(() => location.reload(), 600); })
      .catch(() => $('.js-status').prop('disabled', false));
  });

  $('#markPaid').on('click', async function () {
    const ok = await VC.confirm({ title: 'Cash received?', body: `Record that the customer paid ${number} in cash.`, confirmText: 'Yes, cash received' });
    if (!ok) return;
    VC.post('ajax/order-action.php', { action: 'mark_paid', id })
      .then((res) => { VC.toast(res.message); setTimeout(() => location.reload(), 600); });
  });

  $('#deleteOrder').on('click', async function () {
    const ok = await VC.confirm({ title: `Delete ${number}?`, body: 'This permanently removes the order, its items and payment records. It also disappears from analytics. This cannot be undone.', confirmText: 'Delete permanently', danger: true });
    if (!ok) return;
    VC.post('ajax/order-action.php', { action: 'delete', id }).then((res) => { location.href = res.data.redirect; });
  });
})(jQuery);
