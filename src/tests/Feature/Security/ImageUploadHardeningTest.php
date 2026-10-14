<?php

namespace Tests\Feature\Security;

use App\Enums\Role;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Security\Support\ImageFixtures;
use Tests\Feature\Tenancy\Support\InteractsWithLivewireEndpoint;
use Tests\TestCase;

/**
 * E5.3 (UI-009) — uploads validados pelo CONTEÚDO real, não pelo nome/mime do cliente.
 *
 * Tudo pelo endpoint HTTP real: `POST /livewire/upload-file` (URL assinada) seguido de
 * `POST /livewire/update` com `livewire-file:<hash>` — o mesmo caminho do navegador.
 * Cobre os 3 componentes: Customization (logo/banner/favicon), SeoSettings (og_image)
 * e Products\Form (image).
 */
class ImageUploadHardeningTest extends TestCase
{
    use InteractsWithLivewireEndpoint, RefreshDatabase;

    private Tenant $tenant;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('public');
        Storage::fake('tmp-for-tests'); // disco temporário do Livewire em testes

        $this->tenant = Tenant::factory()->create(['slug' => 'tenant-a']);
        $this->product = Product::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->actingAs(User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => Role::TenantAdmin,
        ]), 'tenant_admin');
    }

    // ------------------------------------------------------------------
    // alvos (os 5 campos de upload)
    // ------------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function targets(): array
    {
        return [
            'customization.logo' => ['logo'],
            'customization.banner' => ['banner'],
            'customization.favicon' => ['favicon'],
            'seo.og_image' => ['og_image'],
            'product.image' => ['image'],
        ];
    }

    /** @return array<string, array{string}> alvos de imagem geral (não favicon) */
    public static function generalTargets(): array
    {
        return array_diff_key(self::targets(), ['customization.favicon' => 1]);
    }

    /**
     * @return array{url: string, component: string, maxKb: int, extra: array<string, mixed>, read: \Closure(): ?string}
     */
    private function target(string $prop): array
    {
        $tenant = fn () => Tenant::query()->findOrFail($this->tenant->id);
        $customization = ['url' => '/tenant-a/admin/personalizacao', 'component' => 'admin.tenant.customization', 'extra' => []];

        return match ($prop) {
            'logo' => $customization + ['maxKb' => 2048, 'read' => fn () => $tenant()->logo_path],
            'banner' => $customization + ['maxKb' => 5120, 'read' => fn () => data_get($tenant()->settings, 'theme.banner_path')],
            'favicon' => $customization + ['maxKb' => 512, 'read' => fn () => data_get($tenant()->settings, 'theme.favicon_path')],
            'og_image' => [
                'url' => '/tenant-a/admin/seo', 'component' => 'admin.tenant.seo-settings', 'extra' => [], 'maxKb' => 2048,
                'read' => fn () => data_get($tenant()->settings, 'seo.og_image_path'),
            ],
            'image' => [
                'url' => "/tenant-a/admin/produtos/{$this->product->id}/editar", 'component' => 'admin.products.form', 'extra' => [], 'maxKb' => 3072,
                'read' => fn () => data_get(Product::query()->withoutGlobalScopes()->findOrFail($this->product->id)->meta, 'image_path'),
            ],
            default => throw new \InvalidArgumentException("Alvo de upload desconhecido: {$prop}"),
        };
    }

    // ------------------------------------------------------------------
    // helpers (endpoint real)
    // ------------------------------------------------------------------

    /** Sobe pelo `livewire/upload-file` (URL assinada) e devolve `livewire-file:<hash>`. */
    private function uploadTemporary(string $clientName, string $bytes): string
    {
        $url = URL::temporarySignedRoute('livewire.upload-file', now()->addMinutes(5));

        $response = $this->postJson($url, ['files' => [UploadedFile::fake()->createWithContent($clientName, $bytes)]]);
        $response->assertOk();

        return 'livewire-file:'.$response->json('paths.0');
    }

    private function save(string $prop, string $clientName, string $bytes): TestResponse
    {
        $target = $this->target($prop);

        $snapshot = $this->snapshotFor((string) $this->get($target['url'])->assertOk()->getContent(), $target['component']);
        $ref = $this->uploadTemporary($clientName, $bytes);

        // O JS do Livewire, após o upload, chama `_finishUpload(prop, [hash], multiple)` — é o
        // que transforma o hash em TemporaryUploadedFile no componente (não é um `wire:model` update).
        $finished = $this->callLivewire($snapshot, '_finishUpload', [$prop, [substr($ref, strlen('livewire-file:'))], false])->assertOk();

        return $this->callLivewire((string) $finished->json('components.0.snapshot'), 'save', [], $target['extra'])->assertOk();
    }

    /** @return array<string, mixed> */
    private function errors(TestResponse $response): array
    {
        $snapshot = json_decode((string) $response->json('components.0.snapshot'), true);

        return (array) data_get($snapshot, 'memo.errors', []);
    }

    private function assertRejected(string $prop, string $clientName, string $bytes, string $why): void
    {
        $response = $this->save($prop, $clientName, $bytes);

        $this->assertArrayHasKey($prop, $this->errors($response), "[{$prop}] deveria rejeitar: {$why}");
        $this->assertNull($this->target($prop)['read'](), "[{$prop}] caminho persistido apesar da rejeição: {$why}");
        $this->assertSame([], Storage::disk('public')->allFiles(), "[{$prop}] arquivo gravado apesar da rejeição: {$why}");
    }

    private function assertAcceptedAs(string $prop, string $clientName, string $bytes, string $expectedExt): string
    {
        $response = $this->save($prop, $clientName, $bytes);

        $this->assertSame([], $this->errors($response), "[{$prop}] {$clientName} deveria ser aceito");

        $path = $this->target($prop)['read']();
        $this->assertNotNull($path, "[{$prop}] nenhum caminho persistido");
        $this->assertMatchesRegularExpression(
            '#^tenants/'.$this->tenant->id.'/(?:products/'.$this->product->id.'/)?[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.'.$expectedExt.'$#',
            $path,
            "[{$prop}] caminho fora do padrão tenants/{id}/…/uuid.{$expectedExt}"
        );
        Storage::disk('public')->assertExists($path);
        $this->assertSame($bytes, Storage::disk('public')->get($path));

        return $path;
    }

    // ------------------------------------------------------------------
    // rejeições
    // ------------------------------------------------------------------

    #[DataProvider('targets')]
    public function test_php_html_and_svg_renamed_as_images_are_rejected(string $prop): void
    {
        $this->assertRejected($prop, 'shell.jpg', ImageFixtures::php(), 'PHP renomeado para .jpg');
        $this->assertRejected($prop, 'shell.png', ImageFixtures::php(), 'PHP renomeado para .png');
        $this->assertRejected($prop, 'page.jpg', ImageFixtures::html(), 'HTML renomeado para .jpg');
        $this->assertRejected($prop, 'xss.jpg', ImageFixtures::svgWithScript(), 'SVG com <script> renomeado para .jpg');
        $this->assertRejected($prop, 'xss.svg', ImageFixtures::svgWithScript(), 'SVG com <script> como .svg');
        $this->assertRejected($prop, 'xss.png', 'texto qualquer sem imagem', 'texto puro como .png');
    }

    #[DataProvider('targets')]
    public function test_php_extension_is_rejected_even_with_image_content(string $prop): void
    {
        $this->assertRejected($prop, 'shell.php', ImageFixtures::png(), 'PNG real com nome .php');
        $this->assertRejected($prop, 'shell.php.jpg.phtml', ImageFixtures::png(), 'PNG real com nome .phtml');
    }

    #[DataProvider('targets')]
    public function test_file_over_the_size_limit_is_rejected(string $prop): void
    {
        $limit = $this->target($prop)['maxKb'];

        $this->assertRejected($prop, 'big.png', ImageFixtures::padded(ImageFixtures::png(), ($limit + 16) * 1024), "acima de {$limit} KB");
    }

    #[DataProvider('targets')]
    public function test_file_over_the_dimension_limit_is_rejected(string $prop): void
    {
        $this->assertRejected($prop, 'wide.png', ImageFixtures::png(20000, 10), 'largura 20000px');
        $this->assertRejected($prop, 'tall.png', ImageFixtures::png(10, 20000), 'altura 20000px');
    }

    public function test_favicon_only_accepts_ico_and_png_by_content(): void
    {
        $this->assertRejected('favicon', 'f.ico', ImageFixtures::jpeg(), 'JPEG com nome .ico');
        $this->assertRejected('favicon', 'f.png', ImageFixtures::gif(), 'GIF com nome .png');
        $this->assertRejected('favicon', 'f.ico', ImageFixtures::webp(), 'WebP com nome .ico');
        $this->assertRejected('favicon', 'f.png', ImageFixtures::png(600, 600), 'favicon 600x600 (limite 512)');
    }

    // ------------------------------------------------------------------
    // aceitação: extensão vem do CONTEÚDO, nome vem de uuid
    // ------------------------------------------------------------------

    #[DataProvider('generalTargets')]
    public function test_extension_is_derived_from_content_not_from_client_name(string $prop): void
    {
        $this->assertAcceptedAs($prop, 'foto.jpg', ImageFixtures::gif(), 'gif');
        $this->assertAcceptedAs($prop, 'foto.gif', ImageFixtures::png(), 'png');
        $this->assertAcceptedAs($prop, 'foto.png', ImageFixtures::jpeg(), 'jpg');
        $this->assertAcceptedAs($prop, 'foto.jpeg', ImageFixtures::webp(), 'webp');
        $this->assertAcceptedAs($prop, 'semextensao', ImageFixtures::png(), 'png');
    }

    public function test_favicon_extension_is_derived_from_content(): void
    {
        $this->assertAcceptedAs('favicon', 'favicon.png', ImageFixtures::ico(), 'ico');
        $this->assertAcceptedAs('favicon', 'favicon.ico', ImageFixtures::png(), 'png');
    }

    #[DataProvider('targets')]
    public function test_stored_file_never_carries_the_client_file_name(string $prop): void
    {
        $bytes = $prop === 'favicon' ? ImageFixtures::png() : ImageFixtures::jpeg();
        $path = $this->assertAcceptedAs($prop, 'MINHA-foto-secreta_2025.JPG', $bytes, $prop === 'favicon' ? 'png' : 'jpg');

        // (sem '2025'/'JPG' na lista: colidiriam com uuid/extensão)
        foreach (['MINHA', 'foto', 'secreta'] as $fragment) {
            $this->assertStringNotContainsStringIgnoringCase($fragment, $path);
        }
        foreach (Storage::disk('public')->allFiles() as $file) {
            $this->assertStringNotContainsStringIgnoringCase('secreta', $file);
        }
    }

    #[DataProvider('targets')]
    public function test_replacing_removes_the_old_file_and_keeps_tenant_isolation(string $prop): void
    {
        $bytes = $prop === 'favicon' ? ImageFixtures::png() : ImageFixtures::jpeg();
        $ext = $prop === 'favicon' ? 'png' : 'jpg';

        $first = $this->assertAcceptedAs($prop, 'a.jpg', $bytes, $ext);
        $second = $this->assertAcceptedAs($prop, 'b.jpg', $bytes.'', $ext);

        $this->assertNotSame($first, $second, 'nome com uuid: cada upload gera um nome novo');
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
        $this->assertCount(1, Storage::disk('public')->allFiles(), 'o arquivo antigo precisa ser removido ao substituir');
        $this->assertStringStartsWith("tenants/{$this->tenant->id}/", $second);
    }

    public function test_old_path_outside_the_tenant_directory_is_never_deleted(): void
    {
        // Um caminho adulterado no banco (outro tenant / fora de tenants/) não pode virar
        // "delete arbitrário" quando o admin troca o arquivo.
        Storage::disk('public')->put('tenants/other-tenant/logo.png', 'x');
        Storage::disk('public')->put('outside.png', 'x');
        $this->tenant->forceFill(['logo_path' => 'tenants/other-tenant/logo.png'])->save();

        $this->assertAcceptedAs('logo', 'a.png', ImageFixtures::png(), 'png');

        Storage::disk('public')->assertExists('tenants/other-tenant/logo.png');
        Storage::disk('public')->assertExists('outside.png');
    }

    public function test_polyglot_with_trailing_php_is_stored_only_as_image_extension(): void
    {
        // Imagem válida + payload PHP no fim: aceito como imagem, mas NUNCA com extensão
        // executável nem com o nome do cliente (limite documentado: não re-encodamos).
        $polyglot = ImageFixtures::gif().ImageFixtures::php();
        $path = $this->assertAcceptedAs('logo', 'shell.php.gif', $polyglot, 'gif');

        $this->assertStringEndsWith('.gif', $path);
        $this->assertStringNotContainsString('php', $path);
    }
}
