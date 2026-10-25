<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\UserRepository;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SearchUsersController extends Controller
{
    public function __construct(private UserRepository $users) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
        ]);

        if ($validator->fails()) {
            return ApiError::validation($request);
        }

        $firstName = trim((string) $request->query('first_name'));
        $lastName = trim((string) $request->query('last_name'));

        if ($firstName === '' || $lastName === '') {
            return ApiError::validation($request);
        }

        $rows = $this->users->searchByNames($firstName, $lastName);

        return response()->json(
            array_map(fn (array $row): array => $this->users->toPublicProfile($row), $rows)
        );
    }
}
