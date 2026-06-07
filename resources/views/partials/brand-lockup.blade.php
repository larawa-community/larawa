@php
    $logoClass = $logoClass ?? 'h-12 w-auto';
@endphp

<img src="{{ asset('images/laraWA-logo.png') }}" alt="LaraWA" class="block object-contain {{ $logoClass }}">
