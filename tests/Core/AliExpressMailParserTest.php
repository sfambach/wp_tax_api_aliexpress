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
}
