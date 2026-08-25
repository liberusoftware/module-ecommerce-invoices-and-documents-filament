<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Filament\Support;

use Closure;
use Illuminate\Support\Facades\Auth;

/**
 * Who an issue or a void is attributed to, on the event row the domain writes.
 *
 * Null rather than an exception, because the domain's `$actorRef` is nullable
 * and null there means "nobody was named" — a distinct fact from an empty
 * string, which would be a row claiming somebody did it and naming no one. The
 * host recorded neither: it edited and deleted invoices with no audit row at
 * all.
 *
 * It is an identity, never an entitlement. Standing is the document's own
 * tenant, which `CustodyPolicy` answers.
 */
final class PanelActor
{
    /** ponytail: one process-global resolver, matching PanelTenant. */
    private static ?Closure $resolver = null;

    public static function resolveUsing(?Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    public static function current(): ?string
    {
        $actor = self::$resolver !== null ? (self::$resolver)() : Auth::id();

        if (! is_string($actor) && ! is_int($actor)) {
            return null;
        }

        $actor = (string) $actor;

        return $actor === '' ? null : $actor;
    }
}
