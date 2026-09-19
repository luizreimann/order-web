# Planejamento da Sprint 05 - Buyer Experience

**Data de Início:** 2026-01-13  
**Data de Término:** 2026-01-27 (2 semanas)  
**Sprint:** 05-buyer-experience  
**Responsável:** Time de Desenvolvimento

---

## 1. Objetivos da Sprint

### 1.1 Objetivo Principal
Implementar melhorias críticas na experiência do comprador da tenant, focando em autenticação simplificada, navegação intuitiva e visualização aprimorada de produtos.

### 1.2 Objetivos Específicos
- ✅ Implementar sistema de login por telefone com popup e sessão persistente
- ✅ Modernizar header com ícones e badges
- ✅ Criar modal de contato com horário de funcionamento
- ✅ Adicionar modo de exibição em carrossel para vitrines
- ✅ Estruturar área básica do cliente

### 1.3 Métricas de Sucesso
- 40% dos visitantes fazem login
- 15% de aumento na taxa de conversão
- 20% de redução no abandono de carrinho
- 30% de aumento em pedidos recorrentes

---

## 2. Backlog da Sprint

### 2.1 User Stories

#### US-01: Login por Telefone
**Como** comprador  
**Quero** fazer login apenas com meu telefone  
**Para** acessar minha conta rapidamente sem precisar de senha

**Critérios de Aceitação:**
- [ ] Popup aparece 3 segundos após carregar a homepage
- [ ] Popup não aparece se usuário já está autenticado
- [ ] Popup pode ser fechado e não reaparece por 24h
- [ ] Input aceita telefone em formato brasileiro
- [ ] Validação de telefone em tempo real
- [ ] Cria Customer automaticamente se não existir
- [ ] Gera cookie de sessão com 90 dias de validade
- [ ] Cookie é específico por tenant
- [ ] Usuário é redirecionado para onde estava após login

**Estimativa:** 13 pontos (19h)

---

#### US-02: Ícone de Carrinho com Badge
**Como** comprador  
**Quero** ver um ícone de carrinho com contador de itens  
**Para** saber rapidamente quantos produtos adicionei

**Critérios de Aceitação:**
- [ ] Botão "Carrinho" substituído por ícone de carrinho
- [ ] Badge vermelha exibe quantidade de itens
- [ ] Badge não aparece quando carrinho está vazio
- [ ] Contador atualiza em tempo real ao adicionar/remover produtos
- [ ] Ícone é responsivo (visível em mobile e desktop)
- [ ] Tooltip "Carrinho" aparece no hover (desktop)

**Estimativa:** 3 pontos (4h)

---

#### US-03: Botão de Conta do Usuário
**Como** comprador autenticado  
**Quero** acessar minha conta facilmente  
**Para** ver meus pedidos e editar meus dados

**Critérios de Aceitação:**
- [ ] Ícone de usuário aparece no header
- [ ] Ícone redireciona para área do cliente
- [ ] Se não autenticado, redireciona para popup de login
- [ ] Tooltip "Minha Conta" aparece no hover
- [ ] Área do cliente básica está funcional
- [ ] Página de perfil permite editar nome, email e telefone
- [ ] Validações funcionam corretamente

**Estimativa:** 8 pontos (12h)

---

#### US-04: Modal de Contato e Horário
**Como** comprador  
**Quero** ver facilmente o horário de funcionamento e contatos da loja  
**Para** saber quando posso fazer pedidos e como entrar em contato

**Critérios de Aceitação:**
- [ ] Ícone de relógio aparece no header
- [ ] Ao clicar, abre modal com informações
- [ ] Modal exibe status atual (Aberto/Fechado)
- [ ] Modal exibe horários da semana completa
- [ ] Modal exibe telefone, WhatsApp e email
- [ ] Links são clicáveis (tel:, wa.me, mailto:)
- [ ] Modal é responsivo (fullscreen em mobile)
- [ ] Modal fecha ao clicar fora ou no X

**Estimativa:** 5 pontos (9h)

---

#### US-05: Modo de Exibição das Vitrines
**Como** administrador da loja  
**Quero** escolher entre exibição em lista ou carrossel para cada vitrine  
**Para** destacar produtos de forma mais atraente

**Critérios de Aceitação:**
- [ ] Campo "Exibição" aparece no formulário de vitrine
- [ ] Opções: "Por lista" e "Por slides"
- [ ] Valor padrão é "Por lista"
- [ ] Alteração é salva no banco de dados
- [ ] Homepage respeita a configuração escolhida

