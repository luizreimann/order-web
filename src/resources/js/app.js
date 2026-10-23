// Entrada do storefront (layouts/app.blade.php).
import './bootstrap';
import bootstrap from 'bootstrap/dist/js/bootstrap.bundle.min.js';
import '../css/fontawesome.css';
import '@splidejs/splide/dist/css/splide.min.css';
import './carousel';

// `bootstrap.bundle.min.js` é UMD: importado como módulo ele NÃO cria `window.bootstrap` (só registra a
// data-api). O modal do Pix do checkout (script inline) precisa da CLASSE `Modal` para abri-lo por
// código; expõe só ela, do mesmo módulo já carregado (sem outra cópia do Bootstrap).
window.StorefrontUI = { Modal: bootstrap.Modal };
