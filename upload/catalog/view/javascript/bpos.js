// Initialize Notyf (top-right) if available
if (typeof window !== 'undefined' && typeof window.Notyf !== 'undefined') {
  window.notyf = window.notyf || new Notyf({ duration: 1000, position: { x: 'right', y: 'top' } });
}

$(document).ready(function() {

//toastr.options = {
//  timeOut: 1000,
//  extendedTimeOut: 0,
//  showDuration: 150,
//  hideDuration: 150,
//  progressBar: true,
//  newestOnTop: true,
//  closeButton: false
//};

    $('footer .tab').on('click', function() {
        $('footer .tab').removeClass('active');
        $(this).addClass('active');

        let route = '';
        if ($(this).hasClass('home')) route = 'bpos/home';
        if ($(this).hasClass('invoices')) route = 'bpos/invoice';
        if ($(this).hasClass('orders')) route = 'bpos/order';
        if ($(this).hasClass('statistics')) route = 'bpos/statistic';
        if ($(this).hasClass('reports')) route = 'bpos/report';
        if ($(this).hasClass('cart')) {
            var target = $('#checkout-summary');
            var scrollBottom = target.offset().top + target.outerHeight() - $(window).height();

            $('html, body').animate({
                scrollTop: scrollBottom
            }, 500);

            // Opsional: beri fokus
            target.attr('tabindex', -1).focus();
        }
        if (route) loadContent(route);
    });

    $(document).on('click','#btn-logout', function() {
        $('#logoutModal').modal('show');
    });

    // Print Bills
    $(document).on('click', '.print', function(e) {
        e.preventDefault();

        let activePayment = $('.paymentbtn.active').data('code');


        $('.payment-error').remove();

        if (!activePayment) {
            $('.payment-error-container').html('<div class="payment-error text-danger mt-2">Please select a payment method</div>');
            return;
        }

        let activeShipping = $('.shippingbtn.active').data('code');


        $('.shipping-error').remove();

        if (!activePayment) {
            $('.shipping-error-container').html('<div class="shipping-error text-danger mt-2">Please select a shipping method</div>');
            return;
        }


        $("body").busyLoad("show", {
            spinner: "accordion",
            text: "Processing Order...",
            textPosition: "bottom",
            background: "rgba(144,238,144,0.7)",
            animation: "fade"
        });

        $.ajax({
            url: 'index.php?route=bpos/order/addOrder',
            type: 'post',
            dataType: 'json',
            success: function(json) {
                $("body").busyLoad("hide");

                if (json.error) {
                    $('.payment-error-container').html('<div class="payment-error text-danger mt-2">' + json.error + '</div>');
                    return;
                }

                if (json.success) {
                    console.log(json['order_id']);
                    loadContent('bpos/checkout/order_confirm&order_id='+ json['order_id']);
                    //window.location.href = 'index.php?route=bpos/checkout/order_confirm&order_id=' + json['order_id'];
                }
            },
            error: function() {
                $("body").busyLoad("hide");
                alert('Order failed. Please try again.');
            }
        });
    });


    $(document).on('click', '.paymentbtn', function() {
        let code = $(this).data('code');

        $('.paymentbtn').removeClass('active');
        $(this).addClass('active');

        $.ajax({
            url: 'index.php?route=bpos/checkout/checkout/setPayment',
            type: 'post',
            data: { code: code },
            dataType: 'json',
            success: function(json) {
                if (window.notyf) { notyf.success(json['success']); }
                updateCheckoutPanel();
            }
        });
    });

    $(document).on('click', '.shippingbtn', function() {
        let code = $(this).data('code');
        console.log(code);
        $('.shippingbtn').removeClass('active');
        $(this).addClass('active');

        $.ajax({
            url: 'index.php?route=bpos/checkout/checkout/setShipping',
            type: 'post',
            data: { code: code },
            dataType: 'json',
            success: function(json) {
                if (window.notyf) { notyf.success(json['success']); }
                updateCheckoutPanel();
            }
        });
    });

    $(document).on('input', '#pos-search', function() {
        let query = $(this).val();
        if (query.length >= 2) {
            $.ajax({
                url: 'index.php?route=bpos/product/search&filter_name=' + encodeURIComponent(query),
                type: 'get',
                dataType: 'html',
                success: function(html) {
                    $('#products-list').html(html);
                    $('#products_paginate').hide();
                }
            });
        } else {
            loadPage('index.php?route=bpos/home');

        }
    });


    // Category
    $(document).on('click', '.filters .btn', function (e) {
        e.preventDefault();
        $('.filters .btn').removeClass('is-active');
        $(this).addClass('is-active');

        const category_id = $(this).data('id');
        loadPage('index.php?route=bpos/home&category_id='+category_id);
        // $('#products-list').html('<div class="text-center py-5">Loading...</div>');

        // $.ajax({
        //     url: 'index.php?route=bpos/home/products&category_id=' + encodeURIComponent(category_id),
        //     type: 'get',
        //     dataType: 'html',
        //     success: function (html) {
        //         $('#products-list').html(html);
        //     },
        //     error: function () {
        //         $('#products-list').html('<div class="text-center py-5">Error loading products</div>');
        //     }
        // });
    });



    $(document).on('click', '.qty-plus', function() {
        let key = $(this).data('key');
        let qty = $(this).data('qty');
        let prev = parseInt($(this).closest('.qty').find('span').text(), 10) || 0;
        updateCartQty(key, qty, prev);
    });

    $(document).on('click', '.qty-minus', function() {
        let key = $(this).data('key');
        let qty = $(this).data('qty');
        let prev = parseInt($(this).closest('.qty').find('span').text(), 10) || 0;
        updateCartQty(key, qty, prev);
    });

    function updateCartQty(key, qty, prevQty) {
        $.ajax({
            url: 'index.php?route=bpos/checkout/cart/edit',
            type: 'post',
            data: {
                key: key,
                quantity: qty,
                mode: 'delta'
            },
            dataType: 'json',
            success: function(json) {
                updateCheckoutPanel();
                if (window.notyf) {
                    var p = typeof prevQty === 'number' ? prevQty : null;
                    if (p != null) {
                        if (qty === 0) {
                            notyf.success('Item removed');
                        } else {
                            var msg = 'Qty: ' + p + ' → ' + qty;
                            // notyf.success(msg);
                            notyf.success('Quantity updated');
                        }
                    } else {
                        notyf.success('Quantity updated');
                    }
                }
            },
            error: function(){
                if (window.notyf) { notyf.error('Failed to update quantity'); }
            }
        });
    }

    // Clear cart
    $(document).on('click', '#clear-cart', function(e) {
    e.preventDefault();

    Swal.fire({
        title: 'Are you sure?',
        text: "Cart will be empty!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Yes',
        cancelButtonText: 'No'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: 'index.php?route=bpos/checkout/cart/clear',
                type: 'post',
                dataType: 'json',
                success: function(json) {
                    updateCheckoutPanel();
                },
                error: function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Failed',
                        text: 'An error occurred while emptying the cart.'
                    });
                }
            });
        }
    });
});

    // Add to Cart

    // $(document).on('click', '.product-item', function() {
    //     let product_id = $(this).data('id');
    //     posAddToCart(product_id);
    // });

    $(document).on('click', '.product-item', function() {
        let product_id = $(this).data('id');

        $.ajax({
            url: 'index.php?route=bpos/product/checkOptions&product_id=' + product_id,
            dataType: 'json',
            success: function(json) {
                if (json.has_option) {
                    // Tampilkan popup form option
                    $.ajax({
                        url: 'index.php?route=bpos/product/options&product_id=' + product_id,
                        dataType: 'html',
                        success: function(html) {
                            $('#productOptionModal .modal-content').html(html);
                            $('#productOptionModal').modal('show');
                        }
                    });
                } else {
                    // Langsung add to cart
                    $.ajax({
                        url: 'index.php?route=bpos/checkout/cart/add',
                        type: 'post',
                        data: { product_id: product_id, quantity: 1 },
                        dataType: 'json',
                        success: function(json) {
                            if (json['success']) {
                                if (window.notyf) { notyf.success(json['success']); }
                                $('.btn--cart .counter').html(json['total_cart']);
                                updateCheckoutPanel();
                            }

                        }
                    });
                }
            }
        });
    });
    $(document).on('click', '#btn-add-with-options', function() {
        let form_data = $('#form-product-options').serialize();
        $.ajax({
            url: 'index.php?route=bpos/checkout/cart/add',
            type: 'post',
            data: form_data + '&quantity=1',
            dataType: 'json',
            success: function(json) {
                // clear error lama
                $('.text-danger').remove();
                $('.form-group').removeClass('has-error');

                if (json['error']) {
                    if (json['error']['option']) {
                        for (let i in json['error']['option']) {
                            let element = $('#input-option' + i.replace('_', '-'));

                            if (element.parent().hasClass('input-group')) {
                                element.parent().after('<div class="text-danger">' + json['error']['option'][i] + '</div>');
                            } else {
                                element.after('<div class="text-danger">' + json['error']['option'][i] + '</div>');
                            }
                        }
                    }

                    if (json['error']['recurring']) {
                        $('select[name="recurring_id"]').after('<div class="text-danger">' + json['error']['recurring'] + '</div>');
                    }

                    // scroll ke modal body biar user lihat error
                    $('#productOptionModal .modal-body').animate({ scrollTop: 0 }, 'slow');
                }
                if (json['success']) {
                    $('#productOptionModal').modal('hide');
                    if (window.notyf) { notyf.success(json['success']); }
                    $('.btn--cart .counter').html(json['total_cart']);
                    updateCheckoutPanel();
                }
            }
        });
    });



    // Fungsi Add to Cart
    function posAddToCart(product_id, quantity = 1) {
        $.ajax({
            url: 'index.php?route=bpos/checkout/cart/add',
            type: 'post',
            data: { product_id: product_id, quantity: quantity },
            dataType: 'json',
            success: function(json) {
                if (json['error']) {
                    if (window.notyf) { notyf.error(json['error']['warning'] || 'Error adding product to cart'); }
                }
                if (json['success']) {
                    if (window.notyf) { notyf.success(json['success']); }
                    //alert(json['success']); // Bisa ganti pakai notifikasi lebih bagus
                    updateCheckoutPanel();
                    var target = $('#checkout-summary');
                    var scrollBottom = target.offset().top + target.outerHeight() - $(window).height();

                    $('html, body').animate({
                        scrollTop: scrollBottom
                    }, 500);

                    // Opsional: beri fokus
                    target.attr('tabindex', -1).focus();

                }
            },
            error: function() {
                if (window.notyf) { notyf.error('Error: Could not add to cart'); }
                //alert('Error: Could not add to cart');
            }
        });
    }

    // Update checkout panel (kalau mau menampilkan isi keranjang di POS)


});
function updateCheckoutPanel() {
        $.ajax({
            url: 'index.php?route=bpos/checkout/checkout&html=1',
            type: 'get',
            dataType: 'html',
            success: function(html) {
                $('#checkout-summary').replaceWith(html);
            }
        });
    }
