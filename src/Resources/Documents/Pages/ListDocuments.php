<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Documents\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\App;
use Liberu\Ecommerce\InvoicesAndDocuments\Actions\DraftDocument;
use Liberu\Ecommerce\InvoicesAndDocuments\Enums\DocumentKind;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Documents\DocumentResource;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Support\Apply;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Support\PanelTenant;

/**
 * No `CreateAction`. A document is drafted by `Actions\DraftDocument`, which
 * reads the sale once through a seam and copies everything the document will
 * ever display; a Filament create page would write a row through Eloquent with
 * whatever somebody typed into it, which is the host's free-text total again.
 */
final class ListDocuments extends ListRecords
{
    protected static string $resource = DocumentResource::class;

    public function mount(): void
    {
        DocumentResource::forgetSummaries();

        parent::mount();
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('draftFromSale')
                ->label('Draft from a sale')
                ->icon('heroicon-o-document-plus')
                ->modalHeading('Draft a document from a sale')
                ->modalDescription('This is the one moment the sale is read. Every line description, quantity, unit price, tax rate and tax amount, both identities and the currency are copied now, and nothing is read from the sale again — so renaming a product or erasing a customer later cannot change what this document says.')
                ->modalSubmitActionLabel('Draft it')
                ->schema([
                    Select::make('kind')
                        ->label('Kind')
                        ->required()
                        ->default(DocumentKind::Invoice->value)
                        ->options(fn (): array => DocumentResource::draftableKindOptions())
                        ->helperText('A credit note is not here: it is about another document, and it is raised from the document it corrects.'),
                    TextInput::make('source_ref')
                        ->label('The sale')
                        ->required()
                        ->maxLength(255)
                        ->helperText('The order, payment or refund reference whatever is bound as the sale source knows. This module never resolves it.'),
                    Textarea::make('note')
                        ->label('Note')
                        ->rows(3)
                        ->maxLength(65535)
                        ->helperText('Printed on the document. It is frozen with everything else.'),
                ])
                ->action(function (array $data): void {
                    /** @var array{kind: string, source_ref: string, note: ?string} $data */
                    $outcome = App::make(DraftDocument::class)(
                        PanelTenant::current(),
                        DocumentKind::from($data['kind']),
                        $data['source_ref'],
                        ($data['note'] ?? null) ?: null,
                    );

                    DocumentResource::forgetSummaries();

                    Apply::report(
                        $outcome,
                        'Drafted',
                        'Everything it will display is copied and frozen. It has no number yet: a number is spent when it is issued.',
                        'A document of that kind was already drafted from that sale, and a second was not created.',
                    );
                }),
        ];
    }
}
