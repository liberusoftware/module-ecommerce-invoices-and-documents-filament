<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Filament\Support;

use Illuminate\Support\Carbon;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\ContinuityReport;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\DocumentSummary;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\Money;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\Outcome;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\TaxRateTotal;
use Liberu\Ecommerce\InvoicesAndDocuments\Enums\DeliveryState;
use Liberu\Ecommerce\InvoicesAndDocuments\Enums\DocumentKind;
use Liberu\Ecommerce\InvoicesAndDocuments\Enums\DocumentState;
use Liberu\Ecommerce\InvoicesAndDocuments\Enums\EventKind;
use Liberu\Ecommerce\InvoicesAndDocuments\Enums\Recording;
use Liberu\Ecommerce\InvoicesAndDocuments\Enums\RefusalReason;
use Liberu\Ecommerce\InvoicesAndDocuments\Support\Seams;

/**
 * The sentences this panel is most likely to get wrong, written once.
 *
 * Nothing here invents a figure. The host printed a dollar sign over a column
 * with no currency on it and a header total its own lines had stopped matching;
 * every amount here carries the currency it was frozen with, and every absence
 * renders as the fact it is rather than as a nought.
 */
final class Render
{
    public const NONE = '—';

    /**
     * Why a write did not happen, in a sentence an operator can act on.
     *
     * A `match` with no default arm over every case the domain publishes: a new
     * refusal reason is a compile-time hole here rather than a blank in a
     * notification, and `RenderTest` walks `cases()` so the hole is found by the
     * suite rather than by a merchant.
     */
    public static function refusal(RefusalReason $reason): string
    {
        return match ($reason) {
            RefusalReason::SaleSourceUnbound => 'nothing is bound to read a sale, so there is nowhere to copy a document from — the panel will not invent lines',
            RefusalReason::SaleNotFound => 'the sale source does not know that reference for this merchant',
            RefusalReason::SaleHasNoLines => 'the sale has no lines, and a document with nothing on it is not a document',
            RefusalReason::MixedCurrencies => 'the amounts do not all share one currency and one exponent, and a document carries exactly one of each',
            RefusalReason::CreditNoteRequiresCorrectedDocument => 'a credit note is about another document, so it cannot be drafted from a sale on its own',
            RefusalReason::StatedTotalDisagreesWithLines => 'the sale states a total its own lines do not add up to, which is the header-versus-lines disagreement this module exists to refuse',
            RefusalReason::SeriesNotFound => 'this merchant has no numbering series under that code',
            RefusalReason::SeriesRequired => 'a fiscal document has to be filed under a series, and none was named',
            RefusalReason::ProformaMayNotUseFiscalSeries => 'a proforma may not take a number from a fiscal series, because it is not a document a tax authority will be shown',
            RefusalReason::SeriesIsGapless => 'this series is gapless, so it will not spend a number on nothing',
            RefusalReason::NotIssued => 'the document has not been issued, and this only applies to one that has',
            RefusalReason::IllegalTransition => 'the document cannot make that move from where it now stands',
            RefusalReason::NotCorrectable => 'a credit note is not itself correctable; a credit note about a credit note is not how this is unwound',
            RefusalReason::ExceedsCorrectedDocument => 'that would credit more than the document is worth, counting every credit note already issued against it',
            RefusalReason::NoRendererBound => 'no renderer is configured, so there is nothing to produce a file',
            RefusalReason::RendererDeclined => 'the configured renderer declined this document, which is not the same as there being no renderer',
            RefusalReason::NoTransportBound => 'nothing is bound to carry a document anywhere, so the attempt was recorded and never transmitted',
            RefusalReason::NoDeliveryAddress => 'there is no address to send it to, and the document carries no buyer email to fall back on',
            RefusalReason::TransportFailed => 'the transport tried and failed',
            RefusalReason::TransportSuppressed => 'the transport suppressed it, so it was deliberately not sent',
        };
    }

