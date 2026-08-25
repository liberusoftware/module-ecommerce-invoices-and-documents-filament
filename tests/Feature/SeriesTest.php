<?php

declare(strict_types=1);

use Liberu\Ecommerce\InvoicesAndDocuments\Actions\BurnNumber;
use Liberu\Ecommerce\InvoicesAndDocuments\Actions\OpenSeries;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Series\Pages\ListSeries;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Series\Pages\ViewSeries;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Series\RelationManagers\BurnedNumbersRelationManager;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Series\RelationManagers\DocumentsRelationManager;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Series\SeriesResource;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Tests\TestTenant;
use Liberu\Ecommerce\InvoicesAndDocuments\Models\BurnedNumber;
use Liberu\Ecommerce\InvoicesAndDocuments\Models\Series;
use Livewire\Livewire;

/*
 * Numbering, which is the other half of the module. The host had no invoice
 * number at all: what it printed under "Invoice #" was the `invoices` primary
 * key, shared across every merchant on the deployment.
 */

it('opens a series through the domain action, and opening it twice changes nothing', function (): void {
    Livewire::test(ListSeries::class)->callAction('open', [
        'code' => 'INV',
        'prefix' => 'INV-',
        'pad' => '5',
        'start_at' => '1',
        'fiscal' => true,
        'gapless' => true,
    ]);

    expect(lastNotification()['title'])->toBe('Opened');

    $series = Series::query()->firstOrFail();

    expect($series->tenant_id)->toBe(TestTenant::PRIMARY)
        ->and($series->format(1))->toBe('INV-00001')
        ->and($series->gapless)->toBeTrue();

    Livewire::test(ListSeries::class)->callAction('open', [
        'code' => 'INV',
        'prefix' => 'DIFFERENT-',
        'pad' => '2',
        'start_at' => '9',
        'fiscal' => false,
        'gapless' => false,
    ]);

    $second = lastNotification();

    // A series' policies are what past documents were filed under, so a second
    // open is not a way of editing one.
    expect($second['title'])->toBe('Nothing changed')
        ->and(Series::query()->count())->toBe(1)
        ->and($series->refresh()->prefix)->toBe('INV-');
});

it('counts what a series has spent through its own relations', function (): void {
    series(gapless: false);
    issued();
    (new BurnNumber())(TestTenant::PRIMARY, 'INV', 'Printed and destroyed');

    Livewire::test(ListSeries::class)
        ->assertSuccessful()
        ->assertSee('INV')
        ->assertSee('Accounted for');

    $counted = SeriesResource::getEloquentQuery()->firstOrFail();

    // Non-zero and right. `withCount` builds the relation from a fresh instance
    // whose `tenant_id` is null; an unguarded restatement reports zero for
    // everything, which looks exactly like isolation working.
    expect($counted->documents_count)->toBe(1)
        ->and($counted->burned_numbers_count)->toBe(1);
});

it('reconciles a series on read and shows what it has spent', function (): void {
    issued();

    Livewire::test(ViewSeries::class, ['record' => 'INV'])
        ->assertSuccessful()
        ->assertSee('Numbers 1 to 1')
        ->assertSee('Nothing is unaccounted for.')
        ->assertSee('Gapless');
});

it('names a number nothing accounts for, because nothing in the module can have caused it', function (): void {
    // A gap here is an external event: the number is spent inside the
    // transaction that writes the document, so a rollback returns it.
    series(gapless: false);
    issued(code: 'INV');
    (new BurnNumber())(TestTenant::PRIMARY, 'INV', 'Printed and destroyed');
    issued(saleRef: 'order-2', code: 'INV');

    BurnedNumber::query()->delete();

    Livewire::test(ViewSeries::class, ['record' => 'INV'])
        ->assertSee('Unaccounted for: 2')
        ->assertSee('external event');

    Livewire::test(ListSeries::class)->assertSee('Unaccounted for');
});

it('does not offer to burn a number on a series that promises not to', function (): void {
    series();

    Livewire::test(ViewSeries::class, ['record' => 'INV'])
        ->assertSuccessful()
        ->assertActionHidden('burn');
});

it('burns a number with its reason, so a hole is a record rather than a silence', function (): void {
    series(gapless: false);

    Livewire::test(ViewSeries::class, ['record' => 'INV'])
        ->assertActionVisible('burn')
        ->callAction('burn', ['reason' => 'Pre-printed stationery destroyed']);

    expect(lastNotification()['title'])->toBe('Burned');

    $burned = BurnedNumber::query()->firstOrFail();

    expect($burned->number)->toBe('INV-00001')
        ->and($burned->reason)->toBe('Pre-printed stationery destroyed')
        ->and(Series::query()->firstOrFail()->next_value)->toBe(2);

    Livewire::test(BurnedNumbersRelationManager::class, ['ownerRecord' => Series::query()->firstOrFail(), 'pageClass' => ViewSeries::class])
        ->assertSuccessful()
        ->assertSee('Pre-printed stationery destroyed');
});

it('lists what has been filed under a series, in the order the numbers were spent', function (): void {
    $document = issued();
    issued(saleRef: 'order-2', code: 'INV');

    Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => Series::query()->firstOrFail(), 'pageClass' => ViewSeries::class])
        ->assertSuccessful()
        ->assertSee('INV-00001')
        ->assertSee('INV-00002')
        ->assertSee($document->buyer_name);
});

it('searches the series codes rather than loading the table, and bounds what it returns', function (): void {
    // The host's invoice form was two `pluck()` calls at form-build time: every
    // customer and every order on the deployment, before a field was drawn.
    for ($i = 1; $i <= 60; $i++) {
        (new OpenSeries())(TestTenant::PRIMARY, 'INV'.str_pad((string) $i, 3, '0', STR_PAD_LEFT));
    }

    (new OpenSeries())(TestTenant::PRIMARY, 'RECEIPTS');

    expect(SeriesResource::searchCodes('INV'))->toHaveCount(50)
        ->and(SeriesResource::searchCodes('RECEIPT'))->toBe(['RECEIPTS' => 'RECEIPTS'])
        ->and(SeriesResource::searchCodes('nothing'))->toBe([]);
});

it('will not label, and therefore will not accept, another merchant’s series code', function (): void {
    series(TestTenant::PRIMARY, 'MINE');
    series(TestTenant::OTHER, 'THEIRS');

    expect(SeriesResource::codeLabel('MINE'))->toBe('MINE')
        ->and(SeriesResource::codeLabel('THEIRS'))->toBeNull()
        ->and(SeriesResource::codeLabel(null))->toBeNull();
});
