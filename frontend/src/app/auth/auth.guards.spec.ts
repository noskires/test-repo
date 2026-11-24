import { TestBed } from '@angular/core/testing';
import { PLATFORM_ID } from '@angular/core';
import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import {
  ActivatedRouteSnapshot,
  provideRouter,
  Router,
  RouterStateSnapshot,
  UrlTree,
} from '@angular/router';
import { firstValueFrom, isObservable, Observable, of } from 'rxjs';
import { adminGuard, authGuard, pendingGuard, reportsGuard } from './auth.guards';
import { authInterceptor } from './auth.interceptor';
import { AuthService, CurrentUser, TOKEN_STORAGE_KEY, UserAccess } from './auth.service';

type GuardResult = boolean | UrlTree;

function user(
  roles: string[],
  vendor_id: number | null = null,
  access: UserAccess = vendor_id === null ? 'internal' : 'vendor',
): CurrentUser {
  return { employee_id: 'EMP-1', name: 'Someone', roles, vendor_id, access };
}

function setup(platform: 'browser' | 'server' = 'browser') {
  TestBed.configureTestingModule({
    providers: [
      { provide: PLATFORM_ID, useValue: platform },
      provideHttpClient(withInterceptors([authInterceptor])),
      provideHttpClientTesting(),
      provideRouter([]),
    ],
  });
  return {
    auth: TestBed.inject(AuthService),
    http: TestBed.inject(HttpTestingController),
    router: TestBed.inject(Router),
  };
}

async function run(guard: typeof authGuard): Promise<GuardResult> {
  const result = TestBed.runInInjectionContext(() =>
    guard({} as ActivatedRouteSnapshot, { url: '/x' } as RouterStateSnapshot),
  );
  const value = isObservable(result) ? result : of(result as GuardResult);
  return firstValueFrom(value as Observable<GuardResult>);
}

function urlOf(result: GuardResult, router: Router): string | boolean {
  return result instanceof UrlTree ? router.serializeUrl(result) : result;
}

describe('route guards', () => {
  beforeEach(() => localStorage.clear());
  afterEach(() => localStorage.clear());

  describe('authGuard', () => {
    it('sends an unauthenticated user to the login screen', async () => {
      const { router } = setup();
      expect(urlOf(await run(authGuard), router)).toBe('/login');
    });

    it('lets a signed-in user through', async () => {
      const { auth, router } = setup();
      auth.setSession('tok', user([]));
      expect(urlOf(await run(authGuard), router)).toBe(true);
    });

    it('with a stored token but no user yet, loads /api/auth/me first', async () => {
      localStorage.setItem(TOKEN_STORAGE_KEY, 'tok');
      const { auth, http, router } = setup();

      const result = run(authGuard);
      http.expectOne('/api/auth/me').flush(user(['management-viewer']));

      expect(urlOf(await result, router)).toBe(true);
      expect(auth.user()?.roles).toEqual(['management-viewer']);
    });

    it('with a stored token that cannot be refreshed, sends the user to login', async () => {
      localStorage.setItem(TOKEN_STORAGE_KEY, 'dead');
      const { http, router } = setup();
      vi.spyOn(router, 'navigateByUrl').mockResolvedValue(true);

      const result = run(authGuard);
      http.expectOne('/api/auth/me').flush({}, { status: 401, statusText: 'Unauthorized' });
      http.expectOne('/api/auth/refresh').flush({}, { status: 401, statusText: 'Unauthorized' });

      expect(urlOf(await result, router)).toBe('/login');
      expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull();
    });

    it('does not redirect during server rendering', async () => {
      const { router } = setup('server');
      expect(urlOf(await run(authGuard), router)).toBe(true);
    });
  });

  describe('pending users (issue 46)', () => {
    it.each([
      ['authGuard', authGuard],
      ['adminGuard', adminGuard],
      ['reportsGuard', reportsGuard],
    ] as const)('%s sends a pending user to the waiting page', async (_label, guard) => {
      const { auth, router } = setup();
      auth.setSession('tok', user(['system-administrator'], null, 'pending'));
      expect(urlOf(await run(guard), router)).toBe('/pending');
    });

    it('after a reload, a pending user from /api/auth/me goes to the waiting page', async () => {
      localStorage.setItem(TOKEN_STORAGE_KEY, 'tok');
      const { http, router } = setup();

      const result = run(authGuard);
      http.expectOne('/api/auth/me').flush(user([], null, 'pending'));

      expect(urlOf(await result, router)).toBe('/pending');
    });

    it('pendingGuard lets a pending user see the waiting page', async () => {
      const { auth, router } = setup();
      auth.setSession('tok', user([], null, 'pending'));
      expect(urlOf(await run(pendingGuard), router)).toBe(true);
    });

    it('pendingGuard sends a classified user to the dashboard', async () => {
      const { auth, router } = setup();
      auth.setSession('tok', user([]));
      expect(urlOf(await run(pendingGuard), router)).toBe('/');
    });

    it('pendingGuard sends an unauthenticated user to login', async () => {
      const { router } = setup();
      expect(urlOf(await run(pendingGuard), router)).toBe('/login');
    });
  });

  describe('adminGuard', () => {
    const cases: [string, string[], string | boolean][] = [
      ['system-administrator', ['system-administrator'], true],
      ['management-viewer', ['management-viewer'], '/'],
      ['ordinary employee', [], '/'],
    ];

    it.each(cases)('%s', async (_label, roles, expected) => {
      const { auth, router } = setup();
      auth.setSession('tok', user(roles));
      expect(urlOf(await run(adminGuard), router)).toBe(expected);
    });

    it('sends an unauthenticated user to login', async () => {
      const { router } = setup();
      expect(urlOf(await run(adminGuard), router)).toBe('/login');
    });
  });

  describe('reportsGuard', () => {
    const cases: [string, string[], string | boolean][] = [
      ['system-administrator', ['system-administrator'], true],
      ['management-viewer', ['management-viewer'], true],
      ['both roles', ['management-viewer', 'system-administrator'], true],
      ['ordinary employee', [], '/'],
    ];

    it.each(cases)('%s', async (_label, roles, expected) => {
      const { auth, router } = setup();
      auth.setSession('tok', user(roles));
      expect(urlOf(await run(reportsGuard), router)).toBe(expected);
    });

    it('vendor staff without a role is refused', async () => {
      const { auth, router } = setup();
      auth.setSession('tok', user([], 7));
      expect(urlOf(await run(reportsGuard), router)).toBe('/');
    });

    it('sends an unauthenticated user to login', async () => {
      const { router } = setup();
      expect(urlOf(await run(reportsGuard), router)).toBe('/login');
    });
  });
});
