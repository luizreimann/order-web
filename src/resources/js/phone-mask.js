// Formata só o input [data-phone-mask]. Não decide login, pedido nem DDD.
const SELECTOR = '[data-phone-mask]';

let hooked = false;

function digitsOf(value) {
    return String(value ?? '').replace(/\D/g, '');
}

// 12 ou 13 dígitos começando em 55, com DDD sem zero: o 55 é DDI.
// 10 ou 11 dígitos começando em 55 são o DDD 55 e ficam.
function stripCountry(digits) {
    if ((digits.length !== 12 && digits.length !== 13) || !digits.startsWith('55')) {
        return null;
    }

    const national = digits.slice(2);

    if (national[0] === '0' || national[1] === '0') {
        return null;
    }

    return national.slice(0, 11);
}

function nationalDigits(value) {
    const digits = digitsOf(value);
    const stripped = stripCountry(digits);

    if (stripped !== null) {
        return stripped;
    }

    return digits.slice(0, 11);
}

function nationalPrefixCount(raw, caret) {
    const full = digitsOf(raw);
    const prefix = digitsOf(String(raw).slice(0, caret));
    const stripped = stripCountry(full);

    if (stripped === null) {
        return Math.min(prefix.length, 11);
    }

    return Math.min(Math.max(0, prefix.length - 2), stripped.length);
}

function formatDigits(digits) {
    const length = digits.length;

    if (length === 0) {
        return '';
    }

    if (length <= 2) {
        return `(${digits}`;
    }

    const ddd = digits.slice(0, 2);
    const rest = digits.slice(2);

    if (rest.length <= 4) {
        return `(${ddd}) ${rest}`;
    }

    if (length <= 10) {
        return `(${ddd}) ${rest.slice(0, 4)}-${rest.slice(4)}`;
    }

    return `(${ddd}) ${rest.slice(0, 5)}-${rest.slice(5)}`;
}

function formatPhone(value) {
    return formatDigits(nationalDigits(value));
}

function caretFor(formatted, count) {
    if (count <= 0) {
        return 0;
    }

    let seen = 0;

    for (let index = 0; index < formatted.length; index++) {
        const char = formatted[index];

        if (char >= '0' && char <= '9') {
            seen += 1;

            if (seen === count) {
                return index + 1;
            }
        }
    }

    return formatted.length;
}

function maskedInput(target) {
    if (!target || target.nodeType !== 1 || target.tagName !== 'INPUT') {
        return null;
    }

    if (!target.matches(SELECTOR)) {
        return null;
    }

    return target;
}

function write(input, formatted, caret) {
    input.dataset.phoneMaskPrev = formatted;

    if (input.value !== formatted) {
        input.value = formatted;
    }

    if (document.activeElement !== input || typeof input.setSelectionRange !== 'function') {
        return;
    }

    const pos = Math.max(0, Math.min(caret, formatted.length));

    try {
        input.setSelectionRange(pos, pos);
    } catch {
        // type que não aceita seleção
    }
}

function onInput(input) {
    const raw = input.value;
    const previous = input.dataset.phoneMaskPrev ?? '';

    if (raw === previous) {
        return;
    }

    let digits = nationalDigits(raw);
    const caret = input.selectionStart ?? raw.length;
    let count = nationalPrefixCount(raw, caret);

    if (digits === nationalDigits(previous) && raw.length < previous.length && count > 0) {
        digits = digits.slice(0, count - 1) + digits.slice(count);
        count -= 1;
    }

    const formatted = formatDigits(digits);

    write(input, formatted, caretFor(formatted, Math.min(count, digits.length)));
}

function onBeforeInput(event) {
    const input = maskedInput(event.target);

    if (!input || event.defaultPrevented) {
        return;
    }

    if (event.inputType === 'insertText' || event.inputType === 'insertCompositionText') {
        const data = event.data ?? '';

        if (data !== '' && /[^\d+]/.test(data)) {
            event.preventDefault();
        }

        return;
    }

    if (event.inputType !== 'deleteContentBackward' && event.inputType !== 'deleteContentForward') {
        return;
    }

    const start = input.selectionStart ?? 0;
    const end = input.selectionEnd ?? start;

    if (start !== end) {
        return;
    }

    const backward = event.inputType === 'deleteContentBackward';
    const index = backward ? start - 1 : start;
    const char = input.value[index];

    if (char === undefined || (char >= '0' && char <= '9')) {
        return;
    }

    const digits = nationalDigits(input.value);
    const at = nationalPrefixCount(input.value, start);
    const removeAt = backward ? at - 1 : at;

    if (removeAt < 0 || removeAt >= digits.length) {
        return;
    }

    event.preventDefault();

    const next = digits.slice(0, removeAt) + digits.slice(removeAt + 1);
    const formatted = formatDigits(next);

    write(input, formatted, caretFor(formatted, removeAt));
    input.dispatchEvent(new Event('input', { bubbles: true }));
}

function onPaste(event) {
    const input = maskedInput(event.target);

    if (!input || !event.clipboardData) {
        return;
    }

    let text;

    try {
        text = event.clipboardData.getData('text');
    } catch {
        return;
    }

    if (typeof text !== 'string') {
        return;
    }

    event.preventDefault();

    const start = input.selectionStart ?? input.value.length;
    const end = input.selectionEnd ?? start;
    const merged = input.value.slice(0, start) + text + input.value.slice(end);
    const formatted = formatPhone(merged);
    const count = nationalPrefixCount(merged, start + text.length);

    write(input, formatted, caretFor(formatted, count));
    input.dispatchEvent(new Event('input', { bubbles: true }));
}

function onInputEvent(event) {
    const input = maskedInput(event.target);

    if (!input) {
        return;
    }

    onInput(input);
}

function eachMasked(root, fn) {
    if (!root || root.nodeType !== 1) {
        return;
    }

    if (root.matches(SELECTOR)) {
        fn(root);
    }

    root.querySelectorAll(SELECTOR).forEach(fn);
}

function remember(root) {
    eachMasked(root, (input) => {
        const formatted = formatPhone(input.value);

        if (input.value !== formatted) {
            input.value = formatted;
        }

        input.dataset.phoneMaskPrev = input.value;
    });
}

function scheduleRemember(el) {
    setTimeout(() => remember(el), 0);
}

function boot() {
    remember(document);

    if (hooked || !window.Livewire) {
        return;
    }

    hooked = true;
    window.Livewire.hook('morph.updated', ({ el }) => scheduleRemember(el));
    window.Livewire.hook('morph.added', ({ el }) => scheduleRemember(el));
}

function start() {
    if (typeof document === 'undefined') {
        return;
    }

    // Capture no document: o valor já está mascarado quando o wire:model (bubble) lê.
    // O listener não depende do nó: se o Livewire recriar o input, a máscara continua.
    document.addEventListener('beforeinput', onBeforeInput, true);
    document.addEventListener('paste', onPaste, true);
    document.addEventListener('input', onInputEvent, true);
    document.addEventListener('livewire:initialized', boot);
    document.addEventListener('livewire:navigated', () => remember(document));

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => remember(document));
    } else {
        remember(document);
    }
}

start();
