<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class LoginController extends Controller
{
    public function __construct(private UserRepository $users) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id' => ['required', 'uuid'],
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return ApiError::validation($request);
        }

        $row = $this->users->findById($request->input('id'));

        if ($row === null) {
            return ApiError::notFound($request, 'Пользователь не найден');
        }

        if (! Hash::check($request->input('password'), $row['password'])) {
            return ApiError::validation($request, 'Неверный пароль');
        }

        if (! $row['is_active']) {
            return ApiError::validation($request, 'Анкета неактивна');
        }

        $this->users->touchLastLogin((string) $row['id']);

        /** @var User $user */
        $user = User::query()->findOrFail($row['id']);
        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user_id' => (string) $row['id'],
        ]);
    }
}
