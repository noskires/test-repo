import { inject, PLATFORM_ID } from '@angular/core';
import { isPlatformBrowser } from '@angular/common';
import { CanActivateFn, Router } from '@angular/router';
import { map } from 'rxjs';
import { AuthService, ROLE_MANAGEMENT_VIEWER, ROLE_SYSTEM_ADMINISTRATOR } from './auth.service';

/** The "waiting for an administrator" page for pending users (issue 46). */
export const PENDING_PATH = '/pending';

/** The change-password page (issue 44). A user with a temporary password sees only this page. */
export const CHANGE_PASSWORD_PATH = '/change-password';

/**
 * Builds a guard that requires a signed-in, classified user and, when `roles` is
 * non-empty, at least one of those roles. Unauthenticated users go to `/login`; users who
 * must change a temporary password go to `/change-password` (issue 44); pending users go
 * to `/pending` (issue 46); signed-in users without the role go to the
 * dashboard (`/`).
 *
 * During server rendering there is no token (localStorage is browser-only), so the guard
 * lets the route render and leaves the decision to the browser.
 */
export function requireRoles(roles: readonly string[] = []): CanActivateFn {
  return () => {
    if (!isPlatformBrowser(inject(PLATFORM_ID))) {
      return true;
    }
    const auth = inject(AuthService);
    const router = inject(Router);

    return auth.ensureUser().pipe(
      map((user) => {
        if (!user) {
          return router.parseUrl('/login');
        }
        if (user.must_change_password) {
          return router.parseUrl(CHANGE_PASSWORD_PATH);
        }
        if (user.access === 'pending') {
          return router.parseUrl(PENDING_PATH);
        }
        if (roles.length > 0 && !auth.hasAnyRole(roles)) {
          return router.parseUrl('/');
        }
        return true;
      }),
    );
  };
}

export const authGuard: CanActivateFn = requireRoles();

/**
 * The pending page: only for a signed-in pending user. Others go to `/login` or the
 * dashboard.
 */
export const pendingGuard: CanActivateFn = () => {
  if (!isPlatformBrowser(inject(PLATFORM_ID))) {
    return true;
  }
  const auth = inject(AuthService);
  const router = inject(Router);

  return auth.ensureUser().pipe(
    map((user) => {
      if (!user) {
        return router.parseUrl('/login');
      }
      if (user.must_change_password) {
        return router.parseUrl(CHANGE_PASSWORD_PATH);
      }
      return user.access === 'pending' ? true : router.parseUrl('/');
    }),
  );
};

/** The change-password page (issue 44): any signed-in user, pending ones included. */
export const changePasswordGuard: CanActivateFn = () => {
  if (!isPlatformBrowser(inject(PLATFORM_ID))) {
    return true;
  }
  const auth = inject(AuthService);
  const router = inject(Router);

  return auth.ensureUser().pipe(map((user) => (user ? true : router.parseUrl('/login'))));
};

/** Admin pages: System Administrator only. */
export const adminGuard: CanActivateFn = requireRoles([ROLE_SYSTEM_ADMINISTRATOR]);

/** Reports (issue 29): System Administrator or Management/Viewer (Q11). */
export const reportsGuard: CanActivateFn = requireRoles([
  ROLE_SYSTEM_ADMINISTRATOR,
  ROLE_MANAGEMENT_VIEWER,
]);
