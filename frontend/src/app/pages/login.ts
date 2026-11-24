import { Component, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { Router } from '@angular/router';
import { AuthService } from '../auth/auth.service';

@Component({
  selector: 'app-login-page',
  template: `
    <section class="login panel">
      <div class="page-head">
        <div>
          <h1>Sign in to PSS</h1>
          <div class="sub">Use your employee ID and password.</div>
        </div>
      </div>
      <form (submit)="submit($event)" novalidate>
        <div class="field">
          <label for="login-employee-id">Employee ID</label>
          <input
            id="login-employee-id"
            class="mono"
            name="employee_id"
            autocomplete="username"
            required
            [value]="employeeId()"
            (input)="employeeId.set(inputValue($event))"
          />
        </div>
        <div class="field">
          <label for="login-password">Password</label>
          <input
            id="login-password"
            name="password"
            type="password"
            autocomplete="current-password"
            required
            [value]="password()"
            (input)="password.set(inputValue($event))"
          />
        </div>
        @if (error()) {
          <p class="err" role="alert">{{ error() }}</p>
        }
        <button type="submit" class="btn accent" [disabled]="busy()">
          {{ busy() ? 'Signing in...' : 'Sign in' }}
        </button>
      </form>
    </section>
  `,
  styles: `
    .login {
      width: 100%;
      max-width: 24rem;
      display: flex;
      flex-direction: column;
      gap: 24px;
    }
    form {
      display: flex;
      flex-direction: column;
      gap: 16px;
    }
    .err {
      margin: 0;
    }
  `,
})
export class LoginPage {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  protected readonly employeeId = signal('');
  protected readonly password = signal('');
  protected readonly error = signal<string | null>(null);
  protected readonly busy = signal(false);

  protected inputValue(event: Event): string {
    return (event.target as HTMLInputElement).value;
  }

  protected submit(event: Event): void {
    event.preventDefault();
    const employeeId = this.employeeId().trim();
    const password = this.password();
    if (!employeeId || !password) {
      this.error.set('Enter your employee ID and password.');
      return;
    }

    this.busy.set(true);
    this.error.set(null);
    this.auth.login(employeeId, password).subscribe({
      next: () => {
        this.busy.set(false);
        void this.router.navigateByUrl('/');
      },
      error: (error: unknown) => {
        this.busy.set(false);
        this.password.set('');
        this.error.set(this.messageFor(error));
      },
    });
  }

  private messageFor(error: unknown): string {
    if (error instanceof HttpErrorResponse) {
      if (error.status === 401) {
        return error.error?.message ?? 'Invalid employee ID or password.';
      }
      if (error.status === 403) {
        return (
          error.error?.message ?? "Your account isn't set up in PSS yet. Ask an administrator."
        );
      }
      if (error.status === 429) {
        return 'Too many sign-in attempts. Wait a minute and try again.';
      }
    }
    return 'Sign-in failed. Try again later.';
  }
}
