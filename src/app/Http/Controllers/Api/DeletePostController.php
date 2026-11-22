<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Repositories\FeedCacheRepository;
use App\Repositories\PostRepository;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DeletePostController extends Controller
{
    public function __construct(
        private PostRepository $posts,
        private FeedCacheRepository $feeds,
    ) {}

    public function __invoke(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make(['id' => $id], [
            'id' => ['required', 'uuid'],
        ]);

        if ($validator->fails()) {
            return ApiError::validation($request);
        }

        $post = $this->posts->findById($id);

        if ($post === null) {
            return ApiError::notFound($request, 'Пост не найден');
        }

        /** @var User $user */
        $user = $request->user();

        if ($post['author_user_id'] !== (string) $user->id) {
            return ApiError::forbidden($request, 'Нельзя удалить чужой пост');
        }

        $this->posts->deleteById($id);

        $this->feeds->onPostDeleted($post['author_user_id'], $id);

        return response()->json();
    }
}
