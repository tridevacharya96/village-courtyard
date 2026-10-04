import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { api } from '../services/api';
import { useSeo } from '../hooks/useSeo';
import { useCart } from '../context/CartContext';
import { useSettings } from '../context/SettingsContext';
import { useToast } from '../context/ToastContext';
import { PageBanner } from '../components/Bits';
import { CouponField, Totals } from '../components/CartDrawer';
import Img from '../components/Img';
import { money } from '../lib/format';
import { rules, validate, focusFirstError } from '../lib/validation';
import { openCheckout } from '../lib/razorpay';
import { rememberOrder, savedCustomer, saveCustomer } from '../lib/orderMemory';

const TYPES = [
  ['delivery', 'bi-bicycle', 'Delivery', 'To your door'],
  ['takeaway', 'bi-bag', 'Takeaway', 'Collect from us'],
  ['dine_in', 'bi-cup-hot', 'Dine-in', "I'm at a table"],
];

/** Label + control + error/help. Defined at module level so inputs keep focus while typing. */
function Field({ name, label, children, help, error }) {
  return (
    <div className="mb-3">
      <label className="form-label" htmlFor={`co_${name}`}>{label}</label>
      {children}
      {error ? <div className="invalid-feedback d-block" id={`co_${name}_err`}>{error}</div> : help && <div className="form-text">{help}</div>}
    </div>
  );
}

