  <template>

    <div class="col-lg-4" :class="{ 'has-mobile-bar': mobileBar }">
      <div class="card p-3 summary">
        <!-- the utility classes are gone: .mb-15 sets its margin with
             !important, which beat the head's own compact spacing -->
        <h5 class="summary-head">
          <span class="summary-head-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                 stroke-linecap="round" stroke-linejoin="round">
              <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z" />
              <path d="M3 6h18" />
              <path d="M16 10a4 4 0 0 1-8 0" />
            </svg>
          </span>
          <span class="summary-head-text">
            {{ $t('ui.checkoutSummary.orderSummary') }}
            <small class="summary-head-note">{{ $t('ui.checkoutSummary.allPricesAreIn', { currency: currencyName }) }}</small>
          </span>
        </h5>

        <!--
          The Order Summary, top to bottom:

            ① Products without VAT         0% - zero-rated or margin scheme
            ② Products with VAT included   the VAT is already in the price
            ③ Products with VAT added      ex-VAT price, VAT added at checkout
            Total charges for all products ① + ② + ③, every one inc-VAT
            + Shipping
            - each discount that applies, on its own row
            = Final amount payable

          Which group a line belongs to is the server's answer, not this
          component's: vat_rate and vat_inclusive come from
          CartsController::repriceForCustomer, and so does every line's ex /
          VAT / inc split (Pricing::lineVat: VAT per unit to the cent, times
          the units paid for - the rule the order charges by). productGroups only sorts
          the lines and adds those figures up.

          Nothing is added to ② - its tax is inside the price already. Only
          ③'s VAT is added, and that is the exclusiveTax totalPrice has always
          added, so "Total charges" is literally totalPriceWithOffer +
          exclusiveTax and the final figure is the same totalPrice the order
          is charged.

          The VAT stated is the VAT before the order-wide discounts below,
          because that is how the order records it (OrderSummary::for).
        -->

        <summary-product-groups v-if="!hasStaleLines" :groups="productGroups" />

        <!--
          The moment between a basket being restored and its prices arriving.

          The lines come back from localStorage without any money on them
          (plugins/vuex-persisted.js strips it, because a saved price is a
          claim about a customer that may no longer be true), and the server is
          asked for the real figures straight away. Until they land there is
          nothing honest to print, so the amounts show a dash rather than the
          0.00 they would otherwise compute to.

          It is a flicker, not an error state, and it must never tell the
          customer to do anything: it clears itself.
        -->
        <p v-if="hasStaleLines && !pricesError" class="sum-stale">
          <span class="sum-stale-icon" aria-hidden="true">
            <span class="sum-stale-spin"></span>
          </span>
          <span>{{ $t('ui.checkoutSummary.updatingPrices') }}</span>
        </p>

        <!--
          The refresh failed, so there is no route back to a priced basket
          except asking again. This used to be a swallowed catch and a spinner
          that ran until the tab was closed.
        -->
        <p v-else-if="hasStaleLines" class="sum-stale is-failed">
          <span class="sum-stale-icon" aria-hidden="true">!</span>
          <span>
            {{ pricesError }}
            <button type="button" class="sum-retry" :disabled="pricesPending" @click="retryPrices">
              {{ pricesPending ? $t('ui.checkoutSummary.trying') : $t('ui.checkoutSummary.tryAgain') }}
            </button>
          </span>
        </p>

        <!--
          ① + ② + ③, with the order's VAT stated under it: the part inside
          the ② prices and the part added for ③. The icon carries the full
          VAT working, rate by rate where there is more than one.
        -->
        <div class="sum-charges">
          <div class="sum-row">
            <span class="sum-label">{{ $t('ui.checkoutSummary.totalCharges') }}</span>
            <span class="sum-value">
              <price-format v-if="!hasStaleLines" :price="formatPrice(productsCharge)" :decimals="2" />
              <span v-else class="sum-pending">&mdash;</span>
            </span>
          </div>
          <div v-if="!hasStaleLines && vatTotal > 0.004" class="sum-charges-vat">
            <i18n v-if="vatIncluded > 0.004 && cartPrice.exclusiveTax > 0.004" path="ui.checkoutSummary.includesVatSplit" tag="span">
              <template #vat><price-format :price="formatPrice(vatTotal)" :decimals="2" /></template>
              <template #included><price-format :price="formatPrice(vatIncluded)" :decimals="2" /></template>
              <template #added><price-format :price="formatPrice(cartPrice.exclusiveTax)" :decimals="2" /></template>
            </i18n>
            <i18n v-else path="ui.checkoutSummary.includesVat" tag="span">
              <template #vat><price-format :price="formatPrice(vatTotal)" :decimals="2" /></template>
            </i18n>
            <!--
              A popover rather than a title attribute: it carries amounts
              worth reading, and a native tooltip is unreachable on a touch
              screen.
            -->
            <info-popover
              :title="$t('ui.checkoutSummary.vatTitle')"
              :label="$t('ui.checkoutSummary.vatLabel')"
              :lede="$t('ui.checkoutSummary.vatLede')"
              :rows="vatExplainerRows"
              :note="vatExplainerNote"
            />
          </div>
        </div>

        <!--
          Always stated, because "no shipping row" and "shipping is free" look
          identical and only one of them is reassuring.
        -->
        <div class="sum-row">
          <span class="sum-label">
            {{ $t('checkoutRight.shipping') }}
            <small v-if="isFreeShipping && !shippingUndecided && !isCollectionChoice" class="sum-note">{{ $t('ui.checkoutSummary.standardDelivery') }}</small>
          </span>
          <span class="sum-value">
            <price-format v-if="shippingUndecided" :price="formatPrice(0)" :decimals="2" />
            <span v-else-if="isFreeShipping" class="color-free">
              {{ $t('invent.fre') }}
            </span>
            <price-format v-else :price="formatPrice(cartPrice.shippingPrice)" :decimals="2" />
          </span>
        </div>

        <!--
          Each discount on its own row, and only one that is actually taking
          money off: see discountRows.
        -->
        <div v-if="discountRows.length" class="sum-discounts">
          <div class="sum-discounts-head">{{ $t('ui.checkoutSummary.discounts') }}</div>
          <div v-for="row in discountRows" :key="row.key" class="sum-row">
            <span class="sum-label">
              {{ row.label }}
              <small v-if="row.note" class="sum-note">{{ row.note }}</small>
            </span>
            <span class="sum-value text-success">
              &minus; <price-format :price="formatPrice(row.amount)" :decimals="2" />
            </span>
          </div>
        </div>

        <!-- .sum-total carries the second rule as its own top border -->
        <div class="sum-total">
          <span class="sum-total-label">{{ $t('ui.checkoutSummary.finalPayable') }}</span>
          <span class="sum-total-value">
            <price-format v-if="!hasStaleLines" :price="formatPrice(totalPrice)" :decimals="2" />
            <span v-else class="sum-pending">&mdash;</span>
          </span>
        </div>

        <!--
          Split payment. A payment, not a discount: the total above is what
          the order costs and does not move; these say how it is being paid.
        -->
        <div v-if="walletApplied > 0 && !hasStaleLines" class="sum-split">
          <div class="sum-row">
            <span class="sum-label">{{ $t('ui.checkoutSummary.paidFromWallet') }}</span>
            <span class="sum-value text-success">
              -<price-format :price="formatPrice(walletApplied)" :decimals="2" />
            </span>
          </div>
          <div class="sum-row sum-split-due">
            <span class="sum-label">{{ $t('ui.checkoutSummary.leftToPay') }}</span>
            <span class="sum-value">
              <price-format :price="formatPrice(amountLeftToPay)" :decimals="2" />
            </span>
          </div>
        </div>

        <!--
          The saving against list prices. Information, not a deduction: the
          prices in the groups above are already the reduced ones, so it is
          stated under the total rather than subtracted on the way to it.
        -->
        <div v-if="!hasStaleLines && formatPrice(productsDiscount) > 0" class="sum-saved">
          <i18n path="ui.checkoutSummary.savedOnList" tag="span">
            <template #amount><strong><price-format :price="formatPrice(productsDiscount)" :decimals="2" /></strong></template>
          </i18n>
          <info-popover
            :title="$t('ui.checkoutSummary.savingsTitle')"
            :label="$t('ui.checkoutSummary.savingsLabel')"
            :lede="$t('ui.checkoutSummary.savingsLede')"
            :groups="savingsExplainerGroups"
            :rows="savingsExplainerRows"
            :note="savingsExplainerNote"
          />
        </div>

        <!--
          A member whose basket is short of a group's minimum used to see
          nothing at all: the discount they had been given was invisible until
          the basket happened to cross the line, with no way to know it existed
          or how close they were.
        -->
        <div v-if="loyaltyNudge" class="loyalty-nudge">
          <div class="loyalty-nudge-head">
            <span class="loyalty-nudge-icon">★</span>
            <i18n :path="loyaltyNudge.upgrade ? 'ui.checkoutSummary.nudgeUpgrade' : 'ui.checkoutSummary.nudgeUnlock'" tag="span">
              <template #amount><strong><price-format :price="formatPrice(loyaltyNudge.shortfall)" :decimals="2" /></strong></template>
              <template #label><strong>{{ loyaltyNudge.label }}</strong></template>
            </i18n>
          </div>

          <i18n path="ui.checkoutSummary.nudgeSub" tag="div" class="loyalty-nudge-sub">
            <template #benefit>{{ loyaltyNudge.benefit }}</template>
            <template #threshold><price-format :price="formatPrice(loyaltyNudge.threshold)" :decimals="2" /></template>
            <template #reward><price-format :price="formatPrice(loyaltyNudge.reward)" :decimals="2" /></template>
          </i18n>

          <div
            class="loyalty-nudge-bar"
            role="progressbar"
            :aria-valuenow="Math.round(loyaltyProgress)"
            aria-valuemin="0"
            aria-valuemax="100"
          >
            <span :style="{ width: loyaltyProgress + '%' }"></span>
          </div>
        </div>

        <ajax-button v-if="!hideBtn" class="primary-btn  w-100" type="button" :fetching-data="submitting"
          :loading-text="''" :disabled="disabled" :text="btnText" @clicked="$emit('go-next')" />
        <!--
          Rendered here OR in the phone bar's fold, never both: the pages that
          fill this slot put a voucher form and a live status message in it,
          and two copies would mean two inputs bound to one model and a message
          announced twice. `mobilePanel` is the one that decides, so the slot
          moves rather than duplicating.
        -->
        <slot v-if="!mobilePanel" name="checkout" />

        <!--
          The two <details> disclosures and the mixed-basis notice that
          used to sit here are gone.

          They restated, at full width and with one of them open by
          default, exactly what the info icons beside "You Save" and
          "VAT included" already say — the per-product working of the
          saving, the rate-by-rate VAT table, and the sentence about a
          basket holding both conventions. Three copies of the same
          arithmetic, and the reason a four-row summary filled a
          screen. The detail is now reached the one way: through the ⓘ
          on the row it explains.
        -->

        <!-- SECOND BUTTON -->
        <button class="continue-btn" @click="goToHome">
          {{ $t('ui.checkoutSummary.continueShopping') }}
        </button>

        <hr class="seprate-hr">

        <!-- PAYMENT IMAGE -->
        <div class="payment-icons">
          <img :src="require('~/assets/images/cards.png')" :alt="$t('ui.checkoutSummary.cardsAlt')" />
        </div>

        <!-- SECURE -->
        <div class="secure-box">
          <svg width="11" height="12" viewBox="0 0 11 12">
            <path
              d="M3.375 3.375V4.5H7.125V3.375C7.125 2.33906 6.28594 1.5 5.25 1.5C4.21406 1.5 3.375 2.33906 3.375 3.375V3.375M1.875 4.5V3.375C1.875 1.51172 3.38672 0 5.25 0C7.11328 0 8.625 1.51172 8.625 3.375V4.5H9C9.82734 4.5 10.5 5.17266 10.5 6V10.5C10.5 11.3273 9.82734 12 9 12H1.5C0.672656 12 0 11.3273 0 10.5V6C0 5.17266 0.672656 4.5 1.5 4.5H1.875V4.5"
              fill="#6B7280" />
          </svg>
          {{ $t('ui.checkoutSummary.secureCheckout') }}
        </div>

      </div>

      <!--
        The floating "You're saving €X!" badge that hung under the card
        is gone. It was a fourth statement of the saving, on a basis of
        its own — it folded the voucher, flash and loyalty discounts into
        the product saving — so it agreed with the "You Save" row only on
        a basket that had none of them, and silently contradicted it on
        every basket that did. Each of those discounts has its own row in
        the summary, where the customer can see which is which.
      -->

      <!--
        ===================================================================
        Phone: the same summary as a fixed bar above the tab bar.
        ===================================================================

        On a phone the card above is the last thing on a long page - below
        the lines, the bulk actions, saved-for-later, the cross-sells, the
        delivery address and the payment accordion. The total and the only
        way out of the basket were four screens of scrolling away, and a
        shopper editing quantities at the top could not see what any of it
        cost.

        Opt-in (`mobile-bar`), and only the basket passes it. Checkout,
        shipping and payment each end in their own submit, so a second
        fixed CTA there would be a rival button rather than a shortcut.

        Every figure below is the SAME computed property the card renders -
        totalPrice, cartPrice, the discount getters, hasStaleLines. Nothing
        is recalculated for the small screen and no extra request is made,
        which is the only arrangement under which the bar and the card can
        never disagree about the amount about to be charged. The card is
        hidden rather than duplicated (see .has-mobile-bar below), so the
        total appears once.
      -->
      <!--
        Escape closes it, because a fold opened from the keyboard has to be
        closeable from the keyboard without hunting back to the control.
      -->
      <div
        v-if="mobileBar"
        class="mfs"
        :class="{ 'is-open': mobileOpen }"
        @keydown.esc="mobileOpen = false"
      >

        <!--
          The breakdown, closed by default: the bar exists to stay out of
          the way, and a shopper who wants the working asks for it.
        -->
        <transition
          name="mfs-fold"
          @before-enter="foldBeforeEnter"
          @enter="foldEnter"
          @after-enter="foldAfterEnter"
          @before-leave="foldBeforeLeave"
          @leave="foldLeave"
        >
          <div v-show="mobileOpen" id="mobile-summary-panel" class="mfs-panel">
            <div class="mfs-panel-inner">

              <!-- The card's groups, each opening from its subtotal. -->
              <summary-product-groups v-if="!hasStaleLines" collapsible :groups="productGroups" />

              <div class="sum-charges">
                <div class="sum-row">
                  <span class="sum-label">{{ $t('ui.checkoutSummary.totalCharges') }}</span>
                  <span class="sum-value">
                    <price-format v-if="!hasStaleLines" :price="formatPrice(productsCharge)" :decimals="2" />
                    <span v-else class="sum-pending">&mdash;</span>
                  </span>
                </div>
                <p v-if="!hasStaleLines && vatTotal > 0.004" class="sum-charges-vat">
                  <i18n path="ui.checkoutSummary.includesVat" tag="span">
                    <template #vat><price-format :price="formatPrice(vatTotal)" :decimals="2" /></template>
                  </i18n>
                </p>
              </div>

              <div class="sum-row">
                <span class="sum-label">{{ $t('checkoutRight.shipping') }}</span>
                <span class="sum-value">
                  <price-format v-if="shippingUndecided" :price="formatPrice(0)" :decimals="2" />
                  <span v-else-if="isFreeShipping" class="color-free">{{ $t('invent.fre') }}</span>
                  <price-format v-else :price="formatPrice(cartPrice.shippingPrice)" :decimals="2" />
                </span>
              </div>

              <div v-if="discountRows.length" class="sum-discounts">
                <div class="sum-discounts-head">{{ $t('ui.checkoutSummary.discounts') }}</div>
                <div v-for="row in discountRows" :key="`mfs-${row.key}`" class="sum-row">
                  <span class="sum-label">{{ row.label }}</span>
                  <span class="sum-value text-success">
                    &minus; <price-format :price="formatPrice(row.amount)" :decimals="2" />
                  </span>
                </div>
              </div>

              <template v-if="walletApplied > 0 && !hasStaleLines">
                <div class="sum-row">
                  <span class="sum-label">{{ $t('ui.checkoutSummary.paidFromWallet') }}</span>
                  <span class="sum-value text-success">
                    -<price-format :price="formatPrice(walletApplied)" :decimals="2" />
                  </span>
                </div>
                <div class="sum-row sum-split-due">
                  <span class="sum-label">{{ $t('ui.checkoutSummary.leftToPay') }}</span>
                  <span class="sum-value">
                    <price-format :price="formatPrice(amountLeftToPay)" :decimals="2" />
                  </span>
                </div>
              </template>

              <!-- Prices restored without their money on them, on their way
                   back from the server. Same transient state the card shows,
                   and the same retry. -->
              <p v-if="hasStaleLines && !pricesError" class="sum-stale">
                <span class="sum-stale-icon" aria-hidden="true">
                  <span class="sum-stale-spin"></span>
                </span>
                <span>{{ $t('ui.checkoutSummary.updatingPrices') }}</span>
              </p>

              <p v-else-if="hasStaleLines" class="sum-stale is-failed">
                <span class="sum-stale-icon" aria-hidden="true">!</span>
                <span>
                  {{ pricesError }}
                  <button type="button" class="sum-retry" :disabled="pricesPending" @click="retryPrices">
                    {{ pricesPending ? $t('ui.checkoutSummary.trying') : $t('ui.checkoutSummary.tryAgain') }}
                  </button>
                </span>
              </p>

              <!--
                Whatever the page put beside the desktop button - the voucher
                form on /checkout, the "what is still missing" note on
                /shipping. It is in the fold rather than the bar itself
                because both are a row tall on their own, and a bar that
                stands 200px high is not a bar.
              -->
              <div v-if="mobilePanel && $slots.checkout" class="mfs-slot">
                <slot name="checkout" />
              </div>

              <p class="mfs-secure">
                <svg width="11" height="12" viewBox="0 0 11 12" aria-hidden="true">
                  <path
                    d="M3.375 3.375V4.5H7.125V3.375C7.125 2.33906 6.28594 1.5 5.25 1.5C4.21406 1.5 3.375 2.33906 3.375 3.375V3.375M1.875 4.5V3.375C1.875 1.51172 3.38672 0 5.25 0C7.11328 0 8.625 1.51172 8.625 3.375V4.5H9C9.82734 4.5 10.5 5.17266 10.5 6V10.5C10.5 11.3273 9.82734 12 9 12H1.5C0.672656 12 0 11.3273 0 10.5V6C0 5.17266 0.672656 4.5 1.5 4.5H1.875V4.5"
                    fill="#6B7280" />
                </svg>
                {{ $t('ui.checkoutSummary.secureCheckout') }} &middot; {{ $t('ui.checkoutSummary.allPricesIn', { currency: currencyName }) }}
              </p>

            </div>
          </div>
        </transition>

        <!--
          Measured (see syncBarHeight) rather than assumed: this is what the
          page has to clear at the bottom, and it changes with the nudge
          strip and with the font the device actually renders.
        -->
        <div ref="mobileBarEl" class="mfs-bar">

          <!--
            The one thing in the card worth keeping visible rather than
            folding away: a discount the shopper has been given and is
            about to miss. Without it the nudge would exist on mobile only
            for someone who opened the breakdown.
          -->
          <div v-if="loyaltyNudge" class="mfs-nudge">
            <span class="mfs-nudge-icon" aria-hidden="true">★</span>
            <i18n :path="loyaltyNudge.upgrade ? 'ui.checkoutSummary.nudgeUpgrade' : 'ui.checkoutSummary.nudgeUnlock'" tag="span">
              <template #amount><strong><price-format :price="formatPrice(loyaltyNudge.shortfall)" :decimals="2" /></strong></template>
              <template #label><strong>{{ loyaltyNudge.label }}</strong></template>
            </i18n>
          </div>

          <!--
            The whole row is the disclosure, so the total reads as
            "Final amount payable EUR 924.16, Show price breakdown, collapsed,
            button" - the amount and the action in one stop, rather than a
            chevron a screen reader cannot relate to the figure beside it.
            No aria-label: it would replace the total instead of adding to
            it.
          -->
          <button
            type="button"
            class="mfs-head"
            :aria-expanded="mobileOpen ? 'true' : 'false'"
            aria-controls="mobile-summary-panel"
            @click="mobileOpen = !mobileOpen"
          >
            <span class="mfs-head-label">{{ $t('ui.checkoutSummary.finalPayableShort') }}</span>
            <span class="mfs-head-value">
              <price-format v-if="!hasStaleLines" :price="formatPrice(totalPrice)" :decimals="2" />
              <span v-else class="sum-pending">&mdash;</span>
            </span>
            <span class="mfs-sr">
              {{ mobileOpen ? $t('ui.checkoutSummary.hideBreakdown') : $t('ui.checkoutSummary.showBreakdown') }}
            </span>
            <svg class="mfs-chev" width="18" height="18" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.2" stroke-linecap="round"
                 stroke-linejoin="round" aria-hidden="true">
              <path d="m6 15 6-6 6 6" />
            </svg>
          </button>

          <!--
            The same AjaxButton the card uses, with the same disabled and
            submitting props: it is disabled while the request is in flight,
            so a second tap cannot place a second order, and it is disabled
            whenever the page says checkout is not available - nothing about
            when a basket may be checked out is decided here.
          -->
          <ajax-button
            v-if="mobileCtaText"
            class="primary-btn mfs-cta"
            :class="{ 'is-busy': submitting }"
            type="button"
            color=""
            :fetching-data="submitting"
            :loading-text="''"
            :disabled="disabled"
            :text="mobileCtaText"
            @clicked="$emit('go-next')"
          />
        </div>
      </div>

    </div>

  </template>


