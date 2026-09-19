# Análise de Experiência do Comprador (Buyer Experience)

**Data:** 2026-01-12  
**Sprint:** 05-buyer-experience  
**Autor:** Cascade (AI Assistant)

---

## 1. Visão Geral

Esta sprint foca em melhorias críticas na experiência do usuário comprador da tenant, com ênfase em autenticação simplificada, navegação intuitiva e acesso a informações essenciais. O objetivo é reduzir fricção no processo de compra e aumentar o engajamento através de funcionalidades centradas no cliente.

---

## 2. Requisitos Funcionais Solicitados

### 2.1 Sistema de Login por Telefone

**Descrição:** Implementar um sistema de autenticação simplificado que captura o usuário assim que ele acessa a loja.

**Comportamento:**
- Exibir popup de login ao acessar a página inicial
- Solicitar apenas número de telefone
- Criar pré-cadastro automático se usuário não existir
- Estabelecer sessão persistente via cookie com duração máxima

**Objetivos:**
- Reduzir abandono de carrinho
- Permitir rastreamento de pedidos
- Personalizar experiência do usuário
- Facilitar recompra

**Considerações Técnicas:**
- Modelo `Customer` já existe com campos `phone`, `name`, `email`
- Atualmente não há sistema de autenticação para clientes
- Carrinho é armazenado em sessão (`cart.{tenant_id}`)
- Checkout já coleta dados do cliente mas não persiste login

### 2.2 Ícone de Carrinho no Header

**Descrição:** Substituir botão textual "Carrinho" por ícone visual.

**Localização Atual:** `@/Users/luizbrunolopesreimann/Documents/Repos/order-web/src/resources/views/livewire/tenant/home.blade.php:33-35`

**Mudança:**
```blade
<!-- Atual -->
<a class="btn btn-outline-primary" href="{{ route('tenant.cart', ['tenant' => $tenant->slug]) }}">
    Carrinho
</a>

<!-- Proposto -->
<a class="btn btn-outline-primary position-relative" href="{{ route('tenant.cart', ['tenant' => $tenant->slug]) }}">
    <i class="fa-solid fa-shopping-cart"></i>
    <span class="badge bg-danger position-absolute top-0 start-100 translate-middle">3</span>
</a>
```

**Benefícios:**
- Reconhecimento visual imediato
- Economia de espaço no header
- Badge com contador de itens
- Padrão de mercado (iFood, Rappi, Amazon)

### 2.3 Botão de Conta do Usuário

**Descrição:** Adicionar botão ao lado do carrinho que leva para área do cliente.

**Funcionalidades da Área do Cliente:**
- Visualizar histórico de pedidos
- Editar dados pessoais (nome, email, telefone)
- Gerenciar endereços de entrega
- Ver status de pedidos em andamento

**Estrutura Necessária:**
- Nova rota: `tenant.account` ou `tenant.profile`
- Novo componente Livewire: `App\Livewire\Tenant\Account`
- View: `resources/views/livewire/tenant/account.blade.php`
- Subpáginas: Pedidos, Dados Pessoais, Endereços

**Fluxo:**
1. Usuário clica no ícone de perfil
2. Se não autenticado → redireciona para login
3. Se autenticado → exibe área do cliente

### 2.4 Botão de Contato com Horário

**Descrição:** Adicionar botão com ícone de relógio exibindo informações de contato e horário de funcionamento.

**Dados Disponíveis:**
- `TenantHelper::getContactInfo()` - telefone, WhatsApp, email
- `TenantHelper::getBusinessHoursForDisplay()` - horários formatados
- `TenantHelper::getWhatsAppUrl()` - link direto para WhatsApp

**Comportamento Proposto:**
- Ícone de relógio no header
- Ao clicar, abre modal/dropdown com:
  - Horário de funcionamento da semana
  - Telefone (clicável para ligar)
  - WhatsApp (link direto)
  - Email (clicável para enviar)
  - Status atual (Aberto/Fechado)

**Implementação:**
- Componente Livewire ou Alpine.js para modal
- Reutilizar helpers existentes
- Design responsivo (mobile-first)

### 2.5 Modo de Exibição das Vitrines

**Descrição:** Permitir que o administrador escolha entre exibição em lista ou carrossel para cada vitrine.

**Modelo Atual:** `Showcase` (`@/Users/luizbrunolopesreimann/Documents/Repos/order-web/src/app/Models/Showcase.php`)

**Campos Existentes:**
- `name`, `slug`, `type`, `category_id`, `max_items`, `sort_order`, `is_active`, `meta`

