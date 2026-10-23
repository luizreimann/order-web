<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'mercadopago' => [
        // Identificador de integrador (programa de parceiros do MP). VAZIO por padrão: o
        // cabeçalho `X-Integrator-Id` só é enviado quando configurado, e o valor tem de ser o
        // do SEU cadastro (um id de terceiros atribuiria as vendas a ele).
        'integrator_id' => env('MERCADOPAGO_INTEGRATOR_ID', ''),

        // Validade do Pix (duração ISO 8601). Documentação do MP (Orders): entre 30 minutos e
        // 30 dias; a sandbox aceita menos, mas a produção segue a documentação. Valores
        // inválidos ou fora da faixa caem no padrão (PT30M); ver `MercadoPagoGateway::pixExpiration()`.
        'pix_expiration' => env('MERCADOPAGO_PIX_EXPIRATION', 'PT30M'),

        // Janela (segundos) em que o cliente pode pagar o Pix: regra NOSSA, imposta pelo servidor
        // (o MP não aceita validade < 30 min, então `pix_expiration` segue como teto do lado dele).
        // Padrão e TETO 300 (5 min); piso 60. Ver `App\Support\PixWindow`.
        'pix_window' => env('MERCADOPAGO_PIX_WINDOW', 300),

        // Pix assíncrono: depois de criar a order, quanto o servidor espera (no máximo, em
        // segundos) pelo QR antes de responder. 0 desliga a espera (o checkout mostra
        // "Gerando o seu Pix…" e obtém o QR pelo polling).
        'pix_qr_wait' => (float) env('MERCADOPAGO_PIX_QR_WAIT', 8),
    ],

];
