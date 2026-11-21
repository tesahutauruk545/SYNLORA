@props(['active'])

@php
$classes = ($active ?? false) ? 'app-link is-active' : 'app-link';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }} @if($active ?? false) aria-current="page" @endif>
    {{ $slot }}
</a>
