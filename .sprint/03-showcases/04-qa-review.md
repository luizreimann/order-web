# QA Review: Funcionalidade de Vitrines (Showcases)

**Data:** 12/01/2026  
**Revisor:** QA Automatizado  
**Status:** 🔍 Revisão Completa

---

## Resumo Executivo

A implementação da funcionalidade de Vitrines foi analisada em profundidade. Foram identificados **3 bugs**, **5 problemas de validação/segurança** e **8 melhorias recomendadas**.

---

## 🐛 Bugs Identificados

### BUG-001: Teste unitário com asserção incorreta (CRÍTICO)

**Arquivo:** `tests/Unit/Models/ShowcaseTest.php:162-175`

**Problema:** O teste `test_get_products_respects_max_items` está incorreto. Ele cria 10 produtos, define `max_items = 5`, mas espera que retorne 10 produtos.

```php
public function test_get_products_respects_max_items(): void
{
    Product::factory()->count(10)->create(['tenant_id' => $this->tenant->id]);

    $showcase = Showcase::factory()->create([
        'tenant_id' => $this->tenant->id,
        'type' => Showcase::TYPE_ALL,
        'max_items' => 5,
    ]);

    $products = $showcase->getProducts();

    $this->assertCount(10, $products); // ❌ Deveria ser 5 ou o teste está errado
}
```

**Causa raiz:** No model `Showcase::getProducts()`, o tipo `TYPE_ALL` ignora `max_items` e usa limite fixo de 100:
```php
$limit = $this->type === self::TYPE_ALL ? 100 : $this->max_items;
```

**Impacto:** O teste passa mas não valida o comportamento correto. Se a intenção é que `TYPE_ALL` ignore `max_items`, o teste deveria refletir isso claramente.

**Correção sugerida:** Renomear o teste para `test_type_all_ignores_max_items` ou corrigir a lógica.

---

### BUG-002: Drag-and-drop não persiste após navegação Livewire

**Arquivo:** `resources/views/livewire/admin/showcases/index.blade.php:101-113`

**Problema:** O script do SortableJS é executado apenas no `DOMContentLoaded`. Quando o Livewire re-renderiza o componente (ex: após toggle de status), o drag-and-drop pode parar de funcionar porque o elemento `#showcaseList` é recriado.

**Causa raiz:** O `wire:ignore` está no container, mas o script não é re-inicializado após updates do Livewire.

**Impacto:** Médio - UX degradada após interações.

**Correção sugerida:** Usar `Livewire.hook` para re-inicializar o Sortable:
```javascript
document.addEventListener('livewire:navigated', function() {
    initSortable();
});
```

---

### BUG-003: Validação de categoria obrigatória ausente

**Arquivo:** `app/Livewire/Admin/Showcases/Form.php:66`

**Problema:** Quando o tipo é `category`, o campo `category_id` deveria ser obrigatório, mas a validação permite `nullable`.

```php
'category_id' => ['nullable', 'uuid', 'exists:categories,id'],
```

**Impacto:** Usuário pode criar vitrine de categoria sem selecionar categoria, resultando em vitrine vazia.

**Correção sugerida:** Usar validação condicional:
```php
'category_id' => [
    Rule::requiredIf($this->type === Showcase::TYPE_CATEGORY),
    'nullable',
    'uuid',
    'exists:categories,id'
],
```

---

## ⚠️ Problemas de Validação/Segurança

### VAL-001: Falta validação de tenant na categoria selecionada

**Arquivo:** `app/Livewire/Admin/Showcases/Form.php:66`

**Problema:** A validação `exists:categories,id` não verifica se a categoria pertence ao tenant atual.

**Risco:** Um admin poderia teoricamente enviar um `category_id` de outro tenant via manipulação de request.

**Correção sugerida:**
```php
'category_id' => [
    'nullable',
    'uuid',
    Rule::exists('categories', 'id')->where('tenant_id', $this->tenant->id),
],
```

---

### VAL-002: Falta validação de tenant nos produtos selecionados

**Arquivo:** `app/Livewire/Admin/Showcases/Form.php:67-68`

