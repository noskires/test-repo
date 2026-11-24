import { TestBed } from '@angular/core/testing';
import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter, Router } from '@angular/router';
import { App } from './app';
import { authInterceptor } from './auth/auth.interceptor';
import { AuthService, TOKEN_STORAGE_KEY } from './auth/auth.service';

describe('App', () => {
  beforeEach(async () => {
    localStorage.clear();
    await TestBed.configureTestingModule({
      imports: [App],
      providers: [
        provideHttpClient(withInterceptors([authInterceptor])),
        provideHttpClientTesting(),
        provideRouter([]),
      ],
    }).compileComponents();
  });

  afterEach(() => localStorage.clear());

  it('shows no menu or logout before sign-in', () => {
    const fixture = TestBed.createComponent(App);
    fixture.detectChanges();
    const el = fixture.nativeElement as HTMLElement;
    expect(el.querySelector('header')).toBeNull();
    expect(el.querySelector('router-outlet')).not.toBeNull();
  });

  it('shows the user name, the role menu and logout once signed in', () => {
    TestBed.inject(AuthService).setSession('tok', {
      employee_id: 'EMP-9002',
      name: 'Mario Management Viewer',
      roles: ['management-viewer'],
      vendor_id: null,
    });
    const fixture = TestBed.createComponent(App);
    fixture.detectChanges();
    const el = fixture.nativeElement as HTMLElement;

    expect(el.querySelector('header')?.textContent).toContain('Mario Management Viewer');
    expect(Array.from(el.querySelectorAll('nav a')).map((a) => a.textContent?.trim())).toEqual([
      'Dashboard',
      'Reports',
    ]);
    expect(el.querySelector('header button')?.textContent).toContain('Log out');
  });

  it('the logout button calls the API, clears the token and goes to login', () => {
    const auth = TestBed.inject(AuthService);
    auth.setSession('tok', { employee_id: 'EMP-1', name: 'X', roles: [], vendor_id: null });
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);
    const fixture = TestBed.createComponent(App);
    fixture.detectChanges();

    (fixture.nativeElement as HTMLElement)
      .querySelector<HTMLButtonElement>('header button')!
      .click();
    TestBed.inject(HttpTestingController)
      .expectOne('/api/auth/logout')
      .flush(null, { status: 204, statusText: 'No Content' });
    fixture.detectChanges();

    expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull();
    expect(navigate).toHaveBeenCalledWith('/login');
    expect((fixture.nativeElement as HTMLElement).querySelector('header')).toBeNull();
  });
});
