import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { api } from '../services/api';
import { useApi } from '../hooks/useApi';
import { useSeo } from '../hooks/useSeo';
import { ErrorState, Loading, PageBanner } from '../components/Bits';
import Img from '../components/Img';
import Lightbox from '../components/Lightbox';

export default function Gallery() {
  useSeo('Gallery', 'Food, the lantern-lit courtyard and celebrations at Village Courtyard.');
  const [params, setParams] = useSearchParams();
  const category = params.get('category') || '';
  const [open, setOpen] = useState(null);
  const { data, error, loading, reload } = useApi((signal) => api.gallery(category, { signal }), [category]);

  return (
    <>
      <PageBanner eyebrow="Gallery" title="Moments in the Courtyard" />
      <section className="section">
        <div className="container">
          {data?.categories?.length > 0 && (
            <div className="chips justify-content-center mb-4" role="group" aria-label="Filter photos">
              <button type="button" className="chip" aria-pressed={!category} onClick={() => setParams({}, { replace: true })}>All</button>
              {data.categories.map((c) => (
                <button type="button" key={c.slug} className="chip" aria-pressed={category === c.slug} onClick={() => setParams({ category: c.slug }, { replace: true })}>{c.name}</button>
              ))}
            </div>
          )}
          {loading && <Loading rows={2} label="Loading photos" />}
          {error && <ErrorState error={error} onRetry={reload} />}
          {data && !data.images.length && <div className="state-box"><i className="bi bi-images" />No photos here yet.</div>}
          {data && data.images.length > 0 && (
            <div className="masonry">
              {data.images.map((g, i) => (
                <figure key={g.id}>
                  <button type="button" onClick={() => setOpen(i)} aria-label={`Open photo${g.caption ? `: ${g.caption}` : ''}`}>
                    <Img src={g.image} alt={g.caption || ''} fallback="scene" />
                  </button>
                  {g.caption && <figcaption>{g.caption}</figcaption>}
                </figure>
              ))}
            </div>
          )}
        </div>
      </section>
      {open !== null && data && <Lightbox images={data.images} index={open} onIndex={setOpen} onClose={() => setOpen(null)} />}
    </>
  );
}