function loadContent(route) {
        let busyTimer;

        $.ajax({
            url: 'index.php?route=' + route + '&format=json',
            type: 'get',
            dataType: 'json',
            beforeSend: function() {
                busyTimer = setTimeout(function() {
                    $("body").busyLoad("show", {
                        spinner: "circle-line",
                        text: "",
                        textPosition: "bottom",
                        background: "rgba(0,0,0,0.3)",
                        animation: "fade"
                    });
                }, 2000);
            },
            success: function(json) {
                if (json['output']) {
                    $('#main-content').html(json['output']);
                } else {
                    $('#main-content').html('<p>No content</p>');
                }
            },
            error: function() {
                $('#main-content').html('<p>Error loading content</p>');
            },
            complete: function() {
                clearTimeout(busyTimer);
                $("body").busyLoad("hide");
            }
        });
}

function loadPage(route) {
    $.ajax({
        url: route + '&format=json',
        type: 'get',
        dataType: 'json',
        beforeSend: function() {

        },
        success: function(json) {
            if (json.output) {
                $('#main-content').html(json.output);
                 $('html, body').animate({ scrollTop: 0 }, 'smooth');
                // window.scrollTo({ top: 0, behavior: 'smooth' });
            } else {
                $('#main-content').html('<p>No content</p>');
            }
        },
        error: function() {
            $('#main-content').html('<p>Error loading content</p>');
        },
        complete: function() {
        }
    });
}

