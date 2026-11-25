import { TestBed } from '@angular/core/testing';
import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter, Router } from '@angular/router';
import { authInterceptor } from '../auth/auth.interceptor';
import { TOKEN_STORAGE_KEY } from '../auth/auth.service';
import { LoginPage } from './login';

function setup() {
  TestBed.configureTestingModule({
    imports: [LoginPage],
    providers: [
      provideHttpClient(withInterceptors([authInterceptor])),
      provideHttpClientTesting(),
      provideRouter([]),
    ],
  });
  const router = TestBed.inject(Router);
  const navigate = vi.spyOn(router, 'navigateByUrl').mockResolvedValue(true);
  const fixture = TestBed.createComponent(LoginPage);
  fixture.detectChanges();
  const el = fixture.nativeElement as HTMLElement;

  function fill(email: string, password: string) {
    const id = el.querySelector<HTMLInputElement>('input[name="email"]')!;
    const pw = el.querySelector<HTMLInputElement>('input[name="password"]')!;
    id.value = email;
    id.dispatchEvent(new Event('input'));
    pw.value = password;
    pw.dispatchEvent(new Event('input'));
    el.querySelector('form')!.dispatchEvent(new Event('submit'));
    fixture.detectChanges();
  }

  return { fixture, el, fill, navigate, http: TestBed.inject(HttpTestingController) };
}

describe('LoginPage', () => {
  beforeEach(() => localStorage.clear());
  afterEach(() => localStorage.clear());

  function me(mustChangePassword: boolean) {
    return {
      employee_id: 'EMP-9001',
      email: 'admin@pss.test',
      name: 'Ada',
      roles: ['system-administrator'],
      vendor_id: null,
      access: 'internal',
      must_change_password: mustChangePassword,
    };
  }

  it('signs in with email and password and goes to the home page', async () => {
    const { fill, http, navigate, fixture } = setup();
    fill(' admin@pss.test ', 'password');

    const login = http.expectOne('/api/auth/login');
    expect(login.request.body).toEqual({ email: 'admin@pss.test', password: 'password' });
    login.flush({
      access_token: 'tok',
      token_type: 'bearer',
      expires_in: 3600,
      must_change_password: false,
    });
    http.expectOne('/api/auth/me').flush(me(false));
    await fixture.whenStable();

    expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBe('tok');
    expect(navigate).toHaveBeenCalledWith('/');
  });

  it('goes to the change-password screen after signing in with a temporary password', async () => {
    const { fill, http, navigate, fixture } = setup();
    fill('admin@pss.test', 'Temporary-Pass-1');

    http.expectOne('/api/auth/login').flush({
      access_token: 'tok',
      token_type: 'bearer',
      expires_in: 3600,
      must_change_password: true,
    });
    http.expectOne('/api/auth/me').flush(me(true));
    await fixture.whenStable();

    expect(navigate).toHaveBeenCalledWith('/change-password');
    expect(navigate).not.toHaveBeenCalledWith('/');
  });

  it('shows the error on 401 and stores nothing', async () => {
    const { fill, http, navigate, fixture, el } = setup();
    fill('admin@pss.test', 'wrong');

    http
      .expectOne('/api/auth/login')
      .flush(
        { message: 'Invalid email or password.' },
        { status: 401, statusText: 'Unauthorized' },
      );
    await fixture.whenStable();
    fixture.detectChanges();

    expect(el.querySelector('[role="alert"]')?.textContent).toContain('Invalid email or password.');
    expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull();
    expect(navigate).not.toHaveBeenCalled();
    http.verify();
  });

  it('shows the backend message on 403 (not set up in PSS) and stores nothing', async () => {
    const { fill, http, navigate, fixture, el } = setup();
    fill('someone@pss.test', 'password');

    http
      .expectOne('/api/auth/login')
      .flush(
        { message: "Your account isn't set up in PSS yet. Ask an administrator." },
        { status: 403, statusText: 'Forbidden' },
      );
    await fixture.whenStable();
    fixture.detectChanges();

    expect(el.querySelector('[role="alert"]')?.textContent).toContain(
      "Your account isn't set up in PSS yet. Ask an administrator.",
    );
    expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull();
    expect(navigate).not.toHaveBeenCalled();
    http.verify();
  });

  it('shows a generic error when the server fails', async () => {
    const { fill, http, fixture, el } = setup();
    fill('admin@pss.test', 'password');

    http.expectOne('/api/auth/login').flush({}, { status: 500, statusText: 'Server Error' });
    await fixture.whenStable();
    fixture.detectChanges();

    expect(el.querySelector('[role="alert"]')?.textContent).toContain('Sign-in failed');
    expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull();
  });

  it('does not submit with an empty email or password', () => {
    const { fill, http } = setup();
    fill('', '');
    http.expectNone('/api/auth/login');
  });

  it('uses the card style with Sign in as the orange main action', () => {
    const { el } = setup();
    expect(el.querySelector('.panel h1')?.textContent).toContain('Sign in to PSS');
    const button = el.querySelector<HTMLButtonElement>('button[type="submit"]')!;
    expect(button.classList).toContain('btn');
    expect(button.classList).toContain('accent');
    expect(button.textContent).toContain('Sign in');
    expect(el.querySelector<HTMLInputElement>('input[name="email"]')!.type).toBe('email');
    expect(el.querySelector('input[name="employee_id"]')).toBeNull();
  });
});
