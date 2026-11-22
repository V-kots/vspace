<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Пишет в лог завершённый HTTP-запрос.
 *
 * Уровень зависит от статуса и метода: 5xx — error, 4xx — warning,
 * успешные изменяющие запросы (POST/PUT/PATCH/DELETE) — info,
 * остальные — debug. При LOG_LEVEL=info (по умолчанию) видны ошибки
 * и бизнес-действия, при LOG_LEVEL=debug — все запросы.
 *
 * Для JSON-ответов в лог попадает тело: объект целиком, массив — первые
 * RESPONSE_SAMPLE_SIZE элементов и общее количество (response_count).
 */
class LogRequest
{
    /**
     * Сколько элементов массива писать в лог.
     */
    private const RESPONSE_SAMPLE_SIZE = 5;

    /**
     * Максимальная длина строки в логе (тело запроса и ответа).
     */
    private const MAX_LOGGED_STRING = 500;

    /**
     * Ответы больше этого размера не декодируются и не логируются.
     */
    private const MAX_DECODED_RESPONSE_BYTES = 1_048_576;

    /**
     * Поля тела запроса, которые нельзя писать в лог.
     *
     * @var list<string>
     */
    private const SENSITIVE_FIELDS = [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        'access_token',
        'refresh_token',
        'secret',
        'authorization',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);

        $response = $next($request);

        $status = $response->getStatusCode();
        $level = match (true) {
            $status >= 500 => 'error',
            $status >= 400 => 'warning',
            $request->isMethodSafe() => 'debug',
            default => 'info',
        };

        $context = [
            'status' => $status,
            'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            'ip' => $request->ip(),
        ];

        $payload = $this->payload($request);

        if ($payload !== null) {
            $context['payload'] = $payload;
        }

        $query = $this->query($request);

        if ($query !== null) {
            $context['query'] = $query;
        }

        $user = $this->user($request);

        if ($user !== null) {
            $context['user_id'] = (string) $user->getAuthIdentifier();
        }

        $context += $this->responseContext($response);

        Log::log($level, 'http_request', $context);

        return $response;
    }

    /**
     * Тело запроса без чувствительных полей. Для GET/HEAD/OPTIONS — null.
     *
     * @return array<string, mixed>|null
     */
    private function payload(Request $request): ?array
    {
        if ($request->isMethodSafe() || $request->isMethod('OPTIONS')) {
            return null;
        }

        try {
            $data = $request->all();
        } catch (\Throwable) {
            return null;
        }

        if ($data === []) {
            return null;
        }

        return $this->sanitize($data);
    }

    /**
     * Query-параметры запроса — «payload» для GET/HEAD.
     *
     * @return array<string, mixed>|null
     */
    private function query(Request $request): ?array
    {
        if (! $request->isMethodSafe() || $request->isMethod('OPTIONS')) {
            return null;
        }

        $data = $request->query();

        return $data === [] ? null : $this->sanitize($data);
    }

    /**
     * Тело JSON-ответа: объект целиком, массив — первые
     * RESPONSE_SAMPLE_SIZE элементов плюс общее количество.
     *
     * Не-JSON, пустые и слишком большие ответы не логируются.
     *
     * @return array<string, mixed>
     */
    private function responseContext(Response $response): array
    {
        $content = $response->getContent();

        if (! is_string($content) || $content === '') {
            return [];
        }

        if (strlen($content) > self::MAX_DECODED_RESPONSE_BYTES) {
            return [];
        }

        if (! str_contains((string) $response->headers->get('Content-Type'), 'json')) {
            return [];
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (! is_array($data)) {
            return [];
        }

        if (! array_is_list($data)) {
            return ['response' => $this->sanitize($data)];
        }

        $context = [
            'response_count' => count($data),
            'response' => $this->sanitize(array_slice($data, 0, self::RESPONSE_SAMPLE_SIZE)),
        ];

        if (count($data) > self::RESPONSE_SAMPLE_SIZE) {
            $context['response_truncated'] = true;
        }

        return $context;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, mixed>
     */
    private function sanitize(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $name = (string) $key;

            if (in_array(mb_strtolower($name), self::SENSITIVE_FIELDS, true)) {
                $result[$name] = '[redacted]';

                continue;
            }

            if (is_array($value)) {
                $result[$name] = $this->sanitize($value);

                continue;
            }

            if ($value instanceof UploadedFile) {
                $result[$name] = [
                    'name' => $value->getClientOriginalName(),
                    'size' => $value->getSize(),
                    'mime' => $value->getClientMimeType(),
                ];

                continue;
            }

            if (is_string($value) && mb_strlen($value) > self::MAX_LOGGED_STRING) {
                $result[$name] = mb_substr($value, 0, self::MAX_LOGGED_STRING).'…';

                continue;
            }

            $result[$name] = $value;
        }

        return $result;
    }

    private function user(Request $request): ?Authenticatable
    {
        try {
            return $request->user();
        } catch (\Throwable) {
            return null;
        }
    }
}
