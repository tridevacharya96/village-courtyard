import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';

// VITE_BASE: the folder the built site is served from ("/" for a domain root,
// "/village-courtyard/" for http://localhost/village-courtyard/ on XAMPP).
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');
  return {
    base: env.VITE_BASE || '/',
    plugins: [react()],
    server: { port: 5173, host: '127.0.0.1' },
    preview: { port: 5173, host: '127.0.0.1' },
    build: {
      outDir: 'dist',
      sourcemap: false,
      rollupOptions: {
        output: {
          // Split rarely-changing libraries into their own cached files
          manualChunks(id) {
            if (!id.includes('node_modules')) return undefined;
            if (/[\\/](react|react-dom|react-router|react-router-dom|scheduler)[\\/]/.test(id)) return 'vendor';
            if (/[\\/](bootstrap|jquery|@popperjs)[\\/]/.test(id)) return 'ui';
            return undefined;
          },
        },
      },
    },
  };
});