/* =========================
   CONFIG ENDPOINTS
   ========================= */
var API = {
  customers_list:   'index.php?route=bpos/customer/list',       // GET
  customers_create: 'index.php?route=bpos/customer/create',     // POST {name}
  customers_edit:   'index.php?route=bpos/customer/edit',       // POST {id, name}
  cart_summary:     'index.php?route=bpos/checkout/cart/summary',        // GET -> { subtotal: 125000 }
  apply_discount:   'index.php?route=bpos/checkout/cart/apply_discount', // POST {percent, fixed}
  apply_charge:     'index.php?route=bpos/checkout/cart/apply_charge',   // POST {percent, fixed}
  remove_discount:  'index.php?route=bpos/checkout/cart/remove_discount',// POST
  remove_charge:    'index.php?route=bpos/checkout/cart/remove_charge',  // POST
  customers_login:  'index.php?route=bpos/customer/login',      // POST {id}
  customers_unset:  'index.php?route=bpos/customer/clear',      // POST
  coupons_list:     'index.php?route=bpos/checkout/cart/coupons',        // GET -> { coupons: [...] }
  apply_coupon:     'index.php?route=bpos/checkout/cart/apply_coupon',   // POST {code}
  remove_coupon:    'index.php?route=bpos/checkout/cart/remove_coupon'   // POST
};

/* =========================
   UTILITIES
   ========================= */
var CUSTOMER_LIST = [];

function getCurrentCustomerName(){
  var el = document.getElementById('pos-current-customer');
  if (!el) return 'Guest Customer';
  var d = el.getAttribute('data-customer-name');
  var t = (d && d.trim()) || (el.textContent||'').trim();
  if (!t) t = 'Guest Customer';
  return t;
}

function formatIDR(n){
  var num = Number(n || 0);
  var el = document.getElementById('bpos-currency');
  var code = (el && el.getAttribute('data-code')) || 'IDR';
  try {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: code }).format(num);
  } catch (e) {
    // Fallback to IDR formatting without decimals
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(num);
  }
}
function toNumber(v){
  if (v === '' || v == null) return 0;
  var n = Number(v);
  return isNaN(n) ? 0 : n;
}
function debounce(fn, wait){
  var t; return function(){ clearTimeout(t); var args=arguments, ctx=this; t=setTimeout(function(){fn.apply(ctx,args);}, wait||200); };
}

/* =========================
   AJAX HELPERS
   ========================= */
function ajaxLoadCustomers(){
  return $.getJSON(API.customers_list).then(function(res){
    CUSTOMER_LIST = (res && res.customers) ? res.customers : [];
    return CUSTOMER_LIST;
  });
}
function ajaxCreateCustomer(payload) {
  return $.post(API.customers_create, payload, null, 'json');
}
function ajaxEditCustomer(payload) {
  return $.post(API.customers_edit, payload, null, 'json');
}
function ajaxLoginCustomer(payload) {
  return $.post(API.customers_login, payload, null, 'json');
}
function ajaxLoadCoupons(){
  return $.getJSON(API.coupons_list).then(function(res){ return (res && res.coupons) ? res.coupons : []; });
}
function applyCoupon(payload) {
  return $.post(API.apply_coupon, payload, null, 'json');
}
function removeCoupon(){
  return $.post(API.remove_coupon, {}, null, 'json');
}
function fetchCartSummary(){
  // Return full summary object: { subtotal, discount:{percent,fixed,amount}, charge:{percent,fixed,amount}, total }
  return $.getJSON(API.cart_summary).then(function(res){
    return res || { subtotal:0, discount:{percent:0,fixed:0,amount:0}, charge:{percent:0,fixed:0,amount:0}, total:0 };
  });
}
function applyDiscount(payload) {
  return $.post(API.apply_discount, payload, null, 'json');
}
function applyCharge(payload) {
  return $.post(API.apply_charge, payload, null, 'json');
}
function removeDiscount(){
  return $.post(API.remove_discount, {}, null, 'json');
}
function removeCharge(){
  return $.post(API.remove_charge, {}, null, 'json');
}
function ajaxUnsetCustomer(){
  return $.post(API.customers_unset, {}, null, 'json');
}

