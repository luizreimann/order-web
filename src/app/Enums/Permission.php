<?php

namespace App\Enums;

/**
 * Permissões de tenant concedidas por papel (E1.6 / SEC-004).
 *
 * As Policies NÃO conhecem papéis: perguntam `Role::can(Permission::X)`.
 * Um novo papel (ex.: `tenant_staff` com matriz definida) exige alterar
 * APENAS {@see Role::permissions()}.
 */
enum Permission: string
{
    case ViewDashboard = 'dashboard.view';
    case ManageCatalog = 'catalog.manage';          // produtos, categorias, vitrines
    case ManageCustomers = 'customers.manage';
    case ManageOrders = 'orders.manage';
    case ManageStoreSettings = 'store.settings.manage';
    case UpdateStoreSlug = 'store.slug.update';      // troca a URL base da loja
    case ManagePaymentMethods = 'payment.methods.manage';
    case ManageIntegrations = 'payment.integrations.manage'; // credenciais de pagamento
    case ToggleStoreStatus = 'store.status.toggle';
    case ViewPayments = 'payments.view';
}