**Mudança Proposta:**
- Adicionar campo `display_mode` ao modelo (ou usar `meta.display_mode`)
- Opções: `list` (padrão) ou `carousel`
- Adicionar select no formulário de criação/edição (`@/Users/luizbrunolopesreimann/Documents/Repos/order-web/src/app/Livewire/Admin/Showcases/Form.php`)

**Exibição Atual:** Grid responsivo (col-12 col-md-6 col-lg-4)

**Exibição em Carrossel:**
- Biblioteca sugerida: Swiper.js ou Splide.js
- Mostrar preview do próximo slide
- Navegação por setas e touch/swipe
- Responsivo (1 item mobile, 2-3 desktop)
- Auto-play opcional

### 2.6 Implementação de Carrossel

**Bibliotecas Recomendadas:**

| Biblioteca | Prós | Contras |
|------------|------|---------|
| **Swiper.js** | Moderno, touch-friendly, muitas features | Arquivo maior |
| **Splide.js** | Leve, acessível, sem jQuery | Menos features |
| **Glide.js** | Muito leve, simples | Menos customização |

**Recomendação:** Splide.js
- Peso: ~30KB minificado
- Acessibilidade nativa
- Suporte a preview de próximo slide
- Fácil integração com Livewire

**Exemplo de Configuração:**
```javascript
new Splide('.showcase-carousel', {
  type: 'loop',
  perPage: 3,
  perMove: 1,
  gap: '1rem',
  padding: { right: '5rem' }, // Preview do próximo
  breakpoints: {
    768: { perPage: 1, padding: { right: '3rem' } },
    1024: { perPage: 2 },
  },
}).mount();
```

---

## 3. Estado Atual da Aplicação

### 3.1 Autenticação

**Status:** ❌ Não implementado para clientes

- Existe autenticação apenas para administradores (Laravel Breeze)
- Clientes são criados/atualizados durante checkout (`OrderService::resolveCustomer()`)
- Não há sessão persistente de cliente
- Carrinho é anônimo (sessão PHP)

**Limitações:**
- Cliente não consegue ver histórico de pedidos
- Dados não são pré-preenchidos em compras futuras
- Sem personalização de experiência
- Impossível implementar wishlist, favoritos, etc.

### 3.2 Navegação do Cliente

**Header Atual:**
- Logo/Nome da loja
- Badge de status (Aberto/Fechado)
- Informações de entrega (tempo, taxa, pedido mínimo)
- Botão "Carrinho" (texto)

**Faltando:**
- Contador de itens no carrinho
- Acesso à conta do usuário
- Informações de contato rápido
- Horário de funcionamento visível

### 3.3 Vitrines (Showcases)

**Implementação Atual:**
- 6 tipos: All, Featured, Best Sellers, Recent, Category, Custom
- Exibição fixa em grid responsivo
- Limite de 10 vitrines por tenant
- Ordenação manual (drag & drop)

**Campos do Modelo:**
```php
'tenant_id', 'name', 'slug', 'type', 'category_id', 
'max_items', 'sort_order', 'is_active', 'meta'
```

**Renderização:** `@/Users/luizbrunolopesreimann/Documents/Repos/order-web/src/resources/views/livewire/tenant/home.blade.php:43-73`

### 3.4 Carrinho

**Funcionalidades Existentes:**
- Adicionar/remover produtos
- Incrementar/decrementar quantidade
- Cálculo de subtotal
- Persistência em sessão

**Limitações:**
- Sem contador visual no header
- Carrinho não sobrevive a limpeza de sessão
- Não vinculado a usuário autenticado

---

## 4. Análise de Mercado

### 4.1 Benchmarking - Login Simplificado

| Plataforma | Método de Login | Experiência |
|------------|-----------------|-------------|
| **iFood** | Telefone + SMS | Popup ao primeiro acesso, código SMS |
| **Rappi** | Telefone + SMS | Obrigatório antes de adicionar ao carrinho |
| **Uber Eats** | Email/Telefone + SMS | Popup suave, pode pular inicialmente |
| **Mercado Livre** | Email/Telefone | Login social disponível |
| **Magazine Luiza** | CPF/Email | Pode comprar como visitante |

**Tendências:**
- Login por telefone é padrão em delivery
- SMS/OTP para verificação (segurança)
- Opção de "continuar como visitante"
- Login social (Google, Facebook) como alternativa

**Recomendação para MVP:**
- Popup ao acessar homepage (primeira vez)
- Solicitar apenas telefone
- Criar conta automaticamente
- Cookie de sessão longa (30-90 dias)
- Sem verificação SMS inicialmente (adicionar em sprint futura)

### 4.2 Benchmarking - Navegação

**Padrões de Mercado:**

