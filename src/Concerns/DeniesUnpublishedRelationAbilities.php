<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Every relation-manager ability forced closed by name. `canAssociate()` and
 * `canDissociate()` are live on a `hasMany` and default open: dissociating a
 * line from its document would change what an issued invoice says, with no
 * edit form and no audit row — which is the same fault as an edit button,
 * reached a different way.
 *
 * The domain raises `DocumentsAreImmutable` from model hooks on every one of
 * these tables, so a delete offered here would throw rather than refuse. An
 * ability that fatals instead of refusing is still an ability that was offered.
 *
 * `canViewAny()` is stated rather than inherited, and declared public where the
 * parent declares these protected: widening is legal, narrowing is fatal, and
 * public is what lets a test ask by name.
 */
trait DeniesUnpublishedRelationAbilities
{
    public function canViewAny(): bool
    {
        return true;
    }

    public function canAssociate(): bool
    {
        return false;
    }

    public function canAttach(): bool
    {
        return false;
    }

    public function canCreate(): bool
    {
        return false;
    }

    public function canDelete(Model $record): bool
    {
        return false;
    }

    public function canDeleteAny(): bool
    {
        return false;
    }

    public function canDetach(Model $record): bool
    {
        return false;
    }

    public function canDetachAny(): bool
    {
        return false;
    }

    public function canDissociate(Model $record): bool
    {
        return false;
    }

    public function canDissociateAny(): bool
    {
        return false;
    }

    public function canEdit(Model $record): bool
    {
        return false;
    }

    public function canForceDelete(Model $record): bool
    {
        return false;
    }

    public function canForceDeleteAny(): bool
    {
        return false;
    }

    public function canReorder(): bool
    {
        return false;
    }

    public function canReplicate(Model $record): bool
    {
        return false;
    }

    public function canRestore(Model $record): bool
    {
        return false;
    }

    public function canRestoreAny(): bool
    {
        return false;
    }

    public function canView(Model $record): bool
    {
        return false;
    }
}
