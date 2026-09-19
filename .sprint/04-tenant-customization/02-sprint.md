# Sprint: Personalização Avançada do Tenant

**Data:** 2026-01-12  
**Sprint:** 04-tenant-customization  
**Duração Estimada:** 2-3 semanas

---

## 1. Objetivo

Expandir as funcionalidades de personalização do tenant para que cada loja possa apresentar informações completas ao cliente final, aumentando a credibilidade, profissionalismo e conversão.

---

## 2. Escopo

### 2.1 Incluído nesta Sprint

| Épico | Funcionalidades |
|-------|-----------------|
| **Informações da Loja** | Descrição curta, descrição completa (sobre) |
| **Contato** | Telefone, WhatsApp, email, botão flutuante WhatsApp |
| **Localização** | Endereço completo, link Google Maps |
| **Horário de Funcionamento** | Configuração por dia, status aberto/fechado automático |
| **Redes Sociais** | Instagram, Facebook, TikTok, YouTube |
| **Visual Avançado** | Banner/capa, favicon |
| **Configurações de Entrega** | Pedido mínimo, taxa de entrega, tempo estimado |
| **SEO Básico** | Meta title, meta description, Open Graph image |

### 2.2 Fora do Escopo (Futuro)

- Cupons de desconto
- Programa de fidelidade
- Sistema de avaliações
- Áreas de entrega com geolocalização
- Chat integrado
- Notificações push
- Dark mode

---

## 3. User Stories

### 3.1 Épico: Informações da Loja

**US-01: Descrição da Loja**
> Como dono de loja, quero adicionar uma descrição curta e uma descrição completa da minha loja, para que os clientes entendam melhor o meu negócio.

**Critérios de Aceite:**
- [ ] Campo "Descrição curta" (max 160 caracteres) para SEO
- [ ] Campo "Sobre a loja" (texto rico, max 2000 caracteres)
- [ ] Opção de exibir/ocultar na storefront
- [ ] Preview em tempo real

---

### 3.2 Épico: Contato

**US-02: Informações de Contato**
> Como dono de loja, quero configurar telefone, WhatsApp e email, para que os clientes possam entrar em contato.

**Critérios de Aceite:**
- [ ] Campo telefone com máscara brasileira
- [ ] Campo WhatsApp (apenas números, com validação)
- [ ] Campo email com validação
- [ ] Toggle para exibir botão flutuante do WhatsApp na storefront

**US-03: Botão WhatsApp Flutuante**
> Como cliente, quero ver um botão de WhatsApp flutuante na loja, para tirar dúvidas rapidamente.

**Critérios de Aceite:**
- [ ] Botão fixo no canto inferior direito
- [ ] Abre WhatsApp Web/App com número pré-configurado
- [ ] Mensagem padrão configurável (opcional)
- [ ] Pode ser desabilitado pelo admin

---

### 3.3 Épico: Localização

**US-04: Endereço da Loja**
> Como dono de loja, quero cadastrar o endereço completo, para que os clientes saibam onde fico localizado.

**Critérios de Aceite:**
- [ ] Campos: Rua, Número, Complemento, Bairro, Cidade, Estado, CEP
- [ ] Campo para link do Google Maps
- [ ] Toggle para exibir na storefront
- [ ] Exibição formatada no rodapé ou página "Sobre"

---

### 3.4 Épico: Horário de Funcionamento

**US-05: Configurar Horários**
> Como dono de loja, quero definir os horários de funcionamento de cada dia da semana, para que os clientes saibam quando posso atender.

**Critérios de Aceite:**
- [ ] Configuração individual por dia (Seg-Dom)
- [ ] Campos: horário abertura, horário fechamento, fechado (checkbox)
- [ ] Suporte a múltiplos períodos por dia (ex: 11h-14h e 18h-22h) - opcional
- [ ] Timezone configurável (default: America/Sao_Paulo)

**US-06: Status Aberto/Fechado Automático**
> Como cliente, quero ver se a loja está aberta ou fechada no momento, para saber se posso fazer um pedido.

