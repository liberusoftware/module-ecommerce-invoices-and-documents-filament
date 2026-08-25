# The panel, and why it is shaped this way

Two resources and no pages. Everything below is a decision, including the ones that were rejected.

## 1. The shape is dictated by fault 16

The host's `InvoiceResource` shipped, on a financial document: a `CreateAction` on its list page, an
`EditAction` and a `DeleteBulkAction` on its table, a `DeleteAction` on its edit page, and a
free-text `total_amount` field on its form. Nothing wrote an audit row for any of it. The deletes
were unrecoverable, because `invoices` has no soft deletes while `InvoicePolicy` publishes `restore`
and `forceDelete` as if it did — a restore that cannot restore anything.

So the whole panel is the negative of that:

| The host offered | This panel offers | Why |
|---|---|---|
| Create an invoice by typing one | Draft one from a sale reference | The sale is read once, through a seam, and everything the document displays is copied then |
| Edit an invoice | Nothing | An issued document is immutable in the domain; the model raises rather than saving |
| Delete an invoice | Void it | Void records the reason, the actor and the state it came from, and keeps the number |
| Bulk delete invoices | Nothing at all on the toolbar | There is nothing here to select several of and act on |
| A free-text total | A total summed from the frozen lines on read | The host's header total came from `orders.total_amount` while only product lines were copied, so it disagreed with its own lines from the day it was written |

`AuthorizationTest` asks each closed ability by name, because a missing policy method is permissive:
Filament falls through to `allow()` for anything nobody answered. `ModuleBoundaryRulesTest` greps the
source for the control classes themselves, so a `DeleteAction` cannot arrive on a screen somebody adds
later.

## 2. Every write goes through a domain action, and re-reads first

Nothing in `src/` calls `save`, `update`, `delete`, `forceFill` or `create`. `Support\Apply` is the
single path: it takes the document the screen was drawn from, re-reads it through `Queries\FindDocument`
under the panel's merchant, and hands *that* to the domain action.

The re-read is the guard. A control the panel hid is not a control — the screen and the row are two
copies of one fact and only one of them is current. Visibility exists so an operator is not offered a
move the document cannot make; the re-read exists so that acting on it is decided by the domain.

**What the re-read cannot be tested against, and why.** A Filament resource page re-resolves its
record from the route key on every request, so a document another session moved is already the moved
document by the time an action is evaluated — the control is simply gone. The re-read's own refusal
path is therefore exercised directly, in `CustodyTest`, by handing `Apply::then` a document belonging
to another merchant: the action closure is never reached and the answer is the same sentence a
document that does not exist gets.

## 3. Refusals

The domain publishes twenty refusal reasons and `Support\Render::refusal()` has a sentence for each,
in a `match` with no default arm. A twenty-first reason is a compile-time hole rather than a blank in
a notification, and `RenderTest` walks `cases()` so the hole is found by the suite.

Nine of the twenty are reachable by driving the panel, and are tested that way: the five ways a draft
can be refused, and the four ways a delivery can be. The rest are unreachable because the control is
not offered in the state that would produce them, or because Filament's own validation refuses
first — a series code that is not this merchant's is rejected by the select, not by the domain,
because the select is searched over the tenant-scoped query and Filament validates the submitted
value against it.

**A refused delivery is not "nothing was recorded".** The domain writes the attempt row before it
transmits, so an unbound transport, a failure and a suppression each leave a row behind, while a
missing address and an unissued document do not. `Render::delivery()` says which, because an operator
told nothing was recorded will simply ask again.

## 4. Crediting in full, and the partial credit note that is not here

`Actions\DraftCreditNote` takes `list<Line>` — description, quantity, unit net, net, rate, tax and
gross, all already computed. There are only three ways a panel can produce those:

1. **Ask an operator to type them.** That is the host's free-text total wearing a repeater.
2. **Compute them.** That is the tax module's job, and a presentation package that computes money is
   a second implementation of the rules.
3. **Copy them.** `Queries\BuildRenderModel` returns the document's own frozen lines. Copying is not
   computing.

So the panel credits in full, and only in full. The credit note's `sourceRef` is the corrected
document's reference, which is a natural key: pressing the button twice cannot raise two. In practice
it does not even reach the index — the domain counts every credit note already raised against the
document, drafts included, and refuses the second by arithmetic.

