<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Documents\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\Line;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Concerns\DeniesUnpublishedRelationAbilities;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Support\Render;
use Liberu\Ecommerce\InvoicesAndDocuments\Models\Document;
use Liberu\Ecommerce\InvoicesAndDocuments\Models\DocumentLine;

/**
 * The lines as they were, not as the catalogue is.
 *
 * The host's lines were a `belongsToMany` straight onto the live `Product`, so
 * renaming a product rewrote every past invoice and deleting one removed lines
 * from a header total that carried on printing. These are copies this module
 * owns; nothing here edits, deletes or dissociates one, and the domain would
 * raise if anything tried.
 */
final class LinesRelationManager extends RelationManager
{
    use DeniesUnpublishedRelationAbilities;

    protected static string $relationship = 'lines';

    protected static ?string $title = 'Lines';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('position')->label('#'),
                TextColumn::make('description')->label('What')->wrap(),
                TextColumn::make('quantity')
                    ->label('How many')
                    ->state(fn (DocumentLine $record): string => $this->line($record)->quantity())
                    ->tooltip('Stored in thousandths, so a fractional quantity never passes through a float.'),
                TextColumn::make('unit_net')->label('Each')->state(fn (DocumentLine $record): string => Render::money($this->line($record)->unitNet)),
                TextColumn::make('net')->label('Net')->state(fn (DocumentLine $record): string => Render::money($this->line($record)->net)),
                TextColumn::make('tax_rate_bp')
                    ->label('Rate')
                    ->state(fn (DocumentLine $record): string => Render::taxRate($record->tax_rate_bp)),
                TextColumn::make('tax')->label('Tax')->state(fn (DocumentLine $record): string => Render::money($this->line($record)->tax)),
                TextColumn::make('gross')->label('Gross')->state(fn (DocumentLine $record): string => Render::money($this->line($record)->gross)),
            ])
            ->filters([])
            ->paginated(false)
            ->defaultSort('position')
            ->recordUrl(null)
            ->recordAction(null)
            ->recordActions([])
            ->headerActions([])
            ->toolbarActions([])
            ->emptyStateHeading('This document has no lines')
            ->emptyStateDescription('Which should be impossible: a sale with no lines is refused at draft.');
    }

    /** A stored line, read back in the currency the document was frozen with. */
    private function line(DocumentLine $record): Line
    {
        $document = $this->document();

        return $record->toLine($document->currency, $document->currency_exponent);
    }

    private function document(): Document
    {
        /** @var Document $record */
        $record = $this->getOwnerRecord();

        return $record;
    }
}
