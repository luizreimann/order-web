# QA — WhatsApp da loja

## Rodada 1
- Veredito: aberta
- Provas reexecutadas: `PhoneTest` 43 passed (311 assertions); `ZaptriaClientTest` 11 passed (53 assertions); `CustomerLoginCodeTest|TenantIsolationAuditTest` 18 passed (201 assertions); `CustomerLoginCodeFallbackTest` 18 passed (213 assertions); `CustomerLoginPopupTest|TenantAdminGuardTest|CustomerGuardTest` 27 passed (135 assertions); `ZaptriaWebhookTest|RouteMiddlewareAuditTest` 20 passed (105 assertions); `WhatsAppSettingsTest|AdminNavigationTest|LivewireAdminAuthorizationAuditTest|RouteMiddlewareAuditTest` 36 passed (425 assertions); build e `WhatsAppConnectTest` 28 passed (182 assertions); `OrderWhatsAppStatusTest` 18 passed (106 assertions); `OrderWhatsAppPaymentTest` 6 passed (46 assertions); `OrderWhatsAppMessageTest` 18 passed (188 assertions); `StorePhoneTest` 27 passed (241 assertions); build e `PhoneMaskTest` 7 passed (62 assertions); `ZaptriaCreditTest` 2 passed (29 assertions); `E2E_SPRINT=.sprint/08-whatsapp npm --prefix src run e2e -- sprint/08-whatsapp.spec.ts` 3 passed. Capturas: `whatsapp-config.png`, `pedido-mensagem.png`, `telefone-mascara.png`.

Os vinte critérios de aceite da spec estão cumpridos por essas provas. O filtro `PhoneTest` também casa `StorePhoneTest`.

### Achados
- Código: `src/app/Rules/CLAUDE.md` ainda diz que `PhoneNumber` exige 10–15 dígitos depois de normalizar. O código exige telefone brasileiro de 10 ou 11, DDD sem zero, e `+` só se for `+55`. Vira a T16.
- Código: `src/app/Models/CLAUDE.md`, na seção `Tenant.settings`, lista os blocos gravados e não cita `whatsapp`. O bloco existe e a tela grava só ele. Vira a T17.
- Spec: nenhum.
- Dispensa: nenhuma.

## Rodada 2
- Veredito: fechada
- Provas reexecutadas: `PhoneTest` 43 passed (311 assertions); `ZaptriaClientTest` 11 passed (53 assertions); `CustomerLoginCodeTest|TenantIsolationAuditTest` 18 passed (201 assertions); `CustomerLoginCodeFallbackTest` 18 passed (213 assertions); `CustomerLoginPopupTest|TenantAdminGuardTest|CustomerGuardTest` 27 passed (135 assertions); `ZaptriaWebhookTest|RouteMiddlewareAuditTest` 20 passed (105 assertions); `WhatsAppSettingsTest|AdminNavigationTest|LivewireAdminAuthorizationAuditTest|RouteMiddlewareAuditTest` 36 passed (425 assertions); build e `WhatsAppConnectTest` 28 passed (182 assertions); `OrderWhatsAppStatusTest` 18 passed (106 assertions); `OrderWhatsAppPaymentTest` 6 passed (46 assertions); `OrderWhatsAppMessageTest` 18 passed (188 assertions); `StorePhoneTest` 27 passed (241 assertions); build e `PhoneMaskTest` 7 passed (62 assertions); `ZaptriaCreditTest` 2 passed (29 assertions); `E2E_SPRINT=.sprint/08-whatsapp npm --prefix src run e2e -- sprint/08-whatsapp.spec.ts` 3 passed (4,4 s). Capturas: `whatsapp-config.png`, `pedido-mensagem.png`, `telefone-mascara.png`. A frase `10 ou 11, DDD sem zero` está em `src/app/Rules/CLAUDE.md` e `10–15` saiu. A frase `bloco \`whatsapp\`` está na seção `Tenant.settings` de `src/app/Models/CLAUDE.md`. O shell não tem o binário `rg`; a busca confirmou as duas frases.

Os vinte critérios de aceite da spec estão cumpridos por essas provas. Os mapas da T16 e da T17 dizem o contrato atual. O filtro `PhoneTest` também casa `StorePhoneTest`.

### Achados
- Código: nenhum.
- Spec: nenhum.
- Dispensa: nenhuma.
