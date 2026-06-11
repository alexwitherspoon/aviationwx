<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * NMS JSON payload parsing for location and geo queries.
 *
 * @covers ::notamExtractAixmRowsFromNmsResponse
 * @covers ::notamDecodeNmsJsonResponse
 */
final class NotamNmsResponseTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/notam/fetcher.php';
    }

    public function testExtract_EmptyDataObject_ReturnsEmptyList(): void
    {
        $rows = notamExtractAixmRowsFromNmsResponse([
            'status' => 'Success',
            'data' => [],
        ]);

        self::assertSame([], $rows);
    }

    public function testExtract_EmptyAixmArray_ReturnsEmptyList(): void
    {
        $rows = notamExtractAixmRowsFromNmsResponse([
            'status' => 'Success',
            'data' => ['aixm' => []],
        ]);

        self::assertSame([], $rows);
    }

    public function testExtract_AixmNull_ReturnsEmptyList(): void
    {
        $rows = notamExtractAixmRowsFromNmsResponse([
            'status' => 'Success',
            'data' => ['aixm' => null],
        ]);

        self::assertSame([], $rows);
    }

    public function testExtract_AixmRows_ReturnsRows(): void
    {
        $xml = '<AIXM xmlns="http://www.aixm.aero/schema/5.1.1"><hasMember/></AIXM>';
        $rows = notamExtractAixmRowsFromNmsResponse([
            'status' => 'Success',
            'data' => ['aixm' => [$xml]],
        ]);

        self::assertSame([$xml], $rows);
    }

    public function testExtract_NullInput_ReturnsNull(): void
    {
        self::assertNull(notamExtractAixmRowsFromNmsResponse(null));
    }

    public function testExtract_MissingDataKey_ReturnsNull(): void
    {
        self::assertNull(notamExtractAixmRowsFromNmsResponse(['status' => 'Success']));
    }

    public function testExtract_DataNotArray_ReturnsNull(): void
    {
        self::assertNull(notamExtractAixmRowsFromNmsResponse([
            'status' => 'Success',
            'data' => 'invalid',
        ]));
    }

    public function testExtract_AixmNotArray_ReturnsNull(): void
    {
        self::assertNull(notamExtractAixmRowsFromNmsResponse([
            'status' => 'Success',
            'data' => ['aixm' => 'not-an-array'],
        ]));
    }

    public function testDecode_ProductionEmptySuccessPayload_ParsesForExtract(): void
    {
        $decoded = notamDecodeNmsJsonResponse('{"status":"Success","data":{}}');

        self::assertIsArray($decoded);
        self::assertSame([], notamExtractAixmRowsFromNmsResponse($decoded));
    }
}