/* =========================
   HTML BUILDERS
   ========================= */
function buildCustomerHTML(){
  var cur = getCurrentCustomerName();
  return ''+
  '<div class="swal-form">'+
    '<div class="btn-group" style="width:100%;gap:6px;display:flex;margin-top:10px;">'+
      '<button type="button" class="btn btn-success" id="swal_add_customer" style="flex:1;">Add</button>'+

    '</div>'+
    '<div class="form-group" style="margin-bottom:8px;">'+
      '<div style="font-size:14px;">Current Customer: <strong>'+ $('<div>').text(cur).html() +'</strong></div>'+
    '</div>'+
    '<label style="display:block;margin-bottom:6px;">Change Customer</label>'+
    '<input type="text" id="swal_customer_input" class="form-control" placeholder="Type name to search" autocomplete="off" />'+
    '<input type="hidden" id="swal_customer_id" />'+
    '<div id="swal_customer_suggest" style="position:relative;">'+
      '<div class="swal-ac-list" style="position:absolute;top:100%;left:0;right:0;margin-top:4px;z-index:9999;background:#fff;border:1px solid #e5e7eb;border-radius:6px;box-shadow:0 8px 22px rgba(13,33,80,.08);display:none;max-height:220px;overflow:auto"></div>'+
    '</div>'+
  '</div>';
}
function buildDiscountHTML(){
  return ''+
  '<div class="swal-form">'+
    '<div class="form-group" style="margin-bottom:10px;">'+
      '<label>% Discount</label>'+
      '<input type="number" min="0" step="0.01" id="swal_discount_pct" class="form-control" placeholder="e.g. 10">'+
    '</div>'+
    '<div class="form-group">'+
      '<label>Fixed Discount</label>'+
      '<input type="number" min="0" step="1" id="swal_discount_fix" class="form-control" placeholder="e.g. 25000">'+
    '</div>'+
    '<div id="swal_discount_preview" style="margin-top:8px;padding:8px;border:1px dashed #e5e7eb;border-radius:8px;font-size:12px;line-height:1.4;">'+
      '<div><strong>Subtotal:</strong> <span data-subtotal>-</span></div>'+
      '<div><strong>Discount %:</strong> <span data-from-pct>-</span></div>'+
      '<div><strong>Fixed Discount:</strong> <span data-from-fix>-</span></div>'+
      '<div><strong>Total diskon:</strong> <span data-total-disc>-</span></div>'+
      '<div><strong>New total:</strong> <span data-new-total>-</span></div>'+
    '</div>'+
    '<p style="font-size:12px;color:#6b7280;margin-top:6px;">You can fill in one or both.</p>'+
  '</div>';
}
function buildChargeHTML(){
  return ''+
  '<div class="swal-form">'+
    '<div class="form-group" style="margin-bottom:10px;">'+
      '<label>Charge %</label>'+
      '<input type="number" min="0" step="0.01" id="swal_charge_pct" class="form-control" placeholder="e.g. 5">'+
    '</div>'+
    '<div class="form-group">'+
      '<label>Fixed Charge</label>'+
      '<input type="number" min="0" step="1" id="swal_charge_fix" class="form-control" placeholder="e.g. 5000">'+
    '</div>'+
    '<div id="swal_charge_preview" style="margin-top:8px;padding:8px;border:1px dashed #e5e7eb;border-radius:8px;font-size:12px;line-height:1.4;">'+
      '<div><strong>Subtotal:</strong> <span data-subtotal>-</span></div>'+
      '<div><strong>Charge %:</strong> <span data-from-pct>-</span></div>'+
      '<div><strong>Fixed Charge:</strong> <span data-from-fix>-</span></div>'+
      '<div><strong>Total charge:</strong> <span data-total-charge>-</span></div>'+
      '<div><strong>New total:</strong> <span data-new-total>-</span></div>'+
    '</div>'+
    '<p style="font-size:12px;color:#6b7280;margin-top:6px;">You can fill in one or both.</p>'+
  '</div>';
}

function buildCouponHTML(list){
  var items = (list||[]).map(function(c){
    var label = c.type === 'P' ? (c.discount + '%') : formatIDR(c.discount);
    var end = (c.date_end && c.date_end !== '0000-00-00') ? ('<small style="color:#6b7280">until '+c.date_end+'</small>') : '';
    return '\n      <div class="swal-coupon-item" data-code="'+c.code+'" style="padding:8px 10px;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:6px;cursor:pointer;">\n        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">\n          <div>\n            <div style="font-weight:600">'+c.name+' <span style="color:#374151">('+c.code+')</span></div>\n            <div style="font-size:12px;color:#6b7280">'+label+' off '+end+'</div>\n          </div>\n          <div><span class="badge" style="background:#f3f4f6;color:#111827;">Select</span></div>\n        </div>\n      </div>';
  }).join('');
  if (!items) items = '<div class="text-muted" style="padding:8px 0">No active coupons</div>';
  return ''+
    '<div class="swal-form">'+
      '<div class="form-group" style="margin-bottom:10px;">'+
        '<label>Coupon code</label>'+
        '<div style="display:flex;gap:6px;">'+
          '<input type="text" id="swal_coupon_code" class="form-control" placeholder="Enter coupon code" style="flex:1" />'+
        '</div>'+
      '</div>'+
      '<div id="swal_coupon_list" style="max-height:220px;overflow:auto">'+items+'</div>'+
    '</div>';
}

