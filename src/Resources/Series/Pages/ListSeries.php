<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Series\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\App;
use Liberu\Ecommerce\InvoicesAndDocuments\Actions\OpenSeries;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Series\SeriesResource;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Support\Apply;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Support\PanelTenant;

/**
 * Opening a series is `Actions\OpenSeries`, not a Filament create page. The
 * difference is not cosmetic: a create page writes through Eloquent, which is
 * how the host came to have invoices nothing had audited.
 */
final class ListSeries extends ListRecords
{
    protected static string $resource = SeriesResource::class;

    public function mount(): void
    {
        SeriesResource::forgetContinuity();

        parent::mount();
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('open')
                ->label('Open a series')
                ->icon('heroicon-o-plus')
                ->modalHeading('Open a numbering series')
                ->modalDescription('A series belongs to this merchant rather than to a storefront, because filing is the merchant\'s obligation. Its policies cannot be changed afterwards: past documents are filed under what it promised at the time.')
                ->modalSubmitActionLabel('Open it')
                ->schema([
                    TextInput::make('code')
                        ->label('Code')
                        ->required()
                        ->maxLength(64)
                        ->helperText('How this series is named when a document is issued. Unique to this merchant.'),
                    TextInput::make('prefix')
                        ->label('Prefix')
                        ->maxLength(32)
                        ->helperText('Printed before the number. `INV-` gives `INV-00001`.'),
                    TextInput::make('pad')
                        ->label('Pad to')
                        ->numeric()
                        ->default(5)
                        ->minValue(0)
                        ->maxValue(20)
                        ->helperText('Leading zeroes, so numbers sort as text the way they sort as numbers.'),
                    TextInput::make('start_at')
                        ->label('Start at')
                        ->numeric()
                        ->default(1)
                        ->minValue(1)
                        ->helperText('For a merchant carrying a series over from somewhere else.'),
                    Toggle::make('fiscal')
                        ->label('Fiscal')
                        ->default(true)
                        ->helperText('A fiscal series files documents a tax authority may ask for. A proforma may not take a number from one.'),
                    Toggle::make('gapless')
                        ->label('Gapless')
                        ->default(true)
                        ->helperText('A gapless series refuses to spend a number on nothing. Turn it off only where the law you file under allows a gap.'),
                ])
                ->action(function (array $data): void {
                    /** @var array{code: string, prefix: ?string, pad: ?string, start_at: ?string, fiscal: bool, gapless: bool} $data */
                    $outcome = App::make(OpenSeries::class)(
                        PanelTenant::current(),
                        $data['code'],
                        $data['prefix'] ?? '',
                        (int) ($data['pad'] ?? 0),
                        $data['fiscal'],
                        $data['gapless'],
                        (int) ($data['start_at'] ?? 1),
                    );

                    SeriesResource::forgetContinuity();

                    Apply::report(
                        $outcome,
                        'Opened',
                        'Documents can now be filed under it. Its policies are fixed from here, because past documents are filed under what it promised.',
                        'A series under that code was already open for this merchant, and nothing about it was changed.',
                    );
                }),
        ];
    }
}
