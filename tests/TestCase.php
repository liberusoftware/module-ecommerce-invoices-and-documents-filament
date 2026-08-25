<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Filament\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\InvoicesAndDocumentsFilamentServiceProvider;
use Liberu\Ecommerce\InvoicesAndDocuments\InvoicesAndDocumentsServiceProvider;
use Liberu\PackageTestbench\PackageTestCase;
use Livewire\LivewireServiceProvider;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;

abstract class TestCase extends PackageTestCase
{
    /**
     * Filament's providers register before Livewire's, and must:
     * `filament/support` re-`bind()`s Livewire's `DataStore`, and a `bind()`
     * drops whatever was registered under that key. Boot Livewire first and
     * every render fails with `ViewErrorBag::put(): $bag must be MessageBag,
     * null given` — an error thrown nowhere near the ordering that caused it.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,

            SupportServiceProvider::class,
            ActionsServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            FilamentServiceProvider::class,

            LivewireServiceProvider::class,

            InvoicesAndDocumentsServiceProvider::class,
            InvoicesAndDocumentsFilamentServiceProvider::class,
            TestPanelProvider::class,
        ];
    }

    /**
     * `app.debug` is on deliberately. Livewire swallows a `TypeError` raised
     * inside a component method whole — no write, no throw, no error state, and
     * a bare 419 — so a signature mistake reads as a session problem without it.
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.debug', true);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }
}
