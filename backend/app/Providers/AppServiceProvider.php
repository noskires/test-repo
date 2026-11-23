<?php

namespace App\Providers;

use App\MasterData\InMemoryMasterDataDirectory;
use App\MasterData\MasterDataDirectory;
use App\MasterData\SampleMasterData;
use App\Models\ECall;
use App\Models\PmTask;
use App\Models\WorkOrder;
use App\Waiting\ApprovedECallsProvider;
use App\Waiting\ECallsForEicReviewProvider;
use App\Waiting\PmTasksForValidationProvider;
use App\Waiting\VendorECallsProvider;
use App\Waiting\VendorPmTasksProvider;
use App\Waiting\WaitingItemProvider;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerMasterDataDirectory();
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
        ], WaitingItemProvider::TAG);
    }

    /**
     * Until the real adapter lands (issue 03): tests get an empty fake, local dev gets the sample data,
     * and every other environment gets no fake binding at all.
     */
    private function registerMasterDataDirectory(): void
    {
        if ($this->app->environment('testing')) {
            $this->app->singleton(MasterDataDirectory::class, InMemoryMasterDataDirectory::class);
        } elseif ($this->app->environment('local')) {
            $this->app->singleton(
                MasterDataDirectory::class,
                fn (): InMemoryMasterDataDirectory => SampleMasterData::load(new InMemoryMasterDataDirectory),
            );
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerMorphMap();
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
        ]);
    }
}