**Critérios de Aceite:**
- [ ] Badge "Aberto" ou "Fechado" na home da loja
- [ ] Cálculo baseado no horário configurado + timezone
- [ ] Toggle para habilitar/desabilitar fechamento automático
- [ ] Opção de "pausar pedidos" manualmente (override)

---

### 3.5 Épico: Redes Sociais

**US-07: Links de Redes Sociais**
> Como dono de loja, quero adicionar links das minhas redes sociais, para que os clientes me sigam.

**Critérios de Aceite:**
- [ ] Campos: Instagram, Facebook, TikTok, YouTube
- [ ] Validação de URL
- [ ] Ícones exibidos no rodapé da storefront
- [ ] Abre em nova aba

---

### 3.6 Épico: Visual Avançado

**US-08: Banner/Imagem de Capa**
> Como dono de loja, quero adicionar um banner de destaque na home, para deixar a loja mais atrativa.

**Critérios de Aceite:**
- [ ] Upload de imagem (JPG/PNG, max 5MB)
- [ ] Dimensões recomendadas: 1200x400px
- [ ] Exibido no topo da home da storefront
- [ ] Preview em tempo real no admin
- [ ] Opção de remover

**US-09: Favicon Personalizado**
> Como dono de loja, quero ter um favicon personalizado, para que minha marca apareça na aba do navegador.

**Critérios de Aceite:**
- [ ] Upload de imagem (ICO/PNG, max 500KB)
- [ ] Dimensões: 32x32 ou 64x64
- [ ] Aplicado automaticamente no layout

---

### 3.7 Épico: Configurações de Entrega

**US-10: Pedido Mínimo**
> Como dono de loja, quero definir um valor mínimo de pedido, para garantir viabilidade das entregas.

**Critérios de Aceite:**
- [ ] Campo valor mínimo em reais
- [ ] Exibido na storefront (checkout)
- [ ] Bloqueia finalização se carrinho < mínimo
- [ ] Mensagem clara ao cliente

**US-11: Taxa de Entrega**
> Como dono de loja, quero definir uma taxa de entrega padrão, para cobrir custos de delivery.

**Critérios de Aceite:**
- [ ] Campo taxa de entrega em reais
- [ ] Exibida no carrinho e checkout
- [ ] Somada ao total do pedido
- [ ] Opção de entrega grátis acima de X reais

**US-12: Tempo Estimado de Entrega**
> Como cliente, quero ver o tempo estimado de entrega, para saber quando receberei meu pedido.

**Critérios de Aceite:**
- [ ] Campo tempo em minutos
- [ ] Exibido na home e checkout
- [ ] Formato amigável (ex: "45-60 min")

---

### 3.8 Épico: SEO Básico

**US-13: Meta Tags Personalizadas**
> Como dono de loja, quero configurar título e descrição para Google, para melhorar meu posicionamento.

**Critérios de Aceite:**
- [ ] Campo meta title (max 60 caracteres)
- [ ] Campo meta description (max 160 caracteres)
- [ ] Preview de como aparece no Google
- [ ] Aplicado no `<head>` da storefront

**US-14: Open Graph Image**
> Como dono de loja, quero configurar a imagem que aparece ao compartilhar minha loja, para ter mais cliques.

**Critérios de Aceite:**
- [ ] Upload de imagem (JPG/PNG, 1200x630 recomendado)
- [ ] Aplicado como `og:image` no `<head>`
- [ ] Fallback para banner ou logo se não configurado

---

## 4. Tarefas Técnicas

### 4.1 Backend

