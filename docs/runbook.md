# Runbook

What breaks on these screens, what it looks like, and what to do.

## "Invoicing could not resolve a tenant for this panel"

Every screen is inaccessible and the navigation entries are gone.

The plugin has no `tenantUsing` resolver and the panel has no Filament tenant, or the resolver
returned null or an empty string. There is deliberately no fallback: a panel that showed everything
because it could not name a merchant would be worse than one that showed nothing, and
`where('tenant_id', null)` compiles to `is null`, which lists exactly the orphan rows a scope exists
to hide.

Fix it in the panel provider — `docs/adoption.md`.

## "Draft from a sale" always refuses

**"nothing is bound to read a sale"** — the host has bound no `Contracts\SaleSource`. Nothing can be
drafted until it does. The panel will not invent lines.

**"the sale source does not know that reference for this merchant"** — the binding exists and
answered null. Either the reference is wrong or the sale belongs to another merchant. The two are the
same answer on purpose.

**"states a total its own lines do not add up to"** — the sale source is computing its own totals
inconsistently. This is the guard the host never had: its invoice header came from
`orders.total_amount`, gross with shipping and discount, while only product lines were copied. Fix
the source; do not work around it by adjusting a line.

## A document says "No renderer is configured"

Expected, and not a fault. The domain binds no `Contracts\DocumentRenderer`, and unbound removes
exactly the artefact: the document is still numbered, filed, listed and deliverable. Bind one if the
merchant needs a file.

## "The attempt is on this document and undelivered"

The row exists. Do not press Send again expecting a different row — look at the Delivery tab, where
the attempt is, with what the transport said.

- **"nothing is bound to carry a document anywhere"** — no `Contracts\DocumentTransport`. Every
  attempt will read this way until one is bound.
- **"the transport tried and failed"** — the transport's own message is in the Detail column.
- **"deliberately not sent"** — the transport suppressed it. That is a decision the transport made,
  usually a suppression list, and re-sending is not the fix.

An attempt with **no answer at all** — Attempted with nothing under Answered — means the transport
never returned. The row is the evidence that something was tried; nothing in this module will settle
it retrospectively.

## A retention window reads "Unknown"

`invoices-and-documents.retention.years` is not configured. This is not zero and the module will not
guess: erasure refuses to redact the buyer on a document whose window is unknown, which is the safe
direction for a statutory record. The host redacting a customer in place is what made every
historical invoice resolve to a redacted identity, with no record of what was redacted and no rule
that could have refused.

Set the retention the merchant files under. Documents issued before it is set keep a null window
until they are reissued, which they will not be — a document issued under an unknown window stays
protected by that unknown.

## A series shows "Unaccounted for"

**This is the alarm that cannot wait, and it is not a cleanup.**

Nothing in the domain can leave a hole in a series. A number is spent under a row lock inside the
transaction that writes the document, so a rollback returns it, and a burn writes a row that accounts
for the number it spent. A gap therefore means one of:

- rows were deleted outside the module — `invoicing_documents` or `invoicing_burned_numbers`;
- a database was restored to a point that dropped a document while keeping the series' `next_value`;
- a migration or a script moved data in.

Find the missing numbers, which the screen names, and reconstruct what they were spent on from
whatever holds it — the sale source, the transport's logs, the paper. Nothing in this module can fill
the hole in, and nothing in it should: a series that could be back-filled through a panel is not a
gapless series.

If the series is not gapless, a gap is still worth explaining but is not a compliance event.

## "Refused: that would credit more than the document is worth"

A credit note for this document already exists. It counts even while it is still a draft. Look at the
Credit notes tab: if the existing one is wrong, void it first, then credit again.

## A control is missing from a document

Every control is drawn from the state the document is in:

| State | Issue | Send | Credit | Void |
|---|---|---|---|---|
| Draft | yes | no | no | yes, labelled "Discard it" |
| Issued | no | yes | yes, unless it is a credit note | yes |
| Delivered | no | yes | yes, unless it is a credit note | yes |
| Void | no | no | no | no |

Nothing is missing because of a permission. If a control is absent, the document cannot make that
move, and the domain would refuse it if it were pressed.
