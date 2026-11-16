@extends('layouts.operations')
@section('operations-title', 'Moderación de Comunidad')
@section('operations-content')
<section class="profile-panel space-y-4"><h2 class="text-lg font-semibold">Publicaciones disponibles para revisión</h2><p>Las acciones se realizan en Comunidad y conservan sus controles de visibilidad, versión y autorización.</p>
@forelse ($posts as $post)<article class="border-b border-gray-200 dark:border-border-dark pb-4 space-y-2 break-words"><h3 class="font-semibold">Publicación #{{ $post['id'] }} · {{ $post['status'] }}</h3><p>{{ \Illuminate\Support\Str::limit($post['content'], 240) }}</p><a class="underline" href="{{ route('community.show', ['post' => $post['id'], 'review' => $can_review_comments ? 1 : 0]) }}">Revisar publicación{{ $can_review_comments ? ' y comentarios' : '' }}</a></article>@empty<p>No hay publicaciones disponibles para tu acceso.</p>@endforelse
<x-community.pagination :page="$posts" /></section>
@endsection
