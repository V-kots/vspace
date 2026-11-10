<?php

use App\Http\Controllers\Api\CreatePostController;
use App\Http\Controllers\Api\FeedController;
use App\Http\Controllers\Api\GetPostController;
use App\Http\Controllers\Api\GetUserController;
use App\Http\Controllers\Api\LoginController;
use App\Http\Controllers\Api\RegisterUserController;
use App\Http\Controllers\Api\SearchUsersController;
use Illuminate\Support\Facades\Route;

Route::post('/login', LoginController::class);
Route::post('/user/register', RegisterUserController::class);
Route::get('/user/search', SearchUsersController::class);
Route::get('/user/get/{id}', GetUserController::class);

Route::get('/post/get/{id}', GetPostController::class);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/post/create', CreatePostController::class);
    Route::get('/post/feed', FeedController::class);
});