<script>
import util from '~/mixin/util'
import { mapGetters } from 'vuex'
import productHelper from "../mixin/productHelper"
import productPriceHelper from "../mixin/productPriceHelper"
import AjaxButton from '~/components/AjaxButton'
import PriceFormat from "./PriceFormat";
import InfoPopover from "~/components/InfoPopover"
import SummaryProductGroups from "~/components/SummaryProductGroups"
import { basketTotals, cents, goodsTotal, isPriced, payable, vatKind } from "~/mixin/checkoutTotals"

export default {
  name: 'CheckoutRight',
  data() {
    return {
      voucher: '',
      /* the phone bar's fold, closed on arrival */
      mobileOpen: false,
      /*
       * True while the bar is the summary rather than the card. Driven by
       * matchMedia on the same 767px the stylesheet uses, because the slot has
       * to MOVE between the two - CSS can hide a copy, it cannot choose which
       * of two copies is the real one.
       */
      onPhone: false,
      /* non-reactive handles for the bottom-clearance plumbing */
      barEnabled: false,
      barObserver: null,
      phoneQuery: null
    }
  },
  watch: {
    /*
     * The page beside this card (the SumUp form) has to show the same figure
     * the shopper is about to be charged, so the total is published from here
     * rather than recomputed there and left to drift.
     */
    totalPrice: {
      immediate: true,
      handler(value) {
        this.$emit('calculated-total', value)
      }
    },
    checkedProduct: {
      handler(val) {
        if (!val.length) {
          // Nothing ticked means nothing to discount, so the opt-in goes
          // with it, or the order would still be told to apply one.
          this.$store.commit('cart/SET_FLASH_DISCOUNT_OPT_IN', false)
        }
      },
      deep: true
    }
  },
  props: {
    pointsDiscount: {
      type: Number,
      default: 0
    },
    /* The part of a split payment taken from the wallet (/checkout only). */
    walletApplied: {
      type: Number,
      default: 0
    },
    checkedProduct: {
      type: Array
    },
    cartProducts: {
      type: Array,
      default: function () {
        return [];
      }
    },
    btnText: {
      type: String,
      default: function () {
        return this.$t('checkoutRight.proceedToCheckout')
      }
    },
    hasShipping: {
      type: Boolean,
      default: false
    },
    /*
     * The delivery choice a page is holding but has not saved yet, keyed by
     * cart id: { shipping_place, shipping_type }. /shipping only writes the
     * choice to the basket when "Proceed to Payment" is pressed, so without
     * this the summary beside the choice priced the saved basket instead and
     * said "Free" under a selected "Send by Post (€32.00)".
     */
    shippingChoice: {
      type: Object,
      default: null
    },
    /*
     * The server's delivery quote for the choice a page is holding
     * (cart/shipping-quote). When a page passes one, it is the delivery
     * charge; undefined means the page has none, and the saved basket is
     * read instead.
     */
    shippingQuote: {
      type: Object,
      default: undefined
    },
    disabled: {
      type: Boolean,
      default: false,
    },
    submitting: {
      type: Boolean,
      default: false,
    },
    hideBtn: {
      type: Boolean,
      default: false,
    },
    /*
     * Render the phone-only fixed bottom summary as well as the card.
     * Opt-in, because only the basket wants it: see the block it renders.
     */
    mobileBar: {
      type: Boolean,
      default: false,
    },
    /*
     * The phone bar's own label, for a page whose card carries no button.
     *
     * /checkout is that page: its summary is `hide-btn`, because the control
     * that actually places the order belongs to the payment gateway and there
     * are nine of them. The bar there is a way BACK to that button, not a
     * second one, so it says what it does and the page decides what happens.
     */
    mobileBtnText: {
      type: String,
      default: '',
    },
    voucherResult: {
      type: Object,
      default: () => ({
        offered: 0
      })
    }
  },
  components: {
    InfoPopover,
    PriceFormat,
    AjaxButton,
    SummaryProductGroups
  },
  computed: {
    flashDiscount() {
      return this.$store.getters['cart/flashDiscountAmount'] || 0
    },
    loyaltyDiscount() {
      return this.$store.state.cart.loyaltyDiscount?.amount || 0
    },
    /*
     * The Essentials Bundle savings, as the server worked them out for THIS
     * basket. Never computed here: the discount only stands while the basket
     * still holds a whole bundle, and the browser is not entitled to decide
     * that -- the order endpoint re-derives it either way.
     */
    bundleDiscounts() {
      return this.$store.state.cart.bundleDiscount?.bundles || []
    },
    bundleDiscount() {
      return Number(this.$store.state.cart.bundleDiscount?.total) || 0
    },
    /*
     * Merchant copy, defaulted server-side. Deliberately not the group's
     * internal admin title.
     */
    loyaltyLabel() {
      return this.$store.state.cart.loyaltyDiscount?.label || this.$t('ui.checkoutSummary.loyaltyDiscount')
    },
    loyaltyBenefit() {
      return this.$store.state.cart.loyaltyDiscount?.benefit || ''
    },
    /*
     * A dated benefit is worth saying out loud; a one-time one is explained by
     * the fact that it is sitting on the order they are about to place.
     */
    loyaltyExpiryNote() {
      const expires = this.$store.state.cart.loyaltyDiscount?.expires_at

      if (!expires) {
        return ''
      }

      const days = Math.ceil((new Date(expires) - Date.now()) / 86400000)

      if (!(days > 0)) {
        return ''
      }

      return days === 1 ? this.$t('ui.checkoutSummary.endsToday') : this.$t('ui.checkoutSummary.daysLeft', { days })
    },
    loyaltyNudge() {
      const next = this.$store.state.cart.loyaltyDiscount?.next

      return next && next.shortfall > 0 ? next : null
    },
    loyaltyProgress() {
      if (!this.loyaltyNudge || !(this.loyaltyNudge.threshold > 0)) {
        return 0
      }

      const reached = this.loyaltyNudge.threshold - this.loyaltyNudge.shortfall

      return Math.max(0, Math.min(100, (reached / this.loyaltyNudge.threshold) * 100))
    },
    /*
     * The VAT rate on the basket, when there is only one.
     *
     * Suppressed for a mixed-rate basket, because one percentage across
     * several rates would be a fiction. The rates come from the server, which
     * read them off each product's own tax rule.
     */
    vatRateLabel() {
      const rates = [...(this.cartPrice.vatRates || [])]

      if (rates.length !== 1) {
        return null
      }

      // One formatter for every rate on the storefront, in the mixin.
      return this.formatVatRate(rates[0]) || null
    },
    /*
     * The bar is asked for AND the viewport is a phone. Both, because
     * `mobileBar` alone is true on a desktop too, where the card is still the
     * summary and still owns the slot.
     */
    mobilePanel() {
      return this.mobileBar && this.onPhone
    },

    /*
     * What the bar's button says, or nothing at all.
     *
     * Not gated on `hideBtn` the way the card's is: hiding the card's button
     * is how /checkout says "the gateway owns the order", not "this page wants
     * no action at the bottom of the screen".
     */
    mobileCtaText() {
      if (this.mobileBtnText) return this.mobileBtnText

      return this.hideBtn ? '' : this.btnText
    },

    /* A page is holding a choice, and the shopper has not made it yet. */
    shippingUndecided() {
      // A page quoting delivery has no price until the server answers yes.
      if (this.shippingQuote !== undefined && !this.shippingQuote?.ok) return true

      if (!this.shippingChoice) return false

      return (this.checkedProduct || []).some(row => {
        const choice = this.shippingChoice[row?.id]
        return !choice || !choice.shipping_type
      })
    },

    /* What the chosen method still has to charge after the wallet part. */
    amountLeftToPay() {
      return Math.max(0, Math.round((this.totalPrice - this.walletApplied) * 100) / 100)
    },

    isFreeShipping() {
      return !(parseFloat(this.cartPrice?.shippingPrice) > 0)
    },
    // Collected, not delivered: "Standard delivery" would be wrong.
    isCollectionChoice() {
      if (this.shippingQuote !== undefined) return parseInt(this.shippingQuote?.type) === 2

      return parseInt((this.checkedProduct || [])[0]?.shipping_type) === 2
    },
    /*
     * "EUR (€)", from the shop's own setting rather than a constant — the
     * storefront is translated into five languages and the currency is
     * configurable, so naming one here would be wrong for anyone who changes
     * it.
     */
    currencyName() {
      const code = this.setting?.currency
      const icon = this.currencyIcon

      if (code && icon) return `${code} (${icon})`

      return code || icon || ''
    },
    /*
     * True when the basket holds both conventions at once. Worth saying out
     * loud: it is the only time the product cards disagree with each other
     * about what a price means, and a customer comparing two lines has no
     * other way to know why.
     */
    hasMixedVatBasis() {
      const bases = new Set(
        (this.checkedProduct || [])
          .filter(row => isPriced(row) && vatKind(row) !== 'none')
          .map(row => vatKind(row))
      )

      return bases.size > 1
    },
    /*
     * The saving against the list price of the goods, gross.
     *
     * Derived from the two figures either side of it rather than summed on its
     * own, so "Products - Discount = Net Products" holds to the cent whatever
     * the lines rounded to. The order-wide discounts are not folded in here;
     * they are separate rows, because the VAT above was charged before them.
     */
    /*
     * True when any line reached this summary without a VAT split at all.
     *
     * See the staleLines counter in cartPrice. It means the page rendered from
     * the persisted store without refreshing — not that the goods are
     * zero-rated, which is a real state with a real split of zero.
     */
    hasStaleLines() {
      return Number(this.cartPrice.staleLines) > 0
    },

    /* The refresh's own state, so a failure is offered a retry rather than
       left spinning. Both are set by cart/getCartByUser. */
    pricesPending() {
      return !!this.$store.state.cart.pricesPending
    },

    pricesError() {
      return this.$store.state.cart.pricesError || null
    },

    /*
     * The goods, added up from the server's per-line split (checkoutTotals).
     *
     * Every line was split server-side on the order's own rule -- VAT per unit
     * rounded to the cent, times the units paid for -- so these sums are the
     * figures the order charges, to the cent. Nothing here applies a rate.
     */
    goods() {
      return basketTotals(this.checkedProduct)
    },

    /*
     * The saving against list prices, gross.
     *
     * Both ends are inc-VAT for every line: the list price split on the same
     * rule as the charge, over the full quantity, against what is charged for
     * the units paid for. So it is one basis throughout - it used to add an
     * ex-VAT line's NET saving (EUR 25.00) to an inc-VAT line's gross one,
     * while the amount actually charged fell by EUR 30.75 for the same line.
     * It is the figure the order confirmation states as its discount
     * (OrderSummary discount_inc_vat), free units included, and it is
     * information only: the prices above are already the reduced ones.
     */
    productsDiscount() {
      return this.goods.saving
    },

    /*
     * Where the saving came from, line by line, gross: the price reduction
     * per unit, and separately any units a bundle gave away. The two add up
     * to the saving above, so the popover's lines and its total agree.
     */
    savingsDetails() {
      return (this.checkedProduct || []).map((row) => {
        if (!isPriced(row)) return null

        const quantity = parseInt(row?.quantity || 0)
        const free = parseInt(row?.free_units || 0)
        const unitFrom = Number(row?.unit_list_inc_vat ?? row?.unit_inc_vat ?? 0)
        const unitTo = Number(row?.unit_inc_vat ?? 0)

        const priceSaving = cents(Math.max(0, unitFrom - unitTo)) * quantity
        const freeValue = cents(unitTo) * free

        if (priceSaving + freeValue <= 0) return null

        return {
          id: row?.id,
          title: row?.flash_product?.title || row?.product?.title || '',
          quantity,
          free,
          unitFrom,
          unitTo,
          priceSaving: priceSaving / 100,
          freeValue: freeValue / 100,
        }
      }).filter(Boolean)
    },

    /*
     * The basket's lines in the summary's three groups, always in this order:
     *
     *   none      no VAT either way - zero-rated or margin scheme
     *   included  the price contains its VAT; nothing is added
     *   added     the price is ex-VAT; VAT is added at checkout
     *
     * Both the classification (vatKind) and every amount are the server's;
     * this only sorts and sums. A flat-amount rule has no percentage but its
     * tax is real, so it is classified by vat_flat as well as vat_rate.
     *
     * A line with no split at all has not been priced yet (see hasStaleLines);
     * it is left out, and the summary shows a dash until it arrives.
     */
    productGroups() {
      const groups = {}

      ;(this.checkedProduct || []).forEach((row) => {
        if (!isPriced(row)) {
          return
        }

        const rate = Number(row.vat_rate)
        const key = vatKind(row)
        const group = groups[key] || (groups[key] = {
          key, lines: [], count: 0, ex: 0, vat: 0, inc: 0, byRate: {},
        })

        const quantity = parseInt(row?.quantity || 0)
        const ex = cents(row?.line_ex_vat)
        const vat = cents(row?.line_vat)
        const inc = cents(row?.line_inc_vat)

        // The line as the product is quoted: net for an ex-VAT product,
        // gross for the others - the figure on its own cart card.
        const amount = key === 'added' ? ex : inc

        /*
         * "EUR 1,000.00 each" only where unit x quantity really is the line. A
         * "buy X get Y free" line is charged for fewer units than it holds,
         * and an "each" beside it would not multiply out.
         */
        const unit = cents(key === 'added' ? row?.unit_ex_vat : row?.unit_inc_vat)
        const showUnit = quantity > 1 && unit * quantity === amount

        const attributes = row?.updated_inventory?.inventory_attributes || []
        const rateLabel = this.vatLabelFor(row)

        group.lines.push({
          id: row?.id,
          title: row?.flash_product?.title || row?.product?.title || '',
          // "256GB • Black • Excellent", as the cart card names the variant
          variant: attributes.map(i => i?.attribute_value?.title).filter(Boolean).join(' • '),
          quantity,
          rateLabel,
          ex: ex / 100,
          vat: vat / 100,
          inc: inc / 100,
          amount: amount / 100,
          unit: showUnit ? unit / 100 : null,
        })

        group.count += quantity
        group.ex += ex
        group.vat += vat
        group.inc += inc

        if (key !== 'none') {
          const rateKey = rate > 0 ? rate.toFixed(2) : `flat-${row.vat_flat}`
          const slot = group.byRate[rateKey] || (group.byRate[rateKey] = {
            rate, rateLabel, vat: 0,
          })

          slot.vat += vat
        }
      })

      return ['none', 'included', 'added']
        .filter(key => groups[key])
        .map((key) => {
          const group = groups[key]

          return {
            key,
            lines: group.lines,
            count: group.count,
            ex: group.ex / 100,
            vat: group.vat / 100,
            inc: group.inc / 100,
            // highest rate first, the way a VAT invoice lists them
            rates: Object.values(group.byRate)
              .map(slot => Object.assign({}, slot, { vat: slot.vat / 100 }))
              .sort((a, b) => b.rate - a.rate),
          }
        })
    },

    /*
     * The goods with their VAT: exactly what the order charges for them before
     * shipping and the order-wide discounts.
     */
    productsCharge() {
      return this.goods.incVat
    },

    /*
     * The order-wide discounts and the amount payable, worked out the way the
     * order works them out (checkoutTotals.payable mirrors
     * CheckoutTotals::chain): each capped by what is still owed after the
     * ones before it. The rows below and the total read the same object, so
     * they cannot disagree with each other -- or with the order.
     */
    totals() {
      return payable({
        goodsIncVat: this.goods.incVat,
        shipping: this.cartPrice.shippingPrice,
        voucher: this.voucherResult?.offered || 0,
        flash: this.flashDiscount,
        bundle: this.bundleDiscount,
        loyalty: this.loyaltyDiscount,
        points: this.pointsDiscount,
        wallet: this.walletApplied,
      })
    },

    /*
     * The order-wide discounts, one row each, in the order they are applied,
     * and only those taking money off: a zero row is a promotion the
     * customer does not have, and listing it reads as one that failed.
     *
     * The loyalty label is the merchant's customer-facing copy, defaulting to
     * "Loyalty Discount" - never the group's internal admin title.
     *
     * One row per Essentials Bundle, named, because it is the one discount a
     * shopper can lose by editing their basket. When the chain caps the
     * bundle saving (an order already taken to zero by the discounts before
     * it), the rows are scaled with it, so they still add up to what is taken.
     */
    discountRows() {
      const totals = this.totals
      const offeredBundles = cents(this.bundleDiscount)
      const grantedBundles = cents(totals.bundle)
      let written = 0

      const bundles = this.bundleDiscounts.map((bundle, index, all) => {
        const share = index === all.length - 1
          ? grantedBundles - written
          : (offeredBundles > 0 ? Math.round(cents(bundle.discount) * grantedBundles / offeredBundles) : 0)

        written += share

        return {
          key: `bundle-${bundle.id}`,
          label: bundle.title || this.$t('ui.checkoutSummary.bundleSaving'),
          note: this.$t('ui.checkoutSummary.bundleNote'),
          amount: share / 100,
        }
      })

      const rows = [
        { key: 'flash', label: this.$t('ui.checkoutSummary.flashDiscount'), amount: totals.flash },
        { key: 'voucher', label: this.$t('ui.checkoutSummary.voucher'), amount: totals.voucher },
        ...bundles,
        {
          key: 'loyalty',
          label: this.loyaltyLabel,
          note: this.loyaltyBenefit ? `${this.loyaltyBenefit}${this.loyaltyExpiryNote}` : '',
          amount: totals.loyalty,
        },
        { key: 'points', label: this.$t('ui.checkoutSummary.cherryPoints'), amount: totals.points },
      ]

      return rows.filter(row => cents(row.amount) > 0)
    },

    /*
     * ---- what the summary's info icons open ---------------------------
     *
     * Each one explains the figure on its own row. Every amount is the one
     * already rendered beside it, so the popover can never disagree with the
     * summary.
     */
    savingsExplainerGroups() {
      return this.savingsDetails.map((row) => {
        const lines = []

        if (row.priceSaving > 0) {
          lines.push(this.$t('ui.checkoutSummary.explain.savingPriceLine', {
            from: this.money(row.unitFrom), to: this.money(row.unitTo), qty: row.quantity,
          }))
        }

        if (row.freeValue > 0) {
          lines.push(this.$t('ui.checkoutSummary.explain.savingFreeLine', {
            count: row.free, amount: this.money(row.freeValue),
          }))
        }

        return {
          name: row.title,
          lines,
          total: this.money(row.priceSaving + row.freeValue),
        }
      })
    },

    savingsExplainerRows() {
      return [
        { label: this.$t('ui.checkoutSummary.explain.totalYouSave'), value: this.money(this.productsDiscount), strong: true },
      ]
    },

    savingsExplainerNote() {
      return this.$t('ui.checkoutSummary.explain.savingsGrossNote')
    },

    /* The VAT already inside Inc-VAT prices. Shown, never added to the total. */
    vatIncluded() {
      return this.goods.vatIncluded
    },

    /* All the VAT on the order, added and included, counted once. */
    vatTotal() {
      return this.goods.vatTotal
    },

    vatExplainerRows() {
      const rows = []

      this.productGroups
        .filter(group => group.key !== 'none')
        .forEach((group) => {
          group.rates.forEach((slot) => {
            rows.push({
              label: this.$t(group.key === 'included'
                ? 'ui.checkoutSummary.explain.vatInsideAt'
                : 'ui.checkoutSummary.explain.vatAddedAt', { rate: slot.rateLabel }),
              value: this.money(slot.vat),
            })
          })
        })

      rows.push({ label: this.$t('ui.checkoutSummary.explain.totalVat'), value: this.money(this.goods.vatTotal), strong: true })
      rows.push({ label: this.$t('ui.checkoutSummary.explain.netExVat'), value: this.money(this.goods.exVat) })
      rows.push({ label: this.$t('ui.checkoutSummary.explain.totalIncVat'), value: this.money(this.goods.incVat) })

      return rows
    },

    vatExplainerNote() {
      return this.$t('ui.checkoutSummary.explain.vatOnceNote')
    },

    /* The amount payable: the goods with their VAT, shipping, less the discounts. */
    totalPrice() {
      return this.totals.total
    },

    cartPrice() {
      let cp = {
        totalItems: 0,
        // goods before any VAT is added (net on ex-VAT lines, gross otherwise)
        totalPriceWithOffer: 0,
        // the amount payable, after shipping and every order-wide discount
        totalPrice: 0,
        finalPayable: 0,
        exVat: 0,
        vat: 0,
        incVat: 0,
        listIncVat: 0,
        vatRates: new Set(),
        // the VAT added to ex-VAT lines - the only VAT that moves the total
        exclusiveTax: 0,
        shippingPrice: 0,
        voucher: 0,
        // lines that arrived with no VAT split at all - see hasStaleLines
        staleLines: 0
      }

      /*
       * Delivery is charged once per order (2026-09-28), from the shipping
       * row the server decided for the address - never summed per product,
       * and never a row this page picked. Same figure OrdersController takes.
       */
      if (this.hasShipping) {
        if (this.shippingQuote !== undefined) {
          cp.shippingPrice = this.shippingQuote?.ok ? parseFloat(this.shippingQuote.price || 0) : 0
        } else {
          const shipped = this.checkedProduct.find(line => line?.shipping_place)

          if (shipped) {
            const place = shipped.shipping_place

            cp.shippingPrice = parseFloat(
              (parseInt(shipped.shipping_type) === 2 ? place.pickup_price : place.price) || 0
            )
          }
        }
      }

      const goods = basketTotals(this.checkedProduct)

      ;(this.checkedProduct || []).forEach((curr) => {
        cp.totalItems += parseInt(curr?.quantity || 0)

        if (Number(curr?.vat_rate) > 0) {
          cp.vatRates.add(Number(curr.vat_rate))
        }
      })

      cp.totalPriceWithOffer = goodsTotal(this.checkedProduct)
      cp.exVat = goods.exVat
      cp.vat = goods.vatTotal
      cp.incVat = goods.incVat
      cp.listIncVat = goods.listIncVat
      cp.exclusiveTax = goods.vatAdded
      cp.staleLines = goods.stale
      cp.voucher = this.voucherResult?.offered || 0

      const discounts = {
        goodsIncVat: goods.incVat,
        shipping: cp.shippingPrice,
        voucher: cp.voucher,
        flash: this.flashDiscount,
        bundle: this.bundleDiscount,
        loyalty: this.loyaltyDiscount,
      }

      /*
       * What a page hands PaymentGateways as its total-price: every discount
       * except points, which the gateway panel takes off itself (it owns the
       * points toggle) - so passing the figure after points would take them
       * twice. The Essentials Bundle discount is in it: checkout.vue once
       * built this figure on its own and left the bundle out, and SumUp was
       * then asked for more than the order came to.
       */
      cp.totalPrice = payable(discounts).total

      // The summary's own Final amount payable, points included.
      cp.finalPayable = payable({ ...discounts, points: this.pointsDiscount }).total

      this.$emit('calculated-price', cp)
      return cp
    },
    ...mapGetters('common', ['currencyIcon', 'setting', 'currencyPosition']),
  },
  mixins: [util, productHelper, productPriceHelper],
  methods: {
    /*
     * The same string <price-format :decimals="2"> renders, as plain text —
     * the popovers put their figures in a <dd> rather than a component, and
     * still take currency symbol, position and separators from the settings.
     */
    money(value) {
      return this.priceFormat(this.currencyPosition, this.currencyIcon, value, this.setting, 2)
    },

    /* "23%", or "flat €5.00" for a flat-amount rule, from the line's own figures. */
    vatLabelFor(row) {
      if (Number(row?.vat_rate) > 0) return this.formatVatRate(row.vat_rate)

      if (Number(row?.vat_flat) > 0) {
        return this.$t('ui.checkoutSummary.flatRate', { amount: this.money(row.vat_flat) })
      }

      return ''
    },

    /*
     * Ask the server for the basket's prices again.
     *
     * The rejection is caught rather than propagated: getCartByUser has already
     * recorded the reason in the store, which is what this button is rendered
     * from, and an unhandled rejection here would replace the page with Nuxt's
     * error screen over a retryable failure.
     */
    retryPrices() {
      this.$store.dispatch('cart/getCartByUser', {
        lang: this.$store.getters['language/langCode'] || null,
      }).catch(() => {})
    },

    goToHome() {
      this.$router.push('/categories')
    },

    /*
     * ---- the phone bar ------------------------------------------------
     *
     * Presentation only. Nothing here decides a price, a discount or
     * whether the basket may be checked out; the bar renders the same
     * computed properties the card does and emits the same `go-next`.
     */

    /*
     * The page has to end above the bar, and the bar is not a fixed height:
     * it grows with the loyalty nudge and with whatever text size the device
     * is actually set to. So it is measured and published as a custom
     * property, which assets/styles/mobile-chrome.styl adds to the tab bar's
     * own 64px for body.has-mobile-summary.
     *
     * Hard-coding a number here is what leaves the last cart line, or the
     * payment accordion, permanently under the bar on one phone and a band
     * of dead space on another.
     */
    syncBarHeight() {
      if (typeof document === 'undefined') {
        return
      }

      const el = this.$refs.mobileBarEl
      // display:none above 767px, so this is 0 on a desktop and the rule
      // that reads it is behind the same breakpoint anyway.
      const height = el ? Math.ceil(el.getBoundingClientRect().height) : 0

      document.documentElement.style.setProperty('--mobile-summary-h', `${height}px`)
    },

    /*
     * Follow the stylesheet's own breakpoint rather than a resize handler and
     * a number kept in step by hand: matchMedia fires only when the answer
     * changes, so rotating a phone moves the slot once instead of on every
     * intermediate width.
     */
    watchPhone() {
      if (typeof window === 'undefined' || !window.matchMedia) {
        return
      }

      this.phoneQuery = window.matchMedia('(max-width: 767px)')
      this.onPhone = this.phoneQuery.matches

      // addEventListener is the current spell; addListener is what Safari
      // before 14 understands, and this runs on a lot of old phones.
      if (this.phoneQuery.addEventListener) {
        this.phoneQuery.addEventListener('change', this.onPhoneChange)
      } else {
        this.phoneQuery.addListener(this.onPhoneChange)
      }
    },

    onPhoneChange(event) {
      this.onPhone = event.matches
    },

    unwatchPhone() {
      if (!this.phoneQuery) return

      if (this.phoneQuery.removeEventListener) {
        this.phoneQuery.removeEventListener('change', this.onPhoneChange)
      } else {
        this.phoneQuery.removeListener(this.onPhoneChange)
      }

      this.phoneQuery = null
    },

    enableMobileBar() {
      if (typeof document === 'undefined' || this.barEnabled) {
        return
      }

      this.barEnabled = true
      this.watchPhone()
      document.body.classList.add('has-mobile-summary')

      this.$nextTick(() => {
        this.syncBarHeight()

        // The nudge appearing, a discount row arriving, the phone rotating:
        // all change the bar's height without a resize event.
        if (typeof ResizeObserver !== 'undefined' && this.$refs.mobileBarEl) {
          this.barObserver = new ResizeObserver(() => this.syncBarHeight())
          this.barObserver.observe(this.$refs.mobileBarEl)
        }
      })

      window.addEventListener('resize', this.syncBarHeight)
      window.addEventListener('orientationchange', this.syncBarHeight)
    },

    /*
     * Leaving the basket must take the clearance with it, or every page the
     * shopper opens next carries a band of empty space at the bottom.
     */
    disableMobileBar() {
      if (typeof document === 'undefined' || !this.barEnabled) {
        return
      }

      this.barEnabled = false
      this.unwatchPhone()
      document.body.classList.remove('has-mobile-summary')
      document.documentElement.style.removeProperty('--mobile-summary-h')

      if (this.barObserver) {
        this.barObserver.disconnect()
        this.barObserver = null
      }

      window.removeEventListener('resize', this.syncBarHeight)
      window.removeEventListener('orientationchange', this.syncBarHeight)
    },

    /*
     * The fold, animated over its own measured height rather than a guessed
     * max-height: a guess either snaps (too small) or eases through empty
     * space (too large), and the breakdown is a different height on every
     * basket.
     *
     * Once open the cap becomes a share of the viewport with its own
     * scrolling, so a basket with four discount rows is still readable on a
     * short phone, and the panel can grow with the basket while it is open.
     */
    foldBeforeEnter(el) {
      el.style.maxHeight = '0px'
      el.style.overflowY = 'hidden'
    },

    foldEnter(el) {
      el.style.maxHeight = `${el.scrollHeight}px`
    },

    /*
     * Handing the cap back to the stylesheet, so a phone held sideways can
     * have a shorter one (see the landscape block). An inline max-height set
     * here would outrank that media query and the fold would fill the screen.
     * Clearing overflow with it lets the panel grow as the basket changes
     * while it is open, up to the cap, which is where scrolling takes over.
     */
    foldAfterEnter(el) {
      el.style.maxHeight = ''
      el.style.overflowY = ''
    },

    /* offsetHeight, not scrollHeight: once open the panel may be scrolling
       inside a cap, and collapsing from the taller content height would jump
       the bar up before it came down. */
    foldBeforeLeave(el) {
      el.style.maxHeight = `${el.offsetHeight}px`
      el.style.overflowY = 'hidden'
    },

    foldLeave(el) {
      // force the browser to take the height above before it is replaced
      void el.offsetHeight
      el.style.maxHeight = '0px'
    },
  },
  created() {
  },
  mounted() {
    if (this.mobileBar) {
      this.enableMobileBar()
    }
  },
  beforeDestroy() {
    this.disableMobileBar()
  }
}
</script>
<style scoped>
/* ===========================================================================
   Order Summary.

   One flow, in the order a customer reads it:

     ① without VAT + ② VAT included + ③ VAT added = Total charges
     Total charges + shipping - each discount      = Final amount payable

   Each group closes on its inc-VAT subtotal, so the three add up without a
   net figure ever being put next to a gross one. The groups themselves are
   SummaryProductGroups.vue; the full VAT working is behind the info icon
   under Total charges.

   Sizes are on the same 2/4/6/8/10/12px step as the cart line, so the two
   columns read as one page rather than two panels that happen to sit side by
   side.
   =========================================================================== */
