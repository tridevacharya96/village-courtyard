import { useEffect, useState } from 'react';
import Img from '../Img';
import { SmartLink } from '../Bits';
import { useSettings } from '../../context/SettingsContext';

/** Rotating hero slides (admin: Homepage → Hero slider). Pauses on hover/focus. */
export default function Hero({ section }) {
  const { s } = useSettings();
  const slides = section.data.slides || [];
  const { autoplay = true, interval = 6000 } = section.content || {};
  const [i, setI] = useState(0);
  const [paused, setPaused] = useState(false);

  useEffect(() => {
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!autoplay || paused || reduce || slides.length < 2) return undefined;
    const t = setTimeout(() => setI((n) => (n + 1) % slides.length), Math.max(2000, interval));
    return () => clearTimeout(t);
  }, [i, autoplay, paused, interval, slides.length]);

  const fallback = [{ id: 0, heading: s.site_name || 'Village Courtyard', subheading: s.tagline, image: null, buttons: [{ text: 'View Menu', link: '/menu', style: 'primary' }, { text: 'Reserve a Table', link: '/reservations', style: 'outline' }] }];
  const list = slides.length ? slides : fallback;
  const slide = list[i % list.length];

  return (
    <section className="hero" aria-roledescription="carousel" aria-label="Welcome"
      onMouseEnter={() => setPaused(true)} onMouseLeave={() => setPaused(false)} onFocus={() => setPaused(true)} onBlur={() => setPaused(false)}>
      <div className="hero-slide" key={slide.id} aria-roledescription="slide" aria-label={`${(i % list.length) + 1} of ${list.length}`}>
        <Img src={slide.image} alt="" className="hero-fade" fallback="scene" style={{ position: 'absolute', inset: 0, width: '100%', height: '100%', objectFit: 'cover' }} />
        <div className="container">
          <div className="hero-copy">
            <div className="eyebrow gold">{s.tagline || 'Rustic kitchen'}</div>
            {i === 0 ? <h1 className="mt-3">{slide.heading}</h1> : <h2 className="h1 mt-3" style={{ fontSize: 'clamp(2.6rem, 6.4vw, 5rem)', fontWeight: 500, color: '#fff' }}>{slide.heading}</h2>}
            {slide.subheading && <p className="lead">{slide.subheading}</p>}
            <div className="d-flex flex-wrap gap-2">
              {slide.buttons.map((b) => (
                <SmartLink key={b.text} href={b.link} className={`btn ${b.style === 'primary' ? 'btn-gold' : 'btn-ghost-light'}`}>{b.text}</SmartLink>
              ))}
            </div>
          </div>
        </div>
      </div>
      {list.length > 1 && (
        <div className="hero-controls">
          <div className="container d-flex justify-content-between align-items-center">
            <div className="hero-dots" role="group" aria-label="Choose slide">
              {list.map((sl, n) => (
                <button key={sl.id} type="button" aria-label={`Slide ${n + 1}: ${sl.heading}`} aria-current={n === i % list.length ? 'true' : 'false'} onClick={() => setI(n)} />
              ))}
            </div>
            <div className="hero-arrows">
              <button type="button" aria-label="Previous slide" onClick={() => setI((n) => (n - 1 + list.length) % list.length)}><i className="bi bi-arrow-left" /></button>
              <button type="button" aria-label="Next slide" onClick={() => setI((n) => (n + 1) % list.length)}><i className="bi bi-arrow-right" /></button>
            </div>
          </div>
        </div>
      )}
      <div className="lantern-row" aria-hidden="true" />
    </section>
  );
}
