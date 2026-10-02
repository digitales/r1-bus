@extends('layouts.admin')

@section('content')
    <h1>Sign in</h1>
    <form method="POST" action="{{ route('login.store') }}" class="card">
        @csrf
        <label for="email">Email</label>
        <div class="row"><input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus></div>
        <label for="password">Password</label>
        <div class="row"><input id="password" name="password" type="password" required></div>
        @error('email')
            <p class="error">{{ $message }}</p>
        @enderror
        <p><button type="submit">Sign in</button></p>
    </form>
@endsection