**Estimativa:** 3 pontos (3h)

---

#### US-06: Carrossel de Produtos
**Como** comprador  
**Quero** navegar por produtos em carrossel  
**Para** ter uma experiência visual mais agradável

**Critérios de Aceitação:**
- [ ] Biblioteca Splide.js integrada
- [ ] Carrossel exibe quando display_mode = 'carousel'
- [ ] Preview do próximo slide é visível (20-30%)
- [ ] Navegação por setas funciona
- [ ] Swipe/touch funciona em mobile
- [ ] Responsivo: 1 item mobile, 2-3 desktop
- [ ] Transições são suaves
- [ ] Performance é adequada (sem lag)

**Estimativa:** 8 pontos (9h)

---

### 2.2 Tarefas Técnicas

#### TECH-01: Migração de Banco de Dados
- [ ] Criar migração para adicionar `session_token` em `customers`
- [ ] Criar migração para adicionar `last_login_at` em `customers`
- [ ] Criar migração para adicionar `display_mode` em `showcases`
- [ ] Executar migrações em ambiente de desenvolvimento
- [ ] Testar rollback das migrações

**Estimativa:** 2 pontos (2h)

---

#### TECH-02: Middleware de Autenticação
- [ ] Criar `TenantCustomerAuth` middleware
- [ ] Implementar verificação de cookie
- [ ] Implementar injeção de customer na request
- [ ] Registrar middleware no Kernel
- [ ] Criar testes unitários do middleware

**Estimativa:** 3 pontos (3h)

---

#### TECH-03: Service de Autenticação
- [ ] Criar `CustomerAuthService`
- [ ] Implementar método `login(tenant, phone)`
- [ ] Implementar método `logout(tenant)`
- [ ] Implementar método `generateSessionToken()`
- [ ] Implementar método `validateSessionToken(token)`
- [ ] Criar testes unitários do service

**Estimativa:** 5 pontos (6h)

---

#### TECH-04: Componente de Header Unificado
- [ ] Criar blade component `tenant-header`
- [ ] Extrair lógica do header atual
- [ ] Implementar contador de carrinho
- [ ] Adicionar ícones com tooltips
- [ ] Tornar responsivo
- [ ] Substituir em todas as views

**Estimativa:** 5 pontos (6h)

---

#### TECH-05: Integração Splide.js
- [ ] Adicionar Splide.js via CDN
- [ ] Criar arquivo `carousel.js` com configurações
- [ ] Implementar inicialização dinâmica
- [ ] Configurar breakpoints responsivos
- [ ] Testar em múltiplos browsers

**Estimativa:** 3 pontos (3h)

---

### 2.3 Bugs e Melhorias

#### BUG-01: Carrinho não persiste entre sessões
**Prioridade:** Média  
**Descrição:** Ao limpar cookies, carrinho é perdido  
**Solução:** Vincular carrinho ao customer autenticado

**Estimativa:** 3 pontos (4h)

---

## 3. Arquitetura e Design

### 3.1 Estrutura de Dados

#### Tabela: customers (modificações)
```sql
ALTER TABLE customers 
ADD COLUMN session_token VARCHAR(255) NULL,
ADD COLUMN last_login_at TIMESTAMP NULL,
ADD INDEX idx_session_token (session_token);
```

#### Tabela: showcases (modificações)
```sql
ALTER TABLE showcases 
ADD COLUMN display_mode VARCHAR(20) DEFAULT 'list' 
AFTER type;
```

### 3.2 Novos Componentes Livewire

```
App\Livewire\Tenant\
├── Auth\
│   └── LoginPopup.php
├── Account\
│   ├── Index.php
│   ├── Profile.php
│   └── Orders.php (básico)
└── ContactModal.php
```

### 3.3 Novos Services

```
App\Services\
└── CustomerAuthService.php
```

### 3.4 Novos Middlewares

```
App\Http\Middleware\
└── TenantCustomerAuth.php
```

### 3.5 Novas Views

```
resources/views/
├── components/
│   └── tenant-header.blade.php
└── livewire/
    └── tenant/
        ├── auth/
        │   └── login-popup.blade.php
        ├── account/
        │   ├── index.blade.php
        │   └── profile.blade.php
        └── contact-modal.blade.php
```

