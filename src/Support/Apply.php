<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Filament\Support;

use Closure;
use Filament\Notifications\Notification;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\Outcome;
use Liberu\Ecommerce\InvoicesAndDocuments\Models\Document;
use Liberu\Ecommerce\InvoicesAndDocuments\Queries\FindDocument;

/**
 * How every write on this panel happens: re-read the document, let the domain
 * decide, and say which of the three things it decided.
 *
 * The re-read is the point. A control the panel hid is not a control — the
 * screen that offered the button and the document the domain guards are two
 * copies of the same fact, and only one of them is current. Visibility exists so
 * an operator is not offered a move the document cannot make; this exists
 * because asking anyway has to be refused rather than done.
 *
 * Nothing in this package writes through Eloquent. The host's panel edited and
 * deleted invoices directly and recorded none of it.
 */
final class Apply
{
    /**
     * The common case: three sentences, one per thing that can have happened.
     *
     * @param  Closure(Document, string): Outcome  $act  the domain action, given the document as it is now
     */
    public static function to(Document $document, string $done, string $did, string $already, Closure $act): void
    {
        self::then($document, $act, fn (Outcome $outcome) => self::report($outcome, $done, $did, $already));
    }

    /**
     * The same re-read, for the write whose answer is not three sentences: a
     * delivery, where a refusal can still have left a row behind.
     *
     * @param  Closure(Document, string): Outcome  $act
     * @param  Closure(Outcome): void  $say
     */
    public static function then(Document $document, Closure $act, Closure $say): void
    {
        $tenant = PanelTenant::current();
        $fresh = (new FindDocument())($tenant, $document->reference);

        if (! $fresh instanceof Document) {
            // One answer for a document that is somebody else's and one that is
            // not there. A panel is not a directory of the deployment.
            Notification::make()
                ->title('Nothing happened')
                ->body('No such document for this merchant.')
                ->color('danger')
                ->persistent()
                ->send();

            return;
        }

        $say($act($fresh, $tenant));
    }

    public static function report(Outcome $outcome, string $done, string $did, string $already): void
    {
        Notification::make()
            ->title(match (true) {
                $outcome->happened() => $done,
                $outcome->wasRefused() => 'Refused',
                default => 'Nothing changed',
            })
            ->body(Render::outcome($outcome, $did, $already))
            ->color(Render::outcomeColour($outcome))
            ->persistent()
            ->send();
    }
}
