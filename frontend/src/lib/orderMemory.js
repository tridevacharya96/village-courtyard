/**
 * Remembers which phone number placed which order, in this browser only,
 * so the order page can show live status without asking again.
 */
const KEY = 'vc_orders_v1';
const read = () => { try { return JSON.parse(localStorage.getItem(KEY) || '{}'); } catch { return {}; } };

export function rememberOrder(orderNumber, phone) {
  const all = read();
  all[orderNumber] = { phone, at: Date.now() };
  // keep the 10 most recent
  const recent = Object.entries(all).sort((a, b) => b[1].at - a[1].at).slice(0, 10);
  try { localStorage.setItem(KEY, JSON.stringify(Object.fromEntries(recent))); } catch { /* storage blocked */ }
}

export const phoneFor = (orderNumber) => read()[orderNumber]?.phone || '';

const DETAILS = 'vc_customer_v1';
export const savedCustomer = () => { try { return JSON.parse(localStorage.getItem(DETAILS) || '{}'); } catch { return {}; } };
export const saveCustomer = (c) => { try { localStorage.setItem(DETAILS, JSON.stringify(c)); } catch { /* storage blocked */ } };
