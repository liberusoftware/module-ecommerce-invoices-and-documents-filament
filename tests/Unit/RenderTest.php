<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\ContinuityReport;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\Money;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\Outcome;
use Liberu\Ecommerce\InvoicesAndDocuments\Enums\DeliveryState;
use Liberu\Ecommerce\InvoicesAndDocuments\Enums\DocumentKind;
use Liberu\Ecommerce\InvoicesAndDocuments\Enums\DocumentState;
use Liberu\Ecommerce\InvoicesAndDocuments\Enums\EventKind;
use Liberu\Ecommerce\InvoicesAndDocuments\Enums\RefusalReason;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Support\Render;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Tests\Fakes\FakeRenderer;
use Liberu\Ecommerce\InvoicesAndDocuments\Support\Frozen;

/*
 * The rendering rules, without a panel. Every one of these is something the host
 * got wrong: a dollar sign over a column with no currency, a header total its
 * own lines had stopped matching, a primary key called "Invoice #".
 */

it('has a sentence for every refusal the domain can give, and no two are the same', function (): void {
    // A `match` with no default arm: a twenty-first reason would be an
    // `UnhandledMatchError` inside a notification. This walks `cases()` so the
    // hole is found by the suite rather than by a merchant mid-issue.
    $sentences = array_map(Render::refusal(...), RefusalReason::cases());

    expect($sentences)->toHaveCount(20)
        ->and(count(array_unique($sentences)))->toBe(20);

    foreach ($sentences as $sentence) {
        expect($sentence)->not->toBe('')
            ->and($sentence)->not->toContain('_');
    }
});

it('tells recorded, already recorded and refused apart in words and in colour', function (): void {
    expect(Render::outcome(Outcome::recorded(1, 'r'), 'Done.', 'Already.'))->toBe('Done.')
        ->and(Render::outcome(Outcome::alreadyRecorded(1, 'r'), 'Done.', 'Already.'))->toBe('Already.')
        ->and(Render::outcome(Outcome::refused(RefusalReason::SeriesRequired), 'Done.', 'Already.'))
        ->toBe('Nothing was recorded, because a fiscal document has to be filed under a series, and none was named.')
        ->and(Render::outcomeColour(Outcome::recorded()))->toBe('success')
        ->and(Render::outcomeColour(Outcome::alreadyRecorded()))->toBe('gray')
        ->and(Render::outcomeColour(Outcome::refused(RefusalReason::NotIssued)))->toBe('danger');
});

it('says whether a refused delivery left an attempt behind, because one of them did', function (): void {
    // The domain writes the attempt row before it transmits. An operator told
    // "nothing was recorded" about an unbound transport will simply ask again,
    // and the row nobody mentioned is the one that explains why.
    expect(Render::delivery(Outcome::refused(RefusalReason::NoTransportBound)))
        ->toStartWith('The attempt is on this document and undelivered, because')
        ->and(Render::delivery(Outcome::refused(RefusalReason::TransportFailed)))
        ->toStartWith('The attempt is on this document and undelivered, because')
        ->and(Render::delivery(Outcome::refused(RefusalReason::TransportSuppressed)))
        ->toStartWith('The attempt is on this document and undelivered, because')
        // Nothing was written for these two: the domain refuses before the row.
        ->and(Render::delivery(Outcome::refused(RefusalReason::NoDeliveryAddress)))
        ->toStartWith('No attempt was recorded, because')
        ->and(Render::delivery(Outcome::refused(RefusalReason::NotIssued)))
        ->toStartWith('No attempt was recorded, because')
        ->and(Render::delivery(Outcome::recorded()))->toContain('reads as delivered')
        ->and(Render::delivery(Outcome::alreadyRecorded()))->toContain('already been made');
});

it('titles a delivery by what happened rather than by whether it is green', function (): void {
    expect(Render::deliveryTitle(Outcome::recorded()))->toBe('Delivered')
        ->and(Render::deliveryTitle(Outcome::alreadyRecorded()))->toBe('Nothing changed')
        ->and(Render::deliveryTitle(Outcome::refused(RefusalReason::TransportFailed)))->toBe('Not delivered');
});

it('never prints an amount without the currency it was frozen with', function (): void {
    // The host had no currency column anywhere on the money path and hard-coded
    // a dollar sign in both invoice views.
    expect(Render::money(new Money(1234, 'GBP')))->toBe('12.34 GBP')
        ->and(Render::money(new Money(-500, 'EUR')))->toBe('-5.00 EUR')
        ->and(Render::money(new Money(1234, 'JPY', 0)))->toBe('1234 JPY');
});

it('renders a document with no number as unnumbered rather than as blank', function (): void {
    // A proforma has no number and that is correct, not missing. The host showed
    // customers the `invoices` primary key under the label "Invoice #".
    expect(Render::number(null))->toBe('Not numbered')
        ->and(Render::number('INV-00001'))->toBe('INV-00001');
});