### 3.6 Assets

```
resources/
├── js/
│   └── carousel.js
└── css/
    └── tenant-enhancements.css (opcional)
```

---

## 4. Fluxos de Trabalho

### 4.1 Fluxo de Login

```
1. Usuário acessa homepage
2. Sistema verifica cookie customer_session_{tenant_id}
3. Se não existe:
   a. Aguarda 3 segundos
   b. Verifica localStorage se já recusou (24h)
   c. Se não recusou, exibe popup
4. Usuário informa telefone
5. Sistema valida formato
6. Sistema busca Customer por tenant_id + phone
7. Se não existe, cria novo Customer
8. Sistema gera session_token único
9. Sistema salva token no Customer
10. Sistema cria cookie com token
11. Sistema fecha popup
12. Usuário está autenticado
```

### 4.2 Fluxo de Verificação de Autenticação

```
1. Middleware intercepta request
2. Busca cookie customer_session_{tenant_id}
3. Se não existe, continua como anônimo
4. Se existe, valida token no banco
5. Se válido, injeta Customer na request
6. Se inválido, remove cookie
7. Continua processamento
```

### 4.3 Fluxo de Carrossel

```
1. Admin cria/edita vitrine
2. Admin seleciona display_mode = 'carousel'
3. Sistema salva configuração
4. Homepage carrega vitrines
5. Para cada vitrine:
   a. Se display_mode = 'list', renderiza grid
   b. Se display_mode = 'carousel', renderiza carrossel
6. JavaScript inicializa Splide.js nos carrosséis
7. Usuário navega por setas ou swipe
```

---

## 5. Cronograma Detalhado

### Semana 1 (13-17 Jan)

#### Segunda-feira (13/01)
- [ ] TECH-01: Migrações de banco (2h)
- [ ] TECH-02: Middleware de autenticação (3h)
- [ ] TECH-03: CustomerAuthService (início - 3h)

#### Terça-feira (14/01)
- [ ] TECH-03: CustomerAuthService (conclusão - 3h)
- [ ] US-01: LoginPopup component (4h)

#### Quarta-feira (15/01)
- [ ] US-01: Integração e testes de login (4h)
- [ ] TECH-04: Componente tenant-header (3h)

#### Quinta-feira (16/01)
- [ ] TECH-04: Conclusão do header (3h)
- [ ] US-02: Ícone de carrinho com badge (4h)

#### Sexta-feira (17/01)
- [ ] US-04: Modal de contato (5h)
- [ ] US-03: Botão de conta (início - 3h)

**Checkpoint Semana 1:**
- ✅ Sistema de login funcional
- ✅ Header modernizado
- ✅ Modal de contato implementado

---

### Semana 2 (20-24 Jan)

#### Segunda-feira (20/01)
- [ ] US-03: Área do cliente básica (5h)
- [ ] US-03: Página de perfil (3h)

#### Terça-feira (21/01)
- [ ] TECH-01: Migração display_mode (1h)
- [ ] US-05: Campo no formulário de vitrine (2h)
- [ ] TECH-05: Integração Splide.js (3h)

#### Quarta-feira (22/01)
- [ ] US-06: Template de carrossel (4h)
- [ ] US-06: Renderização condicional (2h)
- [ ] US-06: Testes e ajustes (2h)

#### Quinta-feira (23/01)
- [ ] BUG-01: Vincular carrinho ao customer (4h)
- [ ] Testes de integração (4h)

#### Sexta-feira (24/01)
- [ ] Testes manuais em devices (4h)
- [ ] Correção de bugs encontrados (4h)

**Checkpoint Semana 2:**
- ✅ Vitrines com carrossel
- ✅ Área do cliente funcional
- ✅ Todos os testes passando

---

### Segunda-feira (27/01) - Buffer
- [ ] Ajustes finais
- [ ] Documentação
- [ ] Deploy em staging
- [ ] Review final

---

## 6. Definição de Pronto (DoD)

### 6.1 Código
- [ ] Código segue PSR-12
- [ ] Sem warnings ou erros no IDE
- [ ] Sem código comentado desnecessário
- [ ] Variáveis e métodos com nomes descritivos
- [ ] Sem duplicação de código

### 6.2 Testes
- [ ] Testes unitários criados para services
- [ ] Testes de feature para componentes Livewire
- [ ] Cobertura mínima de 80% em código novo
- [ ] Todos os testes passando
- [ ] Testes manuais documentados

