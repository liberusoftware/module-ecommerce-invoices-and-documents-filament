# Changelog

## 0.1.0

The first release. A Filament panel over `liberusoftware/ecommerce-invoices-and-documents` `0.1.0`,
built as the opposite of the host's `InvoiceResource`.

### The panel

- **Documents.** A listing keyed on the module's own reference, showing the allocated number, the
  kind, the state, the buyer's name, the total summed from the document's own frozen lines and the
  line count read through the document's own relation. The buyer's email is on the record screen and
  on no listing.
- **The record screen.** What the document says, the money net/tax/gross with a block per distinct
  tax rate, both identities as they were at issue, the retention window, whether a renderer is
  configured, and four relation managers: the frozen lines, the delivery attempts, the credit notes
  raised against it and its full history.
- **Numbering.** The series this merchant files under, with what each has spent counted through its
  own relations and reconciled on read. A number nothing accounts for is named as an external event,
  because nothing in the domain can leave one.

### What is refused rather than offered

- No create page, no edit page and no delete control on either resource, asserted by name in
  `AuthorizationTest` and by a grep for the control classes in `ModuleBoundaryRulesTest`.
- Every relation-manager ability is closed by name, `canAssociate` and `canDissociate` included: an
  association control reassigns a row's owner without an edit form.
- Nothing in `src/` calls `save`, `update`, `delete`, `forceFill` or `create` on a model. Every write
  is a published domain action, and every one of them re-reads the document through `FindDocument`
  first, so the domain decides against the row rather than against the screen.
- Two `where` clauses in the package: each resource's tenant restriction, which is Filament's
  contract, and the series search. The suite fails if a third arrives.

### Deliberately not shipped

- **A partial credit note.** `DraftCreditNote` takes fully computed lines — net, rate, tax and gross
  per line. A panel that let an operator type those would be the host's free-text total again, and a
  panel that computed them would be doing the tax module's job. Crediting in full copies the frozen
  lines through `BuildRenderModel` and computes nothing. A partial credit needs a domain action that
  takes line selections; it is recorded as a gap rather than implemented here.
- **A numbered proforma.** The domain will number one from a non-fiscal series. Offering that would
  mean a second, filtered series select on one screen; the panel issues a proforma unnumbered, which
  the domain treats as a document either way.
- **A download.** Rendering is an unbound seam and the domain ships no renderer. The record screen
  states whether one is configured; the file reaches a person through the transport.
- **Erasure and subject-access screens.** Both domain paths walk one person across every merchant on
  the deployment. Neither belongs on a merchant's panel.
