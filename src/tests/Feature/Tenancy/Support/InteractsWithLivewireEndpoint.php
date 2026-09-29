<?php

namespace Tests\Feature\Tenancy\Support;

use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Helpers para exercitar o ENDPOINT HTTP REAL do Livewire (`POST /livewire/update`).
 *
 * `Livewire::test()` NÃO serve para testar segurança: ele desabilita middleware
 * explicitamente (vendor/livewire/livewire/src/Features/SupportTesting/
 * SubsequentRender.php:35 — `temporarilyDisableExceptionHandlingAndMiddleware`).
 * Só batendo no endpoint de verdade é possível observar o que roda (e o que não
 * roda) numa interação subsequente do componente.
 *
 * A trait usa os helpers do TestCase (`get`, `postJson`, `withHeaders`, `fail`).
 * O `@mixin` declara isso para os analisadores de editor — sem ele a trait é
 * analisada isolada da classe que a usa, e `$this->fail()` (que é `never`) não
 * é resolvido, gerando um falso "not all paths return a value".
 *
 * @mixin TestCase
 *
 * @phpstan-require-extends TestCase
 */
trait InteractsWithLivewireEndpoint
{
    /**
     * Snapshot cru (string JSON) de um componente presente no HTML, como o
     * navegador o devolveria no payload de update.
     */
    private function snapshotFor(string $html, string $componentName): string
    {
        preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches);

        foreach ($matches[1] as $raw) {
            $json = html_entity_decode($raw, ENT_QUOTES);
            $decoded = json_decode($json, true);

            if (is_array($decoded) && data_get($decoded, 'memo.name') === $componentName) {
                return $json;
            }
        }

        $this->fail("Snapshot do componente [{$componentName}] não encontrado no HTML.");
    }

    /**
     * Dispara o endpoint HTTP real do Livewire.
     *
     * @param  list<mixed>  $params
     * @param  array<string, mixed>  $updates  propriedades públicas alteradas
     *                                         pelo cliente (`wire:model`)
     */
    private function callLivewire(string $snapshot, string $method, array $params = [], array $updates = []): TestResponse
    {
        return $this->withHeaders(['X-Livewire' => 'true'])->postJson('/livewire/update', [
            '_token' => csrf_token(),
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => $updates,
                'calls' => [[
                    'path' => '',
                    'method' => $method,
                    'params' => $params,
                ]],
            ]],
        ]);
    }

    /**
     * Re-render puro (o que o cliente envia num `$refresh`: nenhuma call).
     */
    private function refreshLivewire(string $snapshot): TestResponse
    {
        return $this->withHeaders(['X-Livewire' => 'true'])->postJson('/livewire/update', [
            '_token' => csrf_token(),
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => [],
                'calls' => [],
            ]],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function returnedData(TestResponse $response): array
    {
        $snapshot = json_decode((string) $response->json('components.0.snapshot'), true);

        return is_array($snapshot) && is_array($snapshot['data'] ?? null)
            ? $snapshot['data']
            : [];
    }

    /**
     * O Livewire serializa arrays como tupla `[valor, {"s":"arr"}]`. Desembrulha
     * recursivamente para poder assertar sobre os dados de verdade.
     */
    private function unwrap(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (count($value) === 2 && array_is_list($value) && is_array($value[1]) && isset($value[1]['s'])) {
            return $this->unwrap($value[0]);
        }

        return array_map(fn (mixed $item) => $this->unwrap($item), $value);
    }
}