| Elemento | iFood | Rappi | Uber Eats | Amazon |
|----------|-------|-------|-----------|--------|
| Carrinho | Ícone + badge | Ícone + badge | Ícone + badge | Ícone + badge |
| Conta | Ícone de pessoa | Menu hamburguer | Ícone de pessoa | "Olá, Nome" |
| Contato | Menu lateral | Rodapé | Menu lateral | Rodapé |
| Horário | Badge no header | Info do restaurante | Info do restaurante | N/A |

**Insights:**
- Ícone de carrinho com badge é universal
- Conta do usuário sempre visível no header
- Informações de contato variam (menu vs rodapé)
- Horário geralmente próximo ao nome da loja

### 4.3 Benchmarking - Carrosséis

**Uso em E-commerce:**
- **Amazon:** Carrosséis em todas as seções de produtos
- **Mercado Livre:** "Você pode gostar" em carrossel
- **Magazine Luiza:** Ofertas e destaques em carrossel
- **iFood:** Restaurantes e pratos em carrossel horizontal

**Características Comuns:**
- Preview do próximo item (20-30% visível)
- Navegação por setas
- Swipe em mobile
- 1-2 itens mobile, 3-5 desktop
- Auto-play em banners, manual em produtos

---

## 5. Proposta de Arquitetura

### 5.1 Sistema de Autenticação de Clientes

**Abordagem Sugerida:** Cookie-based Session (sem senha)

**Fluxo:**
1. Cliente acessa homepage
2. Verifica cookie `customer_session_{tenant_id}`
3. Se não existe → exibe popup de login
4. Cliente informa telefone
5. Backend valida e busca/cria Customer
6. Gera token único e armazena em cookie
7. Sessão válida por 90 dias

**Estrutura de Dados:**

```php
// Adicionar ao modelo Customer
'session_token' => 'string|nullable', // Hash único
'last_login_at' => 'timestamp|nullable',

// Cookie
[
    'name' => 'customer_session_' . $tenant->id,
    'value' => $sessionToken,
    'expires' => now()->addDays(90),
    'httpOnly' => true,
    'secure' => true,
    'sameSite' => 'lax',
]
```

**Middleware:**
```php
// App\Http\Middleware\TenantCustomerAuth
// Verifica cookie e injeta $customer na request
```

**Componentes:**
- `App\Livewire\Tenant\Auth\LoginPopup` - Modal de login
- `App\Services\CustomerAuthService` - Lógica de autenticação
- Middleware para rotas protegidas

### 5.2 Área do Cliente

**Estrutura de Rotas:**
```php
Route::middleware(['tenant.customer.auth'])->group(function () {
    Route::get('/{tenant}/conta', Account::class)->name('tenant.account');
    Route::get('/{tenant}/conta/pedidos', Orders::class)->name('tenant.account.orders');
    Route::get('/{tenant}/conta/pedido/{order}', OrderDetail::class)->name('tenant.account.order');
    Route::get('/{tenant}/conta/dados', Profile::class)->name('tenant.account.profile');
    Route::get('/{tenant}/conta/enderecos', Addresses::class)->name('tenant.account.addresses');
});
```

**Componentes Livewire:**
- `App\Livewire\Tenant\Account\Index` - Dashboard da conta
- `App\Livewire\Tenant\Account\Orders` - Lista de pedidos
- `App\Livewire\Tenant\Account\OrderDetail` - Detalhes do pedido
- `App\Livewire\Tenant\Account\Profile` - Edição de dados
- `App\Livewire\Tenant\Account\Addresses` - Gerenciamento de endereços

### 5.3 Header Unificado

**Componente:** `resources/views/components/tenant-header.blade.php`

**Estrutura:**
```blade
<header class="sticky-top bg-white shadow-sm">
    <div class="container">
        <div class="d-flex align-items-center justify-content-between py-3">
            <!-- Logo + Status -->
            <div>
                <h1>{{ $tenant->name }}</h1>
                <span class="badge">{{ $isOpen ? 'Aberto' : 'Fechado' }}</span>
            </div>
            
            <!-- Actions -->
            <div class="d-flex gap-2">
                <!-- Contato/Horário -->
                <button class="btn btn-outline-secondary" x-data @click="$dispatch('open-contact-modal')">
                    <i class="fa-solid fa-clock"></i>
                </button>
                
                <!-- Carrinho -->
                <a href="{{ route('tenant.cart') }}" class="btn btn-outline-primary position-relative">
                    <i class="fa-solid fa-shopping-cart"></i>
                    @if($cartCount > 0)
                        <span class="badge bg-danger">{{ $cartCount }}</span>
                    @endif
                </a>
                
                <!-- Conta -->
                <a href="{{ route('tenant.account') }}" class="btn btn-outline-secondary">
                    <i class="fa-solid fa-user"></i>
                </a>
            </div>
        </div>
    </div>
</header>
```

