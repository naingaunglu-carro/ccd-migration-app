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
        'Wing hin motor sdn bhd',
        'foreverise motor',
        'WING HIN AUTOMOBILE SDN BHD',
        'COSMOPOLITAN AUTOMOTIVE ENTERPRISE',
        'Foreverise motor',
        'KOH BROTHER AUTOMOBILE',
        'SOLECAR SDN BHD',
        'Wing Hin Automobile Sdn Bhd. ( 576238-X )',
        'COSMO AUTO SDN BHD',
        'MOTORS CONFIDENCE (M) SDN BHD',
        'PUNCAK MERAK SDN BHD',
        'ZEN AUTO ENTERPRISE',
        'LASER MOTOR SDN BHD',
        'Tan',
        'CARWIFE AUTOMOTIVE SDN BHD',
        'Hafiz',
        'Lim',
        'CKY AUTO CAR',
        'Firdaus',
        'LETGO MOTORSPORT SDN BHD',
        'Wing Hin Motor Sdn Bhd',
        'Lee',
        'Ahmad',
        'INVICTUS MOTOR',
        'Wing Hin Automobile Sdn Bhd',
        'CUSTOMER',
        'Wan',
        'Goh',
        'Amir',
        'Amirul',
        'ISMAIL BIN AHMAD',
        'JAX AUTO',
        'John',
        'TAN CHEE KEONG',
        'Wong',
        'Chong',
        'CKE AUTO SDN BHD',
        'Daniel',
        'Faizal',
        'LEE CHEE KEONG',
        'SAHABAT MOTOR',
        'AK AUTO SPEEDWAY ENTERPRISE',
        'Arif',
        'Chan',
        'Eric',
        'Khairul',
        'KIEW CHANG KEONG',
        'RIZQ HAYAT RESOURCES',
        'TAN',
        'Wing Hin Group Sdn Bhd',
        'Azrul',
        'CCBC AUTO VENTURE',
        'CHEY KOON MING',
        'Chin',
        'CK AUTOMOBILE (M) SDN BHD',
        'Cosmopolitan automotive',
        'CUST',
        'D 4 W ENTERPRISE',
        'David',
        'ERA AUTO SDN BHD',
        'Jason',
        'Joe',
        'LEE CHEE HONG',
        'Low',
        'Qc holiday premium rental sdn bhd',
        'RIZAL',
        'Shahril',
        'SOON POH USED CAR',
        'SUPERIOR REGAL SDN BHD',
        'AHMAD',
        'AJ AUTOMART SDN BHD',
        'Alex',
        'AZHAR BIN AHMAD',
        'BERMAZ MOTOR TRADING SDN BHD',
        'CASSA AUTO CITY SDN BHD',
        'Cosmopolitan automotive enterprise',
        'EASY EXPRESS AUTO',
        'EZZE MOTOR ENTERPRISE',
        'Faiz',
        'FARAH',
        'FIRDAUS',
        'FOREVERISE MOTOR',
        'HAFIZ',
        'Haziq',
        'HZN CARS SDN BHD',
        'Ismail',
        'ISMAIL BIN IBRAHIM',
        'Izzat',
        'Jeff',
        'Kelvin',
        'LEE CHEE SENG',
        'LIM',
        'Nazri',
        'NAZRI',
        'Nizam',
        'NORDIN BIN AHMAD',
        'PUBLIC STAR AUTO SDN BHD',
        'Ridzuan',
        'Rizal',
        'Sam',
        'Siti',
        'wan',
        'WONG CHEE WAI',
        'Yap',
        'Zamri',
        'Aiman',
        'AMIR',
        'AZMAN',
        'Azmi',
        'CASSA AUTO CENTRE SDN BHD',
        'CASSA AUTO CITY',
        'CHUNG AUTO SDN BHD',
        'COSMOPOLITAN AUTOMOTIVE',
        'Danny',
        'EXCELLENT DEAL (M) SDN BHD',
        'Fahmi',
        'Farhan',
        'Farid',
        'FOO AUTO TRADING SDN BHD',
        'foreverise',
        'HAJOON SDN BHD',
        'Helmi',
        'JS BROTHER MOTOR',
        'Kenny',
        'KHOO AUTOMART',
        'LAW BROTHER AUTO SDN BHD',
        'LEE',
        'LIM KOK KEONG',
        'Liyana',
        'MR TAN',
        'NEBULA AUTO',
        'NG CHEE KEONG',
        'NIZAM',
        'NORHAYATI BINTI ABDULLAH',
        'PRIMA MERDU SDN BHD',
        'ROSLI BIN ISMAIL',
        'SARAVANAN A/L SUBRAMANIAM',
        'SELAYANG JAYA ENTERPRISE',
        'SG CAR SDN BHD',
        'SITI AISHAH BINTI ISMAIL',
        'Syafiq',
        'TAN BOON ENG',
        'TAN CHEE SENG',
        'Wing Hin Motor Sdn Bhd.',
        'WINGHIN MOTOR SDN BHD',
    ],

];
