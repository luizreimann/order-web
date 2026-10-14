# order-web (aplicação Laravel)

Este diretório contém a aplicação Laravel 12 + Livewire 3. A documentação do projeto (como rodar, testar, arquitetura multi-tenant, autenticação, pagamentos e riscos aceitos) está no [README da raiz](../README.md).

Atalhos, sempre a partir da raiz do repositório:

```bash
docker compose up -d
docker compose exec app php artisan migrate:fresh --seed
docker compose exec app php artisan test
```
