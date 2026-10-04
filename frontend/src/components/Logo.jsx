import { useState } from 'react';

/** Courtyard-arch mark (SVG) used when no logo file is uploaded. */
export function LogoMark({ size = 42 }) {
  return (
    <svg width={size} height={size} viewBox="0 0 48 48" aria-hidden="true">
      <rect x="1" y="1" width="46" height="46" rx="3" fill="none" stroke="#C9A24D" strokeWidth="1.5" />
      <path d="M12 40V22a12 12 0 0 1 24 0v18" fill="none" stroke="#C9A24D" strokeWidth="2" />
      <path d="M17 40V23a7 7 0 0 1 14 0v17" fill="none" stroke="#D9774A" strokeWidth="1.6" />
      <path d="M24 13v5" stroke="#C9A24D" strokeWidth="1.4" />
      <rect x="21.5" y="18" width="5" height="7" rx="1.5" fill="#C9A24D" />
      <path d="M8 40h32" stroke="#C9A24D" strokeWidth="2" />
    </svg>
  );
}

/** Uploaded logo (light version for dark backgrounds), else mark + name. */
export default function Logo({ settings, light = true }) {
  const src = light ? settings.logo_white || settings.logo : settings.logo;
  const [failed, setFailed] = useState(false);
  const name = settings.site_name || 'Village Courtyard';
  if (src && !failed) {
    return <img src={src} alt={name} onError={() => setFailed(true)} />;
  }
  return (
    <>
      <LogoMark />
      <span><span className="brand-name">{name}</span><span className="brand-tag">Rustic kitchen</span></span>
    </>
  );
}
