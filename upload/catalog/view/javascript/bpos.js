$(document).ready(function() {
    $('.sidebar .bubble').on('click', function() {
        $('.sidebar .bubble').removeClass('active');
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

        let activePayment = $('.paybtn.active').data('code');


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
                    loadContent('bpos/order_confirm&order_id='+ json['order_id']);
                    //window.location.href = 'index.php?route=bpos/order_confirm&order_id=' + json['order_id'];
                }
            },
            error: function() {
                $("body").busyLoad("hide");
                alert('Order failed. Please try again.');
            }
        });
    });


    $(document).on('click', '.paybtn', function() {
        let code = $(this).data('code');

        $('.paybtn').removeClass('active');
        $(this).addClass('active');

        $.ajax({
            url: 'index.php?route=bpos/checkout/setPayment',
            type: 'post',
            data: { code: code },
            dataType: 'json',
            success: function(json) {
                toastr.success(json['success'], '', { timeOut: 1000 });
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
            url: 'index.php?route=bpos/checkout/setShipping',
            type: 'post',
            data: { code: code },
            dataType: 'json',
            success: function(json) {
                toastr.success(json['success'], '', { timeOut: 1000 });
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
    $(document).on('click', '.categories .cat', function (e) {
        e.preventDefault();
        $('.categories .cat').removeClass('active');
        $(this).addClass('active');

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
        updateCartQty(key, qty);
    });

    $(document).on('click', '.qty-minus', function() {
        let key = $(this).data('key');
        let qty = $(this).data('qty');
        updateCartQty(key, qty);
    });

    function updateCartQty(key, qty) {
        $.ajax({
            url: 'index.php?route=bpos/cart/edit',
            type: 'post',
            data: {
                key: key,
                quantity: qty,
                mode: 'delta'
            },
            dataType: 'json',
            success: function(json) {
                updateCheckoutPanel();
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
                url: 'index.php?route=bpos/cart/clear',
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
                        url: 'index.php?route=bpos/cart/add',
                        type: 'post',
                        data: { product_id: product_id, quantity: 1 },
                        dataType: 'json',
                        success: function(json) {
                            if (json['success']) {
                                toastr.success(json['success']);
                                $('.cart .counter').html(json['total_cart']);
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
            url: 'index.php?route=bpos/cart/add',
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
                    toastr.success(json['success']);
                    $('.cart .counter').html(json['total_cart']);
                    updateCheckoutPanel();
                }
            }
        });
    });



    // Fungsi Add to Cart
    function posAddToCart(product_id, quantity = 1) {
        $.ajax({
            url: 'index.php?route=bpos/cart/add',
            type: 'post',
            data: { product_id: product_id, quantity: quantity },
            dataType: 'json',
            success: function(json) {
                if (json['error']) {
                    toastr.error(json['error']['warning'] || 'Error adding product to cart');
                }
                if (json['success']) {
                    toastr.success(json['success']);
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
                toastr.error('Error: Could not add to cart');
                //alert('Error: Could not add to cart');
            }
        });
    }

    // Update checkout panel (kalau mau menampilkan isi keranjang di POS)
    function updateCheckoutPanel() {
        $.ajax({
            url: 'index.php?route=bpos/checkout&html=1',
            type: 'get',
            dataType: 'html',
            success: function(html) {
                $('#checkout-summary').replaceWith(html);
            }
        });
    }

});

function loadContent(route) {
        let busyTimer;

        $.ajax({
            url: 'index.php?route=' + route + '&format=json',
            type: 'get',
            dataType: 'json',
            beforeSend: function() {
                // set timer, if > 2 second show busyLoad
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
                // clear timer wathefer the result
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
  cart_summary:     'index.php?route=bpos/cart/summary',        // GET -> { subtotal: 125000 }
  apply_discount:   'index.php?route=bpos/cart/apply_discount', // POST {percent, fixed}
  apply_charge:     'index.php?route=bpos/cart/apply_charge'    // POST {percent, fixed}
};

/* =========================
   UTILITIES
   ========================= */
var CUSTOMER_LIST = []; // akan diisi dari AJAX

function formatIDR(n){
  var num = Number(n||0);
  return new Intl.NumberFormat('id-ID',{style:'currency',currency:'IDR',maximumFractionDigits:0}).format(num);
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
    // Expect res.customers = [{id:'C001', name:'Walk-in'}, ...]
    CUSTOMER_LIST = (res && res.customers) ? res.customers : [];
    return CUSTOMER_LIST;
  });
}
function ajaxCreateCustomer(payload){
  return $.post(API.customers_create, payload, null, 'json');
}
function ajaxEditCustomer(payload){
  return $.post(API.customers_edit, payload, null, 'json');
}
function fetchCartSummary(){
  return $.getJSON(API.cart_summary).then(function(res){
    return (res && typeof res.subtotal !== 'undefined') ? Number(res.subtotal) : 0;
  });
}
function applyDiscount(payload){ // {percent,fixed}
  return $.post(API.apply_discount, payload, null, 'json');
}
function applyCharge(payload){ // {percent,fixed}
  return $.post(API.apply_charge, payload, null, 'json');
}

/* =========================
   HTML BUILDERS
   ========================= */
function buildCustomerHTML(){
  var opts = CUSTOMER_LIST.map(function(c){return '<option value="'+c.id+'">'+c.name+'</option>';}).join('');
  return ''+
  '<div class="swal-form">'+
    '<label style="display:block;margin-bottom:6px;">Select customer</label>'+
    '<select id="swal_customer_select" class="form-control" style="margin-bottom:10px;">'+opts+'</select>'+
    '<div class="btn-group" style="width:100%;gap:6px;display:flex;">'+
      '<button type="button" class="btn btn-default" id="swal_add_customer" style="flex:1;">Add</button>'+
      '<button type="button" class="btn btn-default" id="swal_edit_customer" style="flex:1;">Edit</button>'+
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
      '<label>Fixed Discount (Rupiah)</label>'+
      '<input type="number" min="0" step="1" id="swal_discount_fix" class="form-control" placeholder="e.g. 25000">'+
    '</div>'+
    '<div id="swal_discount_preview" style="margin-top:8px;padding:8px;border:1px dashed #e5e7eb;border-radius:8px;font-size:12px;line-height:1.4;">'+
      '<div><strong>Subtotal:</strong> <span data-subtotal>-</span></div>'+
      '<div><strong>Dari %:</strong> <span data-from-pct>-</span></div>'+
      '<div><strong>Dari fixed:</strong> <span data-from-fix>-</span></div>'+
      '<div><strong>Total diskon:</strong> <span data-total-disc>-</span></div>'+
      '<div><strong>New total:</strong> <span data-new-total>-</span></div>'+
    '</div>'+
    '<p style="font-size:12px;color:#6b7280;margin-top:6px;">Boleh isi salah satu atau keduanya.</p>'+
  '</div>';
}
function buildChargeHTML(){
  return ''+
  '<div class="swal-form">'+
    '<div class="form-group" style="margin-bottom:10px;">'+
      '<label>% Charge</label>'+
      '<input type="number" min="0" step="0.01" id="swal_charge_pct" class="form-control" placeholder="e.g. 5">'+
    '</div>'+
    '<div class="form-group">'+
      '<label>Fixed Charge (Rupiah)</label>'+
      '<input type="number" min="0" step="1" id="swal_charge_fix" class="form-control" placeholder="e.g. 5000">'+
    '</div>'+
    '<div id="swal_charge_preview" style="margin-top:8px;padding:8px;border:1px dashed #e5e7eb;border-radius:8px;font-size:12px;line-height:1.4;">'+
      '<div><strong>Subtotal:</strong> <span data-subtotal>-</span></div>'+
      '<div><strong>Dari %:</strong> <span data-from-pct>-</span></div>'+
      '<div><strong>Dari fixed:</strong> <span data-from-fix>-</span></div>'+
      '<div><strong>Total charge:</strong> <span data-total-charge>-</span></div>'+
      '<div><strong>New total:</strong> <span data-new-total>-</span></div>'+
    '</div>'+
    '<p style="font-size:12px;color:#6b7280;margin-top:6px;">Boleh isi salah satu atau keduanya.</p>'+
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
          // harapkan res.customer: {id, name}
          if (!res || !res.customer) throw new Error('Create failed');
          return {mode:'add', id:res.customer.id, name:res.customer.name};
        }).catch(function(err){
          Swal.showValidationMessage(err.message || 'Create customer failed');
          return false;
        });
      } else {
        return ajaxEditCustomer({id:idVal, name:name}).then(function(res){
          // harapkan res.customer: {id, name}
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
      willOpen: function(){ Swal.showLoading(); },
      didOpen: function(){
        // Load customers via AJAX, rebuild HTML, then re-bind
        ajaxLoadCustomers().then(function(){
          Swal.update({ html: buildCustomerHTML(), didOpen: bindCustomerButtons });
        }).catch(function(){
          Swal.update({ html: '<p style="color:#ef4444;">Failed to load customers</p>' });
        });
      },
      preConfirm: function(){
        var id = $('#swal_customer_select').val();
        if (!id) { Swal.showValidationMessage('Please select a customer'); return false; }
        var found = CUSTOMER_LIST.find(function(c){return c.id === id;});
        return {type:'customer', customer: found || {id:id, name:'Unknown'}};
      }
    };
  }
  if (type === 'discount' || type === 'charge') {
    var isDiscount = (type === 'discount');
    cfg = {
      title: isDiscount ? 'Add Discount' : 'Add Charge',
      html: isDiscount ? buildDiscountHTML() : buildChargeHTML(),
      willOpen: function(){ Swal.showLoading(); },
      didOpen: function(){
        // Ambil subtotal lalu isi preview
        fetchCartSummary().then(function(subtotal){
          var wrap = isDiscount ? '#swal_discount_preview' : '#swal_charge_preview';
          var $wrap = $(wrap);
          $wrap.find('[data-subtotal]').text(formatIDR(subtotal));
          // bind input & calc
          var recalc = debounce(function(){
            var pct = toNumber($(isDiscount ? '#swal_discount_pct' : '#swal_charge_pct').val());
            var fix = toNumber($(isDiscount ? '#swal_discount_fix' : '#swal_charge_fix').val());
            var fromPct = Math.floor(subtotal * (pct/100));
            var fromFix = fix;
            var delta = fromPct + fromFix; // diskon: kurangi; charge: tambahkan
            var newTotal = isDiscount ? Math.max(0, subtotal - delta) : subtotal + delta;
            $wrap.find('[data-from-pct]').text(formatIDR(fromPct));
            $wrap.find('[data-from-fix]').text(formatIDR(fromFix));
            $wrap.find(isDiscount ? '[data-total-disc]' : '[data-total-charge]').text(formatIDR(delta));
            $wrap.find('[data-new-total]').text(formatIDR(newTotal));
          }, 120);
          $(document).on('input', isDiscount ? '#swal_discount_pct,#swal_discount_fix' : '#swal_charge_pct,#swal_charge_fix', recalc);
          recalc(); // initial
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

  Swal.fire({
    title: cfg.title,
    html: cfg.html,
    showCancelButton: true,
    confirmButtonText: 'Apply',
    focusConfirm: false,
    willOpen: cfg.willOpen || null,
    didOpen: cfg.didOpen || null,
    preConfirm: cfg.preConfirm
  }).then(function(result){
    if (result.isConfirmed){
      // Auto-apply ke server (untuk discount/charge)
      if (result.value && result.value.type === 'discount'){
        Swal.showLoading();
        applyDiscount({percent: result.value.percent, fixed: result.value.fixed})
          .then(function(res){
            if (typeof onSubmit === 'function') onSubmit({ok:true, type:'discount', server:res});
            Swal.fire('Applied','Discount applied','success');
          })
          .catch(function(err){
            Swal.fire('Error', (err && err.message) || 'Failed to apply discount', 'error');
          });
      } else if (result.value && result.value.type === 'charge'){
        Swal.showLoading();
        applyCharge({percent: result.value.percent, fixed: result.value.fixed})
          .then(function(res){
            if (typeof onSubmit === 'function') onSubmit({ok:true, type:'charge', server:res});
            Swal.fire('Applied','Charge applied','success');
          })
          .catch(function(err){
            Swal.fire('Error', (err && err.message) || 'Failed to apply charge', 'error');
          });
      } else {
        // customer
        if (typeof onSubmit === 'function') onSubmit(result.value);
      }
    }
  });

  // Helper bind untuk tombol add/edit customer
  function bindCustomerButtons(){
    $('#swal_add_customer').on('click', function(){
      openCustomerEditor('add').then(function(r){
        if (r.isConfirmed && r.value){
          // refresh list dari server supaya konsisten
          ajaxLoadCustomers().then(function(){
            // rebuild select
            var html = buildCustomerHTML();
            Swal.update({html: html, didOpen: bindCustomerButtons});
            // set value ke customer baru
            setTimeout(function(){
              $('#swal_customer_select').val(r.value.id);
            }, 0);
          });
        }
      });
    });
    $('#swal_edit_customer').on('click', function(){
      var id = $('#swal_customer_select').val();
      if (!id) return;
      var cur = CUSTOMER_LIST.find(function(c){return c.id === id;});
      openCustomerEditor('edit', cur).then(function(r){
        if (r.isConfirmed && r.value){
          // refresh list lalu pilih yang diedit
          ajaxLoadCustomers().then(function(){
            var html = buildCustomerHTML();
            Swal.update({html: html, didOpen: bindCustomerButtons});
            setTimeout(function(){ $('#swal_customer_select').val(r.value.id); }, 0);
          });
        }
      });
    });
  }
}

/* =========================
   BUTTON BINDINGS
   ========================= */
$(document).on('click', '.mini-btn[data-action="customer"]', function(){
  openSwal('customer', function(payload){
    // TODO: simpan pilihan customer ke cart state-mu
    console.log('Selected customer:', payload);
  });
});
$(document).on('click', '.mini-btn[data-action="discount"]', function(){
  openSwal('discount', function(payload){
    // response server tersedia di payload.server
    console.log('Discount applied:', payload);
  });
});
$(document).on('click', '.mini-btn[data-action="charge"]', function(){
  openSwal('charge', function(payload){
    console.log('Charge applied:', payload);
  });
});
