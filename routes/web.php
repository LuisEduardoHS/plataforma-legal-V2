<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Community\CommentController;
use App\Http\Controllers\Community\FeedController;
use App\Http\Controllers\Community\InteractionController;
use App\Http\Controllers\Community\MediaController;
use App\Http\Controllers\Community\PostController;
use App\Http\Controllers\ContentLibrary\EditorialController;
use App\Http\Controllers\ContentLibrary\HomeContentController;
use App\Http\Controllers\ContentLibrary\SavedController;
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

// Public reading remains accessible; help retains its static guides.
Route::get('/comunidad', [FeedController::class, 'index'])->middleware('throttle:community.read')->name('community');
Route::prefix('comunidad')->name('community.')->where([
    'post' => '[1-9][0-9]{0,17}', 'comment' => '[1-9][0-9]{0,17}',
    'user' => '[1-9][0-9]{0,17}', 'attachment' => '[1-9][0-9]{0,17}',
])->group(function () {
    Route::middleware('throttle:community.read')->group(function () {
        Route::get('/publicaciones/{post}', [PostController::class, 'show'])->name('show');
        Route::get('/publicaciones/{post}/archivos/{attachment}', MediaController::class)->name('media');
        Route::get('/usuarios/{user}', [FeedController::class, 'user'])->name('user');
    });
    Route::middleware('auth')->group(function () {
        Route::get('/crear', [PostController::class, 'create'])->name('create');
        Route::get('/publicaciones/{post}/editar', [PostController::class, 'edit'])->name('edit');
        Route::get('/comentarios/{comment}/editar', [CommentController::class, 'edit'])->name('comments.edit');
        Route::get('/usuarios/{user}/conexiones', [InteractionController::class, 'connections'])->middleware('throttle:community.read')->name('connections');
        Route::middleware('throttle:community.write')->group(function () {
            Route::post('/publicaciones', [PostController::class, 'store'])->middleware('throttle:community.upload')->name('posts.store');
            Route::put('/publicaciones/{post}', [PostController::class, 'update'])->middleware('throttle:community.upload')->name('posts.update');
            Route::post('/publicaciones/{post}/publicar', [PostController::class, 'publish'])->name('posts.publish');
            Route::delete('/publicaciones/{post}', [PostController::class, 'destroy'])->name('posts.destroy');
            Route::post('/publicaciones/{post}/moderacion', [PostController::class, 'moderate'])->name('posts.moderate');
            Route::post('/publicaciones/{post}/comentarios', [CommentController::class, 'store'])->name('comments.store');
            Route::put('/comentarios/{comment}', [CommentController::class, 'update'])->name('comments.update');
            Route::delete('/comentarios/{comment}', [CommentController::class, 'destroy'])->name('comments.destroy');
            Route::post('/comentarios/{comment}/moderacion', [CommentController::class, 'moderate'])->name('comments.moderate');
            Route::put('/publicaciones/{post}/reaccion', [InteractionController::class, 'react'])->name('react');
            Route::delete('/publicaciones/{post}/reaccion', [InteractionController::class, 'unreact'])->name('unreact');
            Route::put('/usuarios/{user}/seguir', [InteractionController::class, 'follow'])->name('follow');
            Route::delete('/usuarios/{user}/seguir', [InteractionController::class, 'unfollow'])->name('unfollow');
        });
    });
});
Route::get('/profesionales', ProfessionalDirectoryController::class)->middleware('throttle:profiles.read')->name('professionals');
Route::get('/profesionales/{userId}', [PublicProfileController::class, 'professional'])->whereNumber('userId')->middleware('throttle:profiles.read')->name('professionals.show');
Route::get('/usuarios/{userId}', [PublicProfileController::class, 'show'])->whereNumber('userId')->middleware('throttle:profiles.read')->name('profiles.show');
foreach (['articles' => ['articulos', 'article'], 'resources' => ['recursos', 'resource']] as $name => [$path, $kind]) {
    Route::get('/'.$path, [EditorialController::class, 'index'])->defaults('kind', $kind)->middleware('throttle:content.read')->name($name);
    Route::prefix($path)->name($name.'.')->where(['entry' => '[1-9][0-9]{0,17}', 'attachment' => '[1-9][0-9]{0,17}'])->group(function () use ($kind) {
        Route::middleware('auth')->group(function () use ($kind) {
            Route::get('/gestion', [EditorialController::class, 'workspace'])->defaults('kind', $kind)->middleware('throttle:content.read')->name('workspace');
            Route::get('/crear', [EditorialController::class, 'create'])->defaults('kind', $kind)->middleware('throttle:content.read')->name('create');
            Route::get('/{entry}/editar', [EditorialController::class, 'edit'])->defaults('kind', $kind)->middleware('throttle:content.read')->name('edit');
            Route::get('/{entry}/historial', [EditorialController::class, 'history'])->defaults('kind', $kind)->middleware('throttle:content.read')->name('history');
            Route::post('/', [EditorialController::class, 'store'])->defaults('kind', $kind)->middleware('throttle:content.upload')->name('store');
            Route::put('/{entry}', [EditorialController::class, 'update'])->defaults('kind', $kind)->middleware('throttle:content.upload')->name('update');
            Route::post('/{entry}/estado', [EditorialController::class, 'transition'])->defaults('kind', $kind)->middleware('throttle:content.write')->name('transition');
        });
        Route::get('/{entry}', [EditorialController::class, 'show'])->defaults('kind', $kind)->middleware('throttle:content.read')->name('show');
        Route::get('/{entry}/archivos/{attachment}', [EditorialController::class, 'media'])->defaults('kind', $kind)->middleware('throttle:content.read')->name('media');
    });
}
Route::view('/ayuda', 'pages.help.index')->name('help');

Route::middleware('auth')->group(function () {
    Route::get('/inicio', HomeContentController::class)->name('home');
    Route::view('/mensajes', 'pages.messages.index')->name('messages');
    Route::view('/lexa', 'pages.lexa.index')->name('lexa');
    Route::get('/guardados', [SavedController::class, 'index'])->middleware('throttle:content.read')->name('saved');
    Route::put('/guardados/{type}/{target}', [SavedController::class, 'store'])->where(['type' => 'post|article|resource', 'target' => '[1-9][0-9]{0,17}'])->middleware('throttle:content.write')->name('saved.store');
    Route::delete('/guardados/{type}/{target}', [SavedController::class, 'destroy'])->where(['type' => 'post|article|resource', 'target' => '[1-9][0-9]{0,17}'])->middleware('throttle:content.write')->name('saved.destroy');
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
