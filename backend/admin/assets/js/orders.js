/* Orders list: quick next-step buttons, live refresh, custom date toggle */
(function ($) {
  'use strict';
  const $list = $('#ordersList');

  function reloadList() {
    const params = new URLSearchParams(location.search);
    params.set('partial', '1');
    $.ajax({ url: 'orders.php?' + params.toString(), dataType: 'html' }).done((html) => $list.html(html));
  }

  $list.on('click', '.js-next', function () {
    const $btn = $(this).prop('disabled', true);
    VC.post('ajax/order-action.php', { action: 'status', id: $btn.data('id'), status: $btn.data('status') })
      .then((res) => { VC.toast(res.message); reloadList(); })
      .catch(() => $btn.prop('disabled', false));
  });

  // New orders arrive while the page is open: refresh if the user is on page 1
  document.addEventListener('vc:new-orders', () => {
    if (String($list.data('page')) === '1') reloadList();
  });

  $('#date').on('change', function () {
    $('.custom-range').prop('hidden', this.value !== 'custom');
  });
})(jQuery);
