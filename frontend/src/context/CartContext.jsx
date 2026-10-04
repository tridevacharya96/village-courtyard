import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { api } from '../services/api';
import { useToast } from './ToastContext';

/**
 * Cart: lines saved in the browser so a refresh keeps the order.
 * Prices shown before checkout come from /api/cart.php (server-side pricing);
 * the cart only remembers ids and quantities as the source of truth.
 */
const CartContext = createContext(null);
const STORAGE_KEY = 'vc_cart_v1';
const MAX_QTY = 50;

const load = () => {
  try {
    const raw = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
    return { lines: Array.isArray(raw.lines) ? raw.lines : [], coupon: raw.coupon || '', orderType: raw.orderType || 'delivery' };
  } catch { return { lines: [], coupon: '', orderType: 'delivery' }; }
};

export function CartProvider({ children }) {
  const toast = useToast();
  const initial = useRef(load());
  const [lines, setLines] = useState(initial.current.lines);      // [{id, qty, name, price, image, food_type}]
  const [coupon, setCoupon] = useState(initial.current.coupon);
  const [orderType, setOrderType] = useState(initial.current.orderType);
  const [pricing, setPricing] = useState(null);
  const [pricingState, setPricingState] = useState('idle');       // idle | loading | ready | error
  const drawerOpener = useRef(() => {});

  useEffect(() => {
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify({ lines, coupon, orderType })); } catch { /* storage blocked */ }
  }, [lines, coupon, orderType]);

  const add = useCallback((item, qty = 1) => {
    setLines((ls) => {
      const found = ls.find((l) => l.id === item.id);
      if (found) return ls.map((l) => (l.id === item.id ? { ...l, qty: Math.min(MAX_QTY, l.qty + qty) } : l));
      return [...ls, { id: item.id, qty, name: item.name, price: item.price, image: item.image, food_type: item.food_type }];
    });
    toast(`${item.name} added to your order`);
  }, [toast]);

  const setQty = useCallback((id, qty) => {
    setLines((ls) => (qty <= 0 ? ls.filter((l) => l.id !== id) : ls.map((l) => (l.id === id ? { ...l, qty: Math.min(MAX_QTY, qty) } : l))));
  }, []);

  const clear = useCallback(() => { setLines([]); setCoupon(''); setPricing(null); }, []);

  // Server pricing, debounced; drops dishes that are no longer available
  const reqId = useRef(0);
  useEffect(() => {
    if (!lines.length) { setPricing(null); setPricingState('idle'); return undefined; }
    const id = ++reqId.current;
    setPricingState('loading');
    const timer = setTimeout(() => {
      api.priceCart({ items: lines.map((l) => ({ id: l.id, qty: l.qty })), coupon_code: coupon, order_type: orderType })
        .then((p) => {
          if (id !== reqId.current) return;
          if (p.unavailable?.length) {
            const gone = new Set(p.unavailable.map((u) => u.id));
            const names = lines.filter((l) => gone.has(l.id)).map((l) => l.name).join(', ');
            setLines((ls) => ls.filter((l) => !gone.has(l.id)));
            toast(`${names} ${p.unavailable.length > 1 ? 'are' : 'is'} no longer available and was removed.`, 'info', 6000);
          }
          // Keep names/prices in sync with the menu. Only update state when
          // something changed, otherwise this effect would re-run forever.
          const stale = p.lines.some((s) => {
            const l = lines.find((x) => x.id === s.id);
            return l && (l.price !== s.unit_price || l.name !== s.name);
          });
          if (stale) {
            setLines((ls) => ls.map((l) => {
              const s = p.lines.find((x) => x.id === l.id);
              return s ? { ...l, price: s.unit_price, name: s.name } : l;
            }));
          }
          setPricing(p);
          setPricingState('ready');
        })
        .catch(() => { if (id === reqId.current) setPricingState('error'); });
    }, 250);
    return () => clearTimeout(timer);
  }, [lines, coupon, orderType, toast]);

  // Stable across renders, so the drawer's Bootstrap instance is created once
  const openCart = useCallback(() => drawerOpener.current(), []);
  const registerDrawer = useCallback((fn) => { drawerOpener.current = fn; }, []);

  const count = lines.reduce((n, l) => n + l.qty, 0);
  const localSubtotal = lines.reduce((n, l) => n + l.qty * l.price, 0);

  const value = useMemo(() => ({
    lines, count, localSubtotal, add, setQty, clear,
    coupon, setCoupon, orderType, setOrderType,
    pricing, pricingState,
    qtyOf: (id) => lines.find((l) => l.id === id)?.qty || 0,
    openCart, registerDrawer,
  }), [lines, count, localSubtotal, add, setQty, clear, coupon, orderType, pricing, pricingState, openCart, registerDrawer]);

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>;
}

export const useCart = () => useContext(CartContext);
