import { Component, inject } from '@angular/core';
import { RouterLink, RouterOutlet } from '@angular/router';
import { LucideBuildingComplex, LucideKeyRound, LucideLogOut } from '@lucide/angular';
import { AuthService } from './auth/auth.service';
import { Menu } from './menu/menu';

@Component({
  selector: 'app-root',
  imports: [RouterOutlet, RouterLink, Menu, LucideBuildingComplex, LucideKeyRound, LucideLogOut],
  templateUrl: './app.html',
  styleUrl: './app.css',
})
export class App {
  protected readonly auth = inject(AuthService);

  protected logout(): void {
    this.auth.logout().subscribe();
  }
}
