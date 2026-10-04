import { useEffect, useRef, useState } from 'react';
import { api } from '../services/api';
import { useSeo } from '../hooks/useSeo';
import { useSettings } from '../context/SettingsContext';
import { PageBanner } from '../components/Bits';
import { niceDate, niceTime, todayIso } from '../lib/format';
import { focusFirstError, rules, validate } from '../lib/validation';

export default function Reservations() {
  useSeo('Reserve a table', 'Book a courtyard, indoor or terrace table at Village Courtyard.');
  const { reservations: cfg, s } = useSettings();
  const formRef = useRef(null);
  const [f, setF] = useState({ date: todayIso(), guests: 2, table_preference: 'Any', time: '', name: '', phone: '', email: '', special_requests: '', website: '' });
  const [slots, setSlots] = useState({ state: 'loading', list: [] });
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState(null);
  const [slotTick, setSlotTick] = useState(0);   // bump to re-check times

  // Available times for the chosen date / party size / area
  useEffect(() => {
    const ctrl = new AbortController();
    setSlots((x) => ({ ...x, state: 'loading' }));
    api.availability({ date: f.date, guests: f.guests, location: f.table_preference }, { signal: ctrl.signal })
      .then((d) => {
        setSlots({ state: 'ready', list: d.slots });
        setF((x) => (d.slots.some((sl) => sl.time === x.time && sl.available) ? x : { ...x, time: '' }));
      })
      .catch((e) => { if (e.name !== 'AbortError') setSlots({ state: 'error', list: [], message: e.errors?.date || e.message }); });
    return () => ctrl.abort();
  }, [f.date, f.guests, f.table_preference, slotTick]);

  // Editing a field clears its error straight away
  const set = (k) => (e) => {
    setF((x) => ({ ...x, [k]: e.target.value }));
    setErrors((er) => (er[k] ? { ...er, [k]: undefined } : er));
  };

  async function submit(e) {
    e.preventDefault();
    const errs = validate(f, { time: [(v) => (v ? null : 'Pick a time.')], name: [rules.name], phone: [rules.phone], email: [rules.optionalEmail] });
    setErrors(errs);
    if (Object.keys(errs).length) { focusFirstError(formRef.current, errs); return; }
    setBusy(true);
    try {
      const { reservation } = await api.reserve(f);
      setDone(reservation);
      window.scrollTo({ top: 0 });
    } catch (ex) {
      setErrors({ form: ex.message, ...(ex.errors || {}) });
      if (ex.status === 409) setSlotTick((n) => n + 1);   // that time just filled up: refresh the slots
    } finally { setBusy(false); }
  }

  if (done) {
    return (
      <>
        <PageBanner eyebrow="Reservations" title="Request received" />
        <section className="section">
          <div className="container">
            <div className="panel mx-auto text-center" style={{ maxWidth: 560 }}>
              <i className="bi bi-calendar2-check display-4" style={{ color: 'var(--vc-green)' }} aria-hidden="true" />
              <h2 className="mt-3">Thank you, {done.name.split(' ')[0]}</h2>
              <p>We have your request for <b>{done.guests} guests</b> on <b>{niceDate(done.date, { weekday: 'long', day: 'numeric', month: 'long' })}</b> at <b>{niceTime(done.time)}</b>.</p>
              <p className="mb-1">Reference <b className="tabular">{done.reference}</b></p>
              <p className="text-muted small">We will call you to confirm. Need to change something? Call {s.phone}.</p>
            </div>
          </div>
        </section>
      </>
    );
  }

  const fieldClass = (k, base = 'form-control') => `${base}${errors[k] ? ' is-invalid' : ''}`;
  return (
    <>
      <PageBanner eyebrow="Reservations" title="Reserve a table" intro="Pick a date and party size to see open times. Tables are held for two hours." />
      <section className="section">
        <div className="container">
          <form ref={formRef} onSubmit={submit} noValidate className="row g-4">
            <div className="col-lg-7">
              <div className="panel">
                <h2>When</h2>
                <div className="row g-3">
                  <div className="col-sm-5">
                    <label className="form-label" htmlFor="r_date">Date</label>
                    <input id="r_date" name="date" type="date" className={fieldClass('date')} min={todayIso()} max={todayIso(cfg.max_days_ahead)} value={f.date} onChange={set('date')} required />
                    {errors.date && <div className="invalid-feedback">{errors.date}</div>}
                  </div>
                  <div className="col-6 col-sm-3">
                    <label className="form-label" htmlFor="r_guests">Guests</label>
                    <select id="r_guests" name="guests" className="form-select" value={f.guests} onChange={(e) => setF({ ...f, guests: Number(e.target.value) })}>
                      {Array.from({ length: cfg.max_guests }, (_, i) => i + 1).map((n) => <option key={n} value={n}>{n}</option>)}
                    </select>
                  </div>
                  <div className="col-6 col-sm-4">
                    <label className="form-label" htmlFor="r_area">Seating</label>
                    <select id="r_area" name="table_preference" className="form-select" value={f.table_preference} onChange={set('table_preference')}>
                      <option value="Any">No preference</option>
                      {cfg.locations.map((l) => <option key={l} value={l}>{l}</option>)}
                    </select>
                  </div>
                </div>
                <fieldset className="mt-4">
                  <legend className="form-label fs-6">Time {f.date && <span className="text-muted fw-normal">· {niceDate(f.date, { weekday: 'long', day: 'numeric', month: 'long' })}</span>}</legend>
                  {slots.state === 'loading' && <div className="skeleton" style={{ height: 96 }} aria-label="Loading times" />}
                  {slots.state === 'error' && <div className="note-bad">{slots.message}</div>}
                  {slots.state === 'ready' && !slots.list.some((x) => x.available) && (
                    <p className="note-bad mb-0">No tables left for {f.guests} on this day{f.table_preference !== 'Any' ? ` in the ${f.table_preference.toLowerCase()}` : ''}. Try another date, area or a smaller party, or call {s.phone}.</p>
                  )}
                  {slots.state === 'ready' && slots.list.some((x) => x.available) && (
                    <div className="slot-grid" role="radiogroup" aria-label="Available times">
                      {slots.list.map((sl) => (
                        <button key={sl.time} type="button" className="slot" name="time" disabled={!sl.available} aria-pressed={f.time === sl.time}
                          aria-label={`${niceTime(sl.time)}${sl.available ? '' : ', fully booked'}`} onClick={() => setF({ ...f, time: sl.time })}>{niceTime(sl.time)}</button>
                      ))}
                    </div>
                  )}
                  {errors.time && <div className="invalid-feedback d-block">{errors.time}</div>}
                </fieldset>
              </div>
            </div>
            <div className="col-lg-5">
              <div className="panel sticky-summary">
                <h2>Your details</h2>
                {errors.form && <div className="alert alert-warning py-2" role="alert">{errors.form}</div>}
                {[['name', 'Name', 'text', 'name'], ['phone', 'Phone', 'tel', 'tel'], ['email', 'Email (optional)', 'email', 'email']].map(([k, label, type, ac]) => (
                  <div className="mb-3" key={k}>
                    <label className="form-label" htmlFor={`r_${k}`}>{label}</label>
                    <input id={`r_${k}`} name={k} type={type} autoComplete={ac} className={fieldClass(k)} value={f[k]} onChange={set(k)} />
                    {errors[k] && <div className="invalid-feedback">{errors[k]}</div>}
                  </div>
                ))}
                <div className="mb-4">
                  <label className="form-label" htmlFor="r_req">Special requests (optional)</label>
                  <textarea id="r_req" name="special_requests" rows={3} maxLength={500} className="form-control" value={f.special_requests} onChange={set('special_requests')} placeholder="Birthday, high chair, wheelchair access…" />
                </div>
                <input className="hp-field" tabIndex={-1} autoComplete="off" aria-hidden="true" name="website" value={f.website} onChange={set('website')} />
                <button type="submit" className="btn btn-gold w-100" disabled={busy || !cfg.enabled}>
                  {busy ? 'Sending…' : f.time ? `Request ${niceTime(f.time)} for ${f.guests}` : 'Request a table'}
                </button>
                {!cfg.enabled && <p className="small note-bad mt-2 mb-0">Online booking is closed right now. Please call {s.phone}.</p>}
              </div>
            </div>
          </form>
        </div>
      </section>
    </>
  );
}
