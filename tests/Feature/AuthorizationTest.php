<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Concerns\DeniesUnpublishedRelationAbilities;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Concerns\DeniesUnpublishedResourceAbilities;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Documents\DocumentResource;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Documents\RelationManagers\CorrectionsRelationManager;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Documents\RelationManagers\DeliveriesRelationManager;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Documents\RelationManagers\HistoryRelationManager;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Documents\RelationManagers\LinesRelationManager;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Series\RelationManagers\BurnedNumbersRelationManager;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Series\RelationManagers\DocumentsRelationManager;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Series\SeriesResource;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Support\PanelTenant;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Tests\TestTenant;
use Liberu\Ecommerce\InvoicesAndDocuments\Models\Document;
use Liberu\Ecommerce\InvoicesAndDocuments\Models\Series;

/*
 * The ability matrix, asserted by name.
 *
 * A missing policy is permissive, and a policy that exists but lacks the method
 * asked about is also permissive, because Filament falls through to `allow()`.
 * The only way to know an ability is closed is to ask it and be told no.
 *
 * This is the file that stands where the host's `InvoiceResource` shipped an
 * `EditAction`, a `DeleteAction`, a `DeleteBulkAction`, a `CreateAction` and a
 * free-text total, wrote no audit row for any of it, and whose deletes were
 * unrecoverable: `invoices` has no soft deletes while `InvoicePolicy` publishes
 * `restore` and `forceDelete` as if it did.
 */

$resources = [[DocumentResource::class], [SeriesResource::class]];

$managers = [
    [LinesRelationManager::class],
    [DeliveriesRelationManager::class],
    [CorrectionsRelationManager::class],
    [HistoryRelationManager::class],
    [DocumentsRelationManager::class],
    [BurnedNumbersRelationManager::class],
];

it('publishes no create, no edit and no delete on a financial document', function (string $resource): void {
    $record = new Document();

    expect($resource::canCreate())->toBeFalse()
        ->and($resource::canEdit($record))->toBeFalse()
        ->and($resource::canDelete($record))->toBeFalse()
        ->and($resource::canDeleteAny())->toBeFalse()
        ->and($resource::canForceDelete($record))->toBeFalse()
        ->and($resource::canForceDeleteAny())->toBeFalse()
        ->and($resource::canReorder())->toBeFalse()
        ->and($resource::canReplicate($record))->toBeFalse()
        // The host's policy published these two over a table with no soft
        // deletes, which is a restore that cannot restore anything.
        ->and($resource::canRestore($record))->toBeFalse()
        ->and($resource::canRestoreAny())->toBeFalse();
})->with($resources);

it('registers no create page and no edit page on either resource', function (): void {
    expect(array_keys(DocumentResource::getPages()))->toBe(['index', 'view'])
        ->and(array_keys(SeriesResource::getPages()))->toBe(['index', 'view']);
});

it('answers viewing a document with the domain policy rather than by defaulting to allow', function (): void {
    $mine = issued(TestTenant::PRIMARY);
    $theirs = issued(TestTenant::OTHER);

    expect(DocumentResource::canViewAny())->toBeTrue()
        ->and(DocumentResource::canView($mine))->toBeTrue()
        // Standing is the document's own merchant, never a role name. The host
        // dropped the ownership filter entirely for `super_admin` and `admin`.
        ->and(DocumentResource::canView($theirs))->toBeFalse();
});

it('refuses to view anything at all when the panel has no merchant', function (): void {
    $mine = issued();

    PanelTenant::resolveUsing(fn (): ?string => null);

    expect(DocumentResource::canViewAny())->toBeFalse()
        ->and(DocumentResource::canView($mine))->toBeFalse()
        ->and(SeriesResource::canViewAny())->toBeFalse()
        ->and(SeriesResource::canView(Series::query()->firstOrFail()))->toBeFalse();
});

it('answers a model that is not its own with no', function (): void {
    // `canView` takes Filament's `Model`. A resource that assumed its own model
    // would fatal rather than refuse.
    expect(DocumentResource::canView(new User()))->toBeFalse()
        ->and(SeriesResource::canView(new User()))->toBeFalse();
});

it('closes every relation-manager ability by name, including associate and dissociate', function (string $manager): void {
    /** @var object $instance */
    $instance = new $manager();
    $record = new Document();

    // `canAssociate` and `canDissociate` are live on a `hasMany` and default
    // open. Dissociating a line from its document changes what an issued
    // invoice says, with no edit form and no audit row.
    expect($instance->canAssociate())->toBeFalse()
        ->and($instance->canDissociate($record))->toBeFalse()
        ->and($instance->canDissociateAny())->toBeFalse()
        ->and($instance->canAttach())->toBeFalse()
        ->and($instance->canDetach($record))->toBeFalse()
        ->and($instance->canDetachAny())->toBeFalse()
        ->and($instance->canCreate())->toBeFalse()
        ->and($instance->canEdit($record))->toBeFalse()
        ->and($instance->canDelete($record))->toBeFalse()
        ->and($instance->canDeleteAny())->toBeFalse()
        ->and($instance->canForceDelete($record))->toBeFalse()
        ->and($instance->canForceDeleteAny())->toBeFalse()
        ->and($instance->canReorder())->toBeFalse()
        ->and($instance->canReplicate($record))->toBeFalse()
        ->and($instance->canRestore($record))->toBeFalse()
        ->and($instance->canRestoreAny())->toBeFalse()
        ->and($instance->canView($record))->toBeFalse()
        // The one ability published, stated rather than inherited.
        ->and($instance->canViewAny())->toBeTrue();
})->with($managers);

it('names no method after a Filament ability outside the two concerns that close them', function (string $class): void {
    // A subclass method wins over a trait's, so a method named for an ability
    // would silently reopen it.
    $abilities = array_diff(
        array_merge(
            get_class_methods(DeniesUnpublishedResourceAbilities::class),
            get_class_methods(DeniesUnpublishedRelationAbilities::class),
        ),
        // The two each resource states and answers for itself.
        ['canView', 'canViewAny'],
    );

    // A trait's methods report the using class as their declaring class, so the
    // file is what separates "this class wrote it" from "the trait did".
    $reflection = new ReflectionClass($class);
    $file = $reflection->getFileName();

    $declared = array_map(
        fn (ReflectionMethod $method): string => $method->getName(),
        array_filter(
            $reflection->getMethods(),
            fn (ReflectionMethod $method): bool => $method->getFileName() === $file,
        ),
    );

    expect(array_intersect($declared, $abilities))->toBe([]);
})->with(array_merge($resources, $managers));

it('applies a closing concern to every resource and relation manager the plugin registers', function (): void {
    // A new screen cannot arrive open: the concern is what makes an ability
    // nobody thought about closed rather than allowed.
    foreach ([DocumentResource::class, SeriesResource::class] as $resource) {
        expect(in_array(DeniesUnpublishedResourceAbilities::class, class_uses($resource), true))->toBeTrue();
    }

    foreach ([
        LinesRelationManager::class,
        DeliveriesRelationManager::class,
        CorrectionsRelationManager::class,
        HistoryRelationManager::class,
        DocumentsRelationManager::class,
        BurnedNumbersRelationManager::class,
    ] as $manager) {
        expect(in_array(DeniesUnpublishedRelationAbilities::class, class_uses($manager), true))->toBeTrue();
    }
});
