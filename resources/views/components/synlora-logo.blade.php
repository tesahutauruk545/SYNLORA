@props([
    'variant' => 'full',
    'class' => '',
])

@if($variant === 'full')
    <div class="flex flex-col items-center gap-3 {{ $class }}">
        <img src="{{ asset('images/logo.png') }}" alt="SYNLORA Logo" class="h-40 w-auto">
        <div class="text-center">
            <h1 class="text-4xl font-bold tracking-[0.2em] text-synlora-900">SYNLORA</h1>
            <p class="mt-2 text-sm font-medium text-synlora-600">Learn Together. Evolve Together.</p>
            <p class="mt-1 text-[10px] text-synlora-400 hidden md:block tracking-wide">
                SYNCHRONIZED NETWORK FOR LEARNING, ORGANIZATION, REFLECTION &amp; ACHIEVEMENT
            </p>
        </div>
    </div>
@elseif($variant === 'icon')
    <img src="{{ asset('images/logo.png') }}" alt="SYNLORA" class="h-12 w-auto {{ $class }}">
@else
    <img src="{{ asset('images/logo.png') }}" alt="SYNLORA" class="h-10 w-auto {{ $class }}">
@endif