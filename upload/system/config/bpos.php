<?php
// BPOS registry: pages, roles and capabilities. Loaded with $config->load('bpos').

$_['bpos_version'] = '0.1.0';

// Session lifetime in seconds, sliding from the last request
$_['bpos_session_ttl'] = 43200;

// Login throttling
$_['bpos_user_max_fails'] = 5;     // failures before a username lock
$_['bpos_ip_max_fails']   = 20;    // failures per IP inside the window
$_['bpos_ip_window']      = 600;   // seconds

// Pages shown in the navigation. Set 'ready' to true when the page exists.
$_['bpos_pages'] = array(
	'sales'     => array('route' => 'bpos/sales',     'icon' => 'cart3',         'cap' => 'sales',     'ready' => true),
	'orders'    => array('route' => 'bpos/orders',    'icon' => 'receipt',       'cap' => 'orders',    'ready' => true),
	'products'  => array('route' => 'bpos/products',  'icon' => 'box-seam',      'cap' => 'products',  'ready' => false),
	'reports'   => array('route' => 'bpos/reports',   'icon' => 'bar-chart-line', 'cap' => 'reports',   'ready' => false),
	'customers' => array('route' => 'bpos/customers', 'icon' => 'people',        'cap' => 'customers', 'ready' => false)
);

// Roles from lowest to highest rank
$_['bpos_roles'] = array('cashier', 'supervisor', 'manager', 'owner');

// Page access per role. Owner always passes.
$_['bpos_role_pages'] = array(
	'cashier'    => array('sales'),
	'supervisor' => array('sales', 'orders', 'customers', 'reports'),
	'manager'    => array('sales', 'orders', 'products', 'reports', 'customers')
);

// Capabilities with a fixed minimum role
$_['bpos_cap_fixed'] = array(
	'products.edit'  => 'manager',
	'customers.edit' => 'supervisor'
);

// Editable capabilities (Settings > Permissions): module_bpos_perm[cap][role]
$_['bpos_perm_default'] = array(
	'discount' => array('cashier' => 1, 'supervisor' => 1, 'manager' => 1),
	'charge'   => array('cashier' => 0, 'supervisor' => 1, 'manager' => 1),
	'coupon'   => array('cashier' => 1, 'supervisor' => 1, 'manager' => 1),
	'refund'   => array('cashier' => 0, 'supervisor' => 1, 'manager' => 1),
	'void'     => array('cashier' => 0, 'supervisor' => 1, 'manager' => 1),
	'export'   => array('cashier' => 0, 'supervisor' => 0, 'manager' => 1)
);
