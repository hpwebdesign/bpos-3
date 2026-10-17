<?php
// BPOS shared strings. Keys starting with js_ are also sent to the browser.

$_['heading_title']      = 'Barik POS';
$_['text_brand']         = 'Barik POS';
$_['text_tagline']       = 'Point of sale for OpenCart';

// Navigation
$_['nav_sales']          = 'Sales';
$_['nav_orders']         = 'Orders';
$_['nav_products']       = 'Products';
$_['nav_reports']        = 'Reports';
$_['nav_customers']      = 'Customers';

// Roles
$_['role_cashier']       = 'Cashier';
$_['role_supervisor']    = 'Supervisor';
$_['role_manager']       = 'Manager';
$_['role_owner']         = 'Owner';

// Shell
$_['text_theme']         = 'Toggle theme';
$_['text_theme_short']   = 'Theme';
$_['text_language']      = 'Language';
$_['text_hello']        = 'Hi, %s';
$_['text_account']       = 'Account';
$_['text_menu']          = 'Main menu';
$_['text_store']         = 'Store';
$_['text_terminal']      = 'Terminal';
$_['text_no_terminal']   = 'Not selected';
$_['text_change_terminal'] = 'Change terminal';
$_['text_admin_session'] = 'OpenCart admin';
$_['button_sign_out']    = 'Sign out';

// Sign in
$_['text_sign_in']       = 'Sign in';
$_['text_sign_in_sub']   = 'Use your POS account to continue.';
$_['text_admin_in']      = 'Signed in to OpenCart admin';
$_['button_continue']    = 'Continue';
$_['entry_username']     = 'Username';
$_['entry_password']     = 'Password';
$_['entry_pin']          = 'PIN';
$_['text_method']        = 'Sign-in method';
$_['text_caps']          = 'Caps Lock is on';
$_['button_clear']       = 'Clear';
$_['text_backspace']     = 'Backspace';
$_['text_remember']      = 'Remember username';
$_['text_forgot']        = 'Forgot password?';
$_['text_hero_head']     = 'Sell faster. Count less.';
$_['text_hero_desc']     = 'Sign in to ring up sales, manage orders and keep every branch in sync.';
$_['text_choose_terminal'] = 'Which terminal is this device?';

// Sales (placeholder until the checkout build)
$_['heading_sales']      = 'Sales';
$_['text_sales_sub']     = 'Signed in and ready.';
$_['text_sales_next']    = 'The product catalog, cart and checkout arrive in the next build. Everything on this page comes from your live session.';
$_['text_cashier']       = 'Signed in as';
$_['text_role']          = 'Role';
$_['text_session_left']  = 'Session ends in';

// Errors
$_['error_disabled']     = 'Barik POS is turned off. Ask the store owner to enable it in the OpenCart admin.';
$_['error_session']      = 'Your session has ended. Please sign in again.';
$_['error_csrf']         = 'The page is out of date. Reload and try again.';
$_['error_forbidden']    = 'Your role cannot open this page.';
$_['error_request']      = 'This request is not allowed.';
$_['error_terminal']     = 'That terminal is not available for this store.';

// Browser strings
$_['js_err_username']    = 'Enter your username.';
$_['js_err_password']    = 'Enter your password.';
$_['js_err_pin']         = 'Enter your 4 to 6 digit PIN.';
$_['js_err_invalid_pw']  = 'Wrong username or password.';
$_['js_err_invalid_pin'] = 'Wrong username or PIN.';
$_['js_err_locked']      = 'Too many attempts. Try again in';
$_['js_err_network']     = 'Cannot reach the server. Check the connection and try again.';
$_['js_err_generic']     = 'Something went wrong. Try again.';
$_['js_forgot']          = 'Ask your manager to reset your password or PIN.';
$_['js_signing_in']      = 'Signing in…';
$_['js_welcome']         = 'Welcome back';
$_['js_show_pw']         = 'Show password';
$_['js_hide_pw']         = 'Hide password';
$_['js_opening_admin']   = 'Opening POS as admin…';
$_['js_choose_terminal'] = 'Choose terminal';
$_['js_terminal_saved']  = 'Terminal selected';
$_['js_offline']         = 'You are offline';
$_['js_discard']         = 'Discard unsaved changes?';
$_['js_leave']           = 'Leave';
$_['js_cancel']          = 'Cancel';
$_['js_signed_out']      = 'Signed out';

