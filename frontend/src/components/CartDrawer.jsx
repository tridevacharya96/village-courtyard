import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import Offcanvas from 'bootstrap/js/dist/offcanvas';
import Img from './Img';
import { FoodMark } from './Bits';
import { useCart } from '../context/CartContext';
import { useSettings } from '../context/SettingsContext';
import { money } from '../lib/format';

/** Totals block shared by the drawer and checkout. */
export function Totals({ pricing, orderType }) {
  if (!pricing) return null;
  const freeLeft = pricing.amount_for_free_delivery;
  return (
    <div className="totals">
      <div><span>Subtotal</span><span>{money(pricing.subtotal, { always: true })}</span></div>
      {pricing.discount > 0 && <div className="note-ok"><span>Discount ({pricing.coupon?.code})</span><span>−{money(pricing.discount, { always: true })}</span></div>}
      <div><span>GST ({pricing.tax_percent}%)</span><span>{money(pricing.tax_amount, { always: true })}</span></div>
      {orderType === 'delivery' && <div><span>Delivery</span><span>{pricing.delivery_charge ? money(pricing.delivery_charge, { always: true }) : 'Free'}</span></div>}
      <div className="grand"><span>Total</span><span>{money(pricing.total, { always: true })}</span></div>
      {orderType === 'delivery' && pricing.free_delivery_above > 0 && freeLeft > 0 && (
        <div className="d-block small muted">
          Add {money(freeLeft)} more for free delivery.
          <div className="free-delivery-bar" aria-hidden="true"><span style={{ width: `${Math.min(100, (1 - freeLeft / pricing.free_delivery_above) * 100)}%` }} /></div>
        </div>
      )}
    </div>
  );
}

export function CouponField() {
  const { coupon, setCoupon, pricing } = useCart();
  const [draft, setDraft] = useState(coupon);
  useEffect(() => setDraft(coupon), [coupon]);
  const applied = pricing?.coupon && !pricing.coupon_error;
  return (
    <div>
      {/* Not a <form>: this box also sits inside the checkout form, and forms can't nest */}
      <div className="d-flex gap-2" role="group" aria-label="Coupon">
        <label className="visually-hidden" htmlFor="couponCode">Coupon code</label>
        <input id="couponCode" className="form-control form-control-sm text-uppercase" placeholder="Coupon code" value={draft} maxLength={30}
          onChange={(e) => setDraft(e.target.value)}
          onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); setCoupon(draft.trim().toUpperCase()); } }} />
        {coupon && applied
          ? <button type="button" className="btn btn-sm btn-outline-forest" onClick={() => setCoupon('')}>Remove</button>
          : <button type="button" className="btn btn-sm btn-outline-forest" onClick={() => setCoupon(draft.trim().toUpperCase())}>Apply</button>}
      </div>
      {coupon && pricing?.coupon_error && <div className="small note-bad mt-1">{pricing.coupon_error}</div>}
      {applied && <div className="small note-ok mt-1"><i className="bi bi-check-circle" /> {pricing.coupon.code} applied{pricing.coupon.description ? `: ${pricing.coupon.description}` : ''}</div>}
    </div>
  );
}

export default function CartDrawer() {
  const { lines, count, setQty, pricing, pricingState, registerDrawer, orderType } = useCart();
  const { ordering } = useSettings();
  const ref = useRef(null);
  const navigate = useNavigate();

  useEffect(() => {
    const oc = Offcanvas.getOrCreateInstance(ref.current);
    registerDrawer(() => oc.show());
    return () => oc.dispose();
  }, [registerDrawer]);

  const goCheckout = () => { Offcanvas.getInstance(ref.current)?.hide(); navigate('/checkout'); };
  const goMenu = () => { Offcanvas.getInstance(ref.current)?.hide(); navigate('/menu'); };
  const belowMin = pricing && !pricing.meets_minimum;

  return (
    <div className="offcanvas offcanvas-end cart-drawer" tabIndex={-1} id="cartDrawer" aria-labelledby="cartTitle" ref={ref}>
      <div className="offcanvas-header">
        <h2 className="offcanvas-title" id="cartTitle">Your order</h2>
        <button type="button" className="btn-close" data-bs-dismiss="offcanvas" aria-label="Close" />
      </div>
      <div className="offcanvas-body d-flex flex-column">
        {!count ? (
          <div className="state-box my-auto">
            <i className="bi bi-bag" aria-hidden="true" />
            <p>Your order is empty.</p>
            <button type="button" className="btn btn-forest" onClick={goMenu}>Browse the menu</button>
          </div>
        ) : (
          <>
            <div className="flex-grow-1">
              {lines.map((l) => (
                <div className="cart-line" key={l.id}>
                  <Img src={l.image} alt="" />
                  <div className="min-w-0">
                    <b><FoodMark type={l.food_type} /> {l.name}</b>
                    <small className="tabular">{money(l.price)} each · {money(l.price * l.qty)}</small>
                  </div>
                  <div className="qty" role="group" aria-label={`${l.name} quantity`}>
                    <button type="button" onClick={() => setQty(l.id, l.qty - 1)} aria-label={l.qty === 1 ? `Remove ${l.name}` : `One less ${l.name}`}>{l.qty === 1 ? <i className="bi bi-trash3" /> : '−'}</button>
                    <output>{l.qty}</output>
                    <button type="button" onClick={() => setQty(l.id, l.qty + 1)} aria-label={`One more ${l.name}`}>+</button>
                  </div>
                </div>
              ))}
            </div>
            <div className="pt-3 d-grid gap-3">
              <CouponField />
              {pricingState === 'error' && <div className="small note-bad">Prices could not be updated. Check your connection.</div>}
              <div aria-live="polite" style={{ opacity: pricingState === 'loading' ? 0.55 : 1 }}>
                <Totals pricing={pricing} orderType={orderType} />
                {orderType === 'delivery' && <div className="small text-muted mt-1">Delivery charge shown for delivery orders; pick takeaway or dine-in at checkout.</div>}
              </div>
              {belowMin && <div className="small note-bad">The minimum order is {money(ordering.min_order_amount)}.</div>}
              <button type="button" className="btn btn-gold" onClick={goCheckout} disabled={!pricing || belowMin}>
                Checkout · {pricing ? money(pricing.total, { always: true }) : '…'}
              </button>
            </div>
          </>
        )}
      </div>
    </div>
  );
}
