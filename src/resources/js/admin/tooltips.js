// Tooltips do Bootstrap em todo `[data-bs-toggle="tooltip"]` (botões da sidebar/offcanvas).
import { Tooltip } from './bootstrap-ui';

export function initTooltips() {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => {
        Tooltip.getOrCreateInstance(el);
    });
}

/** Troca o texto do tooltip de um elemento (e o `title`, se o tooltip ainda não foi criado). */
export function setTooltipTitle(el, title) {
    const tooltip = Tooltip.getInstance(el);
    if (!tooltip) {
        el.setAttribute('title', title);
        return;
    }

    el.setAttribute('data-bs-original-title', title);
    tooltip.setContent({ '.tooltip-inner': title });
}

export function bindTooltips() {
    initTooltips();
    // após navegação do Livewire (wire:navigate) o DOM novo precisa de tooltips novos
    document.addEventListener('livewire:navigated', initTooltips);
}
