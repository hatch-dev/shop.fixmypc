// Central definition of the admin sidebar menu so both the sidebar component
// (layouts/partials/Sidebar.vue) and the "Sidebar Icons" settings page stay in
// sync. `t` is the i18n translate function (e.g. this.$t bound to the component).
export function buildSidebarMenu(t) {
  return [
    { path: '', title: t('error.das'), icon: '<svg width="19" height="18" viewBox="0 0 19 18" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M18.606 8.8555L9.96529 0.192403C9.84161 0.0691656 9.67437 0 9.50004 0C9.32571 0 9.15846 0.0691656 9.03479 0.192403L0.394084 8.8555C0.267358 8.98212 0.167204 9.13293 0.0995338 9.29901C0.0318639 9.4651 -0.00195035 9.6431 8.68674e-05 9.8225C0.00846979 10.5625 0.622519 11.1532 1.36022 11.1532H2.2509V18H16.7492V11.1532H17.6587C18.0171 11.1532 18.3545 11.0123 18.6081 10.758C18.733 10.6331 18.8319 10.4847 18.8992 10.3212C18.9664 10.1578 19.0007 9.98253 19 9.80569C19 9.44832 18.8596 9.10987 18.606 8.8555ZM10.6736 16.4864H8.32643V12.198H10.6736V16.4864ZM15.2402 9.63962V16.4864H12.0149V11.6934C12.0149 11.2289 11.6398 10.8526 11.1766 10.8526H7.82345C7.3603 10.8526 6.98516 11.2289 6.98516 11.6934V16.4864H3.75983V9.63962H1.74793L9.50213 1.86784L17.2542 9.63962H15.2402Z" fill="#333199"/></svg>', gate: 'dashboard' },
    { section: 'STORE' },
    { path: 'categories', title: t('error.cat'), icon: 'fa-solid fa-layer-group', gate: 'category' },
    { path: 'products', title: t('error.prod'), icon: 'fa-solid fa-box', gate: 'product' },
    { path: 'attributes', title: t('list.attr'), icon: 'fa-solid fa-tags', gate: 'attribute' },
    { path: 'brands', title: t('error.brands'), icon: 'fa-solid fa-copyright', gate: 'brand' },
    { path: 'tax-rules', title: t('error.tr'), icon: 'fa-solid fa-receipt', gate: 'tax_rule' },
    { path: 'shipping-rules', title: t('brand.shipRules'), icon: 'fa-solid fa-truck-fast', gate: 'shipping_rule' },
    { path: 'product-collections', title: t('error.col'), icon: 'fa-solid fa-layer-group', gate: 'product_collection' },
    { path: 'bulk-product-editor', title: t('error.bulkProduct'), icon: 'fa-solid fa-list-check', gate: 'product_bulk_update' },
    { path: 'rating-reviews', title: t('error.rr'), icon: 'fa-solid fa-star', gate: 'rating_review' },

    { section: 'BUSINESS PRODUCT' },
    { path: 'business-products', title: 'Business Products', icon: 'fa-solid fa-briefcase', gate: 'business_product' },

    { section: 'LOYALTY' },
    { path: 'loyalty-groups', title: 'Loyalty Groups', icon: 'fa-solid fa-award', gate: 'loyalty_group' },

    { section: 'WALLET' },
    { path: 'wallet-overview', title: 'Wallet Overview', icon: 'fa-solid fa-wallet', gate: 'wallet_log' },

    { section: 'ORDERS' },
    { path: 'orders', title: t('error.orders'), icon: 'fa-solid fa-cart-shopping', gate: 'order' },
    // { path: 'refunded', title: 'Refunded', icon: 'fa-solid fa-rotate-left' },
    // { path: 'cancelled', title: 'Cancelled', icon: 'fa-solid fa-ban' },
    // { path: 'incomplete', title: 'Incomplete', icon: 'fa-solid fa-file-circle-xmark' },

    { section: 'PROMOTIONS' },
    /*
     * Upsell and Cross-sell are two separate promotions, not one feature.
     *
     * They used to share a "Product Recommendations" hub, which put a landing
     * page between the admin and either of them and made cross-sell read as a
     * generic recommendation feature rather than what it is: a named set of
     * complementary products, assigned to the products it goes with. The hub
     * is gone. Upsell is currently hidden from the sidebar (its pages and API
     * still exist); Cross-sell has its own entry beside the flash sales.
     */
    // { path: 'upsell', title: 'Upsell', icon: 'fa-solid fa-arrow-up-right-dots', gate: 'upsell' },
    { path: 'cross-sell', title: 'Cross-sell', icon: 'fa-solid fa-layer-group', gate: 'crosssell' },
    { path: 'flash-sales', title: t('error.fs'), icon: 'fa-solid fa-bolt', gate: 'flash_sale' },
    { path: 'flash-discount', title: 'Flash Discount', icon: 'fa-solid fa-percent', gate: 'flash_discount' },
    { path: 'bundle-deals', title: t('error.bd'), icon: 'fa-solid fa-gift', gate: 'bundle_deal' },
    { path: 'clearances', title: t('error.clearances'), icon: 'fa-solid fa-tag', gate: 'clearance' },
    { path: 'vouchers', title: t('error.vou'), icon: 'fa-solid fa-ticket', gate: 'voucher' },
    { path: 'gift-vouchers', title: 'Gift Card Designs', icon: 'fa-solid fa-gift', gate: 'gift_voucher' },
    { path: 'gift-cards', title: 'Gift Card Sales', icon: 'fa-solid fa-receipt', gate: 'gift_card' },

    { section: 'MARKETING' },
    { path: 'subscription-email-formats', title: t('error.ef'), icon: 'fa-solid fa-envelope-open-text', gate: 'subscription_email_format'},
    // { path: 'campaign', title: 'Manage Campaign', icon: 'fa-solid fa-bullhorn' },
    // { path: 'lists', title: 'Manage Lists', icon: 'fa-solid fa-address-book' },

    { section: 'PROCUREMENT' },
    { path: 'procurement/suppliers', title: t('procurement.suppliers'), icon: 'fa-solid fa-truck', gate: 'supplier' },
    { path: 'procurement/sourcing', title: t('procurement.sourcing'), icon: 'fa-solid fa-scale-balanced', gate: 'supplier' },

    { section: 'USER MANAGEMENT' },
    { path: 'admins-vendors', title: t('error.av'), icon: 'fa-solid fa-user-tie', gate: 'admin' },
    { path: 'registered-users', title: t('profile.registered'), icon: 'fa-solid fa-users', gate: 'user' },
    { path: 'guest-users', title: t('profile.guest'), icon: 'fa-solid fa-user', gate: 'user' },
    { path: 'trade-applications', title: 'Trade Applications', icon: 'fa-solid fa-file-signature', gate: 'user' },
    { path: 'subscribers', title: t('error.subCrbs'), icon: 'fa-solid fa-user-plus', gate: 'subscriber' },
    { path: 'roles-permissions', title: t('error.rp'), icon: 'fa-solid fa-key', gate: 'role' },

    { section: 'FINANCE' },
    { path: 'withdrawal-requests', title: t('error.req'), icon: 'fa-solid fa-money-bill-transfer', gate: 'withdrawal_request' },
    { path: 'withdrawal-accounts', title: t('error.acc'), icon: 'fa-solid fa-building-columns', gate: 'withdrawal_account' },


    { section: 'UI' },
    { path: 'templates', title: t('index.templates'), icon: 'fa-solid fa-clone' },
    { path: 'pages', title: t('error.pages'), icon: 'fa-solid fa-file-lines', gate: 'page' },
    { path: 'home-slider', title: t('profile.hSlid'), icon: 'fa-solid fa-images', gate: 'home_slider' },
    { path: 'banners', title: t('admin.banners'), icon: 'fa-solid fa-image', gate: 'banner' },
    { path: 'auth-banners', title: 'Sign-in Banners', icon: 'fa-solid fa-rectangle-ad', gate: 'banner' },
    { path: 'footer-links', title: t('error.fl'), icon: 'fa-solid fa-link', gate: 'footer_link' },
    { path: 'header-links', title: t('dataPage.hl'), icon: 'fa-solid fa-bars', gate: 'header_link' },
    { path: 'site-features', title: t('title.sf'), icon: 'fa-solid fa-star-half-stroke', gate: 'home_slider' },
    { path: 'site-setting', title: t('admin.site'), icon: 'fa-solid fa-sliders', gate: 'site_setting' },
    { path: 'custom-scripts', title: t('title.cs'), icon: 'fa-solid fa-code', gate: 'site_setting' },

    // { section: 'REPORTS' },
    // { path: 'sales-report', title: 'Sales Report', icon: 'fa-solid fa-chart-line' },
    // { path: 'products-report', title: 'Products Report', icon: 'fa-solid fa-boxes-stacked' },
    // { path: 'tax-report', title: 'Tax Report', icon: 'fa-solid fa-receipt' },
    // { path: 'refund-report', title: 'Refund Report', icon: 'fa-solid fa-rotate-right' },
    // { path: 'traffic-report', title: 'Traffic Report', icon: 'fa-solid fa-chart-pie' },
    // { path: 'profit-loss', title: 'Profit Loss', icon: 'fa-solid fa-chart-simple' },

    { section: 'SETTINGS' },
    { path: 'bulk-upload', title: t('title.bu'), icon: 'fa-solid fa-upload', gate: 'bulk_upload' },
    { path: 'store', title: t('error.store'), icon: 'fa-solid fa-gear', gate: 'store' },
    { path: 'setting/currency', title: t('list.set'), icon: 'fa-solid fa-coins', gate: 'setting' },
  ]
}

// The stable override key for a menu item: its path, or 'dashboard' for the
// root item (which has an empty path). Used to map uploaded icons back to items.
export function menuItemKey(item) {
  return item.path || 'dashboard'
}

// Flat list of overridable menu entries (section headers excluded), shaped for
// the settings page: { key, title, defaultIcon }.
export function sidebarMenuItems(t) {
  return buildSidebarMenu(t)
    .filter(item => !item.section && item.title)
    .map(item => ({ key: menuItemKey(item), title: item.title, defaultIcon: item.icon }))
}
