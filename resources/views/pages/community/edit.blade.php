@extends('layouts.app')
@section('title', ($post ? 'Editar publicación' : 'Crear publicación').' | Lefenda')
@section('page-assets') @vite(['resources/css/community.css']) @endsection
@section('content')
<section class="community" data-community><div class="community-single"><a class="community-text-link" href="{{ $post ? route('community.show', $post['id']) : route('community') }}">← Volver</a><h1>{{ $post ? 'Editar publicación' : 'Crear publicación' }}</h1><x-community.feedback /><div class="community-surface"><x-community.post-form :post="$post" :categories="$categories" :specialties="$specialties" :tags="$tags" /></div></div></section>
@endsection
