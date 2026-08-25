<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Documents\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\App;
use Liberu\Ecommerce\InvoicesAndDocuments\Actions\DraftCreditNote;
use Liberu\Ecommerce\InvoicesAndDocuments\Actions\IssueDocument;
use Liberu\Ecommerce\InvoicesAndDocuments\Actions\RecordDelivery;
use Liberu\Ecommerce\InvoicesAndDocuments\Actions\VoidDocument;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\Outcome;
use Liberu\Ecommerce\InvoicesAndDocuments\Enums\DocumentState;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Documents\DocumentResource;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Series\SeriesResource;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Support\Apply;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Support\PanelActor;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Support\Render;
use Liberu\Ecommerce\InvoicesAndDocuments\Models\Document;
use Liberu\Ecommerce\InvoicesAndDocuments\Queries\BuildRenderModel;

/**
 * One document and everything a merchant may do to it.
 *
 * There are four things and none of them is an edit or a delete. Issuing spends
 * the number, sending records an attempt, crediting raises a second document
 * about this one, and voiding records that this one is finished with — keeping
 * its number, which is what keeps a gapless series gapless.
 *
 * A move the document cannot make from where it stands is not offered, *and*
 * every write re-reads the document through `FindDocument` and lets the domain
 * decide, so a document somebody else issued while this page sat open is
 * refused rather than issued twice.
 */
final class ViewDocument extends ViewRecord
{
    protected static string $resource = DocumentResource::class;

