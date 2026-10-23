<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * E5.4 (UI-010) — o bloco JS do Mercado Pago no checkout monta QR/ticket a partir de dados
 * do gateway. Não dá para provar o DOM real sem navegador (o Playwright é da sprint E4.5),
 * então aqui há duas camadas que NÃO dependem de browser:
 *
 *  1. análise estática do fonte da view: nada de `innerHTML`/`outerHTML`/
 *     `insertAdjacentHTML`/`document.write` e o dado do gateway só vira nó DOM por
 *     `createElement`/`textContent`/atributos validados;
 *  2. as funções puras de validação (`safeHttpsUrl`, `safeQrBase64`) são EXECUTADAS em
 *     Node, quando disponível (senão o teste é marcado como skipped, não como verde).
 *
 * O que continua sem prova: que o navegador renderiza o QR/link corretamente e que um
 * `javascript:`/HTML vindo do gateway não é interpretado no DOM de verdade.
 */
class CheckoutDomSafetyTest extends TestCase
{
    private function checkoutSource(): string
    {
        return (string) file_get_contents(resource_path('views/livewire/tenant/checkout.blade.php'));
    }

    public function test_checkout_never_uses_html_string_sinks(): void
    {
        $source = $this->checkoutSource();

        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'createContextualFragment'] as $sink) {
            $this->assertStringNotContainsString($sink, $source, "Sink de HTML [{$sink}] no checkout: dado do gateway poderia ser interpretado como HTML.");
        }
    }

    public function test_box_is_cleared_with_replace_children(): void
    {
        $this->assertStringContainsString('box.replaceChildren()', $this->checkoutSource());
    }

    public function test_gateway_ticket_url_must_pass_the_https_check_before_becoming_a_link(): void
    {
        $source = $this->checkoutSource();

        // o valor cru do gateway nunca chega em href
        $this->assertDoesNotMatchRegularExpression('/\.href\s*=\s*ticket\b/', $source);
        $this->assertMatchesRegularExpression('/const\s+safeTicket\s*=\s*safeHttpsUrl\(\s*td\.ticket_url\s*\)/', $source);
        $this->assertMatchesRegularExpression('/a\.href\s*=\s*safeTicket\b/', $source);
    }

    public function test_gateway_qr_image_must_pass_the_base64_check_before_becoming_an_img_src(): void
    {
        $source = $this->checkoutSource();

        $this->assertMatchesRegularExpression('/const\s+qrBase64\s*=\s*safeQrBase64\(\s*td\.qr_code_base64\s*\)/', $source);
        $this->assertStringContainsString("img.src = 'data:image/png;base64,' + qrBase64", $source);
    }

    public function test_gateway_text_only_reaches_the_dom_through_text_or_value(): void
    {
        $source = $this->checkoutSource();

        // `qr` (copia e cola) é gravado por `.value` (input readonly); nunca concatenado em markup
        $this->assertStringContainsString('input.value = qr;', $source);
        $this->assertDoesNotMatchRegularExpression('/[\'"`]<[a-z][^\'"`]*[\'"`]\s*\+\s*(qr|ticket|qrBase64|td\b)/i', $source);
    }

    public function test_pix_qr_received_by_polling_is_rendered_only_through_the_safe_renderer(): void
    {
        $source = $this->checkoutSource();

        // O QR que chega pelo polling só é copiado para `panel.td` (três campos nomeados) e desenhado
        // por `renderPanel`, que valida de novo com safeQrBase64/safeHttpsUrl; nunca vai direto ao DOM.
        $this->assertMatchesRegularExpression('/panel\.td\s*=\s*\{\s*qr_code:\s*json\.pix\.qr_code,\s*qr_code_base64:\s*json\.pix\.qr_code_base64,\s*ticket_url:\s*json\.pix\.ticket_url,\s*\}/', $source);
        $this->assertDoesNotMatchRegularExpression('/(src|href)\s*=\s*json\.pix/', $source);
    }

    public function test_device_id_script_is_the_mercado_pago_one_and_only_on_checkout(): void
    {
        $source = $this->checkoutSource();

        $this->assertStringContainsString('<script src="https://www.mercadopago.com/v2/security.js" view="checkout"></script>', $source);
        $this->assertStringContainsString('window.MP_DEVICE_SESSION_ID', $source);

        // Em nenhuma outra view do storefront.
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS));
        $scanned = 0;

        foreach ($files as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php') || str_ends_with($file->getPathname(), 'livewire/tenant/checkout.blade.php')) {
                continue;
            }

            $scanned++;
            $this->assertStringNotContainsString('mercadopago.com/v2/security.js', (string) file_get_contents($file->getPathname()), $file->getPathname());
        }

        $this->assertGreaterThan(10, $scanned);
    }

    public function test_validation_helpers_behave_correctly_when_executed(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('Node não está disponível neste ambiente: helpers JS não executados (só análise estática).');
        }

        $this->assertSame(1, preg_match('#// <safe-gateway-values>(.*?)// </safe-gateway-values>#s', $this->checkoutSource(), $m), 'bloco de helpers não encontrado');

        $script = $m[1].<<<'JS'

        const assert = require('assert');
        assert.strictEqual(safeHttpsUrl('https://www.mercadopago.com.br/ticket?id=1'), 'https://www.mercadopago.com.br/ticket?id=1');
        for (const bad of ['javascript:alert(1)', 'JaVaScRiPt:alert(1)', ' javascript:alert(1)', 'data:text/html,<script>alert(1)</script>',
                           'http://insecure.example/x', '//evil.example/x', '/relative', 'vbscript:x', 'https://', 'not a url', '', null, undefined, 42, {}]) {
            assert.strictEqual(safeHttpsUrl(bad), null, 'deveria rejeitar: ' + String(bad));
        }
        assert.strictEqual(safeQrBase64('iVBORw0KGgo='), 'iVBORw0KGgo=');
        for (const bad of ['" onerror="alert(1)', 'abc"><script>', 'a b', 'data:image/png;base64,AAAA', '', null, undefined, 42]) {
            assert.strictEqual(safeQrBase64(bad), '', 'deveria rejeitar: ' + String(bad));
        }
        console.log('ok');
        JS;

        $result = Process::input($script)->run([$node, '-']);

        $this->assertTrue($result->successful(), $result->errorOutput().$result->output());
        $this->assertSame('ok', trim($result->output()));
    }

    // ---------------------------------------------------------------- fluxo reformulado (PIX direto + Brick de cartão)

    public function test_the_script_builds_every_screen_with_text_nodes_and_never_interpolates_server_data_into_markup(): void
    {
        $source = $this->checkoutSource();

        // Mensagens do servidor e estados: sempre `textContent` (via `el(tag, class, text)`/`box.textContent`).
        $this->assertStringContainsString('box.textContent = message;', $source);
        $this->assertStringContainsString('node.textContent = text;', $source);
        // O nº do pedido e o valor viram texto: nunca atributo ou markup.
        $this->assertStringContainsString("'Pedido #' + String(panel.orderNumber)", $source);
        $this->assertStringContainsString('formatBrl(panel.amount)', $source);
        // O link "Voltar à loja" usa a URL da rota (constante do Blade), não dado do gateway.
        $this->assertMatchesRegularExpression('/a\.href\s*=\s*HOME_URL\b/', $source);
    }

    public function test_the_card_token_and_form_data_are_never_logged_stored_or_put_in_the_url(): void
    {
        $source = $this->checkoutSource();

        $this->assertDoesNotMatchRegularExpression('/console\.\w+\([^)]*(formData|raw|token|card)/i', $source);
        foreach (['localStorage', 'sessionStorage', 'document.cookie', 'indexedDB'] as $sink) {
            $this->assertStringNotContainsString($sink, $source);
        }
        // Os dados do cartão vão no corpo do POST (JSON) ao NOSSO servidor; nunca na query string.
        $this->assertStringNotContainsString('?token=', $source);
        // Depois da cobrança aceita os campos do cartão são desmontados.
        $this->assertMatchesRegularExpression('/unmountBrick\(\);\s*\/\/ some com os campos do cartão/', $source);
    }

    public function test_only_the_card_brick_is_used_with_its_own_button_hidden_and_a_single_choice_of_method(): void
    {
        $source = $this->checkoutSource();

        // Brick só de cartão, sem o Payment Brick (que trazia a 2ª lista de meios e o e-mail de novo).
        $this->assertStringContainsString("create('cardPayment'", $source);
        $this->assertDoesNotMatchRegularExpression("/create\(\s*['\"]payment['\"]/", $source);
        // O botão do Brick é escondido: o único botão é o da página (`getFormData()`).
        $this->assertStringContainsString('hidePaymentButton: true', $source);
        $this->assertStringContainsString('cardBrick.getFormData()', $source);
        // O e-mail já informado é entregue ao Brick (que o esconde) e o tipo escolhido restringe o Brick.
        $this->assertStringContainsString('payer: { email: payload.payerEmail }', $source);
        $this->assertStringContainsString('types: { excluded:', $source);
    }

    public function test_the_polling_is_bounded_and_talks_only_to_the_status_route_of_this_tenant(): void
    {
        $source = $this->checkoutSource();

        $this->assertStringContainsString("route('tenant.checkout.status'", $source);
        // Cartão: até 10 min, ~4 s com backoff até 12 s.
        $this->assertStringContainsString('POLL_LIMIT_MS = 10 * 60 * 1000', $source);
        $this->assertStringContainsString('Date.now() > poll.deadline', $source);
        $this->assertStringContainsString('Math.pow(1.15, poll.attempt)', $source);
        // Pix: ~3 s com leve backoff até 5 s e só até a janela + folga (nunca os 10 min do cartão).
        $this->assertStringContainsString('PIX_POLL_BASE_MS = 3000', $source);
        $this->assertStringContainsString('PIX_POLL_MAX_DELAY_MS = 5000', $source);
        $this->assertStringContainsString('PIX_POLL_GRACE_MS = 90 * 1000', $source);
        $this->assertStringContainsString('Math.pow(1.1, poll.attempt)', $source);
        $this->assertStringContainsString('poll.deadline = Date.now() + (panel.isPix ? pixPollBudgetMs() : POLL_LIMIT_MS);', $source);
        // Só duas rotas do próprio tenant: nada de cancelar pedido pelo navegador.
        $this->assertSame(2, preg_match_all('/\bfetch\(/', $source));
        $this->assertStringContainsString('fetch(PROCESS_URL', $source);
        $this->assertStringContainsString('fetch(STATUS_URL', $source);
        // Backoff e respeito ao 429.
        $this->assertStringContainsString('response.status === 429', $source);
        // Para nos estados finais.
        $this->assertMatchesRegularExpression("/status === 'paid' \|\| status === 'review' \|\| status === 'cancelled'/", $source);
    }

    public function test_card_form_data_normalizer_keeps_only_what_the_server_whitelists_when_executed(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('Node não está disponível neste ambiente: normalizador não executado.');
        }

        $this->assertSame(1, preg_match('#// <card-form-data>(.*?)// </card-form-data>#s', $this->checkoutSource(), $m), 'bloco do normalizador não encontrado');

        $script = $m[1].<<<'JS'

        const assert = require('assert');
        // snake_case (formato do onSubmit do Brick)
        assert.deepStrictEqual(
            normalizeCardFormData({ token: 't1', payment_method_id: 'visa', issuer_id: '24', installments: 6, transaction_amount: 0.01, payer: { email: 'x@y.z', identification: { type: 'CPF', number: '123' } }, extra: 1 }),
            { token: 't1', payment_method_id: 'visa', issuer_id: '24', installments: 1, payer: { identification: { type: 'CPF', number: '123' } } });
        // camelCase (formato do getFormData())
        assert.deepStrictEqual(
            normalizeCardFormData({ token: 't2', paymentMethodId: 'master', issuerId: '3', identificationType: 'CPF', identificationNumber: '999' }),
            { token: 't2', payment_method_id: 'master', issuer_id: '3', installments: 1, payer: { identification: { type: 'CPF', number: '999' } } });
        for (const bad of [null, undefined, 'x', 42, {}, { token: '' }, { token: 7 }]) {
            assert.strictEqual(normalizeCardFormData(bad), null, 'deveria rejeitar: ' + JSON.stringify(bad));
        }
        console.log('ok');
        JS;

        $result = Process::input($script)->run([$node, '-']);

        $this->assertTrue($result->successful(), $result->errorOutput().$result->output());
        $this->assertSame('ok', trim($result->output()));
    }

    // ---------------------------------------------------------------- modal do Pix e contador

    public function test_closing_the_modal_only_hides_it_and_the_zero_of_the_countdown_never_declares_expiry_by_itself(): void
    {
        $source = $this->checkoutSource();

        // "Fechar" é o `data-bs-dismiss` do Bootstrap (esconde); o script só reage a `hidden.bs.modal`
        // para mostrar o aviso, e NUNCA chama o servidor para cancelar.
        $this->assertStringContainsString("node.addEventListener('hidden.bs.modal'", $source);
        $this->assertStringContainsString("'Reabrir QR Code'", $source);
        $this->assertDoesNotMatchRegularExpression('/\.dispose\(\)|\.hide\(\)/', $source);

        // O zero do contador pede "Verificando o pagamento…" e consulta já; só o servidor decide.
        $this->assertSame(1, preg_match('/function onCountdownZero\(\) \{(.*?)\n    \}\n/s', $source, $m), 'onCountdownZero não encontrada');
        $this->assertStringContainsString('panel.verifying = true;', $m[1]);
        $this->assertStringContainsString('schedule(0)', $m[1]);
        $this->assertStringContainsString('startPolling(0)', $m[1]);
        $this->assertStringNotContainsString("'expired'", $m[1]);
        $this->assertStringNotContainsString('cancelled', $m[1]);
        $this->assertStringContainsString("'Verificando o pagamento…'", $source);

        // "Expirado" só aparece quando o SERVIDOR responde `cancelled` com `reason: expired`.
        $this->assertStringContainsString("renderPanel(status === 'cancelled' && panel.isPix && json.reason === 'expired' ? 'expired' : status);", $source);
        $this->assertSame(1, substr_count($source, "renderPanel('expired')") + substr_count($source, "'expired' : status"));
        $this->assertStringContainsString('O tempo para pagar o Pix acabou. O pedido foi cancelado. Tente novamente.', $source);
    }

    public function test_the_deadline_used_by_the_countdown_is_the_servers_never_a_naive_client_side_five_minutes(): void
    {
        $source = $this->checkoutSource();

        $this->assertDoesNotMatchRegularExpression('/Date\.now\(\)\s*\+\s*(300|5\s*\*\s*60)\s*\*?\s*1000/', $source);
        $this->assertDoesNotMatchRegularExpression('/PIX_WINDOW_SECONDS\s*\*\s*1000\s*\)\s*;?\s*\n\s*countdown/', $source);
        // O prazo vem do `expires_at` do servidor (resposta da cobrança, do status e da tela restaurada)
        // e o relógio é corrigido pelo `server_now` de cada resposta.
        $this->assertStringContainsString('source.expires_at', $source);
        $this->assertStringContainsString('clockOffsetMs(source.server_now, Date.now())', $source);
        $this->assertStringContainsString('remainingMs(panel.expiresAt, panel.offsetMs, Date.now())', $source);
        $this->assertStringContainsString('applyTiming(AWAITING);', $source);
        $this->assertStringContainsString('applyTiming(json);', $source);
    }

    public function test_the_countdown_helpers_behave_correctly_when_executed(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('Node não está disponível neste ambiente: contador não executado (só análise estática).');
        }

        $this->assertSame(1, preg_match('#// <pix-countdown>(.*?)// </pix-countdown>#s', $this->checkoutSource(), $m), 'bloco do contador não encontrado');

        $script = $m[1].<<<'JS'

        const assert = require('assert');

        // MM:SS, com zero à esquerda; nunca negativo; sempre arredonda para CIMA (00:00 só no zero real).
        assert.strictEqual(formatCountdown(300), '05:00');
        assert.strictEqual(formatCountdown(299), '04:59');
        assert.strictEqual(formatCountdown(61), '01:01');
        assert.strictEqual(formatCountdown(60), '01:00');
        assert.strictEqual(formatCountdown(9), '00:09');
        assert.strictEqual(formatCountdown(0.2), '00:01');
        assert.strictEqual(formatCountdown(0), '00:00');
        assert.strictEqual(formatCountdown(-5), '00:00');
        assert.strictEqual(formatCountdown(NaN), '00:00');
        assert.strictEqual(formatCountdown('abc'), '00:00');
        assert.strictEqual(formatCountdown(undefined), '00:00');
        assert.strictEqual(formatCountdown(3599), '59:59');

        const expires = '2026-10-23T12:05:00Z';
        const at = (iso) => Date.parse(iso);

        // Relógio do cliente CERTO: offset 0, faltam 300 s no instante da cobrança.
        const offsetOk = clockOffsetMs('2026-10-23T12:00:00.000Z', at('2026-10-23T12:00:00.000Z'));
        assert.strictEqual(offsetOk, 0);
        assert.strictEqual(remainingMs(expires, offsetOk, at('2026-10-23T12:00:00.000Z')), 300000);
        assert.strictEqual(remainingMs(expires, offsetOk, at('2026-10-23T12:04:59.500Z')), 500);

        // Relógio do cliente ADIANTADO 1 h: sem a correção o contador começaria em zero; com ela, 5:00.
        const clientAhead = at('2026-10-23T13:00:00.000Z');
        const offsetAhead = clockOffsetMs('2026-10-23T12:00:00.000Z', clientAhead);
        assert.strictEqual(offsetAhead, -3600000);
        assert.strictEqual(remainingMs(expires, offsetAhead, clientAhead), 300000);
        assert.strictEqual(remainingMs(expires, 0, clientAhead), 0, 'sem correção o relógio errado zeraria o contador');
        // 90 s depois (relógio do cliente continua andando): faltam 210 s => 03:30.
        assert.strictEqual(formatCountdown(Math.ceil(remainingMs(expires, offsetAhead, clientAhead + 90000) / 1000)), '03:30');

        // Relógio ATRASADO 10 min: o contador não passa de 5:00 nem "volta" ao recarregar.
        const clientBehind = at('2026-10-23T11:50:00.000Z');
        const offsetBehind = clockOffsetMs('2026-10-23T12:00:00.000Z', clientBehind);
        assert.strictEqual(remainingMs(expires, offsetBehind, clientBehind), 300000);
        // Recarregou 100 s depois: o servidor devolve o MESMO prazo e `server_now` novo => faltam 200 s.
        const reloaded = clockOffsetMs('2026-10-23T12:01:40.000Z', at('2026-10-23T11:51:40.000Z'));
        assert.strictEqual(remainingMs(expires, reloaded, at('2026-10-23T11:51:40.000Z')), 200000);

        // Clamp em 00:00: depois do prazo nunca fica negativo.
        assert.strictEqual(remainingMs(expires, 0, at('2026-10-23T12:05:00.000Z')), 0);
        assert.strictEqual(remainingMs(expires, 0, at('2026-10-23T12:30:00.000Z')), 0);
        assert.strictEqual(formatCountdown(remainingMs(expires, 0, at('2026-10-23T12:30:00.000Z')) / 1000), '00:00');

        // Entradas inválidas: sem prazo válido não há contador (null), e offset inválido vira 0.
        for (const bad of [null, undefined, '', 'amanhã', 42, {}]) {
            assert.strictEqual(remainingMs(bad, 0, Date.now()), null, 'prazo inválido: ' + String(bad));
        }
        for (const bad of [null, undefined, '', 'ontem', 42, {}]) {
            assert.strictEqual(clockOffsetMs(bad, Date.now()), null, 'server_now inválido: ' + String(bad));
        }
        assert.strictEqual(remainingMs(expires, NaN, at('2026-10-23T12:00:00.000Z')), 300000);

        console.log('ok');
        JS;

        $result = Process::input($script)->run([$node, '-']);

        $this->assertTrue($result->successful(), $result->errorOutput().$result->output());
        $this->assertSame('ok', trim($result->output()));
    }
}