.summary {
  position: sticky;
  top: 12px;
  /* the column stretches to the height of the basket beside it, and .card is a
     flex column — without this the summary was drawn as tall as the left-hand
     list, with the trust block pushed to the bottom of a mostly empty panel */
  align-self: flex-start;
  padding: 13px 14px 14px !important;
  border: 1px solid #E3E8F0;
  border-radius: 10px;
  background: #fff;
  font-variant-numeric: tabular-nums;
}

/* --- head ----------------------------------------------------------------- */
.summary-head {
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 0 0 8px;
  padding: 0 0 10px;
  border-bottom: 1px solid #E3E8F0;
  font-size: 21px;
  font-weight: 800;
  letter-spacing: -.022em;
  line-height: 1.15;
  color: #0F2547;
}

.summary-head-icon {
  flex: 0 0 auto;
  display: inline-flex;
  width: 30px;
  height: 30px;
  color: #1A56DB;
}

.summary-head-icon svg {
  width: 100%;
  height: 100%;
}

.summary-head-text {
  min-width: 0;
}

.summary-head-note {
  display: block;
  margin-top: 1px;
  font-size: 12.5px;
  font-weight: 400;
  letter-spacing: 0;
  color: #6B7C93;
}

/* --- rows ----------------------------------------------------------------- */
.sum-row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 14px;
  padding: 5px 0;
}

