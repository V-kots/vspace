<?php

namespace App\Repositories;

use App\Models\Post;

class PostRepository
{
    public function create(string $authorUserId, string $text): string
    {
        $post = Post::query()->create([
            'author_user_id' => $authorUserId,
            'text' => $text,
        ]);

        return (string) $post->id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $id): ?array
    {
        $post = Post::query()
            ->with('author:id,first_name,second_name')
            ->find($id);

        return $post ? $this->toArray($post) : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function feed(int $offset, int $limit, ?string $authorUserId = null): array
    {
        $query = Post::query()
            ->with('author:id,first_name,second_name')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($authorUserId !== null) {
            $query->where('author_user_id', $authorUserId);
        }

        return $query
            ->offset($offset)
            ->limit($limit)
            ->get()
            ->map(fn (Post $post): array => $this->toArray($post))
            ->all();
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
