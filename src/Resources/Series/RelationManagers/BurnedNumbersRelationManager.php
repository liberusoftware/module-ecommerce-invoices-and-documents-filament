<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Series\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Concerns\DeniesUnpublishedRelationAbilities;

/**
 * Numbers this series spent on nothing, each with the reason it was spent.
 *
 * The row is the whole point: a hole in a series that nobody wrote down is a
 * silence somebody has to explain to an auditor years later.
 */
final class BurnedNumbersRelationManager extends RelationManager
{
    use DeniesUnpublishedRelationAbilities;

    protected static string $relationship = 'burnedNumbers';

    protected static ?string $title = 'Burned';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('Number')->copyable(),
                TextColumn::make('number_sequence')->label('#'),
                TextColumn::make('reason')->label('Why')->wrap(),
                TextColumn::make('burned_at')->label('When')->dateTime(),
            ])
            ->filters([])
            ->paginated(false)
            ->defaultSort('number_sequence')
            ->recordUrl(null)
            ->recordAction(null)
            ->recordActions([])
            ->headerActions([])
            ->toolbarActions([])
            ->emptyStateHeading('Nothing has been burned')
            ->emptyStateDescription('Every number this series has spent is on a document.');
    }
}
