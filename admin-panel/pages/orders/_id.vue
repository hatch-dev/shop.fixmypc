<template>
  <div class="detail-width">

    <error-formatter />

    <!--
      Loading, not-found and error are three different answers and used to be
      one: the page drew its cards against a null order while the fetch was in
      flight, and an order that could not be loaded left an empty shell with no
      explanation.
    -->
    <div v-if="loading" class="order-state">
      <spinner :radius="60" color="primary" />
      <p>{{ $t('fSale.loadingOrder') }}</p>
    </div>

    <div v-else-if="!result" class="order-state">
      <div class="order-state-icon"><i class="fa-solid fa-circle-exclamation" /></div>
      <h5>{{ $t('fSale.noOrder') }}</h5>
      <p>{{ $t('fSale.noOrderHint') }}</p>
      <router-link class="primary-btn" to="/orders">{{ $t('error.orders') }}</router-link>
    </div>

    <template v-else>

      <p v-if="orderCancelled" class="info-msg danger-msg order-wrapper mb-15">
        {{ $t('fSale.can', { type: $t('fSale.order') }) }}
      </p>

      <p v-if="refunded" class="info-msg success-msg order-wrapper mb-15">
        {{ $t('fSale.ref', { type: $t('fSale.order') }) }}
      </p>

      <cancellation
        v-if="openCancellationMessage"
        v-outside-click="outsideClick"
        @close="openCancellationMessage = false"
      />

      <div class="order-page">

        <!-- ============================ Header ============================ -->
        <div class="order-head">
          <div class="order-head-info">
            <nav class="order-breadcrumb">
              <router-link to="/orders">{{ $t('error.orders') }}</router-link>
              <i class="fa-solid fa-chevron-right" />
              <span>#{{ result.order }}</span>
            </nav>

            <div class="order-title-row">
              <h4>{{ $t('fSale.orderUp') }} #{{ result.order }}</h4>

              <!--
                The order number is what every conversation about this order
                starts with, so it is copyable rather than something to
                transcribe from the address bar.
              -->
              <button
                class="copy-btn"
                type="button"
                :title="$t('setting.copy')"
                @click.prevent="copyOrderId"
              >
                <i class="fa-solid" :class="copied ? 'fa-check' : 'fa-copy'" />
              </button>

              <span class="order-status-badge" :class="'is-' + statusVariant">
                <i class="fa-solid fa-circle-dot" />
                {{ statusLabel }}
              </span>
            </div>

            <p class="order-subtitle">
              {{ $t('setting.od') }}: {{ result.created }}
            </p>

            <span v-if="copied" class="copy-flash">{{ $t('fSale.orderCopied') }}</span>
          </div>

          <template v-if="!isVendor">
            <div class="order-head-actions">

              <!--
                Only the moves the server will accept from where this order
                stands, so the screen cannot offer one it will refuse.
              -->
              <div v-if="!orderCancelled" class="status-control">
                <dropdown
                  :selected-key="result.status"
                  :options="allowedStatuses"
                  @clicked="selectedStatus"
                />

                <ajax-button
                  name="save-edit"
                  class="primary-btn"
                  :text="$t('fSale.updateStatus')"
                  :loading-text="$t('profile.updatn')"
                  :fetching-data="formSubmitting"
                  type="button"
                  @clicked="updateStatus"
                />
              </div>

              <button class="outline-btn" type="button" @click.prevent="printInvoice">
                <i class="fa-solid fa-print mr-10" />
                {{ $t('setting.pi') }}
              </button>

              <button
                v-if="canStartReturn"
                class="outline-btn"
                type="button"
                @click.prevent="$router.push(`/returns/start?order=${result.id}`)"
              >
                <i class="fa-solid fa-rotate-left mr-10" />
                Start a return
              </button>

              <!-- Contextual: only rendered when the order actually has a
                   cancellation to explain or a payment to refund. -->
              <template v-if="cancellationMessage">
                <button class="outline-btn" type="button" @click.prevent="openCancellationMessage = true">
                  {{ $t('fSale.cReason') }}
                </button>

                <ajax-button
                  v-if="!isCashOnDelivery && paymentDone"
                  class="outline-btn"
                  type="button"
                  :loading-text="$t('fSale.refunding')"
                  :disabled="refunded"
                  :fetching-data="refunding"
                  :text="refunded ? $t('fSale.refUp') : $t('fSale.refund')"
                  @clicked="refund"
                />
              </template>
            </div>
          </template>
        </div>

        <!-- =========================== Overview =========================== -->
        <!--
          Eight facts that answer "what is this order" without scrolling. The
          page opened straight into the customer card, so the account type, the
          fulfilment method and the total were all several sections apart.
        -->
        <section class="order-overview">
          <div class="ov-item">
            <span class="ov-label">{{ $t('fSale.orderUp') }}</span>
            <span class="ov-value ov-mono">#{{ result.order }}</span>
          </div>
          <div class="ov-item">
            <span class="ov-label">{{ $t('category.date') }}</span>
            <span class="ov-value">{{ result.created }}</span>
          </div>
          <div class="ov-item">
            <span class="ov-label">{{ $t('fSale.accountType') }}</span>
            <span class="ov-value">
              <span class="account-pill" :class="'is-' + customer.account_type">{{ accountTypeLabel }}</span>
            </span>
          </div>
          <div class="ov-item">
            <span class="ov-label">{{ $t('fSale.oStatus') }}</span>
            <span class="ov-value" :class="'color-' + statusVariant">{{ statusLabel }}</span>
          </div>
          <div class="ov-item">
            <span class="ov-label">{{ $t('fSale.pMethod') }}</span>
            <span class="ov-value">{{ paymentTypes[result.order_method] || $t('prod.na') }}</span>
          </div>
          <div v-if="result.state && result.state.refund" class="ov-item">
            <span class="ov-label">Refund</span>
            <span class="ov-value" :class="{ 'color-success': ['completed', 'partial'].includes(result.state.refund.key), 'color-danger': result.state.refund.key === 'failed' }">
              {{ { pending: 'Pending', processing: 'Processing', completed: 'Refunded', partial: 'Partly refunded', failed: 'Failed' }[result.state.refund.key] }}
              <template v-if="result.state.refund.amount">· {{ priceFormatting(result.state.refund.amount) }}</template>
            </span>
          </div>
          <div class="ov-item">
            <span class="ov-label">{{ $t('fSale.pStatus') }}</span>
            <span class="ov-value" :class="paymentDone ? 'color-success' : 'color-danger'">
              {{ paymentDone ? $t('fSale.paid') : $t('fSale.unpaid') }}
            </span>
          </div>
          <div class="ov-item">
            <span class="ov-label">{{ $t('fSale.fulfilment') }}</span>
            <span class="ov-value">{{ fulfilmentLabel }}</span>
          </div>
          <div class="ov-item ov-total">
            <span class="ov-label">{{ $t('fSale.total') }}</span>
            <span class="ov-value">{{ priceFormatting(totalPrice) }}</span>
          </div>
        </section>

        <!-- The three things anyone opening an order looks at first. -->
        <div class="order-row">
          <!-- ========================= Customer ========================= -->
          <section class="order-card">
            <div class="order-card-head">
              <i class="fa-solid fa-user" />
              {{ $t('fSale.customer') }}
            </div>
            <div class="order-card-body">
              <div class="customer-head">
                <div class="customer-identity">
                  <b class="customer-name">{{ customer.name || notProvided }}</b>
                  <span class="customer-meta" v-if="customer.id">
                    {{ $t('fSale.customerId') }}: #{{ customer.id }}
                  </span>
                </div>

                <router-link
                  v-if="customer.id"
                  class="outline-btn xs"
                  :to="`/registered-users/${customer.id}`"
                >
                  {{ $t('fSale.viewCustomer') }}
                </router-link>
              </div>

              <dl class="fact-grid mt-15">
                <div class="fact">
                  <dt>{{ $t('profile.email') }}</dt>
                  <dd>{{ customer.email || notProvided }}</dd>
                </div>
                <div class="fact">
                  <dt>{{ $t('profile.phone') }}</dt>
                  <dd>{{ customer.phone || notProvided }}</dd>
                </div>
                <div class="fact">
                  <dt>{{ $t('fSale.accountType') }}</dt>
                  <dd>
                    <span class="account-pill" :class="'is-' + customer.account_type">
                      {{ accountTypeLabel }}
                    </span>
                  </dd>
                </div>
                <!--
                  Named so an admin can tell at a glance why this order was
                  charged what it was, without exposing band ids or any other
                  internal pricing reference.
                -->
                <div class="fact" v-if="pricingLabel">
                  <dt>{{ $t('fSale.pricing') }}</dt>
                  <dd>{{ pricingLabel }}</dd>
                </div>
              </dl>
            </div>
          </section>


          <!-- ========================== Payment ========================== -->
          <section class="order-card">
            <div class="order-card-head">
              <i class="fa-solid fa-credit-card" />
              {{ $t('fSale.pInformation') }}
            </div>
            <div class="order-card-body">
              <div class="summary-row">
                <span>{{ $t('fSale.pMethod') }}</span>
                <span v-if="isVendor">{{ paymentTypes[result.order_method] || $t('prod.na') }}</span>
                <span v-else class="control-cell">
                  <dropdown
                    :selected-key="result.order_method"
                    :options="paymentTypesDrop"
                    :default-null="true"
                    @clicked="orderMethodChanged"
                  />
                </span>
              </div>

              <div class="summary-row">
                <span>{{ $t('fSale.pStatus') }}</span>
                <span v-if="isVendor" :class="paymentDone ? 'color-success' : 'color-danger'">
                  {{ paymentDone ? $t('fSale.paid') : $t('fSale.unpaid') }}
                </span>
                <span v-else class="control-cell">
                  <dropdown
                    :selected-key="result.payment_done"
                    :options="paymentStatus"
                    @changed="selectedPaymentStatus"
                  />
                </span>
              </div>

              <div class="summary-row">
                <span>{{ $t('fSale.pAmount') }}</span>
                <span class="semi-bold">{{ priceFormatting(totalPrice) }}</span>
              </div>

              <!-- Always shown, "N/A" when there is none: a cash order has no
                   gateway reference and must not look as though it has one. -->
              <div class="summary-row">
                <span>{{ $t('title.ti') }}</span>
                <span class="break-any">{{ result.trans_id || $t('prod.na') }}</span>
              </div>

              <!-- Only when the order records one; see paid_at. -->
              <div class="summary-row" v-if="result.paid_on">
                <span>{{ $t('fSale.pDate') }}</span>
                <span>{{ result.paid_on }}</span>
              </div>

              <p v-if="isCashOnDelivery && !paymentDone" class="pay-note">
                <i class="fa-solid fa-circle-info mr-10" />
                {{ isCollection ? $t('fSale.payOnCollection') : $t('fSale.payOnDelivery') }}
              </p>
            </div>
          </section>


          <!-- ========================== Summary ========================== -->
          <section class="order-card summary-card" v-if="!isVendor">
            <div class="order-card-head">
              <i class="fa-solid fa-receipt" />
              {{ $t('fSale.oSummary') }}
            </div>
            <div class="order-card-body">
              <!--
                The goods gross, and what they net down to:

                  Products (Inc-VAT) - Discount = Net Products (Inc-VAT)

                "Subtotal" was in whichever convention each product is priced
                in - this catalogue runs both - so it could not be labelled as
                either, and the same figure meant different things on
                different orders. The discount is the whole saving against the
                list price, which is why the bundle giveaway is inside it
                rather than on a row of its own.
              -->
              <div class="summary-row">
                <span>{{ $t('fSale.productsIncVat') }}</span>
                <span>{{ priceFormatting(subtotalIncVat) }}</span>
              </div>
              <div class="summary-row" v-if="discountIncVat">
                <span>{{ $t('fSale.discount') }}</span>
                <span class="summary-credit">-{{ priceFormatting(discountIncVat) }}</span>
              </div>

              <div class="summary-divider" />

              <!--
                The VAT statement. `vatKnown` is false only for orders placed
                before the treatment was recorded on the line; those say so
                rather than quoting a rate that was never applied to them.

                The VAT is named against the products, not the total: the
                order-wide discounts below come off the gross after VAT was
                charged, so the tax contained in the payable figure is a
                smaller number than this one.
              -->
              <div class="summary-row">
                <span>{{ $t('fSale.netProductsIncVat') }}</span>
                <span>{{ priceFormatting(netProductsIncVat) }}</span>
              </div>
              <div class="summary-row summary-sub">
                <span>
                  {{ $t('fSale.vatIncluded') }}
                  <small v-if="vatRateLabel" class="summary-note">{{ vatRateLabel }}</small>
                  <small v-else-if="!vatKnown" class="summary-note">
                    {{ $t('fSale.vatNotRecorded') }}
                  </small>
                </span>
                <span>{{ priceFormatting(vatAmount) }}</span>
              </div>

              <div class="summary-row">
                <span>{{ $t('fSale.sCost') }}</span>
                <span v-if="isFreeShipping" class="free-badge">{{ $t('ship.fre') }}</span>
                <span v-else>{{ priceFormatting(shippingPrice) }}</span>
              </div>

              <div class="summary-row" v-if="voucherPrice">
                <span>{{ $t('fSale.voucher') }}</span>
                <span class="summary-credit">- {{ priceFormatting(voucherPrice) }}</span>
              </div>
              <div class="summary-row" v-if="flashDiscount">
                <span>{{ $t('fSale.flashDiscount') }}</span>
                <span class="summary-credit">- {{ priceFormatting(flashDiscount) }}</span>
              </div>
              <!--
                The Essentials Bundle saving, distinct from "Bundle offer"
                (the value of what a buy-X-get-Y-free deal gave away).
              -->
              <div class="summary-row" v-if="bundleDiscount">
                <span>{{ $t('fSale.bundleDiscount') }}</span>
                <span class="summary-credit">- {{ priceFormatting(bundleDiscount) }}</span>
              </div>
              <div class="summary-row" v-if="loyaltyDiscount">
                <span>{{ loyaltyLabel }}</span>
                <span class="summary-credit">- {{ priceFormatting(loyaltyDiscount) }}</span>
              </div>
              <div class="summary-row" v-if="pointsDiscount">
                <span>{{ $t('fSale.points') }}</span>
                <span class="summary-credit">- {{ priceFormatting(pointsDiscount) }}</span>
              </div>

              <!--
                One row per rate the order actually charged, when there is more
                than one. A single-rate order says everything it needs to in
                the VAT row above.
              -->
              <template v-if="vatRates.length > 1">
                <div class="summary-divider" />
                <div class="summary-row summary-sub">
                  <span>{{ $t('fSale.vatSummary') }}</span>
                  <span></span>
                </div>
                <div class="summary-row" v-for="(row, i) in vatRates" :key="`rate-${i}`">
                  <span>{{ formatRate(row.rate) }} {{ $t('fSale.vat') }}</span>
                  <span>{{ priceFormatting(row.vat) }}</span>
                </div>
              </template>

              <div class="summary-divider" />

              <!-- The amount actually payable, said unambiguously. -->
              <div class="summary-row summary-total-row">
                <span>{{ $t('fSale.totalIncVat') }}</span>
                <span class="summary-total">{{ priceFormatting(totalPrice) }}</span>
              </div>

              <!--
                A split payment: the wallet part was debited when the order
                was placed; the order's own method is owed the rest. A refund
                goes back card first, then wallet.
              -->
              <template v-if="walletPaid > 0">
                <div class="summary-row">
                  <span>Paid from wallet</span>
                  <span class="summary-credit">- {{ priceFormatting(walletPaid) }}</span>
                </div>
                <div class="summary-row">
                  <span>Due by order method</span>
                  <span>{{ priceFormatting(amountDue) }}</span>
                </div>
              </template>
            </div>
          </section>
        </div>

        <!-- Order items, full width: see .order-full -->
        <!-- ========================= Order items ======================= -->
        <section class="order-card order-full">
          <div class="order-card-head">
            <i class="fa-solid fa-bag-shopping" />
            {{ $t('fSale.oItems', { count: orderedProducts.length }) }}
          </div>

          <!--
            Five columns, not nine.

            Product, fulfilment, quantity, original price, discount, final
            price, tax and line total as eight separate columns needed more
            width than this page has beside its sidebar, so the table
            scrolled sideways on a desktop and cut the line total off the
            right-hand edge - the one figure the row exists to show.

            Nothing has been dropped. The facts that describe the product
            (SKU, variant, fulfilment) live in the product cell, and the
            three prices that describe one unit are one cell: what was
            charged, with what it was before and what came off beneath it.
            The table is fixed-layout at percentage widths, so it cannot
            overflow whatever the content.
          -->
          <div class="order-items-table">
            <table class="no-bg items-table">
              <thead>
                <tr class="lite-bold">
                  <th class="col-product">{{ $t('index.title') }}</th>
                  <th class="num col-qty">{{ $t('fSale.qty') }}</th>
                  <th class="num col-unit">{{ $t('fSale.unitPrice') }}</th>
                  <th class="num col-tax">{{ $t('fSale.tax') }}</th>
                  <th class="num col-total">{{ $t('fSale.lineTotal') }}</th>
                </tr>
              </thead>
              <tbody>
                <ordered-product
                  v-for="(value, index) in orderedProducts"
                  :key="value.id || index"
                  :ordered-product="value"
                />
              </tbody>
            </table>
          </div>

          <div class="order-items-cards">
            <article
              v-for="(item, index) in orderedProducts"
              :key="`card-${item.id || index}`"
              class="item-card"
            >
              <div class="item-card-head">
                <img
                  :src="itemImage(item)"
                  :alt="itemTitle(item)"
                  class="item-card-img"
                  @error="onImageError"
                >
                <div class="item-card-id">
                  <b>{{ itemTitle(item) }}</b>
                  <span v-if="itemSku(item)" class="item-sub">{{ $t('fSale.sku') }}: {{ itemSku(item) }}</span>
                  <span v-if="itemVariant(item)" class="item-sub">{{ itemVariant(item) }}</span>
                  <span class="item-sub">{{ shippingType[item.shipping_type] }}</span>
                </div>
              </div>

              <dl class="item-card-prices">
                <div><dt>{{ $t('fSale.qty') }}</dt><dd>{{ item.quantity }}</dd></div>
                <div><dt>{{ $t('fSale.origUnit') }}</dt><dd>{{ priceFormatting(item.actual_price) }}</dd></div>
                <div v-if="Number(item.line_discount) > 0">
                  <dt>{{ $t('fSale.discount') }}</dt>
                  <dd class="color-success">- {{ priceFormatting(item.line_discount) }}</dd>
                </div>
                <div><dt>{{ $t('fSale.finalUnit') }}</dt><dd>{{ priceFormatting(item.price) }}</dd></div>
                <div v-if="Number(item.tax_total) > 0">
                  <dt>{{ $t('fSale.tax') }}</dt><dd>{{ priceFormatting(item.tax_total) }}</dd>
                </div>
                <div class="item-card-total">
                  <dt>{{ $t('fSale.lineTotal') }}</dt>
                  <dd>{{ priceFormatting(item.line_total) }}</dd>
                </div>
              </dl>
            </article>

            <p v-if="!orderedProducts.length" class="fact-muted p-20">
              {{ $t('fSale.noItems') }}
            </p>
          </div>
        </section>

        <!-- Who the order belongs to, where it is billed and where it goes. -->
        <div class="order-row">
          <!-- ================== Business / Trade account ================= -->
          <!--
            One section, rendered from the account type the order recorded:
            a business order never shows trade-only fields and a trade order
            never shows business-only ones.
          -->
          <section class="order-card" v-if="isBusinessOrder">
            <div class="order-card-head">
              <i class="fa-solid fa-building" />
              {{ isTrade ? $t('fSale.tradeAccount') : $t('fSale.businessAccount') }}
              <span class="head-note">
                {{ customer.from_order_snapshot ? $t('fSale.atCheckout') : $t('fSale.liveProfile') }}
              </span>
            </div>
            <div class="order-card-body">
              <!--
                No account-type row: this card's own heading is "Business
                account" or "Trade account", and the customer card directly
                above it carries the pill. Stating it a third time in the
                same column is noise.
              -->
              <dl class="fact-grid">
                <div class="fact" v-if="customer.company_name">
                  <dt>{{ $t('fSale.company') }}</dt>
                  <dd>{{ customer.company_name }}</dd>
                </div>
                <div class="fact" v-if="customer.vat_number">
                  <dt>{{ $t('fSale.vatNo') }}</dt>
                  <dd>{{ customer.vat_number }}</dd>
                </div>
                <div class="fact" v-if="customer.cro_number">
                  <dt>{{ $t('fSale.croNo') }}</dt>
                  <dd>{{ customer.cro_number }}</dd>
                </div>
                <div class="fact">
                  <dt>{{ $t('fSale.poNo') }}</dt>
                  <dd>
                    <template v-if="result.purchase_order_number">
                      {{ result.purchase_order_number }}
                    </template>
                    <span v-else class="fact-muted">
                      {{ customer.requires_po ? $t('fSale.poRequired') : $t('fSale.poNotRequired') }}
                    </span>
                  </dd>
                </div>

                <!-- Business-only -->
                <template v-if="!isTrade">
                  <div class="fact" v-if="customer.sector">
                    <dt>{{ $t('fSale.sector') }}</dt>
                    <dd>{{ customer.sector }}</dd>
                  </div>
                  <div class="fact">
                    <dt>{{ $t('fSale.creditApp') }}</dt>
                    <dd>{{ customer.credit_application ? $t('util.yes') : $t('util.no') }}</dd>
                  </div>
                </template>

                <!-- Trade-only -->
                <template v-else>
                  <div class="fact" v-if="customer.trade_status">
                    <dt>{{ $t('fSale.tradeStatus') }}</dt>
                    <dd class="capitalise">{{ customer.trade_status }}</dd>
                  </div>
                  <div class="fact" v-if="customer.trade">
                    <dt>{{ $t('fSale.tradeType') }}</dt>
                    <dd>{{ customer.trade }}</dd>
                  </div>
                  <div class="fact" v-if="customer.expected_spend">
                    <dt>{{ $t('fSale.expectedSpend') }}</dt>
                    <dd>{{ customer.expected_spend }}</dd>
                  </div>
                  <div class="fact" v-if="customer.website">
                    <dt>{{ $t('fSale.website') }}</dt>
                    <dd class="break-any">{{ customer.website }}</dd>
                  </div>
                  <div class="fact">
                    <dt>{{ $t('fSale.resaleConfirmed') }}</dt>
                    <dd>{{ customer.resale_confirmed ? $t('util.yes') : $t('util.no') }}</dd>
                  </div>
                </template>
              </dl>
            </div>
          </section>


          <!-- ====================== Billing address ====================== -->
          <section class="order-card">
            <div class="order-card-head">
              <i class="fa-solid fa-file-invoice-dollar" />
              {{ $t('fSale.billingAddr') }}
            </div>
            <div class="order-card-body">
              <address class="addr-block">
                <b v-if="customer.company_name">{{ customer.company_name }}</b>
                <span v-if="billingLines.length">
                  <span v-for="(line, i) in billingLines" :key="`bill-${i}`" class="addr-line">{{ line }}</span>
                </span>
                <span v-else class="fact-muted">{{ notProvided }}</span>
                <span v-if="customer.vat_number" class="addr-line mt-10">
                  {{ $t('fSale.vatNo') }}: {{ customer.vat_number }}
                </span>
              </address>
            </div>
          </section>


          <!-- ======================== Fulfilment ========================= -->
          <!--
            Fulfilment is where the goods go and how; payment is whether the
            money arrived. They were one block headed "Shipping status" that
            in fact showed the order status.
          -->
          <section class="order-card">
            <div class="order-card-head">
              <i class="fa-solid fa-truck-fast" />
              {{ $t('fSale.fulfilment') }}
            </div>
            <div class="order-card-body">
              <dl class="fact-grid">
                <div class="fact">
                  <dt>{{ $t('fSale.method') }}</dt>
                  <dd>{{ fulfilmentLabel }}</dd>
                </div>
                <div class="fact">
                  <dt>{{ isCollection ? $t('fSale.collectionStatus') : $t('fSale.sStatus') }}</dt>
                  <dd :class="'color-' + statusVariant">{{ fulfilmentStatusLabel }}</dd>
                </div>
              </dl>

              <div class="addr-heading mt-15">
                {{ isCollection ? $t('fSale.collectFrom') : $t('fSale.deliveryAddr') }}
              </div>

              <address class="addr-block" v-if="isCollection">
                <span v-if="pickupLines.length">
                  <span v-for="(line, i) in pickupLines" :key="`pick-${i}`" class="addr-line">{{ line }}</span>
                </span>
                <span v-else class="fact-muted">{{ notProvided }}</span>
              </address>

              <address class="addr-block" v-else>
                <b>{{ getDataFromObject(result, 'address.name', '') || notProvided }}</b>
                <span v-if="shippingLines.length">
                  <span v-for="(line, i) in shippingLines" :key="`ship-${i}`" class="addr-line">{{ line }}</span>
                </span>
                <span v-else class="fact-muted">{{ notProvided }}</span>
                <span v-if="getDataFromObject(result, 'address.phone')" class="addr-line">
                  {{ $t('profile.phone') }}: {{ result.address.phone }}
                </span>
              </address>

              <!--
                Shipment tracking (AfterShip). Saving registers the parcel,
                moves the order to On the way and emails the customer once;
                the same details saved again do nothing. See ShipmentTracking.
              -->
              <div v-if="!isVendor && !isCollection" class="shipment-block">
                <div class="ship-head">
                  <span class="addr-heading">{{ $t('fSale.shipTracking') }}</span>
                  <span v-if="shipment" class="ship-pill" :class="'ship-pill-' + shipmentTone">{{ shipment.tag_label }}</span>
                </div>

                <!-- The parcel as AfterShip last reported it. -->
                <div v-if="shipment && !shipmentEditing" class="ship-card">
                  <div class="ship-card-top">
                    <div class="ship-ident">
                      <span class="ship-courier">{{ shipment.courier_name }}</span>
                      <span class="ship-number">
                        {{ shipment.tracking_number }}
                        <button
                          type="button"
                          class="ship-icon-btn"
                          :title="$t('fSale.copyNumber')"
                          :aria-label="$t('fSale.copyNumber')"
                          @click="copyTrackingNumber"
                        >
                          <i class="fa-solid" :class="numberCopied ? 'fa-check' : 'fa-copy'" />
                        </button>
                      </span>
                    </div>
                    <a
                      v-if="shipment.external_url"
                      :href="shipment.external_url"
                      target="_blank"
                      rel="noopener noreferrer"
                      class="ship-track-link"
                    >
                      {{ $t('fSale.openTracking') }}
                      <i class="fa-solid fa-arrow-up-right-from-square" />
                    </a>
                  </div>

                  <p v-if="shipment.subtag_message" class="ship-detail">{{ shipment.subtag_message }}</p>

                  <ul class="ship-meta">
                    <li v-if="shipment.expected_delivery">
                      <i class="fa-regular fa-calendar" />
                      {{ $t('fSale.expectedDelivery') }}: <b>{{ shipmentDate(shipment.expected_delivery) }}</b>
                    </li>
                    <li v-if="shipment.last_synced_at">
                      <i class="fa-regular fa-clock" />
                      {{ $t('fSale.lastUpdate') }}: {{ shipmentDate(shipment.last_synced_at, true) }}
                    </li>
                  </ul>

                  <div class="ship-card-actions">
                    <ajax-button
                      class="outline-btn xs"
                      type="button"
                      :text="$t('fSale.refreshStatus')"
                      :loading-text="$t('profile.updatn')"
                      :fetching-data="shipmentRefreshing"
                      @clicked="refreshShipment"
                    />
                    <button
                      v-if="shipmentAllowed"
                      type="button"
                      class="outline-btn xs"
                      @click="startShipmentEdit"
                    >
                      <i class="fa-solid fa-pen mr-5" />{{ $t('fSale.editShipment') }}
                    </button>
                  </div>
                </div>

                <!-- No scan after 48h, failed attempt, courier problem, expired: staff are also emailed once. -->
                <p v-if="shipment && shipment.attention" class="shipment-attention" role="alert">
                  <i class="fa-solid fa-triangle-exclamation" />
                  <span>{{ shipment.attention.message }}</span>
                </p>
                <p v-if="shipment && shipment.last_error" class="shipment-error">{{ shipment.last_error }}</p>

                <!--
                  Add a shipment, or change one after "Edit". The couriers are
                  the ones activated in the AfterShip account (GET
                  /couriers?active=true); typing filters that list. A courier
                  is added under Settings -> Couriers in AfterShip.
                -->
                <form
                  v-if="shipmentAllowed && (!shipment || shipmentEditing)"
                  class="ship-form"
                  @submit.prevent="saveShipment"
                >
                  <div class="ship-field">
                    <label class="ship-label" for="ship-courier">{{ $t('fSale.courier') }}</label>
                    <div v-outside-click="closeCourierList" class="courier-picker">
                      <i class="fa-solid fa-magnifying-glass courier-picker-icon" />
                      <input
                        id="ship-courier"
                        v-model="courierQuery"
                        class="shipment-input"
                        :class="{ 'has-value': selectedCourier && !courierQuery }"
                        type="text"
                        autocomplete="off"
                        spellcheck="false"
                        role="combobox"
                        :aria-expanded="courierOpen ? 'true' : 'false'"
                        :placeholder="selectedCourier ? selectedCourier.name : $t('fSale.searchCourier')"
                        @focus="openCourierList"
                        @input="courierOpen = true"
                        @keydown.esc="closeCourierList"
                      >
                      <ul v-if="courierOpen" class="courier-list" role="listbox">
                        <li v-if="courierLoading" class="courier-note">{{ $t('fSale.loadingCouriers') }}</li>
                        <li v-else-if="courierError" class="courier-note is-error">{{ courierError }}</li>
                        <template v-else>
                          <li class="courier-group">{{ $t('fSale.activeCouriers') }}</li>
                          <li
                            v-for="c in courierOptions"
                            :key="c.slug"
                            class="courier-option"
                            :class="{ 'is-selected': shipmentForm.courier === c.slug }"
                            role="option"
                            :aria-selected="shipmentForm.courier === c.slug ? 'true' : 'false'"
                            @mousedown.prevent="pickCourier(c)"
                          >
                            <span class="courier-name">{{ c.name }}</span>
                            <span class="courier-meta">{{ c.slug }}<template v-if="c.countries.length"> &middot; {{ c.countries.slice(0, 3).join(', ') }}</template></span>
                          </li>
                          <li v-if="!courierOptions.length" class="courier-note">
                            {{ courierQuery.trim() ? $t('fSale.noCourierMatch') : $t('fSale.noActiveCouriers') }}
                          </li>
                        </template>
                        <li v-if="couriersTotal" class="courier-foot" @mousedown.prevent>
                          {{ $t('fSale.courierSource', { count: couriersTotal }) }}
                          &middot;
                          <a href="#" @click.prevent="loadCouriers(true)">{{ $t('fSale.refreshCouriers') }}</a>
                        </li>
                      </ul>
                    </div>
                  </div>

                  <div class="ship-field">
                    <label class="ship-label" for="ship-number">{{ $t('fSale.trackingNo') }}</label>
                    <input
                      id="ship-number"
                      v-model.trim="shipmentForm.tracking_number"
                      class="shipment-input"
                      type="text"
                      maxlength="64"
                      autocomplete="off"
                      spellcheck="false"
                      required
                    >
                  </div>

                  <!--
                    What the chosen courier needs besides the number (its
                    required_fields in AfterShip): address details come from
                    the order, account number and key are typed here.
                  -->
                  <div v-if="selectedCourier && courierNeedsMore" class="courier-fields">
                    <p v-if="autoFieldNames.length" class="shipment-hint">
                      {{ $t('fSale.fieldsFromOrder', { courier: selectedCourier.name, fields: autoFieldNames.join(', ') }) }}
                    </p>
                    <div
                      v-for="(label, field) in selectedCourier.manual_fields"
                      :key="field"
                      class="ship-field"
                    >
                      <label class="ship-label" :for="'ship-' + field">{{ label }}</label>
                      <input
                        :id="'ship-' + field"
                        v-model.trim="shipmentForm.fields[field]"
                        class="shipment-input"
                        type="text"
                        maxlength="128"
                        autocomplete="off"
                        required
                      >
                    </div>
                  </div>

                  <div class="ship-form-actions">
                    <ajax-button
                      class="primary-btn"
                      type="submit"
                      :text="shipment ? $t('fSale.updateShipment') : $t('fSale.saveShipment')"
                      :loading-text="$t('profile.updatn')"
                      :fetching-data="shipmentSaving"
                    />
                    <button
                      v-if="shipment"
                      type="button"
                      class="outline-btn"
                      @click="cancelShipmentEdit"
                    >{{ $t('fSale.cancelEdit') }}</button>
                  </div>

                  <p v-if="!shipment && !shipmentNotice && !shipmentError" class="shipment-hint">
                    {{ $t('fSale.shipmentHint') }}
                  </p>
                </form>

                <p v-if="!shipmentAllowed && !shipment" class="fact-muted mt-10">{{ $t('fSale.shipConfirmFirst') }}</p>

                <p v-if="shipmentNotice" class="shipment-notice" role="status">
                  <i class="fa-solid fa-circle-check" />
                  <span>{{ shipmentNotice }}</span>
                </p>
                <p v-if="shipmentError" class="shipment-error" role="alert">{{ shipmentError }}</p>
              </div>
            </div>
          </section>
        </div>

        <!-- ========================== Returns ========================== -->
        <section
          v-if="!isVendor && returnsInfo && returnsInfo.returns.length"
          class="order-card order-full"
        >
          <div class="order-card-head">
            <i class="fa-solid fa-rotate-left" />
            Returns
          </div>
          <div class="order-card-body">
            <table class="order-returns-table">
              <tr>
                <th>Return</th><th>Type</th><th>Opened</th><th>Reason</th><th>Units</th><th>Status</th><th>Refund</th>
              </tr>
              <tr v-for="ret in returnsInfo.returns" :key="ret.id">
                <td><nuxt-link class="link" :to="`/returns/${ret.id}`">{{ ret.rma_number }}</nuxt-link></td>
                <td>
                  {{ ret.type === 'exchange' ? 'Exchange' : 'Return' }}
                  <nuxt-link v-if="ret.replacement_order_id" class="link" :to="`/orders/${ret.replacement_order_id}`">(replacement)</nuxt-link>
                </td>
                <td>{{ ret.created_at }}</td>
                <td>{{ ret.reason_label }}</td>
                <td>{{ ret.units }}</td>
                <td>{{ ret.status_label }}</td>
                <td>{{ ret.refund_amount === null ? '—' : priceFormatting(ret.refund_amount) }}</td>
              </tr>
            </table>
          </div>
        </section>

        <!-- ========================== Timeline ========================= -->
        <section class="order-card order-full" v-if="timelineSteps.length">
          <div class="order-card-head">
            <i class="fa-solid fa-clock-rotate-left" />
            {{ $t('fSale.oTimeline') }}
          </div>
          <div class="order-card-body">
            <div class="order-timeline">
              <div
                v-for="step in timelineSteps"
                :key="step.key"
                class="timeline-step"
                :class="'is-' + step.state"
              >
                <span class="timeline-node">
                  <i class="fa-solid" :class="step.state === 'done' ? 'fa-check' : step.icon" />
                </span>
                <span class="timeline-label">{{ step.title }}</span>
                <span class="timeline-sub">{{ step.date || stepStateLabel(step.state) }}</span>
              </div>
            </div>
          </div>
        </section>

        <!-- ======================= Status history ====================== -->
        <section class="order-card order-full">
          <div class="order-card-head">
            <i class="fa-solid fa-list-check" />
            {{ $t('fSale.oHistory') }}
          </div>
          <div class="order-card-body">
            <ol class="status-trail" v-if="statusTrail.length">
              <li v-for="(entry, index) in statusTrail" :key="index">
                <span class="trail-dot" />
                <div class="trail-body">
                  <span class="trail-status">{{ statusTitle(entry.to_status) }}</span>
                  <span class="trail-meta">{{ entry.at }} &middot; {{ trailActor(entry) }}</span>
                  <!-- Admin notes stay behind the admin guard. -->
                  <span class="trail-note" v-if="entry.note">{{ entry.note }}</span>
                </div>
              </li>
            </ol>
            <p v-else class="trail-empty">{{ $t('fSale.noHistory') }}</p>
          </div>
        </section>

        <!--
          Rendered but not shown: the Print invoice action in the page
          header drives it through this ref.
        -->
        <invoice
          v-if="storeData"
          ref="invoice"
          :order="result"
          :hide-button="true"
        />
      </div>
    </template>
  </div>
