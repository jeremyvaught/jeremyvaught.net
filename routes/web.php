<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'site.index')->name('home');
Route::view('/index.htm', 'site.index');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