### 5.4 Modal de Contato/Horário

**Componente:** `App\Livewire\Tenant\ContactModal`

**Conteúdo:**
- Status atual (Aberto agora / Fechado)
- Horário de funcionamento (tabela semanal)
- Telefone (link `tel:`)
- WhatsApp (link `wa.me`)
- Email (link `mailto:`)

**Tecnologia:** Alpine.js para toggle do modal

### 5.5 Showcase com Display Mode

**Migração:**
```php
// Adicionar coluna display_mode
Schema::table('showcases', function (Blueprint $table) {
    $table->string('display_mode')->default('list')->after('type');
    // Valores: 'list', 'carousel'
});
```

**Formulário de Edição:**
```blade
<select wire:model="display_mode">
    <option value="list">Por Lista</option>
    <option value="carousel">Por Slides (Carrossel)</option>
</select>
```

**Renderização Condicional:**
```blade
@if($showcase['display_mode'] === 'carousel')
    <div class="splide showcase-carousel-{{ $showcase['id'] }}">
        <div class="splide__track">
            <ul class="splide__list">
                @foreach($showcase['products'] as $p)
                    <li class="splide__slide">
                        <!-- Card do produto -->
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
@else
    <div class="row g-3">
        <!-- Grid atual -->
    </div>
@endif
```

---

## 6. Melhorias Adicionais Sugeridas

### 6.1 Busca de Produtos

**Problema:** Não há funcionalidade de busca na loja.

**Solução:**
- Input de busca no header
- Componente `App\Livewire\Tenant\Search`
- Busca por nome, descrição, categoria
- Resultados em tempo real (Livewire)
- Histórico de buscas (localStorage)

**Prioridade:** Alta

### 6.2 Wishlist / Favoritos

**Problema:** Cliente não pode salvar produtos para depois.

**Solução:**
- Ícone de coração nos cards de produto
- Armazenar em `customer.meta.wishlist`
- Página dedicada de favoritos
- Notificações quando item favorito entrar em promoção

**Prioridade:** Média

### 6.3 Notificações de Pedido

**Problema:** Cliente não recebe atualizações sobre o pedido.

**Solução:**
- Integração com WhatsApp Business API
- Envio automático de mensagens:
  - Pedido confirmado
  - Pedido em preparo
  - Pedido saiu para entrega
  - Pedido entregue
- Configurável pelo admin

**Prioridade:** Alta

### 6.4 Avaliações de Produtos

**Problema:** Sem feedback de outros clientes.

**Solução:**
- Sistema de avaliações (1-5 estrelas)
- Comentários opcionais
- Apenas clientes que compraram podem avaliar
- Exibir média e quantidade de avaliações
- Filtro por avaliação

**Prioridade:** Média

### 6.5 Cupons de Desconto

**Problema:** Sem mecanismo de promoções.

**Solução:**
- Modelo `Coupon` (código, tipo, valor, validade)
- Input no checkout para aplicar cupom
- Tipos: percentual, valor fixo, frete grátis
- Limite de uso por cliente
- Rastreamento de conversão

**Prioridade:** Alta

### 6.6 Programa de Fidelidade

**Problema:** Sem incentivo para recompra.

**Solução:**
- Sistema de pontos por compra
- 1 ponto = R$ 1,00 gasto
- Resgatar pontos como desconto
- Níveis de fidelidade (Bronze, Prata, Ouro)
- Benefícios por nível (frete grátis, desconto extra)

**Prioridade:** Baixa

### 6.7 Checkout em Uma Página

**Problema:** Checkout atual requer múltiplas etapas.

**Solução:**
- Unificar carrinho + dados + pagamento em uma view
- Formulário colapsável por seções
- Validação em tempo real
- Resumo fixo lateral (desktop)
- Botão "Finalizar Pedido" sempre visível

**Prioridade:** Média

### 6.8 Rastreamento de Pedido

**Problema:** Cliente não sabe status do pedido após finalizar.

**Solução:**
- Página pública de rastreamento
- URL única por pedido (hash)
- Timeline visual do status
- Estimativa de entrega
- Mapa de rastreamento (integração futura)

**Prioridade:** Alta

### 6.9 Produtos Relacionados

**Problema:** Sem cross-sell ou upsell.

**Solução:**
- Seção "Você também pode gostar" na página do produto
- Baseado em categoria ou tags
- Seção "Frequentemente comprados juntos"
- Algoritmo simples: mesma categoria + mais vendidos

**Prioridade:** Baixa

