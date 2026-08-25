<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Documents\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Liberu\Ecommerce\InvoicesAndDocuments\Enums\DeliveryState;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Concerns\DeniesUnpublishedRelationAbilities;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Support\Render;

/**
 * Every attempt to put this document in front of somebody, and what came of it.
 *
 * A row exists before a transmission does, so a transport that never answered
 * leaves an attempt with no outcome rather than leaving nothing at all. The
 * address is on this screen and on no listing, and erasure clears it while the
 * attempt itself stays.
 */
final class DeliveriesRelationManager extends RelationManager
{
    use DeniesUnpublishedRelationAbilities;

    protected static string $relationship = 'deliveries';

    protected static ?string $title = 'Delivery';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('state')
                    ->label('Outcome')
                    ->badge()
                    ->formatStateUsing(fn (DeliveryState $state): string => Render::deliveryLabel($state))
                    ->color(fn (DeliveryState $state): string => Render::deliveryColour($state)),
                TextColumn::make('channel')->label('How')->badge(),
                TextColumn::make('address')
                    ->label('Where')
                    ->placeholder(Render::NONE)
                    ->tooltip('Cleared by erasure. The attempt and its outcome stay, because they are facts about this document rather than about the person.'),
                TextColumn::make('detail')->label('What the transport said')->placeholder(Render::NONE)->wrap(),
                TextColumn::make('attempted_at')->label('Attempted')->dateTime(),
                TextColumn::make('settled_at')
                    ->label('Answered')
                    ->dateTime()
                    ->placeholder(Render::NONE)
                    ->tooltip('Blank means nothing ever answered, which is not the same as a failure.'),
            ])
            ->filters([])
            ->paginated(false)
            ->defaultSort('attempted_at')
            ->recordUrl(null)
            ->recordAction(null)
            ->recordActions([])
            ->headerActions([])
            ->toolbarActions([])
            ->emptyStateHeading('Nobody has been sent this document')
            ->emptyStateDescription('Nothing has been attempted. A document that has not been sent is not a failed delivery.');
    }
}
