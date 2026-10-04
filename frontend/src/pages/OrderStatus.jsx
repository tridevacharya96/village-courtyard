import { useCallback, useEffect, useState } from 'react';
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom';
import { api } from '../services/api';
import { useSeo } from '../hooks/useSeo';
import { useToast } from '../context/ToastContext';
import { PageBanner, ErrorState } from '../components/Bits';
import { money } from '../lib/format';
import { rules, validate } from '../lib/validation';
import { openCheckout } from '../lib/razorpay';
import { phoneFor, rememberOrder } from '../lib/orderMemory';

const STEP_ICON = { pending: 'bi-receipt', confirmed: 'bi-check2', preparing: 'bi-fire', ready: 'bi-bell', out_for_delivery: 'bi-bicycle', served: 'bi-cup-hot', completed: 'bi-check2-all', cancelled: 'bi-x-lg' };
const POLL_MS = 20000;

/** Find an order by number + phone (used at /track and when the phone isn't known). */
function TrackForm({ initialNumber = '' }) {
  const navigate = useNavigate();
  const [f, setF] = useState({ order_number: initialNumber, phone: '' });
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);

  async function submit(e) {
    e.preventDefault();
    const errs = validate(f, { order_number: [rules.required], phone: [rules.phone] });
    setErrors(errs);
    if (Object.keys(errs).length) return;
    setBusy(true);
    try {
      const { order } = await api.trackOrder(f);
      rememberOrder(order.order_number, f.phone);
      navigate(`/order/${order.order_number}`, { replace: true });
    } catch (ex) {
      setErrors({ form: ex.message, ...(ex.errors || {}) });
    } finally { setBusy(false); }
  }

  return (
    <form className="panel mx-auto" style={{ maxWidth: 520 }} onSubmit={submit} noValidate>
      <h2>Find your order</h2>
      {errors.form && <div className="alert alert-warning py-2" role="alert">{errors.form}</div>}
      <div className="mb-3">
        <label className="form-label" htmlFor="t_number">Order number</label>
        <input id="t_number" className={`form-control text-uppercase${errors.order_number ? ' is-invalid' : ''}`} placeholder="VC-20261004-0003" value={f.order_number} onChange={(e) => setF({ ...f, order_number: e.target.value })} />
        {errors.order_number && <div className="invalid-feedback">{errors.order_number}</div>}
      </div>
      <div className="mb-4">
        <label className="form-label" htmlFor="t_phone">Phone number used for the order</label>
        <input id="t_phone" type="tel" className={`form-control${errors.phone ? ' is-invalid' : ''}`} value={f.phone} onChange={(e) => setF({ ...f, phone: e.target.value })} autoComplete="tel" />
        {errors.phone && <div className="invalid-feedback">{errors.phone}</div>}
      </div>
      <button className="btn btn-forest w-100" type="submit" disabled={busy}>{busy ? 'Looking…' : 'Show my order'}</button>
    </form>
  );
}

export function TrackOrder() {
  useSeo('Track your order');
  return (
    <>
      <PageBanner eyebrow="Orders" title="Track your order" intro="Enter the order number from your confirmation and the phone number you used." />
      <section className="section"><div className="container"><TrackForm /></div></section>
    </>
  );
}

