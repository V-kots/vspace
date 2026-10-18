<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'spa');
Route::view('/register', 'spa');
Route::view('/login', 'spa');
Route::view('/cabinet', 'spa');
Route::view('/profile/{id?}', 'spa');