**Problema:** Similar ao VAL-001, os produtos selecionados não são validados contra o tenant.

```php
'selectedProducts.*' => ['uuid', 'exists:products,id'],
```

**Correção sugerida:**
```php
'selectedProducts.*' => [
    'uuid',
    Rule::exists('products', 'id')->where('tenant_id', $this->tenant->id),
],
```

---

### VAL-003: Slug duplicado pode causar erro não tratado

**Arquivo:** `app/Models/Showcase.php:47-51`

**Problema:** Se o slug gerado automaticamente já existir para o tenant, a constraint unique falhará com erro de banco.

**Correção sugerida:** Adicionar sufixo numérico se slug já existir, similar ao que outros sistemas fazem.

---

### VAL-004: Falta proteção contra race condition no limite de vitrines

**Arquivo:** `app/Livewire/Admin/Showcases/Form.php:78-84`

**Problema:** A verificação de limite é feita antes do insert, mas sem lock. Dois requests simultâneos podem ultrapassar o limite.

**Impacto:** Baixo em uso normal, mas possível em cenários de teste automatizado ou uso intenso.

**Correção sugerida:** Usar transação com lock ou constraint no banco.

---

### VAL-005: Categoria inativa pode ser selecionada

**Arquivo:** `app/Livewire/Admin/Showcases/Form.php:132-136`

**Problema:** O formulário só mostra categorias ativas, mas a validação aceita qualquer categoria existente. Se uma categoria for desativada após ser selecionada, a vitrine continuará funcionando.

**Impacto:** Baixo - comportamento pode ser intencional.

---

## 📋 Melhorias Recomendadas

### MEL-001: Adicionar índice na coluna `type`

**Arquivo:** Migration `create_showcases_table.php`

**Justificativa:** Queries podem filtrar por tipo no futuro.

```php
$table->index('type');
```

---

### MEL-002: Adicionar feedback visual de loading no drag-and-drop

**Arquivo:** `resources/views/livewire/admin/showcases/index.blade.php`

**Justificativa:** Usuário não sabe se a ordem foi salva.

**Sugestão:** Adicionar `wire:loading` ou toast de confirmação.

---

### MEL-003: Exibir preview de produtos na listagem de vitrines

**Arquivo:** `resources/views/livewire/admin/showcases/index.blade.php`

**Justificativa:** Admin não consegue ver quantos produtos cada vitrine tem sem editar.

**Sugestão:** Adicionar contador `(X produtos)` na listagem.

---

### MEL-004: Adicionar confirmação antes de excluir vitrine padrão

**Arquivo:** `app/Livewire/Admin/Showcases/Index.php:29-37`

**Justificativa:** Se o admin excluir a única vitrine, a home ficará vazia.

**Sugestão:** Alertar se for a última vitrine ativa.

---

### MEL-005: Melhorar UX do seletor de produtos (tipo custom)

**Arquivo:** `resources/views/livewire/admin/showcases/form.blade.php:78-102`

**Justificativa:** Lista de checkboxes não escala bem para muitos produtos.

**Sugestão:** Usar componente de busca/filtro ou select múltiplo com search.

---

### MEL-006: Adicionar testes de integração/feature

**Justificativa:** Apenas testes unitários do model foram criados. Faltam testes para:
- Componentes Livewire (Index, Form)
- Rotas e autenticação
- Página de categoria no storefront

---

### MEL-007: Considerar lazy loading para vitrines na home

**Arquivo:** `app/Livewire/Tenant/Home.php`

**Justificativa:** Se houver muitas vitrines, todas são carregadas de uma vez.

**Sugestão:** Carregar produtos de vitrines sob demanda ou usar cache.

---

### MEL-008: Documentar comportamento de vitrines vazias

**Justificativa:** Vitrines sem produtos são filtradas na home (`filter(fn($s) => count($s['products']) > 0)`), mas isso não é documentado para o admin.

**Sugestão:** Adicionar nota no formulário ou indicador na listagem.

---

## 🧪 Cobertura de Testes

