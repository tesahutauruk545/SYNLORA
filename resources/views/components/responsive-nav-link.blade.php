@props(['active'])

@php
$classes = ($active ?? false) ? 'app-mlink is-active' : 'app-mlink';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }} @if($active ?? false) aria-current="page" @endif>{{ $slot }}</a>
