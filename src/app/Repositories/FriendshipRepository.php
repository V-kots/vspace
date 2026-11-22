<?php

namespace App\Repositories;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Дружба — ненаправленная связь: не важно, кто кого добавил.
 *
 * Новые связи хранятся канонической парой (меньший UUID первым), а запросы
 * учитывают обе стороны, поэтому старые направленные строки тоже работают.
 */
class FriendshipRepository
{
    public function add(string $userId, string $friendUserId): void
    {
        if ($this->exists($userId, $friendUserId)) {
            return;
        }

        [$low, $high] = $this->orderedPair($userId, $friendUserId);

        DB::table('friendships')->insertOrIgnore([
            'user_id' => $low,
            'friend_user_id' => $high,
            'created_at' => now(),
        ]);

        $this->forgetCache($userId, $friendUserId);
    }

    public function remove(string $userId, string $friendUserId): void
    {
        DB::table('friendships')
            ->where(function ($query) use ($userId, $friendUserId): void {
                $query->where('user_id', $userId)
                    ->where('friend_user_id', $friendUserId);
            })
            ->orWhere(function ($query) use ($userId, $friendUserId): void {
                $query->where('user_id', $friendUserId)
                    ->where('friend_user_id', $userId);
            })
            ->delete();

        $this->forgetCache($userId, $friendUserId);
    }

    public function exists(string $userId, string $friendUserId): bool
    {
        return DB::table('friendships')
            ->where(function ($query) use ($userId, $friendUserId): void {
                $query->where('user_id', $userId)
                    ->where('friend_user_id', $friendUserId);
            })
            ->orWhere(function ($query) use ($userId, $friendUserId): void {
                $query->where('user_id', $friendUserId)
                    ->where('friend_user_id', $userId);
            })
            ->exists();
    }

    /**
     * Публичные профили друзей пользователя — независимо от направления связи.
     *
     * @return array<int, array<string, mixed>>
     */
    public function profiles(string $userId): array
    {
        if (! $this->cacheEnabled()) {
            return $this->queryProfiles($userId);
        }

        return Cache::remember(
            $this->profilesCacheKey($userId),
            $this->cacheTtl(),
            fn (): array => $this->queryProfiles($userId),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function queryProfiles(string $userId): array
    {
        $sql = <<<'SQL'
SELECT DISTINCT
    u.id,
    u.first_name,
    u.second_name,
    u.birthdate,
    u.biography,
    u.city
FROM friendships f
JOIN users u ON u.id = CASE
    WHEN f.user_id = :user_id THEN f.friend_user_id
    ELSE f.user_id
END
WHERE f.user_id = :user_id OR f.friend_user_id = :user_id
ORDER BY u.first_name ASC, u.second_name ASC, u.id ASC
SQL;

        $rows = DB::select($sql, ['user_id' => $userId]);

        return array_map(static fn (object $row): array => (array) $row, $rows);
    }

    /**
     * Количество друзей пользователя (обе стороны связи, без дублей).
     */
    public function countFor(string $userId): int
    {
        if ($this->cacheEnabled()) {
            return count($this->friendUserIds($userId));
        }

        $sql = <<<'SQL'
SELECT COUNT(DISTINCT CASE
    WHEN user_id = :user_id THEN friend_user_id
    ELSE user_id
END) AS aggregate
FROM friendships
WHERE user_id = :user_id OR friend_user_id = :user_id
SQL;

        $row = DB::selectOne($sql, ['user_id' => $userId]);

        return (int) ($row->aggregate ?? 0);
    }

    /**
     * Идентификаторы друзей пользователя — независимо от направления связи.
     *
     * @return list<string>
     */
    public function friendUserIds(string $userId): array
    {
        if (! $this->cacheEnabled()) {
            return $this->queryFriendUserIds($userId);
        }

        return Cache::remember(
            $this->idsCacheKey($userId),
            $this->cacheTtl(),
            fn (): array => $this->queryFriendUserIds($userId),
        );
    }

    /**
     * @return list<string>
     */
    private function queryFriendUserIds(string $userId): array
    {
        $sql = <<<'SQL'
SELECT DISTINCT CASE
    WHEN user_id = :user_id THEN friend_user_id
    ELSE user_id
END AS friend_id
FROM friendships
WHERE user_id = :user_id OR friend_user_id = :user_id
SQL;

        /** @var list<string> $friendUserIds */
        $friendUserIds = array_map(
            static fn (object $row): string => (string) $row->friend_id,
            DB::select($sql, ['user_id' => $userId]),
        );

        return $friendUserIds;
    }

    private function cacheEnabled(): bool
    {
        return (bool) config('friends.cache.enabled');
    }

    private function cacheTtl(): int
    {
        return (int) config('friends.cache.ttl');
    }

    private function forgetCache(string ...$userIds): void
    {
        if (! $this->cacheEnabled()) {
            return;
        }

        foreach ($userIds as $userId) {
            Cache::forget($this->idsCacheKey($userId));
            Cache::forget($this->profilesCacheKey($userId));
        }
    }

    private function idsCacheKey(string $userId): string
    {
        return 'friends:ids:'.$userId;
    }

    private function profilesCacheKey(string $userId): string
    {
        return 'friends:profiles:'.$userId;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function orderedPair(string $userId, string $friendUserId): array
    {
        return strcmp($userId, $friendUserId) <= 0
            ? [$userId, $friendUserId]
            : [$friendUserId, $userId];
    }
}