| ID | Tarefa | Estimativa |
|----|--------|------------|
| T-01 | Criar migration para novos campos em `settings` (documentar estrutura) | 1h |
| T-02 | Atualizar `TenantFactory` com novos campos | 30min |
| T-03 | Criar `Livewire\Admin\Tenant\StoreInfo` (descrição, sobre) | 2h |
| T-04 | Criar `Livewire\Admin\Tenant\ContactSettings` (telefone, WhatsApp, email) | 2h |
| T-05 | Criar `Livewire\Admin\Tenant\AddressSettings` (endereço) | 2h |
| T-06 | Criar `Livewire\Admin\Tenant\BusinessHours` (horários) | 4h |
| T-07 | Criar `Livewire\Admin\Tenant\SocialLinks` (redes sociais) | 1h |
| T-08 | Criar `Livewire\Admin\Tenant\DeliverySettings` (taxa, mínimo, tempo) | 2h |
| T-09 | Criar `Livewire\Admin\Tenant\SeoSettings` (meta tags, OG) | 2h |
| T-10 | Expandir `Customization.php` para banner e favicon | 2h |
| T-11 | Criar helper `TenantHelper::isOpen()` para status aberto/fechado | 2h |
| T-12 | Atualizar checkout para validar pedido mínimo e somar taxa entrega | 3h |

### 4.2 Frontend (Storefront)

| ID | Tarefa | Estimativa |
|----|--------|------------|
| T-13 | Adicionar banner na home | 1h |
| T-14 | Adicionar badge aberto/fechado na home | 1h |
| T-15 | Adicionar seção "Sobre" na home ou página dedicada | 2h |
| T-16 | Adicionar rodapé com contato, endereço, redes sociais | 2h |
| T-17 | Adicionar botão flutuante WhatsApp | 1h |
| T-18 | Adicionar horários de funcionamento na home | 1h |
| T-19 | Adicionar tempo estimado e taxa de entrega no checkout | 1h |
| T-20 | Aplicar meta tags e OG no layout | 1h |
| T-21 | Aplicar favicon dinâmico | 30min |

### 4.3 Admin (UI)

| ID | Tarefa | Estimativa |
|----|--------|------------|
| T-22 | Reorganizar menu admin com novas seções | 1h |
| T-23 | Criar views Blade para cada novo componente Livewire | 4h |
| T-24 | Adicionar navegação por abas ou sidebar nas configurações | 2h |

---

## 5. Cronograma Sugerido

### Semana 1: Fundação + Informações Básicas

| Dia | Tarefas |
|-----|---------|
| D1 | T-01, T-02, T-03 (migration, factory, store info) |
| D2 | T-04, T-05 (contato, endereço) |
| D3 | T-06 (horários - complexo) |
| D4 | T-07, T-08 (redes sociais, entrega) |
| D5 | T-09, T-10 (SEO, banner/favicon) |

### Semana 2: Frontend + Integração

| Dia | Tarefas |
|-----|---------|
| D1 | T-11, T-13, T-14 (helper isOpen, banner, badge) |
| D2 | T-15, T-16 (sobre, rodapé) |
| D3 | T-17, T-18, T-19 (WhatsApp, horários, checkout) |
| D4 | T-20, T-21, T-22 (meta tags, favicon, menu) |
| D5 | T-23, T-24 (views admin, navegação) |

### Semana 3: Testes + Ajustes

| Dia | Tarefas |
|-----|---------|
| D1-D2 | Testes de integração |
| D3-D4 | Ajustes de UI/UX |
| D5 | Code review, deploy staging |

---

## 6. Métricas de Sucesso

| Métrica | Baseline | Meta |
|---------|----------|------|
| Campos de personalização disponíveis | 7 | 25+ |
| Tenants com informações completas | 0% | 50% |
| Tempo médio de configuração inicial | N/A | < 15 min |
| Satisfação do admin (feedback) | N/A | 4+/5 |

---

## 7. Riscos e Mitigações

| Risco | Probabilidade | Impacto | Mitigação |
|-------|---------------|---------|-----------|
| Complexidade do horário de funcionamento | Média | Médio | Começar simples (1 período/dia), expandir depois |
| Performance com muitos campos JSONB | Baixa | Baixo | Índices GIN se necessário |
| Migração de tenants existentes | Baixa | Médio | Valores default sensatos, não obrigatórios |
| Sobrecarga de UI no admin | Média | Médio | Organizar em abas/seções claras |

---

## 8. Definição de Pronto (DoD)

- [ ] Código implementado e funcionando
- [ ] Testes unitários para helpers críticos (isOpen)
- [ ] Testes manuais em staging
- [ ] Documentação atualizada (se aplicável)
- [ ] Code review aprovado
- [ ] Deploy em produção