    public function mount(int|string $record): void
    {
        DocumentResource::forgetSummaries();

        parent::mount($record);
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('issue')
                ->label('Issue it')
                ->icon('heroicon-o-check-badge')
                ->color('primary')
                ->modalHeading('Issue this document')
                ->modalDescription('The number is spent inside the same transaction that writes the document, under a row lock on the series, so a rollback returns it. That is the opposite of the order allocator, which spends on allocation because a gap in an order number costs nothing — a gap in a fiscal series costs an explanation.')
                ->modalSubmitActionLabel('Issue it')
                ->schema([
                    Select::make('series')
                        ->label('Series')
                        ->required()
                        ->searchable()
                        ->getSearchResultsUsing(SeriesResource::searchCodes(...))
                        ->getOptionLabelUsing(SeriesResource::codeLabel(...))
                        ->visible(fn (Document $record): bool => $record->kind->isFiscal())
                        ->helperText('Searched as you type rather than loaded whole: the host\'s invoice form read every customer and every order on the deployment before it drew a field.'),
                ])
                ->visible(fn (Document $record): bool => $record->state->canTransitionTo(DocumentState::Issued))
                ->action(function (Document $record, array $data): void {
                    /** @var array{series?: string} $data */
                    Apply::to(
                        $record,
                        'Issued',
                        'It is numbered, filed and frozen. From here it can be corrected by a credit note or voided, and by nothing else.',
                        'It had already been issued, and it kept the number it was issued under.',
                        fn (Document $fresh, string $tenant): Outcome => App::make(IssueDocument::class)(
                            $tenant,
                            $fresh,
                            // Absent for a proforma, which is issued unnumbered:
                            // it may not be filed under a fiscal series.
                            $data['series'] ?? null,
                            PanelActor::current(),
                        ),
                    );

                    DocumentResource::forgetSummaries();
                }),

            Action::make('deliver')
                ->label('Send it')
                ->icon('heroicon-o-paper-airplane')
                ->modalHeading('Send this document')
                ->modalDescription('The attempt is written down before anything is transmitted, and "delivered" is what the transport answered rather than what dispatching implied. The host had a mailable nothing ever constructed and not one fact about a delivery anywhere.')
                ->modalSubmitActionLabel('Send it')
                ->schema([
                    TextInput::make('channel')
                        ->label('How')
                        ->required()
                        ->default('email')
                        ->maxLength(64)
                        ->helperText('The transport\'s own name for the route. Opaque here.'),
                    TextInput::make('address')
                        ->label('Where')
                        ->maxLength(255)
                        ->helperText('Leave it blank to use the buyer email frozen on this document. There is no fallback beyond that: an attempt with nowhere to go is refused rather than sent somewhere.'),
                ])
                ->visible(fn (Document $record): bool => $record->state->isIssued())
                ->action(function (Document $record, array $data): void {
                    /** @var array{channel: string, address: ?string} $data */
                    Apply::then(
                        $record,
                        // A fresh reference per press. The domain's reference is
                        // an idempotency key for a machine caller retrying; a
                        // person pressing Send twice means it twice.
                        fn (Document $fresh, string $tenant): Outcome => App::make(RecordDelivery::class)(
                            $tenant,
                            $fresh,
                            bin2hex(random_bytes(16)),
                            $data['channel'],
                            ($data['address'] ?? null) ?: null,
                        ),
                        function (Outcome $outcome): void {
                            Notification::make()
                                ->title(Render::deliveryTitle($outcome))
                                ->body(Render::delivery($outcome))
                                ->color(Render::outcomeColour($outcome))
                                ->persistent()
                                ->send();
                        },
                    );

                    DocumentResource::forgetSummaries();
                }),

            Action::make('credit')
                ->label('Credit it in full')
                ->icon('heroicon-o-receipt-refund')
                ->color('warning')
                ->modalHeading('Raise a credit note for the whole of this document')
                ->modalDescription('A document is corrected by another document, never by an edit. Every line of this one is copied onto a credit note, which then has to be issued under a series of its own. Crediting part of a document is arithmetic, and arithmetic belongs to the domain rather than to a form.')
                ->modalSubmitActionLabel('Raise it')
                ->schema([
                    Textarea::make('note')
                        ->label('Why')
                        ->rows(3)
                        ->maxLength(65535)
                        ->helperText('Printed on the credit note and frozen with it.'),
                ])
                ->visible(fn (Document $record): bool => $record->state->isIssued() && ! $record->kind->correctsAnother())
                ->action(function (Document $record, array $data): void {
                    /** @var array{note: ?string} $data */
                    Apply::to(
                        $record,
                        'Drafted',
                        'The credit note carries this document\'s buyer and this document\'s lines. Issue it under a series to give it a number.',
                        'A credit note for the whole of this document already exists, and a second was not raised.',
                        fn (Document $fresh, string $tenant): Outcome => App::make(DraftCreditNote::class)(
                            $tenant,
                            $fresh,
                            // The corrected document is the cause, so it is the
                            // natural key: pressing twice cannot raise two.
                            $fresh->reference,
                            (new BuildRenderModel())($tenant, $fresh)->lines,
                            ($data['note'] ?? null) ?: null,
                        ),
                    );

                    DocumentResource::forgetSummaries();
                }),

            Action::make('voidDocument')
                ->label(fn (Document $record): string => $record->state === DocumentState::Draft ? 'Discard it' : 'Void it')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->modalHeading('Record that this document is finished with')
                ->modalDescription('There is no delete anywhere in this module. Voiding records — the reason, who did it and the state it came from all go on the ledger — and it keeps the number, which is what keeps a gapless series gapless.')
                ->modalSubmitActionLabel('Record it')
                ->schema([
                    TextInput::make('reason')
                        ->label('Why')
                        ->required()
                        ->maxLength(255)
                        ->helperText('This is what an audit reads.'),
                ])
                ->visible(fn (Document $record): bool => $record->state->canTransitionTo(DocumentState::Void))
                ->action(function (Document $record, array $data): void {
                    /** @var array{reason: string} $data */
                    Apply::to(
                        $record,
                        'Voided',
                        'It is on the ledger with its reason, and it keeps its number. Nothing was erased.',
                        'It had already been voided.',
                        fn (Document $fresh, string $tenant): Outcome => App::make(VoidDocument::class)(
                            $tenant,
                            $fresh,
                            $data['reason'],
                            PanelActor::current(),
                        ),
                    );

                    DocumentResource::forgetSummaries();
                }),
        ];
    }
}
