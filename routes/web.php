<?php

/*
--- Rutas web de la Plataforma Legal

--- Define las direcciones accesibles desde el navegador.
*/

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('landing');

// Inicio
// Despues ocupara autenticacion
Route::view('/inicio', 'pages.home.home')->name('home');

Route::view('/iniciar-sesion', 'pages.auth.login')->name('login');

Route::view('/registro', 'pages.auth.register')->name('register');

// Despues se agregara un controlador para manejar la autenticacion.

// Despues se protegeran con middleware auth.

Route::view('/inicio', 'pages.home.home')->name('home');

Route::view('/comunidad', 'pages.community.index')->name('community');

Route::view('/mensajes', 'pages.messages.index')->name('messages');

Route::view('/lexa', 'pages.lexa.index')->name('lexa');

Route::view('/profesionales', 'pages.professionals.index')->name('professionals');

Route::view('/articulos', 'pages.articles.index')->name('articles');

Route::view('/guardados', 'pages.saved.index')->name('saved');

Route::view('/ayuda', 'pages.help.index')->name('help');

Route::view('/perfil', 'pages.profile.show')->name('profile');

Route::view('/configuracion', 'pages.settings.index')->name('settings');
