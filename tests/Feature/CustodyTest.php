<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Liberu\Ecommerce\InvoicesAndDocuments\Actions\BurnNumber;
use Liberu\Ecommerce\InvoicesAndDocuments\Actions\RecordDelivery;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\Outcome;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Documents\DocumentResource;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Documents\Pages\ListDocuments;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Documents\Pages\ViewDocument;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Documents\RelationManagers\CorrectionsRelationManager;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Documents\RelationManagers\DeliveriesRelationManager;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Documents\RelationManagers\HistoryRelationManager;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Documents\RelationManagers\LinesRelationManager;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Series\Pages\ViewSeries;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Series\RelationManagers\BurnedNumbersRelationManager;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Series\RelationManagers\DocumentsRelationManager;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Series\SeriesResource;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Support\Apply;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Support\PanelActor;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Support\PanelTenant;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Tests\TestTenant;
use Liberu\Ecommerce\InvoicesAndDocuments\Models\Document;
use Liberu\Ecommerce\InvoicesAndDocuments\Models\Series;
use Livewire\Livewire;

/*
 * Two merchants with deliberately identical values: the same sale reference,
 * the same buyer, the same series code, the same amounts. Two merchants both
 * invoicing `order-1` under a series called `INV` is the ordinary case, and a
 * proof that creates one merchant's rows proves nothing about a `where` clause
 * nobody wrote.
 *
 * Every relation on every screen is exercised, not only every list: tenancy has
 * leaked through relations rather than queries in four consecutive waves, and
 * this is where `withCount()` and `whereHas()` get reached for.
 */

/** @return array{ours: Document, theirs: Document} */
function twoMerchants(): array
{
    $rows = [];
    bindTransport();

    foreach ([TestTenant::PRIMARY, TestTenant::OTHER] as $tenant) {
        series($tenant, 'INV', gapless: false);
        $document = issued($tenant, 'order-1', buyerRef: 'person-1');

        (new RecordDelivery())($tenant, $document, 'd-1-'.$tenant, 'email');
        (new BurnNumber())($tenant, 'INV', 'Printed and destroyed');

        $rows[] = $document->refresh();
    }

    return ['ours' => $rows[0], 'theirs' => $rows[1]];
}

/** @return Collection<int, Model> */
function relationRecords(string $manager, Model $owner, string $pageClass): Collection
{
    $records = Livewire::test($manager, [
        'ownerRecord' => $owner,
        'pageClass' => $pageClass,
    ])->instance()->getTable()->getRecords();

    /** @var Collection<int, Model> $rows */
    $rows = $records instanceof Collection ? $records : Collection::make($records->items());

    return $rows;
}

it('lists only this merchant’s documents', function (): void {
    $rows = twoMerchants();

    Livewire::test(ListDocuments::class)
        ->assertCanSeeTableRecords(Document::query()->whereKey($rows['ours']->getKey())->get())
        ->assertCanNotSeeTableRecords(Document::query()->whereKey($rows['theirs']->getKey())->get());
});

it('counts this document’s lines through the relation, and gets the right non-zero number', function (): void {
    $rows = twoMerchants();

    // `withCount()` builds the relation from a fresh instance whose `tenant_id`
    // is null. Unguarded, the restatement becomes `where('tenant_id', '')` and
    // reports zero for everything, which looks exactly like isolation working.
    $counted = DocumentResource::getEloquentQuery()->firstOrFail();

    expect((int) $counted->id)->toBe((int) $rows['ours']->id)
        ->and($counted->lines_count)->toBe(1);
});

it('counts what this merchant’s series has spent, and not the identical one next door', function (): void {
    twoMerchants();

    $counted = SeriesResource::getEloquentQuery()->firstOrFail();

    expect($counted->tenant_id)->toBe(TestTenant::PRIMARY)
        ->and($counted->documents_count)->toBe(1)
        ->and($counted->burned_numbers_count)->toBe(1);
});

it('shows only this document’s lines, deliveries, credit notes and history', function (): void {
    $rows = twoMerchants();

    Livewire::test(ViewDocument::class, ['record' => $rows['ours']->reference])
        ->callAction('credit', ['note' => null]);

    $ours = $rows['ours']->refresh();

    $lines = relationRecords(LinesRelationManager::class, $ours, ViewDocument::class);
    $deliveries = relationRecords(DeliveriesRelationManager::class, $ours, ViewDocument::class);
    $corrections = relationRecords(CorrectionsRelationManager::class, $ours, ViewDocument::class);
    $history = relationRecords(HistoryRelationManager::class, $ours, ViewDocument::class);

    // Non-zero and correct, in the right tenant. A guarded restatement that
    // silently reported nothing would pass a test asserting only isolation.
    expect($lines)->toHaveCount(1)
        ->and($lines->pluck('tenant_id')->unique()->all())->toBe([TestTenant::PRIMARY])
        ->and($deliveries)->toHaveCount(1)
        ->and($deliveries->pluck('tenant_id')->unique()->all())->toBe([TestTenant::PRIMARY])
        ->and($corrections)->toHaveCount(1)
        ->and($corrections->pluck('tenant_id')->unique()->all())->toBe([TestTenant::PRIMARY])
        // Drafted, issued, delivered — three, and not the other merchant's
        // identical three.
        ->and($history)->toHaveCount(3)
        ->and($history->pluck('tenant_id')->unique()->all())->toBe([TestTenant::PRIMARY]);
});

