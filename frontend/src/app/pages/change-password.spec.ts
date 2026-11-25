import { TestBed } from '@angular/core/testing';
import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter, Router } from '@angular/router';
import { authInterceptor } from '../auth/auth.interceptor';
import { AuthService, CurrentUser } from '../auth/auth.service';
import { ChangePasswordPage } from './change-password';

function user(mustChangePassword: boolean): CurrentUser {
  return {
    employee_id: 'EMP-1001',
    email: 'requestor@pss.test',
    name: 'Rina',
    roles: [],
    vendor_id: null,
    access: 'internal',
    must_change_password: mustChangePassword,
  };
}

function setup(mustChangePassword = true) {
  TestBed.configureTestingModule({
    imports: [ChangePasswordPage],
    providers: [
      provideHttpClient(withInterceptors([authInterceptor])),
      provideHttpClientTesting(),
      provideRouter([]),
    ],
  });
  const auth = TestBed.inject(AuthService);
  auth.setSession('tok', user(mustChangePassword));
  const navigate = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);
  const fixture = TestBed.createComponent(ChangePasswordPage);
  fixture.detectChanges();
  const el = fixture.nativeElement as HTMLElement;

  function fill(current: string, next: string, confirmation: string) {
    for (const [name, value] of [
      ['current_password', current],
      ['password', next],
      ['password_confirmation', confirmation],
    ]) {
      const input = el.querySelector<HTMLInputElement>(`input[name="${name}"]`)!;
      input.value = value;
      input.dispatchEvent(new Event('input'));
    }
    el.querySelector('form')!.dispatchEvent(new Event('submit'));
    fixture.detectChanges();
  }

  return { auth, fixture, el, fill, navigate, http: TestBed.inject(HttpTestingController) };
}

describe('ChangePasswordPage', () => {
  beforeEach(() => localStorage.clear());
  afterEach(() => localStorage.clear());

  it('tells a user with a temporary password to choose a new one', () => {
    const { el } = setup(true);
    expect(el.querySelector('h1')?.textContent).toContain('Change password');
    expect(el.textContent).toContain('Your password is temporary');
  });

  it('posts the change, updates the signed-in user and goes to the home page', async () => {
    const { auth, fill, http, navigate, fixture } = setup(true);
    fill('Temporary-Pass-1', 'Brand-New-Pass-2', 'Brand-New-Pass-2');

    const request = http.expectOne('/api/auth/password');
    expect(request.request.method).toBe('POST');
    expect(request.request.headers.get('Authorization')).toBe('Bearer tok');
    expect(request.request.body).toEqual({
      current_password: 'Temporary-Pass-1',
      password: 'Brand-New-Pass-2',
      password_confirmation: 'Brand-New-Pass-2',
    });
    request.flush(user(false));
    await fixture.whenStable();

    expect(auth.mustChangePassword()).toBe(false);
    expect(navigate).toHaveBeenCalledWith('/');
  });

  it('shows the field errors from a 422 and stays on the page', async () => {
    const { auth, fill, http, navigate, fixture, el } = setup(true);
    fill('Wrong-Pass-9', 'Brand-New-Pass-2', 'Brand-New-Pass-2');

    http.expectOne('/api/auth/password').flush(
      {
        message: 'The current password is incorrect.',
        errors: { current_password: ['The current password is incorrect.'] },
      },
      { status: 422, statusText: 'Unprocessable Content' },
    );
    await fixture.whenStable();
    fixture.detectChanges();

    expect(el.querySelector('[role="alert"]')?.textContent).toContain(
      'The current password is incorrect.',
    );
    expect(auth.mustChangePassword()).toBe(true);
    expect(navigate).not.toHaveBeenCalled();
  });

  it('does not submit when the new passwords do not match', () => {
    const { fill, http, el } = setup(true);
    fill('Temporary-Pass-1', 'Brand-New-Pass-2', 'Other-Pass-3');

    http.expectNone('/api/auth/password');
    expect(el.querySelector('[role="alert"]')?.textContent).toContain('do not match');
  });

  it('does not submit with an empty field', () => {
    const { fill, http } = setup(false);
    fill('', 'Brand-New-Pass-2', 'Brand-New-Pass-2');
    http.expectNone('/api/auth/password');
  });
});
