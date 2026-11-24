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

  function fill(employeeId: string, password: string) {
    const id = el.querySelector<HTMLInputElement>('input[name="employee_id"]')!;
    const pw = el.querySelector<HTMLInputElement>('input[name="password"]')!;
    id.value = employeeId;
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

  it('signs in and goes to the home page', async () => {
    const { fill, http, navigate, fixture } = setup();
    fill('EMP-9001', 'password');

    const login = http.expectOne('/api/auth/login');
    expect(login.request.body).toEqual({ employee_id: 'EMP-9001', password: 'password' });
    login.flush({ access_token: 'tok', token_type: 'bearer', expires_in: 3600 });
    http
      .expectOne('/api/auth/me')
      .flush({
        employee_id: 'EMP-9001',
        name: 'Ada',
        roles: ['system-administrator'],
        vendor_id: null,
      });
    await fixture.whenStable();

    expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBe('tok');
    expect(navigate).toHaveBeenCalledWith('/');
  });

  it('shows the error on 401 and stores nothing', async () => {
    const { fill, http, navigate, fixture, el } = setup();
    fill('EMP-9001', 'wrong');

    http
      .expectOne('/api/auth/login')
      .flush(
        { message: 'Invalid employee ID or password.' },
        { status: 401, statusText: 'Unauthorized' },
      );
    await fixture.whenStable();
    fixture.detectChanges();

    expect(el.querySelector('[role="alert"]')?.textContent).toContain(
      'Invalid employee ID or password.',
    );
    expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull();
    expect(navigate).not.toHaveBeenCalled();
    http.verify();
  });

  it('shows a generic error when the server fails', async () => {
    const { fill, http, fixture, el } = setup();
    fill('EMP-9001', 'password');

    http.expectOne('/api/auth/login').flush({}, { status: 500, statusText: 'Server Error' });
    await fixture.whenStable();
    fixture.detectChanges();

    expect(el.querySelector('[role="alert"]')?.textContent).toContain('Sign-in failed');
    expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull();
  });

  it('does not submit with an empty employee ID or password', () => {
    const { fill, http } = setup();
    fill('', '');
    http.expectNone('/api/auth/login');
  });
});
