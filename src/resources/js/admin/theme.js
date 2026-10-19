// Tema claro/escuro do painel (`data-bs-theme` no <html> + localStorage `theme`).
//
// A aplicação ANTES do primeiro paint é feita pelo pequeno script inline do <head> de
// layouts/tenant-admin.blade.php (mesma regra de `preferredTheme`, abaixo). Este módulo roda deferido:
// sincroniza ícones e liga os botões de alternar (sidebar e offcanvas).

const STORAGE_KEY = 'theme';

// ids do markup de <x-admin-account> (sufixo Mobile = offcanvas)
const BUTTON_IDS = ['themeToggleBtn', 'themeToggleBtnMobile'];
const ICON_IDS = ['themeToggleIcon', 'themeToggleIconMobile'];

function readStored() {
    try {
        return localStorage.getItem(STORAGE_KEY);
    } catch {
        return null;
    }
}

export function preferredTheme() {
    const stored = readStored();
    if (stored === 'light' || stored === 'dark') return stored;

    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

export function setTheme(theme) {
    document.documentElement.setAttribute('data-bs-theme', theme);
    try {
        localStorage.setItem(STORAGE_KEY, theme);
    } catch {
        // armazenamento indisponível (modo privado): o tema vale só para esta página
    }

    const isDark = theme === 'dark';
    ICON_IDS.forEach((id) => {
        const icon = document.getElementById(id);
        if (!icon) return;
        icon.classList.toggle('fa-moon', !isDark);
        icon.classList.toggle('fa-sun', isDark);
    });
}

export function toggleTheme() {
    const current = document.documentElement.getAttribute('data-bs-theme') || 'light';
    setTheme(current === 'dark' ? 'light' : 'dark');
}

export function initTheme() {
    setTheme(preferredTheme());

    BUTTON_IDS.forEach((id) => {
        document.getElementById(id)?.addEventListener('click', toggleTheme);
    });
}