### 6.10 Modo Escuro (Dark Mode)

**Problema:** Apenas tema claro disponível.

**Solução:**
- Toggle no header
- Persistir preferência em localStorage
- CSS variables para cores
- Respeitar preferência do sistema (prefers-color-scheme)

**Prioridade:** Baixa

### 6.11 PWA (Progressive Web App)

**Problema:** Sem instalação como app.

**Solução:**
- Manifest.json
- Service Worker para cache
- Ícones para instalação
- Funcionar offline (cache de produtos)
- Push notifications (permissão do usuário)

**Prioridade:** Média

### 6.12 Chat de Suporte

**Problema:** Cliente precisa sair da loja para tirar dúvidas.

**Solução:**
- Widget de chat flutuante
- Integração com WhatsApp Business
- Mensagens pré-definidas (FAQ)
- Horário de atendimento
- Fallback para email se offline

**Prioridade:** Média

---

## 7. Priorização por Impacto

### 7.1 Matriz de Priorização

| Funcionalidade | Impacto UX | Esforço Dev | Prioridade | Sprint |
|----------------|------------|-------------|------------|--------|
| **Login por Telefone** | 🔥 Muito Alto | Médio | P0 | 05 |
| **Ícone de Carrinho** | Alto | Muito Baixo | P0 | 05 |
| **Botão de Conta** | Alto | Baixo | P0 | 05 |
| **Modal Contato/Horário** | Alto | Baixo | P0 | 05 |
| **Display Mode Vitrine** | Médio | Baixo | P0 | 05 |
| **Carrossel de Produtos** | Médio | Médio | P0 | 05 |
| **Área do Cliente** | 🔥 Muito Alto | Alto | P1 | 06 |
| **Busca de Produtos** | Alto | Médio | P1 | 06 |
| **Notificações WhatsApp** | Alto | Alto | P1 | 07 |
| **Rastreamento de Pedido** | Alto | Médio | P1 | 06 |
| **Cupons de Desconto** | Alto | Alto | P2 | 08 |
| **Checkout Uma Página** | Médio | Médio | P2 | 07 |
| **Produtos Relacionados** | Médio | Baixo | P2 | 07 |
| **Chat de Suporte** | Médio | Alto | P2 | 08 |
| **Avaliações** | Médio | Alto | P3 | 09 |
| **PWA** | Médio | Alto | P3 | 10 |
| **Wishlist** | Baixo | Médio | P3 | 09 |
| **Programa Fidelidade** | Baixo | Muito Alto | P3 | 11 |
| **Dark Mode** | Baixo | Baixo | P3 | 09 |

### 7.2 Escopo da Sprint 05

**Funcionalidades Confirmadas:**
1. ✅ Sistema de login por telefone (popup + cookie)
2. ✅ Ícone de carrinho com badge de contador
3. ✅ Botão de acesso à conta do usuário
4. ✅ Modal/botão de contato com horário
5. ✅ Campo display_mode nas vitrines
6. ✅ Implementação de carrossel com Splide.js

**Fora do Escopo (Sprint 05):**
- Área completa do cliente (apenas estrutura básica)
- Histórico de pedidos (sprint 06)
- Busca de produtos (sprint 06)
- Notificações (sprint 07)

---

## 8. Considerações de UX/UI

### 8.1 Popup de Login

**Boas Práticas:**
- ❌ Não exibir imediatamente (esperar 2-3 segundos)
- ✅ Permitir fechar facilmente (X no canto)
- ✅ Não bloquear navegação (pode navegar sem login)
- ✅ Reexibir após 24h se não fez login
- ✅ Não exibir se já autenticado (verificar cookie)
- ✅ Design clean e minimalista
- ✅ Explicar benefícios do login

**Texto Sugerido:**
> **Bem-vindo à [Nome da Loja]!**
> 
> Informe seu telefone para:
> - Acompanhar seus pedidos
> - Salvar seus favoritos
> - Comprar mais rápido
> 
> [Input: Telefone]
> [Botão: Continuar]
> [Link: Agora não]

### 8.2 Responsividade

**Mobile First:**
- Header compacto em mobile
- Ícones sem texto
- Modal full-screen em mobile
- Carrossel com swipe nativo
- Botões com área de toque adequada (min 44x44px)

**Desktop:**
- Header com mais espaço
- Ícones com labels opcionais
- Modal centralizado
- Carrossel com navegação por setas
- Hover states

### 8.3 Acessibilidade

**WCAG 2.1 AA:**
- Contraste adequado (4.5:1 para texto)
- Labels em todos os inputs
- Navegação por teclado (Tab, Enter, Esc)
- ARIA labels em ícones
- Foco visível
- Mensagens de erro claras

