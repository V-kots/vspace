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

class UpdatePostController extends Controller
{
    public function __construct(
        private PostRepository $posts,
        private FeedCacheRepository $feeds,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id' => ['required', 'uuid'],
            'text' => ['required', 'string', 'max:5000'],
        ]);

        if ($validator->fails()) {
            return ApiError::validation($request, $validator->errors()->first());
        }

        $text = trim((string) $request->input('text'));

        if ($text === '') {
            return ApiError::validation($request, 'Текст поста не может быть пустым');
        }

        $id = (string) $request->input('id');
        $post = $this->posts->findById($id);

        if ($post === null) {
            return ApiError::notFound($request, 'Пост не найден');
        }

        /** @var User $user */
        $user = $request->user();

        if ($post['author_user_id'] !== (string) $user->id) {
            return ApiError::forbidden($request, 'Нельзя редактировать чужой пост');
        }

        $this->posts->updateText($id, $text);

        $this->feeds->onPostUpdated((string) $user->id);

        return response()->json();
    }
}
