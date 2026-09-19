# Análise de Personalização do Tenant

**Data:** 2026-01-12  
**Sprint:** 04-tenant-customization  
**Autor:** Cascade (AI Assistant)

---

## 1. Visão Geral

O Order Web é uma plataforma multi-tenant para delivery/e-commerce de encomendas. Atualmente, cada tenant (loja) possui opções básicas de personalização visual. Esta análise visa identificar oportunidades de expansão das funcionalidades de customização para tornar cada tenant mais completo e informativo para o cliente final.

---

## 2. Estado Atual da Personalização

### 2.1 Campos do Tenant (Banco de Dados)

| Campo | Tipo | Descrição |
|-------|------|-----------|
| `name` | string | Nome da loja |
| `slug` | string | URL única do tenant |
| `is_active` | boolean | Status ativo/inativo |
| `primary_color` | string | Cor primária (hex) |
| `secondary_color` | string | Cor secundária (hex) |
| `logo_path` | string | Caminho do logo |
| `settings` | jsonb | Configurações flexíveis |

### 2.2 Configurações em `settings` (JSONB)

```json
{
  "theme": {
    "font_family": "Inter, system-ui, ..."
  },
  "payment": {
    "methods": [...],
    "mercadopago": {
      "public_key": "...",
      "access_token": "enc:...",
      "webhook_secret": "enc:..."
    }
  }
}
```

### 2.3 Funcionalidades de Personalização Existentes

| Funcionalidade | Status | Localização |
|----------------|--------|-------------|
| Nome da loja | ✅ Implementado | `Customization.php` |
| Logo | ✅ Implementado | `Customization.php` |
| Cor primária | ✅ Implementado | `Customization.php` |
| Cor secundária | ✅ Implementado | `Customization.php` |
| Fonte (4 opções) | ✅ Implementado | `Customization.php` |
| Slug/URL | ✅ Implementado | `Settings.php` |
| Métodos de pagamento | ✅ Implementado | `PaymentSettings.php` |

### 2.4 Componentes Livewire Atuais

- `App\Livewire\Admin\Tenant\Customization` - Personalização visual
- `App\Livewire\Admin\Tenant\Settings` - Configurações de URL/slug
- `App\Livewire\Admin\Tenant\PaymentSettings` - Métodos de pagamento

### 2.5 Limitações Atuais

1. **Sem informações de contato** - Telefone, WhatsApp, email não configuráveis
2. **Sem horário de funcionamento** - Cliente não sabe quando a loja está aberta
3. **Sem endereço/localização** - Não há informação de onde fica o estabelecimento
4. **Sem redes sociais** - Links para Instagram, Facebook, etc.
5. **Sem descrição/sobre** - Não há texto institucional sobre a loja
6. **Sem banner/imagem de capa** - Apenas logo, sem destaque visual
7. **Sem políticas** - Entrega, troca, privacidade
8. **Sem configuração de entrega** - Áreas, taxas, tempo estimado
9. **Sem SEO** - Meta tags, Open Graph não configuráveis

---

## 3. Pesquisa de Mercado

### 3.1 Concorrentes Analisados

| Plataforma | Tipo | Diferenciais |
|------------|------|--------------|
| **DeliveryVip** | White-label delivery | Zero comissão, cardápio personalizável, cupons, relatórios |
| **Cardápio Web** | Cardápio digital | Chatbot WhatsApp, programa fidelidade, disparo em massa |
| **Kyte** | Cardápio/PDV | IA para descrições, integração redes sociais, variações |
| **iFood** | Marketplace | Avaliações, tempo estimado, status em tempo real |
| **Rappi** | Marketplace | Multi-categoria, chat, rastreamento |

### 3.2 Funcionalidades Comuns no Mercado

#### Informações Básicas da Loja
- ✅ Nome e logo
- ❌ Descrição/sobre
- ❌ Telefone/WhatsApp
- ❌ Email
- ❌ Endereço completo
- ❌ Horário de funcionamento
- ❌ Redes sociais

#### Personalização Visual
- ✅ Cores primária/secundária
- ✅ Fonte
- ❌ Banner/capa
- ❌ Favicon personalizado
- ❌ Imagens de fundo
- ❌ Tema claro/escuro

#### Configurações de Operação
- ❌ Áreas de entrega
- ❌ Taxas de entrega por região
- ❌ Tempo estimado de entrega
- ❌ Pedido mínimo
- ❌ Status aberto/fechado automático
- ❌ Pausar pedidos temporariamente

#### Marketing e Engajamento
- ❌ Cupons de desconto
- ❌ Programa de fidelidade
- ❌ Avaliações de clientes
- ❌ Produtos em destaque
- ❌ Notificações push

#### SEO e Compartilhamento
- ❌ Meta title/description
- ❌ Open Graph (imagem, título)
- ❌ Favicon
- ❌ Google Analytics ID

---

## 4. Análise Comparativa

### 4.1 O que Temos vs O que Falta

| Categoria | Temos | Falta (Essencial) | Falta (Diferencial) |
|-----------|-------|-------------------|---------------------|
| **Identidade** | Nome, logo, cores, fonte | Banner, favicon | Tema dark mode |
| **Contato** | - | WhatsApp, telefone, email | Chat integrado |
| **Localização** | - | Endereço, cidade | Mapa integrado |
| **Horários** | - | Horário funcionamento | Status automático |
| **Social** | - | Instagram, Facebook | TikTok, YouTube |
| **Operação** | - | Pedido mínimo, taxa entrega | Áreas de entrega |
| **Marketing** | - | Cupons básicos | Fidelidade, avaliações |
| **SEO** | - | Meta tags básicas | Schema.org, sitemap |

