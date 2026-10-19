// Abrir/fechar a loja: `POST` em `data-store-status-url` (rota `tenant.admin.toggle-store-status`,
// resposta `{ success, paused }`). Markup: <x-admin-store-actions> (sidebar e offcanvas).
import { showToast } from './toast';
import { setTooltipTitle } from './tooltips';

// ids do markup (sufixo Mobile = offcanvas)
const BUTTON_PAIRS = [
    ['storeStatusBtn', 'storeStatusIcon'],
    ['storeStatusBtnMobile', 'storeStatusIconMobile'],
];

function render(trigger, paused) {
    BUTTON_PAIRS.forEach(([btnId, iconId]) => {
        const btn = document.getElementById(btnId);
        const icon = document.getElementById(iconId);
        if (!btn || !icon) return;

        btn.className = `btn btn-outline-${paused ? 'danger' : 'success'} btn-sm d-flex align-items-center justify-content-center`;
        setTooltipTitle(btn, paused ? trigger.dataset.labelPaused : trigger.dataset.labelOpen);
        icon.className = `fa-solid fa-${paused ? 'lock' : 'lock-open'}`;
    });
}

async function toggleStoreStatus(trigger) {
    try {
        const response = await fetch(trigger.dataset.storeStatusUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
        });
        const data = await response.json();

        if (data.success) {
            render(trigger, data.paused);
            showToast(
                data.paused ? 'Loja fechada! Pedidos pausados.' : 'Loja aberta! Aceitando pedidos.',
                data.paused ? 'warning' : 'success',
            );
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Erro ao alterar status da loja', 'danger');
    }
}

export function initStoreStatus() {
    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-store-status-toggle]');
        if (trigger) toggleStoreStatus(trigger);
    });
}