    /** What happened, for a caller that has an outcome and needs one sentence about it. */
    public static function outcome(Outcome $outcome, string $did, string $already): string
    {
        return match ($outcome->recording) {
            Recording::Recorded => $did,
            Recording::AlreadyRecorded => $already,
            Recording::Refused => 'Nothing was recorded, because '.self::because($outcome->reason).'.',
        };
    }

    /** Recorded, already recorded and refused must not share a colour. */
    public static function outcomeColour(Outcome $outcome): string
    {
        return match ($outcome->recording) {
            Recording::Recorded => 'success',
            Recording::AlreadyRecorded => 'gray',
            Recording::Refused => 'danger',
        };
    }

    /**
     * What became of a delivery.
     *
     * A refusal is not always "nothing was recorded": the domain writes the
     * attempt row before it transmits, so an unbound, failing or suppressing
     * transport leaves a row behind. An operator who is not told that will
     * simply ask again.
     */
    public static function delivery(Outcome $outcome): string
    {
        return match ($outcome->recording) {
            Recording::Recorded => 'The transport confirmed it, and the document now reads as delivered.',
            Recording::AlreadyRecorded => 'An attempt under that reference had already been made, and was not made a second time.',
            Recording::Refused => self::deliveryRefusal($outcome->reason),
        };
    }

    /** Delivered, refused, or an attempt that had already been made under that reference. */
    public static function deliveryTitle(Outcome $outcome): string
    {
        return match ($outcome->recording) {
            Recording::Recorded => 'Delivered',
            Recording::AlreadyRecorded => 'Nothing changed',
            Recording::Refused => 'Not delivered',
        };
    }

    private static function deliveryRefusal(?RefusalReason $reason): string
    {
        $recorded = in_array($reason, [
            RefusalReason::NoTransportBound,
            RefusalReason::TransportFailed,
            RefusalReason::TransportSuppressed,
        ], true);

        return ($recorded
            ? 'The attempt is on this document and undelivered, because '
            : 'No attempt was recorded, because ').self::because($reason).'.';
    }

    private static function because(?RefusalReason $reason): string
    {
        return $reason === null ? 'the write did not happen' : self::refusal($reason);
    }

    /** An amount and the currency it was frozen with, never one without the other. */
    public static function money(Money $money): string
    {
        return $money->decimal().' '.$money->currency;
    }

    /** A number, or the fact that this document has none — which for a proforma is correct rather than missing. */
    public static function number(?string $number): string
    {
        return $number ?? 'Not numbered';
    }

    /** Basis points as a percentage, by integer arithmetic: 2000 is 20%, 1750 is 17.5%. */
    public static function taxRate(int $basisPoints): string
    {
        $fraction = rtrim(str_pad((string) ($basisPoints % 100), 2, '0', STR_PAD_LEFT), '0');

        return intdiv($basisPoints, 100).($fraction === '' ? '' : '.'.$fraction).'%';
    }

    /**
     * The per-rate block a VAT invoice has to carry, one line per distinct rate.
     *
     * @return list<string>
     */
    public static function taxSummary(DocumentSummary $summary): array
    {
        return array_map(
            fn (TaxRateTotal $total): string => self::taxRate($total->rateBasisPoints)
                .' — net '.self::money($total->net)
                .', tax '.self::money($total->tax)
                .', gross '.self::money($total->gross),
            $summary->byRate,
        );
    }

    /** What a series has spent, and whether anything is unaccounted for. */
    public static function continuity(ContinuityReport $report): string
    {
        if ($report->first === null) {
            return 'Nothing has been spent from this series yet.';
        }

        $spent = 'Numbers '.$report->first.' to '.$report->last.', of which '
            .$report->issued.' are on documents and '.$report->burned.' were burned.';

        return $report->isContinuous()
            ? $spent.' Nothing is unaccounted for.'
            : $spent.' Unaccounted for: '.implode(', ', $report->missing)
                .'. Nothing in this module can have caused that, so it is an external event — see the runbook.';
    }

    public static function continuityColour(ContinuityReport $report): string
    {
        return $report->isContinuous() ? 'success' : 'danger';
    }

