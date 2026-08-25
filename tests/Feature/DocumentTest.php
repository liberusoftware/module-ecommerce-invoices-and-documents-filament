<?php

declare(strict_types=1);

use Liberu\Ecommerce\InvoicesAndDocuments\Actions\RecordDelivery;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\Money;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\Party;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\Sale;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\TransportOutcome;
use Liberu\Ecommerce\InvoicesAndDocuments\Enums\DocumentKind;
use Liberu\Ecommerce\InvoicesAndDocuments\Enums\DocumentState;
use Liberu\Ecommerce\InvoicesAndDocuments\Enums\EventKind;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Documents\DocumentResource;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Documents\Pages\ListDocuments;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Documents\Pages\ViewDocument;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Documents\RelationManagers\CorrectionsRelationManager;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Documents\RelationManagers\DeliveriesRelationManager;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Documents\RelationManagers\HistoryRelationManager;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Documents\RelationManagers\LinesRelationManager;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Support\Render;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Tests\TestActor;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Tests\TestTenant;
use Liberu\Ecommerce\InvoicesAndDocuments\Models\DeliveryAttempt;
use Liberu\Ecommerce\InvoicesAndDocuments\Models\Document;
use Livewire\Livewire;

/*
 * The four things a merchant may do to a document, and the fact that none of
 * them is an edit or a delete.
 */

function party(?string $email = 'buyer@example.test'): Party
{
    return new Party('person-1', 'A Buyer', '2 Home Road', null, $email);
}

