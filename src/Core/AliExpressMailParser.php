<?php declare(strict_types=1);

namespace TaxApiAliexpress\Core;

use Taxmod\Core\Import\Mail\MailMessage;
use Taxmod\Core\Import\Mail\LineCollector;
use Taxmod\Core\Import\Mail\OrderMailParser;
use Taxmod\Core\Import\Mail\Text;
use Taxmod\Core\Import\OrderProposal;
use Taxmod\Core\Import\ProposalKind;

/**
 * AliExpress: mails about one order number — «Bestellauftrag bestätigt» / «auf Bestätigung wird gewartet» (title, quantity, unit price,
 * order sum), «versandt» and «zugestellt» (status, tracking number if the mail names one); «Packstück <nr>: beim Zoll» /
 * «mit lokalem Kurier» name only the parcel number and are read as «versandt» without an order number (D-1017).
 * Moved here from Taxmod Mail (D-1006). Port of legacy/kaeufe-import/kaeufe_import/shops/aliexpress.py.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/import/module-schnitt.md
 */
final class AliExpressMailParser implements OrderMailParser
{
    public function __construct(private readonly string $version)
    {
    }

    public function key(): string
    {
        return 'aliexpress';
    }

    public function shopName(): string
    {
        return 'AliExpress';
    }

    public function recognises(MailMessage $mail): bool
    {
        return str_contains($mail->senderDomain(), 'aliexpress');
    }

    public function parse(MailMessage $mail): OrderProposal
    {
        $lines = $mail->lines();

        // Since 2026-10 the parcel mails come in English as well: «Package 0034… has cleared customs».
        // The first parcel number can be the shipper's own («AP0010…»), before the carrier's.
        if (preg_match('/^(?:Packst(?:ü|ue)ck|Package) ([A-Z]{0,4}\d{10,30})/u', $mail->subject, $parcel) === 1) {
            return $this->parcel($mail, $parcel[1]);
        }

        if (preg_match('/(?:Bestellung|Order) (\d{15,20})/', $mail->subject . ' ' . implode(' ', array_slice($lines, 0, 20)), $match) !== 1) {
            return new OrderProposal($this->shopName(), '', ProposalKind::NoReceipt);
        }

        $subject = mb_strtolower($mail->subject);

        // «Wie war Ihr Einkaufserlebnis?» asks for a review; read as a status, it proposed the order again, dated that day.
        if (str_contains($subject, 'einkaufserlebnis')) {
            return new OrderProposal($this->shopName(), '', ProposalKind::NoReceipt);
        }

        $total = Text::after($lines, 'Bestellsumme');
        $sum = $total === null ? null : Text::money($total)[0];
        $items = self::items($lines, $sum, self::pictures($mail->html));
        // «Bestellauftrag bestätigt» is the order confirmation; «auf Bestätigung wird gewartet» only says it is on its way.
        // English since 2026-10: «Order 3077…: collected by the carrier».
        $delivered = str_contains($subject, 'delivered') && ! str_contains($subject, 'out for delivery');
        $kind = match (true) {
            str_contains($subject, 'zugestellt') || str_contains($subject, 'geliefert') || $delivered => ProposalKind::Delivered,
            str_contains($subject, 'versandt') || str_contains($subject, 'versendet') || str_contains($subject, 'carrier')
                || str_contains($subject, 'shipped') || str_contains($subject, 'in transit') || str_contains($subject, 'out for delivery') => ProposalKind::Shipped,
            str_contains($subject, 'bestätigt') => ProposalKind::Ordered,
            default => ProposalKind::Other,
        };
        // A status mail is dated by when it was sent, not when the order was placed; giving that date as the order date
        // made every shipping notice propose a wrong change.
        $orderDate = $kind === ProposalKind::Ordered || $kind === ProposalKind::Other ? $mail->date?->format('Y-m-d') : null;
        $tracking = preg_match('/(?:Sendungsnummer|Tracking-?Nummer|Tracking number)\s*:?\s*([A-Z0-9]{8,})/i', $mail->text, $found) === 1
            ? [$found[1]] : [];

        return new OrderProposal($this->shopName(), $match[1], $kind, $orderDate, 'EUR', $items->lines(),
            null, Text::decimal($sum),
            shippedDate: $kind === ProposalKind::Shipped ? $mail->date?->format('Y-m-d') : null, trackingNumbers: $tracking);
    }

