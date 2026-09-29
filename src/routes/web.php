<?php

use App\Http\Controllers\MercadoPagoWebhookController;
use App\Http\Controllers\TenantAdminAuthController;
use App\Http\Controllers\TenantPaymentController;
use App\Livewire\Admin\Categories\Form as AdminCategoriesForm;
use App\Livewire\Admin\Categories\Index as AdminCategoriesIndex;
use App\Livewire\Admin\Customers\Form as AdminCustomersForm;
use App\Livewire\Admin\Customers\Index as AdminCustomersIndex;
use App\Livewire\Admin\Dashboard as AdminDashboard;
use App\Livewire\Admin\Orders\Index as AdminOrdersIndex;
use App\Livewire\Admin\Orders\Show as AdminOrdersShow;
use App\Livewire\Admin\Products\Form as AdminProductsForm;
use App\Livewire\Admin\Products\Index as AdminProductsIndex;
use App\Livewire\Admin\Showcases\Form as AdminShowcasesForm;
use App\Livewire\Admin\Showcases\Index as AdminShowcasesIndex;
use App\Livewire\Admin\Tenant\AddressSettings as AdminTenantAddressSettings;
use App\Livewire\Admin\Tenant\BusinessHours as AdminTenantBusinessHours;
use App\Livewire\Admin\Tenant\ContactSettings as AdminTenantContactSettings;
use App\Livewire\Admin\Tenant\Customization as AdminTenantCustomization;
use App\Livewire\Admin\Tenant\DeliverySettings as AdminTenantDeliverySettings;
use App\Livewire\Admin\Tenant\PaymentSettings as AdminTenantPaymentSettings;
use App\Livewire\Admin\Tenant\SeoSettings as AdminTenantSeoSettings;
use App\Livewire\Admin\Tenant\Settings as AdminTenantSettings;
use App\Livewire\Admin\Tenant\SocialLinks as AdminTenantSocialLinks;
use App\Livewire\Admin\Tenant\StoreInfo as AdminTenantStoreInfo;
use App\Livewire\Tenant\Account\Index as TenantAccountIndex;
use App\Livewire\Tenant\Account\Profile as TenantAccountProfile;
use App\Livewire\Tenant\Cart as TenantCart;
use App\Livewire\Tenant\CategoryShow as TenantCategoryShow;
use App\Livewire\Tenant\Checkout as TenantCheckout;
use App\Livewire\Tenant\Home as TenantHome;
use App\Livewire\Tenant\ProductShow as TenantProductShow;
use App\Models\Tenant;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/caroldoces');
});

Route::post('/webhooks/mercadopago', [MercadoPagoWebhookController::class, 'handle'])
    ->name('webhooks.mercadopago');

