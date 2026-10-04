import { useCart } from '../context/CartContext';
import { useSettings } from '../context/SettingsContext';

/** "Add" button that turns into a − qty + stepper once the dish is in the cart. */
export default function AddToCart({ item, compact = false }) {
  const { qtyOf, add, setQty } = useCart();
  const { ordering } = useSettings();
  const qty = qtyOf(item.id);

  if (!ordering.enabled) return null;
  if (!qty) {
    return (
      <button type="button" className="btn btn-add" onClick={() => add(item)} aria-label={`Add ${item.name} to order`}>
        <i className="bi bi-plus-lg" aria-hidden="true" />{compact ? '' : ' Add'}
      </button>
    );
  }
  return (
    <div className="qty" role="group" aria-label={`${item.name} quantity`}>
      <button type="button" onClick={() => setQty(item.id, qty - 1)} aria-label={`Remove one ${item.name}`}>−</button>
      <output aria-live="polite">{qty}</output>
      <button type="button" onClick={() => setQty(item.id, qty + 1)} aria-label={`Add one more ${item.name}`}>+</button>
    </div>
  );
}