/* =========================
   CUSTOMER EDITOR (Add/Edit)
   ========================= */
function openCustomerEditor(mode, current){
  var title = (mode === 'add') ? 'Add Customer' : 'Edit Customer';
  var nameVal = current && current.name ? current.name : '';
  var idVal = current && current.id ? current.id : '';
  return Swal.fire({
    title: title,
    html:
      '<div class="swal-form">'+
        (mode === 'edit'
          ? '<div class="form-group"><label>Customer ID</label><input id="swal_cust_id" class="form-control" value="'+(idVal||'')+'" disabled></div>'
          : '')+
        '<div class="form-group"><label>Name</label><input id="swal_cust_name" class="form-control" value="'+(nameVal||'')+'" placeholder="Full Name"></div>'+
      '</div>',
    showCancelButton: true,
    confirmButtonText: (mode === 'add') ? 'Create' : 'Save',
    focusConfirm: false,
    preConfirm: function(){
      var name = $('#swal_cust_name').val().trim();
      if (!name) { Swal.showValidationMessage('Name is required'); return false; }
      if (mode === 'add') {
        return ajaxCreateCustomer({name:name}).then(function(res){
          if (!res || !res.customer) throw new Error('Create failed');
          return {mode:'add', id:res.customer.id, name:res.customer.name};
        }).catch(function(err){
          Swal.showValidationMessage(err.message || 'Create customer failed');
          return false;
        });
      } else {
        return ajaxEditCustomer({id:idVal, name:name}).then(function(res){
          if (!res || !res.customer) throw new Error('Edit failed');
          return {mode:'edit', id:res.customer.id, name:res.customer.name};
        }).catch(function(err){
          Swal.showValidationMessage(err.message || 'Edit customer failed');
          return false;
        });
      }
    }
  });
}

/* =========================
   GENERIC SWAL
   ========================= */