### 6.3 Documentação
- [ ] README atualizado se necessário
- [ ] Comentários em lógica complexa
- [ ] Migrations documentadas
- [ ] Changelog atualizado

### 6.4 UX/UI
- [ ] Responsivo em mobile, tablet e desktop
- [ ] Testado em Chrome, Firefox, Safari
- [ ] Acessibilidade básica (ARIA labels)
- [ ] Loading states implementados
- [ ] Mensagens de erro claras

### 6.5 Performance
- [ ] Lighthouse score > 80
- [ ] Tempo de carregamento < 3s
- [ ] Sem queries N+1
- [ ] Assets minificados

### 6.6 Segurança
- [ ] Validação de inputs
- [ ] Proteção contra CSRF
- [ ] Cookies com httpOnly e secure
- [ ] Sanitização de dados

---

## 7. Riscos e Plano de Contingência

### Risco 1: Conflito de Sessão entre Tenants
**Probabilidade:** Média  
**Impacto:** Alto  
**Mitigação:** Cookie específico por tenant (`customer_session_{tenant_id}`)  
**Contingência:** Adicionar tenant_id ao nome do cookie

### Risco 2: Popup Irritante para Usuários
**Probabilidade:** Alta  
**Impacto:** Médio  
**Mitigação:** Delay de 3s + opção "não mostrar novamente"  
**Contingência:** A/B testing para ajustar timing

### Risco 3: Performance do Carrossel em Mobile
**Probabilidade:** Baixa  
**Impacto:** Médio  
**Mitigação:** Usar biblioteca leve (Splide.js)  
**Contingência:** Lazy load de imagens + reduzir itens por slide

### Risco 4: Atraso no Desenvolvimento
**Probabilidade:** Média  
**Impacto:** Médio  
**Mitigação:** Buffer de 1 dia no final  
**Contingência:** Mover US-03 (área do cliente) para sprint 06

---

## 8. Dependências

### 8.1 Dependências Externas
- [ ] Splide.js (CDN) - disponível
- [ ] Font Awesome 6.x (CDN) - já em uso
- [ ] Alpine.js 3.x (CDN) - já em uso

### 8.2 Dependências Internas
- [ ] TenantHelper (já existe)
- [ ] Modelo Customer (já existe)
- [ ] Modelo Showcase (já existe)
- [ ] Sistema de sessões do Laravel (já existe)

### 8.3 Dependências de Infraestrutura
- [ ] Banco de dados PostgreSQL (já existe)
- [ ] Redis para sessões (opcional)
- [ ] CDN para assets (opcional)

---

## 9. Testes

### 9.1 Testes Unitários

```php
// CustomerAuthServiceTest.php
- test_can_login_with_valid_phone()
- test_creates_customer_if_not_exists()
- test_generates_unique_session_token()
- test_validates_session_token()
- test_logout_removes_token()

// TenantCustomerAuthTest.php
- test_allows_request_without_cookie()
- test_injects_customer_with_valid_cookie()
- test_removes_invalid_cookie()
```

### 9.2 Testes de Feature

```php
// LoginPopupTest.php
- test_popup_appears_on_homepage()
- test_popup_does_not_appear_if_authenticated()
- test_can_login_with_phone()
- test_validates_phone_format()
- test_creates_cookie_after_login()

// AccountTest.php
- test_redirects_to_login_if_not_authenticated()
- test_can_view_account_page()
- test_can_update_profile()
- test_validates_profile_data()

// ContactModalTest.php
- test_modal_opens_on_button_click()
- test_displays_business_hours()
- test_displays_contact_info()
```

### 9.3 Testes Manuais

#### Checklist de Testes Mobile
- [ ] Popup aparece corretamente
- [ ] Popup é fácil de fechar
- [ ] Header é responsivo
- [ ] Ícones são clicáveis
- [ ] Modal ocupa tela toda
- [ ] Carrossel funciona com swipe
- [ ] Área do cliente é navegável

#### Checklist de Testes Desktop
- [ ] Popup está centralizado
- [ ] Tooltips aparecem no hover
- [ ] Modal está centralizado
- [ ] Carrossel funciona com setas
- [ ] Layout não quebra em telas grandes

#### Checklist de Browsers
- [ ] Chrome (última versão)
- [ ] Firefox (última versão)
- [ ] Safari (última versão)
- [ ] Edge (última versão)
- [ ] Chrome Mobile
- [ ] Safari Mobile