// Sales
$_['text_search_ph']     = 'Search product or scan barcode';
$_['text_layout']        = 'Product layout';
$_['text_layout_list']   = 'List layout';
$_['text_layout_grid']   = 'Grid layout';
$_['text_catalog']       = 'Catalog';
$_['text_categories']    = 'Categories';
$_['text_current_order'] = 'Current order';
$_['button_close']       = 'Close';
$_['text_sub_total']     = 'Sub-total';
$_['text_discount']      = 'Discount';
$_['text_extra_charge']  = 'Extra charge';
$_['text_total']         = 'Total';
$_['text_pay_cash']      = 'Cash';
$_['text_guest']         = 'Guest';

// Sales: errors
$_['error_sale_unavailable']      = 'This product is not available.';
$_['error_sale_option']           = 'Choose %s.';
$_['error_sale_option_invalid']   = 'The choice for %s is not valid.';
$_['error_sale_stock']            = 'Only %d in stock.';
$_['error_discount_limit']        = 'A cashier can give at most %s%% discount. Ask a supervisor.';
$_['error_customer_name']         = 'Enter a name (up to 64 characters).';
$_['error_customer_phone']        = 'Enter a valid phone number.';
$_['error_customer_phone_used']   = 'This phone number is already registered.';
$_['error_customer_email']        = 'Enter a valid email address.';
$_['error_customer_email_used']   = 'This email address is already registered.';
$_['error_customer_missing']      = 'The selected customer is no longer available.';
$_['error_customer_required']     = 'Choose a customer for this sale.';
$_['error_fix_fields']            = 'Fix the highlighted fields.';
$_['error_terminal_required']     = 'Choose a terminal before taking payment.';
$_['error_cart_invalid']          = 'Some items cannot be sold. Check the order and try again.';
$_['error_coupon_invalid']        = 'The coupon is not valid.';
$_['error_payment_invalid']       = 'This payment method is not available.';
$_['error_tendered_low']          = 'The cash received is less than the total.';
$_['error_order_status']          = 'Order #%d was saved but its status could not be updated. Check it in the OpenCart admin.';
$_['error_order_missing']         = 'Order not found.';
$_['error_forbidden']             = 'You are not allowed to do that.';

// Receipt
$_['text_receipt']       = 'Receipt';
$_['text_rc_cashier']    = 'Cashier';
$_['text_customer']      = 'Customer';
$_['text_payment']       = 'Payment';
$_['text_received']      = 'Received';
$_['text_change']        = 'Change';

// Sales: client strings
$_['js_close']           = 'Close';
$_['js_sl_item']         = 'item';
$_['js_sl_items']        = 'items';
$_['js_sl_all']          = 'All';
$_['js_sl_results']      = 'Results';
$_['js_sl_products']     = 'Products';
$_['js_sl_no_match']     = 'No product matches';
$_['js_sl_not_sellable'] = 'Not available in POS';
$_['js_sl_out']          = 'Out of stock';
$_['js_sl_only']         = 'Only';
$_['js_sl_left']         = 'left';
$_['js_sl_from']         = 'from';
$_['js_sl_options']      = 'Options';
$_['js_sl_more']         = 'Load more';
$_['js_sl_no_more']      = 'No more stock for this item.';
$_['js_sl_added']        = 'added';
$_['js_sl_required']     = 'Required';
$_['js_sl_optional']     = 'Optional';
$_['js_sl_choose_one']   = 'Choose one';
$_['js_sl_choose_any']   = 'Choose any';
$_['js_sl_add']          = 'Add to order';
$_['js_sl_update']       = 'Update item';
$_['js_sl_updated']      = 'Item updated';
$_['js_sl_change_opts']  = 'Change options';
$_['js_sl_each']         = 'each';
$_['js_sl_qty']          = 'Quantity';
$_['js_sl_dec']          = 'Decrease';
$_['js_sl_inc']          = 'Increase';
$_['js_sl_empty']        = 'Tap a product or scan a barcode to start the order.';
$_['js_sl_cash']         = 'Cash';
$_['js_sl_exact']        = 'Exact';
$_['js_sl_guest']        = 'Guest';
$_['js_sl_customer']     = 'Customer';
$_['js_sl_choose']       = 'Choose';
$_['js_sl_shipping']     = 'Shipping';
$_['js_sl_payment']      = 'Payment';
$_['js_sl_off']          = 'off';
$_['js_sl_discount']     = 'Discount';
$_['js_sl_charge']       = 'Charge';
$_['js_sl_coupon']       = 'Coupon';
$_['js_sl_apply']        = 'Apply';
$_['js_sl_remove']       = 'Remove';
$_['js_sl_save']         = 'Save';
$_['js_sl_new_customer'] = 'New customer';
$_['js_sl_full_name']    = 'Full name';
$_['js_sl_full_name_ph'] = 'e.g. Budi Santoso';
$_['js_sl_phone']        = 'Phone';
$_['js_sl_phone_ph']     = 'e.g. 0812-3456-7788';
$_['js_sl_email_opt']    = 'Email (optional)';
$_['js_sl_search_customer'] = 'Search name or phone';
$_['js_sl_walkin']       = 'Walk-in, no account';
$_['js_sl_no_customer']  = 'No customer found';
$_['js_sl_customer_added'] = 'Customer added';
$_['js_sl_shipping_method'] = 'Shipping method';
$_['js_sl_payment_method'] = 'Payment method';
$_['js_sl_cash_received'] = 'Cash received';
$_['js_sl_other_amount'] = 'Other amount';
$_['js_sl_done']         = 'Done';
$_['js_sl_max_discount'] = 'Cashier limit';
$_['js_sl_extra_charge'] = 'Extra charge';
$_['js_sl_charge_ph']    = 'e.g. 3.00 for gift wrap';
$_['js_sl_coupon_code']  = 'Coupon code';
$_['js_sl_enter_code']   = 'Enter code';
$_['js_sl_code']         = 'Code';
$_['js_sl_coupon_bad']   = 'is not valid or has expired.';
$_['js_sl_received']     = 'Received';
$_['js_sl_change']       = 'Change';
$_['js_sl_pay']          = 'Pay';
$_['js_sl_view_order']   = 'View order';
$_['js_sl_order_empty']  = 'Order is empty';
$_['js_sl_paid']         = 'Payment received';
$_['js_sl_order']        = 'Order';
$_['js_sl_method']       = 'Method';
$_['js_sl_total']        = 'Total';
$_['js_sl_print']        = 'Print receipt';
$_['js_sl_print_fail']   = 'Could not open the print dialog.';
$_['js_sl_new_order']    = 'New order';
$_['js_sl_clear_q']      = 'Clear the cart? All items in the current order will be removed.';
$_['js_sl_clear_ok']     = 'Clear cart';
$_['js_sl_no_barcode']   = 'No sellable product for';

