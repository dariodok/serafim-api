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
        'base_url' => env('MERCADO_PAGO_BASE_URL', 'https://api.mercadopago.com'),
        'access_token' => env('MERCADO_PAGO_ACCESS_TOKEN'),
        'public_key' => env('MERCADO_PAGO_PUBLIC_KEY'),
        'webhook_secret' => env('MERCADO_PAGO_WEBHOOK_SECRET'),
        'back_urls' => [
            'success' => env('MERCADO_PAGO_BACK_URL_SUCCESS', env('APP_URL')),
            'failure' => env('MERCADO_PAGO_BACK_URL_FAILURE', env('APP_URL')),
            'pending' => env('MERCADO_PAGO_BACK_URL_PENDING', env('APP_URL')),
        ],
    ],

    'payway' => [
        'api_base_url' => env('PAYWAY_API_BASE_URL', 'https://ventasonline.payway.com.ar/api/v2'),
        'checkout_base_url' => env('PAYWAY_CHECKOUT_BASE_URL', 'https://ventasonline.payway.com.ar/api/v1/checkout-payment-button'),
        'public_api_key' => env('PAYWAY_PUBLIC_API_KEY'),
        'private_api_key' => env('PAYWAY_PRIVATE_API_KEY'),
        'site_id' => env('PAYWAY_SITE_ID'),
        'template_id' => env('PAYWAY_TEMPLATE_ID'),
        'payment_method_id' => env('PAYWAY_PAYMENT_METHOD_ID'),
        'installments' => env('PAYWAY_INSTALLMENTS', '1'),
        'plan_gobierno' => env('PAYWAY_PLAN_GOBIERNO', false),
        'auth_3ds' => env('PAYWAY_AUTH_3DS'),
        'success_url' => env('PAYWAY_SUCCESS_URL', env('APP_URL')),
        'cancel_url' => env('PAYWAY_CANCEL_URL', env('APP_URL')),
        'redirect_url' => env('PAYWAY_REDIRECT_URL'),
        'notifications_url' => env('PAYWAY_NOTIFICATIONS_URL'),
    ],

    'correo_argentino' => [
        'base_url' => env('CORREO_ARGENTINO_BASE_URL', 'https://apitest.correoargentino.com.ar/micorreo/v1'),
        'username' => env('CORREO_ARGENTINO_USERNAME'),
        'password' => env('CORREO_ARGENTINO_PASSWORD'),
        'customer_id' => env('CORREO_ARGENTINO_CUSTOMER_ID'),
        'origin_postal_code' => env('CORREO_ARGENTINO_ORIGIN_POSTAL_CODE'),
        'default_product_type' => env('CORREO_ARGENTINO_DEFAULT_PRODUCT_TYPE', 'CP'),
        'sender' => [
            'name' => env('CORREO_ARGENTINO_SENDER_NAME', env('APP_NAME')),
            'email' => env('CORREO_ARGENTINO_SENDER_EMAIL', env('MAIL_FROM_ADDRESS')),
            'phone' => env('CORREO_ARGENTINO_SENDER_PHONE'),
            'cell_phone' => env('CORREO_ARGENTINO_SENDER_CELL_PHONE'),
            'origin' => [
                'street_name' => env('CORREO_ARGENTINO_SENDER_STREET_NAME'),
                'street_number' => env('CORREO_ARGENTINO_SENDER_STREET_NUMBER'),
                'floor' => env('CORREO_ARGENTINO_SENDER_FLOOR'),
                'apartment' => env('CORREO_ARGENTINO_SENDER_APARTMENT'),
                'city' => env('CORREO_ARGENTINO_SENDER_CITY'),
                'province' => env('CORREO_ARGENTINO_SENDER_PROVINCE'),
                'postal_code' => env('CORREO_ARGENTINO_SENDER_POSTAL_CODE', env('CORREO_ARGENTINO_ORIGIN_POSTAL_CODE')),
            ],
        ],
        'timeout' => env('CORREO_ARGENTINO_TIMEOUT', 20),
    ],

    'andreani' => [
        'base_url' => env('ANDREANI_BASE_URL'),
        'client_id' => env('ANDREANI_CLIENT_ID'),
        'client_secret' => env('ANDREANI_CLIENT_SECRET'),
        'contract' => env('ANDREANI_CONTRACT'),
        'origin_postal_code' => env('ANDREANI_ORIGIN_POSTAL_CODE'),
        'default_service_type' => env('ANDREANI_DEFAULT_SERVICE_TYPE', 'standard'),
        'timeout' => env('ANDREANI_TIMEOUT', 20),
    ],

];
