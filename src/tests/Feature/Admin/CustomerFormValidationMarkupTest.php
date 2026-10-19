<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Components\Support\RendersComponents;
use Tests\Feature\Tenancy\Support\InteractsWithLivewireEndpoint;
use Tests\TestCase;

/**
 * E4.2 (módulo Clientes) — `save` inválido pelo endpoint real (`POST /livewire/update`)
 * marca o controle (`is-invalid` + `aria-invalid`) e emite o `invalid-feedback` irmão.
 * O endereço principal (`has_address`) é condicional: seus campos só existem com o flag.
 */
class CustomerFormValidationMarkupTest extends TestCase
{
    use InteractsWithLivewireEndpoint, RefreshDatabase, RendersComponents;

    private Tenant $tenant;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->tenant = Tenant::factory()->create(['slug' => 'tenant-a', 'is_active' => true]);
        $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Ana']);

        $this->actingAs(User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => Role::TenantAdmin,
        ]), 'tenant_admin');
    }

    /** @param array<string, mixed> $updates */
    private function save(array $updates, ?string $url = null): TestResponse
    {
        $page = $this->get($url ?? "/tenant-a/admin/clientes/{$this->customer->id}/editar")->assertOk();

        return $this->callLivewire($this->snapshotFor($page->getContent(), 'admin.customers.form'), 'save', [], $updates);
    }

    private function html(TestResponse $response): string
    {
        $response->assertOk();

        return (string) $response->json('components.0.effects.html');
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidInputs(): array
    {
        return [
            'name vazio' => [['name' => ''], 'name'],
            'email inválido' => [['email' => 'não-é-email'], 'email'],
            'phone inválido' => [['phone' => '123'], 'phone'],
            'endereço obrigatório com has_address' => [['has_address' => true, 'address_line1' => ''], 'address_line1'],
            'cidade longa demais' => [['has_address' => true, 'address_line1' => 'Rua A', 'address_city' => str_repeat('a', 121)], 'address_city'],
        ];
    }

    /** @param array<string, mixed> $updates */
    #[DataProvider('invalidInputs')]
    public function test_invalid_save_marks_the_field_and_shows_the_message(array $updates, string $id): void
    {
        $html = $this->html($this->save($updates));

        $control = $this->first($html, "//*[@id='{$id}']");
        $this->assertContains('is-invalid', $this->classes($control), "campo [{$id}] sem is-invalid");
        $this->assertSame('true', $control->getAttribute('aria-invalid'));

        $feedback = $this->first($html, "//*[@id='{$id}']/following-sibling::div[contains(@class,'invalid-feedback')]");
        $this->assertNotSame('', trim($feedback->textContent), "campo [{$id}] sem mensagem");
    }

    public function test_only_the_invalid_field_is_marked(): void
    {
        $html = $this->html($this->save(['name' => '']));

        $this->assertSame(1, $this->countNodes($html, '//*[contains(@class,"is-invalid")]'));
        $this->assertSame(1, $this->countNodes($html, '//div[contains(@class,"invalid-feedback")]'));
    }

    public function test_every_invalid_field_is_marked_at_once(): void
    {
        $html = $this->html($this->save(['name' => '', 'email' => 'x', 'has_address' => true, 'address_line1' => '']));

        foreach (['name', 'email', 'address_line1'] as $id) {
            $this->assertContains('is-invalid', $this->classes($this->first($html, "//*[@id='{$id}']")), $id);
        }
        $this->assertSame(3, $this->countNodes($html, '//div[contains(@class,"invalid-feedback")]'));
    }

    public function test_address_fields_exist_only_with_has_address(): void
    {
        $off = $this->html($this->save(['name' => '', 'has_address' => false]));
        $this->assertSame(0, $this->countNodes($off, "//*[@id='address_line1']"));
        $this->assertSame(1, $this->countNodes($off, "//input[@id='has_address'][@type='checkbox']"));

        $on = $this->html($this->save(['name' => '', 'has_address' => true]));
        foreach (['address_label', 'address_line1', 'address_line2', 'address_city', 'address_state', 'address_postal_code'] as $id) {
            $this->assertSame(1, $this->countNodes($on, "//*[@id='{$id}']"), $id);
        }
    }

    public function test_a_stored_default_address_opens_the_address_section(): void
    {
        CustomerAddress::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'label' => 'Casa', 'is_default' => true, 'line1' => 'Rua A, 1',
        ]);

        $html = $this->get("/tenant-a/admin/clientes/{$this->customer->id}/editar")->assertOk()->getContent();

        $this->assertSame(1, $this->countNodes($html, "//input[@id='address_line1']"));
    }

    public function test_valid_save_has_no_invalid_markup_and_persists(): void
    {
        $html = $this->html($this->save(['name' => 'Ana Maria']));

        $this->assertStringNotContainsString('is-invalid', $html);
        $this->assertStringNotContainsString('invalid-feedback', $html);
        $this->assertSame('Ana Maria', Customer::query()->findOrFail($this->customer->id)->name);
    }

    public function test_invalid_save_does_not_persist_anything(): void
    {
        $this->save(['name' => '']);

        $this->assertSame('Ana', Customer::query()->findOrFail($this->customer->id)->name);
    }

    public function test_create_form_also_marks_invalid_fields(): void
    {
        $html = $this->html($this->save(['name' => ''], '/tenant-a/admin/clientes/novo'));

        $this->assertContains('is-invalid', $this->classes($this->first($html, "//*[@id='name']")));
    }
}
