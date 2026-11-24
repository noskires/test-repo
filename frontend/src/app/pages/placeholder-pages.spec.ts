import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { Type } from '@angular/core';
import { AuthService } from '../auth/auth.service';
import { AdminPage, HomePage, ReportsPage } from './placeholder-pages';

describe('placeholder pages', () => {
  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])],
    });
    TestBed.inject(AuthService).setSession('tok', {
      employee_id: 'EMP-1',
      name: 'Ivy Internal',
      roles: [],
      vendor_id: null,
      access: 'internal',
    });
  });

  afterEach(() => localStorage.clear());

  const cases: [string, Type<unknown>, string][] = [
    ['Home', HomePage, 'Welcome, Ivy Internal'],
    ['Admin', AdminPage, 'Admin'],
    ['Reports', ReportsPage, 'Reports'],
  ];

  it.each(cases)('%s has a page head with its title and a sub line', (_label, page, title) => {
    const fixture = TestBed.createComponent(page);
    fixture.detectChanges();
    const el = fixture.nativeElement as HTMLElement;

    expect(el.querySelector('.page-head h1')?.textContent?.trim()).toBe(title);
    expect(el.querySelector('.page-head .sub')?.textContent?.trim()).not.toBe('');
    expect(el.querySelector('.panel')).not.toBeNull();
  });
});
