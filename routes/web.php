<?php

use Illuminate\Support\Facades\Route;

// Route::view chứ không phải closure: production chạy `route:cache` lúc build image, mà closure
// không serialize được.
Route::view('/', 'welcome');
