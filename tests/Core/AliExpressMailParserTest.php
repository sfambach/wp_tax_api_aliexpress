<?php declare(strict_types=1);

namespace TaxApiAliexpress\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxApiAliexpress\Core\AliExpressMailParser;
use Taxmod\Core\Import\Mail\MimeParser;

/**
 * Each expectation is what the Python importer (legacy/ in Taxmod Mail) read from the same synthetic mail.
 */
final class AliExpressMailParserTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function read(string $fixture): array
    {
        $parser = new AliExpressMailParser('0.0.1');
        $mail = (new MimeParser())->parse(1, (string) file_get_contents(__DIR__ . '/fixtures/' . $fixture . '.eml'));
        self::assertTrue($parser->recognises($mail), 'not recognised: ' . $fixture);

        return $parser->parse($mail)->toArray();
    }

    public function testAliExpressAwaitingConfirmation(): void
    {
        $order = self::read('a1');

        self::assertSame(['8123456789012345678', '12.34'], [$order['order_number'], $order['total']]);
        self::assertSame(['ESP32 DevKit', 'USB-C Kabel'], array_column($order['lines'], 'title'));
        self::assertSame([3.0, 2.0], array_column($order['lines'], 'quantity'));
    }

    public function testAliExpressShippedCarriesNoOrderDate(): void
    {
        $order = self::read('a2');

        self::assertSame(['8123456789012345678', 'shipped', null, '2024-10-21'], [$order['order_number'], $order['kind'], $order['order_date'], $order['shipped_date']]);
        self::assertSame(['LP00123456789012'], $order['tracking']);
    }

    public function testItNamesWhatItReads(): void
    {
        self::assertNotSame([], (new AliExpressMailParser('1.2.3'))->kinds());
        self::assertSame('1.2.3', (new AliExpressMailParser('1.2.3'))->version());
    }

    public function testParcelMailNamesOnlyItsTrackingNumber(): void
    {
        $order = self::read('a3');

        self::assertSame(['shipped', true, ['00340400000000000001']], [$order['kind'], $order['number_missing'], $order['tracking']]);
        self::assertSame(['(5-20 Stück) AT32F415RCT7 LQFP-64(10...'], array_column($order['lines'], 'title'));
    }

    public function testATitleCutShortKeepsItsPlaceBeforeTheVariant(): void
    {
        $order = self::read('a4');

        self::assertSame(['3000000000000001', 'shipped', '50.57'], [$order['order_number'], $order['kind'], $order['total']]);
        self::assertSame(['1-10pcs D1 MINI ESP32 WiFi+Bl...'], array_column($order['lines'], 'title'));
    }

    public function testAReviewRequestIsNoOrderMail(): void
    {
        self::assertSame(['no_receipt', ''], [self::read('a5')['kind'], self::read('a5')['order_number']]);
    }

    public function testAnEnglishParcelMailIsReadLikeAGermanOne(): void
    {
        $parcel = self::read('a6');

        self::assertSame(['shipped', true, ['00340400000000000002'], '2026-10-25'], [$parcel['kind'], $parcel['number_missing'], $parcel['tracking'], $parcel['shipped_date']]);
        self::assertSame(['ESP8266 ESP32 Development Board Test...', 'ESP-32S ESP-WROOM-32 ESP-WROOM-32D ...'], array_column($parcel['lines'], 'title'));
    }

    public function testAnOrderOfOneItemPricesItThroughTheOrderSum(): void
    {
        $order = self::read('a7');

        self::assertSame(['ordered', '4.4'], [$order['kind'], $order['total']]);
        self::assertSame(['100Pcs JST XH2.54 2/3/4/5 Pin...'], array_column($order['lines'], 'title'));
        self::assertSame(['2.2'], array_column($order['lines'], 'unit_price'));
    }

    public function testAEuroPriceBeforeTheQuantityIsTheUnitPrice(): void
    {
        $order = self::read('a8');

        self::assertSame(['ESP32 DevKit', 'USB-C Kabel'], array_column($order['lines'], 'title'));
        self::assertSame(['3.15', null], array_column($order['lines'], 'unit_price'));
    }

    public function testADollarPriceIsNoUnitPriceOfAEuroOrder(): void
    {
        self::assertSame([null, null], array_column(self::read('a1')['lines'], 'unit_price'));
    }
}
