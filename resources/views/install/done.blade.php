@extends('install.layout', ['title' => 'Done', 'step' => 4])

@section('content')
    <h1 class="text-2xl font-bold tracking-tight">Installed 🎉</h1>
    <ul class="mt-5 space-y-2 text-sm">
        @foreach ($steps as $line)
            <li class="flex gap-2.5"><span class="text-emerald-600">✓</span> {{ $line }}</li>
        @endforeach
    </ul>

    @unless ($https)
        <p class="mt-5 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900">Your site address uses http://. Turn on SSL (cPanel → SSL/TLS Status → AutoSSL) before going live.</p>
    @endunless

    @isset($backup)
        <p class="mt-5 rounded-xl bg-sky-50 px-4 py-3 text-sm text-sky-950"><strong>Important:</strong> {{ $backup }}</p>
    @endisset

    @isset($cron)
        <div class="mt-5 rounded-xl bg-zinc-50 px-4 py-3 text-sm">
            <p class="font-medium">Recommended: add this in cPanel → Cron Jobs (every minute)</p>
            <code class="mt-2 block font-mono text-xs break-all text-zinc-700">{{ $cron }}</code>
        </div>
    @endisset

    <div class="mt-6 grid gap-3 sm:grid-cols-2">
        <a href="{{ $admin }}" class="flex h-12 items-center justify-center rounded-xl bg-zinc-900 text-sm font-semibold text-white hover:bg-zinc-700">Go to admin</a>
        <a href="{{ $url }}" class="flex h-12 items-center justify-center rounded-xl border border-zinc-300 text-sm font-semibold hover:bg-zinc-50">View website</a>
    </div>
@endsection
