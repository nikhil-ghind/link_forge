<?php

namespace App\Providers;

use App\Services\RedisLinkStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RedisLinkStore::class, function () {
            return new RedisLinkStore('links');
        });
    }

    public function boot(): void
    {
        // Fail loudly in non-production when a relation is accessed lazily or an
        // unfillable attribute is set; both are easy ways to slow the API down.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }
    }
}