Route::prefix('{tenant:slug}')
    ->middleware('tenant')
    ->group(function () {
        Route::get('/', TenantHome::class)->name('tenant.home');

        Route::get('/p/{productSlug}', TenantProductShow::class)
            ->name('tenant.product.show');

        Route::get('/carrinho', TenantCart::class)
            ->name('tenant.cart');

        Route::get('/checkout', TenantCheckout::class)
            ->name('tenant.checkout');

        Route::get('/categoria/{categorySlug}', TenantCategoryShow::class)
            ->name('tenant.category.show');

        Route::post('/process_payment', [TenantPaymentController::class, 'processPayment'])
            ->name('tenant.process_payment');

        Route::middleware('tenant.customer.auth')->group(function () {
            Route::get('/conta', TenantAccountIndex::class)
                ->name('tenant.account');

            Route::get('/conta/perfil', TenantAccountProfile::class)
                ->name('tenant.account.profile');
        });

        Route::get('/admin/login', [TenantAdminAuthController::class, 'showLogin'])
            ->name('tenant.admin.login');

        Route::post('/admin/login', [TenantAdminAuthController::class, 'login'])
            ->name('tenant.admin.login.post');

        Route::prefix('admin')
            ->middleware('tenant.auth')
            ->group(function () {

                // Dashboard
                Route::get('/', AdminDashboard::class)
                    ->name('tenant.admin.dashboard');

                // Produtos
                Route::get('/produtos', AdminProductsIndex::class)
                    ->name('tenant.admin.products.index');

                Route::get('/produtos/novo', AdminProductsForm::class)
                    ->name('tenant.admin.products.create');

                Route::get('/produtos/{productId}/editar', AdminProductsForm::class)
                    ->name('tenant.admin.products.edit');

                // Categorias
                Route::get('/categorias', AdminCategoriesIndex::class)
                    ->name('tenant.admin.categories.index');

                Route::get('/categorias/nova', AdminCategoriesForm::class)
                    ->name('tenant.admin.categories.create');

                Route::get('/categorias/{categoryId}/editar', AdminCategoriesForm::class)
                    ->name('tenant.admin.categories.edit');

                // Vitrines
                Route::get('/vitrines', AdminShowcasesIndex::class)
                    ->name('tenant.admin.showcases.index');

                Route::get('/vitrines/nova', AdminShowcasesForm::class)
                    ->name('tenant.admin.showcases.create');

                Route::get('/vitrines/{showcaseId}/editar', AdminShowcasesForm::class)
                    ->name('tenant.admin.showcases.edit');

                // Pedidos
                Route::get('/pedidos', AdminOrdersIndex::class)
                    ->name('tenant.admin.orders.index');

                Route::get('/pedidos/{orderId}', AdminOrdersShow::class)
                    ->name('tenant.admin.orders.show');

                // Clientes
                Route::get('/clientes', AdminCustomersIndex::class)
                    ->name('tenant.admin.customers.index');

                Route::get('/clientes/novo', AdminCustomersForm::class)
                    ->name('tenant.admin.customers.create');

                Route::get('/clientes/{customerId}/editar', AdminCustomersForm::class)
                    ->name('tenant.admin.customers.edit');

                // Informações da Loja
                Route::get('/informacoes', AdminTenantStoreInfo::class)
                    ->name('tenant.admin.store-info');

                // Personalização
                Route::get('/personalizacao', AdminTenantCustomization::class)
                    ->name('tenant.admin.customization');

                // Contato
                Route::get('/contato', AdminTenantContactSettings::class)
                    ->name('tenant.admin.contact');

                // Endereço
                Route::get('/endereco', AdminTenantAddressSettings::class)
                    ->name('tenant.admin.address');

                // Horários
                Route::get('/horarios', AdminTenantBusinessHours::class)
                    ->name('tenant.admin.business-hours');

                // Redes Sociais
                Route::get('/redes-sociais', AdminTenantSocialLinks::class)
                    ->name('tenant.admin.social-links');

                // Entrega
                Route::get('/entrega', AdminTenantDeliverySettings::class)
                    ->name('tenant.admin.delivery');

                // SEO
                Route::get('/seo', AdminTenantSeoSettings::class)
                    ->name('tenant.admin.seo');

                // Configurações
                Route::get('/configuracoes', AdminTenantSettings::class)
                    ->name('tenant.admin.settings');

                // Pagamentos
                Route::get('/pagamentos', AdminTenantPaymentSettings::class)
                    ->name('tenant.admin.payment-settings');

                // Toggle Store Status
                Route::post('/toggle-store-status', function (Tenant $tenant) {
                    $settings = $tenant->settings ?? [];
                    $currentPaused = data_get($settings, 'business_hours.paused', false);
                    $newPaused = ! $currentPaused;

                    data_set($settings, 'business_hours.paused', $newPaused);
                    $tenant->settings = $settings;
                    $tenant->save();

                    return response()->json([
                        'success' => true,
                        'paused' => $newPaused,
                    ]);
                })->name('tenant.admin.toggle-store-status');

                // Logout
                Route::post('/logout', [TenantAdminAuthController::class, 'logout'])
                    ->name('tenant.admin.logout');
            });
    });
