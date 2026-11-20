@extends('install.layout', ['title' => 'Database', 'step' => 2])

@section('content')
    <h1 class="text-2xl font-bold tracking-tight">Database</h1>
    <p class="mt-2 text-sm text-zinc-600">On cPanel: <strong>MySQL Databases</strong> → create a database and a user, then add the user to the database with <strong>All Privileges</strong>. Enter them here.</p>

    @error('database')
        <p class="mt-5 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</p>
    @enderror

    <form method="POST" action="{{ \App\Support\Installer::detectUrl() }}/install/database" class="mt-6 space-y-4">
        @csrf
        @include('install.field', ['name' => 'database', 'label' => 'Database name', 'value' => old('database', $db['database']), 'placeholder' => 'cpuser_shop'])
        @include('install.field', ['name' => 'username', 'label' => 'Database username', 'value' => old('username', $db['username']), 'placeholder' => 'cpuser_shopuser'])
        @include('install.field', ['name' => 'password', 'label' => 'Database password', 'type' => 'password', 'value' => ''])
        <div class="grid grid-cols-3 gap-3">
            <div class="col-span-2">@include('install.field', ['name' => 'host', 'label' => 'Host', 'value' => old('host', $db['host']), 'help' => 'Almost always "localhost".'])</div>
            @include('install.field', ['name' => 'port', 'label' => 'Port', 'value' => old('port', $db['port'])])
        </div>
        <button class="flex h-12 w-full items-center justify-center rounded-xl bg-zinc-900 text-sm font-semibold text-white hover:bg-zinc-700">Test and continue</button>
    </form>
@endsection
