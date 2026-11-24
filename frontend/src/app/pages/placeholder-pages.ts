import { Component, inject } from '@angular/core';
import { AuthService } from '../auth/auth.service';

/** Dashboard / home page for every signed-in user. The real dashboard comes later. */
@Component({
  selector: 'app-home-page',
  template: `
    <section class="page">
      <h1>Welcome, {{ auth.user()?.name }}</h1>
      <p>Use the menu above to open the pages your role allows.</p>
    </section>
  `,
  styles: `
    .page {
      padding: 1.5rem;
    }
  `,
})
export class HomePage {
  protected readonly auth = inject(AuthService);
}

/** Placeholder: the admin screens come with later issues. */
@Component({
  selector: 'app-admin-page',
  template: `<section class="page">
    <h1>Admin</h1>
    <p>Administration screens are coming soon.</p>
  </section>`,
  styles: `
    .page {
      padding: 1.5rem;
    }
  `,
})
export class AdminPage {}

/** Placeholder: the reports come with issue 29. */
@Component({
  selector: 'app-reports-page',
  template: `<section class="page">
    <h1>Reports</h1>
    <p>Reports are coming soon.</p>
  </section>`,
  styles: `
    .page {
      padding: 1.5rem;
    }
  `,
})
export class ReportsPage {}
