<?php

return [
    'enabled' => (bool) env('AFIP_ENABLED', false),
    'auto_emit_on_paid' => (bool) env('AFIP_AUTO_EMIT_ON_PAID', true),
    'auto_refresh_fiscal_data' => (bool) env('AFIP_AUTO_REFRESH_FISCAL_DATA', true),
    'refresh_fiscal_on_invoice' => (bool) env('AFIP_REFRESH_FISCAL_ON_INVOICE', true),
    'fiscal_cache_days' => (int) env('AFIP_FISCAL_CACHE_DAYS', 30),
    'environment' => env('AFIP_ENVIRONMENT', env('AFIP_ENV', 'homologacion')),
    'timezone' => env('AFIP_TIMEZONE', 'America/Argentina/Buenos_Aires'),

    'cuit' => env('AFIP_CUIT'),
    'certificate_path' => env('AFIP_CERT_PATH', storage_path('app/afip/keys/certificate.pem')),
    'private_key_path' => env('AFIP_KEY_PATH', storage_path('app/afip/keys/private.key')),
    'private_key_passphrase' => env('AFIP_KEY_PASS', ''),
    'ticket_path' => env('AFIP_TA_PATH', storage_path('app/afip/TA_%service%_%environment%.xml')),
    'tra_path' => env('AFIP_TRA_PATH', storage_path('app/afip/TRA_%service%_%environment%.xml')),
    'tra_tmp_path' => env('AFIP_TRA_TMP_PATH', storage_path('app/afip/TRA_%service%_%environment%.tmp')),

    'point_of_sale' => (int) env('AFIP_PTO_VTA', 1),
    'concept' => 1,
    'currency_id' => env('AFIP_MONEDA_ID', 'PES'),
    'currency_rate' => (float) env('AFIP_MONEDA_COTIZACION', 1),
    'vat_rate' => (float) env('AFIP_IVA_RATE', 0.21),
    'vat_afip_id' => (int) env('AFIP_IVA_AFIP_ID', 5),

    'qr_url' => env('AFIP_QR_URL', 'https://www.arca.gob.ar/fe/qr/'),

    'soap' => [
        'trace' => (bool) env('AFIP_SOAP_TRACE', true),
        'exceptions' => true,
        'verify_peer' => (bool) env('AFIP_SSL_VERIFY_PEER', true),
        'verify_peer_name' => (bool) env('AFIP_SSL_VERIFY_PEER_NAME', true),
        'cache_wsdl' => env('AFIP_WSDL_CACHE', WSDL_CACHE_NONE),
        'connection_timeout' => (int) env('AFIP_CONNECTION_TIMEOUT', 20),
    ],

    'environments' => [
        'homologacion' => [
            'wsaa_wsdl' => env('AFIP_WSAA_WSDL', 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms?wsdl'),
            'wsaa_url' => env('AFIP_URL_LOGIN', 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms'),
            'wsfe_wsdl' => env('AFIP_WSFE_WSDL', 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx?WSDL'),
            'wsfe_url' => env('AFIP_URL_WSFEV1', 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx'),
            'a13_wsdl' => env('AFIP_A13_WSDL') ?: 'https://awshomo.afip.gov.ar/sr-padron/webservices/personaServiceA13?WSDL',
            'a13_url' => env('AFIP_A13_URL') ?: 'https://awshomo.afip.gov.ar/sr-padron/webservices/personaServiceA13',
            'constancia_wsdl' => env('AFIP_CONSTANCIA_WSDL') ?: 'https://awshomo.arca.gob.ar/sr-padron/webservices/personaServiceA5?WSDL',
            'constancia_url' => env('AFIP_CONSTANCIA_URL') ?: 'https://awshomo.arca.gob.ar/sr-padron/webservices/personaServiceA5',
        ],
        'produccion' => [
            'wsaa_wsdl' => env('AFIP_WSAA_WSDL', 'https://wsaa.afip.gov.ar/ws/services/LoginCms?wsdl'),
            'wsaa_url' => env('AFIP_URL_LOGIN', 'https://wsaa.afip.gov.ar/ws/services/LoginCms'),
            'wsfe_wsdl' => env('AFIP_WSFE_WSDL', 'https://servicios1.afip.gov.ar/wsfev1/service.asmx?WSDL'),
            'wsfe_url' => env('AFIP_URL_WSFEV1', 'https://servicios1.afip.gov.ar/wsfev1/service.asmx'),
            'a13_wsdl' => env('AFIP_A13_WSDL') ?: 'https://aws.afip.gov.ar/sr-padron/webservices/personaServiceA13?WSDL',
            'a13_url' => env('AFIP_A13_URL') ?: 'https://aws.afip.gov.ar/sr-padron/webservices/personaServiceA13',
            'constancia_wsdl' => env('AFIP_CONSTANCIA_WSDL') ?: 'https://aws.arca.gob.ar/sr-padron/webservices/personaServiceA5?WSDL',
            'constancia_url' => env('AFIP_CONSTANCIA_URL') ?: 'https://aws.arca.gob.ar/sr-padron/webservices/personaServiceA5',
        ],
    ],
];
