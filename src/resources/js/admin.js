// Entrada do painel admin (layouts/tenant-admin.blade.php e login do admin).
import './bootstrap';
import 'bootstrap/dist/js/bootstrap.bundle.min.js';
import '../css/fontawesome.css';
import Sortable from 'sortablejs';

// Os scripts inline de init (reordenação) das views admin usam o global `Sortable`.
window.Sortable = Sortable;
