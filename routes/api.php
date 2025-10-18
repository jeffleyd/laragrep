<?php

use Illuminate\Support\Facades\Route;
use LaraGrep\Http\Controllers\QueryController;

Route::group([
    'prefix' => config('laragrep.route.prefix', 'laragrep'),
    'middleware' => config('laragrep.route.middleware', []),
], function () {
    Route::post('/', QueryController::class)->name('laragrep.query');
});
