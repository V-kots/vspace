<?php

use App\Http\Controllers\Api\GetUserController;
use App\Http\Controllers\Api\LoginController;
use App\Http\Controllers\Api\RegisterUserController;
use Illuminate\Support\Facades\Route;

Route::post("/login", LoginController::class);
Route::post("/user/register", RegisterUserController::class);
Route::get("/user/get/{id}", GetUserController::class);
