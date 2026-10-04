import { useState } from 'react';

/**
 * <img> that falls back to a drawn plate when the file is missing or fails,
 * so a dish without a photo still looks intentional.
 */
export default function Img({ src, alt = '', className = '', fallback = 'plate', ...rest }) {
  const [failed, setFailed] = useState(false);
  if (!src || failed) {
    return (
      <span className={`img-fallback fallback-${fallback} ${className}`} role={alt ? 'img' : undefined} aria-label={alt || undefined}
        style={{ display: 'block', background: fallback === 'scene'
          ? 'radial-gradient(60% 50% at 50% 60%, rgba(243,201,105,.35), transparent 70%), linear-gradient(#142A1D, #2C5139)'
          : 'radial-gradient(circle at 50% 50%, #A85A2A 0 22%, #F1EBDD 23% 38%, #E2D6BE 39% 40%, #F3EEE2 41% 47%, #3A2C1E 48%)' }} />
    );
  }
  return <img src={src} alt={alt} className={className} loading="lazy" decoding="async" onError={() => setFailed(true)} {...rest} />;
}
