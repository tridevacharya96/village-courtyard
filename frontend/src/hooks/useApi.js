import { useCallback, useEffect, useState } from 'react';

/**
 * Load data on mount / when deps change, with abort on unmount.
 *   const { data, error, loading, reload } = useApi((signal) => api.menu({}, { signal }), []);
 */
export function useApi(fetcher, deps = []) {
  const [state, setState] = useState({ data: null, error: null, loading: true });
  const [tick, setTick] = useState(0);
  // eslint-disable-next-line react-hooks/exhaustive-deps
  const run = useCallback(fetcher, deps);

  useEffect(() => {
    const ctrl = new AbortController();
    setState((s) => ({ ...s, loading: true, error: null }));
    run(ctrl.signal)
      .then((data) => setState({ data, error: null, loading: false }))
      .catch((error) => { if (error.name !== 'AbortError') setState({ data: null, error, loading: false }); });
    return () => ctrl.abort();
  }, [run, tick]);

  return { ...state, reload: () => setTick((t) => t + 1) };
}
