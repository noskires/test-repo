import { Component, inject } from '@angular/core';
import { AuthService } from '../auth/auth.service';

/**
 * Shown instead of every page while the signed-in user is pending (issue 46, Q5): they
 * can sign in, but a System Administrator must classify them (internal or a Vendor)
 * before they can use PSS.
 */
@Component({
  selector: 'app-pending-page',
  template: `
    <section class="page">
      <h1>Waiting for an administrator</h1>
      <p>
        Hello, {{ auth.user()?.name }}. Your PSS account is waiting for an administrator to
        set up your access. Ask a System Administrator, then sign in again.
      </p>
      <button type="button" (click)="logout()">Log out</button>
    </section>
  `,
  styles: `
    .page {
      padding: 1.5rem;
      max-width: 36rem;
    }
    button {
      padding: 0.5rem 1rem;
      font: inherit;
      cursor: pointer;
    }
  `,
})
export class PendingPage {
  protected readonly auth = inject(AuthService);

  protected logout(): void {
    this.auth.logout().subscribe();
  }
}
