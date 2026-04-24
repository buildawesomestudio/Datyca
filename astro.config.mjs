// @ts-check
import { defineConfig } from 'astro/config';
import tailwindcss from '@tailwindcss/vite';
import sitemap from '@astrojs/sitemap';

// https://astro.build/config
export default defineConfig({
  site: 'https://www.datyca.com',
  devToolbar: { enabled: false },
  experimental: {
    preserveScriptOrder: true,
  },
  integrations: [sitemap()],
  vite: {
    plugins: [tailwindcss()],
  },
});
