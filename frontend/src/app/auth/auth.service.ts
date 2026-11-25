import { computed, inject, Injectable, PLATFORM_ID, signal } from '@angular/core';
import { isPlatformBrowser } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { Router } from '@angular/router';
import { catchError, finalize, map, Observable, of, shareReplay, switchMap, tap } from 'rxjs';

/** localStorage key for the PSS access token (issue 43, Q10). */
export const TOKEN_STORAGE_KEY = 'pss.access_token';

export const ROLE_SYSTEM_ADMINISTRATOR = 'system-administrator';
export const ROLE_MANAGEMENT_VIEWER = 'management-viewer';

/**
 * What a user may use (issue 46). A pending user can sign in but sees only the
 * "waiting for an administrator" page until a System Administrator classifies them.
 */
export type UserAccess = 'internal' | 'vendor' | 'pending';

/** Shape of `GET /api/auth/me`. */
export interface CurrentUser {
  employee_id: string;
  email: string | null;
  name: string;
  roles: string[];
  vendor_id: number | null;
  access: UserAccess;
  /** A temporary password (new user or admin reset) must be changed before using PSS (issue 44). */
  must_change_password: boolean;
}

/** Shape of `POST /api/auth/login` and `POST /api/auth/refresh`. */
export interface TokenResponse {
  access_token: string;
  token_type: string;
  expires_in: number;
  must_change_password: boolean;
}

/** Body of `POST /api/auth/password` (issue 44). */
export interface ChangePasswordRequest {
  current_password: string;
  password: string;
  password_confirmation: string;
}

export const AUTH_API = {
  login: '/api/auth/login',
  refresh: '/api/auth/refresh',
  logout: '/api/auth/logout',
  me: '/api/auth/me',
  password: '/api/auth/password',
} as const;

/**
 * Holds the JWT and the signed-in user. The token lives in localStorage, read and
 * written only in the browser: during server rendering there is never a token.
 */
@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly http = inject(HttpClient);
  private readonly router = inject(Router);
  private readonly isBrowser = isPlatformBrowser(inject(PLATFORM_ID));

  private readonly tokenSignal = signal<string | null>(this.readStoredToken());
  private readonly userSignal = signal<CurrentUser | null>(null);

  private refreshInFlight: Observable<string> | null = null;
  private userInFlight: Observable<CurrentUser | null> | null = null;

  readonly token = this.tokenSignal.asReadonly();
  readonly user = this.userSignal.asReadonly();
  readonly isSignedIn = computed(() => this.tokenSignal() !== null && this.userSignal() !== null);
  readonly isPending = computed(() => this.userSignal()?.access === 'pending');
  readonly mustChangePassword = computed(() => this.userSignal()?.must_change_password === true);

  hasToken(): boolean {
    return this.tokenSignal() !== null;
  }

  hasAnyRole(roles: readonly string[]): boolean {
    const mine = this.userSignal()?.roles ?? [];
    return roles.some((role) => mine.includes(role));
  }

  /** Signs in with email and password, stores the token, then loads the signed-in user. Stores nothing on failure. */
  login(email: string, password: string): Observable<CurrentUser> {
    return this.http.post<TokenResponse>(AUTH_API.login, { email, password }).pipe(
      tap((response) => this.storeToken(response.access_token)),
      switchMap(() => this.http.get<CurrentUser>(AUTH_API.me)),
      tap((user) => this.userSignal.set(user)),
      catchError((error) => {
        this.clearSession();
        throw error;
      }),
    );
  }

  /**
   * Changes the signed-in user's own password (issue 44). On success the server clears
   * `must_change_password` and returns the updated user, which replaces the cached one.
   */
  changePassword(request: ChangePasswordRequest): Observable<CurrentUser> {
    return this.http
      .post<CurrentUser>(AUTH_API.password, request)
      .pipe(tap((user) => this.userSignal.set(user)));
  }

  /** Calls the logout API, then always clears the session and goes to the login screen. */
  logout(): Observable<void> {
    return this.http.post<void>(AUTH_API.logout, null).pipe(
      catchError(() => of(undefined)),
      map(() => undefined),
      finalize(() => {
        this.clearSession();
        void this.router.navigateByUrl('/login');
      }),
    );
  }

  /**
   * Exchanges the current (possibly expired) token for a new one. Requests that fail
   * together share one refresh call.
   */
  refresh(): Observable<string> {
    if (!this.refreshInFlight) {
      this.refreshInFlight = this.http.post<TokenResponse>(AUTH_API.refresh, null).pipe(
        map((response) => response.access_token),
        tap((token) => this.storeToken(token)),
        finalize(() => (this.refreshInFlight = null)),
        shareReplay({ bufferSize: 1, refCount: false }),
      );
    }
    return this.refreshInFlight;
  }

  /**
   * Resolves the signed-in user: the cached one, or `GET /api/auth/me` when only a token
   * is stored (e.g. after a page reload). Emits null when there is no usable session.
   */
  ensureUser(): Observable<CurrentUser | null> {
    const current = this.userSignal();
    if (current) {
      return of(current);
    }
    if (!this.hasToken()) {
      return of(null);
    }
    if (!this.userInFlight) {
      this.userInFlight = this.http.get<CurrentUser>(AUTH_API.me).pipe(
        tap((user) => this.userSignal.set(user)),
        catchError(() => {
          this.clearSession();
          return of(null);
        }),
        finalize(() => (this.userInFlight = null)),
        shareReplay({ bufferSize: 1, refCount: false }),
      );
    }
    return this.userInFlight;
  }

  /** Clears the session after a failed refresh and goes to the login screen. */
  expireSession(): void {
    this.clearSession();
    void this.router.navigateByUrl('/login');
  }

  setSession(token: string, user: CurrentUser): void {
    this.storeToken(token);
    this.userSignal.set(user);
  }

  storeToken(token: string): void {
    this.tokenSignal.set(token);
    if (this.isBrowser) {
      localStorage.setItem(TOKEN_STORAGE_KEY, token);
    }
  }

  clearSession(): void {
    this.tokenSignal.set(null);
    this.userSignal.set(null);
    if (this.isBrowser) {
      localStorage.removeItem(TOKEN_STORAGE_KEY);
    }
  }

  private readStoredToken(): string | null {
    return this.isBrowser ? localStorage.getItem(TOKEN_STORAGE_KEY) : null;
  }
}
