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

class SetFriendController extends Controller
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

        /** @var User $user */
        $user = $request->user();
        $currentUserId = (string) $user->id;

        if ($currentUserId === $userId) {
            return ApiError::validation($request, 'Нельзя добавить себя в друзья');
        }

        if (! User::query()->whereKey($userId)->exists()) {
            return ApiError::notFound($request, 'Пользователь не найден');
        }

        $this->friendships->add($currentUserId, $userId);

        $this->feeds->onFriendshipChanged($currentUserId, $userId);

        return response()->json();
    }
}