    /** Whether there is anything to produce a file at all. Unbound removes the artefact and nothing else. */
    public static function renderer(): string
    {
        return Seams::renderer() === null
            ? 'No renderer is configured, so this document has no file. It is still numbered, filed, listed and deliverable.'
            : 'A renderer is configured. A delivery attempt renders the document and hands the result to the transport.';
    }

    /**
     * How long this document has to survive a subject's erasure request.
     *
     * A window the host never configured is unknown, not zero. The host had no
     * such rule at all, so its erasure rewrote every historical invoice's buyer
     * by accident, in the direction the law does not allow.
     */
    public static function retention(?Carbon $issuedAt, ?Carbon $retainUntil): string
    {
        if (! $issuedAt instanceof Carbon) {
            return 'Not issued, so no retention window has started.';
        }

        return $retainUntil instanceof Carbon
            ? 'Kept until '.$retainUntil->toFormattedDateString().'. Erasure refuses to redact the buyer before then.'
            : 'Unknown. No retention window is configured, so erasure refuses to redact the buyer rather than reading an unset window as none.';
    }

    public static function kindLabel(DocumentKind $kind): string
    {
        return match ($kind) {
            DocumentKind::Invoice => 'Invoice',
            DocumentKind::CreditNote => 'Credit note',
            DocumentKind::Receipt => 'Receipt',
            DocumentKind::Proforma => 'Proforma',
        };
    }

    public static function kindColour(DocumentKind $kind): string
    {
        return match ($kind) {
            DocumentKind::Invoice => 'primary',
            DocumentKind::CreditNote => 'warning',
            DocumentKind::Receipt => 'info',
            DocumentKind::Proforma => 'gray',
        };
    }

    public static function stateLabel(DocumentState $state): string
    {
        return match ($state) {
            DocumentState::Draft => 'Draft',
            DocumentState::Issued => 'Issued',
            DocumentState::Delivered => 'Delivered',
            DocumentState::Void => 'Void',
        };
    }

    public static function stateColour(DocumentState $state): string
    {
        return match ($state) {
            DocumentState::Draft => 'gray',
            DocumentState::Issued => 'primary',
            DocumentState::Delivered => 'success',
            // Void records rather than erases, so it is a state and not an
            // absence. The number it was filed under stays spent.
            DocumentState::Void => 'danger',
        };
    }

    /** A state as the event ledger spells it, which is a plain column rather than a cast. */
    public static function stateName(?string $value): string
    {
        $state = $value === null ? null : DocumentState::tryFrom($value);

        return $state instanceof DocumentState ? self::stateLabel($state) : self::NONE;
    }

    public static function deliveryLabel(DeliveryState $state): string
    {
        return match ($state) {
            DeliveryState::Pending => 'Attempted, no answer',
            DeliveryState::Sent => 'Sent',
            DeliveryState::Failed => 'Failed',
            DeliveryState::Suppressed => 'Suppressed',
        };
    }

    public static function deliveryColour(DeliveryState $state): string
    {
        return match ($state) {
            DeliveryState::Pending => 'warning',
            DeliveryState::Sent => 'success',
            DeliveryState::Failed => 'danger',
            DeliveryState::Suppressed => 'gray',
        };
    }

    public static function eventLabel(EventKind $kind): string
    {
        return match ($kind) {
            EventKind::Drafted => 'Drafted',
            EventKind::Issued => 'Issued and numbered',
            EventKind::Delivered => 'Delivered',
            EventKind::DeliveryFailed => 'Delivery did not arrive',
            EventKind::Voided => 'Voided',
            EventKind::Redacted => 'Buyer redacted',
        };
    }

    /** @param  array<string, mixed>|null  $detail */
    public static function detail(?array $detail): string
    {
        if ($detail === null || $detail === []) {
            return self::NONE;
        }

        $parts = [];

        foreach ($detail as $key => $value) {
            $parts[] = $key.': '.(is_scalar($value) ? (string) $value : self::NONE);
        }

        return implode(', ', $parts);
    }
}
