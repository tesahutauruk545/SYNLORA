<x-app-layout>
    <x-slot name="header">
        <h2 class="font-display text-2xl font-semibold leading-tight text-white sm:text-3xl">
            {{ __('Pomodoro Solo') }}
        </h2>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            @include('partials.session-panel', [
                'mode' => 'solo',
                'activeSession' => $activeSession,
                'otherSession' => $otherSession,
                'defaultFocusMinutes' => $activeSession?->focus_duration_minutes ?? 25,
            ])
        </div>
    </div>
</x-app-layout>
