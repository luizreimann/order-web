// Toasts do painel: `window.showToast(mensagem, tipo)`; tipo: success | danger | warning | info | primary.
// Usa o `.toast-container` de <x-toast-container> (ou cria um se não existir).
import { Toast } from './bootstrap-ui';

const TYPE_CLASSES = {
    success: 'text-bg-success',
    danger: 'text-bg-danger',
    warning: 'text-bg-warning',
    info: 'text-bg-info',
    primary: 'text-bg-primary',
};

function container() {
    let el = document.querySelector('.toast-container');
    if (!el) {
        el = document.createElement('div');
        el.className = 'toast-container position-fixed bottom-0 end-0 p-3 z-toast';
        document.body.appendChild(el);
    }

    return el;
}

export function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `toast align-items-center border-0 ${TYPE_CLASSES[type] || TYPE_CLASSES.success}`;
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', 'assertive');
    toast.setAttribute('aria-atomic', 'true');
    toast.innerHTML = `
        <div class="d-flex">
            <div class="toast-body"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>`;
    // textContent: a mensagem nunca é interpretada como HTML
    toast.querySelector('.toast-body').textContent = message;

    container().appendChild(toast);

    toast.addEventListener('hidden.bs.toast', () => toast.remove());
    new Toast(toast, { autohide: true, delay: 3000 }).show();
}

window.showToast = showToast;
