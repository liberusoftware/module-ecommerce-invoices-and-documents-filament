<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Series\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\App;
use Liberu\Ecommerce\InvoicesAndDocuments\Actions\BurnNumber;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Series\SeriesResource;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Support\Apply;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Support\PanelTenant;
use Liberu\Ecommerce\InvoicesAndDocuments\Models\Series;

/**
 * One series, what it has spent, and the one thing an operator may do to it.
 *
 * There is no edit form. A series' prefix, padding and policies are what past
 * documents were filed under; changing them would rewrite how already-issued
 * numbers read.
 */
final class ViewSeries extends ViewRecord
{
    protected static string $resource = SeriesResource::class;

    public function mount(int|string $record): void
    {
        SeriesResource::forgetContinuity();

        parent::mount($record);
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('burn')
                ->label('Burn a number')
                ->icon('heroicon-o-fire')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Spend a number on nothing, on the record')
                ->modalDescription('For a number that has left this system on paper and must never be issued again. It is recorded with a reason, so a hole in the series is something an auditor can be shown rather than a silence somebody has to explain.')
                ->modalSubmitActionLabel('Burn it')
                ->schema([
                    TextInput::make('reason')
                        ->label('Why')
                        ->required()
                        ->maxLength(255)
                        ->helperText('This is the whole point of the row. It is what an audit reads.'),
                ])
                // A gapless series refuses, so it is not offered one. Asking
                // anyway is still refused rather than done.
                ->visible(fn (Series $record): bool => ! $record->gapless)
                ->action(function (Series $record, array $data): void {
                    /** @var array{reason: string} $data */
                    $outcome = App::make(BurnNumber::class)(PanelTenant::current(), $record->code, $data['reason']);

                    SeriesResource::forgetContinuity();

                    Apply::report(
                        $outcome,
                        'Burned',
                        'The number is spent and accounted for. It will never be issued to a document.',
                        'That number was already burned.',
                    );
                }),
        ];
    }
}
