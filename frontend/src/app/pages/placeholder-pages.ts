import { Component, inject } from '@angular/core';
import { AuthService } from '../auth/auth.service';

/** Shared layout for the placeholder pages: page head, then one card. */
const PAGE_STYLES = `
  :host {
    display: flex;
    flex-direction: column;
    gap: 24px;
  }
  p {
    margin: 0;
  }
`;

/** Dashboard / home page for every signed-in user. The real dashboard comes later. */
@Component({
  selector: 'app-home-page',
  template: `
    <div class="page-head">
      <div>
        <h1>Welcome, {{ auth.user()?.name }}</h1>
        <div class="sub">Dashboard</div>
      </div>
    </div>
    <section class="panel">
      <p>Use the menu to open the pages your role allows.</p>
    </section>
  `,
  styles: PAGE_STYLES,
})
export class HomePage {
  protected readonly auth = inject(AuthService);
}

/** Placeholder: the admin screens come with later issues. */
@Component({
  selector: 'app-admin-page',
  template: `
    <div class="page-head">
      <div>
        <h1>Admin</h1>
        <div class="sub">Administration</div>
      </div>
    </div>
    <section class="panel">
      <p>Administration screens are coming soon.</p>
    </section>
  `,
  styles: PAGE_STYLES,
})
export class AdminPage {}

/** Placeholder: the reports come with issue 29. */
@Component({
  selector: 'app-reports-page',
  template: `
    <div class="page-head">
      <div>
        <h1>Reports</h1>
        <div class="sub">Reports for management</div>
      </div>
    </div>
    <section class="panel">
      <p>Reports are coming soon.</p>
    </section>
  `,
  styles: PAGE_STYLES,
})
export class ReportsPage {}