### 8.4 Performance

**Otimizações:**
- Lazy load de carrosséis
- Sprites de ícones (Font Awesome)
- Minificação de CSS/JS
- Cache de assets
- CDN para bibliotecas (Splide.js)
- Debounce em inputs de busca

---

## 9. Riscos e Mitigações

### 9.1 Riscos Técnicos

| Risco | Probabilidade | Impacto | Mitigação |
|-------|---------------|---------|-----------|
| Conflito de sessão entre tenants | Média | Alto | Usar cookie específico por tenant |
| Cookie bloqueado por browser | Baixa | Alto | Fallback para sessionStorage |
| Carrossel não funcionar em mobile | Baixa | Médio | Testes em múltiplos devices |
| Performance com muitos produtos | Média | Médio | Paginação e lazy load |
| Login sem verificação (segurança) | Alta | Médio | Adicionar SMS OTP em sprint futura |

### 9.2 Riscos de UX

| Risco | Probabilidade | Impacto | Mitigação |
|-------|---------------|---------|-----------|
| Popup irritante para usuários | Alta | Alto | Delay de 3s + opção "não mostrar novamente" |
| Usuários não entendem login sem senha | Média | Médio | Texto explicativo claro |
| Confusão com múltiplos ícones no header | Baixa | Baixo | Tooltips e labels |
| Carrossel dificulta visualização | Média | Médio | Manter opção de lista |

### 9.3 Riscos de Negócio

| Risco | Probabilidade | Impacto | Mitigação |
|-------|---------------|---------|-----------|
| Abandono por obrigatoriedade de login | Média | Alto | Login opcional (pode comprar sem) |
| Aumento de bounce rate | Baixa | Médio | A/B testing do popup |
| Clientes não retornam à conta | Alta | Baixo | Email/WhatsApp de lembrete |

---

## 10. Métricas de Sucesso

### 10.1 KPIs Primários

| Métrica | Baseline | Meta | Como Medir |
|---------|----------|------|------------|
| Taxa de login | 0% | 40% | Logins / Visitantes únicos |
| Taxa de conversão | ? | +15% | Pedidos / Visitantes |
| Abandono de carrinho | ? | -20% | Carrinhos abandonados / Carrinhos criados |
| Pedidos recorrentes | ? | +30% | Clientes com 2+ pedidos |

### 10.2 KPIs Secundários

| Métrica | Baseline | Meta | Como Medir |
|---------|----------|------|------------|
| Tempo médio no site | ? | +25% | Google Analytics |
| Páginas por sessão | ? | +20% | Google Analytics |
| Taxa de rejeição | ? | -15% | Google Analytics |
| Cliques no botão de contato | 0 | 100/mês | Event tracking |
| Uso do carrossel | 0 | 60% | Cliques em slides |

### 10.3 Tracking de Eventos

**Google Analytics / Mixpanel:**
```javascript
// Login
gtag('event', 'login', { method: 'phone' });

// Carrinho
gtag('event', 'add_to_cart', { value: productPrice });
gtag('event', 'view_cart');

// Contato
gtag('event', 'contact_modal_open');
gtag('event', 'whatsapp_click');

// Carrossel
gtag('event', 'carousel_interaction', { showcase_id: id });

// Conta
gtag('event', 'view_account');
gtag('event', 'view_orders');
```

---

## 11. Dependências e Integrações

### 11.1 Bibliotecas Externas

| Biblioteca | Versão | Uso | CDN/NPM |
|------------|--------|-----|---------|
| Splide.js | 4.x | Carrossel | CDN |
| Font Awesome | 6.x | Ícones | CDN (já usado) |
| Alpine.js | 3.x | Interatividade | CDN (já usado) |
| Livewire | 3.x | Componentes | Composer (já usado) |

### 11.2 APIs e Serviços

| Serviço | Uso | Necessário |
|---------|-----|------------|
| WhatsApp Business API | Notificações | Sprint 07 |
| SMS Gateway (Twilio) | Verificação OTP | Sprint 06 |
| Google Analytics | Tracking | Já configurado |
| CDN (Cloudflare) | Assets | Opcional |

---

## 12. Cronograma Estimado

### 12.1 Breakdown de Tarefas (Sprint 05)

