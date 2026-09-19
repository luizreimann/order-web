1) Visão geral do projeto

O que é

Uma plataforma multi-tenant (SaaS) em Laravel 12 + PHP 8.4, voltada para encomendas (um e-commerce “order-first”), totalmente white label.

Cada cliente (tenant) tem sua própria loja acessível por URL do tipo:
	•	https://nossoapp.com/{tenantSlug}
	•	https://nossoapp.com/{tenantSlug}/admin

No futuro, pode ter domínio personalizado, mas não é parte do MVP.

Objetivos do produto (MVP)
	1.	Um tenant consegue configurar o visual da loja (logo, cores, fonte).
	2.	Tenant tem um admin minimalista para:
	•	gerenciar catálogo (produtos + categorias)
	•	gerenciar pedidos (listagem + detalhe + atualização de status)
	•	gerenciar clientes (cadastro + endereço principal)
	3.	O lado público (storefront) deve permitir ao usuário:
	•	ver produtos, selecionar, montar pedido e enviar/registrar encomenda
	4.	Pagamento no MVP é manual/simples (ex.: Pix/dinheiro) com status (pago/não pago).

⸻

2) Stack e Infra

Stack
	•	Laravel 12
	•	PHP 8.4
	•	PostgreSQL
	•	Livewire (componentes server-driven)
	•	Bootstrap 5.3 (UI e dark/light via data-bs-theme)
	•	Font Awesome (kit via <script src="https://kit.fontawesome.com/...">)
	•	Docker + docker-compose

Multi-tenancy (arquitetura)
	•	O tenant é resolvido pelo path: /{tenant:slug}
	•	Rotas são agrupadas por:
    Route::prefix('{tenant:slug}')
        ->middleware('tenant')
        ->group(...)

	•	Middleware tenant carrega tenant por slug, define contexto e compartilha currentTenant nas views.
	•	Admin fica dentro desse escopo e tem auth próprio (não usa Breeze/Jetstream).

⸻

3) Estrutura de rotas (padrão atual)

Público
	•	/{tenant} → home (TenantHome Livewire)
	•	(falta MVP) catálogo, produto, checkout etc.

Admin
	•	/{tenant}/admin/login (GET/POST)
	•	/{tenant}/admin dashboard (Livewire)
	•	/{tenant}/admin/produtos (CRUD Livewire)
	•	/{tenant}/admin/categorias (CRUD Livewire)
	•	/{tenant}/admin/pedidos (listagem) + /{tenant}/admin/pedidos/{orderId} (detalhe)
	•	/{tenant}/admin/clientes (CRUD Livewire)
	•	/{tenant}/admin/personalizacao (customização white label)
	•	/{tenant}/admin/configuracoes (troca de slug)
	•	logout: POST /{tenant}/admin/logout

Middleware:
	•	tenant para carregar tenant
	•	tenant.auth (EnsureTenantAdminAuthenticated) para proteger admin

⸻

4) Autenticação (admin manual, minimalista)
	•	Existe uma tabela users com tenant_id e role.
	•	Seed cria admin do tenant (ex.: tenant_admin).
	•	Login salva dados do usuário na sessão e confere tenant/role.
	•	Middleware tenant.auth valida sessão (usuário logado e pertence ao tenant atual).

Importante: esse auth é isolado do “cliente final”. Cliente final não é User.

⸻

5) White label / Tema

O que existe
	•	Tenant tem:
	•	primary_color, secondary_color, logo_path, settings (jsonb)
	•	Partial partials/tenant-theme.blade.php:
	•	injeta CSS variables que sobrescrevem variáveis do Bootstrap 5.3
	•	aplica cores e tipografia global
	•	suporta Google Fonts conforme font escolhida
	•	Login do admin foi customizado para também aplicar o tema:
	•	inclui tenant-theme
	•	mostra logo se existir
	•	aplica dark/light (lê localStorage e prefers-color-scheme)

Dark/Light
	•	Bootstrap 5.3 usa data-bs-theme="dark|light"
	•	Toggle existe no layout admin (desktop e offcanvas mobile), só ícone (lua/sol).
	•	Preferência salva em localStorage.theme.

Personalização (Livewire)
	•	Tela /admin/personalizacao permite:
	•	nome, cores, fonte, upload logo
	•	Ao salvar, dispara reload (F5 programático) para aplicar mudanças que dependem do <head>.

⸻

6) Layout Admin (tenant-admin.blade.php)
	•	Sidebar desktop + offcanvas no mobile
	•	Font Awesome habilitado no <head>
	•	Links agrupados por seções (melhor UX):
	•	Dashboard
	•	Operação: Pedidos, Clientes
	•	Catálogo: Produtos, Categorias
	•	Aparência: Personalização, Configurações
	•	Links neutros (não sempre na cor primária) e ativo destacado.

