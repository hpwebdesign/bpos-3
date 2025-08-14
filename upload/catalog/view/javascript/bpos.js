$(document).ready(function() {
    $('.sidebar .bubble').on('click', function() {
        $('.sidebar .bubble').removeClass('active');
        $(this).addClass('active');

        let route = '';
        if ($(this).hasClass('home')) route = 'bpos/home';
        if ($(this).hasClass('invoices')) route = 'bpos/invoice';
        if ($(this).hasClass('orders')) route = 'bpos/order';
        if ($(this).hasClass('statistics')) route = 'bpos/statistic';

        if (route) loadContent(route);
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


        $("body").busyLoad("show", {
            spinner: "cube-grid",
            text: "Processing Order...",
            textPosition: "bottom",
            background: "rgba(255,255,255,0.7)",
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
                    //loadContent('bpos/invoice&order_id='+ json['order_id']);
                    window.location.href = 'index.php?route=bpos/order/info&order_id=' + json['order_id'];
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
                console.log(json.success);
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
                }
            });
        } else {
            $.ajax({
                url: 'index.php?route=bpos/home/loadProducts',
                type: 'get',
                dataType: 'html',
                success: function(html) {
                    $('#products-list').html(html);
                }
            });
        }
    });


    // Category
    $(document).on('click', '.categories .cat', function() {
        $('.categories .cat').removeClass('active');
        $(this).addClass('active');

        let category_id = $(this).data('id');
        $('#products-list').html('<div class="text-center py-5">Loading...</div>');

        $.ajax({
            url: 'index.php?route=bpos/home/products&category_id=' + category_id,
            type: 'get',
            dataType: 'json',
            success: function(json) {
                if (json['products']) {
                    let html = '';
                    json['products'].forEach(function(product) {
                        html += `
                        <div class="flex-item">
                            <div class="card h-100 p-3 product-item" data-id="${product.product_id}">
                                <div class="food">
                                    <img src="${product.thumb}" alt="${product.name}" title="${product.name}" class="plate img-responsive">
                                </div>
                                <div class="name">${product.name}</div>
                                <div class="price">${product.price}</div>
                            </div>
                        </div>`;
                    });
                    $('#products-list').html(html);
                } else {
                    $('#products-list').html('<div class="text-center py-5">No products found</div>');
                }
            },
            error: function() {
                $('#products-list').html('<div class="text-center py-5">Error loading products</div>');
            }
        });
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
        $.ajax({
            url: 'index.php?route=bpos/cart/clear',
            type: 'post',
            dataType: 'json',
            success: function(json) {
                updateCheckoutPanel();
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
                        url: 'index.php?route=checkout/cart/add',
                        type: 'post',
                        data: { product_id: product_id, quantity: 1 },
                        dataType: 'json',
                        success: function(json) {
                            updateCheckoutPanel();
                        }
                    });
                }
            }
        });
    });
    $(document).on('click', '#btn-add-with-options', function() {
        let form_data = $('#form-product-options').serialize();
        $.ajax({
            url: 'index.php?route=checkout/cart/add',
            type: 'post',
            data: form_data + '&quantity=1',
            dataType: 'json',
            success: function(json) {
                $('#productOptionModal').modal('hide');
                updateCheckoutPanel();
            }
        });
    });



        // Fungsi Add to Cart
        function posAddToCart(product_id, quantity = 1) {
            $.ajax({
                url: 'index.php?route=checkout/cart/add',
                type: 'post',
                data: { product_id: product_id, quantity: quantity },
                dataType: 'json',
                success: function(json) {
                    if (json['error']) {
                        alert(json['error']['warning'] || 'Error adding product to cart');
                    }
                    if (json['success']) {
                        //alert(json['success']); // Bisa ganti pakai notifikasi lebih bagus
                        updateCheckoutPanel();
                    }
                },
                error: function() {
                    alert('Error: Could not add to cart');
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


    function loadContent(route) {
        $('#main-content').html('<p>Loading...</p>');
        $.ajax({
            url: 'index.php?route=' + route + '&format=json',
            type: 'get',
            dataType: 'json',
            success: function(json) {
                if (json['output']) $('#main-content').html(json['output']);
            },
            error: function() {
                $('#main-content').html('<p>Error loading content</p>');
            }
        });
    }

    
});