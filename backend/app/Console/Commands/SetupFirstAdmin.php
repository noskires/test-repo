<?php

namespace App\Console\Commands;

use App\Enums\PssRole;
use App\MasterData\MasterDataDirectory;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

#[Signature('pss:setup-first-admin {--email= : The sign-in email of the first admin; overrides PSS_FIRST_ADMIN_EMAIL}')]
#[Description('Grant System Administrator to the employee in PSS_FIRST_ADMIN_EMPLOYEE_ID, creating the PSS user if missing')]
class SetupFirstAdmin extends Command
{
    /**
     * Execute the console command. Safe to run again: an existing admin is left as it is. A new user is created
     * internal; an existing user keeps their access state (issue 46, Q17), with a warning when they are pending.
     *
     * Sign-in (issue 44): a new user, or an existing one without an email, gets the email (--email, else
     * PSS_FIRST_ADMIN_EMAIL) and the temporary password PSS_FIRST_ADMIN_TEMPORARY_PASSWORD, which they must change on
     * first sign-in. An existing user with an email keeps their email and password, so running it again never resets
     * a password.
     */
    public function handle(MasterDataDirectory $directory): int
    {
        $employeeId = trim((string) config('pss.first_admin_employee_id'));

        if ($employeeId === '') {
            $this->error('PSS_FIRST_ADMIN_EMPLOYEE_ID is not set. Set it in .env to the employee ID of the first System Administrator.');

            return self::FAILURE;
        }

        $employee = $directory->findEmployee($employeeId);
        if ($employee === null) {
            $this->error("Employee [{$employeeId}] was not found in the master data. Check PSS_FIRST_ADMIN_EMPLOYEE_ID.");

            return self::FAILURE;
        }

        $user = User::query()->firstOrNew(['employee_id' => $employeeId], ['name' => $employee->name]);
        $signIn = null;

        if ($user->email === null) {
            $signIn = $this->signInDetails($user);
            if ($signIn === null) {
                return self::FAILURE;
            }
        }

        $isNew = ! $user->exists;

        DB::transaction(function () use ($user, $signIn, $isNew): void {
            if ($signIn !== null) {
                $user->email = $signIn['email'];
                $user->setTemporaryPassword($signIn['password']);
            }
            if ($isNew) {
                $user->markInternal();
            }
            $user->grantRole(PssRole::SystemAdministrator);
        });

        $this->info("{$employee->name} [{$employeeId}] is a System Administrator.");

        if ($signIn === null) {
            $this->info("{$employee->name} keeps their email [{$user->email}] and password.");
        } else {
            $this->info("They sign in with [{$user->email}] and the temporary password from PSS_FIRST_ADMIN_TEMPORARY_PASSWORD, and must change it first.");
        }

        if ($user->isPending()) {
            $this->warn('This user is pending: they can sign in but can\'t use PSS until they are classified (internal or a Vendor) in the database or by another System Administrator.');
        }

        if ($user->vendor_id !== null) {
            $this->warn('This user is linked to a Vendor. Vendor-linked System Administrators are no longer allowed: run `php artisan pss:check-vendor-admins`.');
        }

        return self::SUCCESS;
    }

    /**
     * The email and temporary password for a user who has no email yet, or null after printing why they are invalid.
     *
     * @return array{email: string, password: string}|null
     */
    private function signInDetails(User $user): ?array
    {
        $option = $this->option('email');
        $email = User::normalizeEmail(is_string($option) && trim($option) !== '' ? $option : (string) config('pss.first_admin_email'));
        $password = (string) config('pss.first_admin_temporary_password');

        if ($email === '') {
            $this->error('PSS_FIRST_ADMIN_EMAIL is not set. Set it in .env (or pass --email) to the sign-in email of the first System Administrator.');

            return null;
        }

        if ($password === '') {
            $this->error('PSS_FIRST_ADMIN_TEMPORARY_PASSWORD is not set. Set it in .env to a temporary password; the admin must change it on first sign-in.');

            return null;
        }

        $validator = Validator::make(['email' => $email, 'password' => $password], [
            'email' => ['email', 'max:255'],
            'password' => ['max:255', Password::default()],
        ]);

        if ($validator->errors()->has('email')) {
            $this->error("[{$email}] is not a valid email. Check PSS_FIRST_ADMIN_EMAIL or --email.");

            return null;
        }

        if ($validator->errors()->has('password')) {
            $this->error('PSS_FIRST_ADMIN_TEMPORARY_PASSWORD is too weak: '.$validator->errors()->first('password'));

            return null;
        }

        if (User::query()->where('email', $email)->whereKeyNot($user->getKey())->exists()) {
            $this->error("The email [{$email}] is already used by another user.");

            return null;
        }

        return ['email' => $email, 'password' => $password];
    }
}
