<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Filament\Tests;

/**
 * The merchant the test panel is showing. Mutable, because every custody proof
 * here gives the second merchant deliberately identical values — two merchants
 * both invoicing `order-1` for the same buyer under a series called `INV` is the
 * ordinary case, and a proof that creates one merchant's rows proves nothing
 * about a `where` clause nobody wrote.
 */
final class TestTenant
{
    public const PRIMARY = 'tenant-a';

    public const OTHER = 'tenant-b';

    private static string $current = self::PRIMARY;

    public static function current(): string
    {
        return self::$current;
    }

    public static function use(string $tenantId): void
    {
        self::$current = $tenantId;
    }

    public static function reset(): void
    {
        self::$current = self::PRIMARY;
    }
}
