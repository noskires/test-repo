import { TestBed } from '@angular/core/testing';
import { PLATFORM_ID } from '@angular/core';
import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter, Router } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { AuthService, TOKEN_STORAGE_KEY } from './auth.service';
import { authInterceptor } from './auth.interceptor';

const ME = {
  employee_id: 'EMP-9001',
  name: 'Ada Administrator',
  roles: ['system-administrator'],
  vendor_id: null,
  access: 'internal',
  email: null,
  must_change_password: false,
};

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

describe('AuthService', () => {
  beforeEach(() => localStorage.clear());
  afterEach(() => localStorage.clear());

  it('login posts the credentials, stores the token and loads the signed-in user', async () => {
    const { auth, http } = setup();
    const done = firstValueFrom(auth.login('admin@pss.test', 'password'));

    const login = http.expectOne('/api/auth/login');
    expect(login.request.method).toBe('POST');
    expect(login.request.body).toEqual({ email: 'admin@pss.test', password: 'password' });
    login.flush({ access_token: 'tok-1', token_type: 'bearer', expires_in: 3600 });

    const me = http.expectOne('/api/auth/me');
    expect(me.request.method).toBe('GET');
    expect(me.request.headers.get('Authorization')).toBe('Bearer tok-1');
    me.flush(ME);

    await expect(done).resolves.toEqual(ME);
    expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBe('tok-1');
    expect(auth.user()).toEqual(ME);
    expect(auth.isSignedIn()).toBe(true);
    http.verify();
  });

  it('login with wrong credentials (401) stores nothing', async () => {
    const { auth, http } = setup();
    const done = firstValueFrom(auth.login('admin@pss.test', 'wrong'));

    http
      .expectOne('/api/auth/login')
      .flush(
        { message: 'Invalid email or password.' },
        { status: 401, statusText: 'Unauthorized' },
      );

    await expect(done).rejects.toMatchObject({ status: 401 });
    expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull();
    expect(auth.user()).toBeNull();
    expect(auth.isSignedIn()).toBe(false);
    http.verify();
  });

  it('logout calls the API, clears the token and goes to the login screen', async () => {
    localStorage.setItem(TOKEN_STORAGE_KEY, 'tok-1');
    const { auth, http, router } = setup();
    const navigate = vi.spyOn(router, 'navigateByUrl').mockResolvedValue(true);

    const done = firstValueFrom(auth.logout(), { defaultValue: undefined });
    const req = http.expectOne('/api/auth/logout');
    expect(req.request.method).toBe('POST');
    expect(req.request.headers.get('Authorization')).toBe('Bearer tok-1');
    req.flush(null, { status: 204, statusText: 'No Content' });
    await done;

    expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull();
    expect(auth.user()).toBeNull();
    expect(navigate).toHaveBeenCalledWith('/login');
    http.verify();
  });

  it('logout still clears the session when the API call fails', async () => {
    localStorage.setItem(TOKEN_STORAGE_KEY, 'tok-1');
    const { auth, http, router } = setup();
    const navigate = vi.spyOn(router, 'navigateByUrl').mockResolvedValue(true);

    const done = firstValueFrom(auth.logout(), { defaultValue: undefined });
    http.expectOne('/api/auth/logout').flush(null, { status: 500, statusText: 'Server Error' });
    await done;

    expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull();
    expect(navigate).toHaveBeenCalledWith('/login');
  });

  it('reads an existing token from localStorage in the browser', () => {
    localStorage.setItem(TOKEN_STORAGE_KEY, 'tok-saved');
    const { auth } = setup();
    expect(auth.token()).toBe('tok-saved');
    expect(auth.hasToken()).toBe(true);
  });

  it('never touches localStorage on the server', () => {
    localStorage.setItem(TOKEN_STORAGE_KEY, 'tok-saved');
    const getItem = vi.spyOn(Storage.prototype, 'getItem');
    const setItem = vi.spyOn(Storage.prototype, 'setItem');
    const removeItem = vi.spyOn(Storage.prototype, 'removeItem');
    const { auth } = setup('server');

    expect(auth.token()).toBeNull();
    auth.storeToken('tok-new');
    auth.clearSession();

    expect(getItem).not.toHaveBeenCalled();
    expect(setItem).not.toHaveBeenCalled();
    expect(removeItem).not.toHaveBeenCalled();
    vi.restoreAllMocks();
  });

  it('hasAnyRole follows the roles from /api/auth/me', async () => {
    const { auth, http } = setup();
    expect(auth.hasAnyRole(['system-administrator'])).toBe(false);

    const done = firstValueFrom(auth.login('viewer@pss.test', 'password'));
    http
      .expectOne('/api/auth/login')
      .flush({ access_token: 't', token_type: 'bearer', expires_in: 3600 });
    http.expectOne('/api/auth/me').flush({ ...ME, roles: ['management-viewer'] });
    await done;

    expect(auth.hasAnyRole(['system-administrator'])).toBe(false);
    expect(auth.hasAnyRole(['system-administrator', 'management-viewer'])).toBe(true);
  });

  it('changePassword posts the change and replaces the signed-in user (issue 44)', async () => {
    const { auth, http } = setup();
    auth.setSession('tok-1', { ...ME, access: 'internal', must_change_password: true });
    expect(auth.mustChangePassword()).toBe(true);

    const done = firstValueFrom(
      auth.changePassword({
        current_password: 'Temporary-Pass-1',
        password: 'Brand-New-Pass-2',
        password_confirmation: 'Brand-New-Pass-2',
      }),
    );
    const request = http.expectOne('/api/auth/password');
    expect(request.request.method).toBe('POST');
    expect(request.request.headers.get('Authorization')).toBe('Bearer tok-1');
    request.flush({ ...ME, must_change_password: false });
    await done;

    expect(auth.mustChangePassword()).toBe(false);
    http.verify();
  });
});
