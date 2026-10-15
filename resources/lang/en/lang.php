<?php

return [
    "write_review" => "WRITE A REVIEW",
    "migrating" => "Migrating... Please wait",
    "a_while" => "This may take a while.",
    "wrong_email" => "Wrong email/password",
    'hello' => 'Hello',
    'almost_there' => 'You are almost there.',
    'copy_code' => 'Please copy the code below and verify your email in order to update your password.',
    'thank_you' => 'Thank You',
    'phone' => 'Phone',
    'order' => 'Order',
    'order_date' => 'Order date',
    'purchase_order' => 'Purchase order',
    'billed_to' => 'Billed to',
    'vat_number' => 'VAT number',
    'order_amount' => 'Order amount',
    'ship_to' => 'Ship To',
    'order_method' => 'Order Method',
    'email' => 'Email',
    'title' => 'Title',
    'delivery_fee' => 'Delivery Fee',
    'quantity' => 'Quantity',
    'price' => 'Price',
    'total' => 'Total',
    'subtotal' => 'Subtotal',
    'product_discount' => 'Product discount',
    'shipping_cost' => 'Shipping cost',
    'bundle_offer' => 'Bundle offer',
    'voucher' => 'Voucher',
    'flash_discount' => 'Flash discount',
    // The Essentials Bundle saving. Distinct from 'bundle_offer' above, which
    // is the value of the units a "buy X get Y free" deal gave away.
    'bundle_discount' => 'Bundle saving',
    'loyalty_discount' => 'Loyalty discount',
    'points_discount' => 'Points',
    'tax' => 'Tax',
    'net_ex_vat' => 'Net (Ex-VAT)',
    'vat' => 'VAT',
    'products_inc_vat' => 'Products (Inc-VAT)',
    'net_products_inc_vat' => 'Net Products (Inc-VAT)',
    'vat_included' => 'VAT included in products',
    'discount' => 'Discount',
    'total_inc_vat' => 'Total (Inc-VAT)',
    'notes' => 'Notes',
    'order_number' => 'Please always include your order number when making any query',
    'question_str' => 'For any question regarding order, please contact',
    'thank_order' => 'Thank you for your order from',
    'order_logging' => 'You can check the status of your order by logging into',
    'your_account' => 'Your account',
    'your_order' => 'Your Order',
    'placed_on' => 'Placed on',
    'inform_you' => 'We are pleased to inform you that your order',
    'delivered' => 'has been delivered.',
    'shipped_to' => 'Shipped To',
    'paid_via' => 'Paid via',
    'created_account' => 'You account has been created successfully.',
    'verify_email' => 'You need to verify you email in order to login to your account.',
    'code_below' => 'Please copy the code below and verify your account.',
    'error_occurred' => 'The following error occurred.',
    'configured_your' => 'You have successfully configured your server. You can browse the site now.',
    'updated_your' => 'You have successfully updated your server. You can browse the site now.',
    'redirecting_in' => 'You will be redirecting in',
    'seconds' => 'seconds.',
    'app_name' => 'App Name',
    'database_name' => 'Database Name',
    'database_user' => 'Database User',
    'database_password' => 'Database Password',
    'submit' => 'Submit',
    'not_connect' => 'Could not connect to the database. Please check your configuration.',
    'already_configured' => 'Server already configured. If you want configure the server, please update the value of "SHARED_SERVER_CONFIGURED" from "env" file.',
    'enjoying_purchase' => 'We hope you are enjoying your purchase. We would love to hear your shopping experience to keep un constantly improving.',
    'update' => 'Update to',
    'default_version' => 'v1.0.1',
    'update_msg' => 'Please backup your existing files before update the project.',
    'update_log_msg' => 'Unable to read update log.',
    'update_env' => 'Updating environment data',
    'lang_err_msg' => 'Unable to read language file.',

    'success_email' => 'Success!!! We have sent you an email.',
    'no_email' => 'No email found.',
    'code_not_match.' => 'Verification code did not match.',
    'password_updated' => 'Success!!! Password has been updated.',
    'logged_out' => 'Successfully logged out.',
    'commission_must' => 'Commission must be numeric.',
    'already_exists' => 'Email/username already exists.',
    'own_role' => 'You can not update your own role. Please update your details from profile page.',
    'delete_account' => 'You are unable to delete your own account.',
    'has_products' => 'Unable to delete. Admin has products. Empty all the products of this admin first.',
    'couldnt_found' => 'We couldn\'t found anything.',
    'no_found' => 'No email found.',
    'email_exists' => 'Email already exists.',
    'clearance_already_live' => 'This product already has a clearance deal running. Finish or unpublish that one first.',
    'went_wrong' => 'Something went wrong',
    'no_access' => 'Unauthorized access.',
    'invalid_parameter' => 'Invalid Parameter.',
    'used_inventory' => 'Deleted attribute is being used by Order. Delete the order first.',
    /*
     * The attribute-value delete refusal. Replaces 'used_inventory', which
     * blamed an Order and told the admin to delete it — the check has always
     * been against product variants, so that instruction could not work.
     */
    'attribute_value_in_use' => 'Cannot delete :values. Remove the value from those products first, then delete it here.',
    'attribute_value_usage' => ':name — used by :count variant(s) of :products',
    'attribute_value_unnamed' => 'the blank value',
    'attribute_value_stale' => ':count variant record(s) with no product',
    'delete_attribute' => 'Attribute Value is being used by Order. Delete the order first.',
    'brand_used' => 'Brand is being used by a product.',
    'slider_used' => 'Home slider is using this brand.',
    'banner_used' => 'Banner is using this item.',
    'deal_used' => 'Bundle deal is being used by a product.',
    'category_used' => 'Category is being used by a subcategory.',
    'category_by_product' => 'Category is being used by a product.',
    'slider_by_cat' => 'Home slider is using this category.',
    'unable_delete' => 'Unable to delete. :message',
    'cancel_order' => 'You can cancel any order only when the status is pending.',
    'uncheck_cart' => ' is not public. Please uncheck from cart.',
    'stock_out' => ' is out of stock. Please uncheck from cart.',
    'bulk_update_refused' => 'Nothing was saved. Some rows need fixing first.',
    'product_retired_not_deleted' => ':products appears on a past order, so it was unpublished and removed from sale instead of being deleted. Its order history is kept.',
    'slug_exists' => 'Slug already exists.',
    'quantity_exceeds' => 'Quantity exceeds inventory.',
    'compare_success' => 'This product added successfully to the compare list.',
    'compare_removed' => 'This product removed successfully from compare list.',
    'end_time_must' => 'End time must be greater than start time.',
    'invalid_voucher' => 'Invalid voucher.',
    'invalid_user' => 'Invalid user.',
    'invalid_order' => 'Invalid order.',
    'invalid_token' => 'Invalid token.',
    'no_user' => 'No user found',
    'package_delivered' => 'Your package has been delivered.',
    'no_order' => 'No order found.',
    'not_order' => 'Not your order.',
    'accepted_paypal' => 'Paypal payment is not accepted.',
    'accepted_card' => 'Card payment is not accepted.',
    'accepted_gateway' => 'Gateway is not accepted.',
    'payment_unconfirmed' => 'We could not confirm your payment with the gateway. If your card was charged, contact support with your order reference.',
    "payment_already_used" => "This payment has already been recorded against order :order.",
    'accepted_cod' => 'Cash on delivery is not accepted.',
    'delete_footer' =>  'Please delete footer link first of this page.',
    'using_collection' =>  'Unable to delete. Product is using this collection.',
    'until_delivered' =>  'You can not review a product until the product is being delivered.',
    'error_uploading' =>  'Thank you for your feedback. But there was error while uploading image.',
    'order_cancelled' =>  'This order has been cancelled.',
    'change_permissions' =>  'You are unable to change your own permissions.',
    'delete_admin' =>  'Admin is using this role. Delete the admin user first.',
    'shipping_least' =>  'Shipping must have at least one rule.',
    'place_delete' =>  'Unable to delete. Deleted place is being used by an order.',
    'shipping_delete' =>  'Unable to delete. Shipping rule is being used by a product.',
    'item_used' =>  'Unable to delete. This item is being used by a product.',
    'slider_used_sub_category' =>  'Unable to delete. Home slider is using this sub category.',
    'email_format' =>  'No email format found.',
    'no_subscriber' =>  'No subscriber found.',
    'tax_used' =>  'Unable to delete. Tax rule is being used by a product.',
    'not_verified' =>  'User is not verified.',
    'not_exists' =>  'Email does not exists',
    'invalid_code' =>  'Invalid verification code.',
    'account_registration' => 'Account registration.',
    'profile_updated' => 'Profile updated successfully.',
    'address_message' => 'Address :message successfully.',
    'created' => 'created',
    'updated' => 'updated',
    'deleted' => 'deleted',
    'removed' => 'removed',
    'added' => 'added',
    'address_used' => 'Unable to delete. Address is being used by an order.',
    'email_verified' => 'Email already verified.',
    'wishlist_message' => 'Wishlist :message successfully.',
    'no_email.' => 'No email found.',
    'code_invalid' => 'Invalid Code.',
    'least_spend' => 'You have to spend at least :amount',
    'limit_exceeded' => 'Voucher limit exceeded.',
    'maximum_time' => 'You have used this voucher maximum time.',
    'voucher_expired' => 'This voucher has expired.',
    'time_greater' => 'End time must be greater than start time.',
    'voucher_exists' => 'Voucher code already exists.',
    'used_in_order' => 'Unable to delete. Voucher is being used in the order.',
    'used_request' => 'Unable to delete. Account is being used by an withdrawal request.',
    'no_account' => 'No withdrawal account found.',
    'pending_request' => 'You already have a pending withdrawal request.',
    'insufficient_balance' => 'Insufficient balance.',
    'inventory_used' => 'You can not add a new combination. Old inventory was used in an order.',
    'product_clashes' => ':product is already in the flash sale ":sale", which runs over the same dates.',
    'product_already' => ':product already used in another flash sale.',
    /* Flash sale product rules (QA: the products payload was unvalidated). */
    'product_duplicate' => 'The same product is listed twice in this flash sale.',
    'product_not_public' => ':product is not published, so it cannot go in a flash sale.',
    'invalid_product' => 'A product in this flash sale could not be found.',
    'invalid_price' => 'Enter a sale price for :product.',
    'price_above_zero' => 'The sale price for :product has to be more than zero.',
    'invalid_discount_type' => 'Choose how the reduction on :product is worked out.',
    'invalid_discount_value' => 'Enter a reduction above zero for :product.',
    'discount_below_hundred' => 'A percentage reduction on :product has to be under 100%.',
    'discount_above_price' => ':product sells from :price, so the reduction has to be less than that.',
    'fixed_price_on_variants' => ':product has variants at :count different prices (:low to :high). Use a percentage or an amount off, so the reduction applies to each of them.',
    'invalid_quantity_limit' => 'The quantity limit for :product has to be a whole number above zero, or left blank for no limit.',
    'price_above_retail' => 'The sale price for :product has to be below :price.',
    'price_above_offer' => ':product is already reduced to :price. The sale price has to beat that, or it is not a reduction.',
    'private_product' => ':product is not public. Please uncheck from cart.',
    'out_stock_product' => ':product is out of stock. Please uncheck from cart.',
    'no_cart' => 'No cart found',
    'confirmation' => 'Your :store order confirmation',
    'min_spent' => 'You have to spend at least :amount',
    'voucher_exceeded' => 'Voucher limit exceeded.',
    'voucher_max' =>  'You have used this voucher maximum time.',
    'flutterwave_error' =>  'Transaction ID is invalid',
    'language_exists' =>  'Language already exists.',
    'unpaid_order' =>  'This is an unpaid order.',
    'unable_refund' =>  'Unable to refund for cash on delivery.',
    'not_refund' =>  'One of the product of this order is not refundable.',
    'refunded' =>  'Order refunded successfully',
    'one_default' =>  'Must have at least one default account.',
    'cancelled' =>  'Order cancelled successfully',
    'thank_feedback' =>  'Thank you for your feedback.',
    'failed' => 'Failed to connect',
    'error' => "Got status :status, try again shortly.",
    'error_parse' => "Error parsing response, try again",
    'acc_deleted' => "Account deleted successfully",
    'cat_parent' => "Category and parent category can not be the same",
    'ra_required' => "Rating is required",
    'ra_max' => "Maximum value of the rating can be 5.",
    'ra_numeric' => 'The rating must be numeric.',
    'ra_min' => 'The rating must be at least 1.',
    'multi_img' => 'You can not upload more that 10 images at once.',
    'invalid_bulk' => 'Invalid excel file. Please check the rows.',
    'new_order' => "A new order had been placed",
    'ac_success' => "Your account has been activated.",
    'can_login' => "You can login from the link below.",
    'congo' => "Congratulations",
    'seller_login' => "Seller Login",
    'acc_active' => "Account Activation",
    'com' => "We propose a commission rate of :commission on the total sales amount for each product sold through our platform.",
    'valid_email' => "The email must be a valid email address.",
    'pass_min' => "Password length must be at least 1.",
    'email_required' => "Email is required.",
    'contact_admin' => "Account is not active. Please contact the admin.",
    "ip" => "Iyzico Payment",
    "fw" => "Flutterwave",
    "paypal" => "Paypal",
    "stripe" => "Stripe",
    "rp" => "Razorpay",
    "cod" => "Cash on delivery",
    "bp" => "Bank payment",
    "user_exists" => "Username already exists, please use another username.",

    "fre" => "Free",
    "inv" => "Invoice",
    "fnf" => "File not found: :path",
    "mcd" => "Migration class does not exist.",
    "invalid_sig" => "Invalid signature",
    "invalid_ip" => "Invalid IP",
    "invalid_amount" => "Invalid amount",
    "invalid_validation" => "Invalid validation",

    // QA audit: order status lifecycle, checkout guards and status emails
    "invalid_status_change" => "That status change is not allowed for this order.",
    "paid_cancelled_order" => "This order has been cancelled, so it cannot be marked as paid.",
    "paid_refunded_order" => "This order has been refunded, so it cannot be marked as paid.",
    "select_shipping" => "Please choose a delivery option for :product before checking out.",
    "select_address" => "Please choose a delivery address before checking out.",
    "select_contact_details" => "Please provide your contact details for collection.",
    "order_status_subject" => "Order #:order is now :status",
    "order_status_intro" => "Your order",
    "order_status_is_now" => "is now",
    "order_summary" => "Order summary",
    "order_id" => "Order",
    "status" => "Status",
    "view_order" => "View order",
    "thanks_shopping" => "Thanks for shopping with",

    "refund_failed" => "The refund could not be completed. Check the gateway and try again.",

    "cart_owner_required" => "We could not identify your basket. Please refresh the page and try again.",
    "cart_quantity_minimum" => "The quantity must be at least 1. Remove the item to take it out of your basket.",
    "cart_quantity_maximum" => "You can order at most :max of this item in one line.",


    /*
    |----------------------------------------------------------------------
    | Email templates
    |----------------------------------------------------------------------
    | Copy shared by resources/views/mail_templates. Kept in the language
    | files so nothing user-facing is hard-coded inside a Blade template.
    */

    // Shell: header, support strip, footer
    "mail_visit_store" => "Visit store",
    "mail_need_help" => "Need a hand?",
    "mail_support_line" => "Our support team is here to help.",
    "mail_help_centre" => "Help centre",
    "mail_privacy" => "Privacy",
    "mail_refunds" => "Returns & refunds",
    "mail_contact" => "Contact",
    "mail_transactional_note" => "This is a service message about your account or a recent order, so it is sent regardless of your marketing preferences.",
    "mail_newsletter_reason" => "You are receiving this because you subscribed to news and offers from :store.",
    "mail_unsubscribe" => "Unsubscribe",
    "mail_sent_to" => "Sent to :email",

    // One-time codes
    "mail_verification_code" => "Verification code",
    "mail_code_expiry" => "Expires in :minutes minutes and can only be used once.",

    // Account registration
    "mail_welcome_eyebrow" => "Welcome",
    "mail_verify_title" => "Confirm your email address",
    "mail_verify_sub" => "One quick step and your account is ready.",
    "mail_verify_body" => "Enter the code below on the sign-up screen to finish creating your account.",
    "mail_ignore_registration" => "Didn't create an account? You can safely ignore this email and nothing further will happen.",

    // Forgotten password (code)
    "mail_reset_eyebrow" => "Password reset",
    "mail_reset_code_title" => "Reset your password",
    "mail_reset_code_sub" => "Use the code below to choose a new password.",
    "mail_reset_link_sub" => "Choose a new password using the secure link in this email.",
    "mail_reset_code_body" => "Enter this code on the password reset screen to set a new password.",
    "mail_ignore_reset" => "Didn't ask to reset your password? Ignore this email — your password will not change.",

    // Account opened after a guest checkout
    "mail_guest_account_eyebrow" => "Your account",
    "mail_guest_account_title" => "Your account is ready",
    "mail_guest_account_sub" => "We've set one up so your order and repairs stay in one place.",
    "mail_guest_account_body" => "Thanks for your order. Because you confirmed your email at checkout, we've opened an account on that address and put the order in it — no sign-up needed. Here is what to sign in with.",
    "mail_guest_account_details" => "Sign-in details",
    "mail_guest_account_email" => "Email address",
    "mail_guest_account_password" => "Password",
    "mail_guest_account_cta" => "Sign in to your account",
    "mail_guest_account_warning" => "This email is the only copy of that password — we store it scrambled and cannot read it back. Change it to something of your own after you sign in.",
    "mail_guest_account_note" => "Your order confirmation is on its way in a separate email, and you can track the order from your account at any time.",

    // Sign-in code
    "mail_login_eyebrow" => "Sign in",
    "mail_login_title" => "Your sign-in code",
    "mail_login_sub" => "Use this code to finish signing in.",
    "mail_login_body" => "Enter this code on the sign-in screen to continue.",
    "mail_login_warning" => "Never share this code. Our team will never ask you for it.",
    "mail_ignore_login" => "Didn't try to sign in? Ignore this email, and change your password if you weren't expecting it.",

    // Password reset link
    "mail_reset_link_body" => "We received a request to set a new password for your account. Choose a new one using the button below.",
    "mail_set_new_password" => "Set a new password",
    "mail_link_expiry" => "This link expires in :minutes minutes and can only be used once.",
    "mail_link_fallback" => "If the button doesn't work, copy this address into your browser:",

    // Vendor activation
    "mail_seller_eyebrow" => "Vendor account",
    "mail_seller_title" => "Your vendor account is live",
    "mail_seller_sub" => "You can start listing products straight away.",
    "mail_open_dashboard" => "Open vendor dashboard",

    // Order confirmation
    "mail_order_eyebrow" => "Order confirmed",
    "mail_order_title" => "Thanks for your order",
    "mail_order_sub" => "We've got it. Your invoice is attached to this email and we'll let you know the moment it ships.",
    "mail_order_details" => "Order details",
    "mail_order_number" => "Order number",
    "mail_order_placed_on" => "Placed on",
    "mail_payment_method" => "Payment method",
    "mail_delivery_address" => "Delivery address",
    "mail_items_ordered" => "Items ordered",
    "mail_item_count" => "{1} :count item|[2,*] :count items",
    "mail_product" => "Product",
    "mail_qty" => "Qty",
    "mail_each" => "each",
    "mail_free" => "Free",
    "mail_total_paid" => "Total paid",
    "mail_track_order" => "Track your order",
    "mail_view_order" => "View your order",
    "mail_next_update" => "We'll email you again as soon as your order is on its way.",
    "mail_quote_order" => "Please quote your order number in any correspondence about this order.",

    // Vendor: new order
    "mail_vendor_eyebrow" => "New order",
    "mail_vendor_title" => "You have a new order to fulfil",
    "mail_vendor_sub" => "Order #:order is ready to be picked and dispatched.",
    "mail_customer" => "Customer",
    "mail_fulfil_order" => "Fulfil this order",

    // Order status change
    "mail_status_eyebrow" => "Order update",
    "mail_status_title" => "Your order is now :status",
    "mail_status_sub" => "Order #:order has moved to a new stage.",

    // Delivered
    "mail_delivered_eyebrow" => "Delivered",
    "mail_delivered_title" => "Your order has arrived",
    "mail_delivered_sub" => "Order #:order was marked as delivered. We hope it's everything you expected.",
    "mail_review_prompt" => "How did we do? A short review helps other shoppers and helps us get better.",

    // Payment failed. Deliberately vague about the cause: the gateway's reason
    // is not something the customer can act on, and quoting a decline code at
    // someone whose card was declined reads as an accusation.
    "mail_payment_failed_eyebrow" => "Payment problem",
    "mail_payment_failed_title" => "We couldn't take your payment",
    "mail_payment_failed_sub" => "Order #:order is being held for you.",
    "mail_payment_failed_body" => "Your payment didn't go through, so we haven't been able to start on your order yet. Nothing has been charged. Your order is still here and you can try paying for it again whenever you're ready.",
    "mail_payment_failed_cta" => "Try payment again",
    "mail_payment_failed_subject" => "We couldn't take payment for order #:order",
    "mail_payment_failed_note" => "If you tried more than once, don't worry - you'll only ever be charged once. Any pending amount your bank is showing will drop off on its own.",

    // Payment confirmed after the fact - a late redirect, a webhook, or an
    // admin marking off a transfer.
    "mail_payment_received_label" => "Payment received",
    "mail_payment_received_subject" => "We've received payment for order #:order",

    // Refund detail, added to the status email.
    "mail_refunded_amount" => "Refunded",
    "mail_refund_reference" => "Refund reference",

    // Subjects
    "mail_subject_registration" => "Confirm your email address",
    "mail_subject_login" => "Your sign-in code",
    "mail_subject_forgot" => "Reset your password",
    "mail_subject_order" => "Order #:order is confirmed",
    "mail_subject_delivered" => "Order #:order has been delivered",
    "mail_subject_vendor_order" => "New order #:order to fulfil",
    "mail_subject_seller" => "Your vendor account is live",


    /* ---------------------------------------------------------------------
     | Trade application decisions.
     |
     | Written to be read by the customer: the approval says what changed and
     | where to use it, the rejection says what the account can still do
     | rather than only what it cannot. Neither carries anything from the
     | review beyond the note the reviewer chose to leave, which the customer
     | already sees on their dashboard.
     * -------------------------------------------------------------------- */
    "mail_trade_eyebrow" => "Trade account",

    "mail_trade_approved_subject" => "Your Trade Account Has Been Approved",
    "mail_trade_approved_title" => "Your trade account is approved",
    "mail_trade_approved_sub" => "Trade pricing is switched on and ready to use.",
    "mail_trade_approved_body" => "Good news — we have reviewed your trade application and approved it. Your account is active and there is nothing else for you to do.",
    "mail_trade_approved_pricing" => "Trade pricing now applies automatically. You will see your rates on each product and in your basket, and they are the prices you will be charged at checkout.",
    "mail_trade_approved_cta" => "Start shopping at trade prices",
    "mail_trade_approved_account" => "Sign in any time to see your trade status, your orders and your invoices in your account: :url",

    "mail_trade_rejected_subject" => "Update on Your Trade Account Application",
    "mail_trade_rejected_title" => "About your trade application",
    "mail_trade_rejected_sub" => "We were not able to approve it this time.",
    "mail_trade_rejected_body" => "Thank you for applying for a trade account. We have reviewed your application and we are not able to approve it at this time.",
    "mail_trade_rejected_retail" => "Your account is still open and working normally — you can sign in and order at our standard prices exactly as before.",
    "mail_trade_rejected_cta" => "Continue shopping",
    "mail_trade_rejected_support" => "If your circumstances have changed, or you think we have got this wrong, reply to this email or contact us at :email and we will take another look.",

    "mail_trade_panel" => "Your application",
    "mail_trade_company" => "Company",
    "mail_trade_status" => "Status",
    "mail_trade_status_approved" => "Approved",
    "mail_trade_status_rejected" => "Not approved",
    "mail_trade_reason_prefix" => "What the reviewer said:",
    "mail_trade_note_prefix" => "A note from the reviewer:",

    /* Account upgrades (App\Models\Helper\AccountUpgrade) */
    "mail_upgrade_business_subject" => "Your Business Account Is Ready",
    "mail_upgrade_business_eyebrow" => "Business account",
    "mail_upgrade_business_title" => "Your account is now a business account",
    "mail_upgrade_business_sub" => "Business pricing is switched on.",
    "mail_upgrade_business_body" => "You have upgraded your account to a business account. There is nothing else you need to do.",
    "mail_upgrade_business_pricing" => "Business pricing now applies automatically at the quantity breaks shown on each product, in your basket and at checkout.",
    "mail_upgrade_business_check" => "We will check the company details you gave us, and will only be in touch if something needs correcting.",
    "mail_upgrade_business_cta" => "Start shopping",
    "mail_upgrade_panel" => "Your account",
    "mail_upgrade_type" => "Account type",
    "mail_upgrade_type_business" => "Business",
    "mail_upgrade_not_you" => "If you did not make this change, contact us straight away at :email.",
    "mail_trade_received_subject" => "We Have Received Your Trade Application",
    "mail_trade_received_title" => "Your trade application is with us",
    "mail_trade_received_sub" => "We usually review applications within one working day.",
    "mail_trade_received_body" => "Thank you for applying for a trade account. Our team will review your application and email you as soon as it has been decided.",
    "mail_trade_received_business" => "Your business account and its pricing carry on exactly as before while we review it.",
    "mail_trade_received_personal" => "You can keep ordering at our standard prices while we review it.",
    "mail_trade_received_cta" => "View your application",
    "mail_trade_received_not_you" => "If you did not make this application, contact us straight away at :email.",
    "mail_trade_trade" => "Trade",
    "mail_trade_status_pending" => "Under review",
    "mail_trade_rejected_business" => "Your business account is unchanged — business pricing still applies to your orders exactly as before.",


    /* Category hierarchy */
    'category_parent_self' => 'A category cannot be its own parent.',
    'category_parent_cycle' => 'That parent sits underneath this category, which would create a loop.',
    'category_parent_missing' => 'The selected parent category no longer exists.',
    'category_parent_too_deep' => 'Categories can only be nested :max levels deep.',
    'category_slug_format' => 'The slug may only contain lowercase letters, numbers and hyphens.',
    'category_title_exists' => 'A category with this name already exists under the same parent.',
    'category_not_found' => 'That category no longer exists.',
    'category_has_children' => 'This category has subcategories. Choose what should happen to them before deleting it.',
    'category_deleted' => 'Category deleted.',
    'category_status_updated' => 'Category status updated.',

    'invalid_image' => 'Upload a JPG, PNG, GIF, WebP, AVIF or SVG image.',
    'image_too_large' => 'That image is too large. The maximum is :max MB.',

    /* Used by every 'no such record' refusal, including the stock check. */
    'nothing_found' => 'We could not find that item.',

    /*
     * Upsell campaigns. Each one is phrased as the fix rather than the fault,
     * because every one of them is shown against a step of a wizard the admin
     * is still standing in and can correct.
     */
    'upsell_needs_source' => 'Choose at least one product this upgrade applies to.',
    'upsell_needs_option' => 'Choose at least one upgrade option to offer.',
    'upsell_self_upgrade' => 'A product cannot be offered as an upgrade to itself. Choose a different option.',
    'upsell_unavailable_products' => 'These products are not published and cannot be used: :products',
    'upsell_variant_mismatch' => 'That variant does not belong to the product it was chosen under.',
    'upsell_bad_dates' => 'The end date falls before the start date.',
    'upsell_no_surface' => 'Choose at least one place for this upsell to show.',

    /* Why a campaign refused to go live. */
    'upsell_block_no_sources' => 'It has no source products. Add at least one product this should apply to.',
    'upsell_block_no_options' => 'It has no upgrade options. Add at least one product to offer.',
    'upsell_block_no_surface' => 'It is not set to show anywhere. Choose at least one of Product, Cart or Checkout.',
    'upsell_block_bad_dates' => 'Its end date falls before its start date.',
    'upsell_block_expired' => 'Its end date has already passed. Clear or extend the end date first.',
    'upsell_block_unavailable_products' => 'One or more of its products is no longer published. Replace or remove it first.',

    /*
     * Variant upgrades. Written for a shopper, because these are the messages
     * that come back when an upgrade stops being possible between the page
     * rendering and the click.
     */
    'upgrade_no_variant' => 'We could not find that option.',
    'upgrade_unchanged' => 'That is the option you already have.',
    'upgrade_not_available' => 'That upgrade is no longer available.',
    'upgrade_out_of_stock' => 'That option has just gone out of stock.',
    'upgrade_quantity' => 'There are not enough of those left for the quantity you wanted.',

    /* Configuring upgrades, in the admin. */
    'upgrade_duplicate_attribute' => 'That attribute already has an upgrade group. Edit the existing one instead.',
    'upgrade_attribute_unused' => 'None of this product\'s variants use that attribute.',
    'upgrade_manual_needs_options' => 'Choose at least one option to offer, or switch the group back to automatic.',
    'upgrade_value_mismatch' => 'One of the chosen options does not belong to the upgrade attribute.',

    /*
     * Cross-sell, in the admin. Each one names the products it is about,
     * because a picker that refuses twelve ticks with "validation failed"
     * tells the admin nothing about which of the twelve was wrong.
     */
    'cross_sell_unavailable' => 'These products are not published and cannot be offered: :products',
    'cross_sell_too_many' => 'A cross-sell can offer at most :max products. Remove some before adding more.',
    'cross_sell_block_no_products' => 'It has no products. Add at least one product before switching it on.',
    'cross_sell_not_found' => 'That cross-sell no longer exists.',
    'cross_sell_already_assigned' => ':name is already assigned to this product.',
    'cross_sell_self_assign' => ':name offers this very product, so it cannot be assigned to it.',

    /*
     * Cross-sell, on the storefront. Written for a shopper, because these come
     * back when a recommendation stops being buyable between the block
     * rendering and the click -- which is the normal case, not the error case.
     */
    'cross_sell_nothing_selected' => 'Select at least one product to add.',
    'cross_sell_gone' => 'That product is no longer available.',
    'cross_sell_needs_option' => 'Choose an option for :product before adding it.',
    'cross_sell_bad_option' => 'That option does not belong to :product.',
    'cross_sell_option_unavailable' => 'That option of :product is no longer available.',
    'cross_sell_out_of_stock' => ':product has just gone out of stock.',
    'cross_sell_not_added' => 'We could not add anything to your basket.',

    /*
     * The Essentials Bundle. A bundle is all or nothing, so there is no
     * per-item message here: an item that has gone takes the bundle with it,
     * and telling a shopper which of five accessories sold out does not help
     * them, because the bundle price was never available piecemeal.
     */
    'bundle_gone' => 'That bundle is no longer available.',
    'bundle_unavailable' => 'That bundle is not complete at the moment, so it cannot be added.',
];
