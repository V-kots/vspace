<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\PostRepository;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FeedController extends Controller
{
    public function __construct(private PostRepository $posts) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'offset' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'author_user_id' => ['nullable', 'uuid'],
        ]);

        if ($validator->fails()) {
            return ApiError::validation($request, $validator->errors()->first());
        }

        $offset = (int) $request->query('offset', 0);
        $limit = (int) $request->query('limit', 10);
        $authorUserId = $request->query('author_user_id');

        return response()->json(
            $this->posts->feed($offset, $limit, $authorUserId === null ? null : (string) $authorUserId)
        );
    }
}
