{{--
    <x-form-select> — <x-form-field type="select"> (E4.2). Slot = <option>s. Mesma API
    de props e atributos do form-field (is-invalid/invalid-feedback automáticos).

    Exemplo:
      <x-form-select name="status" label="Status" wire:model.live="status">
          <option value="active">Ativos</option>
      </x-form-select>
--}}
@props(['name', 'label' => null, 'help' => null, 'required' => false, 'id' => null, 'errorKey' => null, 'named' => false])

<x-form-field type="select" :name="$name" :label="$label" :help="$help" :required="$required" :id="$id" :error-key="$errorKey" :named="$named" {{ $attributes }}>{{ $slot }}</x-form-field>
