import { useEffect } from 'react';
import { Route, Routes, useLocation } from 'react-router-dom';
import { useSettings } from './context/SettingsContext';
import { api } from './services/api';
import { initEffects, refreshReveal, scrollToTop } from './lib/effects';
import Header from './components/Header';
import Footer from './components/Footer';
import CartDrawer from './components/CartDrawer';
import { LogoMark } from './components/Logo';
import Home from './pages/Home';
import Menu from './pages/Menu';
import About from './pages/About';
import Gallery from './pages/Gallery';
import Reservations from './pages/Reservations';
import Contact from './pages/Contact';
import Checkout from './pages/Checkout';
import OrderStatus, { TrackOrder } from './pages/OrderStatus';
import CmsPage from './pages/CmsPage';
import NotFound from './pages/NotFound';

let effectsStarted = false;

/** Scroll to top, refresh scroll-reveal and record a page view on every route change. */
function RouteEffects() {
  const { pathname } = useLocation();
  useEffect(() => {
    if (!effectsStarted) { initEffects(); effectsStarted = true; }
    scrollToTop();
    api.trackView({ path: pathname, referrer: document.referrer || undefined });
  }, [pathname]);
  useEffect(() => {
    // New page content mounts after data loads; re-scan for [data-reveal] a few times
    const timers = [50, 400, 1200].map((ms) => setTimeout(refreshReveal, ms));
    return () => timers.forEach(clearTimeout);
  });
  return null;
}

function FullScreen({ title, children }) {
  return (
    <main className="full-screen-state">
      <div>
        <LogoMark size={64} />
        <h1 className="mt-3">{title}</h1>
        {children}
      </div>
    </main>
  );
}

export default function App() {
  const { status, error, reload, maintenance, s } = useSettings();

  if (status === 'loading') {
    return <FullScreen title="Village Courtyard"><p className="mt-2" aria-live="polite">Lighting the lanterns…</p></FullScreen>;
  }
  if (status === 'error') {
    return (
      <FullScreen title="We can't reach the kitchen">
        <p className="mt-2">{error?.message}</p>
        <button type="button" className="btn btn-gold mt-2" onClick={reload}>Try again</button>
      </FullScreen>
    );
  }
  if (maintenance) {
    return (
      <FullScreen title={s.site_name || 'Village Courtyard'}>
        <p className="mt-3 fs-5" style={{ maxWidth: '40ch', margin: '0 auto' }}>{maintenance}</p>
        {s.phone && <p className="mt-3">Call us on <a className="link-light" href={`tel:${s.phone.replace(/[^\d+]/g, '')}`}>{s.phone}</a></p>}
      </FullScreen>
    );
  }

  return (
    <>
      <a className="skip-link" href="#main">Skip to content</a>
      <RouteEffects />
      <Header />
      <main id="main" tabIndex={-1}>
        <Routes>
          <Route path="/" element={<Home />} />
          <Route path="/menu" element={<Menu />} />
          <Route path="/about" element={<About />} />
          <Route path="/gallery" element={<Gallery />} />
          <Route path="/reservations" element={<Reservations />} />
          <Route path="/contact" element={<Contact />} />
          <Route path="/checkout" element={<Checkout />} />
          <Route path="/track" element={<TrackOrder />} />
          <Route path="/order/:number" element={<OrderStatus />} />
          <Route path="/page/:slug" element={<CmsPage />} />
          <Route path="*" element={<NotFound />} />
        </Routes>
      </main>
      <Footer />
      <CartDrawer />
      <a href="#main" className="back-to-top" data-back-to-top aria-label="Back to top"><i className="bi bi-arrow-up" aria-hidden="true" /></a>
    </>
  );
}