function openSwal(type, onSubmit){
  var cfg;
  if (type === 'customer') {
    cfg = {
      title: 'Customer',
      html: '<div style="text-align:center;padding:14px 0;">Loading...</div>',
      // willOpen: function(){  },
      didOpen: function(){
        ajaxLoadCustomers().then(function(){
          // Swal.update({ html: buildCustomerHTML() });
          const html = buildCustomerHTML();
            Swal.getHtmlContainer().innerHTML = html;  // ✅ tanpa update()
          bindCustomerButtons();
          bindCustomerAutocomplete();
        }).catch(function(){
          Swal.getHtmlContainer().innerHTML = '<p style="color:#ef4444;">Failed to load customers</p>';
        });
      },
      preConfirm: function(){
        var id = $('#swal_customer_id').val();
        var nameInput = ($('#swal_customer_input').val() || '').trim();
        var found = null;
        if (id) {
          found = CUSTOMER_LIST.find(function(c){ return String(c.id) === String(id); });
        } else if (nameInput) {
          var matches = CUSTOMER_LIST.filter(function(c){ return c.name.toLowerCase() === nameInput.toLowerCase(); });
          if (matches.length === 1) { found = matches[0]; }
        }
        if (!found) { Swal.showValidationMessage('Please select a customer'); return false; }
        return {type:'customer', customer: found};
      }
    };
  }
  if (type === 'coupon') {
    cfg = {
      title: 'Coupon',
      html: '<div style="text-align:center;padding:14px 0;">Loading...</div>',
      didOpen: function(){
        Promise.all([fetchCartSummary(), ajaxLoadCoupons()])
          .then(function(tuple){
            var summary = tuple[0] || {};
            var list = tuple[1] || [];
            Swal.update({ html: buildCouponHTML(list) });
            if (summary && summary.coupon){
              $('#swal_coupon_code').val(summary.coupon);
            }
            $(document).off('click.swal_coupon').on('click.swal_coupon', '#swal_coupon_list .swal-coupon-item', function(){
              var code = $(this).data('code');
              $('#swal_coupon_code').val(code).focus();
            });
            // Optional paste support
            // $(document).off('click.swal_coupon_paste').on('click.swal_coupon_paste', '#swal_coupon_paste', function(){
            //   if (navigator.clipboard && navigator.clipboard.readText){
            //     navigator.clipboard.readText().then(function(t){ $('#swal_coupon_code').val((t||'').trim()).focus(); });
            //   }
            // });
          })
          .catch(function(){
            Swal.update({ html: '<p style="color:#ef4444;">Failed to load coupons</p>' });
          });
      },
      preConfirm: function(){
        var code = ($('#swal_coupon_code').val()||'').trim();
        if (!code){ Swal.showValidationMessage('Enter coupon code'); return false; }
        return {type:'coupon', code: code};
      }
    };
  }
  if (type === 'discount' || type === 'charge') {
    var isDiscount = (type === 'discount');
    cfg = {
      title: isDiscount ? 'Add Discount' : 'Add Charge',
      html: isDiscount ? buildDiscountHTML() : buildChargeHTML(),
      willOpen: function() { Swal.showLoading(); },
      didOpen: function() {
        fetchCartSummary().then(function(summary){
          var subtotal = Number(summary && summary.subtotal || 0);
          var wrap = isDiscount ? '#swal_discount_preview' : '#swal_charge_preview';
          var $wrap = $(wrap);
          $wrap.find('[data-subtotal]').text(formatIDR(subtotal));

          // Prefill from session if available
          if (isDiscount && summary && summary.discount) {
            $('#swal_discount_pct').val(toNumber(summary.discount.percent));
            $('#swal_discount_fix').val(toNumber(summary.discount.fixed));
          } else if (!isDiscount && summary && summary.charge) {
            $('#swal_charge_pct').val(toNumber(summary.charge.percent));
            $('#swal_charge_fix').val(toNumber(summary.charge.fixed));
          }

          var recalc = debounce(function(){
            var pct = toNumber($(isDiscount ? '#swal_discount_pct' : '#swal_charge_pct').val());
            var fix = toNumber($(isDiscount ? '#swal_discount_fix' : '#swal_charge_fix').val());
            var fromPct = Math.floor(subtotal * (pct/100));
            var fromFix = fix;
            var delta = fromPct + fromFix;
            var newTotal = isDiscount ? Math.max(0, subtotal - delta) : subtotal + delta;
            $wrap.find('[data-from-pct]').text(formatIDR(fromPct));
            $wrap.find('[data-from-fix]').text(formatIDR(fromFix));
            $wrap.find(isDiscount ? '[data-total-disc]' : '[data-total-charge]').text(formatIDR(delta));
            $wrap.find('[data-new-total]').text(formatIDR(newTotal));
          }, 120);
          $(document).on('input', isDiscount ? '#swal_discount_pct,#swal_discount_fix' : '#swal_charge_pct,#swal_charge_fix', recalc);
          recalc();
          Swal.hideLoading();
        }).catch(function(){
          Swal.update({ footer: '<small style="color:#ef4444;">Failed to load subtotal</small>' });
          Swal.hideLoading();
        });
      },
      preConfirm: function(){
        var pct = toNumber($(isDiscount ? '#swal_discount_pct' : '#swal_charge_pct').val());
        var fix = toNumber($(isDiscount ? '#swal_discount_fix' : '#swal_charge_fix').val());
        if (pct <= 0 && fix <= 0) { Swal.showValidationMessage('Isi minimal salah satu: % atau fixed'); return false; }
        if (pct < 0 || fix < 0) { Swal.showValidationMessage('Nilai tidak boleh negatif'); return false; }
        return {type, percent:pct, fixed:fix};
      }
    };
  }

let showDeny = false;
let denyText = undefined;

if (type === 'customer') {
  const el = document.getElementById('pos-current-customer');
  const curId = el ? el.getAttribute('data-customer-id') : '';
  showDeny = !!(curId && curId !== '0' && curId !== '');
  denyText = showDeny ? 'Remove' : undefined;
} else if (type === 'discount' || type === 'charge' || type === 'coupon') {
  showDeny = true;
  denyText = 'Remove';
}

Swal.fire({
  title: cfg.title,
  html: cfg.html,
  showCancelButton: true,
  confirmButtonText: 'Apply',
  showDenyButton: showDeny,
  denyButtonText: (type === 'customer' || type === 'discount' || type === 'charge' || type === 'coupon') ? 'Remove' : undefined,
  focusConfirm: false,
  willOpen: cfg.willOpen || null,
  didOpen: cfg.didOpen || null,
  preConfirm: cfg.preConfirm
}).then(function(result){
  if (result.isDenied && type === 'customer') {
    ajaxUnsetCustomer()
      .then(function(res){
        if (res && res.ok){
          if (typeof updateCheckoutPanel === 'function') updateCheckoutPanel();
          if (window.notyf) notyf.success('Customer unset to guest');
        } else {
          if (window.notyf) notyf.error((res && res.error) || 'Failed to unset customer');
        }
      })
      .catch(function(err){
        if (window.notyf) notyf.error((err && err.message) || 'Failed to unset customer');
      });
  } else if (result.isDenied && type === 'discount') {
    removeDiscount()
      .then(function(res){
        if (res && res.ok){
          if (typeof updateCheckoutPanel === 'function') updateCheckoutPanel();
          if (window.notyf) notyf.success('Discount removed');
        } else {
          if (window.notyf) notyf.error((res && res.error) || 'Failed to remove discount');
        }
      })
      .catch(function(err){
        if (window.notyf) notyf.error((err && err.message) || 'Failed to remove discount');
      });
  } else if (result.isDenied && type === 'charge') {
    removeCharge()
      .then(function(res){
        if (res && res.ok){
          if (typeof updateCheckoutPanel === 'function') updateCheckoutPanel();
          if (window.notyf) notyf.success('Charge removed');
        } else {
          if (window.notyf) notyf.error((res && res.error) || 'Failed to remove charge');
        }
      })
      .catch(function(err){
        if (window.notyf) notyf.error((err && err.message) || 'Failed to remove charge');
      });
  } else if (result.isDenied && type === 'coupon') {
    removeCoupon()
      .then(function(res){
        if (res && res.ok){
          if (typeof updateCheckoutPanel === 'function') updateCheckoutPanel();
          if (window.notyf) notyf.success('Coupon removed');
        } else {
          if (window.notyf) notyf.error((res && res.error) || 'Failed to remove coupon');
        }
      })
      .catch(function(err){
        if (window.notyf) notyf.error((err && err.message) || 'Failed to remove coupon');
      });
  } else if (result.isConfirmed) {
    if (result.value && result.value.type === 'discount'){
      Swal.showLoading();
      applyDiscount({percent: result.value.percent, fixed: result.value.fixed})
        .then(function(res){
          if (typeof onSubmit === 'function') onSubmit({ok:true, type:'discount', server:res});
          updateCheckoutPanel();
          if (window.notyf) notyf.success('Discount applied');
        })
        .catch(function(err){
          if (window.notyf) notyf.error((err && err.message) || 'Failed to apply discount');
        });
    } else if (result.value && result.value.type === 'charge'){
      Swal.showLoading();
      applyCharge({percent: result.value.percent, fixed: result.value.fixed})
        .then(function(res){
          if (typeof onSubmit === 'function') onSubmit({ok:true, type:'charge', server:res});
          updateCheckoutPanel();
          if (window.notyf) notyf.success('Charge applied');
        })
        .catch(function(err){
          if (window.notyf) notyf.error((err && err.message) || 'Failed to apply charge');
        });
    } else if (result.value && result.value.type === 'coupon'){
      applyCoupon({code: result.value.code})
        .then(function(res){
          if (res && res.ok){
            if (typeof onSubmit === 'function') onSubmit({ok:true, type:'coupon', code: result.value.code});
            if (typeof updateCheckoutPanel === 'function') updateCheckoutPanel();
            if (window.notyf) notyf.success('Coupon applied');
          } else {
            if (window.notyf) notyf.error((res && res.error) || 'Failed to apply coupon');
          }
        })
        .catch(function(err){
          if (window.notyf) notyf.error((err && err.message) || 'Failed to apply coupon');
        });
    } else {
      if (result.value && result.value.type === 'customer' && result.value.customer && result.value.customer.id){
        ajaxLoginCustomer({id: result.value.customer.id})
          .then(function(res){
            if (res && res.ok){
              if (typeof onSubmit === 'function') onSubmit({ok:true, type:'customer', customer: res.customer});
              if (window.notyf) notyf.success('Customer selected');
              if (typeof updateCheckoutPanel === 'function') updateCheckoutPanel();
            } else {
              if (window.notyf) notyf.error((res && res.error) || 'Failed to login customer');
            }
          })
          .catch(function(err){
            if (window.notyf) notyf.error((err && err.message) || 'Failed to login customer');
          });
      } else {
        if (typeof onSubmit === 'function') onSubmit(result.value);
      }
    }
  }
});

  function bindCustomerButtons(){
    $('#swal_add_customer').on('click', function(){
      openCustomerEditor('add').then(function(r){
        if (r.isConfirmed && r.value){
          ajaxLoadCustomers().then(function(){
            var html = buildCustomerHTML();
            Swal.update({html: html});
            bindCustomerButtons();
            bindCustomerAutocomplete();
            setTimeout(function(){
              $('#swal_customer_input').val(r.value.name);
              $('#swal_customer_id').val(r.value.id);
            }, 0);
          });
        }
      });
    });
    $('#swal_edit_customer').on('click', function(){
      var id = $('#swal_customer_id').val();
      var cur = null;
      if (id) {
        cur = CUSTOMER_LIST.find(function(c){ return String(c.id) === String(id); });
      } else {
        var name = ($('#swal_customer_input').val()||'').trim();
        cur = CUSTOMER_LIST.find(function(c){ return c.name.toLowerCase() === name.toLowerCase(); });
      }
      if (!cur) return;
      openCustomerEditor('edit', cur).then(function(r){
        if (r.isConfirmed && r.value){
          ajaxLoadCustomers().then(function(){
            var html = buildCustomerHTML();
            Swal.update({html: html});
            bindCustomerButtons();
            bindCustomerAutocomplete();
            setTimeout(function(){
              $('#swal_customer_input').val(r.value.name);
              $('#swal_customer_id').val(r.value.id);
            }, 0);
          });
        }
      });
    });
  }

  function bindCustomerAutocomplete() {
    var $inp = $('#swal_customer_input');
    var $id = $('#swal_customer_id');
    var $box = $('.swal-ac-list');

    function escapeHtml(str){ return $('<div>').text(str).html(); }
    function render(items){
      if (!items || !items.length){ $box.hide().empty(); return; }
      var html = items.slice(0, 20).map(function(c){
        return '<div class="swal-ac-item" data-id="'+c.id+'" data-name="'+escapeHtml(c.name)+'" style="padding:8px 10px;cursor:pointer;">'+
                 escapeHtml(c.name)+' <small style="color:#6b7280">#'+c.id+'</small>'+
               '</div>';
      }).join('');
      $box.html(html).show();
    }

    var run = debounce(function(){
      var q = ($inp.val()||'').toLowerCase().trim();
      $id.val('');
      if (!q){ $box.hide().empty(); return; }
      var items = CUSTOMER_LIST.filter(function(c){ return c.name.toLowerCase().indexOf(q) !== -1 || String(c.id).indexOf(q) !== -1; });
      render(items);
    }, 120);

    $inp.on('input focus', run);
    $(document).off('click.swal_ac').on('click.swal_ac', function(e){ if ($(e.target).closest('.swal-ac-list, #swal_customer_input').length === 0){ $box.hide(); } });
    $box.on('click', '.swal-ac-item', function(){
      var cid = $(this).data('id');
      var cname = $(this).data('name');
      $inp.val(cname);
      $id.val(cid);
      $box.hide();
    });
  }
}

