<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Repositories\FriendshipRepository;
use App\Repositories\UserRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListFriendsController extends Controller
{
    public function __construct(
        private FriendshipRepository $friendships,
        private UserRepository $users,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $rows = $this->friendships->profiles((string) $user->id);

        return response()->json(
            array_map(fn (array $row): array => $this->users->toPublicProfile($row), $rows)
        );
    }
}
