import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { api } from '../services/api';
import { useApi } from '../hooks/useApi';
import { useSeo } from '../hooks/useSeo';
import { ErrorState, Loading, PageBanner } from '../components/Bits';
import MenuRow from '../components/MenuRow';
import { useSettings } from '../context/SettingsContext';
import { useCart } from '../context/CartContext';
import { money } from '../lib/format';

/**
 * Full menu: category chips, veg / non-veg filter and search, grouped by
 * category. Filters live in the URL (?category=desserts&veg=1&q=paneer) so
 * a filtered menu can be shared.
 */
export default function Menu() {
  useSeo('Menu', 'Village mutton curry, clay-oven kebabs, Odia classics and continental plates. Order online for delivery, takeaway or dine-in.');
  const { ordering } = useSettings();
  const { count, openCart, pricing, localSubtotal } = useCart();
  const [params, setParams] = useSearchParams();
  const category = params.get('category') || '';
  const type = params.get('type') || '';
  const [q, setQ] = useState(params.get('q') || '');

  const { data, error, loading, reload } = useApi(
    (signal) => Promise.all([api.categories({ signal }), api.menu({}, { signal })]),
    [],
  );

  // Keep the search box in the URL (debounced)
  useEffect(() => {
    const t = setTimeout(() => {
      // Read the live URL: the router's copy can be a render behind
      const next = new URLSearchParams(window.location.search);
      if ((next.get('q') || '') === q.trim()) return;
      if (q.trim()) next.set('q', q.trim()); else next.delete('q');
      setParams(next, { replace: true });
    }, 250);
    return () => clearTimeout(t);
  }, [q]); // eslint-disable-line react-hooks/exhaustive-deps

  // Build from the live URL so quick successive clicks never undo each other
  const setFilter = (key, value) => {
    const next = new URLSearchParams(window.location.search);
    if (value) next.set(key, value); else next.delete(key);
    setParams(next, { replace: true });
  };

  const groups = useMemo(() => {
    if (!data) return [];
    const [{ categories }, { items }] = data;
    const term = q.trim().toLowerCase();
    return categories
      .filter((c) => !category || c.slug === category)
      .map((c) => ({
        ...c,
        items: items.filter((i) => i.category_id === c.id
          && (!type || i.food_type === type)
          && (!term || `${i.name} ${i.description || ''}`.toLowerCase().includes(term))),
      }))
      .filter((c) => c.items.length);
  }, [data, category, type, q]);

  if (loading) return <><PageBanner eyebrow="À la carte" title="The Menu" /><Loading rows={5} label="Loading the menu" /></>;
  if (error) return <><PageBanner eyebrow="À la carte" title="The Menu" /><ErrorState error={error} onRetry={reload} /></>;
  const categories = data[0].categories;
  const shown = groups.reduce((n, g) => n + g.items.length, 0);

  return (
    <>
      <PageBanner eyebrow="À la carte" title="The Menu"
        intro={ordering.enabled ? `Add dishes to your order for delivery, takeaway or your table. Prices are before ${ordering.gst_percent}% GST.` : 'Online ordering is paused right now. Call us to order.'} />

      <div className="menu-tools">
        <div className="container d-grid gap-3">
          <div className="chips" role="group" aria-label="Category">
            <button type="button" className="chip" aria-pressed={!category} onClick={() => setFilter('category', '')}>All</button>
            {categories.map((c) => (
              <button type="button" key={c.slug} className="chip" aria-pressed={category === c.slug} onClick={() => setFilter('category', c.slug)}>{c.name}</button>
            ))}
          </div>
          <div className="d-flex flex-wrap gap-3 align-items-center">
            <div className="flex-grow-1" style={{ maxWidth: 360 }}>
              <label className="visually-hidden" htmlFor="menuSearch">Search the menu</label>
              <input id="menuSearch" type="search" className="form-control" placeholder="Search dishes, e.g. paneer" value={q} onChange={(e) => setQ(e.target.value)} />
            </div>
            <div className="chips" role="group" aria-label="Diet">
              {[['', 'Veg & non-veg'], ['veg', 'Veg only'], ['non_veg', 'Non-veg only']].map(([v, l]) => (
                <button type="button" key={v} className="chip" aria-pressed={type === v} onClick={() => setFilter('type', v)}>{l}</button>
              ))}
            </div>
            <span className="small text-muted ms-auto" aria-live="polite">{shown} dish{shown === 1 ? '' : 'es'}</span>
          </div>
        </div>
      </div>

      <section className="section pt-5">
        <div className="container">
          {groups.length === 0 && (
            <div className="menu-empty">
              <p>No dishes match{q ? ` “${q}”` : ''}. Try another word or clear the filters.</p>
              <button type="button" className="btn btn-outline-forest" onClick={() => { setQ(''); setParams({}, { replace: true }); }}>Clear filters</button>
            </div>
          )}
          {groups.map((g) => (
            <section className="menu-category" key={g.slug} id={g.slug} aria-labelledby={`cat-${g.slug}`}>
              <div className="menu-category-head"><h2 id={`cat-${g.slug}`}>{g.name}</h2>{g.description && <p>{g.description}</p>}</div>
              <div className="menu-grid">{g.items.map((it) => <MenuRow key={it.id} item={it} />)}</div>
            </section>
          ))}
        </div>
      </section>

      {count > 0 && (
        <div className="position-fixed bottom-0 start-0 end-0 p-3 d-lg-none" style={{ zIndex: 1025 }}>
          <button type="button" className="btn btn-gold w-100 shadow" onClick={openCart}>
            View order · {count} item{count === 1 ? '' : 's'} · {money(pricing?.subtotal ?? localSubtotal)}
          </button>
        </div>
      )}
    </>
  );
}
