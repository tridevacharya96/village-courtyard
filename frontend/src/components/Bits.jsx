import { Link } from 'react-router-dom';
import { FOOD_TYPES, isInternal } from '../lib/format';

export function FoodMark({ type }) {
  const t = FOOD_TYPES[type] || FOOD_TYPES.veg;
  return <span className={`food-mark ${type}`} role="img" aria-label={t.label} title={t.label} />;
}

export function Spice({ level }) {
  if (!level) return null;
  const words = ['', 'Mild', 'Medium', 'Hot'];
  return <span className="spice" title={`${words[level]} spice`} aria-label={`${words[level]} spice`}>{'●'.repeat(level)}</span>;
}

/** Router link for "/menu"-style paths, normal anchor for full URLs. */
export function SmartLink({ href, children, ...rest }) {
  if (!href) return null;
  if (isInternal(href)) return <Link to={href} {...rest}>{children}</Link>;
  return <a href={href} target="_blank" rel="noopener noreferrer" {...rest}>{children}</a>;
}

export function SectionHead({ eyebrow, title, intro, light }) {
  return (
    <div className="section-head" data-reveal>
      {eyebrow && <div className={`eyebrow${light ? ' gold' : ''}`}>{eyebrow}</div>}
      {title && <h2>{title}</h2>}
      <div className="gold-rule" aria-hidden="true">✦</div>
      {intro && <p>{intro}</p>}
    </div>
  );
}

export function PageBanner({ eyebrow, title, intro, image }) {
  return (
    <header className={`page-banner${image ? ' has-image' : ''}`}>
      {image && <img src={image} alt="" />}
      <div className="container">
        {eyebrow && <div className="eyebrow gold">{eyebrow}</div>}
        <h1>{title}</h1>
        {intro && <p>{intro}</p>}
      </div>
      <div className="lantern-row" aria-hidden="true" />
    </header>
  );
}

export function Loading({ rows = 3, label = 'Loading' }) {
  return (
    <div className="container section" aria-busy="true" aria-label={label}>
      <div className="skeleton mb-3" style={{ height: 34, width: '40%', margin: '0 auto' }} />
      {Array.from({ length: rows }).map((_, i) => <div key={i} className="skeleton mb-3" style={{ height: 90 }} />)}
    </div>
  );
}

export function ErrorState({ error, onRetry, title = 'This page could not load' }) {
  return (
    <div className="state-box" role="alert">
      <i className="bi bi-cloud-slash" aria-hidden="true" />
      <h2 className="h3">{title}</h2>
      <p>{error?.message || 'Please try again in a moment.'}</p>
      {onRetry && <button type="button" className="btn btn-forest" onClick={onRetry}>Try again</button>}
    </div>
  );
}
