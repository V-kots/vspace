<?php

namespace App\Models;

use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;
use Throwable;

/**
 * Токен Sanctum с опциональным кешем поиска по хешу.
 *
 * Кешируется сама модель вместе с пользователем, поэтому при HIT
 * не выполняется ни поиск токена, ни запрос за tokenable.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    private const CACHE_PREFIX = 'auth:token:';

    /**
     * @param  string  $token
     * @return static|null
     */
    public static function findToken($token)
    {
        if (! config('sanctum.token_cache.enabled')) {
            return parent::findToken($token);
        }

        if (! str_contains($token, '|')) {
            return null;
        }

        // Ключ совпадает с колонкой token в БД: sha256 части после «id|».
        [, $plainToken] = explode('|', $token, 2);
        $key = self::CACHE_PREFIX.hash('sha256', $plainToken);

        try {
            $payload = Cache::get($key);

            if (is_array($payload)) {
                $cached = self::fromPayload($payload);

                if ($cached !== null) {
                    return $cached;
                }
            }
        } catch (Throwable) {
            return parent::findToken($token);
        }

        $accessToken = parent::findToken($token);

        if ($accessToken instanceof static) {
            $accessToken->loadMissing('tokenable');

            $payload = self::toPayload($accessToken);

            if ($payload !== null) {
                try {
                    Cache::put($key, $payload, (int) config('sanctum.token_cache.ttl'));
                } catch (Throwable) {
                    // Кеш не критичен: токен уже найден в БД.
                }
            }

            return $accessToken;
        }

        return null;
    }

    /**
     * В кеш кладём массивы, а не модель: config('cache.serializable_classes') = false
     * не даёт unserialize() восстанавливать объекты (вернулся бы __PHP_Incomplete_Class).
     *
     * @return array<string, mixed>|null
     */
    private static function toPayload(self $accessToken): ?array
    {
        $tokenable = $accessToken->getRelation('tokenable');

        if ($tokenable === null) {
            return null;
        }

        return [
            'token' => $accessToken->getAttributes(),
            'tokenable_class' => $tokenable::class,
            'tokenable' => $tokenable->getAttributes(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function fromPayload(array $payload): ?static
    {
        if (! isset($payload['token'], $payload['tokenable'], $payload['tokenable_class'])) {
            return null;
        }

        $tokenableClass = $payload['tokenable_class'];

        if (! is_string($tokenableClass) || ! class_exists($tokenableClass)) {
            return null;
        }

        /** @var static $prototype */
        $prototype = app(static::class);
        $accessToken = $prototype->newFromBuilder($payload['token']);

        $tokenable = (new $tokenableClass)->newFromBuilder($payload['tokenable']);
        $accessToken->setRelation('tokenable', $tokenable);

        return $accessToken;
    }

    public static function forgetCachedToken(string $hashedToken): void
    {
        if (! config('sanctum.token_cache.enabled')) {
            return;
        }

        Cache::forget(self::CACHE_PREFIX.$hashedToken);
    }
}