/* a figure we decline to state, rather than a zero we cannot support */
/* a split payment, under the total it divides */
.sum-split {
  margin-top: 8px;
  padding: 6px 12px;
  border: 1px dashed #D9DCEA;
  border-radius: 10px;
}

.sum-split-due .sum-label,
.sum-split-due .sum-value {
  font-weight: 700;
  color: #130E2B;
}

.sum-pending {
  color: #8C9AB0;
}

/* A transient state, so it is quiet: grey and small, not an amber warning.
   Nothing is wrong and there is nothing for the customer to do. */
.sum-stale {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 2px 0;
  padding: 2px 0;
  font-size: 12.5px;
  line-height: 1.4;
  color: #6B7C93;
}

.sum-stale-icon {
  flex: 0 0 auto;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 13px;
  height: 13px;
}

.sum-stale-spin {
  width: 11px;
  height: 11px;
  border: 1.6px solid #D6DEEA;
  border-top-color: #1A56DB;
  border-radius: 50%;
  animation: sum-stale-turn .7s linear infinite;
}

@keyframes sum-stale-turn {
  to { transform: rotate(360deg); }
}

/* a spinner that never spins is better than one that distracts */
@media (prefers-reduced-motion: reduce) {
  .sum-stale-spin { animation: none; }
}

