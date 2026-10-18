<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\UserRepository;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class RegisterUserController extends Controller
{
    public function __construct(private UserRepository $users) {}

    public function __invoke(Request $request): JsonResponse
    {
        $minBirthdate = now()->subYears(120)->toDateString();

        $validator = Validator::make($request->all(), [
            'first_name' => ['required', 'string', 'min:2', 'max:100', 'regex:/^[\p{L}\s\'-]+$/u'],
            'second_name' => ['required', 'string', 'min:2', 'max:100', 'regex:/^[\p{L}\s\'-]+$/u'],
            'birthdate' => ['required', 'date', 'before:today', 'after:'.$minBirthdate],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'gender' => ['required', 'string', 'in:male,female,other'],
            'biography' => ['nullable', 'string', 'max:5000'],
            'city' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        if ($validator->fails()) {
            return ApiError::validation($request, $validator->errors()->first());
        }

        $email = strtolower(trim((string) $request->input('email')));

        if ($this->users->findByEmail($email) !== null) {
            return ApiError::validation($request, 'Email уже зарегистрирован');
        }

        $userId = $this->users->insert([
            'first_name' => trim((string) $request->input('first_name')),
            'second_name' => trim((string) $request->input('second_name')),
            'birthdate' => $request->input('birthdate'),
            'email' => $email,
            'gender' => $request->input('gender'),
            'biography' => $request->input('biography'),
            'city' => $request->input('city'),
            'password' => Hash::make($request->input('password')),
        ]);

        return response()->json(['user_id' => $userId]);
    }
}
