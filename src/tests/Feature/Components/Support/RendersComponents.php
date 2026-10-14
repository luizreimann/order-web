<?php

namespace Tests\Feature\Components\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

/**
 * Renderiza templates Blade com os componentes do design system, sem Vite e (opcionalmente)
 * com erros de validação compartilhados como o middleware `ShareErrorsFromSession` faz.
 */
trait RendersComponents
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string|list<string>>  $errors
     */
    protected function render(string $template, array $data = [], array $errors = []): string
    {
        $this->withoutVite();

        view()->share('errors', (new ViewErrorBag)->put('default', new MessageBag($errors)));

        return Blade::render($template, $data);
    }

    /**
     * Renderiza como se estivesse dentro de um componente Livewire: aí o Livewire injeta
     * marcadores `<!--[if BLOCK]-->` ao redor de @if/@foreach, que NÃO podem cair dentro de
     * uma tag (HTML quebrado). Devolve o HTML já com os marcadores.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string|list<string>>  $errors
     */
    protected function renderInsideLivewire(string $template, array $data = [], array $errors = []): string
    {
        $extend = app(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::class);
        $extend->startLivewireRendering(new class extends \Livewire\Component {});

        try {
            return $this->render($template, $data, $errors);
        } finally {
            $extend->endLivewireRendering();
        }
    }

    protected function assertNoMarkerInsideTags(string $html): void
    {
        $this->assertDoesNotMatchRegularExpression('/<[a-zA-Z][^<>]*<!--/', $html, "marcador do Livewire dentro de uma tag:\n{$html}");
    }

    protected function xpath(string $html): DOMXPath
    {
        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><body>'.$html.'</body>');
        libxml_clear_errors();

        return new DOMXPath($doc);
    }

    /** Primeiro elemento que casa com o XPath (falha se não existir). */
    protected function first(string $html, string $query): DOMElement
    {
        $nodes = $this->xpath($html)->query($query);
        $this->assertGreaterThan(0, $nodes->length, "XPath [{$query}] não encontrou nada em:\n{$html}");

        $node = $nodes->item(0);
        $this->assertInstanceOf(DOMElement::class, $node);

        return $node;
    }

    protected function countNodes(string $html, string $query): int
    {
        return $this->xpath($html)->query($query)->length;
    }

    /** @return list<string> */
    protected function classes(DOMElement $el): array
    {
        return array_values(array_filter(preg_split('/\s+/', $el->getAttribute('class'))));
    }

    /** XPath que casa uma classe CSS inteira. */
    protected function hasClass(string $class): string
    {
        return "contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')";
    }
}