// Orders
$_['heading_orders'] = 'Orders';
$_['button_export'] = 'Export CSV';
$_['text_orders_search'] = 'Search order, customer, phone or email';
$_['text_range'] = 'Date range';
$_['text_range_today'] = 'Today';
$_['text_range_7'] = '7 days';
$_['text_range_30'] = '30 days';
$_['text_range_custom'] = 'Custom';
$_['text_date_from'] = 'From date';
$_['text_date_to'] = 'To date';
$_['text_status'] = 'Status';
$_['text_order'] = 'Order';
$_['text_when'] = 'When';
$_['text_order_detail'] = 'Order detail';
$_['text_comment_void'] = 'Voided from POS';
$_['text_comment_refund'] = 'Refunded from POS';
$_['error_status_unset'] = 'Set the refund and void statuses in the BPOS settings first.';
$_['error_void_state'] = 'Only a completed order from today can be voided.';
$_['error_refund_state'] = 'Only a completed order can be refunded.';
$_['error_status_failed'] = 'The order status could not be changed. Check the order in the OpenCart admin.';
$_['js_od_status_other'] = 'Other';
$_['js_od_empty'] = 'No orders match these filters.';
$_['js_od_prev'] = 'Previous page';
$_['js_od_next'] = 'Next page';
$_['js_od_tab_all'] = 'All';
$_['js_od_tab_done'] = 'Completed';
$_['js_od_tab_refund'] = 'Refunded';
$_['js_od_tab_void'] = 'Voided';
$_['js_od_tab_other'] = 'Other';
$_['js_od_all_pay'] = 'All payments';
$_['js_od_all_cashiers'] = 'All cashiers';
$_['js_od_when'] = 'When';
$_['js_od_cashier'] = 'Cashier';
$_['js_od_customer'] = 'Customer';
$_['js_od_payment'] = 'Payment';
$_['js_od_received'] = 'Received';
$_['js_od_change'] = 'Change';
$_['js_od_coupon'] = 'Coupon';
$_['js_od_items'] = 'Items';
$_['js_od_history'] = 'History';
$_['js_od_reprint'] = 'Reprint receipt';
$_['js_od_void'] = 'Void order';
$_['js_od_refund'] = 'Refund order';
$_['js_od_void_title'] = 'Void this order?';
$_['js_od_refund_title'] = 'Refund this order?';
$_['js_od_void_note'] = 'The full order is cancelled and its stock is returned. Only possible on the day of the sale.';
$_['js_od_refund_note'] = 'The full order is marked as refunded and its stock is returned. Return the money to the customer yourself.';
$_['js_od_reason'] = 'Reason (optional)';
$_['js_od_voided'] = 'Order voided';
$_['js_od_refunded'] = 'Order refunded';
$_['js_od_export_cut'] = 'Export is limited to the newest 5000 orders.';
