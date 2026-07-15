<?php

use App\Http\Controllers\MediaController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
| Shared uploads from site/public (SHARED_PUBLIC_PATH).
| Example: GET /media/uploads/hizmet/seeder_hizmet_2.jpg
*/
Route::get('/media/{path}', [MediaController::class, 'show'])
    ->where('path', '.*')
    ->name('media.show');
