import { createContext, useCallback, useContext, useRef, useState } from 'react';

/** Lightweight toasts rendered by React (Bootstrap styling, no Bootstrap JS needed). */
const ToastContext = createContext(() => {});

export function ToastProvider({ children }) {
  const [toasts, setToasts] = useState([]);
  const nextId = useRef(1);

  const dismiss = useCallback((id) => setToasts((t) => t.filter((x) => x.id !== id)), []);
  const toast = useCallback((message, tone = 'success', ms = 3500) => {
    const id = nextId.current++;
    setToasts((t) => [...t.slice(-2), { id, message, tone }]);
    if (ms) setTimeout(() => dismiss(id), ms);
  }, [dismiss]);

  return (
    <ToastContext.Provider value={toast}>
      {children}
      <div className="vc-toasts" aria-live="polite" aria-atomic="false">
        {toasts.map((t) => (
          <div key={t.id} className={`vc-toast tone-${t.tone}`} role={t.tone === 'danger' ? 'alert' : 'status'}>
            <i className={`bi ${t.tone === 'danger' ? 'bi-exclamation-circle' : t.tone === 'info' ? 'bi-info-circle' : 'bi-check-circle'}`} aria-hidden="true" />
            <span>{t.message}</span>
            <button type="button" className="btn-close btn-close-white" aria-label="Dismiss" onClick={() => dismiss(t.id)} />
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  );
}

export const useToast = () => useContext(ToastContext);