it('shows only this merchant’s documents and burns under a series of the same name', function (): void {
    twoMerchants();

    $series = SeriesResource::getEloquentQuery()->firstOrFail();

    $documents = relationRecords(DocumentsRelationManager::class, $series, ViewSeries::class);
    $burned = relationRecords(BurnedNumbersRelationManager::class, $series, ViewSeries::class);

    expect($documents)->toHaveCount(1)
        ->and($documents->pluck('tenant_id')->unique()->all())->toBe([TestTenant::PRIMARY])
        ->and($burned)->toHaveCount(1)
        ->and($burned->pluck('tenant_id')->unique()->all())->toBe([TestTenant::PRIMARY]);
});

it('answers another merchant’s document exactly as it answers nobody’s', function (): void {
    $rows = twoMerchants();

    // Not a 403 and not a different message: the panel is not a directory of
    // the deployment, so "belongs to somebody else" and "does not exist" are
    // one answer.
    $open = fn (string $reference): mixed => Livewire::test(ViewDocument::class, ['record' => $reference]);

    expect(fn (): mixed => $open($rows['theirs']->reference))->toThrow(ModelNotFoundException::class)
        ->and(fn (): mixed => $open('nothing-at-all'))->toThrow(ModelNotFoundException::class);
});

it('answers another merchant’s series exactly as it answers nobody’s', function (): void {
    twoMerchants();

    $open = fn (string $code): mixed => Livewire::test(ViewSeries::class, ['record' => $code]);

    TestTenant::use(TestTenant::OTHER);
    $theirs = $open('INV');
    TestTenant::use(TestTenant::PRIMARY);

    expect($theirs->instance()->getRecord()->tenant_id)->toBe(TestTenant::OTHER)
        ->and(fn (): mixed => $open('NOTHING'))->toThrow(ModelNotFoundException::class);
});

it('acts on nothing when the document is not this merchant’s, and says the same thing either way', function (): void {
    $rows = twoMerchants();
    $reached = false;

    // The re-read is the guard. A control the panel hid is not a control, so
    // every write asks the domain for the row again and acts on what it finds.
    Apply::then(
        $rows['theirs'],
        function () use (&$reached): Outcome {
            $reached = true;

            return Outcome::recorded();
        },
        fn (Outcome $outcome) => null,
    );

    $said = lastNotification();

    expect($reached)->toBeFalse()
        ->and($said['title'])->toBe('Nothing happened')
        ->and($said['body'])->toBe('No such document for this merchant.')
        ->and($said['color'])->toBe('danger');
});

it('follows the panel’s merchant when it changes, rather than the first one it saw', function (): void {
    $rows = twoMerchants();

    TestTenant::use(TestTenant::OTHER);
    DocumentResource::forgetSummaries();

    Livewire::test(ListDocuments::class)
        ->assertCanSeeTableRecords(Document::query()->whereKey($rows['theirs']->getKey())->get())
        ->assertCanNotSeeTableRecords(Document::query()->whereKey($rows['ours']->getKey())->get());
});

it('refuses to resolve a panel with no merchant rather than matching orphan rows', function (): void {
    // `where('tenant_id', null)` compiles to `is null`, which lists exactly the
    // orphan rows a scope exists to hide. The host's invoices born off a host
    // are unstamped for precisely this reason.
    PanelTenant::resolveUsing(fn (): ?string => null);

    expect(fn (): string => PanelTenant::current())->toThrow(RuntimeException::class);

    PanelTenant::resolveUsing(fn (): string => '');

    expect(fn (): string => PanelTenant::current())->toThrow(RuntimeException::class);

    PanelTenant::resolveUsing(fn (): int => 7);

    expect(PanelTenant::current())->toBe('7');
});

it('has no merchant to fall back to when the host names no resolver', function (): void {
    // A panel with no Filament tenancy and no resolver has no merchant to be.
    // There is no "show everything" to fall back to.
    PanelTenant::resolveUsing(null);

    expect(fn (): string => PanelTenant::current())->toThrow(RuntimeException::class);
});

it('attributes a move to nobody rather than to a blank operator', function (): void {
    // Null is the domain's "nobody was named". An empty string would be a row
    // claiming somebody did it and naming no one, which is worse than a blank.
    PanelActor::resolveUsing(fn (): ?string => null);

    expect(PanelActor::current())->toBeNull();

    PanelActor::resolveUsing(fn (): string => '');

    expect(PanelActor::current())->toBeNull();

    PanelActor::resolveUsing(fn (): int => 12);

    expect(PanelActor::current())->toBe('12');

    // Nobody is signed in to the test panel, so there is nobody to be.
    PanelActor::resolveUsing(null);

    expect(PanelActor::current())->toBeNull();
});

it('keeps a series in one merchant’s hands even when both have the same code', function (): void {
    twoMerchants();

    $ours = Series::query()->where('tenant_id', TestTenant::PRIMARY)->firstOrFail();
    $theirs = Series::query()->where('tenant_id', TestTenant::OTHER)->firstOrFail();

    expect(SeriesResource::canView($ours))->toBeTrue()
        ->and(SeriesResource::canView($theirs))->toBeFalse();
});
