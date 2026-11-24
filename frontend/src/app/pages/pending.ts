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
    <div class="page-head">
      <div>
        <h1>Waiting for an administrator</h1>
        <div class="sub">Your access isn't set up yet.</div>
      </div>
    </div>
    <section class="panel">
      <p>
        Hello, {{ auth.user()?.name }}. Your PSS account is waiting for an administrator to set up
        your access. Ask a System Administrator, then sign in again.
      </p>
      <button type="button" class="btn accent" (click)="logout()">Log out</button>
    </section>
  `,
  styles: `
    :host {
      display: flex;
      flex-direction: column;
      gap: 24px;
      max-width: 40rem;
    }
    p {
      margin: 0 0 16px;
    }
  `,
})
export class PendingPage {
  protected readonly auth = inject(AuthService);

  protected logout(): void {
    this.auth.logout().subscribe();
  }
}
