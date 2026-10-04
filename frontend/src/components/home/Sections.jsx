import { Link } from 'react-router-dom';
import Img from '../Img';
import DishCard from '../DishCard';
import { SectionHead, SmartLink } from '../Bits';
import { ContactList, HoursTable, MapFrame } from '../HoursBlock';
import { useSettings } from '../../context/SettingsContext';

/* Each component renders one homepage section from /api/homepage.php.
   `section` = { key, title, subtitle, content, image, data } — all editable in
   Admin → Homepage. */

export function About({ section }) {
  const c = section.content || {};
  return (
    <section className="section">
      <div className="container">
        <div className="row g-4 g-lg-5 align-items-center">
          <div className="col-lg-6" data-reveal>
            <div className="eyebrow">{section.subtitle}</div>
            <h2 className="display-5 mt-2 mb-3">{section.title}</h2>
            {c.text && <p className="text-secondary fs-5" style={{ maxWidth: '52ch' }}>{c.text}</p>}
            {c.stats?.length > 0 && (
              <div className="stats">{c.stats.map((st) => <div key={st.label}><b>{st.value}</b><span>{st.label}</span></div>)}</div>
            )}
            {c.btn_text && <SmartLink href={c.btn_link || '/about'} className="btn btn-outline-forest mt-4">{c.btn_text}</SmartLink>}
          </div>
          <div className="col-lg-5 offset-lg-1" data-reveal>
            <div className="about-media"><Img src={section.image} alt="Inside the courtyard" fallback="scene" /></div>
          </div>
        </div>
      </div>
    </section>
  );
}

export function Signature({ section }) {
  const items = section.data.items || [];
  if (!items.length) return null;
  return (
    <section className="section paper">
      <div className="container">
        <SectionHead eyebrow={section.subtitle} title={section.title} />
        <div className="row g-4">
          {items.map((it) => <div className="col-sm-6 col-lg-4" key={it.id}><DishCard item={it} /></div>)}
        </div>
        <div className="text-center mt-5"><Link to="/menu" className="btn btn-forest">See the full menu</Link></div>
      </div>
    </section>
  );
}

export function Specials({ section }) {
  const c = section.content || {};
  const items = section.data.items || [];
  return (
    <>
      <section className="specials-banner" aria-label={section.title}>
        {section.image && <img src={section.image} alt="" />}
        <div className="container inner">
          <div>
            <div className="eyebrow">{section.subtitle} · {section.title}</div>
            <h2>{c.banner_text}</h2>
          </div>
          {c.btn_text && <SmartLink href={c.btn_link || '/menu'} className="btn btn-ghost-light">{c.btn_text}</SmartLink>}
        </div>
      </section>
      {items.length > 0 && (
        <section className="section pt-5">
          <div className="container">
            <div className="row g-4">
              {items.map((it) => <div className="col-sm-6 col-lg-3" key={it.id}><DishCard item={it} /></div>)}
            </div>
          </div>
        </section>
      )}
    </>
  );
}

export function WhyUs({ section }) {
  const items = section.content?.items || [];
  return (
    <section className="section forest on-dark">
      <div className="container">
        <SectionHead eyebrow={section.subtitle} title={section.title} light />
        <div className="row g-4">
          {items.map((it) => (
            <div className="col-sm-6 col-lg-3" key={it.title} data-reveal>
              <div className="why-item"><i className={`bi ${it.icon || 'bi-star'}`} aria-hidden="true" /><h3>{it.title}</h3><p>{it.text}</p></div>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}

export function GalleryPreview({ section }) {
  const images = section.data.images || [];
  if (!images.length) return null;
  return (
    <section className="section">
      <div className="container">
        <SectionHead eyebrow={section.subtitle} title={section.title} />
        <div className="gallery-strip" data-reveal>
          {images.map((g) => <Link key={g.id} to="/gallery" aria-label={g.caption || 'Open gallery'}><Img src={g.image} alt={g.caption || ''} fallback="scene" /></Link>)}
        </div>
        <div className="text-center mt-4"><Link to="/gallery" className="btn btn-outline-forest">View the gallery</Link></div>
      </div>
    </section>
  );
}

export function Testimonials({ section }) {
  const list = section.data.testimonials || [];
  if (!list.length) return null;
  return (
    <section className="section paper">
      <div className="container">
        <SectionHead eyebrow={section.subtitle} title={section.title} />
        <div className="row g-4">
          {list.slice(0, 3).map((t) => (
            <div className="col-lg-4" key={t.id} data-reveal>
              <figure className="quote-card">
                <div className="stars" role="img" aria-label={`${t.rating} out of 5 stars`}>{'★'.repeat(t.rating)}{'☆'.repeat(5 - t.rating)}</div>
                <blockquote>{t.message}</blockquote>
                <figcaption className="who">
                  {t.photo && <img src={t.photo} alt="" />}
                  <span><b>{t.name}</b><span>{t.designation}</span></span>
                </figcaption>
              </figure>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}

export function Hours({ section }) {
  const { s } = useSettings();
  const d = section.data || {};
  return (
    <section className="section" id="visit">
      <div className="container">
        <div className="row g-4 g-lg-5">
          <div className="col-lg-5" data-reveal>
            <div className="eyebrow">{section.subtitle}</div>
            <h2 className="display-6 mt-2 mb-4">{section.title}</h2>
            <HoursTable hours={d.opening_hours || []} />
            <div className="mt-4"><ContactList s={{ ...s, address: d.address, phone: d.phone, email: d.email }} /></div>
            <Link to="/reservations" className="btn btn-gold mt-4">Reserve a table</Link>
          </div>
          {section.content?.show_map && <div className="col-lg-7" data-reveal><MapFrame url={d.map_embed_url} /></div>}
        </div>
      </div>
    </section>
  );
}

export function Cta({ section }) {
  const c = section.content || {};
  return (
    <section className="cta section">
      {section.image && <img src={section.image} alt="" />}
      <div className="container" data-reveal>
        <div className="eyebrow gold">{section.subtitle}</div>
        <h2 className="mt-2">{section.title}</h2>
        {c.text && <p>{c.text}</p>}
        <div className="d-flex justify-content-center flex-wrap gap-2">
          {c.btn1_text && <SmartLink href={c.btn1_link} className="btn btn-gold">{c.btn1_text}</SmartLink>}
          {c.btn2_text && <SmartLink href={c.btn2_link} className="btn btn-ghost-light">{c.btn2_text}</SmartLink>}
        </div>
      </div>
    </section>
  );
}
