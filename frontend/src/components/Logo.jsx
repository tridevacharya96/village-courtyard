import { useState } from 'react';

/** Courtyard-arch mark (same drawing as branding/logo-icon.svg), used when no logo file is uploaded. */
export function LogoMark({ size = 42 }) {
  return (
    <svg width={size} height={size} viewBox="0 0 240 240" aria-hidden="true">
      <path d="M48 212V112a72 72 0 0 1 144 0v100" fill="none" stroke="#C9A24D" strokeWidth="12" strokeLinecap="round" />
      <path d="M76 212V120a44 44 0 0 1 88 0v92" fill="none" stroke="#D9774A" strokeWidth="8" strokeLinecap="round" />
      <path d="M24 214h192" stroke="#C9A24D" strokeWidth="10" strokeLinecap="round" />
      <path d="M120 4c15 9 17 24 0 34c-17-10-15-25 0-34z" fill="#C9A24D" />
      <path d="M120 76v20" stroke="#C9A24D" strokeWidth="4" strokeLinecap="round" />
      <path d="M110 104l4-8h12l4 8z" fill="#C9A24D" />
      <rect x="105" y="104" width="30" height="38" rx="7" fill="#C9A24D" />
      <rect x="112" y="111" width="16" height="24" rx="4" fill="#F6F0E4" />
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
