@props(['value'])

<label {{ $attributes->merge(['class' => 'block font-medium text-sm text-synlora-900/80']) }}>
    {{ $value ?? $slot }}
</label>
