<?php

declare(strict_types=1);

namespace Filagora\Messages;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Private messages have no staff screens: they are between members, and staff do not read them.
 * The plugin exists so a panel can list the package like the others; it registers nothing.
 */
class FilagoraMessagesPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'filagora-messages';
    }

    public function register(Panel $panel): void {}

    public function boot(Panel $panel): void {}
}