| Tarefa | Estimativa | Dependências |
|--------|------------|--------------|
| **1. Sistema de Login** | | |
| 1.1 Criar componente LoginPopup | 4h | - |
| 1.2 Implementar CustomerAuthService | 6h | - |
| 1.3 Criar middleware de autenticação | 3h | 1.2 |
| 1.4 Adicionar session_token ao Customer | 2h | - |
| 1.5 Testes de autenticação | 4h | 1.1-1.4 |
| **2. Header Melhorado** | | |
| 2.1 Criar componente tenant-header | 3h | - |
| 2.2 Adicionar ícone de carrinho + badge | 2h | 2.1 |
| 2.3 Adicionar botão de conta | 2h | 2.1, 1.3 |
| 2.4 Adicionar botão de contato | 2h | 2.1 |
| 2.5 Responsividade do header | 3h | 2.1-2.4 |
| **3. Modal de Contato** | | |
| 3.1 Criar componente ContactModal | 4h | - |
| 3.2 Integrar com TenantHelper | 2h | 3.1 |
| 3.3 Design e responsividade | 3h | 3.1 |
| **4. Display Mode Vitrines** | | |
| 4.1 Migração display_mode | 1h | - |
| 4.2 Atualizar formulário de showcase | 2h | 4.1 |
| 4.3 Atualizar modelo Showcase | 1h | 4.1 |
| **5. Carrossel** | | |
| 5.1 Integrar Splide.js | 2h | - |
| 5.2 Criar template de carrossel | 4h | 5.1 |
| 5.3 Renderização condicional | 2h | 4.3, 5.2 |
| 5.4 Testes e ajustes | 3h | 5.1-5.3 |
| **6. Área do Cliente (Básica)** | | |
| 6.1 Criar rota e componente Account | 3h | 1.3 |
| 6.2 Layout da área do cliente | 4h | 6.1 |
| 6.3 Página de perfil (edição) | 5h | 6.1 |
| **7. Testes e QA** | | |
| 7.1 Testes unitários | 6h | - |
| 7.2 Testes de integração | 4h | - |
| 7.3 Testes manuais (devices) | 4h | - |
| 7.4 Correções de bugs | 6h | 7.1-7.3 |

**Total Estimado:** 82 horas (~2 semanas com 1 dev full-time)

### 12.2 Fases de Entrega

**Fase 1 (Semana 1):**
- Sistema de login completo
- Header melhorado (ícones + badges)
- Modal de contato

**Fase 2 (Semana 2):**
- Display mode nas vitrines
- Carrossel implementado
- Área do cliente básica
- Testes e QA

---

## 13. Documentação Necessária

### 13.1 Para Desenvolvedores

- [ ] Guia de autenticação de clientes
- [ ] API do CustomerAuthService
- [ ] Estrutura de cookies e sessões
- [ ] Componentes Livewire criados
- [ ] Helpers e utilities
- [ ] Testes automatizados

### 13.2 Para Usuários (Admin)

- [ ] Como configurar display mode das vitrines
- [ ] Melhores práticas para carrosséis
- [ ] Gerenciamento de clientes
- [ ] Análise de métricas de login

### 13.3 Para Clientes Finais

- [ ] Como criar uma conta
- [ ] Como acessar minha conta
- [ ] Como ver meus pedidos
- [ ] Como editar meus dados
- [ ] FAQ sobre login

---

## 14. Conclusão

Esta sprint representa um salto significativo na experiência do comprador, focando em três pilares fundamentais:

### 14.1 Redução de Fricção
- Login simplificado sem senha
- Acesso rápido ao carrinho e conta
- Informações essenciais sempre visíveis

### 14.2 Personalização
- Sessão persistente do cliente
- Dados pré-preenchidos
- Histórico de pedidos acessível

### 14.3 Engajamento
- Visual moderno com carrosséis
- Navegação intuitiva
- Acesso fácil a contato e suporte

### 14.4 Próximos Passos

**Sprint 06 - Área do Cliente Completa:**
- Histórico de pedidos detalhado
- Gerenciamento de endereços
- Busca de produtos
- Rastreamento de pedidos

**Sprint 07 - Notificações e Comunicação:**
- Integração WhatsApp Business
- Notificações de status de pedido
- Chat de suporte
- Email marketing

**Sprint 08 - Promoções e Conversão:**
- Sistema de cupons
- Checkout otimizado
- Produtos relacionados
- Upsell e cross-sell

**Sprint 09 - Engajamento Avançado:**
- Avaliações e reviews
- Wishlist
- Dark mode
- PWA básico

**Sprint 10+ - Fidelização:**
- Programa de pontos
- Gamificação
- Recomendações personalizadas
- Analytics avançado

---

## Anexo A: Wireframes Conceituais

