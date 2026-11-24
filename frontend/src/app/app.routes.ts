import { Routes } from '@angular/router';
import { adminGuard, authGuard, pendingGuard, reportsGuard } from './auth/auth.guards';
import { LoginPage } from './pages/login';
import { PendingPage } from './pages/pending';
import { AdminPage, HomePage, ReportsPage } from './pages/placeholder-pages';

export const routes: Routes = [
  { path: 'login', component: LoginPage, title: 'Sign in | PSS' },
  {
    path: 'pending',
    component: PendingPage,
    canActivate: [pendingGuard],
    title: 'Waiting for an administrator | PSS',
  },
  { path: '', component: HomePage, canActivate: [authGuard], title: 'Dashboard | PSS' },
  { path: 'reports', component: ReportsPage, canActivate: [reportsGuard], title: 'Reports | PSS' },
  { path: 'admin', component: AdminPage, canActivate: [adminGuard], title: 'Admin | PSS' },
  { path: '**', redirectTo: '' },
];
