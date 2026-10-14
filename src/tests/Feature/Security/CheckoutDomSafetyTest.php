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
}