/* A failure is not a flicker, so it is allowed to look like something. */
.sum-stale.is-failed {
  align-items: flex-start;
  padding: 8px 10px;
  border-radius: 8px;
  background: #FFF7ED;
  color: #9A3412;
}

.sum-stale.is-failed .sum-stale-icon {
  width: 16px;
  height: 16px;
  margin-top: 1px;
  border: 1.4px solid currentColor;
  border-radius: 50%;
  font-size: 10px;
  font-weight: 700;
  line-height: 1;
}

.sum-retry {
  /* the same base.styl rule: without this the inline "try again" link was a
     50px-tall button, which pushed its row from 40px to 66px. `font: inherit`
     below already resets the line-height; only the height was left. */
  height: auto;
  margin-left: 6px;
  padding: 0;
  border: 0;
  background: none;
  color: inherit;
  font: inherit;
  font-weight: 700;
  text-decoration: underline;
  cursor: pointer;
}

.sum-retry:disabled {
  opacity: .6;
  cursor: default;
}

.sum-label {
  /* the value beside it is nowrap, so the label is what has to give when the
     column is narrow — without this the row pushed the page sideways */
  min-width: 0;
  font-size: 14.5px;
  font-weight: 600;
  line-height: 1.35;
  color: #0F2547;
  overflow-wrap: anywhere;
}

