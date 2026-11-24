import { RenderMode, ServerRoute } from '@angular/ssr';

/**
 * The login screen needs no session, so it is prerendered. Every other page depends on
 * the token in the browser's localStorage, so it renders in the browser only: the server
 * never runs the guards and never redirects a signed-in user to login.
 */
export const serverRoutes: ServerRoute[] = [
  {
    path: 'login',
    renderMode: RenderMode.Prerender,
  },
  {
    path: '**',
    renderMode: RenderMode.Client,
  },
];