⸻

7) Domínio / Modelagem de dados

Tenants
	•	tenants (uuid)
	•	slug (muda via config com validação)
	•	name, logo_path, primary_color, secondary_color, settings jsonb

Products
	•	products (uuid)
	•	tenant_id
	•	campos “robustos” + meta jsonb
	•	categorias: relação M:N via pivot category_product
	•	imagem do produto: planejado/implementando via meta.image_path (upload no form admin)

Categories
	•	categories (uuid)
	•	tenant_id
	•	name, slug, description, sort_order, is_active
	•	meta jsonb
	•	unique: (tenant_id, slug)
	•	Pivot: category_product (uuid ids)

Orders
	•	orders (uuid)
	•	tenant_id
	•	number (sequencial por tenant)
	•	status (pending|confirmed|done|cancelled)
	•	payment_status (unpaid|paid|refunded)
	•	payment_method string
	•	dados do cliente (customer_name, customer_phone) + fulfillment (pickup/delivery)
	•	scheduled_for (timestamp) e notes
	•	totais em centavos: subtotal/discount/delivery_fee/total
	•	address snapshot no pedido (line1, etc.)
	•	meta jsonb

Order Items
	•	order_items (uuid)
	•	tenant_id, order_id, product_id (nullable)
	•	snapshot: name, sku, unit_price_cents, quantity, total_cents
	•	notes, meta jsonb

Customers (cliente final)
	•	customers (uuid)
	•	tenant_id
	•	name, email, phone, is_active
	•	meta jsonb
	•	unique por tenant em phone (opcional, implementado)

Customer Addresses
	•	customer_addresses (uuid)
	•	tenant_id, customer_id
	•	label, is_default
	•	endereço: line1, line2, city, state, postal_code
	•	meta jsonb

Ordem e cliente
	•	orders tem customer_id nullable (já adicionada por migration)
	•	Admin CRUD de clientes já existe com “endereço principal” (default)

⸻

8) Geração do número do pedido (#123)

Foi implementada a abordagem correta para Postgres com concorrência:
	•	tabela tenant_counters:
	•	PK composta (tenant_id, key)
	•	value incrementa
	•	Em Order::creating, se number não vier preenchido:
	•	abre transação
	•	lockForUpdate() no counter do tenant
	•	incrementa e usa como order.number

Isso garante sequência por tenant sem colisão.

⸻

9) Funcionalidades já prontas (admin)

✅ Dashboard em Livewire (layout ok)
✅ Auth admin manual
✅ Tema white label (logo/cores/fonte + Google Fonts)
✅ Dark/Light toggle persistente
✅ CRUD Produtos (com categorias no index e no form)
✅ CRUD Categorias
✅ Admin Pedidos (Index + Show, update status)
✅ CRUD Clientes (Index + Form + endereço principal)

⸻

10) O que falta para o MVP “fechar” (em ordem de importância)

A) Storefront público (essencial)

Ainda falta o lado do cliente final:
	1.	/{tenant} exibir vitrine real:
	•	listagem de produtos ativos
	•	filtros por categoria (opcional mas fácil)
	2.	/{tenant}/p/{productSlug} ou /{tenant}/produtos/{slug}:
	•	detalhe do produto
	•	mostrar imagem (meta.image_path)
	•	tags/variações (pode ir em meta no MVP)
	3.	“Carrinho” simples (sessão)
	4.	Checkout/encomenda:
	•	nome + whatsapp
	•	tipo: retirada/entrega
	•	data/hora (se produto exigir)
	•	observações gerais
	•	endereço (se entrega)
	5.	Ao finalizar:
	•	criar Customer (findOrCreate por phone)
	•	criar Order e OrderItems
	•	calcular totais
	•	setar status inicial pending / payment_status unpaid

Esse bloco é o coração do MVP.

B) Integração cliente ↔ pedido no admin

No admin de pedidos:
	•	mostrar customer associado (se houver)
	•	link para “editar cliente”
	•	puxar endereço default do customer ao criar pedido (opcional)

C) Pagamento (MVP simples)
	•	lista de métodos habilitados por tenant (ex.: pix/dinheiro/cartão)
	•	persistir no order: payment_method
	•	optionally: chave pix / instruções em tenant.settings.payment.pix_key

D) Qualidade e segurança mínima
	•	validações fortes em todas rotas multi-tenant (sempre filtrar por tenant_id)
	•	garantir que pivot categoria/produto não vaze tenant
	•	logs e tratamento de erros no checkout

E) Seeds + dados de demo
	•	seed de produtos/categorias/pedidos para testar UI

⸻

11) Convenções de código / patterns usados

