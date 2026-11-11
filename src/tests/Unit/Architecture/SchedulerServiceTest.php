<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * O estoque de um Pix vencido só volta se o agendador rodar (a varredura é `schedule`d a cada
 * minuto). Sem um processo de `schedule:run`/`schedule:work`, o prazo só é imposto quando o próprio
 * cliente consulta o status. O compose de desenvolvimento tem de subir esse processo.
 */
class SchedulerServiceTest extends TestCase
{
    private function compose(): string
    {
        $path = dirname(__DIR__, 4).'/docker-compose.yml';

        // O container de desenvolvimento só monta `src/`: o compose (na raiz do repositório) existe
        // no host e no CI, onde este teste roda.
        if (! is_file($path)) {
            $this->markTestSkipped('docker-compose.yml fora do alcance (container monta só src/).');
        }

        return (string) file_get_contents($path);
    }

    public function test_the_compose_runs_the_laravel_scheduler(): void
    {
        $compose = $this->compose();

        $this->assertStringContainsString('scheduler:', $compose);
        $this->assertStringContainsString('php artisan schedule:work', $compose);
    }

    public function test_the_scheduler_reuses_the_app_image_and_restarts(): void
    {
        $compose = $this->compose();
        $service = substr($compose, (int) strpos($compose, '  scheduler:'));
        $service = substr($service, 0, (int) strpos($service, "\n  nginx:") ?: strlen($service));

        $this->assertStringContainsString('image: order-web-app', $service);
        $this->assertStringContainsString('restart: unless-stopped', $service);
    }
}