### Testes Existentes (14)
| Teste | Status |
|-------|--------|
| `test_showcase_has_correct_types` | ✅ Pass |
| `test_showcase_generates_slug_automatically` | ✅ Pass |
| `test_scope_for_tenant_filters_correctly` | ✅ Pass |
| `test_scope_active_filters_correctly` | ✅ Pass |
| `test_get_products_type_all_returns_all_active_products` | ✅ Pass |
| `test_get_products_type_featured_returns_only_featured` | ✅ Pass |
| `test_get_products_type_recent_returns_newest_first` | ✅ Pass |
| `test_get_products_type_category_returns_products_from_category` | ✅ Pass |
| `test_get_products_type_custom_returns_selected_products` | ✅ Pass |
| `test_get_products_respects_max_items` | ⚠️ Pass (asserção incorreta) |
| `test_sync_products_maintains_order` | ✅ Pass |
| `test_showcase_belongs_to_tenant` | ✅ Pass |
| `test_showcase_belongs_to_category` | ✅ Pass |
| `test_max_showcases_per_tenant_constant` | ✅ Pass |

### Testes Faltando
- [ ] Teste para `TYPE_BEST_SELLERS` (não testado)
- [ ] Teste para vitrine de categoria sem `category_id`
- [ ] Testes de componentes Livewire
- [ ] Testes de rotas/HTTP
- [ ] Teste de limite de vitrines por tenant

---

## 📊 Matriz de Severidade

| ID | Tipo | Severidade | Esforço | Prioridade |
|----|------|------------|---------|------------|
| BUG-001 | Bug | Alta | Baixo | P1 |
| BUG-002 | Bug | Média | Médio | P2 |
| BUG-003 | Bug | Média | Baixo | P1 |
| VAL-001 | Segurança | Alta | Baixo | P1 |
| VAL-002 | Segurança | Alta | Baixo | P1 |
| VAL-003 | Validação | Baixa | Médio | P3 |
| VAL-004 | Validação | Baixa | Alto | P4 |
| VAL-005 | Validação | Baixa | Baixo | P4 |
| MEL-001 | Melhoria | Baixa | Baixo | P3 |
| MEL-002 | UX | Média | Baixo | P2 |
| MEL-003 | UX | Média | Médio | P2 |
| MEL-004 | UX | Baixa | Baixo | P3 |
| MEL-005 | UX | Média | Alto | P3 |
| MEL-006 | Qualidade | Alta | Alto | P2 |
| MEL-007 | Performance | Média | Médio | P3 |
| MEL-008 | Documentação | Baixa | Baixo | P4 |

---

## ✅ Pontos Positivos

1. **Arquitetura consistente** - Segue padrões do projeto (scopes, UUIDs, multi-tenant)
2. **Separação de responsabilidades** - Model com lógica, Livewire com apresentação
3. **Tipos bem definidos** - Constantes e método `getTypes()` facilitam manutenção
4. **Testes unitários** - Boa cobertura do model principal
5. **UX do admin** - Drag-and-drop e formulário condicional são bons
6. **Seed automático** - Vitrine padrão criada para novos tenants

---

## 🎯 Recomendações Prioritárias

### Imediato (antes de deploy)
1. Corrigir VAL-001 e VAL-002 (validação de tenant)
2. Corrigir BUG-003 (categoria obrigatória)
3. Corrigir BUG-001 (teste incorreto)

### Curto prazo
1. Adicionar teste para `TYPE_BEST_SELLERS`
2. Implementar MEL-002 (feedback de loading)
3. Corrigir BUG-002 (re-init do Sortable)

### Médio prazo
1. Adicionar testes de integração (MEL-006)
2. Melhorar seletor de produtos (MEL-005)
3. Implementar cache para vitrines (MEL-007)

---

## Conclusão

A implementação está **funcional** e segue os padrões do projeto. Os bugs identificados são de severidade média e as falhas de validação de tenant devem ser corrigidas antes do deploy em produção por questões de segurança multi-tenant.

**Aprovação:** ⚠️ **Condicional** - Corrigir itens P1 antes de merge.
