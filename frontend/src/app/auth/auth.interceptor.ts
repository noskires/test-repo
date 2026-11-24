import { HttpErrorResponse, HttpInterceptorFn, HttpRequest } from '@angular/common/http';
import { inject } from '@angular/core';
import { catchError, switchMap, throwError } from 'rxjs';
import { AUTH_API, AuthService } from './auth.service';

/** Only same-origin `/api/...` calls get the token (the dev proxy forwards them to Laravel). */
function isApiRequest(url: string): boolean {
  return url === '/api' || url.startsWith('/api/');
}

/** A 401 from these means "bad credentials" or "session over", never "try a refresh". */
const NO_REFRESH = new Set<string>([AUTH_API.login, AUTH_API.refresh, AUTH_API.logout]);

function withToken(request: HttpRequest<unknown>, token: string | null): HttpRequest<unknown> {
  return token ? request.clone({ setHeaders: { Authorization: `Bearer ${token}` } }) : request;
}

/**
 * Adds `Authorization: Bearer <token>` to API calls. On a 401 it calls
 * `POST /api/auth/refresh` once and retries the request with the new token. If the
 * refresh fails, or the retried request is refused again, it clears the session and
 * sends the user to the login screen.
 */
export const authInterceptor: HttpInterceptorFn = (request, next) => {
  if (!isApiRequest(request.url)) {
    return next(request);
  }

  const auth = inject(AuthService);
  const sentToken = auth.token();

  return next(withToken(request, sentToken)).pipe(
    catchError((error: unknown) => {
      const unauthorized = error instanceof HttpErrorResponse && error.status === 401;
      if (!unauthorized || NO_REFRESH.has(request.url) || sentToken === null) {
        return throwError(() => error);
      }

      return auth.refresh().pipe(
        catchError((refreshError: unknown) => {
          auth.expireSession();
          return throwError(() => refreshError);
        }),
        switchMap((freshToken) =>
          next(withToken(request, freshToken)).pipe(
            catchError((retryError: unknown) => {
              if (retryError instanceof HttpErrorResponse && retryError.status === 401) {
                auth.expireSession();
              }
              return throwError(() => retryError);
            }),
          ),
        ),
      );
    }),
  );
};