### 4.2 Priorização por Impacto

| Prioridade | Funcionalidade | Impacto Cliente | Esforço Dev |
|------------|----------------|-----------------|-------------|
| **P0** | Horário de funcionamento | Alto | Baixo |
| **P0** | WhatsApp/Telefone | Alto | Baixo |
| **P0** | Endereço | Alto | Baixo |
| **P1** | Banner/Capa | Médio | Baixo |
| **P1** | Redes sociais | Médio | Baixo |
| **P1** | Descrição/Sobre | Médio | Baixo |
| **P1** | Pedido mínimo | Alto | Médio |
| **P2** | Taxa de entrega | Alto | Médio |
| **P2** | Meta tags SEO | Médio | Baixo |
| **P2** | Favicon | Baixo | Baixo |
| **P3** | Cupons de desconto | Alto | Alto |
| **P3** | Programa fidelidade | Alto | Alto |
| **P3** | Avaliações | Médio | Alto |

---

## 5. Proposta de Estrutura de Dados

### 5.1 Novos Campos Sugeridos para `settings`

```json
{
  "theme": {
    "font_family": "Inter, ...",
    "banner_path": "tenants/{id}/banner.jpg",
    "favicon_path": "tenants/{id}/favicon.ico",
    "dark_mode_enabled": false
  },
  "contact": {
    "phone": "+55 11 99999-9999",
    "whatsapp": "+5511999999999",
    "email": "contato@loja.com",
    "show_whatsapp_button": true
  },
  "address": {
    "street": "Rua Exemplo, 123",
    "neighborhood": "Centro",
    "city": "São Paulo",
    "state": "SP",
    "zip_code": "01234-567",
    "complement": "Sala 1",
    "google_maps_url": "https://maps.google.com/...",
    "show_on_storefront": true
  },
  "business_hours": {
    "enabled": true,
    "timezone": "America/Sao_Paulo",
    "auto_close": true,
    "schedule": {
      "monday": { "open": "08:00", "close": "18:00", "closed": false },
      "tuesday": { "open": "08:00", "close": "18:00", "closed": false },
      "wednesday": { "open": "08:00", "close": "18:00", "closed": false },
      "thursday": { "open": "08:00", "close": "18:00", "closed": false },
      "friday": { "open": "08:00", "close": "22:00", "closed": false },
      "saturday": { "open": "10:00", "close": "22:00", "closed": false },
      "sunday": { "open": null, "close": null, "closed": true }
    }
  },
  "social": {
    "instagram": "https://instagram.com/loja",
    "facebook": "https://facebook.com/loja",
    "tiktok": null,
    "youtube": null
  },
  "about": {
    "short_description": "Descrição curta para SEO (160 chars)",
    "full_description": "Texto completo sobre a loja...",
    "show_on_storefront": true
  },
  "delivery": {
    "minimum_order_cents": 2000,
    "default_fee_cents": 500,
    "estimated_time_minutes": 45,
    "free_delivery_above_cents": 10000
  },
  "seo": {
    "meta_title": "Loja | Encomendas sob medida",
    "meta_description": "Descrição para Google...",
    "og_image_path": "tenants/{id}/og-image.jpg",
    "google_analytics_id": "G-XXXXXXXXXX"
  },
  "payment": { ... }
}
```

### 5.2 Organização em Abas/Seções

1. **Identidade Visual** - Logo, banner, cores, fonte, favicon
2. **Informações da Loja** - Nome, descrição, sobre
3. **Contato** - Telefone, WhatsApp, email
4. **Localização** - Endereço completo
5. **Horário de Funcionamento** - Dias e horários
6. **Redes Sociais** - Links das redes
7. **Configurações de Entrega** - Taxa, pedido mínimo, tempo
8. **SEO** - Meta tags, Open Graph
9. **Pagamentos** - (já existe)

---

## 6. Conclusão

A aplicação atual possui uma base sólida de personalização visual (cores, fonte, logo), mas carece de informações essenciais que o cliente final espera encontrar em uma loja online:

- **Informações de contato** para tirar dúvidas
- **Horário de funcionamento** para saber quando pode pedir
- **Endereço** para saber de onde vem o produto
- **Redes sociais** para conhecer melhor a marca

A implementação dessas funcionalidades é de **baixo esforço técnico** (maioria são campos de texto/configuração) mas de **alto impacto** na experiência do cliente e na credibilidade da loja.

---

## Anexo: Arquivos Relacionados

```
src/
├── app/
│   ├── Livewire/Admin/Tenant/
│   │   ├── Customization.php      # Personalização visual atual
│   │   ├── Settings.php           # Configuração de slug
│   │   └── PaymentSettings.php    # Métodos de pagamento
│   └── Models/
│       └── Tenant.php             # Modelo com settings JSONB
├── database/
│   └── migrations/
│       └── 2025_12_24_002453_create_tenants_table.php
└── resources/views/
    ├── livewire/admin/tenant/
    │   └── customization.blade.php
    └── partials/
        └── tenant-theme.blade.php  # CSS vars do tema
```