    /**
     * A parcel mail names the parcel, not the order: the modeller finds the order by the tracking number (D-1017).
     */
    private function parcel(MailMessage $mail, string $number): OrderProposal
    {
        $subject = mb_strtolower($mail->subject);
        $delivered = str_contains($subject, 'zugestellt') || (str_contains($subject, 'delivered') && ! str_contains($subject, 'out for delivery'));
        $kind = $delivered ? ProposalKind::Delivered : ProposalKind::Shipped;

        return new OrderProposal($this->shopName(), 'packstueck:' . $number, $kind, null, 'EUR', self::items($mail->lines(), null, self::pictures($mail->html))->lines(),
            shippedDate: $kind === ProposalKind::Shipped ? $mail->date?->format('Y-m-d') : null, trackingNumbers: [$number], numberMissing: true);
    }

    /**
     * The mail names a price per item only now and then; an order of one item names it through the order sum.
     * A picture goes to its item only when the mail shows one per item, in the same order (D-1079).
     *
     * @param list<string> $lines
     * @param list<string> $pictures
     */
    private static function items(array $lines, ?float $sum = null, array $pictures = []): LineCollector
    {
        $items = new LineCollector();
        $found = [];

        foreach ($lines as $k => $line) {
            if ($k > 1 && preg_match('/^x(\d+)$/', $line, $quantity) === 1) {
                // Before «xN» stands the title, or a price or the chosen variant under a title cut short with «...».
                $priced = preg_match('/^(?:US )?[$€£]?\s*\d[\d.,]*\s*(?:€|EUR)?$/u', $lines[$k - 1]) === 1;
                $truncated = str_ends_with($lines[$k - 2], '...') || str_ends_with($lines[$k - 2], '…');
                $title = $priced || $truncated ? $lines[$k - 2] : $lines[$k - 1];
                // Only a euro price: the order is in euro, a dollar figure beside it is the shop's list price.
                [$amount, $currency] = $priced ? Text::money($lines[$k - 1]) : [null, null];
                $found[] = [$title, (float) $quantity[1], $currency === 'EUR' ? $amount : null];
            }
        }

        if (count($found) === 1 && $found[0][2] === null && $sum !== null && $found[0][1] > 0) {
            $found[0][2] = $sum / $found[0][1];
        }

        $pictures = count($pictures) === count($found) ? $pictures : [];

        foreach ($found as $index => [$title, $count, $price]) {
            $items->add($title, $count, $price, null, $pictures[$index] ?? null);
        }

        return $items;
    }

    /**
     * The item pictures: the shop's product image at 100 × 100 px. Icons and the «Das könnte Ihnen auch gefallen» tiles
     * come in other sizes.
     *
     * @return list<string>
     */
    private static function pictures(string $html): array
    {
        preg_match_all('/<img\b[^>]*>/i', $html, $tags);
        $found = [];

        foreach ($tags[0] as $tag) {
            if (preg_match('/\bsrc="(https:\/\/[^"]+\.(?:jpe?g|png|webp))"/i', $tag, $src) === 1
                && preg_match('/width:\s*100px;\s*height:\s*100px/i', $tag) === 1) {
                $found[] = html_entity_decode($src[1], ENT_QUOTES);
            }
        }

        return $found;
    }

    public function kinds(): array
    {
        return [ProposalKind::Ordered, ProposalKind::Shipped, ProposalKind::Delivered];
    }

    public function version(): string
    {
        return $this->version;
    }
}
