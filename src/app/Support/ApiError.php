<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ApiError
{
    public const CODE_VALIDATION = 40001;

    public const CODE_NOT_FOUND = 40401;

    public const CODE_UNAUTHORIZED = 40101;

    public const CODE_SERVER = 50001;

    public static function validation(Request $request, string $message = 'Невалидные данные'): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'request_id' => self::requestId($request),
            'code' => self::CODE_VALIDATION,
        ], 400);
    }

    public static function notFound(Request $request, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'request_id' => self::requestId($request),
            'code' => self::CODE_NOT_FOUND,
        ], 404);
    }

    public static function server(Request $request, string $message = 'Ошибка сервера'): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'request_id' => self::requestId($request),
            'code' => self::CODE_SERVER,
        ], 500);
    }

    private static function requestId(Request $request): ?string
    {
        return $request->attributes->get('request_id')
            ?? $request->headers->get('X-Request-Id');
    }
}
