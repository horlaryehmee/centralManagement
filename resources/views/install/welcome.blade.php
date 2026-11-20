@extends('install.layout', ['title' => 'Server check', 'step' => 1])

@section('content')
    <h1 class="text-2xl font-bold tracking-tight">Let's set up {{ $app }}</h1>
    <p class="mt-2 text-sm text-zinc-600">This takes about a minute. You'll need the database name, username and password from your hosting (cPanel → MySQL Databases).</p>

    <ul class="mt-6 space-y-2.5">
        @foreach ($checks as $check)
            <li class="flex items-start gap-2.5 text-sm">
                <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white {{ $check['ok'] ? 'bg-emerald-600' : 'bg-red-600' }}">{{ $check['ok'] ? '✓' : '!' }}</span>
                <span class="min-w-0">
                    <span class="block">{{ $check['label'] }}</span>
                    @unless ($check['ok'])
                        <span class="block text-xs text-red-700">{{ $check['help'] }}</span>
                    @endunless
                </span>
            </li>
        @endforeach
    </ul>

    @if ($ready)
        <a href="{{ \App\Support\Installer::detectUrl() }}/install/database" class="mt-7 flex h-12 w-full items-center justify-center rounded-xl bg-zinc-900 text-sm font-semibold text-white hover:bg-zinc-700">Continue</a>
    @else
        <p class="mt-6 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900">Fix the items marked in red (usually in your hosting control panel), then reload this page.</p>
        <a href="" class="mt-4 flex h-12 w-full items-center justify-center rounded-xl border border-zinc-300 text-sm font-semibold hover:bg-zinc-50">Check again</a>
    @endif
@endsection
