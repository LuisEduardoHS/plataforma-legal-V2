@extends('layouts.verification')
@section('verification-title', 'No se completó la operación')
@section('verification-content')
<section class="profile-panel space-y-4" role="alert"><h2 class="font-semibold">Error {{ $status }}</h2><p>{{ $message }}</p><a class="underline inline-block" href="{{ route('verification') }}">Consultar mi estado actual</a></section>
@endsection
