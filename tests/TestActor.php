<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Filament\Tests;

/** Who the test panel attributes an issue or a void to. Nobody, when a test is about a panel with no one signed in. */
final class TestActor
{
    public const PRIMARY = 'operator-1';

    private static ?string $current = self::PRIMARY;

    public static function current(): ?string
    {
        return self::$current;
    }

    public static function use(?string $actorRef): void
    {
        self::$current = $actorRef;
    }

    public static function reset(): void
    {
        self::$current = self::PRIMARY;
    }
}
