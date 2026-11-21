@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 font-medium text-sm text-emerald-700']) }} role="status">
        {{ $status }}
    </div>
@endif
