import { defineConfig } from 'astro/config';
import node from '@astrojs/node';

// Server output so basket, account and admin pages can talk to the Laravel API
// (with the visitor's session cookie) on every request.
export default defineConfig({
  site: 'https://wdscompetitions.co.uk',
  output: 'server',
  adapter: node({ mode: 'standalone' }),
  server: { port: 4321, host: true },
});
