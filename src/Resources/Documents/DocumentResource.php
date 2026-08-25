<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Documents;

use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\DocumentSummary;
use Liberu\Ecommerce\InvoicesAndDocuments\Enums\DocumentKind;
use Liberu\Ecommerce\InvoicesAndDocuments\Enums\DocumentState;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Concerns\DeniesUnpublishedResourceAbilities;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Documents\Pages\ListDocuments;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Documents\Pages\ViewDocument;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Documents\RelationManagers\CorrectionsRelationManager;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Documents\RelationManagers\DeliveriesRelationManager;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Documents\RelationManagers\HistoryRelationManager;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Documents\RelationManagers\LinesRelationManager;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Support\PanelTenant;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Support\Render;
use Liberu\Ecommerce\InvoicesAndDocuments\Models\Document;
use Liberu\Ecommerce\InvoicesAndDocuments\Policies\CustodyPolicy;
use Liberu\Ecommerce\InvoicesAndDocuments\Queries\SummariseDocument;
use UnitEnum;

/**
 * One merchant's documents, and everything each one says.
 *
 * No create page, no edit page, no delete action; every total summed from the
 * document's own frozen lines on read; the route key the reference this module
 * mints. The host's `InvoiceResource` had the opposite of each, and `docs/panel.md`
 * carries which fault produced which decision.
 */
final class DocumentResource extends Resource
{
    use DeniesUnpublishedResourceAbilities;

    protected static ?string $model = Document::class;

    protected static bool $isScopedToTenant = false;

    protected static ?string $recordTitleAttribute = 'reference';

    protected static ?string $modelLabel = 'document';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Documents';

    protected static UnitEnum|string|null $navigationGroup = 'Invoicing';

    protected static ?int $navigationSort = 10;

    public static function getRecordRouteKeyName(): string
    {
        return 'reference';
    }

    /** A panel with no merchant has no documents to be about. */
    public static function canViewAny(): bool
    {
        return PanelTenant::resolvable();
    }

    /**
     * Custody, asked of the domain. The host dropped the ownership filter
     * entirely for `hasRole(['super_admin','admin'])`, which is a role name and
     * not a merchant: one merchant's admin read every merchant's invoices.
     */
    public static function canView(Model $record): bool
    {
        return $record instanceof Document
            && PanelTenant::resolvable()
            && CustodyPolicy::ownsDocument($record, PanelTenant::current());
    }

    /** @return Builder<Document> */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Document> $query */
        $query = parent::getEloquentQuery();

