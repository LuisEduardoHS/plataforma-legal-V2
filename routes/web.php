<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Profiles\ProfessionalDirectoryController;
use App\Http\Controllers\Profiles\ProfileController;
use App\Http\Controllers\Profiles\ProfilePhotoController;
use App\Http\Controllers\Profiles\PublicProfileController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('landing');

Route::middleware('guest')->group(function () {
    Route::get('/iniciar-sesion', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/iniciar-sesion', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:auth.login')->name('login.store');
    Route::get('/registro', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/registro', [RegisteredUserController::class, 'store'])->middleware('throttle:auth.register')->name('register.store');
});

// Public reading remains accessible. Community/articles/help retain their demos.
Route::view('/comunidad', 'pages.community.index')->name('community');
Route::get('/profesionales', ProfessionalDirectoryController::class)->middleware('throttle:profiles.read')->name('professionals');
Route::get('/profesionales/{userId}', [PublicProfileController::class, 'professional'])->whereNumber('userId')->middleware('throttle:profiles.read')->name('professionals.show');
Route::get('/usuarios/{userId}', [PublicProfileController::class, 'show'])->whereNumber('userId')->middleware('throttle:profiles.read')->name('profiles.show');
Route::view('/articulos', 'pages.articles.index')->name('articles');
Route::view('/ayuda', 'pages.help.index')->name('help');

Route::middleware('auth')->group(function () {
    Route::view('/inicio', 'pages.home.home')->name('home');
    Route::view('/mensajes', 'pages.messages.index')->name('messages');
    Route::view('/lexa', 'pages.lexa.index')->name('lexa');
    Route::view('/guardados', 'pages.saved.index')->name('saved');
    Route::get('/perfil', [ProfileController::class, 'show'])->name('profile');
    Route::get('/perfil/editar', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::get('/perfil/profesional/editar', [ProfileController::class, 'editProfessional'])->name('profile.professional.edit');
    Route::put('/perfil', [ProfileController::class, 'update'])->middleware('throttle:profiles.write')->name('profile.update');
    Route::put('/perfil/profesional', [ProfileController::class, 'updateProfessional'])->middleware('throttle:profiles.write')->name('profile.professional.update');
    Route::get('/perfil/fotografia', [ProfilePhotoController::class, 'show'])->middleware('throttle:profiles.read')->name('profile.photo');
    Route::post('/perfil/fotografia', [ProfilePhotoController::class, 'store'])->middleware('throttle:profiles.photo')->name('profile.photo.store');
    Route::delete('/perfil/fotografia', [ProfilePhotoController::class, 'destroy'])->middleware('throttle:profiles.write')->name('profile.photo.destroy');
    Route::view('/configuracion', 'pages.settings.index')->name('settings');
    Route::post('/cerrar-sesion', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});
