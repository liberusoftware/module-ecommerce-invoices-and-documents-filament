<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Documents\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Liberu\Ecommerce\InvoicesAndDocuments\Enums\DocumentState;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Concerns\DeniesUnpublishedRelationAbilities;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Documents\DocumentResource;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Support\Render;
use Liberu\Ecommerce\InvoicesAndDocuments\Models\Document;

/**
 * The credit notes raised against this document.
 *
 * This is what a correction looks like here. The host had no credit note
 * anywhere and left a refunded order's invoice reading as paid, so the only way
 * to correct anything was to edit or delete the document itself.
 */
final class CorrectionsRelationManager extends RelationManager
{
    use DeniesUnpublishedRelationAbilities;

    protected static string $relationship = 'corrections';

    protected static ?string $title = 'Credit notes';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label('Number')
                    ->state(fn (Document $record): string => Render::number($record->number))
                    ->copyable(),
                TextColumn::make('state')
                    ->label('State')
                    ->badge()
                    ->formatStateUsing(fn (DocumentState $state): string => Render::stateLabel($state))
                    ->color(fn (DocumentState $state): string => Render::stateColour($state)),
                TextColumn::make('gross')
                    ->label('Credited')
                    ->state(fn (Document $record): string => Render::money(DocumentResource::summary($record)->gross)),
                TextColumn::make('issued_at')->label('Issued')->dateTime()->placeholder(Render::NONE),
            ])
            ->filters([])
            ->paginated(false)
            ->defaultSort('id')
            ->recordUrl(fn (Document $record): string => DocumentResource::getUrl('view', ['record' => $record->reference]))
            ->recordAction(null)
            ->recordActions([])
            ->headerActions([])
            ->toolbarActions([])
            ->emptyStateHeading('Nothing has been credited against this document')
            ->emptyStateDescription('A correction is a credit note that references this one. There is no other way to change what it says.');
    }
}
