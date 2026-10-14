{{--
    <x-form-check> — checkbox/radio/switch com label (E4.2). `.form-check` > input +
    label + invalid-feedback; a ajuda (`form-text`) vem logo depois do `.form-check`.

    Props:
      name      (obrigatório) chave de validação e base do id.
      label     texto do label (ou use o slot para label rico).
      type      checkbox (padrão) | radio.
      switch    `form-switch`.
      help      texto de ajuda.
      id        obrigatório quando vários checks compartilham o `name` (ex.: category_ids).
      validates false => não lê `$errors` (checks de um grupo; o erro fica no <x-form-field type="group">).
      error-key valida por outra chave que não `name`.
    Atributos extras (wire:model*, value, disabled...) vão para o <input>.

    Exemplos:
      <x-form-check name="is_active" label="Ativo" wire:model.defer="is_active" />
      <x-form-check name="track_inventory" label="Controlar estoque" help="Desligado = sob encomenda." wire:model.live="track_inventory" />
      <x-form-check name="category_ids" :id="'cat_'.$cat->id" :value="$cat->id" :label="$cat->name" :validates="false" wire:model.defer="category_ids" />
--}}
@props([
    'name',
    'label' => null,
    'type' => 'checkbox',
    'switch' => false,
    'help' => null,
    'id' => null,
    'errorKey' => null,
    'validates' => true,
])

@php
    $errorKey ??= $name;
    $messages = [];
    if ($validates && isset($errors)) {
        $messages = array_values(array_unique(\Illuminate\Support\Arr::flatten([$errors->get($errorKey), $errors->get($errorKey.'.*')])));
    }
    $invalid = $messages !== [];
    $controlId = $id ?? trim((string) preg_replace('/[^A-Za-z0-9_\-]+/', '_', $name), '_');
    $extra = array_filter([
        'id' => $controlId,
        'aria-invalid' => $invalid ? 'true' : null,
    ], fn ($v) => $v !== null);
@endphp

<div @class(['form-check', 'form-switch' => $switch && $type === 'checkbox'])>
    <input type="{{ $type }}" {{ $attributes->class(['form-check-input', 'is-invalid' => $invalid])->merge($extra) }}>
    <label class="form-check-label" for="{{ $controlId }}">{{ $label ?? $slot }}</label>
    @foreach ($messages as $message)
        <div class="invalid-feedback">{{ $message }}</div>
    @endforeach
</div>
@if ($help !== null)
    <div class="form-text">{{ $help }}</div>
@endif
