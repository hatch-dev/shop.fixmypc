<?php
return [
    'media' => [
        'DEFAULT_IMAGE' => env('DEFAULT_IMAGE'),
        'MAX_IMG_UPLOAD' => 10,
        'MAX_IMAGE_SIZE' => 1024,
        'MAX_VIDEO_SIZE' => 2048,
        'MAX_FILE_SIZE' => 2048,
    ],
    'dateTime' => [
        'ONLY_DATE' => 'd M, y'
    ],
    'auth' => [
        'EXPIRATION_IN_HOURS' => 12,
        // A customer who ticked "Keep me signed in".
        'EXPIRATION_IN_DAYS' => 30,
        // A customer who did not: signed out within a day.
        'SESSION_HOURS' => 24
    ],
    'pagination' => [
        'FRONTEND_PRODUCT_RATING' => 6,
        'FRONTEND_SEARCH' => 9,
        'DASHBOARD' => 8
    ],
    'homePagePagination' => [
        'COLLECTION' => 9,
        'FEATURED_BRANDS' => 15,
        'FEATURED_CATEGORIES' => 12
    ],
    /*
     * Returns (App\Models\Helper\OrderReturns). The window is advisory in
     * phase 1: staff see a warning past it, and can still open the return.
     */
    'returns' => [
        'WINDOW_DAYS' => 30,
        // Email the shop when a customer asks for a return or exchange.
        'STAFF_ALERT' => true,
    ],

    'api' => [
        'PAGINATION' => 14
    ],
    'frontend' => [
        'PAGINATION' => 25
    ],
    'homeProduct' => [
        'PAGINATION' => 24
    ],
    'listing' => [
        'PAGINATION' => 30
    ],
    'imageSlider' => [
        'PAGINATION' => 7
    ],
    'setting' => [
        'CURRENCY' => 'USD',
        'CURRENCY_ICON' => '$'
    ],
    'status' => [
        'PUBLIC' => 1,
        'PRIVATE' => 2
    ],
    'priceType' => [
        'FLAT' => 1,
        'PERCENT' => 2
    ],
    'footerLinkType' => [
        'SERVICE' => 1,
        'ABOUT' => 2
    ],
    'headerLinkType' => [
        'LEFT' => 1,
        'RIGHT' => 2
    ],
    'footerImageLinkType' => [
        'PAYMENT' => 1,
        'SOCIAL' => 2
    ],
    'banner' => [
        'BANNER_1' => 1,
        'BANNER_2' => 2,
        'BANNER_3' => 3,
        'BANNER_4' => 4,
        'BANNER_5' => 5,
        'BANNER_6' => 6,
        'BANNER_7' => 7,
        'BANNER_8' => 8,
        'BANNER_9' => 9,
    ],
    'homeSlider' => [
        'MAIN' => 1,
        'RIGHT_TOP' => 2,
        'RIGHT_BOTTOM' => 3
    ],
    'sliderSourceType' => [
        'CATEGORY' => 1,
        'SUB_CATEGORY' => 2,
        'TAG' => 3,
        'BRAND' => 4,
        'PRODUCT' => 5,
        'URL' => 6
    ],
    'wysiwygImage' => [
        'PAGE' => 1,
        'PRODUCT_OVERVIEW' => 2,
        'PRODUCT_DESCRIPTION' => 3
    ],
    'shippingTypeIn' => [
        'LOCATION' => 1,
        'PICKUP' => 2
    ],
    'posMethod' => [
        'CASH' => 1,
        'CASH_ON_DELIVERY' => 2,
        'OFFLINE' => 3
    ],
    /*
     * How long an unpaid bank transfer order is held before
     * orders:cancel-unpaid-transfers cancels it. The bank details page counts
     * down to the same moment.
     */
    'bankTransferHoldHours' => (int)env('BANK_TRANSFER_HOLD_HOURS', 24),

    'paymentMethod' => [
        'RAZORPAY' => 1,
        'CASH_ON_DELIVERY' => 2,
        'STRIPE' => 3,
        'PAYPAL' => 4,
        'FLUTTERWAVE' => 5,
        'IYZICO_PAYMENT' => 6,
        'BANK' => 7,
        'PAYFAST' => 8,
        'SUMUP' => 9,
        'WALLET' => 10
    ],
    'paymentMethodIn' => [
        1 => 'Razorpay',
        2 => 'Cash on delivery',
        3 => 'Stripe',
        4 => 'Paypal',
        5 => 'Flutterwave',
        6 => 'Iyzico Payment',
        7 => 'Bank Payment',
        8 => 'PayFast',
        9 => 'Sumup',
        10 => 'Wallet'
    ],
    'paymentMethodKLangIn' => [
        1 => 'rp',
        2 => 'cod',
        3 => 'stripe',
        4 => 'paypal',
        5 => 'fw',
        6 => 'ip',
        7 => 'bp',
        8 => 'payfast',
        9 => 'sumup',
        10 => 'wallet'
    ],
    /*
     * QA audit ORD-01. There was no terminal state other than DELIVERED, which
     * is why cancellation had to be carried on a separate boolean column and
     * cancelled orders kept showing as PENDING in every admin list.
     */
    /*
     * Inventory alerting.
     *
     * A variant is 'low' at or below this many units. There is no per-product
     * threshold column and no stored setting, so this is the single place the
     * figure is defined - it was previously a bare 5 inside the dashboard
     * controller, invisible to anyone configuring the shop.
     */
    'inventory' => [
        'lowStockThreshold' => (int)env('LOW_STOCK_THRESHOLD', 5),
    ],
    'orderStatus' => [
        'PENDING' => 1,
        'CONFIRMED' => 2,
        'PICKED_UP' => 3,
        'ON_THE_WAY' => 4,
        'DELIVERED' => 5,
        'CANCELLED' => 6,
        'RETURNED' => 7,
        'REFUNDED' => 8
    ],
    'orderStatusIn' => [
        1 => 'Pending',
        2 => 'Confirmed',
        3 => 'Picked up',
        4 => 'On the way',
        5 => 'Delivered',
        6 => 'Cancelled',
        7 => 'Returned',
        8 => 'Refunded'
    ],
    'withdraw' => [
        'MIN_AMOUNT' => 50
    ] ,
    'withdrawalStatus' => [
        'APPROVED' => 1,
        'REQUESTED' => 2,
        'CANCELLED' => 3
    ],
    'currencyPosition' => [
        'PRE'=> 1,
        'POST'=> 2
    ],
    'withdrawn' => [
        'NO' => 0,
        'YES' => 1,
        'PENDING' => 2
    ]
];
