import { createContext, useCallback, useContext, useEffect, useState } from 'react';
import { api } from '../services/api';

/**
 * Site-wide settings from /api/settings.php: name, logo, contact, hours,
 * social links, ordering rules, reservation limits, navigation pages.
 */
const SettingsContext = createContext(null);

export function SettingsProvider({ children }) {
  const [state, setState] = useState({ status: 'loading', data: null, error: null });
  const [maintenance, setMaintenance] = useState(null);

  const load = useCallback(() => {
    setState((s) => ({ ...s, status: 'loading', error: null }));
    api.settings()
      .then((data) => {
        setState({ status: 'ready', data, error: null });
        if (data.maintenance) setMaintenance(data.settings.maintenance_message || 'We will be back shortly.');
      })
      .catch((error) => setState({ status: 'error', data: null, error }));
  }, []);

  useEffect(() => { load(); }, [load]);

  // Any API call answering "maintenance" switches the whole site to the notice
  useEffect(() => {
    const on = (e) => setMaintenance(e.detail || 'We will be back shortly.');
    window.addEventListener('vc:maintenance', on);
    return () => window.removeEventListener('vc:maintenance', on);
  }, []);

  return (
    <SettingsContext.Provider value={{ ...state, reload: load, maintenance }}>
      {children}
    </SettingsContext.Provider>
  );
}

export function useSettings() {
  const ctx = useContext(SettingsContext);
  const d = ctx.data || {};
  return {
    ...ctx,
    s: d.settings || {},
    navPages: d.nav_pages || [],
    ordering: d.ordering || { enabled: true, cod_enabled: true, razorpay_enabled: false, gst_percent: 5, delivery_charge: 0, free_delivery_above: 0, min_order_amount: 0 },
    reservations: d.reservations || { enabled: true, max_guests: 20, max_days_ahead: 60, locations: [] },
  };
}
