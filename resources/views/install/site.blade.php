@extends('install.layout', ['title' => 'Your site', 'step' => 3])

@section('content')
    <h1 class="text-2xl font-bold tracking-tight">Your {{ $kind }} and login</h1>
    <p class="mt-2 text-sm text-zinc-600">The database works. Last step: name your {{ $kind }} and create your administrator login.</p>

    @error('install')
        <p class="mt-5 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</p>
    @enderror

    <form method="POST" action="{{ \App\Support\Installer::detectUrl() }}/install/finish" class="mt-6 space-y-4" onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').textContent = 'Installing… (up to a minute)';">
        @csrf
        @include('install.field', ['name' => 'site_name', 'label' => $kind === 'store' ? 'Business name' : 'Name', 'value' => old('site_name', $siteName ?? '')])
        @include('install.field', ['name' => 'url', 'label' => 'Website address', 'value' => old('url', $url), 'help' => 'Detected automatically. Use https:// if your site has SSL.'])
        <div class="border-t border-zinc-100 pt-4"></div>
        @include('install.field', ['name' => 'name', 'label' => 'Your name', 'value' => old('name')])
        @include('install.field', ['name' => 'email', 'label' => 'Admin email', 'type' => 'email', 'value' => old('email')])
        @include('install.field', ['name' => 'password', 'label' => 'Admin password', 'type' => 'password', 'help' => $minPassword ?? 'At least 8 characters.'])
        @include('install.field', ['name' => 'password_confirmation', 'label' => 'Repeat password', 'type' => 'password'])
        @if ($kind === 'store')
            <label class="flex items-start gap-3 rounded-xl bg-zinc-50 p-3 text-sm">
                <input type="hidden" name="demo" value="0">
                <input type="checkbox" name="demo" value="1" class="mt-0.5 h-4 w-4 rounded border-zinc-300" @checked(old('demo'))>
                <span><span class="block font-medium">Add demo products</span><span class="block text-xs text-zinc-500">Sample categories and products to see how it looks. You can delete them later.</span></span>
            </label>
        @endif
        <button class="flex h-12 w-full items-center justify-center rounded-xl bg-zinc-900 text-sm font-semibold text-white hover:bg-zinc-700 disabled:opacity-60">Install</button>
    </form>
@endsection
