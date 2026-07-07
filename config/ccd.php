<?php

return [

    /*
    |--------------------------------------------------------------------------
    | tenant_id => country_id
    |--------------------------------------------------------------------------
    | Manual map used by every ccd:* migration command to pick which country's
    | dealer_* data belongs to a tenant. Add a row per tenant.
    */

    'tenant_country' => [
        2 => 1,  // singapore
        3 => 2,  // indonesia
        4 => 3,  // thailand
        5 => 32, // hong_kong
        6 => 35, // japan
        1 => 49, // malaysia
        7 => 84, // taiwan
    ],
    /*
    |--------------------------------------------------------------------------
    | Polymorphic party types
    |--------------------------------------------------------------------------
    | dealer_transaction_parties.type / bank_accounts.account_holder_type morph
    | class => how to resolve it:
    |   source = dealer_* table, ref = ccd_parties.reference_name, kind = party type
    */

    'party_types' => [
        'App\\Modules\\Account\\User\\Models\\User'     => ['source' => 'dealer_users', 'ref' => 'user', 'kind' => 'person'],
        'App\\Modules\\Contact\\Models\\Contact'        => ['source' => 'dealer_contacts', 'ref' => 'contact', 'kind' => 'person'],
        'App\\Modules\\Contact\\Models\\CompanyContact' => ['source' => 'dealer_company_contacts', 'ref' => 'company_contact', 'kind' => 'company'],
        'App\\Modules\\Account\\Group\\Models\\Group'   => ['source' => 'dealer_groups', 'ref' => 'group', 'kind' => 'company'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ignored contact names
    |--------------------------------------------------------------------------
    | Company/dealer names (and other known junk) that show up as Contact-type
    | dealer_contacts.name / display_name values instead of a real person.
    | ccd:stage-parties matches these case-insensitively (trimmed) and stages
    | them as status 'unidentified' / reason 'ignored_name' instead of
    | resolving an identification_key for them.
    */

    'ignore_names' => [
        'WING HIN MOTOR SDN BHD',
        'NGAN KOK SENG',
        'FOREVERISE MOTOR',
        'WING HIN AUTOMOBILE SDN BHD',
        'KOH BROTHER AUTOMOBILE',
        'SOLECAR SDN BHD',
        'COSMOPOLITAN AUTOMOTIVE ENTERPRISE',
        'WING HIN AUTOMOBILE SDN BHD. ( 576238-X )',
        'COSMO AUTO SDN BHD',
        'MOTORS CONFIDENCE (M) SDN BHD',
        'PUNCAK MERAK SDN BHD',
        'LASER MOTOR SDN BHD',
        'ZEN AUTO ENTERPRISE',
        'CARWIFE AUTOMOTIVE SDN BHD',
        'CKY AUTO CAR',
        'LETGO MOTORSPORT SDN BHD',
        'INVICTUS MOTOR',
        'ISMAIL BIN AHMAD',
        'JAX AUTO',
        'TAN CHEE KEONG',
        'CKE AUTO SDN BHD',
        'SAHABAT MOTOR',
        'FIRDAUS',
        'KIEW CHANG KEONG',
        'LEE CHEE KEONG',
        'AK AUTO SPEEDWAY ENTERPRISE',
        'CCBC AUTO VENTURE',
        'CHEY KOON MING',
        'CK AUTOMOBILE (M) SDN BHD',
        'D 4 W ENTERPRISE',
        'QC HOLIDAY PREMIUM RENTAL SDN BHD',
        'RIZQ HAYAT RESOURCES',
        'WAN',
        'WING HIN GROUP SDN BHD',
        'AMIRUL',
        'BERMAZ MOTOR TRADING SDN BHD',
        'CASSA AUTO CITY SDN BHD',
        'EASY EXPRESS AUTO',
        'ERA AUTO SDN BHD',
        'EZZE MOTOR ENTERPRISE',
    ],

];
