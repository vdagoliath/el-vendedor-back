<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Catálogo online por negocio
    |--------------------------------------------------------------------------
    |
    | Dominio bajo el que se publica cada catálogo: {subdomain}.{base_domain}.
    | El subdominio se guarda en `catalog_storefronts.subdomain`.
    |
    */

    'base_domain' => env('CATALOG_BASE_DOMAIN', 'miraloaki.com'),

    'scheme' => env('CATALOG_SCHEME', 'https'),

];
