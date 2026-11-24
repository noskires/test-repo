import { TestBed } from '@angular/core/testing';
import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter, Router } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { authInterceptor } from './auth.interceptor';
import { AuthService, TOKEN_STORAGE_KEY } from './auth.service';

const UNAUTHORIZED = { status: 401, statusText: 'Unauthorized' };

function setup() {
  TestBed.configureTestingModule({
    providers: [
      provideHttpClient(withInterceptors([authInterceptor])),
      provideHttpClientTesting(),
      provideRouter([]),
    ],
  });
  const router = TestBed.inject(Router);
  return {
    client: TestBed.inject(HttpClient),
    http: TestBed.inject(HttpTestingController),
    auth: TestBed.inject(AuthService),
    navigate: vi.spyOn(router, 'navigateByUrl').mockResolvedValue(true),
  };
}

describe('authInterceptor', () => {
  beforeEach(() => localStorage.clear());
  afterEach(() => localStorage.clear());

  it('adds the Bearer token to API requests', () => {
    localStorage.setItem(TOKEN_STORAGE_KEY, 'tok-1');
    const { client, http } = setup();

    client.get('/api/dashboard').subscribe();

    const req = http.expectOne('/api/dashboard');
    expect(req.request.headers.get('Authorization')).toBe('Bearer tok-1');
    req.flush({});
    http.verify();
  });

  it('sends no Authorization header when there is no token', () => {
    const { client, http } = setup();

    client.get('/api/dashboard').subscribe();

    const req = http.expectOne('/api/dashboard');
    expect(req.request.headers.has('Authorization')).toBe(false);
    req.flush({});
  });

  it('does not send the token to non-API URLs', () => {
    localStorage.setItem(TOKEN_STORAGE_KEY, 'tok-1');
    const { client, http } = setup();

    client.get('https://example.test/api/thing').subscribe();
    client.get('/assets/config.json').subscribe();

    expect(
      http.expectOne('https://example.test/api/thing').request.headers.has('Authorization'),
    ).toBe(false);
    expect(http.expectOne('/assets/config.json').request.headers.has('Authorization')).toBe(false);
  });

  it('on 401 refreshes once, stores the new token and retries the request', async () => {
    localStorage.setItem(TOKEN_STORAGE_KEY, 'expired');
    const { client, http, navigate } = setup();

    const result = firstValueFrom(client.get('/api/dashboard'));
    http.expectOne('/api/dashboard').flush({ message: 'Unauthenticated.' }, UNAUTHORIZED);

    const refresh = http.expectOne('/api/auth/refresh');
    expect(refresh.request.method).toBe('POST');
    expect(refresh.request.headers.get('Authorization')).toBe('Bearer expired');
    refresh.flush({ access_token: 'fresh', token_type: 'bearer', expires_in: 3600 });

    const retry = http.expectOne('/api/dashboard');
    expect(retry.request.headers.get('Authorization')).toBe('Bearer fresh');
    retry.flush({ ok: true });

    await expect(result).resolves.toEqual({ ok: true });
    expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBe('fresh');
    expect(navigate).not.toHaveBeenCalled();
    http.verify();
  });

  it('on 401 when the refresh fails, clears the token and redirects to login', async () => {
    localStorage.setItem(TOKEN_STORAGE_KEY, 'expired');
    const { client, http, auth, navigate } = setup();

    const result = firstValueFrom(client.get('/api/dashboard'));
    http.expectOne('/api/dashboard').flush({}, UNAUTHORIZED);
    http.expectOne('/api/auth/refresh').flush({}, UNAUTHORIZED);

    await expect(result).rejects.toMatchObject({ status: 401 });
    expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull();
    expect(auth.user()).toBeNull();
    expect(navigate).toHaveBeenCalledWith('/login');
    http.verify();
  });

  it('refreshes only once: a 401 on the retried request redirects to login', async () => {
    localStorage.setItem(TOKEN_STORAGE_KEY, 'expired');
    const { client, http, navigate } = setup();

    const result = firstValueFrom(client.get('/api/dashboard'));
    http.expectOne('/api/dashboard').flush({}, UNAUTHORIZED);
    http
      .expectOne('/api/auth/refresh')
      .flush({ access_token: 'fresh', token_type: 'bearer', expires_in: 3600 });
    http.expectOne('/api/dashboard').flush({}, UNAUTHORIZED);

    await expect(result).rejects.toMatchObject({ status: 401 });
    http.expectNone('/api/auth/refresh');
    expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull();
    expect(navigate).toHaveBeenCalledWith('/login');
    http.verify();
  });

  it('shares one refresh between requests that fail together', async () => {
    localStorage.setItem(TOKEN_STORAGE_KEY, 'expired');
    const { client, http } = setup();

    const a = firstValueFrom(client.get('/api/a'));
    const b = firstValueFrom(client.get('/api/b'));
    http.expectOne('/api/a').flush({}, UNAUTHORIZED);
    http.expectOne('/api/b').flush({}, UNAUTHORIZED);

    http
      .expectOne('/api/auth/refresh')
      .flush({ access_token: 'fresh', token_type: 'bearer', expires_in: 3600 });
    http.expectOne('/api/a').flush('A');
    http.expectOne('/api/b').flush('B');

    await expect(a).resolves.toBe('A');
    await expect(b).resolves.toBe('B');
    http.verify();
  });

  it('does not refresh or redirect on a 401 from the login request', async () => {
    const { client, http, navigate } = setup();

    const result = firstValueFrom(
      client.post('/api/auth/login', { employee_id: 'x', password: 'y' }),
    );
    http
      .expectOne('/api/auth/login')
      .flush({ message: 'Invalid employee ID or password.' }, UNAUTHORIZED);

    await expect(result).rejects.toMatchObject({ status: 401 });
    http.expectNone('/api/auth/refresh');
    expect(navigate).not.toHaveBeenCalled();
    http.verify();
  });

  it('passes non-401 errors through untouched', async () => {
    localStorage.setItem(TOKEN_STORAGE_KEY, 'tok-1');
    const { client, http, navigate } = setup();

    const result = firstValueFrom(client.get('/api/admin/users'));
    http.expectOne('/api/admin/users').flush({}, { status: 403, statusText: 'Forbidden' });

    await expect(result).rejects.toMatchObject({ status: 403 });
    http.expectNone('/api/auth/refresh');
    expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBe('tok-1');
    expect(navigate).not.toHaveBeenCalled();
  });
});
