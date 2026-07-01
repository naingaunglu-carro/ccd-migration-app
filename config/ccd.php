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
        1 => 49,
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
        'App\\Modules\\Account\\User\\Models\\User' => ['source' => 'dealer_users', 'ref' => 'user', 'kind' => 'person'],
        'App\\Modules\\Contact\\Models\\Contact' => ['source' => 'dealer_contacts', 'ref' => 'contact', 'kind' => 'person'],
        'App\\Modules\\Contact\\Models\\CompanyContact' => ['source' => 'dealer_company_contacts', 'ref' => 'company_contact', 'kind' => 'company'],
        'App\\Modules\\Account\\Group\\Models\\Group' => ['source' => 'dealer_groups', 'ref' => 'group', 'kind' => 'company'],
    ],

];