Multi-tenant scoping
	•	Quase todos models têm scope:
    public function scopeForTenant($query, string $tenantId)
    •	Queries admin devem usar sempre:
    Model::query()->forTenant($tenant->id)...

IDs
	•	UUID para quase tudo (HasUuids)

Meta jsonb
	•	meta usado para extensibilidade (imagem de produto, extras etc.)
	•	Laravel casts: meta => array

Totais em centavos
	•	Evita float:
	•	*_cents integer
	•	formata no Blade: number_format($cents/100, 2, ',', '.')

Livewire
	•	Componentes admin renderizam com:
    ->layout('layouts.tenant-admin')

Rotas com {productId}, {categoryId}, {orderId}, {customerId} ao invés de binding automático, para evitar erros anteriores.

⸻

12) Exemplos práticos (para orientar outra IA)

Criar pedido (pseudocódigo)
DB::transaction(function() use ($tenant, $payload, $cart) {
  $customer = Customer::forTenant($tenant->id)
    ->where('phone', $payload['phone'])
    ->first();

  if (!$customer) {
     $customer = Customer::create([...]);
  }

  $order = Order::create([
    'tenant_id' => $tenant->id,
    'customer_id' => $customer->id,
    'customer_name' => $payload['name'],
    'customer_phone' => $payload['phone'],
    'fulfillment_type' => $payload['fulfillment'],
    'scheduled_for' => $payload['scheduled_for'] ?? null,
    'payment_method' => $payload['payment_method'],
    'status' => 'pending',
    'payment_status' => 'unpaid',
    // totals filled later
  ]);

  $subtotal = 0;

  foreach($cart as $item){
    $product = Product::forTenant($tenant->id)->findOrFail($item['product_id']);

    $unit = $product->price_cents;
    $total = $unit * $item['qty'];
    $subtotal += $total;

    OrderItem::create([
      'tenant_id' => $tenant->id,
      'order_id' => $order->id,
      'product_id' => $product->id,
      'name' => $product->name,
      'quantity' => $item['qty'],
      'unit_price_cents' => $unit,
      'total_cents' => $total,
      'meta' => ['options' => $item['options'] ?? []],
    ]);
  }

  $order->subtotal_cents = $subtotal;
  $order->total_cents = $subtotal; // + delivery - discount
  $order->save();

  return $order;
});

Produto com imagem no meta
	•	Upload salva em storage/public/...
	•	meta.image_path = 'tenants/{tenant}/products/{product}/main.jpg'
	•	Exibir:
    <img src="{{ Storage::url(data_get($product->meta, 'image_path')) }}">

Tema do tenant
	•	No <head>:
    @include('partials.tenant-theme')

Ele sobrescreve variáveis do Bootstrap 5.3 (primary/secondary/link/focus etc.)

⸻

13) Lista de telas existentes (admin)
	•	Dashboard
	•	Pedidos (Index + Show)
	•	Clientes (Index + Form)
	•	Produtos (Index + Form)
	•	Categorias (Index + Form)
	•	Personalização
	•	Configurações (slug)

14) Próximos passos recomendados (roadmap curto)
	1.	Finalizar upload de imagem do produto no form + mostrar na index + mostrar no público
	2.	Implementar storefront:
	•	listagem + detalhe
	3.	Carrinho em sessão
	4.	Checkout gerando order + customer + items
	5.	Ajustar admin pedido para linkar cliente e mostrar infos completas
	6.	(opcional) gerar WhatsApp message template do pedido (muito útil em encomendas)

⸻

15) “Como instruir outra IA” (prompt pronto)

Você pode colar algo assim para outra IA:

Você está continuando um projeto Laravel 12 (PHP 8.4) multi-tenant por path /{tenant:slug} com Postgres, Livewire e Bootstrap 5.3. Admin protegido por auth manual via session (tenant.auth). Já existem CRUDs de Produtos (com meta jsonb e categorias many-to-many), Categorias, Pedidos (index + show + update status), Clientes (com endereço default), além de Personalização white label (logo, cores, font + Google Fonts) e Configurações (troca de slug). Falta o MVP do lado público: storefront (lista + detalhe), carrinho em sessão e checkout criando Order/OrderItems e associando Customer por phone. Tudo deve ser sempre escopado por tenant_id e usar UUID. Totais são em centavos. Tema do tenant é aplicado via partials/tenant-theme.blade.php e Bootstrap data-bs-theme. Continue implementando checkout e storefront.

⸻

Se você quiser, eu também posso te entregar um mapa de arquivos/paths (onde fica cada controller/middleware/component) — pra isso eu precisaria que você me dissesse rapidamente os nomes exatos dos middlewares/controllers que você criou (ou me mandar um print/lista do app/Http e app/Livewire).