        return $query->where('tenant_id', PanelTenant::current())->withCount('lines');
    }

    /** @return array<class-string> */
    public static function getRelations(): array
    {
        return [
            LinesRelationManager::class,
            DeliveriesRelationManager::class,
            CorrectionsRelationManager::class,
            HistoryRelationManager::class,
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label('Number')
                    ->state(fn (Document $record): string => Render::number($record->number))
                    ->copyable()
                    ->searchable()
                    ->tooltip('Allocated from a series inside the transaction that issued the document. A proforma has none, which is correct rather than missing.'),
                TextColumn::make('kind')
                    ->label('Kind')
                    ->badge()
                    ->formatStateUsing(fn (DocumentKind $state): string => Render::kindLabel($state))
                    ->color(fn (DocumentKind $state): string => Render::kindColour($state))
                    ->sortable(),
                TextColumn::make('state')
                    ->label('State')
                    ->badge()
                    ->formatStateUsing(fn (DocumentState $state): string => Render::stateLabel($state))
                    ->color(fn (DocumentState $state): string => Render::stateColour($state))
                    ->sortable(),
                TextColumn::make('buyer_name')
                    ->label('Buyer')
                    ->searchable()
                    ->tooltip('As it was at issue. The buyer\'s email is on the record screen and on no listing.'),
                TextColumn::make('gross')
                    ->label('Total')
                    ->state(fn (Document $record): string => Render::money(self::summary($record)->gross))
                    ->tooltip('Summed from this document\'s own frozen lines every time it is asked for. No column holds it, so nothing can drift away from what the document says.'),
                TextColumn::make('lines_count')
                    ->label('Lines')
                    ->tooltip('Counted through the document\'s own relation. Deleting a product cannot remove one: the lines are copies this module owns.'),
                TextColumn::make('issued_at')->label('Issued')->dateTime()->placeholder(Render::NONE)->sortable(),
                TextColumn::make('reference')->label('Reference')->copyable()->searchable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('kind')->label('Kind')->options(fn (): array => self::kindOptions()),
                SelectFilter::make('state')->label('State')->options(fn (): array => self::stateOptions()),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([])
            // No bulk actions at all. The host shipped a `DeleteBulkAction` on
            // financial documents; there is nothing here to select several of
            // and act on.
            ->toolbarActions([])
            ->emptyStateIcon('heroicon-o-document-text')
            ->emptyStateHeading('This merchant has issued nothing')
            ->emptyStateDescription('A document is drafted from a sale, which copies everything it will ever display, and issued under a numbering series.');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('The document')
                ->columns(4)
                ->schema([
                    TextEntry::make('kind')
                        ->label('Kind')
                        ->badge()
                        ->state(fn (Document $record): string => Render::kindLabel($record->kind))
                        ->color(fn (Document $record): string => Render::kindColour($record->kind)),
                    TextEntry::make('state')
                        ->label('State')
                        ->badge()
                        ->state(fn (Document $record): string => Render::stateLabel($record->state))
                        ->color(fn (Document $record): string => Render::stateColour($record->state)),
                    TextEntry::make('number')
                        ->label('Number')
                        ->state(fn (Document $record): string => Render::number($record->number))
                        ->copyable()
                        ->helperText('The number this document is filed under. Spent inside the transaction that issued it, so a rollback returns it rather than leaving a hole.'),
                    TextEntry::make('reference')
                        ->label('Reference')
                        ->copyable()
                        ->helperText('This module\'s own handle. It is not a row id, and it is not the count of documents the platform has ever issued.'),
                    TextEntry::make('source_ref')
                        ->label('The sale it came from')
                        ->copyable()
                        ->helperText('Opaque here. It was read once, at draft, and never again.'),
                    TextEntry::make('corrects')
                        ->label('Corrects')
                        ->state(fn (Document $record): string => $record->corrects_document_id === null
                            ? Render::NONE
                            : $record->corrects()->firstOrFail()->reference)
                        ->helperText('A credit note is about another document. Nothing else corrects one.'),
                    TextEntry::make('note')->label('Note')->placeholder(Render::NONE)->columnSpan(2),
                ]),

            Section::make('The money')
                ->description('Summed from the frozen lines on read, and never recomputed: the tax module divides and rounds, this module only adds. The host had no currency anywhere on the money path and printed a dollar sign over it.')
                ->columns(3)
                ->schema([
                    TextEntry::make('net')->label('Net')->state(fn (Document $record): string => Render::money(self::summary($record)->net)),
                    TextEntry::make('tax')->label('Tax')->state(fn (Document $record): string => Render::money(self::summary($record)->tax)),
                    TextEntry::make('gross')->label('Gross')->state(fn (Document $record): string => Render::money(self::summary($record)->gross)),
                    TextEntry::make('by_rate')
                        ->label('Per rate')
                        ->state(fn (Document $record): array => Render::taxSummary(self::summary($record)))
                        ->listWithLineBreaks()
                        ->columnSpanFull()
                        ->helperText('A VAT invoice that states only a gross total is not a valid VAT invoice. The host copied no tax figure onto one at all.'),
                ]),

            Section::make('Seller')
                ->columns(4)
                ->schema([
                    TextEntry::make('seller_name')->label('Name'),
                    TextEntry::make('seller_address')->label('Address'),
                    TextEntry::make('seller_tax_id')->label('Tax registration')->placeholder(Render::NONE),
                    TextEntry::make('seller_ref')->label('Reference')->copyable(),
                ]),

            Section::make('Buyer')
                ->description('As it was at issue. Renaming or erasing a customer elsewhere cannot rewrite this; the host read the live customer row and every historical invoice changed with it.')
                ->columns(4)
                ->schema([
                    TextEntry::make('buyer_name')->label('Name'),
                    TextEntry::make('buyer_address')->label('Address'),
                    TextEntry::make('buyer_tax_id')->label('Tax registration')->placeholder(Render::NONE),
                    TextEntry::make('buyer_email')
                        ->label('Email')
                        ->placeholder(Render::NONE)
                        ->copyable()
                        ->helperText('On this screen and on no listing.'),
                ]),

            Section::make('Keeping and delivering')
                ->columns(2)
                ->schema([
                    TextEntry::make('retention')
                        ->label('Retention')
                        ->state(fn (Document $record): string => Render::retention($record->issued_at, $record->retain_until)),
                    TextEntry::make('renderer')
                        ->label('Rendering')
                        ->state(fn (): string => Render::renderer()),
                    TextEntry::make('redacted_at')
                        ->label('Buyer redacted')
                        ->dateTime()
                        ->placeholder(Render::NONE)
                        ->helperText('Erasure redacts the buyer and keeps every figure. Retention outranks it, so a document still inside its window is refused by name.'),
                    TextEntry::make('void_reason')
                        ->label('Voided because')
                        ->placeholder(Render::NONE)
                        ->helperText('Void records and keeps the number, which is what keeps a gapless series gapless.'),
                    TextEntry::make('issued_at')->label('Issued')->dateTime()->placeholder(Render::NONE),
                    TextEntry::make('delivered_at')->label('Delivered')->dateTime()->placeholder(Render::NONE),
                ]),
        ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListDocuments::route('/'),
            'view' => ViewDocument::route('/{record}'),
        ];
    }

    /** @return array<string, string> */
    public static function kindOptions(): array
    {
        $options = [];

        foreach (DocumentKind::cases() as $kind) {
            $options[$kind->value] = Render::kindLabel($kind);
        }

        return $options;
    }

    /** @return array<string, string> */
    public static function stateOptions(): array
    {
        $options = [];

        foreach (DocumentState::cases() as $state) {
            $options[$state->value] = Render::stateLabel($state);
        }

        return $options;
    }

    /**
     * The kinds a document can be drafted from a sale as.
     *
     * A credit note is not among them: it is about another document, and the
     * domain refuses to draft one from a sale on its own.
     *
     * @return array<string, string>
     */
    public static function draftableKindOptions(): array
    {
        return array_diff_key(self::kindOptions(), [DocumentKind::CreditNote->value => null]);
    }

    /**
     * The domain's totals for one document.
     *
     * ponytail: memoised per row, because four entries and a column read it and
     * each read walks the lines. Dropped when a page mounts and after every
     * write, so no figure outlives the document state it was taken from.
     *
     * @var array<int, DocumentSummary>
     */
    private static array $summaries = [];

    public static function summary(Document $record): DocumentSummary
    {
        return self::$summaries[(int) $record->id] ??= (new SummariseDocument())($record->tenant_id, $record);
    }

    public static function forgetSummaries(): void
    {
        self::$summaries = [];
    }
}
