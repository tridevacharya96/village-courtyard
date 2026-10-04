/**
 * Razorpay Checkout helper. The script is only loaded when a guest pays online.
 * openCheckout() resolves to { status: 'paid' | 'dismissed' | 'failed', response, error }.
 */
const SCRIPT = 'https://checkout.razorpay.com/v1/checkout.js';
let loading = null;

export function loadRazorpay() {
  if (window.Razorpay) return Promise.resolve(true);
  if (loading) return loading;
  loading = new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = SCRIPT;
    s.async = true;
    s.onload = () => resolve(true);
    s.onerror = () => { loading = null; reject(new Error('The payment window could not load. Check your connection and try again.')); };
    document.body.appendChild(s);
  });
  return loading;
}

export async function openCheckout(payment) {
  await loadRazorpay();
  return new Promise((resolve) => {
    let settled = false;
    const done = (v) => { if (!settled) { settled = true; resolve(v); } };
    const rzp = new window.Razorpay({
      key: payment.key,
      amount: payment.amount,
      currency: payment.currency,
      name: payment.name,
      description: payment.description,
      image: payment.image || undefined,
      order_id: payment.razorpay_order_id,
      prefill: payment.prefill,
      theme: payment.theme,
      handler: (response) => done({ status: 'paid', response }),
      modal: { ondismiss: () => done({ status: 'dismissed' }), confirm_close: true },
    });
    rzp.on('payment.failed', (r) => done({ status: 'failed', error: r?.error?.description || 'The payment did not go through.' }));
    rzp.open();
  });
}
