<?php

declare(strict_types=1);

use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Liberu\Ecommerce\InvoicesAndDocuments\Actions\DraftDocument;
use Liberu\Ecommerce\InvoicesAndDocuments\Actions\IssueDocument;
use Liberu\Ecommerce\InvoicesAndDocuments\Actions\OpenSeries;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\Line;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\Money;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\Party;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\TransportOutcome;
use Liberu\Ecommerce\InvoicesAndDocuments\Enums\DocumentKind;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Documents\DocumentResource;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Series\SeriesResource;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Support\PanelActor;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Support\PanelTenant;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Tests\Fakes\FakeRenderer;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Tests\Fakes\FakeSaleSource;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Tests\Fakes\FakeTransport;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Tests\TestActor;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Tests\TestCase;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Tests\TestTenant;
use Liberu\Ecommerce\InvoicesAndDocuments\Models\Document;

uses(TestCase::class, RefreshDatabase::class)
    ->beforeEach(function (): void {
        TestTenant::reset();
        TestActor::reset();

        PanelTenant::resolveUsing(fn (): string => TestTenant::current());
        PanelActor::resolveUsing(fn (): ?string => TestActor::current());

        // Load-bearing. Every seam is unbound as the domain ships it, and that
        // unbound state is behaviour this suite asserts: a test inheriting a
        // binding from the one before it would prove the opposite of what it
        // claims.
        Config::set('invoices-and-documents.seams.sale', null);
        Config::set('invoices-and-documents.seams.renderer', null);
        Config::set('invoices-and-documents.seams.transport', null);
        Config::set('invoices-and-documents.retention.years', null);

        // No figure survives a test, for the same reason none survives a page
        // load: a total carried past the document it was taken from is one
        // somebody reads after it moved.
        DocumentResource::forgetSummaries();
        SeriesResource::forgetContinuity();
    })
    ->in(__DIR__.'/Feature');

// The rendering rules need a container to read a seam binding out of, and no
// database at all.
uses(TestCase::class)->in(__DIR__.'/Unit');

function line(string $description = 'A widget', int $netMinor = 1000, int $rateBp = 2000, int $taxMinor = 200, string $currency = 'GBP'): Line
{
    return new Line(
        $description,
        1000,
        new Money($netMinor, $currency),
        new Money($netMinor, $currency),
        $rateBp,
        new Money($taxMinor, $currency),
        new Money($netMinor + $taxMinor, $currency),
    );
}

/** @param  list<Line>|null  $lines */
function bindSale(?array $lines = null, string $tenantId = TestTenant::PRIMARY, string $saleRef = 'order-1', ?string $buyerRef = null, ?Money $statedGross = null, ?Party $buyer = null): FakeSaleSource
{
    $source = Config::get('invoices-and-documents.seams.sale');
    $source = $source instanceof FakeSaleSource ? $source : new FakeSaleSource();

    $source->offer(
        $tenantId,
        $saleRef,
        $lines ?? [line()],
        $buyer ?? ($buyerRef === null ? null : new Party($buyerRef, 'A Buyer', '2 Home Road', null, $buyerRef.'@example.test')),
        $statedGross,
    );

    Config::set('invoices-and-documents.seams.sale', $source);

    return $source;
}

function bindRenderer(): FakeRenderer
{
    $renderer = new FakeRenderer();
    Config::set('invoices-and-documents.seams.renderer', $renderer);

    return $renderer;
}

function bindTransport(?TransportOutcome $answer = null): FakeTransport
{
    $transport = new FakeTransport($answer);
    Config::set('invoices-and-documents.seams.transport', $transport);

    return $transport;
}

function series(string $tenantId = TestTenant::PRIMARY, string $code = 'INV', bool $fiscal = true, bool $gapless = true, int $startAt = 1): string
{
    (new OpenSeries())($tenantId, $code, $code.'-', 5, $fiscal, $gapless, $startAt);

    return $code;
}

/** @param  list<Line>|null  $lines */
function draft(string $tenantId = TestTenant::PRIMARY, string $saleRef = 'order-1', DocumentKind $kind = DocumentKind::Invoice, ?array $lines = null, ?string $buyerRef = null, ?Party $buyer = null): Document
{
    bindSale($lines, $tenantId, $saleRef, $buyerRef, null, $buyer);

    $outcome = (new DraftDocument())($tenantId, $kind, $saleRef);

    return Document::query()->findOrFail($outcome->id);
}

/** @param  list<Line>|null  $lines */
function issued(string $tenantId = TestTenant::PRIMARY, string $saleRef = 'order-1', DocumentKind $kind = DocumentKind::Invoice, ?array $lines = null, ?string $buyerRef = null, string $code = 'INV', ?Party $buyer = null): Document
{
    $document = draft($tenantId, $saleRef, $kind, $lines, $buyerRef, $buyer);
    series($tenantId, $code);
    (new IssueDocument())($tenantId, $document, $code);

    return $document->refresh();
}

/**
 * The notifications the last request sent, as title, body and colour.
 *
 * Filament's own `assertNotified()` compares the whole serialised notification,
 * so it fails on an icon this suite has no opinion about. What matters here is
 * which sentence the panel chose and what colour it put on it: a refusal must
 * never be green, and a delivery that left a row behind must never read as one
 * that recorded nothing.
 *
 * Reading them consumes them, because mounting the component drains the
 * session: take one copy per assertion.
 *
 * @return array<int, array{title: ?string, body: ?string, color: mixed}>
 */
function sentNotifications(): array
{
    $component = new Notifications();
    $component->mount();

    return $component->notifications
        ->map(fn (Notification $notification): array => [
            'title' => $notification->getTitle(),
            'body' => $notification->getBody(),
            'color' => $notification->getColor(),
        ])
        ->values()
        ->all();
}

/** @return array{title: ?string, body: ?string, color: mixed} */
function lastNotification(): array
{
    $sent = sentNotifications();

    return $sent === [] ? ['title' => null, 'body' => null, 'color' => null] : $sent[count($sent) - 1];
}
