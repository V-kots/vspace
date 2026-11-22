<?php

namespace App\Repositories;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Лента постов: кешируемая (Redis ZSET на пользователя) или обычный запрос в БД.
 *
 * Кеш включается POST_CACHE_ENABLED. В ZSET хранятся только id постов,
 * ограничение — последние feed.cache.size записей.
 */
class FeedCacheRepository
{
    public const SOURCE_HIT = 'HIT';

    public const SOURCE_MISS = 'MISS';

    public const SOURCE_OFF = 'OFF';

    private const LOCK_TTL_SECONDS = 5;

    public function __construct(
        private PostRepository $posts,
        private FriendshipRepository $friendships,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('feed.cache.enabled');
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, cache: string}
     */
    public function page(string $userId, int $offset, int $limit): array
    {
        if (! $this->enabled()) {
            return $this->fromDatabase($userId, $offset, $limit);
        }

        if ($this->usesPageCache($offset, $limit)) {
            return $this->cachedPage($userId, $offset, $limit);
        }

        return $this->readItems($userId, $offset, $limit);
    }

    /**
     * L1: готовая страница главной через Cache::flexible.
     *
     * @return array{items: array<int, array<string, mixed>>, cache: string}
     */
    private function cachedPage(string $userId, int $offset, int $limit): array
    {
        $key = $this->pageKey($userId, $offset, $limit);

        try {
            $hit = Cache::has($key);

            /** @var array{items: array<int, array<string, mixed>>, cache: string} $page */
            $page = Cache::flexible(
                $key,
                [(int) config('feed.page.fresh'), (int) config('feed.page.stale')],
                fn (): array => $this->readItems($userId, $offset, $limit),
            );

            return [
                'items' => $page['items'],
                'cache' => $hit ? self::SOURCE_HIT : $page['cache'],
            ];
        } catch (Throwable $e) {
            report($e);

            return $this->readItems($userId, $offset, $limit);
        }
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, cache: string}
     */
    private function readItems(string $userId, int $offset, int $limit): array
    {
        try {
            $cached = $this->ensureFeed($userId);

            if ($cached === null) {
                // Ленту прямо сейчас собирает другой запрос — этот обслуживаем из БД.
                return $this->fromDatabase($userId, $offset, $limit);
            }

            $ids = $this->redis()->zrevrange($this->key($userId), $offset, $offset + $limit - 1);

            return [
                'items' => $this->posts->findByIds(array_values($ids)),
                'cache' => $cached ? self::SOURCE_HIT : self::SOURCE_MISS,
            ];
        } catch (Throwable $e) {
            report($e);

            return $this->fromDatabase($userId, $offset, $limit);
        }
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, cache: string}
     */
    private function fromDatabase(string $userId, int $offset, int $limit): array
    {
        return [
            'items' => $this->posts->feed($offset, $limit, $this->feedOwners($userId)),
            'cache' => self::SOURCE_OFF,
        ];
    }

    private function usesPageCache(int $offset, int $limit): bool
    {
        return $offset === (int) config('feed.page.offset')
            && $limit === (int) config('feed.page.limit');
    }

    public function onPostCreated(string $authorId, string $postId, int $createdAtMs): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            $owners = $this->feedOwners($authorId);

            $this->pushIntoFeeds($owners, $postId, $createdAtMs);
            $this->forgetPages($owners);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function onPostDeleted(string $authorId, string $postId): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            $keys = array_map(
                fn (string $id): string => $this->key($id),
                $this->feedOwners($authorId),
            );

            $this->pipeline(function (\Redis $pipe) use ($keys, $postId): void {
                foreach ($keys as $key) {
                    $pipe->zrem($key, $postId);
                }
            });

            $this->forgetPages($this->feedOwners($authorId));
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function onPostUpdated(string $authorId): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            $this->forgetPages($this->feedOwners($authorId));
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function onFriendshipChanged(string $userId, string $friendUserId): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            $this->redis()->del($this->key($userId), $this->key($friendUserId));

            $this->forgetPages([$userId, $friendUserId]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Ключа нет — собираем ленту из БД.
     * true: лента уже была, false: собрали сейчас, null: сборку выполняет другой запрос.
     */
    private function ensureFeed(string $userId): ?bool
    {
        $key = $this->key($userId);

        if ($this->redis()->exists($key) === 1) {
            return true;
        }

        $lock = Cache::lock($key.':lock', self::LOCK_TTL_SECONDS);

        if (! $lock->get()) {
            return null;
        }

        try {
            if ($this->redis()->exists($key) === 1) {
                return true;
            }

            $rows = $this->posts->recentIds($this->feedOwners($userId), (int) config('feed.cache.size'));

            if ($rows !== []) {
                $ttl = (int) config('feed.cache.ttl');

                $this->pipeline(function (\Redis $pipe) use ($key, $rows, $ttl): void {
                    foreach ($rows as $row) {
                        $pipe->zadd($key, $row['created_at'], $row['id']);
                    }

                    $pipe->expire($key, $ttl);
                });
            }

            return false;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  list<string>  $userIds
     */
    private function pushIntoFeeds(array $userIds, string $postId, int $createdAtMs): void
    {
        $keys = array_map(fn (string $id): string => $this->key($id), $userIds);

        $existing = $this->pipeline(function (\Redis $pipe) use ($keys): void {
            foreach ($keys as $key) {
                $pipe->exists($key);
            }
        });

        $targets = [];

        foreach ($keys as $index => $key) {
            // Ленту ещё не собирали — не создаём «огрызок», она соберётся лениво из БД.
            if (($existing[$index] ?? 0) === 1) {
                $targets[] = $key;
            }
        }

        if ($targets === []) {
            return;
        }

        $size = (int) config('feed.cache.size');
        $ttl = (int) config('feed.cache.ttl');

        $this->pipeline(function (\Redis $pipe) use ($targets, $postId, $createdAtMs, $size, $ttl): void {
            foreach ($targets as $key) {
                $pipe->zadd($key, $createdAtMs, $postId);
                $pipe->zremrangebyrank($key, 0, -($size + 1));
                $pipe->expire($key, $ttl);
            }
        });
    }

    /**
     * Кому виден пост автора (и чью ленту он формирует): сам автор и его друзья.
     *
     * @return list<string>
     */
    private function feedOwners(string $userId): array
    {
        return array_values(array_unique([$userId, ...$this->friendships->friendUserIds($userId)]));
    }

    private function redis(): Connection
    {
        return Redis::connection();
    }

    /**
     * @param  callable(\Redis): void  $callback
     * @return array<int, mixed>
     */
    private function pipeline(callable $callback): array
    {
        $pipe = $this->redis()->pipeline();

        try {
            $callback($pipe);

            return $pipe->exec();
        } catch (Throwable $e) {
            $pipe->discard();

            throw $e;
        }
    }

    /**
     * @param  list<string>  $userIds
     */
    private function forgetPages(array $userIds): void
    {
        $offset = (int) config('feed.page.offset');
        $limit = (int) config('feed.page.limit');

        foreach ($userIds as $userId) {
            Cache::forget($this->pageKey($userId, $offset, $limit));
        }
    }

    private function key(string $userId): string
    {
        return 'feed:'.$userId;
    }

    private function pageKey(string $userId, int $offset, int $limit): string
    {
        return sprintf('feed:page:%s:%d:%d', $userId, $offset, $limit);
    }
}
