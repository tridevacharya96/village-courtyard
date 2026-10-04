import { useRef, useState } from 'react';
import { api } from '../services/api';
import { useSeo } from '../hooks/useSeo';
import { useSettings } from '../context/SettingsContext';
import { PageBanner } from '../components/Bits';
import { ContactList, HoursTable, MapFrame } from '../components/HoursBlock';
import { focusFirstError, rules, validate } from '../lib/validation';

export default function Contact() {
  useSeo('Contact', 'Address, phone, opening hours and a message form for Village Courtyard.');
  const { s } = useSettings();
  const formRef = useRef(null);
  const blank = { name: '', email: '', phone: '', subject: '', message: '', website: '' };
  const [f, setF] = useState(blank);
  const [errors, setErrors] = useState({});
  const [state, setState] = useState('idle');     // idle | sending | sent

  // Editing a field clears its error straight away
  const set = (k) => (e) => {
    setF((x) => ({ ...x, [k]: e.target.value }));
    setErrors((er) => (er[k] ? { ...er, [k]: undefined } : er));
  };

  async function submit(e) {
    e.preventDefault();
    const errs = validate(f, { name: [rules.name], email: [rules.email], phone: [(v) => (v ? rules.phone(v) : null)], message: [rules.minLength(10, 'Please write a little more (at least 10 characters).')] });
    setErrors(errs);
    if (Object.keys(errs).length) { focusFirstError(formRef.current, errs); return; }
    setState('sending');
    try {
      await api.contact(f);
      setState('sent');
      setF(blank);
    } catch (ex) {
      setErrors({ form: ex.message, ...(ex.errors || {}) });
      setState('idle');
    }
  }

  const input = (k, label, type = 'text', extra = {}) => (
    <div className="mb-3">
      <label className="form-label" htmlFor={`c_${k}`}>{label}</label>
      <input id={`c_${k}`} name={k} type={type} className={`form-control${errors[k] ? ' is-invalid' : ''}`} value={f[k]} onChange={set(k)} {...extra} />
      {errors[k] && <div className="invalid-feedback">{errors[k]}</div>}
    </div>
  );

  return (
    <>
      <PageBanner eyebrow="Contact" title="Come and find us" intro="For bookings, events or feedback. We usually reply within a day." />
      <section className="section">
        <div className="container">
          <div className="row g-4 g-lg-5">
            <div className="col-lg-5">
              <h2 className="display-6 mb-4">Visit</h2>
              <ContactList s={s} />
              <h3 className="h4 mt-5 mb-3">Opening hours</h3>
              <HoursTable hours={s.opening_hours || []} />
              <div className="mt-4"><MapFrame url={s.map_embed_url} /></div>
            </div>
            <div className="col-lg-7">
              <form ref={formRef} className="panel" onSubmit={submit} noValidate>
                <h2>Send us a message</h2>
                {state === 'sent' && <div className="alert alert-success" role="status"><i className="bi bi-check-circle me-1" />Thank you for writing to us. We usually reply within a day.</div>}
                {errors.form && <div className="alert alert-warning" role="alert">{errors.form}</div>}
                <div className="row g-0 gx-3">
                  <div className="col-sm-6">{input('name', 'Name', 'text', { autoComplete: 'name' })}</div>
                  <div className="col-sm-6">{input('email', 'Email', 'email', { autoComplete: 'email' })}</div>
                  <div className="col-sm-6">{input('phone', 'Phone (optional)', 'tel', { autoComplete: 'tel' })}</div>
                  <div className="col-sm-6">{input('subject', 'Subject (optional)', 'text', { maxLength: 150 })}</div>
                </div>
                <div className="mb-4">
                  <label className="form-label" htmlFor="c_message">Message</label>
                  <textarea id="c_message" name="message" rows={6} maxLength={3000} className={`form-control${errors.message ? ' is-invalid' : ''}`} value={f.message} onChange={set('message')} />
                  {errors.message && <div className="invalid-feedback">{errors.message}</div>}
                </div>
                <input className="hp-field" tabIndex={-1} autoComplete="off" aria-hidden="true" name="website" value={f.website} onChange={set('website')} />
                <button className="btn btn-forest" type="submit" disabled={state === 'sending'}>{state === 'sending' ? 'Sending…' : 'Send message'}</button>
              </form>
            </div>
          </div>
        </div>
      </section>
    </>
  );
}