export default function Checkout() {
  useSeo('Checkout');
  const { lines, pricing, pricingState, orderType, setOrderType, coupon, clear } = useCart();
  const { ordering } = useSettings();
  const toast = useToast();
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const formRef = useRef(null);

  const saved = useMemo(() => savedCustomer(), []);
  const tableFromQr = (params.get('table') || '').toUpperCase();
  const [f, setF] = useState({
    name: saved.name || '', phone: saved.phone || '', email: saved.email || '',
    delivery_address: saved.delivery_address || '', notes: '', table_number: tableFromQr,
    payment_method: ordering.razorpay_enabled ? 'razorpay' : 'cod', website: '',
  });
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);
  const [tables, setTables] = useState([]);

  // A table QR code (…/checkout?table=T4) switches to dine-in
  useEffect(() => { if (tableFromQr) setOrderType('dine_in'); }, [tableFromQr, setOrderType]);
  useEffect(() => { if (orderType === 'dine_in' && !tables.length) api.tables().then((d) => setTables(d.tables)).catch(() => {}); }, [orderType, tables.length]);
  useEffect(() => {
    if (!ordering.razorpay_enabled && f.payment_method === 'razorpay') setF((x) => ({ ...x, payment_method: 'cod' }));
  }, [ordering.razorpay_enabled, f.payment_method]);

  // Editing a field clears its error straight away
  const set = (k) => (e) => {
    setF((x) => ({ ...x, [k]: e.target.value }));
    setErrors((er) => (er[k] ? { ...er, [k]: undefined } : er));
  };
  const cashLabel = orderType === 'delivery' ? 'Cash on delivery' : orderType === 'dine_in' ? 'Pay at the table' : 'Pay at the counter';

  if (!lines.length && !busy) {
    return (
      <>
        <PageBanner eyebrow="Checkout" title="Your order is empty" />
        <div className="state-box"><p>Add a few dishes first.</p><Link to="/menu" className="btn btn-forest">Browse the menu</Link></div>
      </>
    );
  }
  if (!ordering.enabled) {
    return <><PageBanner eyebrow="Checkout" title="Ordering is paused" /><div className="state-box"><p>We are not taking online orders right now. Please call us.</p></div></>;
  }

  async function submit(e) {
    e.preventDefault();
    const schema = { name: [rules.name], phone: [rules.phone], email: [rules.optionalEmail] };
    if (orderType === 'delivery') schema.delivery_address = [rules.minLength(10, 'Please enter your full delivery address.')];
    if (orderType === 'dine_in') schema.table_number = [(v) => (v ? null : 'Choose your table number.')];
    const errs = validate(f, schema);
    setErrors(errs);
    if (Object.keys(errs).length) { focusFirstError(formRef.current, errs); return; }

    setBusy(true);
    saveCustomer({ name: f.name, phone: f.phone, email: f.email, delivery_address: f.delivery_address });
    let result;
    try {
      result = await api.placeOrder({
        ...f, order_type: orderType, coupon_code: coupon,
        items: lines.map((l) => ({ id: l.id, qty: l.qty })),
      });
    } catch (ex) {
      setBusy(false);
      setErrors(ex.errors || {});
      toast(ex.message, 'danger', 7000);
      if (ex.errors) focusFirstError(formRef.current, ex.errors);
      return;
    }

    const { order, payment } = result;
    rememberOrder(order.order_number, f.phone);
    clear();

    if (!payment) {
      navigate(`/order/${order.order_number}`, { replace: true, state: { justPlaced: true } });
      return;
    }
    try {
      const outcome = await openCheckout(payment);
      if (outcome.status === 'paid') {
        await api.verifyPayment({ order_number: order.order_number, ...outcome.response });
        navigate(`/order/${order.order_number}`, { replace: true, state: { justPlaced: true } });
        return;
      }
      if (outcome.status === 'failed') toast(outcome.error, 'danger', 7000);
      navigate(`/order/${order.order_number}`, { replace: true, state: { paymentPending: true } });
    } catch (ex) {
      toast(ex.message, 'danger', 7000);
      navigate(`/order/${order.order_number}`, { replace: true, state: { paymentPending: true } });
    }
  }

  const inputProps = (name) => ({ id: `co_${name}`, name, value: f[name], onChange: set(name), className: `form-control${errors[name] ? ' is-invalid' : ''}`, 'aria-invalid': !!errors[name], 'aria-describedby': errors[name] ? `co_${name}_err` : undefined });
  const belowMin = pricing && !pricing.meets_minimum;

  return (
    <>
      <PageBanner eyebrow="Checkout" title="Almost there" />
      <section className="section">
        <div className="container">
          <form ref={formRef} onSubmit={submit} noValidate className="row g-4">
            <div className="col-lg-7">
              <div className="panel">
                <h2>How would you like it?</h2>
                <div className="choice-grid" role="radiogroup" aria-label="Order type">
                  {TYPES.map(([v, icon, label, sub]) => (
                    <div className="choice" key={v}>
                      <input type="radio" name="order_type" id={`type_${v}`} value={v} checked={orderType === v} onChange={() => setOrderType(v)} />
                      <label htmlFor={`type_${v}`}><i className={`bi ${icon}`} aria-hidden="true" /><b>{label}</b><small>{sub}</small></label>
                    </div>
                  ))}
                </div>
                {orderType === 'dine_in' && (
                  <div className="mt-3">
                    <Field name="table_number" error={errors.table_number} label="Your table">
                      <select {...inputProps('table_number')} className={`form-select${errors.table_number ? ' is-invalid' : ''}`}>
                        <option value="">Choose your table</option>
                        {tables.map((t) => <option key={t.table_number} value={t.table_number}>{t.table_number} · {t.location}</option>)}
                        {tableFromQr && !tables.length && <option value={tableFromQr}>{tableFromQr}</option>}
                      </select>
                    </Field>
                  </div>
                )}
              </div>

              <div className="panel">
                <h2>Your details</h2>
                <div className="row g-3">
                  <div className="col-sm-6"><Field name="name" error={errors.name} label="Name"><input {...inputProps('name')} autoComplete="name" /></Field></div>
                  <div className="col-sm-6"><Field name="phone" error={errors.phone} label="Phone" help="We call this number if anything changes."><input {...inputProps('phone')} type="tel" autoComplete="tel" inputMode="tel" /></Field></div>
                  <div className="col-12"><Field name="email" error={errors.email} label="Email (optional)" help="For your receipt."><input {...inputProps('email')} type="email" autoComplete="email" /></Field></div>
                  {orderType === 'delivery' && (
                    <div className="col-12"><Field name="delivery_address" error={errors.delivery_address} label="Delivery address" help="House or flat number, street, area and landmark.">
                      <textarea {...inputProps('delivery_address')} rows={3} autoComplete="street-address" /></Field></div>
                  )}
                  <div className="col-12"><Field name="notes" error={errors.notes} label="Notes for the kitchen (optional)"><input {...inputProps('notes')} maxLength={500} placeholder="Less spicy, no onion, birthday candle…" /></Field></div>
                </div>
                <input className="hp-field" tabIndex={-1} autoComplete="off" aria-hidden="true" name="website" value={f.website} onChange={set('website')} />
              </div>

              <div className="panel">
                <h2>Payment</h2>
                <div className="choice-grid" role="radiogroup" aria-label="Payment method">
                  <div className="choice">
                    <input type="radio" name="payment_method" id="pay_rzp" value="razorpay" checked={f.payment_method === 'razorpay'} disabled={!ordering.razorpay_enabled} onChange={set('payment_method')} />
                    <label htmlFor="pay_rzp"><i className="bi bi-credit-card" aria-hidden="true" /><b>Pay online</b><small>{ordering.razorpay_enabled ? 'UPI, cards, net banking, wallets' : 'Not available right now'}</small></label>
                  </div>
                  <div className="choice">
                    <input type="radio" name="payment_method" id="pay_cod" value="cod" checked={f.payment_method === 'cod'} disabled={!ordering.cod_enabled} onChange={set('payment_method')} />
                    <label htmlFor="pay_cod"><i className="bi bi-cash-coin" aria-hidden="true" /><b>{cashLabel}</b><small>{ordering.cod_enabled ? 'Cash or UPI to our staff' : 'Not available right now'}</small></label>
                  </div>
                </div>
                {errors.payment_method && <div className="invalid-feedback d-block">{errors.payment_method}</div>}
                {errors.items && <div className="alert alert-warning mt-3 mb-0">{errors.items}</div>}
              </div>
            </div>

            <div className="col-lg-5">
              <div className="panel sticky-summary">
                <h2>Order summary</h2>
                {lines.map((l) => (
                  <div className="cart-line" key={l.id}>
                    <Img src={l.image} alt="" />
                    <div className="min-w-0"><b>{l.name}</b><small className="tabular">{l.qty} × {money(l.price)}</small></div>
                    <span className="tabular fw-bold">{money(l.qty * l.price)}</span>
                  </div>
                ))}
                <div className="my-3"><CouponField /></div>
                <div aria-live="polite" style={{ opacity: pricingState === 'loading' ? 0.55 : 1 }}><Totals pricing={pricing} orderType={orderType} /></div>
                {belowMin && <div className="small note-bad mt-2">The minimum order is {money(ordering.min_order_amount)}. <Link to="/menu">Add more dishes</Link>.</div>}
                <button type="submit" id="placeOrder" className="btn btn-gold w-100 mt-4" disabled={busy || !pricing || belowMin || pricingState === 'loading'}>
                  {busy ? 'Placing your order…' : f.payment_method === 'razorpay' ? `Pay ${pricing ? money(pricing.total, { always: true }) : ''}` : `Place order · ${pricing ? money(pricing.total, { always: true }) : ''}`}
                </button>
                <p className="small text-muted mt-3 mb-0"><i className="bi bi-shield-lock" /> Prices are checked again by the restaurant when you place the order. Online payments are handled by Razorpay; we never see your card details.</p>
              </div>
            </div>
          </form>
        </div>
      </section>
    </>
  );
}
