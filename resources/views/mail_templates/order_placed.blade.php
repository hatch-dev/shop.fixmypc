@php $totalQty = $order->ordered_products->sum('quantity'); @endphp
<!doctype html>
<html lang="en">
    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title>{{ $setting->store_name }} - Order</title>

        <link
            href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@400;500;600;700&display=swap"
            rel="stylesheet"
        />
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
        >

        <style>

            .card-content.right .address:before {
                content: "\f111";
                font-family: "Font Awesome 6 Free";
                font-weight: 900;
                display: inline-block;
                margin-right: 15px;
                color: #cbd5e1;
                font-size: 8px;
                position: relative;
                top: 8px;
            }



        </style>
    </head>

    <body style="margin: 0; font-family: 'Inter', sans-serif; background: #f8fafc; padding: 0 20px;">
        <div class="wrapper" style="max-width: 767px;
                margin: 40px auto;
                background: #ffffff;
                border-radius: 40px;
                box-shadow: 0px 10px 40px -5px #00000014;
                border: 1px solid #f1f5f9;">
            <div class="header_success-sc" style="padding: 40px;
                background:
                    url('{{ url('public/images/header_gradient-circle.png') }}') top right no-repeat,
                    linear-gradient(180deg, #f0f9ff 0%, #ffffff 100%);
                background-size:
                    auto,
                    100% 100%;
                border-radius: 40px 40px 0 0;">
                <div class="center" style="text-align: center;">
                    <div class="logo" style="display: inline-flex;
                background: #ffffffcc;
                padding: 10px;
                border-radius: 30px;
                font-weight: 700;
                margin-bottom: 20px;
                font-size: 20px;
                line-height: 1.2em;
                box-shadow: 0px 1px 2px 0px #0000000d;
                backdrop-filter: blur(4px);
                border: 1px solid #f1f5f9;
                height: 32px;
                min-width: 165px;
                align-items: center;
                justify-content: center;
                gap: 15px;"><img src="{{ $setting->logo }}" alt="FixMyPC" /> FixMyPC</div>
                    <div class="success-icon">
                        <img src="{{ asset('public/images/icon_success.png') }}" alt="Success" />
                    </div>
                    <h1 style="margin-top: 0;
                font-size: 48px;
                font-weight: 700;
                line-height: 1.15em;
                margin-bottom: 20px;">Woohoo!<br />Your Order is Confirmed.</h1>
                    <p class="subtitle" style="color: #64748b;
                font-weight: 400;
                line-height: 30px;
                font-size: 18px;
                max-width: 427px;
                margin: auto;">
                        Thanks for shopping with FixMyPC. We're getting your tech gear ready for shipment. It's going to
                        be awesome.
                    </p>
                </div>
            </div>

            <div class="cards-gear-sc" style="padding: 10px 40px !important;
                background-image: url('{{ url('public/images/cards_gradient-circle.png') }}');
                background-repeat: no-repeat;
                background-position: center left;">
                <div class="cards" style="display: flex;
                gap: 20px;
                flex-wrap: wrap;
                margin-bottom: 35px;">
                    <div class="card" style="flex: 1;
                min-width: 250px;
                background: #ffffff;
                padding: 25px;
                border-radius: 24px;
                border: 1px solid #f1f5f9;
                box-shadow: 0px 4px 20px -2px #0000000d;">
                        <div class="card-hdr" style="display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 20px;">
                            <img src="{{ asset('public/images/order_details-icon.png') }}" alt="Order Details" style="width: 40px;
                height: auto;" />
                            <h3 style="margin: 0;
                font-weight: 700;
                font-size: 18px;
                line-height: 1.1em;
                color: #1e293b;">Order Details</h3>
                        </div>
                        <div class="card-content left">
                            <p style="margin-top: 0;
                font-size: 16px;
                color: #1e293b;
                line-height: 24px;
                font-weight: 600;
                display: flex;
                gap: 5px;
                justify-content: space-between;
                margin-bottom: 22px;
                flex-wrap: wrap;"><strong style="color: #64748b;
                font-weight: 500;
                font-size: 15px;">Order Number:</strong> #{{ $order->order }}</p>
                            <p style="margin-top: 0;
                font-size: 16px;
                color: #1e293b;
                line-height: 24px;
                font-weight: 600;
                display: flex;
                gap: 5px;
                justify-content: space-between;
                margin-bottom: 22px;
                flex-wrap: wrap;"><strong style="color: #64748b;
                font-weight: 500;
                font-size: 15px;">Order Date:</strong> {{ $order->created_at->format('M d, Y') }}</p>
                            <!-- <p><strong>Payment:</strong> Visa **** 4242</p> -->
                        </div>
                    </div>

                    <div class="card" style="flex: 1;
                min-width: 250px;
                background: #ffffff;
                padding: 25px;
                border-radius: 24px;
                border: 1px solid #f1f5f9;
                box-shadow: 0px 4px 20px -2px #0000000d;">
                        <div class="card-hdr" style="display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 20px;">
                            <img src="{{ asset('public/images/shipping-icon.png') }}" alt="Shipping" style="width: 40px;
                height: auto;"/>
                            <h3 style="margin: 0;
                font-weight: 700;
                font-size: 18px;
                line-height: 1.1em;
                color: #1e293b;">Shipping To</h3>
                        </div>
                        <div class="card-content right">
                            <div class="address" style="display: flex;">
                                <p style="margin-top: 0;
                margin-bottom: 25px;
                display: flex;
                flex-direction: column;
                gap: 2px;
                color: #64748b;
                font-weight: 500;
                font-size: 15px;">
                                    <strong style="font-size: 16px;
                color: #1e293b !important;
                line-height: 24px;
                font-weight: 600;">{{ $order->address->name }}</strong> {{ $order->formatted_address }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="gear-section" style="border: 1px solid #f1f5f9;
                box-shadow: 0px 4px 20px -2px #0000000d;
                border-radius: 24px;">
                    <div class="gear-header" style="display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 0;
                background: #f8fafc80;
                padding: 25px;">
                        <h3 style="font-size: 18px;
                margin: 0;
                color: #1e293b;
                font-weight: 700;">Your Gear</h3>
                        <span class="item-count" style="background: #e0f2fe;
                color: #0284c7;
                padding: 6px 14px;
                border-radius: 20px;
                font-size: 12px;
                font-weight: 700;
                line-height: 16px;">{{ $totalQty }} Items</span>
                    </div>

                    <div class="gear-card" style="border-radius: 0 0 24px 24px;
                padding: 25px;
                background: #fff;
                border-top: 1px solid #f1f5f9;">
                        <!-- Items -->

                        @foreach ($order->ordered_products as $op)
                        <div class="gear-item" style="display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 30px;">
                            <div class="gear-left" style="display: flex;
                align-items: center;
                gap: 18px;">
                                <div class="product-icon cpu" style="position: relative;">
                                    <div class="product-img" style="width: 80px;
                height: 80px;
                border-radius: 16px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 20px;
                background: #fff;
                border: 1px solid #f1f5f9;
                box-shadow: 0px 1px 2px 0px #0000000d;">
                                        <img src="{{ asset('uploads') }}/{{ $op->product->image }}" alt="{{ $op->product->title }}" style="width: 80px; height: 80px" />
                                    </div>
                                    <div class="qty-badge" style="right: -6px;
                top: -6px;
                width: 20px;
                height: 20px;
                background: #111827;
                color: #fff;
                font-size: 11px;
                font-weight: 600;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                position: absolute;">{{ $op->quantity }}</div>
                                </div>
                                <div class="product-info">
                                    <h4 style="margin: 0;
                font-size: 18px;
                font-weight: 700;
                color: #1e293b;
                line-height: 29px;">{{ $op->product->title }}</h4>
                                    <p style="margin: 2px 0 0;
                font-size: 14px;
                color: #64748b;
                line-height: 20px;">{{ \App\Models\Helper\MailHelper::generatingAttribute($op) }}</p>
                                </div>
                            </div>
                            <div class="gear-price" style="font-weight: 700;
                font-size: 18px;
                color: #1e293b;
                line-height: 28px;">{{ $setting->currency_icon }}{{ number_format((float)$op->selling * $op->quantity, 2) }}</div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="summary-outer" style="padding: 25px 40px 40px 40px;">
                <div class="summary-section" style="padding: 25px;
                border-radius: 24px;
                color: #fff;
                background-image: url('{{ url('public/images/summary_top-circle.png') }}'), url('{{ url('public/images/summary_bottom-circle.png') }}'),
                    linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
                background-position:
                    top right,
                    bottom left,
                    center;
                background-repeat: no-repeat, no-repeat, no-repeat;
                background-size: auto, auto, cover;">
                    <div class="summary-header" style="display: flex;
                align-items: center;
                gap: 8px;
                margin-bottom: 30px;">
                        <img src="{{ asset('public/images/summary-icon.png') }}" alt="Summary" />
                        <h3 style="margin: 0;
                font-size: 20px;
                line-height: 1.2em;
                font-weight: 700;">Summary</h3>
                    </div>

                    <div class="summary-row" style="display: flex;
                justify-content: space-between;
                margin-bottom: 18px;
                font-size: 16px;
                gap: 10px;">
                        <span style="color: #cbd5e1;">Subtotal</span>
                        <p style="margin: 0;
                color: #fff;
                font-weight: 500;">{{ $setting->currency_icon }}{{ $order->calculated_price['subtotal'] }}</p>
                    </div>

                    <div class="summary-row" style="display: flex;
                justify-content: space-between;
                margin-bottom: 18px;
                font-size: 16px;
                gap: 10px;">
                        <span style="color: #cbd5e1;">Shipping</span>
                        <p  style="margin: 0;
                color: #fff;
                font-weight: 500;">
                            @if((float) $order->calculated_price['shipping_price'] > 0)
                                {{ $setting->currency_icon }}{{ $order->calculated_price['shipping_price'] }}
                            @else
                                {{__('lang.fre')}} 
                            @endif
                        </p>
                    </div>

                    @if ((int) $order->calculated_price['bundle_offer'] > 0)
                        <div class="summary-row" style="display: flex;
                justify-content: space-between;
                margin-bottom: 18px;
                font-size: 16px;
                gap: 10px;">
                            <span style="color: #cbd5e1;">Bundle offer</span>
                            <p style="margin: 0;
                color: #fff;
                font-weight: 500;">{{ $setting->currency_icon }}{{ $order->calculated_price['bundle_offer'] }}</p>
                        </div>
                    @endif

                    @if ((int) $order->calculated_price['tax'] > 0)
                        <div class="summary-row" style="display: flex;
                justify-content: space-between;
                margin-bottom: 18px;
                font-size: 16px;
                gap: 10px;">
                            <span style="color: #cbd5e1;">Tax</span>
                            <p style="margin: 0;
                color: #fff;
                font-weight: 500;">{{ $setting->currency_icon }}{{ $order->calculated_price['tax'] }}</p>
                        </div>
                    @endif

                    @if ((int) $order->calculated_price['voucher_price'] > 0)
                        <div class="summary-row discount" style="display: flex;
                justify-content: space-between;
                margin-bottom: 18px;
                font-size: 16px;
                gap: 10px;">
                            <span style="font-weight: 500 !important;
                color: #34d399 !important;">Discount</span>
                            <span style="font-weight: 500 !important;
                color: #34d399 !important;">- {{ $setting->currency_icon }}{{ $order->calculated_price['voucher_price'] }}</span>
                        </div>
                    @endif

                    <div class="summary-divider" style="height: 1px;
                background: rgba(255, 255, 255, 0.15);
                margin: 30px 0;"></div>

                    <div class="summary-total" style="display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 10px;
                flex-wrap: wrap;">
                        <div>
                            <p class="paid-text" style="margin: 0;
                font-size: 14px;
                font-weight: 400;
                color: #94a3b8;">Total Amount Paid</p>
                        </div>
                        <div class="total-amount" style="font-size: 35px;
                font-weight: 700;">{{ $setting->currency_icon }}{{ $order->calculated_price['total_price'] }}</div>
                    </div>
                </div>
            </div>

            <div class="track-btn" style="padding: 0 40px 40px 40px;
                text-align: center;">
                <a href="{{ url('track-order') }}" class="btn" style="display: inline-block;
                padding: 16px 40px;
                background: #0284c7;
                color: #fff;
                border-radius: 30px;
                text-decoration: none;
                font-weight: 700;
                box-shadow: 0px 20px 50px -10px #0ea5e940;
                font-size: 18px;
                line-height: 28px;
                transition: all 0.3s ease-in-out;">Track Your Order <i class="fa-solid fa-arrow-right" style="margin-left: 10px;"></i></a>
                <p style="font-weight: 400;
                font-size: 14px;
                color: #94a3b8;
                margin: 20px 0 0 0;">You'll receive another email when your order ships.</p>
            </div>

            <div class="deals-section" style="margin-top: 30px;
                text-align: center;
                padding: 0 40px 40px 40px;">
                <h3 class="deals-title" style="font-size: 16px;
                margin-bottom: 20px;
                margin-top: 0 !important;
                line-height: 24px;
                color: #1e293b;
                font-family: "Inter", sans-serif;">Get these amazing deals today!</h3>

                <div class="deals-grid" style="display: flex;
                gap: 20px;
                justify-content: center;">
                    <div class="deal-card" style="width: 100%;
                max-width: 350px;
                height: 90px;
                background: #e9e4e4;
                border-radius: 16px;
                border: 1px solid #f1f5f9;
                padding: 20px;"></div>
                    <div class="deal-card" style="width: 100%;
                max-width: 350px;
                height: 90px;
                background: #e9e4e4;
                border-radius: 16px;
                border: 1px solid #f1f5f9;
                padding: 20px;"></div>
                </div>
            </div>

            <div class="footer-section" style="padding: 50px 30px 70px 30px;
                text-align: center;
                background: #f1f5f9;
                border-top: 1px solid #f1f5f9;">
                <div class="footer-cards" style="display: flex;
                justify-content: space-between;
                gap: 15px;
                margin-bottom: 30px;">
                    <div class="footer-card" style="background: #ffffff;
                border-radius: 16px;
                padding: 24px;
                border: 1px solid #f1f5f9;
                max-width: 220px !important;
                width: 100%;">
                        <div class="footer-icon support" style="width: 40px;
                height: 40px;
                margin: 0 auto 10px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                color: #fff;
                font-size: 16px;">
                            <img src="{{ asset('public/images/help-icon.png') }}" alt="Help"  style="width: 40px;"/>
                        </div>
                        <h4 style="margin: 14px 0 2px;
                font-size: 14px;
                color: #1e293b;
                line-height: 1.2em;
                font-family: 'Inter', sans-serif;">Need Help?</h4>
                        <a href="#" style="font-size: 12px;
                color: #0284c7;
                text-decoration: none;
                font-weight: 500;
                line-height: 16px;
                transition: all 0.3s ease-in-out;">Contact Support</a>
                    </div>

                    <div class="footer-card" style="background: #ffffff;
                border-radius: 16px;
                padding: 24px;
                border: 1px solid #f1f5f9;
                max-width: 220px !important;
                width: 100%;">
                        <div class="footer-icon faq" style="width: 40px;
                height: 40px;
                margin: 0 auto 10px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                color: #fff;
                font-size: 16px;">
                            <img src="{{ asset('public/images/question-icon.png') }}" alt="Questions" style="width: 40px;"/>
                        </div>
                        <h4 style="margin: 14px 0 2px;
                font-size: 14px;
                color: #1e293b;
                line-height: 1.2em;
                font-family: 'Inter', sans-serif;">Questions?</h4>
                        <a href="#" style="font-size: 12px;
                color: #0284c7;
                text-decoration: none;
                font-weight: 500;
                line-height: 16px;
                transition: all 0.3s ease-in-out;">View FAQ</a>
                    </div>

                    <div class="footer-card" style="background: #ffffff;
                border-radius: 16px;
                padding: 24px;
                border: 1px solid #f1f5f9;
                max-width: 220px !important;
                width: 100%;">
                        <div class="footer-icon account" style="width: 40px;
                height: 40px;
                margin: 0 auto 10px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                color: #fff;
                font-size: 16px;">
                            <img src="{{ asset('public/images/account-icon.png') }}" alt="Account" style="width: 40px;"/>
                        </div>
                        <h4 style="margin: 14px 0 2px;
                font-size: 14px;
                color: #1e293b;
                line-height: 1.2em;
                font-family: 'Inter', sans-serif;">My Account</h4>
                        <a href="#" style="font-size: 12px;
                color: #0284c7;
                text-decoration: none;
                font-weight: 500;
                line-height: 16px;
                transition: all 0.3s ease-in-out;">Manage Orders</a>
                    </div>
                </div>

                <!-- Social Icons -->
                <div class="social-icons" style="margin-bottom: 25px;">
                    <a href="#" style="display: inline-flex;
                width: 40px;
                height: 40px;
                margin: 0 6px;
                border-radius: 50%;
                background: #ffffff;
                align-items: center;
                justify-content: center;
                color: #6b7280;
                font-size: 16px;
                text-decoration: none;
                border: 1px solid #e2e8f0;
                transition: all 0.3s ease-in-out;"><img src="{{ asset('public/images/facebook-f-brands-solid.png') }}" style="height: 14px; width: 16px;"></a>
                    <a href="#" style="display: inline-flex;
                width: 40px;
                height: 40px;
                margin: 0 6px;
                border-radius: 50%;
                background: #ffffff;
                align-items: center;
                justify-content: center;
                color: #6b7280;
                font-size: 16px;
                text-decoration: none;
                border: 1px solid #e2e8f0;
                transition: all 0.3s ease-in-out;"><img src="{{ asset('public/images/twitter-brands-solid.png') }}" style="height: 14px; width: 16px;"></a>
                    <a href="#" style="display: inline-flex;
                width: 40px;
                height: 40px;
                margin: 0 6px;
                border-radius: 50%;
                background: #ffffff;
                align-items: center;
                justify-content: center;
                color: #6b7280;
                font-size: 16px;
                text-decoration: none;
                border: 1px solid #e2e8f0;
                transition: all 0.3s ease-in-out;"><img src="{{ asset('public/images/instagram-brands-solid.png') }}" style="height: 14px; width: 16px;"></a>
                    <a href="#" style="display: inline-flex;
                width: 40px;
                height: 40px;
                margin: 0 6px;
                border-radius: 50%;
                background: #ffffff;
                align-items: center;
                justify-content: center;
                color: #6b7280;
                font-size: 16px;
                text-decoration: none;
                border: 1px solid #e2e8f0;
                transition: all 0.3s ease-in-out;"><img src="{{ asset('public/images/linkedin-brands-solid.png') }}" style="height: 14px; width: 16px;"></a>
                </div>

                <!-- Legal -->
                <div class="footer-legal">
                    <p style="margin: 0 0 8px 0;
                font-size: 12px;
                color: #94a3b8;
                line-height: 16px;">© 2023 FixMyPC Inc. All rights reserved.</p>
                    <p style="margin: 0 0 8px 0;
                font-size: 12px;
                color: #94a3b8;
                line-height: 16px;">123 Tech Boulevard, Silicon Valley, CA 94000</p>
                    <div class="legal-links">
                        <a href="#" style="margin: 0 2px;
                text-decoration: underline;
                font-size: 12px;
                color: #94a3b8;
                line-height: 16px;
                text-underline-offset: 3px;
                transition: all 0.3s ease-in-out;">Privacy Policy</a>
                        <a href="#" style="margin: 0 2px;
                text-decoration: underline;
                font-size: 12px;
                color: #94a3b8;
                line-height: 16px;
                text-underline-offset: 3px;
                transition: all 0.3s ease-in-out;">Terms of Service</a>
                        <a href="#" style="margin: 0 2px;
                text-decoration: underline;
                font-size: 12px;
                color: #94a3b8;
                line-height: 16px;
                text-underline-offset: 3px;
                transition: all 0.3s ease-in-out;">Unsubscribe</a>
                    </div>
                </div>
            </div>
        </div>
    </body>
</html>