.sum-label .sum-note {
  display: block;
  margin-top: 1px;
  font-size: 12.5px;
  font-weight: 400;
  line-height: 1.35;
  color: #6B7C93;
}

.sum-value {
  flex: 0 0 auto;
  font-size: 15px;
  font-weight: 700;
  letter-spacing: -.01em;
  line-height: 1.35;
  white-space: nowrap;
  color: #0F2547;
}

.sum-value.text-success,
.sum-value .color-free {
  color: #16A34A !important;
}

.sum-rule {
  height: 1px;
  margin: 6px 0;
  background: #E3E8F0;
}

/* the trigger circle now lives in InfoPopover, with the panel it opens */

.sum-row-sub {
  padding: 2px 0 5px;
}

.sum-row-sub .sum-label,
.sum-row-sub .sum-value {
  font-size: 13px;
  font-weight: 400;
  color: #6B7C93;
}

/* --- total charges, discounts, saving ---------------------------------- */
/* the sum of the three groups: ruled off from them, and set a step heavier */
.sum-charges {
  margin: 6px 0 2px;
  padding: 4px 0 6px;
  border-bottom: 1px solid #E3E8F0;
}

.sum-charges .sum-label,
.sum-charges .sum-value {
  font-size: 15.5px;
  font-weight: 800;
}

