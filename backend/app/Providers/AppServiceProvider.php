<?php

namespace App\Providers;

use App\MasterData\InMemoryMasterDataDirectory;
use App\MasterData\MasterDataDirectory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if ($this->app->environment('testing')) {
            $this->app->singleton(MasterDataDirectory::class, InMemoryMasterDataDirectory::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
