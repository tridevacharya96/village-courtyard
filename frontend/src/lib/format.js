/** ₹1,234.50 in Indian digit grouping. Whole rupees drop the paise unless `always`. */
export function money(value, { always = false } = {}) {
  const n = Number(value || 0);
  const whole = Number.isInteger(n) && !always;
  return '₹' + n.toLocaleString('en-IN', { minimumFractionDigits: whole ? 0 : 2, maximumFractionDigits: 2 });
}

export const FOOD_TYPES = {
  veg: { label: 'Veg', short: 'Veg' },
  non_veg: { label: 'Non-veg', short: 'Non-veg' },
  egg: { label: 'Contains egg', short: 'Egg' },
};

/** "2026-10-04" → "Sun, 4 Oct" */
export function niceDate(iso, opts = { weekday: 'short', day: 'numeric', month: 'short' }) {
  if (!iso) return '';
  const [y, m, d] = iso.split('-').map(Number);
  return new Date(y, m - 1, d).toLocaleDateString('en-IN', opts);
}

/** "19:30" → "7:30 PM" */
export function niceTime(hhmm) {
  if (!hhmm) return '';
  const [h, m] = hhmm.split(':').map(Number);
  return `${((h + 11) % 12) + 1}:${String(m).padStart(2, '0')} ${h < 12 ? 'AM' : 'PM'}`;
}

export function todayIso(offsetDays = 0) {
  const d = new Date();
  d.setDate(d.getDate() + offsetDays);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** Internal links ("/menu") go through the router; anything else is external. */
export const isInternal = (href) => typeof href === 'string' && href.startsWith('/') && !href.startsWith('//');
