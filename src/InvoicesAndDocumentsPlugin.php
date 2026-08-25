<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Filament;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Documents\DocumentResource;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Resources\Series\SeriesResource;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Support\PanelActor;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\Support\PanelTenant;

/**
 * The filing cabinet, as the host attaches it.
 *
 *     $panel->plugin(
 *         InvoicesAndDocumentsPlugin::make()
 *             ->tenantUsing(fn (): string => (string) Filament::getTenant()?->getKey())
 *             ->actorUsing(fn (): ?string => Filament::auth()->id()),
 *     );
 */
final class InvoicesAndDocumentsPlugin implements Plugin
{
    public static function make(): self
    {
        return new self();
    }

    public function getId(): string
    {
        return 'ecommerce-invoices-and-documents';
    }

    /** How this panel names the merchant. Without it, the panel's own Filament tenant is used. */
    public function tenantUsing(?Closure $resolver): self
    {
        PanelTenant::resolveUsing($resolver);

        return $this;
    }

    /** Who an issue or a void is attributed to. Without it, the panel's authenticated user is used. */
    public function actorUsing(?Closure $resolver): self
    {
        PanelActor::resolveUsing($resolver);

        return $this;
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            DocumentResource::class,
            SeriesResource::class,
        ]);
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
