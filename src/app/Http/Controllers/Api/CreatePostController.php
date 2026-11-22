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

class CreatePostController extends Controller
{
    public function __construct(
        private PostRepository $posts,
        private FeedCacheRepository $feeds,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'text' => ['required', 'string', 'max:5000'],
        ]);

        if ($validator->fails()) {
            return ApiError::validation($request, $validator->errors()->first());
        }

        $text = trim((string) $request->input('text'));

        if ($text === '') {
            return ApiError::validation($request, 'Текст поста не может быть пустым');
        }

        /** @var User $user */
        $user = $request->user();

        $post = $this->posts->create((string) $user->id, $text);

        $this->feeds->onPostCreated(
            (string) $user->id,
            (string) $post->id,
            $post->created_at->getTimestampMs(),
        );

        return response()->json(['id' => (string) $post->id]);
    }
}
