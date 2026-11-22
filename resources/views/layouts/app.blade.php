<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#0b0a1c">
        @auth
            <meta name="synlora-presence" content="{{ route('presence.ping') }}" data-interval="{{ (int) config('synlora.presence.ping_seconds', 25) }}">
        @endauth

        <title>{{ config('app.name', 'SYNLORA') }}</title>
        <link rel="icon" href="{{ asset('images/logo.png') }}">

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="theme-night font-sans antialiased">
        <div class="night-ambient" aria-hidden="true"><i></i><i></i></div>

        <div class="min-h-screen">
            @include('layouts.navigation')

            @if (isset($header))
                <header class="app-header">
                    <div class="max-w-7xl mx-auto py-6 sm:py-8 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endif

            @if (session('status') && ! in_array(session('status'), ['profile-updated', 'password-updated', 'verification-link-sent'], true))
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4"
                     x-data="{ show: true }" x-init="setTimeout(() => show = false, 9000)" x-show="show" x-cloak
                     x-transition:enter="transition duration-500 ease-synlora"
                     x-transition:enter-start="opacity-0 -translate-y-3"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     x-transition:leave="transition duration-300 ease-synlora"
                     x-transition:leave-start="opacity-100 translate-y-0"
                     x-transition:leave-end="opacity-0 -translate-y-3">
                    <div class="syn-toast flex items-start justify-between gap-3 bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-800" role="status">
                        <span>{{ session('status') }}</span>
                        <button type="button" @click="show = false" class="-m-1 rounded-full p-1 opacity-70 transition hover:opacity-100" aria-label="Tutup notifikasi">
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M5 5l10 10M15 5L5 15"/></svg>
                        </button>
                    </div>
                </div>
            @endif

            <main class="app-main">
                {{ $slot }}
            </main>
        </div>
    </body>
</html>