### A.1 Popup de Login
```
┌─────────────────────────────────────┐
│  ╳                                  │
│                                     │
│     Bem-vindo à [Loja]!            │
│                                     │
│  Informe seu telefone para:        │
│  • Acompanhar seus pedidos         │
│  • Salvar seus favoritos           │
│  • Comprar mais rápido             │
│                                     │
│  ┌───────────────────────────────┐ │
│  │ (11) 99999-9999               │ │
│  └───────────────────────────────┘ │
│                                     │
│  ┌───────────────────────────────┐ │
│  │       Continuar               │ │
│  └───────────────────────────────┘ │
│                                     │
│          Agora não                 │
│                                     │
└─────────────────────────────────────┘
```

### A.2 Header Desktop
```
┌─────────────────────────────────────────────────────────┐
│  [Logo] Loja Exemplo  [Aberto]                          │
│  ⏱ 45 min • 🚚 R$ 5,00 • Pedido mín. R$ 20,00          │
│                                                          │
│                              [🕐] [🛒 3] [👤]           │
└─────────────────────────────────────────────────────────┘
```

### A.3 Header Mobile
```
┌───────────────────────────┐
│ [Logo] Loja  [Aberto]    │
│                           │
│              [🕐][🛒3][👤]│
└───────────────────────────┘
```

### A.4 Modal de Contato
```
┌─────────────────────────────────────┐
│  Informações de Contato        ╳   │
├─────────────────────────────────────┤
│                                     │
│  Status: 🟢 Aberto agora           │
│                                     │
│  📅 Horário de Funcionamento       │
│  ┌─────────────────────────────┐  │
│  │ Seg-Sex: 08:00 - 18:00      │  │
│  │ Sábado:  10:00 - 22:00      │  │
│  │ Domingo: Fechado            │  │
│  └─────────────────────────────┘  │
│                                     │
│  📞 Telefone                       │
│  (11) 3333-4444                    │
│                                     │
│  💬 WhatsApp                       │
│  (11) 99999-9999                   │
│  [Abrir WhatsApp]                  │
│                                     │
│  ✉️ Email                          │
│  contato@loja.com                  │
│                                     │
└─────────────────────────────────────┘
```

### A.5 Carrossel de Produtos
```
┌─────────────────────────────────────────────────────────┐
│  Destaques                                 [← →]        │
├─────────────────────────────────────────────────────────┤
│                                                          │
│  ┌──────┐  ┌──────┐  ┌──────┐  ┌───                   │
│  │[IMG] │  │[IMG] │  │[IMG] │  │[I                    │
│  │Prod 1│  │Prod 2│  │Prod 3│  │Pr                    │
│  │R$10  │  │R$15  │  │R$20  │  │R$                    │
│  └──────┘  └──────┘  └──────┘  └───                   │
│                                                          │
│  ● ○ ○                                                  │
└─────────────────────────────────────────────────────────┘
```

---

## Anexo B: Estrutura de Arquivos

```
src/
├── app/
│   ├── Http/
│   │   └── Middleware/
│   │       └── TenantCustomerAuth.php          # Novo
│   ├── Livewire/
│   │   └── Tenant/
│   │       ├── Auth/
│   │       │   └── LoginPopup.php              # Novo
│   │       ├── Account/
│   │       │   ├── Index.php                   # Novo
│   │       │   ├── Orders.php                  # Novo
│   │       │   ├── OrderDetail.php             # Novo
│   │       │   ├── Profile.php                 # Novo
│   │       │   └── Addresses.php               # Novo
│   │       └── ContactModal.php                # Novo
│   ├── Services/
│   │   └── CustomerAuthService.php             # Novo
│   └── Models/
│       ├── Customer.php                         # Modificado
│       └── Showcase.php                         # Modificado
├── database/
│   └── migrations/
│       ├── 2026_01_13_add_session_token_to_customers.php  # Novo
│       └── 2026_01_13_add_display_mode_to_showcases.php   # Novo
├── resources/
│   ├── views/
│   │   ├── components/
│   │   │   └── tenant-header.blade.php         # Novo
│   │   └── livewire/
│   │       └── tenant/
│   │           ├── auth/
│   │           │   └── login-popup.blade.php   # Novo
│   │           ├── account/
│   │           │   ├── index.blade.php         # Novo
│   │           │   ├── orders.blade.php        # Novo
│   │           │   ├── order-detail.blade.php  # Novo
│   │           │   ├── profile.blade.php       # Novo
│   │           │   └── addresses.blade.php     # Novo
│   │           ├── contact-modal.blade.php     # Novo
│   │           └── home.blade.php              # Modificado
│   └── js/
│       └── carousel.js                          # Novo
└── routes/
    └── web.php                                  # Modificado
```

---

**Documento preparado para:** Sprint 05 - Buyer Experience  
**Próxima etapa:** Criação do arquivo `02-sprint.md` com planejamento detalhado  
**Revisão:** Aguardando aprovação para iniciar desenvolvimento