/* =========================
   BUTTON BINDINGS
   ========================= */
$(document).on('click', '.mini-btn[data-action="customer"]', function(){
  openSwal('customer', function(payload){
    console.log('Selected customer:', payload);
  });
});
$(document).on('click', '.mini-btn[data-action="discount"]', function(){
  openSwal('discount', function(payload){
    console.log('Discount applied:', payload);
  });
});
$(document).on('click', '.mini-btn[data-action="charge"]', function(){
  openSwal('charge', function(payload){
    console.log('Charge applied:', payload);
  });
});
$(document).on('click', '.mini-btn[data-action="coupon"]', function(){
  openSwal('coupon', function(payload){
    console.log('Coupon applied:', payload);
  });
});

// ========================
// BARCODE SCANNER HANDLER
// ========================

let barcodeBuffer = "";
let barcodeTimer = null;

// Fokuskan ke input agar scanner bisa langsung mengetik
$(document).on('keydown', function (e) {
  // Abaikan jika sedang input manual di form
  if ($(e.target).is('input, textarea')) return;

  // Jika waktu antar karakter lebih dari 300ms, reset buffer
  if (barcodeTimer) clearTimeout(barcodeTimer);
  barcodeTimer = setTimeout(() => (barcodeBuffer = ""), 300);

  // Enter = akhir barcode
  if (e.key === 'Enter' && barcodeBuffer.length > 0) {
    let code = barcodeBuffer.trim();
    barcodeBuffer = "";
    handleBarcodeScan(code);
    return;
  }

  // Simpan karakter (angka/huruf saja)
  if (e.key.length === 1) barcodeBuffer += e.key;
});

