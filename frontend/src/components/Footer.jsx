import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useSettings } from '../context/SettingsContext';
import { api } from '../services/api';
import { rules } from '../lib/validation';
import { useToast } from '../context/ToastContext';
import { HoursTable } from './HoursBlock';
import { pagePath } from './Header';

const SOCIAL = [['instagram', 'bi-instagram', 'Instagram'], ['facebook', 'bi-facebook', 'Facebook'], ['twitter', 'bi-twitter-x', 'X'], ['youtube', 'bi-youtube', 'YouTube']];

export default function Footer() {
  const { s, navPages } = useSettings();
  const toast = useToast();
  const [email, setEmail] = useState('');
  const [website, setWebsite] = useState('');      // honeypot
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  async function subscribe(e) {
    e.preventDefault();
    const err = rules.email(email);
    setError(err || '');
    if (err) return;
    setBusy(true);
    try {
      await api.subscribe({ email, website });
      toast('Thank you! You will hear about seasonal menus and offers.');
      setEmail('');
    } catch (ex) {
      setError(ex.errors?.email || ex.message);
    } finally { setBusy(false); }
  }

  return (
    <footer className="site-footer">
      <div className="container">
        <div className="row g-4 g-lg-5">
          <div className="col-lg-4">
            <h2>{s.site_name || 'Village Courtyard'}</h2>
            <p>{s.footer_about}</p>
            <div className="social d-flex gap-2 mt-3">
              {SOCIAL.filter(([k]) => s[k]).map(([k, icon, label]) => (
                <a key={k} href={s[k]} target="_blank" rel="noopener noreferrer" aria-label={label}><i className={`bi ${icon}`} aria-hidden="true" /></a>
              ))}
            </div>
          </div>
          <div className="col-12 col-sm-6 col-lg-2">
            <h2>Explore</h2>
            <ul>
              <li><Link to="/menu">Menu</Link></li>
              <li><Link to="/reservations">Reserve a table</Link></li>
              <li><Link to="/track">Track an order</Link></li>
              <li><Link to="/gallery">Gallery</Link></li>
              <li><Link to="/contact">Contact</Link></li>
              {navPages.filter((p) => p.slug !== 'about').map((p) => <li key={p.slug}><Link to={pagePath(p.slug)}>{p.title}</Link></li>)}
            </ul>
          </div>
          <div className="col-12 col-sm-6 col-lg-3">
            <h2>Hours</h2>
            <div className="small"><HoursTable hours={s.opening_hours || []} /></div>
            {s.phone && <p className="mt-3 mb-0"><a href={`tel:${s.phone.replace(/[^\d+]/g, '')}`}>{s.phone}</a></p>}
          </div>
          <div className="col-lg-3">
            <h2>Newsletter</h2>
            <p className="small">Seasonal menus and festival offers, about once a month.</p>
            <form onSubmit={subscribe} noValidate>
              <label className="visually-hidden" htmlFor="newsletterEmail">Email address</label>
              <div className="d-flex gap-2">
                <input id="newsletterEmail" className={`form-control${error ? ' is-invalid' : ''}`} type="email" placeholder="Your email" autoComplete="email"
                  value={email} onChange={(e) => setEmail(e.target.value)} aria-describedby={error ? 'newsletterError' : undefined} />
                <button className="btn btn-gold" type="submit" disabled={busy}>Join</button>
              </div>
              <input className="hp-field" tabIndex={-1} autoComplete="off" aria-hidden="true" name="website" value={website} onChange={(e) => setWebsite(e.target.value)} />
              {error && <div id="newsletterError" className="small mt-2" style={{ color: '#F3B6A0' }}>{error}</div>}
            </form>
          </div>
        </div>
        <div className="footer-bottom d-flex flex-wrap justify-content-between gap-2">
          <span>{s.copyright_text}</span>
          <span><Link to="/page/privacy-policy">Privacy</Link> · <Link to="/page/terms">Terms</Link></span>
        </div>
      </div>
    </footer>
  );
}
