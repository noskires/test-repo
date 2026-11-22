<?php

namespace App\Providers;

use App\MasterData\InMemoryMasterDataDirectory;
use App\MasterData\MasterDataDirectory;
use App\MasterData\SampleMasterData;
use App\Models\ECall;
use App\Models\PmTask;
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
     * Later issues add work_order here.
     */
    private function registerMorphMap(): void
    {
        Relation::enforceMorphMap([
            'pm_task' => PmTask::class,
            'e_call' => ECall::class,
        ]);
    }
}
