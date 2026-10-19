// Única porta de entrada para as classes JS do Bootstrap dentro dos módulos do painel.
//
// `bootstrap.bundle.min.js` é UMD: ao ser importado como módulo ele NÃO cria `window.bootstrap`
// (só registra a data-api: collapse, offcanvas...). O layout antigo testava `typeof bootstrap`,
// que ficava sempre `undefined` no build do Vite. Importar o default (o mesmo módulo que
// admin.js já carrega) dá acesso a `Tooltip`/`Toast` sem duplicar o Bootstrap.
import bootstrap from 'bootstrap/dist/js/bootstrap.bundle.min.js';

export const { Tooltip, Toast } = bootstrap;
