// Entrada do painel admin (layouts/tenant-admin.blade.php e login do admin).
import './bootstrap';
import 'bootstrap/dist/js/bootstrap.bundle.min.js';
import '../css/fontawesome.css';
import '../css/admin.css';
import Sortable from 'sortablejs';
import qrcode from 'qrcode';
import './admin/index';
import './schedule-calendar';
import './phone-mask';

// Os scripts inline de init (reordenação) das views admin usam o global `Sortable`.
window.Sortable = Sortable;

// qrcode 1.5.4: toCanvas(canvas, text, options) devolve Promise se não houver callback.
const toCanvas = qrcode.toCanvas ?? qrcode.default?.toCanvas;

const QR = '[data-whatsapp-qr]';

let qrHooked = false;

function drawQr(el) {
    if (typeof toCanvas !== 'function') {
        return;
    }

    const value = el.getAttribute('data-whatsapp-qr') || '';

    if (value === '') {
        el.replaceChildren();
        delete el.dataset.whatsappQrDrawn;

        return;
    }

    if (el.dataset.whatsappQrDrawn === value && el.querySelector('canvas')) {
        return;
    }

    const canvas = document.createElement('canvas');
    el.replaceChildren(canvas);
    el.dataset.whatsappQrDrawn = value;

    toCanvas(canvas, value, { margin: 1, width: 280 }).catch(() => {
        if (el.dataset.whatsappQrDrawn !== value) {
            return;
        }

        el.replaceChildren();
        delete el.dataset.whatsappQrDrawn;
    });
}

function drawWhatsappQr(root) {
    if (!root || root.nodeType !== 1) {
        return;
    }

    if (root.matches(QR)) {
        drawQr(root);
    }

    root.querySelectorAll(QR).forEach(drawQr);
}

function bootWhatsappQr() {
    drawWhatsappQr(document.documentElement);

    if (!window.Livewire || qrHooked) {
        return;
    }

    qrHooked = true;
    window.Livewire.hook('morph.updated', ({ el }) => {
        if (!el || el.nodeType !== 1) {
            return;
        }

        if (el.matches(QR) || el.querySelector(QR)) {
            drawWhatsappQr(el);
        }
    });
}

document.addEventListener('livewire:initialized', bootWhatsappQr);
document.addEventListener('livewire:navigated', bootWhatsappQr);
