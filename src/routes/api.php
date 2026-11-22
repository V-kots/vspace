<?php

use App\Http\Controllers\Api\CreatePostController;
use App\Http\Controllers\Api\DeleteFriendController;
use App\Http\Controllers\Api\DeletePostController;
use App\Http\Controllers\Api\FeedController;
use App\Http\Controllers\Api\GetPostController;
use App\Http\Controllers\Api\GetUserController;
use App\Http\Controllers\Api\ListFriendsController;
use App\Http\Controllers\Api\LoginController;
use App\Http\Controllers\Api\RegisterUserController;
use App\Http\Controllers\Api\SearchUsersController;
use App\Http\Controllers\Api\SetFriendController;
use App\Http\Controllers\Api\UpdatePostController;
use Illuminate\Support\Facades\Route;

Route::post('/login', LoginController::class);
Route::post('/user/register', RegisterUserController::class);
Route::get('/user/search', SearchUsersController::class);
Route::get('/user/get/{id}', GetUserController::class);

Route::get('/post/get/{id}', GetPostController::class);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/post/create', CreatePostController::class);
    Route::put('/post/update', UpdatePostController::class);
    Route::put('/post/delete/{id}', DeletePostController::class);
    Route::get('/post/feed', FeedController::class);

    Route::put('/friend/set/{user_id}', SetFriendController::class);
    Route::put('/friend/add/{user_id}', SetFriendController::class);
    Route::put('/friend/delete/{user_id}', DeleteFriendController::class);
    Route::get('/friend/list', ListFriendsController::class);
});