.sum-charges-vat {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 4px;
  margin: -2px 0 0;
  font-size: 12.5px;
  line-height: 1.4;
  color: #6B7C93;
}

.sum-discounts {
  margin-top: 2px;
}

.sum-discounts-head {
  padding-top: 4px;
  font-size: 12.5px;
  font-weight: 800;
  letter-spacing: .04em;
  text-transform: uppercase;
  color: #0F2547;
}

.sum-discounts .sum-row {
  padding-inline-start: 10px;
}

.sum-saved {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 4px;
  margin: 0 0 10px;
  font-size: 13px;
  line-height: 1.4;
  color: #15803D;
}

/* --- total ---------------------------------------------------------------- */
/* a tinted band rather than another ruled row: it is the one figure the
   shopper is being asked to agree to */
.sum-total {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin: 8px 0 10px;
  padding: 10px 12px;
  border: 0;
  border-radius: 8px;
  background: #EFF5FF;
}

.sum-total-label {
  font-size: 17px;
  font-weight: 700;
  color: #0F2547;
}

.sum-total-value {
  font-size: 22px;
  font-weight: 800;
  letter-spacing: -.025em;
  white-space: nowrap;
  color: #0F2547;
}

/* --- primary action ------------------------------------------------------- */
.summary ::v-deep .primary-btn {
  width: 100%;
  height: 46px;
  min-height: 46px;
  padding: 0 14px;
  border: 0;
  border-radius: 8px;
  background: #1A56DB;
  color: #fff;
  font-size: 15px;
  font-weight: 700;
  line-height: 1;
}

.summary ::v-deep .primary-btn:hover:not(:disabled) {
  background: #1544B0;
}

.summary ::v-deep .primary-btn:disabled {
  opacity: .55;
}

/* --- loyalty nudge -------------------------------------------------------- */
/*
  A member whose basket is short of a group's minimum used to see nothing at
  all: the discount they had been given was invisible until the basket happened
  to cross the line.
*/
.loyalty-nudge {
  margin: 0 0 10px;
  padding: 9px 11px;
  border: 1px solid #FCE6BF;
  border-radius: 8px;
  background: #FFFBF3;
}

.loyalty-nudge-head {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  font-size: 13px;
  line-height: 1.4;
  color: #7A4E07;
}

.loyalty-nudge-icon {
  flex: 0 0 auto;
  color: #D9880B;
}

.loyalty-nudge-sub {
  margin-top: 3px;
  font-size: 12.5px;
  line-height: 1.4;
  color: #9A7333;
}

.loyalty-nudge-bar {
  margin-top: 7px;
  height: 5px;
  border-radius: 999px;
  background: #F4E3C4;
  overflow: hidden;
}

.loyalty-nudge-bar span {
  display: block;
  height: 100%;
  border-radius: 999px;
  background: #D9880B;
  transition: width .3s;
}

/* --- secondary trust block ------------------------------------------------ */
/* deliberately quiet: the payment marks and the secure-checkout line are
   reassurance, not part of the arithmetic */
.seprate-hr {
  margin: 10px 0;
  border: 0;
  border-top: 1px solid #E3E8F0;
  opacity: 1;
}

