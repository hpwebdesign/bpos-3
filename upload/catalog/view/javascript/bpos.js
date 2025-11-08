if (typeof window !== 'undefined' && typeof window.Notyf !== 'undefined') {
  window.notyf = window.notyf || new Notyf({ duration: 1000, position: { x: 'right', y: 'top' } });
}
let currentCarouselPage = 0;
let CUSTOMER_GROUPS = [];
$(document).ready(function() {

    initCategoryCarousel();
    loadCustomerGroups();
      populateCustomerGroups();
    $('footer .tab').on('click', function() {
        $('footer .tab').removeClass('active');
        $(this).addClass('active');

        let route = '';
        if ($(this).hasClass('home')) route = 'bpos/home';
        if ($(this).hasClass('invoices')) route = 'bpos/invoice';
        if ($(this).hasClass('orders')) route = 'bpos/order';
        if ($(this).hasClass('customers')) route = 'bpos/customer';
        if ($(this).hasClass('settings')) route = 'bpos/setting';
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

     $(document).on('click','.fab-cart', function() {
         var target = $('#checkout-summary');
            var scrollBottom = target.offset().top + target.outerHeight() - $(window).height();

            $('html, body').animate({
                scrollTop: scrollBottom
            }, 500);

            target.attr('tabindex', -1).focus();
    });

    $(document).on('click','#btn-logout', function() {
        $('#logoutModal').modal('show');
    });

    $(document).on('click', '.print', function(e) {
        e.preventDefault();

        let activePayment = $('.paymentbtn.active').data('code');

        $('#paymentConfirmModal').modal('hide');
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

                if (json.success && json.gateway) {
                    showPaymentConfirmModal(json.confirm_html);
                    return;
                }

                if (json.success) {
                    loadContent('bpos/checkout/order_confirm&order_id='+ json['order_id']);
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
                    $('#catalogGrid').html(html);
                    $('#products_paginate').hide();
                }
            });
        } else {
            loadPage('index.php?route=bpos/home');

        }
    });

    $(document).on('click', '.filters .btn', function (e) {
        e.preventDefault();
       const $btn = $(this);
       const category_id = $btn.data('id');

       $('.filters .btn').removeClass('is-active');
       $btn.addClass('is-active');
       const $owl = $('#category-carousel');
      if ($owl.data('owl.carousel')) {
        currentCarouselPage = $owl.data('owl.carousel').relative($owl.find('.owl-item.active').first().index());
      }
        loadPage('index.php?route=bpos/home&category_id='+category_id);
    });



    $(document).on('click', '.qty-plus', function() {
        let key = $(this).data('key');
        let qty = $(this).data('qty');
        let prev = parseInt($(this).closest('.qty').find('.qty-input').val(), 10) || 0;
        updateCartQty(key, qty, prev);
    });

    $(document).on('click', '.qty-minus', function() {
        let key = $(this).data('key');
        let qty = $(this).data('qty');
        let prev = parseInt($(this).closest('.qty').find('.qty-input').val(), 10) || 0;
        updateCartQty(key, qty, prev);
    });

    $(document).on('input', '.qty-input', function() {
        let $input = $(this);
        let key = $input.data('key');
        let qty = $input.val();
        let prev = qty;

        updateCartQty(key, qty, prev);

        setTimeout(function() {
        let $target = $(`.qty-input[data-key="${key}"]`);
        $target.focus();

        let val = $target.val();
        let el = $target.get(0);
        if (el && typeof el.setSelectionRange === 'function') {
            el.setSelectionRange(val.length, val.length); 
        }
    }, 400); 
    });

    function showPaymentConfirmModal(html) {
        $('#paymentConfirmModal').remove();

        $('body').append(`
            <div class="modal fade" id="paymentConfirmModal" tabindex="-1" role="dialog" aria-labelledby="paymentConfirmModalLabel" aria-hidden="true">
              <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                  <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="paymentConfirmModalLabel"><i class="bi bi-credit-card"></i> Payment Confirmation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <div class="modal-body p-3">${html}</div>
                </div>
              </div>
            </div>
        `);

        $('#paymentConfirmModal').modal('show');
    }

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

    $(document).on('click', '.product-item', function() {
        let product_id = $(this).data('id');

        $.ajax({
            url: 'index.php?route=bpos/product/checkOptions&product_id=' + product_id,
            dataType: 'json',
            success: function(json) {
                if (json.has_option) {
                    $.ajax({
                        url: 'index.php?route=bpos/product/options&product_id=' + product_id,
                        dataType: 'html',
                        success: function(html) {
                            $('#productOptionModal .modal-content').html(html);
                            $('#productOptionModal').modal('show');
                        }
                    });
                } else {
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
     
                    updateCheckoutPanel();
                    var target = $('#checkout-summary');
                    var scrollBottom = target.offset().top + target.outerHeight() - $(window).height();

                    $('html, body').animate({
                        scrollTop: scrollBottom
                    }, 500);

                
                    target.attr('tabindex', -1).focus();

                }
            },
            error: function() {
                if (window.notyf) { notyf.error('Error: Could not add to cart'); }
              
            }
        });
    }



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
                    if (route == 'bpos/home') {
                         initCategoryCarousel();
                    }
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
                 initCategoryCarousel();
             
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

function initCategoryCarousel() {
  const $carousel = $('#category-carousel.filters');

  if ($carousel.length) {
    if ($carousel.hasClass('owl-loaded')) {
      $carousel.trigger('destroy.owl.carousel');
      $carousel.find('.owl-stage-outer').children().unwrap();
    }

    $carousel.owlCarousel({
      loop: false,
      margin: 4,
      nav: false,
      dots: true,
      autoWidth: false,  
      smartSpeed: 400,
      responsive: {
        0:    { items: 2 },
        600:  { items: 3 },
        992:  { items: 4 },  
        1400: { items: 4 }
      }
    });

    $('.owl-prev').off('click').on('click', () => $carousel.trigger('prev.owl.carousel'));
    $('.owl-next').off('click').on('click', () => $carousel.trigger('next.owl.carousel'));
     if (currentCarouselPage > 0) {
         $carousel.trigger('to.owl.carousel', [currentCarouselPage, 0, true]);
    }
  }
}

function initCustomer() {
     $.getJSON('index.php?route=bpos/customer/customer_list', res=>{
    if(res.data){
      state.data = res.data.map(c=>({
        id:c.customer_id,
        name:c.name,
        phone:c.telephone,
        email:c.email,
        address:c.address,
        tier:c.tier,
        customer_group_id:c.customer_group_id,
        orders:c.orders,
        last:c.last,
        spent:c.spent,
        joined:c.joined,
        notes:c.notes||''
      }));
      apply();
    }
  });
}

function loadCustomerGroups() {
  return $.getJSON('index.php?route=bpos/customer/customer_group')
    .then(res => {
      if (res && res.ok && Array.isArray(res.groups)) {
        CUSTOMER_GROUPS = res.groups;
      }
    })
    .catch(err => console.error('Customer group error:', err));
}

function populateCustomerGroups(){
  const $sel = $('#filterGroup');
  if (!$sel.length) {
    // elemen belum ada, jangan ngapa-ngapain
    return;
  }

  $sel.empty().append('<option value="">All Groups</option>');
  CUSTOMER_GROUPS.forEach(g=>{
    $sel.append(`<option value="${g.id}">${g.name}</option>`);
  });
}

const seeded = [];

const state = { q:'', sort:'name', group_id:'', page:0, perPage:10, data:[...seeded] };

const money = n => 'Rp ' + n.toLocaleString('id-ID');
const daysAgo = (d)=> (Date.now()-new Date(d).getTime())/864e5;

function tierBadge(t){
  const cls = t==='VIP'?'badge vip':(t==='Gold'?'badge gold':'badge');
  return `<span class="${cls}">🏷️ ${t}</span>`;
}

function renderRows(list){
  const $rows = $('#rows');
  const html = list.map(c=>`
    <tr>
      <td data-label="Customer">
        <div class="name">
          <div class="avatar" aria-hidden="true">${c.name.split(' ').map(s=>s[0]).slice(0,2).join('')}</div>
          <div>
            <div style="font-weight:600">${c.name} ${tierBadge(c.tier)}</div>
            <div class="muted">Joined ${c.joined}</div>
          </div>
        </div>
      </td>
      <td data-label="Contact">
        <div>${c.phone}</div>
        <div class="muted">${c.email}</div>
        <div class="muted">${c.address||''}</div>
      </td>
      <td data-label="Orders">${c.orders}</td>
      <td data-label="Last Purchase">${c.last}</td>
      <td data-label="Total Spent" class="money">${money(c.spent)}</td>
      <td data-label="Action">
        <div class="actions">
          <button class="btn btn-sm ghost btn-view" data-id="${c.id}">View</button>
          <button class="btn btn-sm ghost btn-edit" data-id="${c.id}">Edit</button>
        </div>
      </td>
    </tr>
  `).join('');
  $rows.html(html);
}

function computeStats(list){
  $('#stat-total').text(list.length);
  $('#stat-new').text(list.filter(c=> daysAgo(c.joined) <= 7).length);
  const top = list.slice().sort((a,b)=>b.spent-a.spent)[0];
  $('#stat-top').text(top? top.name : '—');
}

function apply(){
  const q = state.q.trim().toLowerCase();
  let list = state.data.filter(c => (
      !state.group_id || String(c.customer_group_id) === String(state.group_id)
    ) && (
      !q || [c.name,c.phone,c.email,(c.address||'')].some(v=>String(v).toLowerCase().includes(q))
    ));

  switch(state.sort){
    case 'latest': list.sort((a,b)=> new Date(b.last)-new Date(a.last)); break;
    case 'orders': list.sort((a,b)=> b.orders-a.orders); break;
    case 'spent': list.sort((a,b)=> b.spent-a.spent); break;
    default: list.sort((a,b)=> a.name.localeCompare(b.name));
  }

  const start = state.page*state.perPage;
  const slice = list.slice(start, start+state.perPage);

  renderRows(slice);
  $('#showing').text(slice.length);
  $('#total').text(list.length);
  computeStats(list);

  $('#prev').prop('disabled', state.page===0);
  $('#next').prop('disabled', start+state.perPage>=list.length);
  renderPager(list.length);
}

function renderPager(totalCount){
  const pageCount = Math.ceil(totalCount/state.perPage) || 1;
  const $nums = $('#pageNumbers').empty();
  const windowSize = 2;
  let start = Math.max(0, state.page - windowSize);
  let end = Math.min(pageCount - 1, state.page + windowSize);
  if (state.page < windowSize) end = Math.min(pageCount-1, windowSize*2);
  if (state.page > pageCount-1-windowSize) start = Math.max(0, pageCount-1-windowSize*2);

  function addBtn(i){
    const $b = $('<button/>', {class:'page-btn', text: i+1});
    if(i===state.page) $b.addClass('active');
    $b.on('click', function(){ state.page=i; apply(); });
    $nums.append($b);
  }
  if(start>0){ addBtn(0); if(start>1) $nums.append($('<span/>',{text:'…',class:'muted'})); }
  for(let i=start;i<=end;i++) addBtn(i);
  if(end<pageCount-1){ if(end<pageCount-2) $nums.append($('<span/>',{text:'…',class:'muted'})); addBtn(pageCount-1); }
}

function openDetail(id) {
  const $drawer = $('#drawer');
  $drawer.addClass('open').attr('aria-hidden', 'false');

  $('#detail').html('<div class="loading-ajax">Loading customer...</div>');

  $.ajax({
    url: 'index.php?route=bpos/customer/getCustomerHtml&id=' + id,
    type: 'GET',
    dataType: 'html',
    success: function(html) {
      $('#detail').html(html);

      bindCustomerLocationHandlers();
      if (id == 0) {
        $('#deleteBtn').hide();
      } else {
        $('#deleteBtn').show();
      }
    },
    error: function(xhr) {
      console.error(xhr);
      $('#detail').html('<div class="loading-ajax">Failed to load customer detail.</div>');
    }
  });

  $('#saveBtn').data('id', id);
  $('#deleteBtn').data('id', id);
}

function bindCustomerLocationHandlers() {
  $(document).off('change.bpos_country').on('change.bpos_country', '#f_country_id', function() {
    var countryId = $(this).val() || '';
    var $zone = $('#f_zone_id');

    $zone.html('<option value="">Loading...</option>');

    if (!countryId) {
      $zone.html('<option value="">--- Please Select ---</option>');
      return;
    }

    $.ajax({
      url: 'index.php?route=bpos/customer/zone&country_id=' + countryId,
      type: 'GET',
      dataType: 'json',
      success: function(res) {
        if (!res || !res.ok) {
          $zone.html('<option value="">--- Please Select ---</option>');
          return;
        }

        var currentZoneId = $zone.attr('data-current-zone') || '';
        var opts = '<option value="">--- Please Select ---</option>';

        if (res.zones && res.zones.length) {
          for (var i=0; i<res.zones.length; i++) {
            var z = res.zones[i];
            opts += '<option value="'+ z.zone_id +'"'
              + (String(currentZoneId) === String(z.zone_id) ? ' selected' : '')
              + '>'+ z.name +'</option>';
          }
        } else {
          opts += '<option value="0" selected="selected"> --- None --- </option>';
        }

        $zone.html(opts);
      },
      error: function(xhr) {
        console.error(xhr);
        $zone.html('<option value="">--- Please Select ---</option>');
      }
    });
  });

  $('#f_country_id').trigger('change.bpos_country');
}


function renderCustomerDetail(c) {
  const html = `
    <div class="kv"><div class="muted">Name</div><div>
      <input id="f_name" value="${c.name || ''}" class="inp">
    </div></div>

    <div class="kv"><div class="muted">Customer Group</div><div class="chips">
      ${
        CUSTOMER_GROUPS.map(g => `
          <label class="badge ${g.name.toLowerCase().includes('vip') ? 'vip' : g.name.toLowerCase().includes('gold') ? 'gold' : ''}">
            <input type="radio" name="tier" value="${g.id}" ${Number(c.customer_group_id) === Number(g.id) ? 'checked' : ''}>
            ${g.name}
          </label>
        `).join('')
      }
    </div></div>

    <div class="kv"><div class="muted">Phone</div><div>
      <input id="f_phone" value="${c.phone || ''}" class="inp">
    </div></div>

    <div class="kv"><div class="muted">Email</div><div>
      <input id="f_email" value="${c.email || ''}" class="inp">
    </div></div>

    <div class="kv"><div class="muted">Address</div><div>
      <input id="f_address" value="${c.address || ''}" class="inp">
    </div></div>

    <div class="kv"><div class="muted">Joined</div><div>${c.joined || '-'}</div></div>
    <div class="kv"><div class="muted">Orders</div><div>${c.orders || 0}</div></div>
    <div class="kv"><div class="muted">Total Spent</div><div class="money">${c.spent}</div></div>

    <div>
      <div class="muted" style="margin-bottom:6px">Notes</div>
      <textarea id="f_notes" rows="2" class="inp">${c.notes || ''}</textarea>
    </div>
    <div class="orders">
    <div class="muted" style="margin-bottom:6px">Recent Orders</div>
      ${
        (c.recent_orders && c.recent_orders.length)
        ? `<ul style="margin:0 0 0 16px;padding:0">
            ${c.recent_orders.map(o => `
              <li>
                <strong>${o.invoice}</strong> • ${o.date} • ${o.total}
              </li>`).join('')}
          </ul>`
        : `<div class="muted">No recent orders</div>`
      }
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
      <button class="btn btn-sm btn-success ghost" id="actStartOrder" data-id="${c.id}">Start Order</button>
      <button class="btn btn-sm ghost" id="actSendReceipt" data-id="${c.id}">Send Receipt</button>
      <button class="btn btn-sm btn-primary ghost" id="actAddPoints" data-id="${c.id}">Save</button>
    </div>
  `;
  $('#detail').html(html);
}

function closeDrawer(){ $('#drawer').removeClass('open').attr('aria-hidden','true'); }

function toast(msg, type='success'){
  if(!window.notyf){ window.notyf = new Notyf({ duration: 1800, position:{x:'right', y:'bottom'} }); }
  if(type==='error') window.notyf.error(msg); else window.notyf.success(msg);
}

function saveCustomer(id) {
  const name    = $('#f_name').val().trim();
  const phone   = $('#f_phone').val().trim();
  if (!name || !phone) {
    toast('Name and Telephone are required', 'error');
    return;
  }

  const email       = $('#f_email').val().trim();
  const address     = $('#f_address').val().trim();
  const city        = $('#f_city').val().trim();
  const country_id  = $('#f_country_id').val();
  const zone_id     = $('#f_zone_id').val();
  const notes       = $('#f_notes').val().trim();
  const tier        = $('input[name="tier"]:checked').val();

  const payload = {
    id: id,
    name: name,
    phone: phone,
    email: email,
    address: address,
    city: city,
    country_id: country_id,
    zone_id: zone_id,
    note: notes,
    customer_group_id: tier
  };

  const doAfterLogin = (customerId) => {
    const p = ajaxLoginCustomer({ id: customerId });

    if (p && typeof p.then === 'function') {
      p.then(res => {
        if (res && res.ok) {
          if (typeof updateCheckoutPanel === 'function') updateCheckoutPanel();
          toast('Customer applied to current POS session');
        } else {
          toast((res && res.error) || 'Failed to apply customer', 'error');
        }
      })
      .catch(err => {
        toast((err && err.message) || 'Failed to apply customer', 'error');
      })
      .always(() => {
        initCustomer()
        apply();
        closeDrawer();
      });
    } else {
      initCustomer();
      apply();
      closeDrawer();
    }
  };

  if (id > 0) {
    // EDIT
    $.ajax({
      url: 'index.php?route=bpos/customer/edit',
      type: 'POST',
      data: payload,
      dataType: 'json',
      success: function(res) {
        if (res && res.ok) {
          toast('Customer updated successfully');
          doAfterLogin(res.id || id);
        } else {
          toast((res && res.error) || 'Failed to update customer', 'error');
        }
      },
      error: function(xhr) {
        console.error(xhr);
        toast('Network or server error while updating customer', 'error');
      }
    });

  } else {
    // CREATE
    $.ajax({
      url: 'index.php?route=bpos/customer/create',
      type: 'POST',
      data: payload,
      dataType: 'json',
      success: function(res) {
        if (res && res.ok && res.id) {
          toast('Customer created successfully');
          doAfterLogin(res.id);
        } else {
          toast((res && res.error) || 'Failed to create customer', 'error');
        }
      },
      error: function(xhr) {
        console.error(xhr);
        toast('Network or server error while creating customer', 'error');
      }
    });
  }
}


function removeCustomer(id) {
  Swal.fire({
    title: 'Delete this customer?',
    text: 'This action cannot be undone.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#d33',
    cancelButtonColor: '#999',
    confirmButtonText: 'Yes, delete it'
  }).then((result) => {
    if (!result.isConfirmed) return;

    $.ajax({
      url: 'index.php?route=bpos/customer/delete',
      type: 'POST',
      data: { id: id },
      dataType: 'json',
      success: function(res) {
        if (res && res.ok) {
          state.data = state.data.filter(x => x.id !== id);
          toast('Customer deleted successfully');
          closeDrawer();
          initCustomer();
           apply();
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
        } else {
          toast((res && res.error) || 'Failed to delete customer', 'error');
        }
      },
      error: function(xhr) {
        console.error(xhr);
        toast('Network or server error while deleting customer', 'error');
      }
    });
  });
}

$(document).on('click','#actStartOrder', function(){ saveCustomer($(this).data('id')); });
$(document).on('click','#actSendReceipt', function(){ toast('Receipt sent to email'); });
$(document).on('click','#actAddPoints', function(){ saveCustomer($(this).data('id'));});

// events (jQuery)

  // search/sort/filter
  $(document).on('input', '#q', function () {
  state.q = $(this).val();
  state.page = 0;
  apply();
});

$(document).on('change', '#sort', function () {
  state.sort = $(this).val();
  state.page = 0;
  apply();
});

$(document).on('change', '#filterGroup', function () {
  state.group_id = $(this).val();
  state.page = 0;
  apply();
});

  $('#filterVip').on('click', function(){ state.vipOnly=!state.vipOnly; $(this).toggleClass('ghost').toggleClass('btn'); state.page=0; apply(); });

  // pager
  $(document).on('click', '#prev', function(){
      if (state.page > 0) {
        state.page--;
        apply();
      }
    });

    $(document).on('click', '#next', function(){
      state.page++;
      apply();
    });

    // ADD CUSTOMER
    $(document).on('click', '#addBtn', function(){
      const id = Math.max(0, ...state.data.map(x=>x.id)) + 1;
      // const newItem = {
      //   id,
      //   name: '',
      //   phone: '',
      //   email: '',
      //   address: '',
      //   customer_group_id: '',
      //   orders: 0,
      //   last: new Date().toISOString().slice(0,10),
      //   spent: 0,
      //   joined: new Date().toISOString().slice(0,10),
      //   notes: ''
      // };
      // state.data.unshift(newItem);
      // state.page = 0;
      apply();
      openDetail(0);
      setTimeout(()=> $('#f_name').focus(), 50);
    });

  // global drawer actions
  $(document).on('click','[data-close]', closeDrawer);
  $('#saveBtn').on('click', function(){ saveCustomer($(this).data('id')); });
  $('#deleteBtn').on('click', function(){ removeCustomer($(this).data('id')); });

  // table action buttons (delegated)
  $(document).on('click','.btn-view, .btn-edit', function(){ openDetail(Number($(this).data('id'))); });

  // init
 

  apply();

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

function formatIDR(n) {
  var num = Number(n || 0);
  var el = document.getElementById('bpos-currency');

  if (!el) return num;

  var symbolLeft   = el.getAttribute('data-symbol-left') || '';
  var symbolRight  = el.getAttribute('data-symbol-right') || '';
  var decimalPlace = parseInt(el.getAttribute('data-decimal-place') || '0', 10);

  // Fungsi numberFormat manual (mirip PHP number_format)
  function numberFormat(number, decimals, decPoint, thousandsSep) {
    number = Number(number) || 0;
    decimals = isNaN(decimals) ? 0 : Math.abs(decimals);
    decPoint = decPoint || ',';
    thousandsSep = thousandsSep || '.';

    var fixedNum = number.toFixed(decimals);
    var parts = fixedNum.split('.');
    var integerPart = parts[0];
    var decimalPart = parts.length > 1 ? decPoint + parts[1] : '';

    // Tambah pemisah ribuan (.)
    integerPart = integerPart.replace(/\B(?=(\d{3})+(?!\d))/g, thousandsSep);

    return integerPart + decimalPart;
  }

  // Format sesuai pengaturan decimalPlace
  var formatted = numberFormat(num, decimalPlace, ',', '.');

  // Tambahkan simbol kiri dan kanan
  return symbolLeft + formatted + symbolRight;
}

function toNumber(v) {
  if (v === '' || v == null) return 0;
  v = String(v).replace(/[^\d.-]/g, ''); // bersihkan semua karakter non-angka
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
    '<div class="btn-group">'+
      '<button type="button" class="btn btn-sm btn-success" id="swal_add_customer">Add</button>'+

    '</div>'+
    '<div class="form-group">'+
      '<div>Current Customer: <strong>'+ $('<div>').text(cur).html() +'</strong></div>'+
    '</div>'+
    '<label class="label-customer">Change Customer</label>'+
    '<input type="text" id="swal_customer_input" class="form-control" placeholder="Type name to search" autocomplete="off" />'+
    '<input type="hidden" id="swal_customer_id" />'+
    '<div id="swal_customer_suggest">'+
      '<div class="swal-ac-list"></div>'+
    '</div>'+
  '</div>';
}
function buildDiscountHTML(){
  return ''+
  '<p class="swal-desc">You can fill in one or both.</p>'+
  '<div class="swal-form">'+
    '<div class="swal-input">'+
    '<div class="form-group">'+
      '<label>% Discount</label>'+
      '<input type="number" min="0" step="0.01" id="swal_discount_pct" class="form-control" placeholder="e.g. 10">'+
    '</div>'+
    '<div class="form-group">'+
      '<label>Fixed Discount</label>'+
      '<input type="number" min="0" step="1" id="swal_discount_fix" class="form-control" placeholder="e.g. 25000">'+
    '</div>'+
    '</div>'+
    '<div id="swal_discount_preview">'+
      '<div><strong>Subtotal:</strong> <span data-subtotal>-</span></div>'+
      '<div><strong>Discount %:</strong> <span data-from-pct>-</span></div>'+
      '<div><strong>Fixed Discount:</strong> <span data-from-fix>-</span></div>'+
      '<div><strong>Total diskon:</strong> <span data-total-disc>-</span></div>'+
      '<div><strong>New total:</strong> <span data-new-total>-</span></div>'+
    '</div>'+
    
  '</div>';
}
function buildChargeHTML(){
  return ''+
   '<p class="swal-desc">You can fill in one or both.</p>'+
  '<div class="swal-form">'+
   '<div class="swal-input">'+
    '<div class="form-group">'+
      '<label>Charge %</label>'+
      '<input type="number" min="0" step="0.01" id="swal_charge_pct" class="form-control" placeholder="e.g. 5">'+
    '</div>'+
    '<div class="form-group">'+
      '<label>Fixed Charge</label>'+
      '<input type="number" min="0" step="1" id="swal_charge_fix" class="form-control" placeholder="e.g. 5000">'+
    '</div>'+
    '</div>'+
    '<div id="swal_charge_preview">'+
      '<div><strong>Subtotal:</strong> <span data-subtotal>-</span></div>'+
      '<div><strong>Charge %:</strong> <span data-from-pct>-</span></div>'+
      '<div><strong>Fixed Charge:</strong> <span data-from-fix>-</span></div>'+
      '<div><strong>Total charge:</strong> <span data-total-charge>-</span></div>'+
      '<div><strong>New total:</strong> <span data-new-total>-</span></div>'+
    '</div>'+
   
  '</div>';
}

function buildCouponHTML(list){
  var items = (list||[]).map(function(c){
    var label = c.type === 'P' ? (c.discount + '%') : formatIDR(c.discount);
    var end = (c.date_end && c.date_end !== '0000-00-00') ? ('<small style="color:#6b7280">until '+c.date_end+'</small>') : '';
    return '\n      <div class="swal-coupon-item" data-code="'+c.code+'">\n        <div class="swal-coupon-content">\n          <div>\n            <div class="swal-coupon-name">'+c.name+' <span>('+c.code+')</span></div>\n            <div class="swal-coupon-label">'+label+' off '+end+'</div>\n          </div>\n          <div><span class="badge">Select</span></div>\n        </div>\n      </div>';
  }).join('');
  if (!items) items = '<div class="coupon-text-muted">No active coupons</div>';
  return ''+
    '<div class="swal-form">'+
    '<div class="swal-input">'+
      '<div class="form-group">'+
        '<label>Coupon code</label>'+
          '<input type="text" id="swal_coupon_code" class="form-control" placeholder="Enter coupon code" />'+
        '</div>'+
      '</div>'+
      '<div id="swal_coupon_list">'+items+'</div>'+
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
      html: '<div class="swal-ajax-loading">Loading...</div>',
      didOpen: function(){
        ajaxLoadCustomers().then(function(){

          const html = buildCustomerHTML();
            Swal.getHtmlContainer().innerHTML = html; 
          bindCustomerButtons();
          bindCustomerAutocomplete();
        }).catch(function(){
          Swal.getHtmlContainer().innerHTML = '<p class="swal-error">Failed to load customers</p>';
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
      html: '<div class="swal-ajax-loading">Loading...</div>',
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
            Swal.update({ html: '<p class="swal-error">Failed to load coupons</p>' });
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
          var subtotal = toNumber(summary && summary.subtotal || 0);
          console.log(subtotal);
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
            var fromPct = Math.round(subtotal * (pct/100));
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
          Swal.update({ footer: '<small class="swal-error">Failed to load subtotal</small>' });
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

function toNumberSafe(val) {
  return Number(String(val || '0').replace(/[^\d.-]/g, '')) || 0;
}

  function bindCustomerButtons(){
    $('#swal_add_customer').on('click', function(){
         if (typeof Swal !== 'undefined' && Swal.isVisible()) {
          Swal.close();
        }
       const id = 0;
        const newItem = {id, name:'', phone:'', email:'', address:'', tier:1, orders:0, last:new Date().toISOString().slice(0,10), spent:0, joined:new Date().toISOString().slice(0,10), notes:'',recent_orders:[]};
        state.data.unshift(newItem);
        state.page=0; apply(); openDetail(id);
        setTimeout(()=> $('#f_name').focus(), 50);
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
        return '<div class="swal-ac-item" data-id="'+c.id+'" data-name="'+escapeHtml(c.name)+'">'+
                 escapeHtml(c.name)+' <small>#'+c.id+'</small>'+
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
$(document).on('click', '.customer-btn[data-action="customer"]', function(){
    var id = $(this).data('customer-id');
    if (id > 0) {
        openDetail(id);
    } else {
      openSwal('customer', function(payload){
       // console.log('Selected customer:', payload);
      });
    }
});
$(document).on('click', '.mini-btn[data-action="customer"]', function(){
  openSwal('customer', function(payload){
   // console.log('Selected customer:', payload);
  });
});

$(document).on('click', '.mini-btn[data-action="discount"]', function(){
  openSwal('discount', function(payload){
  //  console.log('Discount applied:', payload);
  });
});
$(document).on('click', '.mini-btn[data-action="charge"]', function(){
  openSwal('charge', function(payload){
   // console.log('Charge applied:', payload);
  });
});
$(document).on('click', '.mini-btn[data-action="coupon"]', function(){
  openSwal('coupon', function(payload){
   // console.log('Coupon applied:', payload);
  });
});



// ========================
// BARCODE SCANNER HANDLER
// ========================

let barcodeBuffer = "";
let barcodeTimer = null;

$(document).on('keydown', function (e) {

  if ($(e.target).is('input, textarea')) return;

  if (barcodeTimer) clearTimeout(barcodeTimer);
  barcodeTimer = setTimeout(() => (barcodeBuffer = ""), 300);

  if (e.key === 'Enter' && barcodeBuffer.length > 0) {
    let code = barcodeBuffer.trim();
    barcodeBuffer = "";
    handleBarcodeScan(code);
    return;
  }

  if (e.key.length === 1) barcodeBuffer += e.key;
});
$(function () {
  const $icon = $('.search span');
  const $search = $('#pos-search');
  const $barcode = $('#barcode-input');

  $search.on('focus', function() {
    $icon.text('🔎');
  });

  $barcode.on('focus', function() {
    $icon.text('🏷️');
  });

  $search.add($barcode).on('blur', function() {
    setTimeout(function(){
      if (!$search.is(':focus') && !$barcode.is(':focus')) {
        $icon.text('🔎');
      }
    }, 50);
  });
});

function handleBarcodeScan(code) {

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

