<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Series;

use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\ContinuityReport;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Concerns\DeniesUnpublishedResourceAbilities;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Series\Pages\ListSeries;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Series\Pages\ViewSeries;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Series\RelationManagers\BurnedNumbersRelationManager;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Series\RelationManagers\DocumentsRelationManager;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Support\PanelTenant;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Support\Render;
use Liberu\Ecommerce\InvoicesAndDocuments\Models\Series;
use Liberu\Ecommerce\InvoicesAndDocuments\Queries\CheckSeriesContinuity;
use UnitEnum;

/**
 * One merchant's numbering series, and what each has spent.
 *
 * A series belongs to the merchant rather than to a storefront, because the
 * obligation to file is the merchant's. `commerce-core`'s order sequence is per
 * store and spends its number on allocation; a fiscal series can afford
 * neither, and the ADR in the domain package says why.
 *
 * The route key is the code, resolved over the tenant-scoped query, so another
 * merchant's code and one that does not exist are the same
 * `ModelNotFoundException`.
 */
final class SeriesResource extends Resource
{
    use DeniesUnpublishedResourceAbilities;

    protected static ?string $model = Series::class;

    protected static bool $isScopedToTenant = false;

    protected static ?string $recordTitleAttribute = 'code';

    protected static ?string $modelLabel = 'numbering series';

    protected static ?string $pluralModelLabel = 'numbering series';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-hashtag';

    protected static ?string $navigationLabel = 'Numbering';

    protected static UnitEnum|string|null $navigationGroup = 'Invoicing';

    protected static ?int $navigationSort = 20;

    public static function getRecordRouteKeyName(): string
    {
        return 'code';
    }

    /** A panel with no merchant has no series to be about. */
    public static function canViewAny(): bool
    {
        return PanelTenant::resolvable();
    }

    /**
     * The domain's `CustodyPolicy` answers about a document and publishes
     * nothing about a series, so this states the same rule the tenant-scoped
     * query already applies. It is reported as a gap rather than hidden.
     */
    public static function canView(Model $record): bool
    {
        return $record instanceof Series
            && PanelTenant::resolvable()
            && $record->tenant_id === PanelTenant::current();
    }

    /** @return Builder<Series> */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Series> $query */
        $query = parent::getEloquentQuery();

        // `withCount` builds each relation from a fresh instance whose
        // `tenant_id` is null, which is exactly where the domain's guarded
        // restatement earns its keep: unguarded it counts nothing and looks
        // like isolation working.
        return $query->where('tenant_id', PanelTenant::current())->withCount(['documents', 'burnedNumbers']);
    }

    /** @return array<class-string> */
    public static function getRelations(): array
    {
        return [
            DocumentsRelationManager::class,
            BurnedNumbersRelationManager::class,
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('Code')->searchable()->sortable(),
                TextColumn::make('prefix')->label('Prefix')->placeholder(Render::NONE),
                TextColumn::make('next_value')->label('Next')->sortable(),
                IconColumn::make('fiscal')
                    ->label('Fiscal')
                    ->boolean()
                    ->tooltip('A fiscal series files documents a tax authority may ask for. A proforma may not take a number from one.'),
                IconColumn::make('gapless')
                    ->label('Gapless')
                    ->boolean()
                    ->tooltip('A gapless series refuses to spend a number on nothing. Any other series records the burn instead of leaving a silent hole.'),
                TextColumn::make('documents_count')->label('Documents')->sortable(),
                TextColumn::make('burned_numbers_count')->label('Burned'),
                TextColumn::make('continuity')
                    ->label('Continuity')
                    ->badge()
                    ->state(fn (Series $record): string => self::continuity($record)->isContinuous() ? 'Accounted for' : 'Unaccounted for')
                    ->color(fn (Series $record): string => Render::continuityColour(self::continuity($record))),
            ])
            ->filters([])
            ->defaultSort('code')
            ->recordActions([])
            // No bulk anything. A series is what past documents are filed
            // under; there is nothing here to select several of and act on.
            ->toolbarActions([])
            ->emptyStateIcon('heroicon-o-hashtag')
            ->emptyStateHeading('This merchant files under no series yet')
            ->emptyStateDescription('An invoice, a credit note and a receipt each have to be filed under one before they can be issued. Open one to start.');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('The series')
                ->columns(4)
                ->schema([
                    TextEntry::make('code')->label('Code')->copyable(),
                    TextEntry::make('prefix')->label('Prefix')->placeholder(Render::NONE),
                    TextEntry::make('pad')->label('Padded to')->suffix(' digits'),
                    TextEntry::make('next_value')
                        ->label('Next number')
                        ->helperText('Spent under a row lock inside the transaction that writes the document, so a rollback returns it.'),
                ]),

            Section::make('What it promises')
                ->columns(2)
                ->schema([
                    TextEntry::make('fiscal')
                        ->label('Fiscal')
                        ->badge()
                        ->state(fn (Series $record): string => $record->fiscal ? 'Fiscal' : 'Not fiscal')
                        ->color(fn (Series $record): string => $record->fiscal ? 'primary' : 'gray')
                        ->helperText('A proforma may not be filed under a fiscal series, because it is not a document a tax authority will be shown.'),
                    TextEntry::make('gapless')
                        ->label('Gapless')
                        ->badge()
                        ->state(fn (Series $record): string => $record->gapless ? 'Gapless' : 'Gaps allowed, recorded')
                        ->color(fn (Series $record): string => $record->gapless ? 'primary' : 'gray')
                        ->helperText('Gaplessness is this series\' policy, not a property of invoicing: jurisdictions differ. A gapless series refuses to burn a number at all.'),
                ]),

            Section::make('What it has spent')
                ->schema([
                    TextEntry::make('continuity')
                        ->label('Continuity')
                        ->state(fn (Series $record): string => Render::continuity(self::continuity($record)))
                        ->color(fn (Series $record): string => Render::continuityColour(self::continuity($record)))
                        ->helperText('Reconciled on read against the documents issued and the numbers burned. Nothing in this module can leave a hole, so one is an external event.'),
                ]),
        ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListSeries::route('/'),
            'view' => ViewSeries::route('/{record}'),
        ];
    }

    /**
     * The codes this merchant files under, matching what was typed.
     *
     * Searched and bounded, never a `pluck()` of the table at form-build time:
     * the host's invoice form loaded every customer and every order on the
     * deployment before it drew a single field.
     *
     * @return array<string, string>
     */
    public static function searchCodes(string $search): array
    {
        /** @var array<string, string> $codes */
        $codes = self::getEloquentQuery()
            ->where('code', 'like', '%'.$search.'%')
            ->orderBy('code')
            ->limit(50)
            ->pluck('code', 'code')
            ->all();

        return $codes;
    }

    /** The value is the code; the label is the code, if it is this merchant's. */
    public static function codeLabel(?string $value): ?string
    {
        return $value === null ? null : (self::searchCodes($value)[$value] ?? null);
    }

    /**
     * The domain's reconciliation for one series.
     *
     * ponytail: memoised per row, because the badge and its colour each read it
     * and a list page reads it once per series. Bounded by the number of series
     * a merchant runs by hand; if that stops being true, a batched continuity
     * belongs in the domain.
     *
     * @var array<int, ContinuityReport>
     */
    private static array $continuity = [];

    public static function continuity(Series $record): ContinuityReport
    {
        return self::$continuity[(int) $record->id] ??= (new CheckSeriesContinuity())($record->tenant_id, $record->code);
    }

    public static function forgetContinuity(): void
    {
        self::$continuity = [];
    }
}
