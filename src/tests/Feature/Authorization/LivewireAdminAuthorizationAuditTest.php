<?php

namespace Tests\Feature\Authorization;

use Livewire\Component;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Auditoria ESTRUTURAL (E1.6 / SEC-004), no estilo de RouteMiddlewareAuditTest.
 *
 * Todo método público declarado na própria classe de `app/Livewire/Admin/**` é
 * chamável do cliente (`POST /livewire/update`). Portanto todo método assim
 * precisa chamar `authorize(...)` / `Gate::` (ou delegar a um helper
 * `authorize*` do próprio componente) — a menos que esteja na allowlist
 * abaixo, COM justificativa. Adicionar uma action sem autorização faz este
 * teste falhar.
 */
class LivewireAdminAuthorizationAuditTest extends TestCase
{
    /**
     * Métodos públicos isentos, com o porquê. Chave: `Classe::método`.
     *
     * @var array<string, string>
     */
    private const ALLOWLIST = [
        'App\Livewire\Admin\Tenant\PaymentSettings::isMercadoPagoIntegrated' => 'Leitura pura de estado já autorizado (mount/hydrate); devolve bool, não muta nem consulta o banco.',
    ];

    /** Padrões de nome isentos: ciclo de vida e computed properties (só leitura, sob mount/hydrate autorizados). */
    private const EXEMPT_NAME_PATTERNS = [
        '/^(mount|render|boot|booted|hydrate|dehydrate|updating|updated|rendering|rendered)/',
        '/^get.+Property$/',
    ];

    /**
     * @return list<class-string<Component>>
     */
    private function adminComponents(): array
    {
        $classes = [];
        $base = app_path('Livewire/Admin');

        foreach ((new Finder)->files()->in($base)->name('*.php') as $file) {
            $relative = str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            $class = 'App\\Livewire\\Admin\\'.$relative;

            if (class_exists($class) && is_subclass_of($class, Component::class)) {
                $classes[] = $class;
            }
        }

        $this->assertNotEmpty($classes, 'Nenhum componente de admin encontrado.');

        return $classes;
    }

    private function methodSource(ReflectionMethod $method): string
    {
        $file = $method->getFileName();
        $this->assertNotFalse($file);

        $lines = file($file);
        $this->assertNotFalse($lines);

        return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }

    private function isExempt(string $class, string $name): bool
    {
        if (isset(self::ALLOWLIST["{$class}::{$name}"])) {
            return true;
        }

        foreach (self::EXEMPT_NAME_PATTERNS as $pattern) {
            if (preg_match($pattern, $name) === 1) {
                return true;
            }
        }

        return false;
    }

    public function test_every_public_action_of_admin_components_authorizes(): void
    {
        $violations = [];

        foreach ($this->adminComponents() as $class) {
            $reflection = new ReflectionClass($class);

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                // Métodos vindos de traits (ex.: `WithFileUploads::_startUpload`, framework
                // protegido por URL assinada + hydrate autorizado) têm outro arquivo de origem.
                if ($method->getDeclaringClass()->getName() !== $class
                    || $method->getFileName() !== $reflection->getFileName()
                    || $method->isStatic() || $method->isConstructor()) {
                    continue;
                }

                if ($this->isExempt($class, $method->getName())) {
                    continue;
                }

                if (preg_match('/(->authorize\(|->authorize[A-Z]\w*\(|Gate::(authorize|allows|denies|inspect|check)\()/', $this->methodSource($method)) !== 1) {
                    $violations[] = "{$class}::{$method->getName()}()";
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            'Actions públicas de admin SEM autorização (SEC-004). Chame $this->authorize(...) no método, '
            ."ou (só se for leitura pura) inclua na ALLOWLIST com justificativa:\n - ".implode("\n - ", $violations)
        );
    }

    public function test_allowlist_has_no_stale_entries(): void
    {
        foreach (array_keys(self::ALLOWLIST) as $entry) {
            [$class, $method] = explode('::', $entry);

            $this->assertTrue(
                class_exists($class) && method_exists($class, $method),
                "Entrada obsoleta na allowlist: {$entry}"
            );
        }
    }

    public function test_every_admin_component_authorizes_view_on_mount_and_hydrate(): void
    {
        foreach ($this->adminComponents() as $class) {
            $reflection = new ReflectionClass($class);

            $this->assertTrue(
                $reflection->hasMethod('mount')
                && preg_match('/authorize/', $this->methodSource($reflection->getMethod('mount'))) === 1,
                "{$class}::mount() deve autorizar a visualização."
            );

            $this->assertContains(
                \App\Livewire\Admin\Concerns\AuthorizesOnHydrate::class,
                class_uses_recursive($class),
                "{$class} deve usar AuthorizesOnHydrate (re-autoriza a cada update do Livewire)."
            );
        }
    }
}
