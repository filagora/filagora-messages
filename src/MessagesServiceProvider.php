<?php

declare(strict_types=1);

namespace Filagora\Messages;

use Filagora\Core\Modules\Module;
use Filagora\Core\Modules\ModuleRegistry;
use Filagora\Core\Privacy\PrivacyContributorRegistry;
use Filagora\Core\Privacy\RetentionRuleRegistry;
use Filagora\Core\Schema\PackageSchema;
use Filagora\Core\Schema\SchemaRegistry;
use Filagora\Messages\Console\PurgeTrashedCommand;
use Filagora\Messages\Contracts\MessagingPolicy;
use Filagora\Messages\Events\MessageReceived;
use Filagora\Messages\Listeners\SendMessageMail;
use Filagora\Messages\Messaging\AllowAllMessaging;
use Filagora\Messages\Privacy\MessagesDataContributor;
use Filagora\Messages\Privacy\TrashRetention;
use Filagora\Messages\Services\Mailbox;
use Filagora\Messages\Services\Messages;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class MessagesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/filagora-messages.php', 'filagora-messages');

        $this->app->bindIf(MessagingPolicy::class, AllowAllMessaging::class);
        $this->app->singleton(Mailbox::class);
        $this->app->singleton(Messages::class);
    }

    public function boot(): void
    {
        $this->app->make(ModuleRegistry::class)->register(
            new Module('messages', 'Filagora Messages', 'filagora/filagora-messages', requires: ['core']),
        );

        $this->app->make(SchemaRegistry::class)->register(
            PackageSchema::fromFile('messages', __DIR__.'/../database/schema.php', __DIR__.'/../database/migrations'),
        );

        Event::listen(MessageReceived::class, SendMessageMail::class);

        $this->app->make(PrivacyContributorRegistry::class)->register(new MessagesDataContributor);
        $this->app->make(RetentionRuleRegistry::class)->register(new TrashRetention($this->app->make(Mailbox::class)));

        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'filagora-messages');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([PurgeTrashedCommand::class]);

            $this->publishes([
                __DIR__.'/../config/filagora-messages.php' => config_path('filagora-messages.php'),
            ], 'filagora-messages-config');
        }
    }
}
