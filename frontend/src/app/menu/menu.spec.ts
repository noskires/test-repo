import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { AuthService } from '../auth/auth.service';
import { Menu, menuFor, menuGroupsFor } from './menu';

describe('menuFor', () => {
  const cases: [string, string[], string[]][] = [
    ['ordinary employee', [], ['Dashboard']],
    ['management-viewer', ['management-viewer'], ['Dashboard', 'Reports']],
    ['system-administrator', ['system-administrator'], ['Dashboard', 'Admin', 'Reports']],
    [
      'both roles',
      ['management-viewer', 'system-administrator'],
      ['Dashboard', 'Admin', 'Reports'],
    ],
  ];

  it.each(cases)('%s sees %j', (_label, roles, expected) => {
    expect(menuFor(roles).map((item) => item.label)).toEqual(expected);
  });

  it('links each entry to its route', () => {
    expect(menuFor(['system-administrator']).map((item) => item.path)).toEqual([
      '/',
      '/admin',
      '/reports',
    ]);
  });
});

describe('menuGroupsFor', () => {
  function shape(roles: string[]) {
    return menuGroupsFor(roles).map((group) => [group.label, group.items.map((i) => i.label)]);
  }

  it('gives an ordinary employee only the Monitor group', () => {
    expect(shape([])).toEqual([['Monitor', ['Dashboard']]]);
  });

  it('gives a management viewer Monitor and Admin with Reports only', () => {
    expect(shape(['management-viewer'])).toEqual([
      ['Monitor', ['Dashboard']],
      ['Admin', ['Reports']],
    ]);
  });

  it('gives a system administrator Admin and Reports under Admin', () => {
    expect(shape(['system-administrator'])).toEqual([
      ['Monitor', ['Dashboard']],
      ['Admin', ['Admin', 'Reports']],
    ]);
  });

  it('never shows a group without entries, such as Repairs today', () => {
    for (const roles of [[], ['management-viewer'], ['system-administrator']]) {
      expect(menuGroupsFor(roles).map((group) => group.label)).not.toContain('Repairs');
      expect(menuGroupsFor(roles).every((group) => group.items.length > 0)).toBe(true);
    }
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
      auth.setSession('tok', {
        employee_id: 'EMP-1',
        name: 'Someone',
        roles,
        vendor_id: null,
        access: 'internal',
      });
    }
    const fixture = TestBed.createComponent(Menu);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  const links = (el: HTMLElement) =>
    Array.from(el.querySelectorAll('nav a')).map((a) => a.textContent?.trim());
  const headings = (el: HTMLElement) =>
    Array.from(el.querySelectorAll('nav .navsec')).map((h) => h.textContent?.trim());

  afterEach(() => localStorage.clear());

  it('shows nothing when nobody is signed in', () => {
    const el = render(null);
    expect(el.querySelector('nav')).toBeNull();
    expect(links(el)).toEqual([]);
  });

  it('shows only the dashboard, under Monitor, to an ordinary employee', () => {
    const el = render([]);
    expect(links(el)).toEqual(['Dashboard']);
    expect(headings(el)).toEqual(['Monitor']);
  });

  it('shows Monitor and Admin with Reports only to a management viewer', () => {
    const el = render(['management-viewer']);
    expect(links(el)).toEqual(['Dashboard', 'Reports']);
    expect(headings(el)).toEqual(['Monitor', 'Admin']);
  });

  it('shows Admin and Reports under Admin to a system administrator', () => {
    const el = render(['system-administrator']);
    expect(links(el)).toEqual(['Dashboard', 'Admin', 'Reports']);
    expect(headings(el)).toEqual(['Monitor', 'Admin']);
  });

  it('gives every entry a 16px lucide icon', () => {
    const el = render(['system-administrator']);
    const icons = Array.from(el.querySelectorAll('nav a svg'));
    expect(icons).toHaveLength(3);
    for (const icon of icons) {
      expect(icon.getAttribute('width')).toBe('16');
      expect(icon.getAttribute('height')).toBe('16');
      expect(icon.classList).toContain('lucide');
      expect(icon.getAttribute('aria-hidden')).toBe('true');
    }
  });
});
