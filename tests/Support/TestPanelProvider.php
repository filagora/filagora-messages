<?php

declare(strict_types=1);

namespace Filagora\Messages\Tests\Support;

use Filagora\Core\FilagoraCorePlugin;
use Filagora\Messages\FilagoraMessagesPlugin;
use Filament\Panel;
use Filament\PanelProvider;

class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->plugin(FilagoraCorePlugin::make())
            ->plugin(FilagoraMessagesPlugin::make());
    }
}