export default function OrderStatus() {
  const { number } = useParams();
  const location = useLocation();
  const toast = useToast();
  const phone = phoneFor(number);
  const [order, setOrder] = useState(null);
  const [error, setError] = useState(null);
  const [paying, setPaying] = useState(false);
  useSeo(`Order ${number}`);

  const load = useCallback(() => {
    if (!phone) return Promise.resolve();
    return api.trackOrder({ order_number: number, phone }).then((d) => { setOrder(d.order); setError(null); }).catch(setError);
  }, [number, phone]);

  useEffect(() => { load(); }, [load]);
  // Live status: refresh while the order is still moving and the tab is visible
  useEffect(() => {
    if (!order || ['completed', 'cancelled'].includes(order.status)) return undefined;
    const t = setInterval(() => { if (document.visibilityState === 'visible') load(); }, POLL_MS);
    return () => clearInterval(t);
  }, [order, load]);

  async function payNow() {
    setPaying(true);
    try {
      const { payment } = await api.retryPayment({ order_number: number, phone });
      if (payment) {
        const outcome = await openCheckout(payment);
        if (outcome.status === 'paid') {
          await api.verifyPayment({ order_number: number, ...outcome.response });
          toast('Payment received. Your order is confirmed!');
        } else if (outcome.status === 'failed') {
          toast(outcome.error, 'danger', 7000);
        }
      }
      await load();
    } catch (ex) {
      toast(ex.message, 'danger', 7000);
    } finally { setPaying(false); }
  }

  if (!phone) {
    return (
      <>
        <PageBanner eyebrow="Orders" title={`Order ${number}`} />
        <section className="section"><div className="container"><TrackForm initialNumber={number} /></div></section>
      </>
    );
  }
  if (error) return <><PageBanner eyebrow="Orders" title={`Order ${number}`} /><ErrorState error={error} onRetry={load} title="We couldn't load this order" /></>;
  if (!order) return <><PageBanner eyebrow="Orders" title={`Order ${number}`} /><div className="container section"><div className="skeleton" style={{ height: 260 }} /></div></>;

  const needsPayment = order.payment_method === 'razorpay' && ['pending', 'failed'].includes(order.payment_status) && order.status !== 'cancelled';
  const justPlaced = location.state?.justPlaced;
  const pill = order.status === 'cancelled' ? 'bad' : needsPayment ? 'warn' : '';

  return (
    <>
      <PageBanner eyebrow={justPlaced ? 'Thank you!' : 'Your order'} title={justPlaced && !needsPayment ? 'Order received' : `Order ${order.order_number}`}
        intro={justPlaced && !needsPayment ? `Thank you, ${order.customer_name.split(' ')[0]}. We have your order ${order.order_number}. This page updates by itself.` : undefined} />
      <section className="section">
        <div className="container">
          <div className="row g-4">
            <div className="col-lg-7">
              {needsPayment && (
                <div className="panel" style={{ borderColor: 'var(--vc-terra)' }} role="alert">
                  <h2 className="h3">Payment not completed</h2>
                  <p>Your order is saved but hasn't been sent to the kitchen yet. Complete the payment of <b>{money(order.total, { always: true })}</b> to confirm it.</p>
                  <button type="button" className="btn btn-gold" onClick={payNow} disabled={paying}>{paying ? 'Opening payment…' : 'Pay now'}</button>
                </div>
              )}
              <div className="panel">
                <div className="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                  <h2 className="mb-0">Status</h2>
                  <span className={`status-pill ${pill}`} aria-live="polite"><i className={`bi ${STEP_ICON[order.status] || 'bi-circle'}`} aria-hidden="true" />{needsPayment ? 'Awaiting payment' : order.status_label}</span>
                </div>
                <ol className="steps" aria-label="Order progress">
                  {order.steps.map((st) => (
                    <li key={st.key} className={st.state}>
                      <span className="dot" aria-hidden="true"><i className={`bi ${st.state === 'done' ? 'bi-check-lg' : STEP_ICON[st.key]}`} /></span>
                      <span><b>{st.label}</b><small>{st.state === 'current' ? 'Now' : st.state === 'done' ? 'Done' : ''}</small></span>
                    </li>
                  ))}
                </ol>
                {!['completed', 'cancelled'].includes(order.status) && <p className="small text-muted mt-3 mb-0"><i className="bi bi-arrow-repeat" /> Updates automatically every 20 seconds.</p>}
              </div>
            </div>
            <div className="col-lg-5">
              <div className="panel">
                <h2>Details</h2>
                <dl className="contact-list mb-3">
                  <dt>Order</dt><dd className="tabular">{order.order_number}</dd>
                  <dt>Type</dt><dd>{{ delivery: 'Delivery', takeaway: 'Takeaway', dine_in: `Dine-in · table ${order.table_number}` }[order.order_type]}</dd>
                  {order.delivery_address && <><dt>To</dt><dd>{order.delivery_address}</dd></>}
                  <dt>Payment</dt><dd>{order.payment_method === 'cod' ? 'Pay on delivery / at the counter' : 'Online'} · {order.payment_status === 'paid' ? 'Paid' : order.payment_status === 'refunded' ? 'Refunded' : 'Not paid yet'}</dd>
                </dl>
                {order.items.map((it) => (
                  <div className="d-flex justify-content-between gap-3 py-1" key={it.name}><span>{it.quantity} × {it.name}</span><span className="tabular">{money(it.line_total)}</span></div>
                ))}
                <div className="totals mt-3">
                  <div className="muted"><span>Subtotal</span><span>{money(order.subtotal, { always: true })}</span></div>
                  {order.discount > 0 && <div className="note-ok"><span>Discount</span><span>−{money(order.discount, { always: true })}</span></div>}
                  <div className="muted"><span>GST ({order.tax_percent}%)</span><span>{money(order.tax_amount, { always: true })}</span></div>
                  {order.delivery_charge > 0 && <div className="muted"><span>Delivery</span><span>{money(order.delivery_charge, { always: true })}</span></div>}
                  <div className="grand"><span>Total</span><span>{money(order.total, { always: true })}</span></div>
                </div>
              </div>
              <p className="small text-muted mt-3">Questions about this order? Call us and mention <b>{order.order_number}</b>. <Link to="/menu">Order something else</Link></p>
            </div>
          </div>
        </div>
      </section>
    </>
  );
}
