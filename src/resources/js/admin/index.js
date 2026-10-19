// Comportamento do layout do painel (layouts/tenant-admin.blade.php), antes um <script> inline.
// admin.js também é carregado pelo login do admin (sem sidebar): só inicia onde há o shell.
import { initTheme } from './theme';
import { bindTooltips } from './tooltips';
import { initStoreStatus } from './store-status';
import './toast'; // registra window.showToast

if (document.querySelector('.admin-shell')) {
    initTheme();
    bindTooltips();
    initStoreStatus();
}