it('renders a tax rate from basis points without a float', function (): void {
    expect(Render::taxRate(2000))->toBe('20%')
        ->and(Render::taxRate(1750))->toBe('17.5%')
        ->and(Render::taxRate(1755))->toBe('17.55%')
        ->and(Render::taxRate(0))->toBe('0%');
});

it('summarises tax per distinct rate, because a gross total on its own is not a VAT invoice', function (): void {
    $summary = Frozen::summarise([
        line('Zero rated', 5000, 0, 0),
        line('Standard', 1000, 2000, 200),
        line('Standard again', 2000, 2000, 400),
    ], 'GBP', 2);

    expect(Render::taxSummary($summary))->toBe([
        '0% — net 50.00 GBP, tax 0.00 GBP, gross 50.00 GBP',
        '20% — net 30.00 GBP, tax 6.00 GBP, gross 36.00 GBP',
    ]);
});

it('names a number nothing accounts for as an external event rather than as a cleanup', function (): void {
    $empty = new ContinuityReport('tenant-a', 'INV', true, 0, 0, null, null, []);
    $whole = new ContinuityReport('tenant-a', 'INV', true, 3, 1, 1, 4, []);
    $holed = new ContinuityReport('tenant-a', 'INV', true, 2, 0, 1, 4, [2, 3]);

    expect(Render::continuity($empty))->toBe('Nothing has been spent from this series yet.')
        ->and(Render::continuity($whole))->toContain('Nothing is unaccounted for.')
        ->and(Render::continuity($holed))->toContain('Unaccounted for: 2, 3')
        ->and(Render::continuity($holed))->toContain('external event')
        ->and(Render::continuityColour($whole))->toBe('success')
        ->and(Render::continuityColour($holed))->toBe('danger');
});

it('says plainly that no renderer is configured rather than showing an empty file', function (): void {
    // Unbound removes the artefact and nothing else: the document is still
    // numbered, filed, listed and deliverable.
    expect(Render::renderer())->toStartWith('No renderer is configured');

    Config::set('invoices-and-documents.seams.renderer', new FakeRenderer());

    expect(Render::renderer())->toStartWith('A renderer is configured');
});

it('treats a retention window nobody configured as unknown rather than as none', function (): void {
    // This is the difference between erasure refusing and erasure rewriting a
    // statutory record. The host had no rule at all, so it did the second one by
    // accident.
    $issued = Carbon::parse('2026-01-01');

    expect(Render::retention(null, null))->toBe('Not issued, so no retention window has started.')
        ->and(Render::retention($issued, null))->toStartWith('Unknown.')
        ->and(Render::retention($issued, null))->toContain('rather than reading an unset window as none')
        ->and(Render::retention($issued, Carbon::parse('2033-01-01')))->toContain('Kept until Jan 1, 2033');
});

it('labels and colours every kind, state, delivery outcome and event the domain publishes', function (): void {
    foreach (DocumentKind::cases() as $kind) {
        expect(Render::kindLabel($kind))->not->toBe('')
            ->and(Render::kindColour($kind))->not->toBe('');
    }

    foreach (DocumentState::cases() as $state) {
        expect(Render::stateLabel($state))->not->toBe('')
            ->and(Render::stateColour($state))->not->toBe('');
    }

    foreach (DeliveryState::cases() as $state) {
        expect(Render::deliveryLabel($state))->not->toBe('')
            ->and(Render::deliveryColour($state))->not->toBe('');
    }

    foreach (EventKind::cases() as $kind) {
        expect(Render::eventLabel($kind))->not->toBe('');
    }

    // Void is the only red state, because void is a document that exists and is
    // finished with rather than one that was removed.
    expect(Render::stateColour(DocumentState::Void))->toBe('danger')
        ->and(Render::deliveryLabel(DeliveryState::Pending))->toBe('Attempted, no answer');
});

it('reads the ledger’s own state column back into a label, and an absent one as an em dash', function (): void {
    expect(Render::stateName(null))->toBe(Render::NONE)
        ->and(Render::stateName(DocumentState::Issued->value))->toBe('Issued');
});

it('renders an event’s detail, including the one whose value is nothing', function (): void {
    // A proforma issues with no number, so the issued event carries
    // `number: null`. Rendering that as an empty string would read as a number
    // nobody can see.
    expect(Render::detail(null))->toBe(Render::NONE)
        ->and(Render::detail([]))->toBe(Render::NONE)
        ->and(Render::detail(['number' => 'INV-00001']))->toBe('number: INV-00001')
        ->and(Render::detail(['number' => null]))->toBe('number: '.Render::NONE)
        ->and(Render::detail(['reason' => 'Wrong buyer', 'delivery' => 'abc']))->toBe('reason: Wrong buyer, delivery: abc');
});
