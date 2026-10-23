<?php

namespace Tests\Unit\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Guarda do tema claro/escuro: nenhuma view usa classe de COR FIXA (`bg-white`, `bg-light`, `text-dark`...).
 *
 * Por que: `bg-light`/`bg-white` continuam claros com `data-bs-theme="dark"` enquanto o texto herdado
 * passa a ser claro (card "Preview do Checkout" branco com texto quase invisível no admin escuro), e
 * `text-dark`/`text-white` fazem o inverso. As variáveis semânticas do Bootstrap 5.3 (`bg-body`,
 * `bg-body-tertiary`, `bg-body-secondary`, `text-body`, `border`...) trocam junto com o tema e dão o MESMO
 * visual no claro (`bg-light` == `bg-body-tertiary` == #f8f9fa; `bg-white` == `bg-body` == #fff;
 * `text-dark` == `text-body` == #212529).
 *
 * Varre o código-fonte (não precisa de app, banco nem build). Comentários Blade/HTML são ignorados (podem
 * citar a classe proibida para explicar a regra).
 */
class ThemeClassesTest extends TestCase
{
    /** Classes de cor fixa proibidas (token inteiro: `text-bg-light` é um token à parte). */
    private const FIXED = [
        'bg-white', 'bg-light', 'bg-dark', 'bg-black',
        'text-white', 'text-light', 'text-dark', 'text-black', 'text-white-50', 'text-black-50',
        'border-white', 'border-light', 'border-dark', 'border-black',
        'table-light', 'table-dark',
        'btn-light', 'btn-dark', 'btn-outline-light', 'btn-outline-dark',
        'list-group-item-light', 'list-group-item-dark',
        'text-bg-light', 'text-bg-dark',
        'alert-light', 'alert-dark',
        'navbar-light', 'navbar-dark', 'navbar-expand-light',
        'link-light', 'link-dark',
    ];

    /**
     * Exceções, por view e classe, com o número EXATO de ocorrências e o porquê. Cada uma é um fundo/texto
     * que precisa ser claro/escuro INDEPENDENTE do tema. Não acrescente entradas sem justificar aqui.
     *
     * @var array<string, array<string, array{int, string}>>
     */
    private const ALLOWED = [
        // QR code do PIX (DOM criado no <script> do checkout). Leitores de QR exigem módulos escuros sobre
        // fundo claro com margem ("quiet zone"): o fundo branco é requisito funcional, não tema.
        'livewire/tenant/checkout.blade.php' => [
            'bg-white' => [1, 'fundo (quiet zone) do QR code do PIX: precisa ser branco em qualquer tema'],
        ],
        // Miniaturas de logo e favicon em Personalização: mostradas sobre branco como aparecerão no header
        // da loja (sempre claro). Um logo PNG transparente com texto escuro some sobre o fundo do admin escuro.
        'livewire/admin/tenant/customization.blade.php' => [
            'bg-white' => [2, 'miniatura do logo/favicon sobre o fundo claro do header da loja'],
        ],
    ];

    public function test_views_do_not_use_fixed_light_or_dark_color_classes(): void
    {
        $offenders = [];
        $seen = [];

        foreach ($this->views() as $relative => $content) {
            foreach (self::FIXED as $class) {
                $count = preg_match_all($this->tokenPattern($class), $this->withoutComments($content));
                if ($count === 0) {
                    continue;
                }

                $seen[$relative][$class] = $count;
                $allowed = self::ALLOWED[$relative][$class][0] ?? 0;

                if ($count !== $allowed) {
                    $offenders[] = "{$relative}: `{$class}` x{$count} (permitido {$allowed})";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Classe de cor fixa quebra o tema claro/escuro. Use bg-body / bg-body-tertiary / bg-body-secondary / text-body.\n"
                .implode("\n", $offenders),
        );

        // a allowlist não pode ficar velha: toda exceção tem de ainda existir
        foreach (self::ALLOWED as $relative => $classes) {
            foreach ($classes as $class => [$count, $why]) {
                $this->assertSame(
                    $count,
                    $seen[$relative][$class] ?? 0,
                    "Exceção obsoleta em {$relative} (`{$class}`, {$why}): remova-a da allowlist.",
                );
            }
        }
    }

    public function test_php_code_does_not_emit_fixed_color_classes(): void
    {
        $offenders = [];

        foreach ($this->files($this->root().'/app') as $path => $content) {
            if (! str_ends_with($path, '.php')) {
                continue;
            }

            foreach (self::FIXED as $class) {
                if (preg_match($this->tokenPattern($class), $this->withoutComments($content))) {
                    $offenders[] = substr($path, strlen($this->root()) + 1)." (`{$class}`)";
                }
            }
        }

        $this->assertSame([], $offenders, "Classe de cor fixa montada em PHP (enum/componente):\n".implode("\n", $offenders));
    }

    public function test_every_allowlist_entry_explains_itself(): void
    {
        foreach (self::ALLOWED as $relative => $classes) {
            foreach ($classes as $class => [$count, $why]) {
                $this->assertGreaterThan(0, $count);
                $this->assertGreaterThan(20, strlen($why), "{$relative} `{$class}` sem justificativa.");
            }
        }
    }

    public function test_css_sources_do_not_hardcode_bootstrap_blue(): void
    {
        $offenders = [];

        foreach ($this->files($this->root().'/resources/css') as $path => $content) {
            // comentários podem citar o azul para explicar o problema
            if (preg_match_all(self::BLUE, preg_replace('#/\*.*?\*/#s', '', $content) ?? $content, $m)) {
                $offenders[] = substr($path, strlen($this->root()) + 1).': '.implode(', ', array_unique($m[0]));
            }
        }

        $this->assertSame([], $offenders, "Azul padrão do Bootstrap hardcoded no CSS do app (use var(--bs-primary)/--tenant-primary):\n".implode("\n", $offenders));
    }

    /** A paleta azul do Bootstrap 5.3 (claro e escuro) em hex (também URL-encoded em data-URI) e a tripla de rgba(). */
    public const BLUE = '/(?:%23|#)(?:0d6efd|0a58ca|0a53be|0b5ed7|0c63e4|86b7fe|6ea8fe|3d8bfd|052c65|031633|cfe2ff|9ec5fe|b6d4fe|e7f1ff|084298)\b|\b(?:13,\s*110,\s*253|49,\s*132,\s*253)\b/i';

    private function tokenPattern(string $class): string
    {
        // token inteiro: não casa `text-bg-light` ao procurar `bg-light`, nem `bg-white-50`
        return '/(?<![\w-])'.preg_quote($class, '/').'(?![\w-])/';
    }

    private function withoutComments(string $content): string
    {
        $content = preg_replace('/\{\{--.*?--\}\}/s', '', $content) ?? $content;

        return preg_replace('/<!--.*?-->/s', '', $content) ?? $content;
    }

    /** @return array<string, string> caminho relativo a resources/views => conteúdo */
    private function views(): array
    {
        $base = $this->root().'/resources/views';
        $views = [];

        foreach ($this->files($base) as $path => $content) {
            if (str_ends_with($path, '.blade.php')) {
                $views[substr($path, strlen($base) + 1)] = $content;
            }
        }

        $this->assertNotEmpty($views);

        return $views;
    }

    /** @return iterable<string, string> */
    private function files(string $dir): iterable
    {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

        foreach ($it as $file) {
            if ($file->isFile()) {
                yield $file->getPathname() => file_get_contents($file->getPathname());
            }
        }
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