---

## 10. Métricas e Monitoramento

### 10.1 Eventos para Tracking

```javascript
// Google Analytics Events
gtag('event', 'login_popup_shown');
gtag('event', 'login_popup_dismissed');
gtag('event', 'login_success', { method: 'phone' });
gtag('event', 'login_failed', { reason: 'invalid_phone' });
gtag('event', 'cart_icon_clicked');
gtag('event', 'account_icon_clicked');
gtag('event', 'contact_modal_opened');
gtag('event', 'whatsapp_clicked');
gtag('event', 'carousel_slide_changed', { showcase_id: id });
gtag('event', 'carousel_arrow_clicked');
```

### 10.2 Métricas a Monitorar

**Semana 1 após deploy:**
- Taxa de exibição do popup
- Taxa de conversão do popup
- Taxa de rejeição do popup
- Tempo médio até login
- Cliques no ícone de carrinho
- Cliques no ícone de conta
- Aberturas do modal de contato

**Semana 2-4 após deploy:**
- Taxa de login geral
- Taxa de conversão (pedidos/visitantes)
- Abandono de carrinho
- Pedidos recorrentes
- Tempo médio no site
- Páginas por sessão
- Taxa de rejeição

---

## 11. Comunicação

### 11.1 Daily Standups
- **Quando:** Todo dia às 9h
- **Duração:** 15 minutos
- **Formato:** O que fiz ontem / O que farei hoje / Impedimentos

### 11.2 Sprint Review
- **Quando:** 27/01 às 15h
- **Duração:** 1 hora
- **Participantes:** Time + Stakeholders
- **Objetivo:** Demonstrar funcionalidades implementadas

### 11.3 Sprint Retrospective
- **Quando:** 27/01 às 16h
- **Duração:** 1 hora
- **Participantes:** Time
- **Objetivo:** O que funcionou / O que melhorar / Ações

---

## 12. Critérios de Aceitação da Sprint

### 12.1 Funcionalidades Obrigatórias
- [x] Sistema de login por telefone funcionando
- [x] Header com ícones e badges
- [x] Modal de contato implementado
- [x] Carrossel de produtos funcionando
- [x] Área do cliente básica acessível

### 12.2 Qualidade
- [x] Todos os testes passando
- [x] Cobertura de testes > 80%
- [x] Sem bugs críticos
- [x] Performance adequada

### 12.3 Documentação
- [x] Código documentado
- [x] README atualizado
- [x] Migrations documentadas
- [x] Testes documentados

---

## 13. Próximos Passos (Sprint 06)

### Funcionalidades Planejadas
1. Área do cliente completa
   - Histórico detalhado de pedidos
   - Gerenciamento de endereços
   - Dados de entrega

2. Busca de produtos
   - Input de busca no header
   - Resultados em tempo real
   - Filtros por categoria

3. Rastreamento de pedidos
   - Página pública de rastreamento
   - Timeline visual
   - Notificações de status

4. Melhorias no checkout
   - Pré-preenchimento de dados
   - Salvar endereços
   - Histórico de pagamentos

---

## 14. Notas Importantes

### 14.1 Decisões Técnicas
- **Cookie vs JWT:** Optamos por cookie simples para MVP, JWT pode ser adicionado depois
- **Biblioteca de Carrossel:** Splide.js escolhido por ser leve e acessível
- **Popup vs Banner:** Popup escolhido por maior visibilidade, mas com delay para não irritar

### 14.2 Débito Técnico Conhecido
- Login sem verificação SMS (adicionar em sprint futura)
- Área do cliente incompleta (expandir em sprint 06)
- Sem sistema de notificações (sprint 07)
- Carrinho ainda em sessão (migrar para banco em sprint 06)

### 14.3 Melhorias Futuras
- Verificação por SMS/OTP
- Login social (Google, Facebook)
- PWA com push notifications
- Chat de suporte integrado
- Sistema de cupons
- Programa de fidelidade

---

## 15. Aprovações

| Papel | Nome | Assinatura | Data |
|-------|------|------------|------|
| Product Owner | - | - | - |
| Tech Lead | - | - | - |
| UX Designer | - | - | - |

---

**Documento preparado em:** 2026-01-12  
**Última atualização:** 2026-01-12  
**Próxima revisão:** 2026-01-20 (meio da sprint)
