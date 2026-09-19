@props(['target' => null, 'size' => 'sm'])

@php
    $sizeClasses = [
        'sm' => 'spinner-border-sm',
        'md' => '',
        'lg' => 'spinner-border-lg'
    ];
    $spinnerClass = $sizeClasses[$size] ?? $sizeClasses['sm'];
@endphp

<span {{ $attributes->merge(['class' => 'inline-loader']) }} @if($target) wire:loading wire:target="{{ $target }}" @endif>
    <span class="spinner-border {{ $spinnerClass }}" role="status">
        <span class="visually-hidden">Carregando...</span>
    </span>
</span>

<style>
    .inline-loader {
        display: inline-block;
        margin-left: 0.5rem;
    }
    
    .spinner-border-lg {
        width: 2rem;
        height: 2rem;
        border-width: 0.3em;
    }
</style>
