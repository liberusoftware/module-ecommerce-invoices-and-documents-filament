<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Series\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Liberu\Ecommerce\InvoicesAndDocuments\Enums\DocumentKind;
use Liberu\Ecommerce\InvoicesAndDocuments\Enums\DocumentState;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Concerns\DeniesUnpublishedRelationAbilities;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Documents\DocumentResource;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Support\Render;
use Liberu\Ecommerce\InvoicesAndDocuments\Models\Document;

/** What this series has been spent on. Nothing here associates or dissociates: a filed document keeps the number it was filed under, including when it is voided. */
final class DocumentsRelationManager extends RelationManager
{
    use DeniesUnpublishedRelationAbilities;

    protected static string $relationship = 'documents';

    protected static ?string $title = 'Filed under it';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label('Number')
                    ->state(fn (Document $record): string => Render::number($record->number))
                    ->copyable(),
                TextColumn::make('kind')
                    ->label('Kind')
                    ->badge()
                    ->formatStateUsing(fn (DocumentKind $state): string => Render::kindLabel($state))
                    ->color(fn (DocumentKind $state): string => Render::kindColour($state)),
                TextColumn::make('state')
                    ->label('State')
                    ->badge()
                    ->formatStateUsing(fn (DocumentState $state): string => Render::stateLabel($state))
                    ->color(fn (DocumentState $state): string => Render::stateColour($state)),
                TextColumn::make('buyer_name')->label('Buyer'),
                TextColumn::make('issued_at')->label('Issued')->dateTime()->placeholder(Render::NONE),
            ])
            ->filters([])
            ->defaultSort('number_sequence')
            ->recordUrl(fn (Document $record): string => DocumentResource::getUrl('view', ['record' => $record->reference]))
            ->recordAction(null)
            ->recordActions([])
            ->headerActions([])
            ->toolbarActions([])
            ->emptyStateHeading('Nothing has been filed under this series')
            ->emptyStateDescription('Its next number has not been spent on a document yet.');
    }
}
