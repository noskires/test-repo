import { TestBed } from '@angular/core/testing';
import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter, Router } from '@angular/router';
import { authInterceptor } from '../auth/auth.interceptor';
import { AuthService, TOKEN_STORAGE_KEY } from '../auth/auth.service';
import { PendingPage } from './pending';

describe('PendingPage', () => {
  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({
      imports: [PendingPage],
      providers: [
        provideHttpClient(withInterceptors([authInterceptor])),
        provideHttpClientTesting(),
        provideRouter([]),
      ],
    });
    TestBed.inject(AuthService).setSession('tok', {
      employee_id: 'EMP-P',
      name: 'Paula Pending',
      roles: [],
      vendor_id: null,
      access: 'pending',
    });
  });

  afterEach(() => localStorage.clear());

  it('says the account is waiting for an administrator and shows the name', () => {
    const fixture = TestBed.createComponent(PendingPage);
    fixture.detectChanges();
    const el = fixture.nativeElement as HTMLElement;

    expect(el.querySelector('h1')?.textContent).toContain('Waiting for an administrator');
    expect(el.textContent).toContain('Paula Pending');
  });

  it('the Log out button signs out and goes to login', () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);
    const fixture = TestBed.createComponent(PendingPage);
    fixture.detectChanges();

    (fixture.nativeElement as HTMLElement).querySelector<HTMLButtonElement>('button')!.click();
    TestBed.inject(HttpTestingController)
      .expectOne('/api/auth/logout')
      .flush(null, { status: 204, statusText: 'No Content' });

    expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull();
    expect(navigate).toHaveBeenCalledWith('/login');
  });
});
