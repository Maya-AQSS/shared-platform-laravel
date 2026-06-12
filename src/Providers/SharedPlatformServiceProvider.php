<?php

declare(strict_types=1);

namespace Maya\Platform\Providers;

use Illuminate\Support\ServiceProvider;
use Maya\Platform\Console\Commands\GenerateSeedersFromDatabase;

class SharedPlatformServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateSeedersFromDatabase::class,
            ]);
        }
    }
}
