/**
 * Thin client for the Core PHP API (backend/api/*.php).
 * Every call resolves to the `data` part of { success, data, message }.
 * Failures throw ApiError with the server's message and per-field errors.
 */
export const API_URL = (import.meta.env.VITE_API_URL || 'http://localhost/village-courtyard/backend/api').replace(/\/$/, '');

export class ApiError extends Error {
  constructor(message, status = 0, errors = {}, extra = {}) {
    super(message);
    this.status = status;
    this.errors = errors;
    this.maintenance = !!extra.maintenance;
  }
}

async function request(path, { method = 'GET', body, signal, query } = {}) {
  let url = `${API_URL}/${path}`;
  if (query) {
    const qs = new URLSearchParams(Object.entries(query).filter(([, v]) => v !== undefined && v !== null && v !== ''));
    if ([...qs].length) url += `?${qs}`;
  }
  let res;
  try {
    res = await fetch(url, {
      method,
      signal,
      headers: body ? { 'Content-Type': 'application/json', Accept: 'application/json' } : { Accept: 'application/json' },
      body: body ? JSON.stringify(body) : undefined,
    });
  } catch (e) {
    if (e.name === 'AbortError') throw e;
    throw new ApiError('We could not reach the restaurant. Check your connection and try again.', 0);
  }
  let json = null;
  try { json = await res.json(); } catch { /* empty or non-JSON body */ }

  if (!res.ok || (json && json.success === false)) {
    const err = new ApiError(json?.message || 'Something went wrong. Please try again.', res.status, json?.errors || {}, json || {});
    if (err.maintenance) window.dispatchEvent(new CustomEvent('vc:maintenance', { detail: err.message }));
    throw err;
  }
  return json?.data ?? null;
}

export const api = {
  settings: (o) => request('settings.php', o),
  homepage: (o) => request('homepage.php', o),
  categories: (o) => request('categories.php', o),
  menu: (query, o) => request('menu.php', { ...o, query }),
  gallery: (category, o) => request('gallery.php', { ...o, query: { category } }),
  testimonials: (o) => request('testimonials.php', o),
  page: (slug, o) => request('pages.php', { ...o, query: { slug } }),
  tables: (o) => request('tables.php', o),
  priceCart: (body, o) => request('cart.php', { ...o, method: 'POST', body }),
  placeOrder: (body) => request('orders.php', { method: 'POST', body }),
  verifyPayment: (body) => request('payment-verify.php', { method: 'POST', body }),
  retryPayment: (body) => request('payment-retry.php', { method: 'POST', body }),
  trackOrder: (body, o) => request('track-order.php', { ...o, method: 'POST', body }),
  availability: (query, o) => request('reservations.php', { ...o, query }),
  reserve: (body) => request('reservations.php', { method: 'POST', body }),
  contact: (body) => request('contact.php', { method: 'POST', body }),
  subscribe: (body) => request('newsletter.php', { method: 'POST', body }),
  trackView: (body) => request('track.php', { method: 'POST', body }).catch(() => {}),
};
