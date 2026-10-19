import { defineConfig } from '@playwright/test';
import { BASE_URL, STOREFRONT_STATE } from './e2e/support/env';

/**
 * Playwright (E4.5). Rode SEMPRE por `npm run e2e` (bin/e2e.sh): o script prepara o servidor isolado
 * (porta 8081, banco `wl_e2e`), o seed determinístico e executa os testes dentro da imagem oficial do
 * Playwright, que é o que mantém os snapshots idênticos entre Mac, Linux e CI.
 *
 * Decisões:
 *  - 1 worker: os testes compartilham UM banco (restaurado a partir de um template a cada teste);
 *  - sem retries: um snapshot instável tem de aparecer, não ser mascarado por nova tentativa;
 *  - `desktop` roda tudo; `mobile` só as telas visuais (e o offcanvas, que define o próprio viewport).
 */
export default defineConfig({
    testDir: './e2e',
    testMatch: '**/*.spec.ts',
    outputDir: './test-results',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    forbidOnly: !!process.env.CI,
    timeout: 45_000,
    expect: {
        timeout: 7_000,
        toHaveScreenshot: {
            // Tolerância APERTADA de propósito, calibrada com a mutação do plano: tirar `shadow-sm` do
            // <x-card> muda de ~1 mil a ~9 mil pixels de tom MUITO sutil por tela. Com o `threshold`
            // padrão (0.2) e 0.1% de pixels isso passava despercebido (provado). Com 0.02 (distância de cor
            // YIQ por pixel) e 0.02% de pixels diferentes o teste morde, e o ruído de antialiasing de
            // texto entre execuções no MESMO container é zero (2 execuções seguidas, 0 diff). Se o CI em
            // outra arquitetura (amd64 x arm64) divergir em antialiasing, afrouxe `maxDiffPixelRatio` aqui
            // — nunca o `threshold` — depois de revisar o diff.
            maxDiffPixelRatio: 0.0002,
            threshold: 0.02,
            animations: 'disabled',
            caret: 'hide',
            scale: 'css',
            stylePath: './e2e/styles/visual.css',
        },
    },
    // e2e/__screenshots__/<projeto>/<arquivo do spec>/<nome>.png
    snapshotPathTemplate: '{testDir}/__screenshots__/{projectName}/{testFilePath}/{arg}{ext}',
    reporter: process.env.CI
        ? [['list'], ['github'], ['html', { open: 'never', outputFolder: 'playwright-report' }]]
        : [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]],
    globalSetup: './e2e/support/global-setup.ts',
    use: {
        baseURL: BASE_URL,
        locale: 'pt-BR',
        timezoneId: 'UTC',
        colorScheme: 'light',
        reducedMotion: 'reduce',
        serviceWorkers: 'block',
        storageState: STOREFRONT_STATE,
        actionTimeout: 10_000,
        navigationTimeout: 20_000,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'off',
    },
    projects: [
        {
            name: 'desktop',
            testIgnore: ['**/admin-mobile.spec.ts'],
            use: { viewport: { width: 1280, height: 800 }, deviceScaleFactor: 1 },
        },
        {
            name: 'mobile',
            // Só o que importa no celular: telas marcadas com a tag @mobile e o spec do offcanvas.
            testMatch: ['**/visual/*.spec.ts', '**/admin-mobile.spec.ts'],
            grep: /@mobile|admin no celular/,
            use: { viewport: { width: 390, height: 844 }, deviceScaleFactor: 1, isMobile: true, hasTouch: true },
        },
    ],
});
