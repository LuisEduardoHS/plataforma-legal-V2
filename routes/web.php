<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('landing');

Route::middleware('guest')->group(function () {
    Route::get('/iniciar-sesion', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/iniciar-sesion', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:auth.login')->name('login.store');
    Route::get('/registro', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/registro', [RegisteredUserController::class, 'store'])->middleware('throttle:auth.register')->name('register.store');
});

// Public reading remains accessible. These modules still render their existing demos.
Route::view('/comunidad', 'pages.community.index')->name('community');
Route::view('/profesionales', 'pages.professionals.index')->name('professionals');
Route::view('/articulos', 'pages.articles.index')->name('articles');
Route::view('/ayuda', 'pages.help.index')->name('help');

Route::middleware('auth')->group(function () {
    Route::view('/inicio', 'pages.home.home')->name('home');
    Route::view('/mensajes', 'pages.messages.index')->name('messages');
    Route::view('/lexa', 'pages.lexa.index')->name('lexa');
    Route::view('/guardados', 'pages.saved.index')->name('saved');
    Route::view('/perfil', 'pages.profile.show')->name('profile');
    Route::view('/configuracion', 'pages.settings.index')->name('settings');
    Route::post('/cerrar-sesion', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});
