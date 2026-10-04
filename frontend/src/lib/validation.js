/**
 * Client-side form checks (vanilla JS). These mirror the PHP rules so guests
 * see problems before submitting; the server re-checks everything.
 */
export const rules = {
  required: (v) => (String(v ?? '').trim() ? null : 'This field is required.'),
  name: (v) => (String(v ?? '').trim().length >= 2 ? null : 'Please enter your name.'),
  phone: (v) => (/^\+?[0-9][0-9\s-]{8,15}$/.test(String(v ?? '').trim()) ? null : 'Enter a valid phone number.'),
  email: (v) => (/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(String(v ?? '').trim()) ? null : 'Enter a valid email address.'),
  optionalEmail: (v) => (!String(v ?? '').trim() ? null : rules.email(v)),
  minLength: (n, msg) => (v) => (String(v ?? '').trim().length >= n ? null : msg || `Please write at least ${n} characters.`),
};

/**
 * validate({ name: 'Asha' }, { name: [rules.name] }) → { name: 'message' } for failures only.
 */
export function validate(values, schema) {
  const errors = {};
  for (const [field, checks] of Object.entries(schema)) {
    for (const check of checks) {
      const msg = check(values[field], values);
      if (msg) { errors[field] = msg; break; }
    }
  }
  return errors;
}

/** Focus the first invalid field so keyboard and screen-reader users land on it. */
export function focusFirstError(form, errors) {
  const first = Object.keys(errors)[0];
  if (!first || !form) return;
  const el = form.querySelector(`[name="${first}"]`);
  if (el) el.focus();
}
