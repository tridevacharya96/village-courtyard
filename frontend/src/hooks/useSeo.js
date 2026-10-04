import { useEffect } from 'react';
import { useSettings } from '../context/SettingsContext';

function setMeta(attr, key, content) {
  if (!content) return;
  let el = document.head.querySelector(`meta[${attr}="${key}"]`);
  if (!el) { el = document.createElement('meta'); el.setAttribute(attr, key); document.head.appendChild(el); }
  el.setAttribute('content', content);
}

/** Per-page <title>, description and social-share tags. */
export function useSeo(title, description, image) {
  const { s } = useSettings();
  useEffect(() => {
    const site = s.site_name || 'Village Courtyard';
    const full = title ? `${title} · ${site}` : (s.meta_title || site);
    const desc = description || s.meta_description;
    document.title = full;
    setMeta('name', 'description', desc);
    setMeta('property', 'og:title', full);
    setMeta('property', 'og:description', desc);
    setMeta('property', 'og:image', image || s.og_image);
    setMeta('name', 'keywords', s.meta_keywords);
  }, [title, description, image, s]);
}
