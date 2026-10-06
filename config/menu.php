<?php

declare(strict_types=1);

/*
 * Main navigation. An item is shown only if the user holds at least one of its
 * permissions ('any'). 'ready' => false items appear greyed out ("Soon") until
 * their phase is built. Hiding a menu item is a convenience only - every route
 * also checks the permission on the server.
 */
return [
    ['key' => 'dashboard', 'label' => 'Dashboard',      'path' => '/',             'any' => ['dashboard.view'], 'ready' => true],
    ['key' => 'branches',  'label' => 'Branch Details', 'path' => '/branches',     'any' => ['branches.view'],  'ready' => true],
    ['key' => 'sales',     'label' => 'Sales Details',  'path' => '/sales',        'any' => ['sales.view', 'targets.view', 'collections.view', 'pending_orders.view', 'samples.view', 'dc.view', 'outstanding.view'], 'ready' => true],
    ['key' => 'imports',   'label' => 'Excel Upload',   'path' => '/imports',      'any' => ['pending_orders.import', 'samples.import', 'dc.import', 'collections.import', 'sales.import', 'outstanding.import', 'customers.import', 'leads.import'], 'ready' => true],
    ['key' => 'hrm',       'label' => 'HRM',            'path' => '/hrm',          'any' => ['hrm.view'],       'ready' => true],
    ['key' => 'reports',   'label' => 'Reports',        'path' => '/reports',      'any' => ['reports.view'],   'ready' => true],
    ['key' => 'sms',       'label' => 'SMS',            'path' => '/sms',          'any' => ['sms.view'],       'ready' => true],
    ['key' => 'mail',      'label' => 'Mail',           'path' => '/mail',         'any' => ['mail.view'],      'ready' => true],
    ['key' => 'access',    'label' => 'Access',         'path' => '/access/users', 'any' => ['users.view', 'access.manage'], 'ready' => true],
    ['key' => 'customers', 'label' => 'Customers',      'path' => '/customers',    'any' => ['customers.view'], 'ready' => true],
    ['key' => 'leads',     'label' => 'Leads',          'path' => '/leads',        'any' => ['leads.view'],     'ready' => true],
    ['key' => 'products',  'label' => 'Products',       'path' => '/products',     'any' => ['products.view'],  'ready' => true],
    ['key' => 'settings',  'label' => 'Settings',       'path' => '/settings',     'any' => ['settings.manage', 'audit.view'], 'ready' => true],
];