</template>


<script>

  import Invoice from '~/components/partials/Invoice'
  import Cancellation from '~/components/partials/Cancellation'
  import AjaxButton from '~/components/AjaxButton'
  import Spinner from '~/components/Spinner'
  import Dropdown from '~/components/Dropdown'
  import util from '~/mixin/util'
  import {mapGetters, mapActions} from 'vuex'
  import outsideClick from '~/directives/outside-click'
  import LazyImage from "~/components/LazyImage"
  import OrderedProduct from "../../components/partials/OrderedProduct";
  import ErrorFormatter from "../../components/ErrorFormatter";

  export default {
    name: "order",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        statusChanged: false,
        refunding: false,
        formSubmitting: false,
        openCancellationMessage: false,
        logoBase64: null,
        result: null,
        loading: false,
        copied: false,
        shipmentForm: { courier: '', tracking_number: '', fields: {} },
        // The form is shown to add a shipment, or after "Edit" on an existing one.
        shipmentEditing: false,
        numberCopied: false,
        // The courier picker, fed by AfterShip's courier list (shipmentCouriers()).
        courierQuery: '',
        courierOpen: false,
        courierLoading: false,
        courierError: '',
        courierActive: [],
        couriersTotal: 0,
        couriersFetchedAt: null,
        pickedCourier: null,
        shipmentSaving: false,
        shipmentRefreshing: false,
        shipmentNotice: '',
        shipmentError: '',
        // Return cases and what is still returnable (order-return/order/{id}).
        returnsInfo: null,
        statusIcons: {
          1: 'fa-cart-shopping',
          2: 'fa-circle-check',
          3: 'fa-box',
          4: 'fa-truck-fast',
          5: 'fa-house-circle-check'
        }
      }
    },
    directives: {outsideClick},
    mixins: [util],
    components: {
      ErrorFormatter,
      OrderedProduct,
      LazyImage,
      Dropdown,
      AjaxButton,
      Invoice,
      Cancellation,
      Spinner
    },
    computed: {
      isFreeShipping() {
        return !(parseFloat(this.shippingPrice) > 0)
      },
      /*
       * The customer, decided server-side: the account first, then the contact
       * captured at checkout, then the address. The screen used to read
       * `address.name` and `address.phone` directly, which are empty on every
       * click-and-collect order because nothing is being posted - so a customer
       * whose name and number were on their account showed as "N/A".
       */
      customer() {
        return this.result?.customer || {}
      },
      accountTypeLabel() {
        return {
          business: this.$t('fSale.business'),
          trade: this.$t('fSale.trade'),
          personal: this.$t('fSale.personal'),
          guest: this.$t('fSale.guest')
        }[this.customer.account_type] || this.$t('fSale.personal')
      },
      isBusinessOrder() {
        const type = this.customer.account_type

        return type === 'business' || type === 'trade'
      },
      isTrade() {
        return this.customer.account_type === 'trade'
      },
      /* Every line on an order shares one fulfilment type; 2 is collection. */
      shipment() {
        return this.result?.shipment_tracking || null
      },
      // Same reading as the storefront's tracking pill.
      shipmentTone() {
        const tag = this.shipment?.tag
        if (tag === 'Delivered') return 'good'
        if (tag === 'Exception') return 'bad'
        if (['AttemptFail', 'Expired'].includes(tag)) return 'warn'
        // Wrong or unrecognised courier, no scan after 48h: a problem, not a wait.
        if (this.shipment?.attention) return 'warn'
        if (tag === 'Pending') return 'muted'
        return 'info'
      },
      // Activated couriers while the box is empty, search results once typing.
      courierOptions() {
        const q = this.courierQuery.trim().toLowerCase()
        if (!q) return this.courierActive
        return this.courierActive.filter(c =>
          [c.name, c.other_name, c.slug].some(v => (v || '').toLowerCase().includes(q)))
      },
      /*
       * The courier the form will save: the one picked, or the one already
       * on the shipment (named from the row until the list has loaded).
       */
      selectedCourier() {
        if (this.pickedCourier && this.pickedCourier.slug === this.shipmentForm.courier) {
          return this.pickedCourier
        }
        const slug = this.shipmentForm.courier
        if (!slug) return null
        const known = this.courierActive.find(c => c.slug === slug)
        if (known) return known
        return this.shipment && this.shipment.courier_slug === slug
          ? { slug, name: this.shipment.courier_name, countries: [], auto_fields: {}, manual_fields: {} }
          : null
      },
      courierNeedsMore() {
        const c = this.selectedCourier
        return !!c && (Object.keys(c.auto_fields || {}).length + Object.keys(c.manual_fields || {}).length) > 0
      },
      autoFieldNames() {
        return Object.values(this.selectedCourier?.auto_fields || {})
      },
      /*
       * Confirmed, picked up or on the way, by the status the server last
       * confirmed - the same rule saveShipment() enforces.
       */
      shipmentAllowed() {
        return !this.isVendor && !this.isCollection && !!this.result?.shipment_allowed
      },
      isCollection() {
        return parseInt(this.result?.ordered_products?.[0]?.shipping_type)
          === this.shippingTypeIn.pickup
      },
      fulfilmentLabel() {
        return this.isCollection ? this.$t('fSale.collection') : this.$t('fSale.delivery')
      },
      /*
       * Where the goods have got to, which is not the same question as whether
       * the money has arrived. Both were previously shown under one heading.
       */
      fulfilmentStatusLabel() {
        return this.statusTitle(this.result?.status)
      },
      /*
       * "Not provided" rather than "N/A": the field is empty because nobody
       * gave us the information, which is a different statement from the
       * value being inapplicable. N/A is kept for the transaction id, where it
       * genuinely does not apply.
       */
      notProvided() {
        return this.$t('fSale.notProvided')
      },
      orderedProducts() {
        return this.result?.ordered_products || []
      },
      /*
       * The pickup point, one line per part, so a long address wraps inside
       * the card instead of running as a single string past its edge.
       */
      pickupLines() {
        const place = this.result?.ordered_products?.[0]?.shipping_place

        if (!place) {
          return []
        }

        const lines = [
          [place.pickup_address_line_1, place.pickup_address_line_2].filter(Boolean).join(', '),
          [place.pickup_city, place.pickup_state].filter(Boolean).join(', '),
          place.pickup_zip,
          place.pickup_country
        ].filter(Boolean)

        if (place.pickup_phone) {
          lines.push(`${this.$t('profile.phone')}: ${place.pickup_phone}`)
        }

        return lines
      },
      /* The delivery address, same treatment. */
      shippingLines() {
        const a = this.result?.address

        if (!a) {
          return []
        }

        return [
          [a.address_1, a.address_2].filter(Boolean).join(', '),
          [a.city, this.stateName(a)].filter(Boolean).join(', '),
          a.zip,
          this.countryName(a)
        ].filter(Boolean)
      },
      /*
       * A business account is invoiced at its registered address, which is not
       * necessarily where the goods went. Held on the profile as one free-text
       * block, so it is split on commas rather than re-formatted.
       *
       * A personal order has none, and falls back to the address the order
       * itself carries.
       */
      billingLines() {
        const stored = this.customer.billing_address

        if (stored) {
          return String(stored).split(',').map(part => part.trim()).filter(Boolean)
        }

        return this.shippingLines
      },
      /*
       * Why this order was charged what it was, in words. Deliberately not a
       * band id or any other internal pricing reference.
       */
      pricingLabel() {
        if (this.productDiscount <= 0) {
          return null
        }

        if (this.isTrade) {
          return this.$t('fSale.tradePricing')
        }

        if (this.customer.account_type === 'business') {
          return this.$t('fSale.businessPricing')
        }

        return null
      },
      /*
       * The rate this order's VAT was actually charged at, derived by the
       * server from the two figures the order froze. Suppressed when the lines
       * disagree, because one order-level percentage would then be a fiction.
       */
      /*
       * The order as a VAT document states it. All three come from the server,
       * which split them from what the order recorded; nothing here divides by
       * a rate of its own.
       */
      /*
       * The goods gross, and the saving against their list price. Both come
       * from OrderSummary, split from what the order recorded — the admin
       * screen never re-derives money.
       */
      subtotalIncVat() {
        return Number(this.result?.calculations?.subtotal_inc_vat || 0)
      },
      discountIncVat() {
        return Number(this.result?.calculations?.discount_inc_vat || 0)
      },
      netProductsIncVat() {
        return Number(this.result?.calculations?.inc_vat || 0)
      },
      netExVat() {
        return Number(this.result?.calculations?.ex_vat || 0)
      },
      vatAmount() {
        return Number(this.result?.calculations?.vat || 0)
      },
      vatRates() {
        return this.result?.calculations?.vat_breakdown || []
      },
      /*
       * False only on orders placed before the VAT treatment was recorded on
       * the line. Those state that rather than quoting a rate that was never
       * applied to them.
       */
      vatKnown() {
        return this.orderedProducts.every(item => item.vat_known)
      },
      vatRateLabel() {
        const rates = this.orderedProducts
          .map(item => Number(item.tax_rate || 0))
          .filter(rate => rate > 0)

        if (!rates.length) {
          return null
        }

        const first = rates[0]

        if (rates.some(rate => Math.abs(rate - first) > 0.01)) {
          return null
        }

        return `${this.trimRate(first)}%`
      },
      /*
       * The moves the server will accept from where this order stands. Offering
       * anything else only produces a refusal the admin cannot act on.
       */
      allowedStatuses() {
        const current = parseInt(this.result?.status)
        const options = {}

        // The current status stays selectable so the dropdown can show it.
        options[current] = { title: this.statusTitle(current) }

        /*
         * Built from the allowed list rather than from util.orderStatus, which
         * only maps 1-5. Cancelled is a legal move out of almost every state
         * and was not in that map, so the dropdown could never offer it -
         * cancelling an order from this screen was impossible.
         */
        ;(this.result?.allowed_transitions || []).forEach(key => {
          const k = parseInt(key)

          options[k] = { title: this.statusTitle(k) }
        })

        return options
      },
      statusTrail() {
        return this.result?.status_trail || []
      },
      userEmail(){
        return this.customer.email || this.notProvided
      },
      currencyPosition() {
        return this.setting?.currency_position
      },
      refunded(){
        return parseInt(this.result?.cancellation?.refunded) === this.status.PUBLIC || false
      },
      paymentDone(){
        return parseInt(this.result?.payment_done) === this.status.PUBLIC
      },
      isBackPayment(){
        return parseInt(this.result?.order_method) === this.orderMethodsIn.BANK
      },
      isCashOnDelivery(){
        return parseInt(this.result?.order_method) === this.orderMethodsIn.CASH_ON_DELIVERY
      },
      canStartReturn() {
        return !this.isVendor && this.$can('order', 'edit') && !!this.returnsInfo?.eligibility?.ok &&
          (this.returnsInfo?.lines || []).some(line => line.returnable_quantity > 0)
      },
      cancellationMessage(){
        return this.result?.cancellation || null
      },
      orderCancelled(){
        return (parseInt(this.result?.cancelled) === this.status.PUBLIC)
      },
      totalPrice(){
        return this.result?.calculated.total_price
      },
      voucherPrice(){
        return this.result?.calculated.voucher_price
      },
      flashDiscount(){
        return Number(this.result?.calculated?.flash_discount || 0)
      },
      bundleDiscount(){
        return Number(this.result?.calculated?.bundle_discount || 0)
      },
      loyaltyDiscount(){
        return Number(this.result?.calculated?.loyalty_discount || 0)
      },
      loyaltyLabel(){
        return this.result?.calculated?.loyalty_label || 'Loyalty Discount'
      },
      pointsDiscount(){
        return Number(this.result?.calculated?.points_discount || 0)
      },
      walletPaid(){
        return Number(this.result?.calculated?.wallet_paid || 0)
      },
      amountDue(){
        return Number(this.result?.calculated?.amount_due || 0)
      },
      bundleOffer(){
        return this.result?.calculated.bundle_offer
      },
      shippingPrice(){
        return this.result?.calculated.shipping_price
      },
      taxPrice(){
        return this.result?.calculated.tax
      },
      /*
       * The goods at list price, with the discount that got them to the price
       * charged shown as its own row - the pair the customer approved at
       * checkout. Falls back to the charged figure on an order that carried no
       * discount, so those read exactly as they always have.
       */
      subtotalPrice(){
        return this.productDiscount > 0
          ? this.result?.calculated?.original_subtotal
          : this.result?.calculated?.subtotal
      },
      productDiscount(){
        return Number(this.result?.calculated?.discount || 0)
      },
      id() {
        return this.$route?.params?.id
      },
      currencyIcon() {
        return this.setting?.currency_icon || '$'
      },
      /*
       * util.orderStatus only maps 1-5. Cancelled, returned and refunded are
       * real statuses an order reaches, and reading `.title` straight off an
       * undefined entry threw and took the page down; now they name themselves.
       */
      statusLabel() {
        // A legacy order the old cancellation refund moved to 8 is still a cancelled order.
        if (this.result?.state?.order === 'cancelled') return this.$t('index.orderCan')
        return this.statusTitle(this.result?.status)
      },
      statusVariant() {
        if (this.orderCancelled) return 'danger'
        const s = parseInt(this.result?.status)
        if (s === this.orderStatusIn.DELIVERED) return 'success'
        if (s === this.orderStatusIn.PENDING) return 'warning'
        return 'info'
      },
      /*
       * The progress strip, driven off orders.status alone: a step is `done`
       * only once the order has genuinely passed it, and `active` on the one it
       * is sitting at. Nothing ahead of the order is ever marked complete.
       *
       * The dates come from the audit trail, so each step shows when it
       * actually happened rather than only the first one showing the order
       * date.
       *
       * An order that has left the 1-5 track - returned, refunded - has no
       * position on this strip, so it is not drawn at all rather than drawn
       * with every step ticked.
       */
      timelineSteps() {
        const current = parseInt(this.result?.status) || 1

        if (current > this.orderStatusIn.DELIVERED) {
          return []
        }

        const reachedAt = {}

        this.statusTrail.forEach(entry => {
          if (reachedAt[entry.to_status] === undefined) {
            reachedAt[entry.to_status] = entry.at
          }
        })

        return Object.keys(this.orderStatus).map(k => {
          const key = parseInt(k)
          let state = 'pending'
          if (key < current) state = 'done'
          else if (key === current) state = 'active'
          return {
            key,
            title: this.statusTitle(key),
            icon: this.statusIcons[key] || 'fa-circle',
            state,
            date: reachedAt[key] || null
          }
        })
      },
      ...mapGetters('admin', ['isVendor']),
      ...mapGetters('resource', ['countryList']),
      ...mapGetters('setting', ['storeData', 'setting'])
    },
    methods: {
      async orderMethodChanged(evt){
        this.loading = true
        try {
          const data = await this.setRequest({
            params: {
              order_method: evt.key,
              id: this.id
            },
            api: 'paymentMethod'
          })

          if(data?.order_method) {
            this.result.order_method = data.order_method
          }

        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.loading = false
      },
      async refund(){
        this.refunding = true
        try {
          const data = await this.getById({id: this.result?.cancellation?.id, params: {}, api: 'setOrderRefund'})
          if(data){
            this.result = {...this.result, ...{cancellation: {...this.result.cancellation, ...{refunded: data.refunded}}}}
          }
        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.refunding = false
      },
      outsideClick() {
        this.openCancellationMessage = false
      },
      /*
       * The Print Invoice action lives in the page header, where an admin
       * looks for it, and drives the hidden invoice component in the sidebar.
       * It used to be a button of its own at the bottom of that component,
       * below the summary, where it read as part of the summary card.
       */
      printInvoice() {
        const invoice = this.$refs.invoice

        if (invoice && typeof invoice.generatingPdf === 'function') {
          invoice.generatingPdf()
        }
      },
      /* ---------------------------------------------- order item helpers */
      itemTitle(item) {
        return item?.product?.title || ''
      },
      itemSku(item) {
        return item?.updated_inventory?.sku || ''
      },
      /* "128GB / Black / Very Good" for the mobile card, where a column per
         attribute does not fit. */
      itemVariant(item) {
        return (item?.updated_inventory?.inventory_attributes || [])
          .map(row => row?.attribute_value?.title)
          .filter(Boolean)
          .join(' / ')
      },
      /*
       * products.image carries two conventions: a bare file name from the
       * older uploader and one already prefixed with "uploads/" from the
       * newer. imgSrcUrl ends in /uploads/, so the prefixed form resolved to
       * /uploads/thumb-uploads/... and rendered as a broken image.
       */
      itemImage(item) {
        const image = (item?.product?.image || '').replace(/^\/?uploads\//, '')

        return image ? this.getThumbImageURL(image) : this.getImageURL()
      },
      /* A file no longer on disk shows the store placeholder, not the
         browser's broken-image icon. Guarded so a missing placeholder cannot
         retry forever. */
      onImageError(event) {
        const fallback = this.getImageURL()

        if (event.target.src !== fallback) {
          event.target.src = fallback
        }
      },
      /* A rate as it belongs on screen: 23.00 reads as 23%, 13.50 keeps its half. */
      formatRate(rate) {
        return this.trimRate(rate) + '%'
      },
      /* 23.00 reads as 23; 13.50 keeps its half. */
      trimRate(rate) {
        return String(Number(rate).toFixed(2)).replace(/\.?0+$/, '')
      },
      /* The address's state and country as words, via the resource list. */
      stateName(address) {
        const country = this.countryList?.[address?.country]

        return country?.states?.[address?.state]?.name || address?.state || ''
      },
      countryName(address) {
        return this.countryList?.[address?.country]?.name || address?.country || ''
      },
      stepStateLabel(state) {
        if (state === 'done') return this.$t('fSale.completed')
        if (state === 'active') return this.$t('fSale.inProgress')
        return this.$t('util.pend')
      },
      /*
       * util.orderStatus stops at 5. The terminal three exist and orders reach
       * them, so they are named here rather than rendering as blank.
       */
      statusTitle(status) {
        const key = parseInt(status)

        const terminal = {
          [this.orderStatusIn.CANCELLED]: this.$t('index.orderCan'),
          [this.orderStatusIn.RETURNED]: this.$t('fSale.returned'),
          [this.orderStatusIn.REFUNDED]: this.$t('fSale.refUp')
        }

        return this.getDataFromObject(this.orderStatus[key], 'title', terminal[key] || '')
      },
      /* Who made a status change, for the audit trail. */
      trailActor(entry) {
        if (entry.by === 'admin') {
          return entry.by_name || this.$t('error.admin')
        }
        if (entry.by === 'customer') {
          return this.$t('fSale.byCustomer')
        }
        if (entry.by === 'guest') {
          return this.$t('fSale.byGuest')
        }
        return this.$t('fSale.bySystem')
      },
      async copyOrderId() {
        try {
          await navigator.clipboard.writeText(`#${this.result?.order}`)
          this.copied = true
          setTimeout(() => { this.copied = false }, 1500)
        } catch (e) {
          console.error(e)
        }
      },
      async selectedPaymentStatus(evt){
          this.loading = true
          try {
            const data = await this.setRequest({
              params: {
                payment_done: evt.key,
                id: this.id,
                time_zone: this.timeZone
              },
              api: 'paymentStatus'
            })

            /*
             * Tested for presence, not truth. `if (data?.payment_done)` is
             * false when the order was just marked *unpaid*, so the screen kept
             * showing "Paid" against an order the server had already changed -
             * and the only way to see the real state was to reload.
             *
             * The server answers with the stored value rather than the
             * requested one, so a refused change leaves the screen showing what
             * the order actually says.
             */
            if (data && data.payment_done !== undefined) {
              this.result = {
                ...this.result,
                payment_done: data.payment_done,
                paid_on: data.paid_at || null
              }
            }

            /*
             * A late bank transfer on an order the unpaid-transfer sweep had
             * cancelled puts the order back to Pending. Show that, or the
             * screen reads "Cancelled" and "Paid" together until a reload.
             */
            if (data && data.reinstated) {
              this.result = { ...this.result, status: data.status }
              this.statusChanged = false
            }

          } catch (e) {
            return this.$nuxt.error(e)
          }
          this.loading = false
      },
      selectedStatus(evt){
        this.statusChanged = true
        this.result = { ...this.result, ...{ status: evt.key } }
      },
      async updateStatus(){
        if(!this.statusChanged){
          return false
        }
        this.statusChanged = false
        this.formSubmitting = true
        try {
          await this.setRequest({
            params: {
              status: this.result.status,
              id: this.id
            },
            api: 'updateOrderStatus'
          })
          // Confirming the order opens the shipment form; finishing it closes it.
          this.result = {
            ...this.result,
            shipment_allowed: [2, 3, 4].includes(parseInt(this.result.status))
          }
        } catch (e) {
          return this.$nuxt.error(e)
        }

        this.formSubmitting = false

        try {
          if(this.orderStatusIn.DELIVERED === parseInt(this.result.status)) {
            await this.getById({
              id: this.id,
              params: { },
              api: 'sendStatusUpdateEmail'
            })
          }
        } catch (e) {
          console.error(e)
        }
      },
      generateAddress(obj) {
        if(!obj){
          return this.$t('fSale.noAddr')
        }
        let addArr = []
        addArr.push(obj?.address_1)
        if (obj?.address_2) {
          addArr.push(obj?.address_2)
        }
        addArr.push(obj?.city + '-' + obj?.zip)

        if (this.countryList[obj?.country]) {
          const country = this.countryList[obj?.country]

          if (country.states[obj?.state]) {
            addArr.push(country.states[obj.state]?.name)
          }
          addArr.push(country?.name)
        }
        return  addArr.join(', ')
      },
      /* The shipment section shows its own refusals; the page-top banner would repeat them. */
      clearPageErrors() {
        this.$store.dispatch('ui/setErrors', null)
      },
      startShipmentEdit() {
        this.shipmentNotice = ''
        this.shipmentError = ''
        this.shipmentEditing = true
        if (!this.courierActive.length && !this.courierLoading) {
          this.loadCouriers()
        }
      },
      cancelShipmentEdit() {
        this.shipmentEditing = false
        this.shipmentError = ''
        this.courierQuery = ''
        this.shipmentForm = {
          courier: this.shipment?.courier_slug || '',
          tracking_number: this.shipment?.tracking_number || '',
          fields: {}
        }
      },
      async copyTrackingNumber() {
        try {
          await navigator.clipboard.writeText(this.shipment?.tracking_number || '')
          this.numberCopied = true
          setTimeout(() => { this.numberCopied = false }, 1500)
        } catch (e) {
          // Clipboard blocked (http, permissions): the number is on screen to select.
        }
      },
      /* AfterShip's activated couriers (and the list's size and age); refresh re-reads AfterShip now. */
      async loadCouriers(refresh = false) {
        this.courierLoading = true
        this.courierError = ''
        try {
          const data = await this.getRequest({
            params: refresh ? { refresh: 1 } : {},
            api: 'shipmentCouriers'
          })
          this.courierActive = data?.active || []
          this.couriersTotal = data?.total || 0
          this.couriersFetchedAt = data?.fetched_at || null
        } catch (e) {
          this.courierError = e?.message || 'Could not load couriers from AfterShip.'
        } finally {
          this.courierLoading = false
        }
      },
      openCourierList() {
        this.courierOpen = true
        if (!this.courierActive.length && !this.courierLoading) {
          this.loadCouriers()
        }
      },
      closeCourierList() {
        this.courierOpen = false
      },
      pickCourier(c) {
        this.pickedCourier = c
        this.shipmentForm = { ...this.shipmentForm, courier: c.slug, fields: {} }
        this.courierQuery = ''
        this.courierOpen = false
      },
      shipmentDate(value, withTime = false) {
        const d = value ? new Date(value) : null
        if (!d || isNaN(d.getTime())) return value || ''
        const opts = { day: 'numeric', month: 'short', year: 'numeric' }
        if (withTime) Object.assign(opts, { hour: '2-digit', minute: '2-digit' })
        return d.toLocaleString('en-IE', opts)
      },
      /*
       * Take a shipment answer from the server: the row, and the status the
       * order is now at (saving moves Confirmed / Picked up to On the way).
       */
      applyShipmentResult(data) {
        const status = data.status !== undefined ? data.status : this.result.status
        this.result = {
          ...this.result,
          shipment_tracking: data.shipment,
          status,
          allowed_transitions: data.allowed_transitions || this.result.allowed_transitions,
          shipment_allowed: [2, 3, 4].includes(parseInt(status))
        }
        this.statusChanged = false
        this.shipmentForm = {
          courier: data.shipment?.courier_slug || '',
          tracking_number: data.shipment?.tracking_number || '',
          fields: {}
        }
        this.shipmentEditing = false
      },
      async saveShipment() {
        if (this.shipmentSaving) return
        this.shipmentNotice = ''
        this.shipmentError = ''
        if (!this.shipmentForm.courier) {
          this.shipmentError = this.$t('fSale.chooseCourier')
          return
        }
        this.shipmentSaving = true
        try {
          const data = await this.setRequest({
            params: {
              id: this.id,
              courier: this.shipmentForm.courier,
              tracking_number: this.shipmentForm.tracking_number,
              fields: this.shipmentForm.fields
            },
            api: 'saveShipment'
          })

          // A refusal resolves with the whole envelope (status 201), not a throw.
          if (!data || data.status === 201 || !data.shipment) {
            this.shipmentError = data?.data?.form?.[0] || this.$store.getters['ui/errors']?.form?.[0] || 'AfterShip refused the shipment.'
            this.clearPageErrors()
            return
          }

          this.applyShipmentResult(data)
          this.shipmentNotice = {
            unchanged: this.$t('fSale.shipUnchanged'),
            corrected: this.$t('fSale.shipCorrected'),
            shipped_email_pending: this.$t('fSale.shipEmailPending'),
            shipped: this.$t('fSale.shipSaved'),
            tracking_email: this.$t('fSale.shipSaved')
          }[data.notified] || this.$t('fSale.shipSaved')
        } catch (e) {
          this.shipmentError = e?.message || 'Could not save the shipment.'
        } finally {
          this.shipmentSaving = false
        }
      },
      async refreshShipment() {
        if (this.shipmentRefreshing) return
        this.shipmentNotice = ''
        this.shipmentError = ''
        this.shipmentRefreshing = true
        try {
          const data = await this.setById({ id: this.id, params: {}, api: 'refreshShipment' })

          if (!data || !data.shipment) {
            this.shipmentError = this.$store.getters['ui/errors']?.form?.[0] || 'AfterShip could not be reached.'
            this.clearPageErrors()
            await this.fetchingData()
            return
          }

          this.applyShipmentResult(data)
          this.shipmentNotice = this.$t('fSale.shipRefreshed')
        } catch (e) {
          this.shipmentError = e?.message || 'AfterShip could not be reached.'
        } finally {
          this.shipmentRefreshing = false
        }
      },
      async loadReturns() {
        if (this.isVendor || !this.$can('order', 'view')) return
        try {
          this.returnsInfo = await this.getById({id: this.id, params: {}, api: 'getReturnsForOrder'})
        } catch (e) {
          // The order page stands without it.
          this.returnsInfo = null
        }
      },
      async fetchingData() {
        try {
          this.loading = true
          this.result= Object.assign({}, await this.getById({id: this.id, params: {time_zone: this.timeZone}, api: 'getOrder'}))
          this.shipmentForm = {
            courier: this.result?.shipment_tracking?.courier_slug || '',
            tracking_number: this.result?.shipment_tracking?.tracking_number || '',
            fields: {}
          }
          // Couriers from AfterShip, only where the form is shown.
          if (this.result?.shipment_allowed && !this.isVendor) {
            this.loadCouriers()
          }
          this.loadReturns()
          this.loading = false
        } catch (e) {
          return this.$nuxt.error(e)
        }
      },
      ...mapActions('resource', ['setCountryList']),
      ...mapActions('setting', ['getSetting']),
      ...mapActions('common', ['setRequest', 'getById', 'setById', 'getRequest'])
    },
    async mounted() {
      if (!this.countryList) {
        this.loading = true

        const data = await this.getById({
          params: null,
          id: 'countries',
          api: 'resource'
        })

        this.setCountryList(data)
        this.loading = false
      }

      if (!this.storeData) {
        this.loading = true
        try {
          await this.getSetting()
        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.loading = false
      }
      await this.fetchingData()
    }
  }
</script>

<style scoped>
  .order-returns-table { width: 100%; font-size: 13px; border-collapse: collapse; }
  .order-returns-table th, .order-returns-table td { text-align: left; padding: 8px 6px; border-bottom: 1px solid #f2f4f7; }
  .order-returns-table th { color: #6b7280; font-weight: 600; }
</style>
