<?php

namespace App\Providers;

use App\Auth\CredentialChecker;
use App\Auth\LocalCredentialChecker;
use App\Enums\PssRole;
use App\MasterData\DatabaseMasterDataDirectory;
use App\MasterData\InMemoryMasterDataDirectory;
use App\MasterData\MasterDataDirectory;
use App\Models\ECall;
use App\Models\PmTask;
use App\Models\User;
use App\Models\WorkOrder;
use App\Waiting\ApprovedECallsProvider;
use App\Waiting\ECallsForEicReviewProvider;
use App\Waiting\ImplementedWorkOrdersProvider;
use App\Waiting\OpenWorkOrdersProvider;
use App\Waiting\PmTasksForValidationProvider;
use App\Waiting\VendorECallsProvider;
use App\Waiting\VendorPmTasksProvider;
use App\Waiting\VendorWorkOrdersProvider;
use App\Waiting\WaitingItemProvider;
use App\Waiting\WorkOrderEndorsementsProvider;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerMasterDataDirectory();
        $this->registerCredentialChecker();
        $this->registerWaitingItemProviders();
    }

    /**
     * The "Waiting for me" sources (issue 24). A new workflow adds its provider here; the endpoint needs no change.
     */
    private function registerWaitingItemProviders(): void
    {
        $this->app->tag([
            PmTasksForValidationProvider::class,
            VendorPmTasksProvider::class,
            VendorECallsProvider::class,
            ECallsForEicReviewProvider::class,
            ApprovedECallsProvider::class,
            OpenWorkOrdersProvider::class,
            WorkOrderEndorsementsProvider::class,
            VendorWorkOrdersProvider::class,
            ImplementedWorkOrdersProvider::class,
        ], WaitingItemProvider::TAG);
    }

    /**
     * Tests get an empty in-memory fake. Every other environment reads the imported master data tables (issue 03);
     * local dev fills them with MasterDataSampleSeeder.
     */
    private function registerMasterDataDirectory(): void
    {
        if ($this->app->environment('testing')) {
            $this->app->singleton(MasterDataDirectory::class, InMemoryMasterDataDirectory::class);
        } else {
            $this->app->singleton(MasterDataDirectory::class, DatabaseMasterDataDirectory::class);
        }
    }

    /**
     * Login checks credentials only through this port (ADR 0004). Until the external checker lands (issue 44) every
     * environment gets the local one, which itself refuses to resolve outside local and testing.
     */
    private function registerCredentialChecker(): void
    {
        $this->app->bind(CredentialChecker::class, LocalCredentialChecker::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerMorphMap();
        $this->registerAdminGates();
    }

    /**
     * The System Administrator holds every admin gate (Q4). Everyone else, including Management / Viewer and vendor
     * staff, is denied. Roles are read from the database, so a revoke takes effect on the next request.
     */
    private function registerAdminGates(): void
    {
        foreach ([
            'manage-vendors',
            'manage-site-settings',
            'manage-checklists',
            'manage-price-lists',
            'manage-billing-thresholds',
            'manage-roles',
        ] as $gate) {
            Gate::define($gate, fn (User $user): bool => $user->hasRole(PssRole::SystemAdministrator));
        }
    }

    /**
     * Stable aliases for polymorphic rows (Approval Record subjects), so stored data never depends on class names.
     * Enforced: a polymorphic relation to an unmapped model throws instead of storing its class name.
     * New ApprovalSubject models must add their alias here.
     */
    private function registerMorphMap(): void
    {
        Relation::enforceMorphMap([
            'pm_task' => PmTask::class,
            'e_call' => ECall::class,
            'work_order' => WorkOrder::class,
            'user' => User::class,
        ]);
    }
}
