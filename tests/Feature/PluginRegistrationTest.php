<?php

declare(strict_types=1);

use Filagora\Core\Modules\ModuleRegistry;
use Filagora\Messages\FilagoraMessagesPlugin;
use Filament\Panel;

it('registers on a panel', function () {
    $panel = Panel::make()->id('admin')->plugin(FilagoraMessagesPlugin::make());

    expect($panel->hasPlugin('filagora-messages'))->toBeTrue();
});

it('registers itself as a module that needs only core', function () {
    expect(app(ModuleRegistry::class)->get('messages')->requires)->toBe(['core']);
});
