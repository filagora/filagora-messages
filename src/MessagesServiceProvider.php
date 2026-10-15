<?php

declare(strict_types=1);

namespace Filagora\Messages;

use Filagora\Core\Modules\Module;
use Filagora\Core\Modules\ModuleRegistry;
use Filagora\Core\Schema\PackageSchema;
use Filagora\Core\Schema\SchemaRegistry;
use Illuminate\Support\ServiceProvider;

class MessagesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/filagora-messages.php', 'filagora-messages');
    }

    public function boot(): void
    {
        $this->app->make(ModuleRegistry::class)->register(
            new Module('messages', 'Filagora Messages', 'filagora/filagora-messages', requires: ['core']),
        );

        $this->app->make(SchemaRegistry::class)->register(
            PackageSchema::fromFile('messages', __DIR__.'/../database/schema.php', __DIR__.'/../database/migrations'),
        );

        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'filagora-messages');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/filagora-messages.php' => config_path('filagora-messages.php'),
            ], 'filagora-messages-config');
        }
    }
}
