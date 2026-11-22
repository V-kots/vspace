<?php

namespace App\Repositories;

use App\Models\Post;
use Illuminate\Support\Facades\Cache;
use Throwable;

class PostRepository
{
    public function create(string $authorUserId, string $text): Post
    {
        return Post::query()->create([
            'author_user_id' => $authorUserId,
            'text' => $text,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $id): ?array
    {
        return $this->findByIds([$id])[0] ?? null;
    }

    public function updateText(string $id, string $text): void
    {
        Post::query()->whereKey($id)->update([
            'text' => $text,
        ]);

        $this->forgetCachedPost($id);
    }

    public function deleteById(string $id): void
    {
        Post::query()->whereKey($id)->delete();

        $this->forgetCachedPost($id);
    }

    /**
     * Лента постов указанных авторов. По умолчанию контроллер передаёт
     * текущего пользователя и его друзей; при фильтре по автору — одного автора.
     *
     * @param  list<string>  $authorUserIds
     * @return array<int, array<string, mixed>>
     */
    public function feed(int $offset, int $limit, array $authorUserIds): array
    {
        if ($authorUserIds === []) {
            return [];
        }

        return Post::query()
            ->with('author:id,first_name,second_name')
            ->whereIn('author_user_id', $authorUserIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->offset($offset)
            ->limit($limit)
            ->get()
            ->map(fn (Post $post): array => $this->toArray($post))
            ->all();
    }

    /**
     * Идентификаторы последних постов указанных авторов — для сборки ленты в кеш.
     *
     * @param  list<string>  $authorUserIds
     * @return list<array{id: string, created_at: int}>
     */
    public function recentIds(array $authorUserIds, int $limit): array
    {
        if ($authorUserIds === [] || $limit < 1) {
            return [];
        }

        return Post::query()
            ->useWritePdo()
            ->whereIn('author_user_id', $authorUserIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'created_at'])
            ->map(static fn (Post $post): array => [
                'id' => (string) $post->id,
                'created_at' => $post->created_at->getTimestampMs(),
            ])
            ->all();
    }

    /**
     * Посты по списку идентификаторов с сохранением порядка этого списка.
     *
     * @param  list<string>  $ids
     * @return array<int, array<string, mixed>>
     */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        if (! $this->postCacheEnabled()) {
            return $this->ordered($ids, $this->fetchPosts($ids));
        }

        $keys = array_map(fn (string $id): string => $this->postCacheKey($id), $ids);

        try {
            $cached = Cache::many($keys);
        } catch (Throwable) {
            return $this->ordered($ids, $this->fetchPosts($ids));
        }

        $missing = [];
        $payloads = [];

        foreach ($ids as $id) {
            $value = $cached[$this->postCacheKey($id)] ?? null;

            if (is_array($value)) {
                $payloads[$id] = $value;
            } else {
                $missing[] = $id;
            }
        }

        if ($missing !== []) {
            $fetched = $this->fetchPosts($missing, true);

            $toCache = [];
            foreach ($fetched as $id => $payload) {
                $payloads[$id] = $payload;
                $toCache[$this->postCacheKey($id)] = $payload;
            }

            try {
                if ($toCache !== []) {
                    Cache::putMany($toCache, (int) config('feed.post.ttl'));
                }
            } catch (Throwable) {
                // Кеш не критичен: отдаём то, что пришло из БД.
            }
        }

        return $this->ordered($ids, $payloads);
    }

    /**
     * @param  list<string>  $ids
     * @param  array<string, array<string, mixed>>  $payloads
     * @return array<int, array<string, mixed>>
     */
    private function ordered(array $ids, array $payloads): array
    {
        $result = [];

        foreach ($ids as $id) {
            if (isset($payloads[$id])) {
                $result[] = $payloads[$id];
            }
        }

        return $result;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>>
     */
    private function fetchPosts(array $ids, bool $fromMaster = false): array
    {
        $query = Post::query()
            ->with('author:id,first_name,second_name')
            ->whereIn('id', $ids);

        if ($fromMaster) {
            // Иначе с лагом реплики в кеш можно записать старый текст поста.
            $query->useWritePdo();
        }

        return $query
            ->get()
            ->mapWithKeys(fn (Post $post): array => [(string) $post->id => $this->toArray($post)])
            ->all();
    }

    private function postCacheEnabled(): bool
    {
        return (bool) config('feed.post.enabled');
    }

    private function postCacheKey(string $id): string
    {
        return 'post:'.$id;
    }

    private function forgetCachedPost(string $id): void
    {
        if (! $this->postCacheEnabled()) {
            return;
        }

        try {
            Cache::forget($this->postCacheKey($id));
        } catch (Throwable) {
            // Ничего не делаем: устаревшая запись инвалидируется по TTL.
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(Post $post): array
    {
        return [
            'id' => (string) $post->id,
            'text' => $post->text,
            'author_user_id' => (string) $post->author_user_id,
            'author' => $post->author === null ? null : [
                'id' => (string) $post->author->id,
                'first_name' => $post->author->first_name,
                'second_name' => $post->author->second_name,
            ],
            'created_at' => $post->created_at->toIso8601String(),
        ];
    }
}
