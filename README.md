# ecommerce-invoices-and-documents-filament

The filing cabinet. A Filament panel over
[`liberusoftware/ecommerce-invoices-and-documents`](https://github.com/liberusoftware/module-ecommerce-invoices-and-documents):
the documents a sale produced, the lines each one froze, the series they are numbered from, the
credit notes that correct them and every attempt to put one in front of somebody.

It is a one-to-one adapter. It contains no business rules: every decision is a published domain
action, query or policy, and every figure is one the domain sums on read.

## The three faults it is shaped around

**A financial document had an edit form, a delete action and a bulk delete.** The host's
`InvoiceResource` shipped `EditAction` and `DeleteBulkAction`, its list page shipped `CreateAction`,
its edit page shipped `DeleteAction`, and its form's total was a free-text field. Nothing wrote an
audit row for any of it, and the deletes were unrecoverable — `invoices` has no soft deletes while
`InvoicePolicy` publishes `restore` and `forceDelete` as if it did. So here there is no create page,
no edit page and no delete control anywhere; a document is corrected by raising a credit note and
discarded by being voided, and both write a row on the ledger. That is a test, not a convention.

**Two form selects loaded whole tables.** `Customer::pluck(...)` and `Order::pluck(...)`, both
evaluated at form-build time, both deployment-wide. The one select here is searched as you type over
this merchant's own series and bounded to fifty results — and because the search is what validates
it, another merchant's series code is not merely hidden, it is refused.

**The invoice had no number, so the panel printed the primary key.** What a customer saw under
"Invoice #" was the `invoices` auto-increment key, shared across every merchant on the deployment.
Every screen here is keyed on the reference this module mints, and the number a document is filed
under comes from a series the merchant opened.

## What it publishes

| | |
|---|---|
| `InvoicesAndDocumentsPlugin` | The entry point. The host attaches it to the panels it means to. |
| **Documents** | Every document, its frozen lines, its per-rate tax block, its deliveries, its credit notes and its history. Draft from a sale, issue, send, credit in full, void. |
| **Numbering** | The series this merchant files under, what each has spent, and whether anything is unaccounted for. Open one, burn a number where the series allows it. |

## What it does not do

- **No figure is ever a zero it did not measure.** A document with no number reads as not numbered, a
  retention window nobody configured reads as unknown, and a panel with no renderer says so rather
  than offering an empty file.
- **No partial credit note.** Crediting part of a document is arithmetic, and arithmetic belongs to
  the domain. The panel copies the whole of a document's frozen lines onto a credit note; a partial
  one needs a domain action that does not exist yet — see [`docs/panel.md`](docs/panel.md).
- **No numbered proforma.** A proforma may not be filed under a fiscal series, so this panel issues
  one unnumbered rather than offering a second class of series to choose between.
- **No erasure and no export screen.** `ForgetParticipant` and `ExportParticipantRecord` walk one
  person across every merchant on the deployment. A merchant panel is the wrong place for either.
- **No renderer and no PDF.** Rendering is an unbound seam. This panel names the fact and delivers
  through whatever the host bound.
- **No create, no edit, no delete, anywhere.**

## Installing

```bash
composer require liberusoftware/ecommerce-invoices-and-documents-filament
```

Nothing boots on install: the module manager registers the provider when the module is named in
`MODULES_ENABLED`. Attaching the panel is one call — see [`docs/adoption.md`](docs/adoption.md).

Why every screen is shaped the way it is, including the shapes that were rejected, is in
[`docs/panel.md`](docs/panel.md). What breaks and what to do about it is in
[`docs/runbook.md`](docs/runbook.md).