/*
   base.styl puts height:50px and line-height:48px on EVERY <button>
   (btn-height = 50px). This button declares its own height but never its own
   line-height, so its label was laid out on a 48px line inside a 40px box - an
   8px overflow, which is the cramped look. .primary-btn directly above it does
   not have the problem only because it happens to declare line-height:1.

   Centred with flex rather than by matching line-height to height, so the
   label stays centred whatever the font metrics are.
*/
.continue-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 40px;
  margin-top: 8px;
  padding: 0 14px;
  line-height: 1;
  border: 1px solid #D6DEEA;
  border-radius: 8px;
  background: #fff;
  font-size: 14px;
  font-weight: 600;
  color: #33486A;
  cursor: pointer;
  transition: border-color .15s, color .15s, background .15s;
}

.continue-btn:hover {
  border-color: #1A56DB;
  background: #EFF5FF;
  color: #1A56DB;
}

.payment-icons {
  display: flex;
  justify-content: center;
}

.payment-icons img {
  max-width: 100%;
  height: 22px;
  object-fit: contain;
}

.secure-box {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  margin-top: 8px;
  font-size: 12.5px;
  color: #6B7C93;
}


/* ===========================================================================
   Phone: fixed bottom summary.

   Stacking, bottom up:

     the page            (cleared by body.has-mobile-summary, below)
     the tab bar         fixed, bottom 0, z-index 45  (MobileTabBar.vue)
     this bar            fixed, bottom 64px + inset, z-index 46

   64px is the tab bar's height, the same constant the product page's
   add-to-cart dock sits on (assets/styles/mobile-chrome.styl). The safe-area
   inset is added on top of it, not instead of it: on a notched iPhone the
   tabs are 64px of chrome ABOVE the home indicator, so a bar placed at the
   inset alone would sit across them.
   =========================================================================== */
.mfs {
  display: none;
}

/* Every price in here is <price-format>, which renders the shop's own
   currency and separators; tabular figures stop the total jittering as
   quantities change. */
.mfs-panel,
.mfs-bar {
  font-variant-numeric: tabular-nums;
}

.mfs-bar {
  padding: 8px 14px 10px;
  background: #fff;
}

/* The disclosure. A row rather than a chevron on its own: the amount is the
   thing being tapped, and 46px of it is a comfortable target. */
.mfs-head {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  min-height: 46px;
  height: auto;
  padding: 4px 2px;
  border: 0;
  border-radius: 8px;
  background: none;
  line-height: 1.2;
  text-align: left;
  cursor: pointer;
}

.mfs-head-label {
  font-size: 14.5px;
  font-weight: 600;
  color: #33486A;
}

.mfs-head-value {
  margin-left: auto;
  font-size: 21px;
  font-weight: 800;
  letter-spacing: -.025em;
  white-space: nowrap;
  color: #0F2547;
}

.mfs-chev {
  flex: 0 0 auto;
  color: #6B7C93;
  transform: rotate(180deg);
  transition: transform .2s ease;
}

.mfs.is-open .mfs-chev {
  transform: rotate(0deg);
}

.mfs-head:focus-visible {
  outline: 2px solid #1A56DB;
  outline-offset: 2px;
}

/* Announced, never drawn. */
.mfs-sr {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}

/* --- the CTA -------------------------------------------------------------- */
/*
  base.styl puts height:50px AND line-height:50px on every <button>, so a
  height set here without a line-height to match leaves the label off-centre.
  Both are declared, and the flex centring makes it independent of the font's
  own metrics.
*/
.mfs ::v-deep .primary-btn.mfs-cta {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  width: 100%;
  height: 50px;
  min-height: 50px;
  margin-top: 2px;
  padding: 0 16px;
  border: 0;
  border-radius: 10px;
  background: #1A56DB;
  color: #fff;
  font-size: 16px;
  font-weight: 700;
  line-height: 1;
}

.mfs ::v-deep .primary-btn.mfs-cta:hover:not(:disabled) {
  background: #1544B0;
}

.mfs ::v-deep .primary-btn.mfs-cta:focus-visible {
  outline: 2px solid #0F2547;
  outline-offset: 2px;
}

/* Visibly unavailable rather than merely inert: a button that looks live and
   does nothing reads as a broken page. The label stays legible against the
   grey - a disabled control is exempt from the contrast minimum, but the
   shopper still has to be able to read what is unavailable. */
.mfs ::v-deep .primary-btn.mfs-cta:disabled {
  background: #C3CEDE;
  color: #4F6180;
  opacity: 1;
  cursor: not-allowed;
}

/*
  Busy is not unavailable. AjaxButton disables itself while the request is in
  flight - which is what stops a second tap placing a second order - and
  without this the CTA turned grey at the exact moment the shopper was waiting
  to hear that something was happening, which reads as the button having
  failed. It keeps its colour and shows the spinner; it is still not clickable.
*/
.mfs ::v-deep .primary-btn.mfs-cta.is-busy:disabled {
  background: #1A56DB;
  color: #fff;
  cursor: progress;
}

/* --- the fold ------------------------------------------------------------- */
/*
  max-height is driven from the transition hooks with the panel's own measured
  height, so the open animation is the real distance rather than a guessed one
  that snaps. Cleared afterwards (capped, scrollable) so a long breakdown on a
  short phone can still be read.
*/
.mfs-panel {
  overflow: hidden;
  border-bottom: 1px solid #EEF1F6;
  transition: max-height .24s ease;
}

/* The cap, once the fold has finished opening: a share of the viewport rather
   than a fixed height, because the breakdown is a different length on every
   basket and the screen is a different height on every phone. */
.mfs.is-open .mfs-panel {
  max-height: 52vh;
  overflow-y: auto;
}

.mfs-panel-inner {
  padding: 10px 14px 12px;
}

.mfs-panel .sum-row {
  padding: 4px 0;
}

.mfs-panel .sum-label {
  font-size: 14px;
}

.mfs-panel .sum-value {
  font-size: 14.5px;
}

/*
  The page's own slot content, in the fold. It arrives with the styling the
  page gave it - a voucher form, a status line - so this only separates it
  from the figures above and stops a full-width input running to the edges.
*/
.mfs-slot {
  margin: 8px 0 0;
  padding: 9px 0 0;
  border-top: 1px solid #EEF1F6;
}

.mfs-slot > :first-child {
  margin-top: 0;
}

.mfs-slot > :last-child {
  margin-bottom: 0;
}

.mfs-secure {
  display: flex;
  align-items: center;
  gap: 6px;
  margin: 8px 0 0;
  font-size: 11.5px;
  line-height: 1.3;
  color: #6B7C93;
}

.mfs-secure svg {
  flex: 0 0 auto;
}

/* --- the nudge strip ------------------------------------------------------ */
.mfs-nudge {
  display: flex;
  align-items: center;
  gap: 7px;
  margin: -2px -4px 4px;
  padding: 5px 8px;
  border-radius: 7px;
  background: #FFFBF3;
  font-size: 12px;
  line-height: 1.35;
  color: #7A5B12;
}

.mfs-nudge strong {
  font-weight: 700;
}

.mfs-nudge-icon {
  flex: 0 0 auto;
  color: #E0A21A;
}

@media only screen and (max-width: 767px) {
  .mfs {
    position: fixed;
    left: 0;
    right: 0;
    bottom: calc(64px + env(safe-area-inset-bottom, 0px));
    z-index: 46;
    display: block;
    border-top: 1px solid #E3E8F0;
    border-radius: 14px 14px 0 0;
    background: #fff;
    box-shadow: 0 -6px 20px rgba(15, 37, 71, .1);
  }

  /*
    The card is hidden, not copied: the bar is the summary on a phone, and two
    of them would be two totals to reconcile. The column is collapsed with it
    so the row's gutter does not leave a blank band where it used to be.
  */
  .has-mobile-bar .summary {
    display: none;
  }

  .has-mobile-bar {
    margin-top: 0 !important;
    padding: 0;
  }
}

/* A phone in landscape has room for the bar and almost nothing else, so the
   fold is capped harder and the CTA loses a little height. */
@media only screen and (max-width: 767px) and (max-height: 460px) {
  .mfs-head {
    min-height: 40px;
  }

  .mfs-head-value {
    font-size: 19px;
  }

  .mfs ::v-deep .primary-btn.mfs-cta {
    height: 44px;
    min-height: 44px;
  }

  .mfs.is-open .mfs-panel {
    max-height: 40vh;
  }
}

@media (prefers-reduced-motion: reduce) {
  .mfs-panel,
  .mfs-chev {
    transition: none !important;
  }
}

/* --- phone ---------------------------------------------------------------- */
@media only screen and (max-width: 991px) {
  .summary {
    position: static;
  }
}

@media only screen and (max-width: 767px) {
  .summary {
    padding: 12px !important;
  }

  .summary-head {
    font-size: 19px;
  }

  .sum-total-label {
    font-size: 16px;
  }

  .sum-total-value {
    font-size: 20px;
  }

  .sum-value {
    font-size: 14.5px;
  }
}
</style>
