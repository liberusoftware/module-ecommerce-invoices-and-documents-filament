<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Every ability the domain does not publish, forced closed by name. A missing
 * policy method is permissive — Filament falls through to `allow()` — so an
 * ability nobody thought about is open unless it is answered.
 *
 * `canEdit` and `canDelete` are the point of this package. The host's
 * `InvoiceResource` shipped an `EditAction`, a `DeleteAction`, a
 * `DeleteBulkAction` and a free-text total on a financial document, wrote no
 * audit row for any of it, and the delete was unrecoverable: `invoices` has no
 * soft deletes while `InvoicePolicy` publishes `restore` and `forceDelete` as
 * if it did. Here a document is corrected by a credit note and discarded by
 * being voided, both of which record.
 *
 * `canViewAny` and `canView` are not in this list. They are stated on each
 * resource and answered there.
 */
trait DeniesUnpublishedResourceAbilities
{
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    public static function canReorder(): bool
    {
        return false;
    }

    public static function canReplicate(Model $record): bool
    {
        return false;
    }

    public static function canRestore(Model $record): bool
    {
        return false;
    }

    public static function canRestoreAny(): bool
    {
        return false;
    }
}
