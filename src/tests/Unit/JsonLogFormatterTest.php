<?php

namespace Tests\Unit;

use App\Logging\JsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

class JsonLogFormatterTest extends TestCase
{
    public function test_it_emits_flat_json_with_request_context(): void
    {
        $formatter = new JsonFormatter;

        $record = new LogRecord(
            new \DateTimeImmutable('2026-11-14T20:00:00+00:00'),
            'vspace',
            Level::Warning,
            'http_request',
            [
                'request_id' => 'req-1',
                'status' => 404,
                'duration_ms' => 12.5,
            ],
            [
                'user_id' => 'user-1',
            ],
        );

        $data = json_decode(trim($formatter->format($record)), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('2026-11-14T20:00:00+00:00', $data['timestamp']);
        self::assertSame('warning', $data['level']);
        self::assertSame('WARNING', $data['level_name']);
        self::assertSame('vspace', $data['channel']);
        self::assertSame('http_request', $data['message']);
        self::assertSame('req-1', $data['request_id']);
        self::assertSame(404, $data['status']);
        self::assertSame(12.5, $data['duration_ms']);
        self::assertSame('user-1', $data['user_id']);
        self::assertArrayNotHasKey('context', $data);
        self::assertArrayNotHasKey('extra', $data);
    }

    public function test_it_flattens_exception_details(): void
    {
        $formatter = new JsonFormatter;

        $record = new LogRecord(
            new \DateTimeImmutable('2026-11-14T20:00:00+00:00'),
            'vspace',
            Level::Error,
            'Ошибка',
            [
                'exception' => new \RuntimeException('boom'),
            ],
        );

        $data = json_decode(trim($formatter->format($record)), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('error', $data['level']);
        self::assertSame(\RuntimeException::class, $data['exception']['class']);
        self::assertSame('boom', $data['exception']['message']);
    }
}
