import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { AuthService } from '../auth/auth.service';
import { Menu, menuFor } from './menu';

describe('menuFor', () => {
  const cases: [string, string[], string[]][] = [
    ['ordinary employee', [], ['Dashboard']],
    ['management-viewer', ['management-viewer'], ['Dashboard', 'Reports']],
    ['system-administrator', ['system-administrator'], ['Dashboard', 'Reports', 'Admin']],
    [
      'both roles',
      ['management-viewer', 'system-administrator'],
      ['Dashboard', 'Reports', 'Admin'],
    ],
  ];

  it.each(cases)('%s sees %j', (_label, roles, expected) => {
    expect(menuFor(roles).map((item) => item.label)).toEqual(expected);
  });

  it('links each entry to its route', () => {
    expect(menuFor(['system-administrator']).map((item) => item.path)).toEqual([
      '/',
      '/reports',
      '/admin',
    ]);
  });
});

describe('Menu component', () => {
  function render(roles: string[] | null) {
    TestBed.configureTestingModule({
      imports: [Menu],
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])],
    });
    const auth = TestBed.inject(AuthService);
    if (roles) {
      auth.setSession('tok', { employee_id: 'EMP-1', name: 'Someone', roles, vendor_id: null, access: 'internal' });
    }
    const fixture = TestBed.createComponent(Menu);
    fixture.detectChanges();
    const el = fixture.nativeElement as HTMLElement;
    return Array.from(el.querySelectorAll('a')).map((a) => a.textContent?.trim());
  }

  afterEach(() => localStorage.clear());

  it('shows nothing when nobody is signed in', () => {
    expect(render(null)).toEqual([]);
  });

  it('shows only the dashboard to an ordinary employee', () => {
    expect(render([])).toEqual(['Dashboard']);
  });

  it('shows Reports to a management viewer', () => {
    expect(render(['management-viewer'])).toEqual(['Dashboard', 'Reports']);
  });

  it('shows Reports and Admin to a system administrator', () => {
    expect(render(['system-administrator'])).toEqual(['Dashboard', 'Reports', 'Admin']);
  });
});
