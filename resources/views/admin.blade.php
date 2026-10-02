@extends('layouts.admin')

@section('content')
    <div class="bar">
        <h1>Bus times</h1>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="plain">Sign out</button>
        </form>
    </div>
    {{-- stop schedule editor --}}
@endsection