A partial credit note is a real requirement and it belongs in the domain: an action taking line
positions and quantities, doing the proportional arithmetic where the rest of the arithmetic lives.
It is reported as a gap, not implemented here.

## 5. Numbering

A second resource, because a series is not a document and the merchant has to be able to see what
each one has spent. Opening a series is `Actions\OpenSeries` behind a header action rather than a
Filament create page, for the reason in §2.

There is no edit form on a series either. Its prefix, its padding and its two policies are what
already-issued documents were filed under; changing them would rewrite how existing numbers read.

**Continuity is reconciled on read and shown per row.** `Queries\CheckSeriesContinuity` runs once per
series on the list. That is one query per row, bounded by the number of series a merchant runs by
hand — the same trade the reference packages make for a per-row derived figure. If a merchant ever
runs enough series for that to matter, a batched continuity belongs in the domain rather than a cache
here.

**A gapless series is not offered the burn control.** It would refuse, and a control that always
refuses is a control that should not be drawn. The refusal sentence still exists and is tested,
because the API package can reach it.

**A number nothing accounts for is an alarm, not a cleanup.** Nothing in the domain can leave a hole:
the number is spent inside the transaction that writes the document, so a rollback returns it. A gap
is therefore an external event, and the screen says so.

## 6. The series select

One select in the package, and it is the answer to fault 17. It is searched as you type over this
merchant's series, bounded to fifty results, and never evaluated at form-build time. Filament
validates the submitted value against the same search, so a code belonging to another merchant is
refused by the form rather than by the domain — the tenancy is in the search, which is what makes the
validation tenant-aware for free.

## 7. What is not here, and why

- **A numbered proforma.** The domain will number one from a non-fiscal series; offering that means a
  second series select filtered the other way on the same screen. A proforma issues unnumbered
  instead, which the domain treats as a document either way. If merchants ask for numbered proformas,
  the select gains a fiscality filter and the domain needs nothing.
- **A download.** Rendering is an unbound seam; the domain ships no renderer and a domain package must
  not carry a PDF library. Streaming bytes out of a panel would also mean reaching for
  `laravel/framework`'s response helpers, which this package does not depend on. The record screen
  states whether a renderer is configured, and the file reaches a person through the transport.
- **A per-attempt idempotency key on the send form.** The domain's delivery `reference` is an
  idempotency key for a machine caller retrying a request. A person pressing Send twice means it
  twice, so the panel mints a fresh reference per submission and says so here rather than pretending
  a resend is a mistake.
- **Erasure and subject-access screens.** `Actions\ForgetParticipant` and
  `Queries\ExportParticipantRecord` walk one person across every merchant on the deployment. Putting
  either behind a merchant's panel would hand one merchant a view of another's documents. They are
  host-wide paths and they stay there.
- **A widget.** Every figure a summary widget would show is already on the two listings. A dashboard
  tile that restates a column is a second place for the same number to be wrong.

## 8. Gaps found in the domain package

Reported rather than closed here, per the presentation brief:

1. **`DraftCreditNote` has no partial form.** §4.
2. **`Document` has no `series()` relation.** `Series::documents()` exists; the inverse does not, so a
   document cannot name the series it was filed under without a second query. The panel shows the
   number, which carries the series prefix, and does not show the code.
3. **`CustodyPolicy` answers about a document and about nothing else.** There is no `ownsSeries`, so
   `SeriesResource::canView()` states the tenant comparison itself. It is the same rule the
   tenant-scoped query already applies, and it is the one place in this package where a custody
   decision is not the domain's answer.
4. **`Queries\ListDocuments` returns a materialised `Collection`.** A Filament table needs a builder to
   sort, filter and page over, so the listing uses `getEloquentQuery()` with the tenant restriction
   instead. The query is usable by the API and Livewire packages and not by this one.
5. **`DraftCreditNote` counts draft credit notes against the corrected document's remaining value.**
   That is the conservative choice and it is probably right, but it means a draft credit note that is
   later voided still has to be voided before another can be raised. It is stated here because it is
   not written down in the domain's docs.