function handleBarcodeScan(code) {
  console.log("Barcode scanned:", code);

  // Panggil endpoint untuk cari product berdasarkan model
  $.ajax({
    url: 'index.php?route=bpos/product/getByModel&model=' + encodeURIComponent(code),
    dataType: 'json',
    success: function (json) {
      if (json && json.product_id) {
        // Otomatis add to cart
        $.ajax({
          url: 'index.php?route=bpos/checkout/cart/add',
          type: 'post',
          data: { product_id: json.product_id, quantity: 1 },
          dataType: 'json',
          success: function (res) {
            if (res.success && window.notyf) {
              notyf.success(res.success);
            }
            updateCheckoutPanel();
            $('.btn--cart .counter').html(res.total_cart || 0);
          },
          error: function () {
            if (window.notyf) notyf.error('Failed to add product');
          }
        });
      } else {
        if (window.notyf) notyf.error('Product not found for barcode: ' + code);
      }
    },
    error: function () {
      if (window.notyf) notyf.error('Error checking barcode');
    }
  });
}

$(document).on('keydown', function(e) {
  // pastikan tidak aktif di dalam input/textarea lain
  const tag = (e.target.tagName || '').toLowerCase();
  if (tag === 'input' || tag === 'textarea') return;

  // Windows: Ctrl+K, Mac: Command+K
  const isMac = navigator.platform.toUpperCase().indexOf('MAC') >= 0;
  if ((isMac && e.metaKey && e.key.toLowerCase() === 'k') || (!isMac && e.ctrlKey && e.key.toLowerCase() === 'k')) {
    e.preventDefault();
    $('#barcode-input').focus();  // fokus ke input barcode
  }
});

// $('.filters.bslide').each(function(){
//   const track=$(this).find('.bslide-track');
//   track.css({
//     display:'flex',
//     overflowX:'auto',
//     scrollBehavior:'smooth',
//     gap:'8px'
//   });
//   // opsional: auto hide scrollbar
//   track.on('wheel', function(e){
//     e.preventDefault();
//     this.scrollLeft += e.originalEvent.deltaY;
//   });
// });

$(function(){
  const $wrap = $('.filters.bslide');
  const $view = $wrap.find('.bslide-viewport'); // <- scroll container
  const $track = $wrap.find('.bslide-track');
  const $prev = $wrap.find('.bslide-nav.prev');
  const $next = $wrap.find('.bslide-nav.next');

  // Wheel -> horizontal
  $view.on('wheel', function(e){
    e.preventDefault();
    this.scrollLeft += e.originalEvent.deltaY;
  });

  // Drag to scroll (mouse)
  let isDown = false, startX = 0, startLeft = 0;
  $view.on('mousedown', function(e){
    isDown = true;
    startX = e.pageX;
    startLeft = this.scrollLeft;
    $(this).addClass('dragging');
  });
  $(document).on('mousemove', function(e){
    if(!isDown) return;
    const dx = e.pageX - startX;
    $view[0].scrollLeft = startLeft - dx;
  }).on('mouseup mouseleave', function(){
    isDown = false;
    $view.removeClass('dragging');
  });

  // Step per klik panah: 3/4/6 item
  function itemsPerViewport(){
    const w = window.innerWidth;
    if (w >= 1200) return 6;   // desktop
    if (w >= 768)  return 4;   // tablet
    return 3;                  // mobile
  }
  function itemWidth(){
    const $first = $track.find('.btn').first();
    return $first.outerWidth(true) || 120;
  }
  function stepSize(){ return itemsPerViewport() * itemWidth(); }

  function scrollBy(dx){
    const target = $view.scrollLeft() + dx;
    $view.stop().animate({ scrollLeft: target }, 220);
  }

  $prev.on('click', function(e){ e.preventDefault(); scrollBy(-stepSize()); });
  $next.on('click', function(e){ e.preventDefault(); scrollBy( stepSize()); });
});