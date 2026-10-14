{{--
    <x-form-field> — label + controle + erro + ajuda (E4.2, D8). Corrige o defeito
    "@error sem is-invalid": `is-invalid` e `invalid-feedback` saem sozinhos de `$errors`.

    Sem wrapper: emite label, controle, feedback e ajuda como fragmento; o container
    (`col-*`, `vstack`) é de quem chama.

    Props:
      name      (obrigatório) chave de validação. Aceita ponto/array (`images.0`);
                erros de `name.*` também marcam o campo.
      label     texto do label; sem label, só o controle é emitido.
      type      text|number|email|password|file|date|... (`<input>`), `textarea`,
                `select` (slot = <option>s) ou `group` (slot = conteúdo livre, ex.: um
                conjunto de <x-form-check>; o erro aparece com `d-block`).
      required  só a marca " *" no label (NÃO põe o atributo HTML `required`).
      help      texto de ajuda (`form-text`).
      id        sobrescreve o id derivado do nome (`images.0` => `images_0`).
      error-key valida por outra chave que não `name`.
      named     emite `name="..."` no controle (formulários sem Livewire).
    Slots:  default (opções do select / conteúdo do group) e `after` (renderizado
            depois do erro e antes da ajuda; ex.: preview de imagem).
    Atributos extras (wire:model*, min, max, step, placeholder, disabled, rows, accept,
    class...) vão para o controle.

    Exemplos:
      <x-form-field name="name" label="Nome" required wire:model.defer="name" />
      <x-form-field name="price" label="Preço (R$)" placeholder="19,90" wire:model.defer="price" />
      <x-form-field type="textarea" name="description" label="Descrição" rows="5" wire:model.defer="description" />
      <x-form-field type="file" name="image" label="Imagem" help="PNG/JPG, até 3MB." wire:model="image" accept="image/*">
          <x-slot:after>...preview...</x-slot:after>
      </x-form-field>
      <x-form-field type="group" name="category_ids" label="Categorias">...checks...</x-form-field>
--}}
@props([
    'name',
    'label' => null,
    'type' => 'text',
    'help' => null,
    'required' => false,
    'id' => null,
    'errorKey' => null,
    'named' => false,
    'after' => null,
])

@php
    $errorKey ??= $name;
    $isGroup = $type === 'group';
    $isSelect = $type === 'select';

    $messages = [];
    if (isset($errors)) {
        $messages = array_values(array_unique(\Illuminate\Support\Arr::flatten([$errors->get($errorKey), $errors->get($errorKey.'.*')])));
    }
    $invalid = $messages !== [];

    $controlId = $id ?? (($label !== null || $help !== null)
        ? trim((string) preg_replace('/[^A-Za-z0-9_\-]+/', '_', $name), '_')
        : null);

    // Sem @if dentro da tag (os marcadores de morph do Livewire não podem cair em atributos).
    $labelAttrs = array_filter([
        'for' => $isGroup ? null : $controlId,
        'class' => 'form-label',
    ], fn ($v) => $v !== null);

    $extra = array_filter([
        'id' => $controlId,
        'name' => $named ? $name : null,
        'aria-invalid' => $invalid ? 'true' : null,
    ], fn ($v) => $v !== null);
@endphp

@if ($label !== null)
    <label {{ new \Illuminate\View\ComponentAttributeBag($labelAttrs) }}>{{ $label }}@if ($required) *@endif</label>
@endif

@if ($isGroup)
    {{ $slot }}
@elseif ($isSelect)
    <select {{ $attributes->class(['form-select', 'is-invalid' => $invalid])->merge($extra) }}>{{ $slot }}</select>
@elseif ($type === 'textarea')
    <textarea {{ $attributes->class(['form-control', 'is-invalid' => $invalid])->merge($extra) }}></textarea>
@else
    <input type="{{ $type }}" {{ $attributes->class(['form-control', 'is-invalid' => $invalid])->merge($extra) }}>
@endif

@foreach ($messages as $message)
    <div @class(['invalid-feedback', 'd-block' => $isGroup])>{{ $message }}</div>
@endforeach

{{ $after }}

@if ($help !== null)
    <div class="form-text">{{ $help }}</div>
@endif
