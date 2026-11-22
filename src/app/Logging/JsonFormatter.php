<?php

namespace App\Logging;

use Monolog\Formatter\JsonFormatter as MonologJsonFormatter;
use Monolog\LogRecord;

/**
 * Единый JSON-формат логов приложения для Loki/Grafana.
 *
 * Все поля (контекст, extra, исключение) поднимаются на верхний уровень,
 * чтобы в Grafana работали простые запросы вида `| json | level="error"`.
 */
class JsonFormatter extends MonologJsonFormatter
{
    public function __construct()
    {
        parent::__construct(self::BATCH_MODE_NEWLINES, true, true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeRecord(LogRecord $record): array
    {
        $payload = [
            'timestamp' => $record->datetime->format(DATE_ATOM),
            'level' => strtolower($record->level->getName()),
            'level_name' => $record->level->getName(),
            'channel' => $record->channel,
            'message' => $record->message,
            'env' => $this->configValue('app.env'),
            'app' => $this->configValue('app.name'),
        ];

        foreach ([$record->context, $record->extra] as $data) {
            foreach ($data as $key => $value) {
                if (is_string($key)) {
                    $payload[$key] = $value;
                }
            }
        }

        $normalized = $this->normalize($payload);

        return is_array($normalized) ? $normalized : $payload;
    }

    private function configValue(string $key): ?string
    {
        try {
            $value = config($key);
        } catch (\Throwable) {
            return null;
        }

        return is_string($value) ? $value : null;
    }
}