it('lists a document by its own number and its own frozen total', function (): void {
    $document = issued();

    Livewire::test(ListDocuments::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords(Document::query()->whereKey($document->getKey())->get())
        // The number the module allocated, not the row id the host printed
        // under the label "Invoice #".
        ->assertSee('INV-00001')
        ->assertSee('12.00 GBP')
        ->assertSee('A Buyer')
        // On the record screen and on no listing.
        ->assertDontSee('buyer@example.test');
});

it('shows a drafted document as unnumbered rather than as blank', function (): void {
    draft();

    Livewire::test(ListDocuments::class)->assertSee('Not numbered');
});

it('states every figure the document froze, per line and per rate', function (): void {
    $document = issued(lines: [line('Standard', 1000, 2000, 200), line('Zero rated', 5000, 0, 0)]);

    Livewire::test(ViewDocument::class, ['record' => $document->reference])
        ->assertSuccessful()
        ->assertSee('60.00 GBP')
        ->assertSee('20% — net 10.00 GBP, tax 2.00 GBP, gross 12.00 GBP')
        ->assertSee('0% — net 50.00 GBP, tax 0.00 GBP, gross 50.00 GBP')
        ->assertSee('Merchant Ltd')
        ->assertSee('buyer@example.test')
        // Retention and rendering, each stated as the fact it is rather than
        // left blank for somebody to read as nothing.
        ->assertSee('No retention window is configured')
        ->assertSee('No renderer is configured');
});

it('shows the lines as copies, in the currency the document was frozen with', function (): void {
    $document = issued(lines: [line('A widget', 1000, 1750, 175)]);

    Livewire::test(LinesRelationManager::class, ['ownerRecord' => $document, 'pageClass' => ViewDocument::class])
        ->assertSuccessful()
        ->assertSee('A widget')
        ->assertSee('10.00 GBP')
        ->assertSee('17.5%')
        ->assertSee('1.75 GBP');
});

it('drafts from a sale, and drafting the same sale twice does not make a second document', function (): void {
    bindSale();

    Livewire::test(ListDocuments::class)->callAction('draftFromSale', ['kind' => 'invoice', 'source_ref' => 'order-1', 'note' => 'Thanks']);

    expect(lastNotification()['title'])->toBe('Drafted')
        ->and(Document::query()->count())->toBe(1)
        ->and(Document::query()->firstOrFail()->note)->toBe('Thanks');

    Livewire::test(ListDocuments::class)->callAction('draftFromSale', ['kind' => 'invoice', 'source_ref' => 'order-1', 'note' => null]);

    // The cause is the natural key. Pressing twice is one document, and the
    // panel says so rather than reporting a success it did not have. Reading
    // the notifications drains them, so this takes one copy.
    $second = lastNotification();

    expect($second['title'])->toBe('Nothing changed')
        ->and($second['color'])->toBe('gray')
        ->and(Document::query()->count())->toBe(1);
});

it('refuses to draft rather than inventing a document, and says which refusal it was', function (Closure $prepare, string $sourceRef, string $expected): void {
    $prepare();

    Livewire::test(ListDocuments::class)->callAction('draftFromSale', ['kind' => 'invoice', 'source_ref' => $sourceRef, 'note' => null]);

    $said = lastNotification();

    expect($said['title'])->toBe('Refused')
        ->and($said['color'])->toBe('danger')
        ->and($said['body'])->toContain($expected)
        ->and(Document::query()->count())->toBe(0);
})->with([
    'nothing bound to read a sale' => [
        fn () => null,
        'order-1',
        'nothing is bound to read a sale',
    ],
    'the sale source has never heard of it' => [
        fn () => bindSale(),
        'order-nothing',
        'does not know that reference for this merchant',
    ],
    'the sale states a total its own lines contradict' => [
        fn () => bindSale(statedGross: new Money(9999, 'GBP')),
        'order-1',
        'states a total its own lines do not add up to',
    ],
    'the sale has no lines' => [
        fn () => bindSale()->sales[TestTenant::PRIMARY.'/order-1'] = new Sale(
            'order-1', party(), party(), [], Money::zero('GBP'), Money::zero('GBP'), Money::zero('GBP'),
        ),
        'order-1',
        'has no lines',
    ],
    'the sale does not agree with itself about the currency' => [
        fn () => bindSale()->sales[TestTenant::PRIMARY.'/order-1'] = new Sale(
            'order-1', party(), party(), [line()], new Money(1000, 'EUR'), new Money(200, 'GBP'), new Money(1200, 'GBP'),
        ),
        'order-1',
        'do not all share one currency',
    ],
]);

it('issues under a series and records who did it', function (): void {
    series();
    $document = draft();

    Livewire::test(ViewDocument::class, ['record' => $document->reference])->callAction('issue', ['series' => 'INV']);

    $document->refresh();

    expect(lastNotification()['title'])->toBe('Issued')
        ->and($document->number)->toBe('INV-00001')
        ->and($document->state)->toBe(DocumentState::Issued)
        // The audit row the host wrote for nothing at all.
        ->and($document->events()->where('kind', EventKind::Issued->value)->firstOrFail()->actor_ref)
        ->toBe(TestActor::PRIMARY);
});

it('records the move rather than nobody when the panel has no one signed in', function (): void {
    series();
    $document = draft();
    TestActor::use(null);

    Livewire::test(ViewDocument::class, ['record' => $document->reference])->callAction('issue', ['series' => 'INV']);

    // Null is "nobody was named", which is a fact. An empty string would be a
    // row claiming somebody did it and naming no one.
    expect($document->refresh()->events()->where('kind', EventKind::Issued->value)->firstOrFail()->actor_ref)->toBeNull();
});

it('will not let a merchant file under another merchant’s series code', function (): void {
    series(TestTenant::OTHER, 'THEIRS');
    $document = draft();

    Livewire::test(ViewDocument::class, ['record' => $document->reference])
        ->callAction('issue', ['series' => 'THEIRS'])
        // Refused by the select itself, which is searched over this merchant's
        // series and nobody else's.
        ->assertHasActionErrors(['series']);

    expect($document->refresh()->number)->toBeNull();
});

it('issues a proforma unnumbered, because it may not be filed under a fiscal series', function (): void {
    series();
    $document = draft(kind: DocumentKind::Proforma);

    $component = Livewire::test(ViewDocument::class, ['record' => $document->reference]);
    $component->assertActionVisible('issue');
    $component->callAction('issue', []);

    $document->refresh();

    expect(lastNotification()['title'])->toBe('Issued')
        ->and($document->state)->toBe(DocumentState::Issued)
        ->and($document->number)->toBeNull();

    Livewire::test(ViewDocument::class, ['record' => $document->reference])->assertSee('Not numbered');
});

it('corrects a document by raising a credit note that carries its lines, and cannot raise two', function (): void {
    $document = issued();

    Livewire::test(ViewDocument::class, ['record' => $document->reference])->callAction('credit', ['note' => 'Wrong buyer']);

    expect(lastNotification()['title'])->toBe('Drafted');

    $credit = Document::query()->where('kind', DocumentKind::CreditNote->value)->firstOrFail();

    expect($credit->corrects_document_id)->toBe($document->id)
        ->and($credit->buyer_name)->toBe($document->buyer_name)
        ->and($credit->note)->toBe('Wrong buyer')
        ->and(DocumentResource::summary($credit)->gross->minor)->toBe(1200);

    Livewire::test(ViewDocument::class, ['record' => $document->reference])->callAction('credit', ['note' => null]);

    // The domain counts every credit note already raised against the document,
    // draft ones included, so pressing twice is refused by arithmetic before it
    // ever reaches the natural key. Either way there is one credit note.
    $second = lastNotification();

    expect($second['title'])->toBe('Refused')
        ->and($second['body'])->toContain('would credit more than the document is worth')
        ->and(Document::query()->where('kind', DocumentKind::CreditNote->value)->count())->toBe(1);

    Livewire::test(CorrectionsRelationManager::class, ['ownerRecord' => $document, 'pageClass' => ViewDocument::class])
        ->assertSuccessful()
        ->assertSee('12.00 GBP');
});

it('sends a document and records that a transport said so', function (): void {
    $document = issued();
    $transport = bindTransport();

    Livewire::test(ViewDocument::class, ['record' => $document->reference])
        ->callAction('deliver', ['channel' => 'email', 'address' => null]);

    expect(lastNotification()['title'])->toBe('Delivered')
        ->and($transport->sawAddress)->toBe('buyer@example.test')
        ->and($document->refresh()->state)->toBe(DocumentState::Delivered);

    Livewire::test(DeliveriesRelationManager::class, ['ownerRecord' => $document, 'pageClass' => ViewDocument::class])
        ->assertSuccessful()
        ->assertSee('Sent')
        ->assertSee('buyer@example.test');
});

it('sends to an address the operator names rather than only to the frozen one', function (): void {
    $document = issued();
    $transport = bindTransport();

    Livewire::test(ViewDocument::class, ['record' => $document->reference])
        ->callAction('deliver', ['channel' => 'post', 'address' => 'accounts@example.test']);

    expect($transport->sawAddress)->toBe('accounts@example.test');
});

it('says the attempt was written down when the transport could not carry it', function (Closure $bind, string $expectedStart, string $expectedReason, int $rows): void {
    $document = issued();
    $bind();

    Livewire::test(ViewDocument::class, ['record' => $document->reference])
        ->callAction('deliver', ['channel' => 'email', 'address' => null]);

    $said = lastNotification();

    expect($said['title'])->toBe('Not delivered')
        ->and($said['color'])->toBe('danger')
        ->and($said['body'])->toStartWith($expectedStart)
        ->and($said['body'])->toContain($expectedReason)
        ->and(DeliveryAttempt::query()->count())->toBe($rows)
        // Whatever happened to the transmission, the document did not become
        // delivered by anybody dispatching anything.
        ->and($document->refresh()->state)->toBe(DocumentState::Issued);
})->with([
    'nothing bound to carry it' => [
        fn () => null,
        'The attempt is on this document and undelivered',
        'nothing is bound to carry a document anywhere',
        1,
    ],
    'the transport failed' => [
        fn () => bindTransport(TransportOutcome::failed('mailbox full')),
        'The attempt is on this document and undelivered',
        'the transport tried and failed',
        1,
    ],
    'the transport suppressed it' => [
        fn () => bindTransport(TransportOutcome::suppressed('unsubscribed')),
        'The attempt is on this document and undelivered',
        'deliberately not sent',
        1,
    ],
]);

it('refuses an attempt with nowhere to go, and writes no row for it', function (): void {
    $document = issued(buyer: party(null));
    bindTransport();

    Livewire::test(ViewDocument::class, ['record' => $document->reference])
        ->callAction('deliver', ['channel' => 'email', 'address' => null]);

    $said = lastNotification();

    expect($said['body'])->toStartWith('No attempt was recorded, because')
        ->and($said['body'])->toContain('no address to send it to')
        ->and(DeliveryAttempt::query()->count())->toBe(0);
});

it('voids rather than deleting, and the number stays spent', function (): void {
    $document = issued();

    Livewire::test(ViewDocument::class, ['record' => $document->reference])->callAction('voidDocument', ['reason' => 'Superseded']);

    $document->refresh();

    expect(lastNotification()['title'])->toBe('Voided')
        ->and($document->state)->toBe(DocumentState::Void)
        ->and($document->void_reason)->toBe('Superseded')
        // Keeping it is what keeps a gapless series gapless.
        ->and($document->number)->toBe('INV-00001')
        ->and($document->events()->where('kind', EventKind::Voided->value)->firstOrFail()->actor_ref)
        ->toBe(TestActor::PRIMARY);
});

it('calls discarding a draft what it is, and records it the same way', function (): void {
    $document = draft();

    $component = Livewire::test(ViewDocument::class, ['record' => $document->reference]);
    $component->assertSee('Discard it');
    $component->callAction('voidDocument', ['reason' => 'Drafted against the wrong order']);

    expect($document->refresh()->state)->toBe(DocumentState::Void)
        ->and($document->events()->where('kind', EventKind::Voided->value)->count())->toBe(1);
});

it('offers only the moves the document can make from where it stands', function (): void {
    $draft = draft(saleRef: 'order-1');

    Livewire::test(ViewDocument::class, ['record' => $draft->reference])
        ->assertActionVisible('issue')
        ->assertActionVisible('voidDocument')
        ->assertActionHidden('deliver')
        ->assertActionHidden('credit');

    $issuedDocument = issued(saleRef: 'order-2', code: 'INV2');

    Livewire::test(ViewDocument::class, ['record' => $issuedDocument->reference])
        ->assertActionHidden('issue')
        ->assertActionVisible('deliver')
        ->assertActionVisible('credit')
        ->assertActionVisible('voidDocument');

    bindTransport();
    (new RecordDelivery())(TestTenant::PRIMARY, $issuedDocument, 'd-1', 'email');

    Livewire::test(ViewDocument::class, ['record' => $issuedDocument->reference])
        ->assertActionVisible('deliver')
        ->assertActionVisible('credit')
        ->assertActionHidden('issue');
});

it('offers nothing at all on a voided document', function (): void {
    $document = issued();

    Livewire::test(ViewDocument::class, ['record' => $document->reference])->callAction('voidDocument', ['reason' => 'Superseded']);

    Livewire::test(ViewDocument::class, ['record' => $document->reference])
        ->assertActionHidden('issue')
        ->assertActionHidden('deliver')
        ->assertActionHidden('credit')
        // Void from void is nothing to record, so it is not offered either.
        ->assertActionHidden('voidDocument');
});

it('does not offer to credit a credit note', function (): void {
    $document = issued();

    Livewire::test(ViewDocument::class, ['record' => $document->reference])->callAction('credit', ['note' => null]);

    $credit = Document::query()->where('kind', DocumentKind::CreditNote->value)->firstOrFail();
    series(code: 'CN');
    Livewire::test(ViewDocument::class, ['record' => $credit->reference])->callAction('issue', ['series' => 'CN']);

    Livewire::test(ViewDocument::class, ['record' => $credit->reference])
        ->assertActionHidden('credit')
        ->assertActionVisible('voidDocument');
});

it('shows the whole history of a document, including the state it moved from', function (): void {
    $document = issued();

    Livewire::test(ViewDocument::class, ['record' => $document->reference])->callAction('voidDocument', ['reason' => 'Superseded']);

    Livewire::test(HistoryRelationManager::class, ['ownerRecord' => $document->refresh(), 'pageClass' => ViewDocument::class])
        ->assertSuccessful()
        ->assertSee('Drafted')
        ->assertSee('Issued and numbered')
        ->assertSee('Voided')
        ->assertSee('number: INV-00001')
        ->assertSee(TestActor::PRIMARY)
        // The drafting has no state to have come from, which renders as the
        // fact rather than as a blank.
        ->assertSee(Render::NONE);
});

it('filters a listing by kind and by state, using the labels a merchant reads', function (): void {
    expect(DocumentResource::kindOptions())->toBe([
        'invoice' => 'Invoice',
        'credit_note' => 'Credit note',
        'receipt' => 'Receipt',
        'proforma' => 'Proforma',
    ])
        ->and(DocumentResource::stateOptions())->toBe([
            'draft' => 'Draft',
            'issued' => 'Issued',
            'delivered' => 'Delivered',
            'void' => 'Void',
        ])
        // A credit note is about another document, so it cannot be drafted from
        // a sale and is not offered as one.
        ->and(DocumentResource::draftableKindOptions())->not->toHaveKey('credit_note');
});
