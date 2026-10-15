<?php

declare(strict_types=1);

namespace Filagora\Messages\Support;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Resolves the package's model classes. The package never names a model class directly:
 * a host swaps one under `filagora.models.<key>` (or `filagora-messages.models.<key>`) and
 * every relation and query follows.
 */
final class Models
{
    /**
     * @return class-string<Model>
     */
    public static function class(string $key): string
    {
        /** @var class-string<Model>|null $class */
        $class = config("filagora.models.{$key}") ?? config("filagora-messages.models.{$key}");

        return $class ?? throw new InvalidArgumentException("No Filagora messages model is registered for [{$key}].");
    }
}
