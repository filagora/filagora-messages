<?php

declare(strict_types=1);

namespace Filagora\Messages;

use Filagora\Core\Modules\Module;
use Filagora\Core\Modules\ModuleRegistry;
use Filagora\Core\Privacy\PrivacyContributorRegistry;
use Filagora\Core\Privacy\RetentionRuleRegistry;
use Filagora\Core\Schema\PackageSchema;
use Filagora\Core\Schema\SchemaExtension;
use Filagora\Core\Schema\SchemaRegistry;
use Filagora\Forums\Services\Attachments;
use Filagora\Messages\Attachments\ClaimMessageAttachments;
use Filagora\Messages\Attachments\MessageAttachmentAccess;
use Filagora\Messages\Console\LinkAttachmentsCommand;
use Filagora\Messages\Console\PurgeTrashedCommand;
use Filagora\Messages\Contracts\MessagingPolicy;
use Filagora\Messages\Events\MessageReceived;
use Filagora\Messages\Listeners\SendMessageMail;
use Filagora\Messages\Messaging\AllowAllMessaging;
use Filagora\Messages\Privacy\MessagesDataContributor;
use Filagora\Messages\Privacy\TrashRetention;
use Filagora\Messages\Services\IgnoredUsers;
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
        $this->app->singleton(IgnoredUsers::class);
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

        $this->app->make(SchemaRegistry::class)->extend(SchemaExtension::fromFile(
            'messages',
            'forums',
            __DIR__.'/../database/extensions/forums.php',
            __DIR__.'/../database/migrations/forums',
        ));

        Event::listen(MessageReceived::class, SendMessageMail::class);
        Event::listen(MessageReceived::class, ClaimMessageAttachments::class);
        $this->allowParticipantsToSeeMessageFiles();

        $this->app->make(PrivacyContributorRegistry::class)->register(new MessagesDataContributor);
        $this->app->make(RetentionRuleRegistry::class)->register(new TrashRetention($this->app->make(Mailbox::class)));

        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'filagora-messages');
        $this->loadMigrationsFrom([__DIR__.'/../database/migrations', __DIR__.'/../database/migrations/forums']);

        if ($this->app->runningInConsole()) {
            $this->commands([PurgeTrashedCommand::class, LinkAttachmentsCommand::class]);

            $this->publishes([
                __DIR__.'/../config/filagora-messages.php' => config_path('filagora-messages.php'),
            ], 'filagora-messages-config');
        }
    }

    /**
     * With `filagora-forums` installed, its attachments service asks this package about files that
     * belong to a private message.
     */
    private function allowParticipantsToSeeMessageFiles(): void
    {
        if (! class_exists(Attachments::class)) {
            return;
        }

        $this->callAfterResolving(Attachments::class, function (Attachments $attachments): void {
            $attachments->authorizeUsing((new MessageAttachmentAccess)(...));
        });
    }
}
