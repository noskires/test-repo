<?php

namespace App\Console\Commands;

use App\Enums\PssRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('pss:check-vendor-admins')]
#[Description('List vendor-linked users who hold System Administrator (read-only, issue 45)')]
class CheckVendorAdmins extends Command
{
    /**
     * Read-only: lists the users, sorted by employee ID, and changes nothing. What to do with them is a human decision.
     */
    public function handle(): int
    {
        $users = User::query()
            ->whereNotNull('vendor_id')
            ->whereHas('roles', fn ($q) => $q->where('role', PssRole::SystemAdministrator))
            ->with('vendor')
            ->orderBy('employee_id')
            ->get();

        if ($users->isEmpty()) {
            $this->info('No vendor-linked System Administrators found.');

            return self::SUCCESS;
        }

        $this->warn("{$users->count()} vendor-linked System Administrator(s) found. Nothing was changed.");
        foreach ($users as $user) {
            $this->line("{$user->employee_id} | {$user->name} | {$user->vendor?->name}");
        }

        return self::SUCCESS;
    }
}
