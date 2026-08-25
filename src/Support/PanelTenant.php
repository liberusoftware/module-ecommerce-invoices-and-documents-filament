<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Filament\Support;

use Closure;
use Filament\Facades\Filament;
use RuntimeException;

/**
 * Where the panel gets its merchant id from. Every domain query and action takes
 * the tenant as an argument, so the argument comes from one place rather than
 * from `Filament::getTenant()` repeated across two resources and six relation
 * managers.
 *
 * There is no fallback to "no tenant": `where('tenant_id', null)` compiles to
 * `is null` and lists exactly the orphan rows a scope exists to hide.
 */
final class PanelTenant
{
    /** ponytail: one process-global resolver; move it onto the plugin if a host ever needs one rule per panel. */
    private static ?Closure $resolver = null;

    public static function resolveUsing(?Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    public static function current(): string
    {
        $tenant = self::$resolver !== null
            ? (self::$resolver)()
            : Filament::getTenant()?->getKey();

        if (! is_string($tenant) && ! is_int($tenant)) {
            throw new RuntimeException(
                'Invoicing could not resolve a tenant for this panel. '
                .'Set one with InvoicesAndDocumentsPlugin::make()->tenantUsing(...) in the panel provider.'
            );
        }

        $tenant = (string) $tenant;

        if ($tenant === '') {
            throw new RuntimeException('Invoicing resolved an empty tenant id, which would match orphan rows.');
        }

        return $tenant;
    }

    /** Whether a merchant can be named at all. A screen nobody can scope is inaccessible, not an exception. */
    public static function resolvable(): bool
    {
        try {
            self::current();
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }
}
