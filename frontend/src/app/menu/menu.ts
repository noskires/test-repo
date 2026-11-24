import { Component, computed, inject } from '@angular/core';
import { RouterLink, RouterLinkActive } from '@angular/router';
import {
  LucideChartColumn,
  LucideDynamicIcon,
  LucideIcon,
  LucideLayoutDashboard,
  LucideShieldCheck,
} from '@lucide/angular';
import {
  AuthService,
  ROLE_MANAGEMENT_VIEWER,
  ROLE_SYSTEM_ADMINISTRATOR,
} from '../auth/auth.service';

/** The sidebar headings from the design, in display order. */
export const MENU_GROUPS = ['Monitor', 'Repairs', 'Admin'] as const;
export type MenuGroupLabel = (typeof MENU_GROUPS)[number];

export interface MenuItem {
  label: string;
  path: string;
  group: MenuGroupLabel;
  icon: LucideIcon;
  /** Empty: every signed-in user. Otherwise: any one of these roles. */
  roles: readonly string[];
}

export interface MenuGroup {
  label: MenuGroupLabel;
  items: MenuItem[];
}

/** Same role rules as the route guards in `auth.guards.ts`. Order within a group = display order. */
export const MENU_ITEMS: readonly MenuItem[] = [
  { label: 'Dashboard', path: '/', group: 'Monitor', icon: LucideLayoutDashboard, roles: [] },
  {
    label: 'Admin',
    path: '/admin',
    group: 'Admin',
    icon: LucideShieldCheck,
    roles: [ROLE_SYSTEM_ADMINISTRATOR],
  },
  {
    label: 'Reports',
    path: '/reports',
    group: 'Admin',
    icon: LucideChartColumn,
    roles: [ROLE_SYSTEM_ADMINISTRATOR, ROLE_MANAGEMENT_VIEWER],
  },
];

/** The groups the user can see, each with its visible entries. Groups without entries are left out. */
export function menuGroupsFor(roles: readonly string[]): MenuGroup[] {
  const visible = MENU_ITEMS.filter(
    (item) => item.roles.length === 0 || item.roles.some((role) => roles.includes(role)),
  );
  return MENU_GROUPS.map((label) => ({
    label,
    items: visible.filter((item) => item.group === label),
  })).filter((group) => group.items.length > 0);
}

/** The visible entries in display order (group by group). */
export function menuFor(roles: readonly string[]): MenuItem[] {
  return menuGroupsFor(roles).flatMap((group) => group.items);
}

/**
 * The main menu. In the sidebar it shows the group headings; on phones the shell's CSS
 * turns the same list into a bottom dock (headings hidden), as in the mobile mocks.
 */
@Component({
  selector: 'app-menu',
  imports: [RouterLink, RouterLinkActive, LucideDynamicIcon],
  template: `
    @if (groups().length > 0) {
      <nav class="menu" aria-label="Main menu">
        @for (group of groups(); track group.label) {
          <div class="navsec" [id]="'navsec-' + group.label">{{ group.label }}</div>
          <ul [attr.aria-labelledby]="'navsec-' + group.label">
            @for (item of group.items; track item.path) {
              <li>
                <a
                  [routerLink]="item.path"
                  routerLinkActive="on"
                  ariaCurrentWhenActive="page"
                  [routerLinkActiveOptions]="{ exact: item.path === '/' }"
                  ><svg [lucideIcon]="item.icon" size="16"></svg><span>{{ item.label }}</span></a
                >
              </li>
            }
          </ul>
        }
      </nav>
    }
  `,
})
export class Menu {
  private readonly auth = inject(AuthService);

  protected readonly groups = computed(() => {
    const user = this.auth.user();
    return user ? menuGroupsFor(user.roles) : [];
  });
}
