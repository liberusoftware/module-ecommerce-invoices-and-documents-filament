# Adopting the panel

## Install

```bash
composer require liberusoftware/ecommerce-invoices-and-documents-filament
```

The domain package is not on Packagist yet, so the host needs the same `repositories` entry this
package carries:

```json
{ "type": "vcs", "url": "https://github.com/liberusoftware/module-ecommerce-invoices-and-documents" }
```

Installing boots nothing. `extra.laravel.providers` is absent on purpose: the module manager
registers `InvoicesAndDocumentsFilamentServiceProvider` when `ecommerce-invoices-and-documents-filament`
is named in `MODULES_ENABLED`, and the domain module has to be enabled too.

## Attach it to a panel

The provider registers no screens. Every screen arrives through the plugin, so the host decides which
panels get the filing cabinet:

```php
use Filament\Facades\Filament;
use Liberu\Ecommerce\InvoicesAndDocuments\Filament\InvoicesAndDocumentsPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->plugin(
            InvoicesAndDocumentsPlugin::make()
                ->tenantUsing(fn (): string => (string) Filament::getTenant()?->getKey())
                ->actorUsing(fn (): ?string => Filament::auth()->id()),
        );
}
```

The manifest names the **`app`** panel, because that is where this host keeps a merchant's own
screens and where its invoice resource lived. Both host panels resolve `Team` tenancy, so either
would scope correctly; `app` is the merchant-facing one.

**`tenantUsing` is the merchant, not the store.** In this host's vocabulary that is the `Team`: a
`Channel` belongs to a `Store` and a `Store` to a `Team`, and the obligation to file documents is the
merchant's. Without a resolver the plugin falls back to `Filament::getTenant()`, and a panel with
neither has no merchant to be — every screen reports itself inaccessible rather than falling back to
showing everything. There is no "no tenant" case: `where('tenant_id', null)` compiles to `is null`,
which lists exactly the orphan rows a scope exists to hide.

**`actorUsing` is an identity, never an entitlement.** It is written onto the ledger row when a
document is issued or voided. Null is allowed and means "nobody was named", which the domain records
as a fact; an empty string would be a row claiming somebody did it and naming no one, so this package
turns one into the other. Standing is always the document's own merchant, answered by the domain's
`CustodyPolicy`.

## What the host still has to bind

The domain ships three seams and binds none of them. This panel works with all three unbound, and
says so on the screens where it matters.

| Seam | Unbound | What the panel does |
|---|---|---|
| `Contracts\SaleSource` | Nothing can be drafted | "Draft from a sale" refuses and names the reason |
| `Contracts\DocumentRenderer` | No file is produced | The record screen states that no renderer is configured; everything else still works |
| `Contracts\DocumentTransport` | Nothing is transmitted | "Send it" records the attempt and says the row exists and is undelivered |

Bind them in `config/invoices-and-documents.php` or in the container, as the domain package's
`docs/adoption.md` describes. Retention lives there too: `retention.years` unset is *unknown*, not
zero, and the panel renders it that way.

## What the host deletes, and what it does not

Nothing in this package replaces a host table. The host's `app/Filament/App/Resources/Invoices/`
tree — the resource, `ListInvoices`, `EditInvoice` — is what this package replaces, along with
`resources/views/invoices/index.blade.php` and `show.blade.php`. Deleting them is the host's move to
make once the module is enabled and the `invoices` table has been migrated into
`invoicing_documents`; the domain package's `docs/adoption.md` owns that migration, because the data
question is a domain question.

`app/Http/Livewire/InvoicePdf.php` and `app/Mail/InvoiceMail.php` are not adopted by anything. The
first is a Livewire component in a directory Livewire 4 does not discover, returning a view that does
not exist; the second names a view that does not exist and is constructed nowhere. They are dead
either way.
