<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Documents\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Liberu\Ecommerce\InvoicesAndDocuments\Enums\EventKind;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Concerns\DeniesUnpublishedRelationAbilities;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Support\Render;
use Liberu\Ecommerce\InvoicesAndDocuments\Models\DocumentEvent;

/**
 * Every move this document has made, in sequence, with who made it.
 *
 * Append-only in the domain and arbitrated by a unique `(document, sequence)`
 * index. This is the row the host never wrote: it shipped an edit form, a
 * delete action and a bulk delete on a financial document and recorded none of
 * them anywhere.
 */
final class HistoryRelationManager extends RelationManager
{
    use DeniesUnpublishedRelationAbilities;

    protected static string $relationship = 'events';

    protected static ?string $title = 'History';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sequence')->label('#'),
                TextColumn::make('kind')
                    ->label('What happened')
                    ->badge()
                    ->formatStateUsing(fn (EventKind $state): string => Render::eventLabel($state)),
                TextColumn::make('from_state')
                    ->label('From')
                    ->state(fn (DocumentEvent $record): string => Render::stateName($record->from_state)),
                TextColumn::make('to_state')
                    ->label('To')
                    ->state(fn (DocumentEvent $record): string => Render::stateName($record->to_state)),
                TextColumn::make('actor_ref')
                    ->label('Who')
                    ->placeholder(Render::NONE)
                    ->tooltip('Blank means nobody was named, which the module records as a fact rather than attributing the move to an empty string.'),
                TextColumn::make('detail')
                    ->label('Detail')
                    ->state(fn (DocumentEvent $record): string => Render::detail($record->detail))
                    ->wrap(),
                TextColumn::make('occurred_at')->label('When')->dateTime(),
            ])
            ->filters([])
            ->paginated(false)
            ->defaultSort('sequence')
            ->recordUrl(null)
            ->recordAction(null)
            ->recordActions([])
            ->headerActions([])
            ->toolbarActions([])
            ->emptyStateHeading('Nothing has happened to this document')
            ->emptyStateDescription('Not even the drafting, which should be impossible: a document exists because one was recorded.');
    }
}
