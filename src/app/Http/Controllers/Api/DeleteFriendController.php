<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Repositories\FeedCacheRepository;
use App\Repositories\FriendshipRepository;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DeleteFriendController extends Controller
{
    public function __construct(
        private FriendshipRepository $friendships,
        private FeedCacheRepository $feeds,
    ) {}

    public function __invoke(Request $request, string $userId): JsonResponse
    {
        $validator = Validator::make(['user_id' => $userId], [
            'user_id' => ['required', 'uuid'],
        ]);

        if ($validator->fails()) {
            return ApiError::validation($request);
        }

        if (! User::query()->whereKey($userId)->exists()) {
            return ApiError::notFound($request, 'Пользователь не найден');
        }

        /** @var User $user */
        $user = $request->user();

        $this->friendships->remove((string) $user->id, $userId);

        $this->feeds->onFriendshipChanged((string) $user->id, $userId);

        return response()->json();
    }
}
