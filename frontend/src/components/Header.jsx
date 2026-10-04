import { useEffect, useRef } from 'react';
import { Link, NavLink, useLocation } from 'react-router-dom';
import Collapse from 'bootstrap/js/dist/collapse';
import Logo from './Logo';
import { useSettings } from '../context/SettingsContext';
import { useCart } from '../context/CartContext';

/** CMS pages with a dedicated route keep it ("about" → /about). */
export const pagePath = (slug) => (slug === 'about' ? '/about' : `/page/${slug}`);

export default function Header() {
  const { s, navPages, ordering } = useSettings();
  const { count, openCart } = useCart();
  const collapseRef = useRef(null);
  const location = useLocation();

  // Close the mobile menu after navigating
  useEffect(() => {
    const el = collapseRef.current;
    if (el?.classList.contains('show')) Collapse.getOrCreateInstance(el, { toggle: false }).hide();
  }, [location.pathname]);

  const extraPages = navPages.filter((p) => p.slug !== 'about');
  const links = [
    ['/', 'Home'], ['/menu', 'Menu'], ['/about', 'About'], ['/gallery', 'Gallery'], ['/reservations', 'Reserve'], ['/contact', 'Contact'],
  ];

  return (
    <header className="site-header">
      <nav className="navbar navbar-expand-lg" aria-label="Main">
        <div className="container">
          <Link className="brand" to="/" aria-label={`${s.site_name || 'Village Courtyard'} home`}><Logo settings={s} /></Link>

          <div className="d-flex align-items-center gap-2 order-lg-last">
            {ordering.enabled && (
              <button type="button" className="btn btn-sm cart-button" onClick={openCart} aria-label={`Your order, ${count} item${count === 1 ? '' : 's'}`}>
                <i className="bi bi-bag" aria-hidden="true" /><span className="d-none d-sm-inline">Order</span>
                <span className="cart-count tabular">{count}</span>
              </button>
            )}
            <button className="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Open menu">
              <i className="bi bi-list fs-4" aria-hidden="true" />
            </button>
          </div>

          <div className="collapse navbar-collapse" id="mainNav" ref={collapseRef}>
            <ul className="navbar-nav ms-auto me-lg-3">
              {links.map(([to, label]) => (
                <li className="nav-item" key={to}><NavLink className="nav-link" to={to} end={to === '/'}>{label}</NavLink></li>
              ))}
              {extraPages.length > 0 && (
                <li className="nav-item dropdown">
                  <button className="nav-link dropdown-toggle btn btn-link" type="button" data-bs-toggle="dropdown" aria-expanded="false">More</button>
                  <ul className="dropdown-menu dropdown-menu-end">
                    <li><NavLink className="dropdown-item" to="/track">Track an order</NavLink></li>
                    {extraPages.map((p) => <li key={p.slug}><NavLink className="dropdown-item" to={pagePath(p.slug)}>{p.title}</NavLink></li>)}
                  </ul>
                </li>
              )}
            </ul>
          </div>
        </div>
      </nav>
    </header>
  );
}
