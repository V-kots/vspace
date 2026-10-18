<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\UserRepository;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class GetUserController extends Controller
{
    public function __construct(private UserRepository $users) {}

    public function __invoke(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make(['id' => $id], [
            'id' => ['required', 'uuid'],
        ]);

        if ($validator->fails()) {
            return ApiError::validation($request);
        }

        $row = $this->users->findById($id);

        if ($row === null) {
            return ApiError::notFound($request, 'Анкета не найдена');
        }

        return response()->json($this->users->toPublicProfile($row));
    }
}
