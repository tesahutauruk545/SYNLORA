<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#100e26">

        <title>{{ config('app.name', 'SYNLORA') }}</title>
        <link rel="icon" href="{{ asset('images/logo.png') }}">

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="theme-night font-sans antialiased">
        {{-- Latar ilustrasi meja belajar (cover) + overlay gradien navy-violet --}}
        <div class="auth-bg" style="--auth-img: url('{{ asset('images/auth-desk.jpg') }}')" aria-hidden="true"></div>
        <div class="auth-shade" aria-hidden="true"></div>

        <div class="relative flex min-h-screen w-full flex-col items-center justify-center px-4 py-10 sm:px-6">
            <a href="{{ url('/') }}" class="auth-logo app-brand mb-6" aria-label="SYNLORA, kembali ke beranda">
                <img src="{{ asset('images/logo-mark.png') }}" alt="" style="height:2.6rem">
                <span style="font-size:1.7rem">Synlora</span>
            </a>

            <div class="auth-card w-full {{ request()->routeIs('register') ? 'max-w-xl' : 'max-w-md' }} px-6 py-8 sm:px-9 sm:py-10">
                {{ $slot }}
            </div>

            <p class="auth-logo mt-6 text-center text-xs" style="color: rgba(253,247,241,.6); animation-delay:.3s">Learn Together. Evolve Together.</p>
        </div>
    </body>
</html>
