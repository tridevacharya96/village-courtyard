import { useEffect, useRef } from 'react';

/** Full-screen photo viewer with keyboard (← → Esc) and swipe support. */
export default function Lightbox({ images, index, onClose, onIndex }) {
  const closeRef = useRef(null);
  const touchX = useRef(null);
  const img = images[index];

  useEffect(() => {
    const prevFocus = document.activeElement;
    closeRef.current?.focus();
    document.body.style.overflow = 'hidden';
    const onKey = (e) => {
      if (e.key === 'Escape') onClose();
      if (e.key === 'ArrowRight') onIndex((index + 1) % images.length);
      if (e.key === 'ArrowLeft') onIndex((index - 1 + images.length) % images.length);
    };
    window.addEventListener('keydown', onKey);
    return () => { window.removeEventListener('keydown', onKey); document.body.style.overflow = ''; prevFocus?.focus?.(); };
  }, [index, images.length, onClose, onIndex]);

  if (!img) return null;
  return (
    <div className="lightbox" role="dialog" aria-modal="true" aria-label="Photo viewer"
      onTouchStart={(e) => { touchX.current = e.touches[0].clientX; }}
      onTouchEnd={(e) => {
        if (touchX.current === null) return;
        const dx = e.changedTouches[0].clientX - touchX.current;
        if (Math.abs(dx) > 50) onIndex((index + (dx < 0 ? 1 : -1) + images.length) % images.length);
        touchX.current = null;
      }}>
      <div className="lightbox-top">
        <span className="small tabular">{index + 1} / {images.length}</span>
        <button ref={closeRef} type="button" className="btn-close btn-close-white" aria-label="Close photo viewer" onClick={onClose} />
      </div>
      <div className="lightbox-stage">
        <button type="button" className="nav" aria-label="Previous photo" onClick={() => onIndex((index - 1 + images.length) % images.length)}><i className="bi bi-chevron-left" /></button>
        <img src={img.image} alt={img.caption || 'Gallery photo'} />
        <button type="button" className="nav" aria-label="Next photo" onClick={() => onIndex((index + 1) % images.length)}><i className="bi bi-chevron-right" /></button>
      </div>
      <div className="lightbox-caption">{img.caption}{img.category ? <span className="text-white-50"> · {img.category}</span> : null}</div>
    </div>
  );
}
