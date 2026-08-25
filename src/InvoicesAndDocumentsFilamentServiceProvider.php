<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Filament;

use Illuminate\Support\ServiceProvider;

/**
 * Registers nothing. Every screen arrives through {@see InvoicesAndDocumentsPlugin},
 * so the host decides which panels get the filing cabinet — a provider that
 * registered resources would put one merchant's invoices on whatever panel
 * happened to boot, including a shopper-facing one.
 */
class InvoicesAndDocumentsFilamentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}
