<?php

namespace Tests\Feature\Tenancy;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Critério #3 da Sprint 06, parte estrutural: o isolamento não pode regredir por um model novo
 * esquecido ou por um opt-out/SQL cru novo. As exceções existentes ficam numa lista FECHADA e
 * justificada; qualquer uma nova derruba este teste e obriga a decidir de propósito.
 */
class TenantIsolationAuditTest extends TestCase
{
    use RefreshDatabase;

    /** Models com coluna `tenant_id` que NÃO usam a trait, e o motivo. */
    private const WITHOUT_TRAIT = [
        'TenantCounter' => 'chave composta (tenant_id, key): só é gravado por TenantCounter::nextValue, com tenant explícito e sem instância',
        'User' => 'tenant_id é nullable: o admin de plataforma não pertence a tenant; o acesso é pelos guards e policies',
    ];

    /** Opt-outs do escopo em app/ (fora da definição na trait), com a justificativa. */
    private const SCOPE_OPT_OUTS = [
        'app/Services/PaymentWebhookProcessor.php' => 'localizar o dono do pagamento pelo id do gateway: o webhook chega sem tenant no path',
        'app/Models/Concerns/GeneratesUniqueSlug.php' => 'filtra explicitamente pelo tenant do PRÓPRIO model logo em seguida',
    ];

    /** SQL cru (`DB::table`...) em app/ e o motivo de ser seguro. */
    private const RAW_SQL = [
        'app/Models/Showcase.php' => 'trava a linha de `tenants` (não é tabela de tenant) para limitar vitrines',
        'app/Services/PaymentWebhookProcessor.php' => 'insertOrIgnore de `payment_webhook_events` com tenant_id explícito do pagamento',
    ];

    /** @return list<string> caminhos relativos a src/ dos arquivos PHP de app/ */
    private function appFiles(): array
    {
        $files = [];
        foreach ((new Finder)->files()->in(base_path('app'))->name('*.php') as $file) {
            $files[] = 'app/'.str_replace('\\', '/', $file->getRelativePathname());
        }

        return $files;
    }

    private function filesMatching(string $pattern): array
    {
        $hits = [];
        foreach ($this->appFiles() as $path) {
            $code = (string) file_get_contents(base_path($path));
            // ignora comentários/docblocks: só código executável conta
            $code = preg_replace('#/\*.*?\*/#s', '', $code) ?? $code;
            $code = preg_replace('#^\s*//.*$#m', '', $code) ?? $code;

            if (preg_match($pattern, $code) === 1) {
                $hits[] = $path;
            }
        }
        sort($hits);

        return $hits;
    }

    public function test_every_model_with_a_tenant_id_column_uses_the_tenant_trait_unless_justified(): void
    {
        $missing = [];

        foreach ($this->appFiles() as $path) {
            if (! str_starts_with($path, 'app/Models/') || str_contains($path, '/Concerns/') || str_contains($path, '/Scopes/')) {
                continue;
            }

            $class = 'App\\Models\\'.str_replace(['app/Models/', '.php', '/'], ['', '', '\\'], $path);

            if (! class_exists($class) || ! is_subclass_of($class, \Illuminate\Database\Eloquent\Model::class)) {
                continue;
            }

            $table = (new $class)->getTable();

            if (Schema::hasColumn($table, 'tenant_id') && ! in_array(BelongsToTenant::class, class_uses_recursive($class), true)) {
                $missing[] = class_basename($class);
            }
        }

        sort($missing);
        $allowed = array_keys(self::WITHOUT_TRAIT);
        sort($allowed);

        $this->assertSame($allowed, $missing, 'model novo com tenant_id sem BelongsToTenant (ou exceção que deixou de ser necessária)');
    }

    public function test_the_scope_opt_outs_in_app_are_a_closed_justified_list(): void
    {
        $found = array_values(array_diff(
            $this->filesMatching('/(::|->)withoutTenantScope\(|withoutGlobalScope\(\s*TenantScope|withoutGlobalScopes\(/'),
            ['app/Models/Concerns/BelongsToTenant.php'],
        ));

        $allowed = array_keys(self::SCOPE_OPT_OUTS);
        sort($allowed);

        $this->assertSame($allowed, $found, 'opt-out novo do escopo de tenant: justifique em SCOPE_OPT_OUTS (ou remova)');
    }

    public function test_raw_sql_in_app_is_a_closed_justified_list(): void
    {
        $found = $this->filesMatching('/DB::(table|select|statement|insert|update|delete|unprepared)\(/');

        $allowed = array_keys(self::RAW_SQL);
        sort($allowed);

        $this->assertSame($allowed, $found, 'SQL cru novo em app/: ele ignora o escopo de tenant; justifique em RAW_SQL');
    }

    public function test_the_write_guard_and_the_scoped_rehydration_exist_on_the_trait(): void
    {
        $trait = (string) file_get_contents(base_path('app/Models/Concerns/BelongsToTenant.php'));

        $this->assertStringContainsString('assertWritableInCurrentTenant', $trait);
        $this->assertStringContainsString('newQueryForRestoration', $trait);
        $this->assertStringContainsString("['saving', 'deleting', 'restoring']", $trait);
    }
}
