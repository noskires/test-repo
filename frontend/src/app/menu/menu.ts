import { Component, computed, inject } from '@angular/core';
import { RouterLink, RouterLinkActive } from '@angular/router';
import {
  AuthService,
  ROLE_MANAGEMENT_VIEWER,
  ROLE_SYSTEM_ADMINISTRATOR,
} from '../auth/auth.service';

export interface MenuItem {
  label: string;
  path: string;
  /** Empty: every signed-in user. Otherwise: any one of these roles. */
  roles: readonly string[];
}

/** Same role rules as the route guards in `auth.guards.ts`. */
export const MENU_ITEMS: readonly MenuItem[] = [
  { label: 'Dashboard', path: '/', roles: [] },
  {
    label: 'Reports',
    path: '/reports',
    roles: [ROLE_SYSTEM_ADMINISTRATOR, ROLE_MANAGEMENT_VIEWER],
  },
  { label: 'Admin', path: '/admin', roles: [ROLE_SYSTEM_ADMINISTRATOR] },
];

export function menuFor(roles: readonly string[]): MenuItem[] {
  return MENU_ITEMS.filter(
    (item) => item.roles.length === 0 || item.roles.some((role) => roles.includes(role)),
  );
}

@Component({
  selector: 'app-menu',
  imports: [RouterLink, RouterLinkActive],
  template: `
    @if (items().length > 0) {
      <nav aria-label="Main menu">
        <ul>
          @for (item of items(); track item.path) {
            <li>
              <a
                [routerLink]="item.path"
                routerLinkActive="active"
                [routerLinkActiveOptions]="{ exact: item.path === '/' }"
                >{{ item.label }}</a
              >
            </li>
          }
        </ul>
      </nav>
    }
  `,
  styles: `
    ul {
      display: flex;
      gap: 1rem;
      list-style: none;
      margin: 0;
      padding: 0;
    }
    a {
      color: inherit;
      text-decoration: none;
      padding: 0.25rem 0.5rem;
      border-radius: 4px;
    }
    a.active {
      background: rgba(255, 255, 255, 0.2);
    }
  `,
})
export class Menu {
  private readonly auth = inject(AuthService);

  protected readonly items = computed(() => {
    const user = this.auth.user();
    return user ? menuFor(user.roles) : [];
  });
}
