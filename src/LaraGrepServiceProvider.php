<?php

namespace LaraGrep;

use Illuminate\Support\ServiceProvider;
use LaraGrep\Metadata\SchemaMetadataLoader;
use LaraGrep\Services\LaraGrepQueryService;

class LaraGrepServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/laragrep.php', 'laragrep');

        $this->app->singleton(SchemaMetadataLoader::class, function ($app) {
            return new SchemaMetadataLoader(
                $app['db'],
                $app['config']->get('laragrep.connection'),
                $app['config']->get('laragrep.exclude_tables', [])
            );
        });

        $this->app->singleton(LaraGrepQueryService::class, function ($app) {
            return new LaraGrepQueryService(
                $app->make(SchemaMetadataLoader::class),
                $app['config']->get('laragrep', [])
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/laragrep.php' => config_path('laragrep.php'),
        ], 'laragrep-config');

        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
    }
}
