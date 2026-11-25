import { Component, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { Router } from '@angular/router';
import { AuthService } from '../auth/auth.service';

type PasswordField = 'current_password' | 'password' | 'password_confirmation';

/**
 * Change your own password (issue 44). A user signed in with a temporary password sees
 * only this page until the change succeeds; others reach it from the top bar.
 */
@Component({
  selector: 'app-change-password-page',
  template: `
    <div class="page-head">
      <div>
        <h1>Change password</h1>
        <div class="sub">
          @if (auth.mustChangePassword()) {
            Your password is temporary. Choose a new one to continue.
          } @else {
            Choose a new password for your PSS account.
          }
        </div>
      </div>
    </div>
    <section class="panel">
      <form (submit)="submit($event)" novalidate>
        <div class="field">
          <label for="current-password">Current password</label>
          <input
            id="current-password"
            name="current_password"
            type="password"
            autocomplete="current-password"
            required
            [value]="currentPassword()"
            (input)="currentPassword.set(inputValue($event))"
          />
          @if (fieldErrors().current_password; as message) {
            <p class="err">{{ message }}</p>
          }
        </div>
        <div class="field">
          <label for="new-password">New password</label>
          <input
            id="new-password"
            name="password"
            type="password"
            autocomplete="new-password"
            required
            [value]="password()"
            (input)="password.set(inputValue($event))"
          />
          @if (fieldErrors().password; as message) {
            <p class="err">{{ message }}</p>
          }
        </div>
        <div class="field">
          <label for="confirm-password">Confirm new password</label>
          <input
            id="confirm-password"
            name="password_confirmation"
            type="password"
            autocomplete="new-password"
            required
            [value]="confirmation()"
            (input)="confirmation.set(inputValue($event))"
          />
        </div>
        @if (error()) {
          <p class="err" role="alert">{{ error() }}</p>
        }
        <button type="submit" class="btn accent" [disabled]="busy()">
          {{ busy() ? 'Saving...' : 'Change password' }}
        </button>
      </form>
    </section>
  `,
  styles: `
    :host {
      display: flex;
      flex-direction: column;
      gap: 24px;
      max-width: 28rem;
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
export class ChangePasswordPage {
  protected readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  protected readonly currentPassword = signal('');
  protected readonly password = signal('');
  protected readonly confirmation = signal('');
  protected readonly error = signal<string | null>(null);
  protected readonly fieldErrors = signal<Partial<Record<PasswordField, string>>>({});
  protected readonly busy = signal(false);

  protected inputValue(event: Event): string {
    return (event.target as HTMLInputElement).value;
  }

  protected submit(event: Event): void {
    event.preventDefault();
    this.fieldErrors.set({});
    if (!this.currentPassword() || !this.password() || !this.confirmation()) {
      this.error.set('Fill in all three fields.');
      return;
    }
    if (this.password() !== this.confirmation()) {
      this.error.set('The new passwords do not match.');
      return;
    }

    this.busy.set(true);
    this.error.set(null);
    this.auth
      .changePassword({
        current_password: this.currentPassword(),
        password: this.password(),
        password_confirmation: this.confirmation(),
      })
      .subscribe({
        next: () => {
          this.busy.set(false);
          void this.router.navigateByUrl('/');
        },
        error: (error: unknown) => {
          this.busy.set(false);
          this.showError(error);
        },
      });
  }

  private showError(error: unknown): void {
    if (error instanceof HttpErrorResponse && error.status === 422) {
      const errors = (error.error?.errors ?? {}) as Record<string, string[]>;
      this.fieldErrors.set({
        current_password: errors['current_password']?.[0],
        password: errors['password']?.[0],
      });
      this.error.set(error.error?.message ?? 'Check the fields and try again.');
      return;
    }
    this.error.set('Changing the password failed. Try again later.');
  }
}
