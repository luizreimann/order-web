{{--
    <x-status-badge> — badge de status de domínio (E4.2, D8/9.3). O mapeamento
    estado → cor mora no ENUM (`label()`/`variant()`), nunca na view. Emite
    `badge text-bg-{variant}`; só aceita variantes semânticas (nunca `primary`, que o
    tema do tenant sobrescreve, nem `danger`, reservado a ações destrutivas/erros).

    Props:
      status  enum com `label()`/`variant()` (OrderStatus, PaymentStatus,
              GatewayPaymentStatus...). `null` não renderiza nada.
      active  bool (ativo/inativo, sem enum): "Ativo"/success ou "Inativo"/secondary.
      label   sobrescreve o texto.

    Exemplos:
      <x-status-badge :status="$order->status" />
      <x-status-badge :status="$order->payment_status" />
      <x-status-badge :active="$product->is_active" />
--}}
@props(['status' => null, 'active' => null, 'label' => null])

@php
    $allowed = ['success', 'warning', 'info', 'secondary', 'light', 'dark'];

    if ($active !== null) {
        $text = $active ? 'Ativo' : 'Inativo';
        $variant = $active ? 'success' : 'secondary';
    } elseif ($status instanceof \BackedEnum && method_exists($status, 'label') && method_exists($status, 'variant')) {
        $text = $status->label();
        $variant = $status->variant();
    } elseif ($status === null) {
        $text = null;
        $variant = null;
    } else {
        throw new \InvalidArgumentException('x-status-badge exige um enum com label() e variant().');
    }

    if ($variant !== null && ! in_array($variant, $allowed, true)) {
        $variant = 'secondary';
    }
@endphp

@if ($text !== null)
    <span {{ $attributes->class(['badge', "text-bg-{$variant}"]) }}>{{ $label ?? $text }}</span>
@endif
