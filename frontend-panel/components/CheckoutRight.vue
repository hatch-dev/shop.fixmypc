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
            Order Summary
            <small class="summary-head-note">All prices are in {{ currencyName }}</small>
          </span>
        </h5>

        <!--
          One flow, and VAT stated once at the end of it:

            Products Total + VAT + Shipping = Total (Inc-VAT)

          The "Original Products" list that used to open this panel is gone. It
          restated the cart line for line, immediately beside the cart itself,
          and then closed with a "Total" that was the same figure as the
          "Products Total" row directly under it -- the identical number twice,
          five lines apart, inviting the reader to look for the difference
          between them. On a three-item basket it was the tallest thing in the
          summary and said nothing the list on the left had not already said.

          What it was there for survives in two better places: the per-line
          original price and its Ex-VAT / Inc-VAT badge are on the cart card
          itself, and the working behind the saving is in the popover on the
          "You Save" row. originalTotal is still computed -- it is the base
          that saving is measured against -- it is simply no longer printed as
          a list.

          Every figure down to "Products Total" is a price AS DISPLAYED - the
          number on the product's card, which for an ex-VAT product is net and
          for an inc-VAT product is gross. They add up because VAT is then
          added exactly once, and only to the lines that do not already carry
          it:

            2,921.00  products total, as displayed
            + 465.06  VAT, added for the ex-VAT lines only
            = 3,386.06

          A product quoted gross has nothing added to it and its VAT is already
          inside its own figure. A product quoted net has its VAT in the VAT
          row. Adding VAT to both would tax the gross one twice - which is the
          one error this arrangement exists to prevent.

          This is also literally what totalPrice computes:
          totalPriceWithOffer + exclusiveTax, where exclusiveTax is summed
          only over lines with vat_inclusive false. The rows and the amount
          charged are the same arithmetic rather than two routes to one number.

          The caveat is the VAT row: it is the VAT ADDED, not the VAT contained
          in the order, which is larger once the gross lines' own tax is
          counted. The icon on that row carries both, because an invoice needs
          the second figure.
        -->

        <template v-if="formatPrice(productsDiscount) > 0">

        <div class="sum-row">
          <span class="sum-label">
            You Save
            <!--
              Click to open the figure behind the row. A popover rather than a
              title attribute: these explanations carry amounts worth reading,
              and a native tooltip is unreachable on a touch screen.
            -->
            <info-popover
              title="Your savings"
              label="About your total saving"
              lede="What you save against the original prices, at the prices shown."
              :groups="savingsExplainerGroups"
              :rows="savingsExplainerRows"
              :note="savingsExplainerNote"
            />
          </span>
          <span class="sum-value text-success">
            -<price-format :price="formatPrice(productsDiscount)" :decimals="2" />
          </span>
        </div>

        <div class="sum-rule"></div>

        </template>

        <!--
          The goods, net of VAT whenever the basket carries any, so the one
          VAT row below can hold all of it: VAT added for Ex-VAT products and
          VAT already inside Inc-VAT prices. Net + VAT + Shipping = Total.
        -->
        <div class="sum-row">
          <span class="sum-label">
            Products Total<template v-if="hasVat"> (Ex-VAT)</template>
            <info-popover
              title="Products total"
              label="About the products total"
              :lede="hasVat ? 'What your products cost before VAT, after the saving. The VAT on all of them is in the row below.' : 'What your products cost at the prices shown on their cards, after the saving.'"
              :rows="productsExplainerRows"
            />
          </span>
          <span class="sum-value">
            <price-format v-if="!hasStaleLines" :price="formatPrice(productsNet)" :decimals="2" />
            <span v-else class="sum-pending">&mdash;</span>
          </span>
        </div>

        <!--
          All the VAT on the order, in one row: the VAT added to Ex-VAT
          products and the VAT inside Inc-VAT prices. The Products Total above
          is net for exactly this reason, so it is still counted only once.

          The rate is named here only when the whole basket shares one; with
          two in play there is no single percentage to print, and the icon
          lists each rate separately.
        -->
        <div class="sum-row">
          <span class="sum-label">
            VAT<template v-if="vatRateLabel"> ({{ vatRateLabel }})</template>
            <info-popover
              title="VAT details"
              label="About the VAT"
              lede="All the VAT on your order: the VAT inside the price of products priced Inc-VAT and the VAT added to products priced Ex-VAT. It is charged once."
              :rows="vatExplainerRows"
              :note="vatExplainerNote"
            />
          </span>
          <span class="sum-value">
            <price-format v-if="!hasStaleLines" :price="formatPrice(vatTotal)" :decimals="2" />
            <span v-else class="sum-pending">&mdash;</span>
          </span>
        </div>

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
          <span>Updating prices&hellip;</span>
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
              {{ pricesPending ? 'Trying…' : 'Try again' }}
            </button>
          </span>
        </p>

        <div v-if="formatPrice(flashDiscount) > 0" class="sum-row">
          <span class="sum-label">Flash Discount</span>
          <span class="sum-value text-success">
            - <price-format :price="formatPrice(flashDiscount)" :decimals="2" />
          </span>
        </div>

        <div v-if="formatPrice(voucherResult?.offered) > 0" class="sum-row">
          <span class="sum-label">Voucher</span>
          <span class="sum-value text-success">
            - <price-format :price="formatPrice(voucherResult?.offered || 0)" :decimals="2" />
          </span>
        </div>

        <!--
          The label is the merchant's customer-facing copy, defaulting to
          "Loyalty Discount". It is never the group's internal admin title,
          which is how a shopper's basket came to read "Group 1  - €250.00".
        -->
        <div v-if="formatPrice(loyaltyDiscount) > 0" class="sum-row">
          <span class="sum-label">
            {{ loyaltyLabel }}
            <small v-if="loyaltyBenefit" class="sum-note">
              {{ loyaltyBenefit }}{{ loyaltyExpiryNote }}
            </small>
          </span>
          <span class="sum-value text-success">
            - <price-format :price="formatPrice(loyaltyDiscount)" :decimals="2" />
          </span>
        </div>

        <!--
          One row per Essentials Bundle, named, because this discount is the
          only one on the list a shopper can lose by editing their basket:
          removing a single item of a bundle ends the set price for the rest
          of it. An unlabelled "Bundle saving" that quietly disappeared would
          read as the total going wrong.
        -->
        <div
          v-for="bundle in bundleDiscounts"
          :key="`bundle-saving-${bundle.id}`"
          class="sum-row"
        >
          <span class="sum-label">
            {{ bundle.title || 'Bundle saving' }}
            <small class="sum-note">while the whole bundle is in your basket</small>
          </span>
          <span class="sum-value text-success">
            - <price-format :price="formatPrice(bundle.discount)" :decimals="2" />
          </span>
        </div>

        <div v-if="formatPrice(pointsDiscount) > 0" class="sum-row">
          <span class="sum-label">Cherry Points</span>
          <span class="sum-value text-success">
            - <price-format :price="formatPrice(pointsDiscount)" :decimals="2" />
          </span>
        </div>

        <!--
          Always stated, because "no shipping row" and "shipping is free" look
          identical and only one of them is reassuring. Last before the total,
          since it is the only thing added to the goods.
        -->
        <div class="sum-row">
          <span class="sum-label">
            {{ $t('checkoutRight.shipping') }}
            <small v-if="isFreeShipping && !shippingUndecided && !isCollectionChoice" class="sum-note">Standard delivery</small>
          </span>
          <span class="sum-value">
            <price-format v-if="shippingUndecided" :price="formatPrice(0)" :decimals="2" />
            <span v-else-if="isFreeShipping" class="color-free">
              {{ $t('invent.fre') }}
            </span>
            <price-format v-else :price="formatPrice(cartPrice.shippingPrice)" :decimals="2" />
          </span>
        </div>

        <!-- .sum-total carries the second rule as its own top border -->
        <div class="sum-total">
          <span class="sum-total-label">Total (Inc-VAT)</span>
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
            <span class="sum-label">Paid from wallet</span>
            <span class="sum-value text-success">
              -<price-format :price="formatPrice(walletApplied)" :decimals="2" />
            </span>
          </div>
          <div class="sum-row sum-split-due">
            <span class="sum-label">Left to pay</span>
            <span class="sum-value">
              <price-format :price="formatPrice(amountLeftToPay)" :decimals="2" />
            </span>
          </div>
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
            <span>
              Add
              <strong><price-format :price="formatPrice(loyaltyNudge.shortfall)" :decimals="2" /></strong>
              more to
              {{ loyaltyNudge.upgrade ? 'upgrade to' : 'unlock' }}
              <strong>{{ loyaltyNudge.label }}</strong>
            </span>
          </div>

          <div class="loyalty-nudge-sub">
            {{ loyaltyNudge.benefit }} on orders over
            <price-format :price="formatPrice(loyaltyNudge.threshold)" :decimals="2" />
            &mdash; saving you at least
            <price-format :price="formatPrice(loyaltyNudge.reward)" :decimals="2" />
          </div>

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
          Continue Shopping
        </button>

        <hr class="seprate-hr">

        <!-- PAYMENT IMAGE -->
        <div class="payment-icons">
          <img :src="require('~/assets/images/cards.png')" alt="cards" />
        </div>

        <!-- SECURE -->
        <div class="secure-box">
          <svg width="11" height="12" viewBox="0 0 11 12">
            <path
              d="M3.375 3.375V4.5H7.125V3.375C7.125 2.33906 6.28594 1.5 5.25 1.5C4.21406 1.5 3.375 2.33906 3.375 3.375V3.375M1.875 4.5V3.375C1.875 1.51172 3.38672 0 5.25 0C7.11328 0 8.625 1.51172 8.625 3.375V4.5H9C9.82734 4.5 10.5 5.17266 10.5 6V10.5C10.5 11.3273 9.82734 12 9 12H1.5C0.672656 12 0 11.3273 0 10.5V6C0 5.17266 0.672656 4.5 1.5 4.5H1.875V4.5"
              fill="#6B7280" />
          </svg>
          Secure Checkout Guaranteed
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

              <div v-if="formatPrice(productsDiscount) > 0" class="sum-row">
                <span class="sum-label">You Save</span>
                <span class="sum-value text-success">
                  -<price-format :price="formatPrice(productsDiscount)" :decimals="2" />
                </span>
              </div>

              <div class="sum-row">
                <span class="sum-label">
                  Products Total<template v-if="hasVat"> (Ex-VAT)</template>
                </span>
                <span class="sum-value">
                  <price-format v-if="!hasStaleLines" :price="formatPrice(productsNet)" :decimals="2" />
                  <span v-else class="sum-pending">&mdash;</span>
                </span>
              </div>

              <!-- VAT ADDED, for the Ex-VAT lines only - an Inc-VAT line
                   already carries its own. Same rule, same figure, same
                   rate label as the card. -->
              <div class="sum-row">
                <span class="sum-label">
                  VAT<template v-if="vatRateLabel"> ({{ vatRateLabel }})</template>
                </span>
                <span class="sum-value">
                  <price-format v-if="!hasStaleLines" :price="formatPrice(vatTotal)" :decimals="2" />
                  <span v-else class="sum-pending">&mdash;</span>
                </span>
              </div>

              <div v-if="formatPrice(flashDiscount) > 0" class="sum-row">
                <span class="sum-label">Flash Discount</span>
                <span class="sum-value text-success">
                  - <price-format :price="formatPrice(flashDiscount)" :decimals="2" />
                </span>
              </div>

              <div v-if="formatPrice(voucherResult?.offered) > 0" class="sum-row">
                <span class="sum-label">Voucher</span>
                <span class="sum-value text-success">
                  - <price-format :price="formatPrice(voucherResult?.offered || 0)" :decimals="2" />
                </span>
              </div>

              <div v-if="formatPrice(loyaltyDiscount) > 0" class="sum-row">
                <span class="sum-label">{{ loyaltyLabel }}</span>
                <span class="sum-value text-success">
                  - <price-format :price="formatPrice(loyaltyDiscount)" :decimals="2" />
                </span>
              </div>

              <div
                v-for="bundle in bundleDiscounts"
                :key="`mfs-bundle-${bundle.id}`"
                class="sum-row"
              >
                <span class="sum-label">{{ bundle.title || 'Bundle saving' }}</span>
                <span class="sum-value text-success">
                  - <price-format :price="formatPrice(bundle.discount)" :decimals="2" />
                </span>
              </div>

              <div v-if="formatPrice(pointsDiscount) > 0" class="sum-row">
                <span class="sum-label">Cherry Points</span>
                <span class="sum-value text-success">
                  - <price-format :price="formatPrice(pointsDiscount)" :decimals="2" />
                </span>
              </div>

              <div class="sum-row">
                <span class="sum-label">{{ $t('checkoutRight.shipping') }}</span>
                <span class="sum-value">
                  <price-format v-if="shippingUndecided" :price="formatPrice(0)" :decimals="2" />
                  <span v-else-if="isFreeShipping" class="color-free">{{ $t('invent.fre') }}</span>
                  <price-format v-else :price="formatPrice(cartPrice.shippingPrice)" :decimals="2" />
                </span>
              </div>

              <template v-if="walletApplied > 0 && !hasStaleLines">
                <div class="sum-row">
                  <span class="sum-label">Paid from wallet</span>
                  <span class="sum-value text-success">
                    -<price-format :price="formatPrice(walletApplied)" :decimals="2" />
                  </span>
                </div>
                <div class="sum-row sum-split-due">
                  <span class="sum-label">Left to pay</span>
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
                <span>Updating prices&hellip;</span>
              </p>

              <p v-else-if="hasStaleLines" class="sum-stale is-failed">
                <span class="sum-stale-icon" aria-hidden="true">!</span>
                <span>
                  {{ pricesError }}
                  <button type="button" class="sum-retry" :disabled="pricesPending" @click="retryPrices">
                    {{ pricesPending ? 'Trying…' : 'Try again' }}
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
                Secure Checkout Guaranteed &middot; All prices in {{ currencyName }}
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
            <span>
              Add
              <strong><price-format :price="formatPrice(loyaltyNudge.shortfall)" :decimals="2" /></strong>
              more to {{ loyaltyNudge.upgrade ? 'upgrade to' : 'unlock' }}
              <strong>{{ loyaltyNudge.label }}</strong>
            </span>
          </div>

          <!--
            The whole row is the disclosure, so the total reads as
            "Total (Inc-VAT) EUR 924.16, Show price breakdown, collapsed,
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
            <span class="mfs-head-label">Total (Inc-VAT)</span>
            <span class="mfs-head-value">
              <price-format v-if="!hasStaleLines" :price="formatPrice(totalPrice)" :decimals="2" />
              <span v-else class="sum-pending">&mdash;</span>
            </span>
            <span class="mfs-sr">
              {{ mobileOpen ? 'Hide price breakdown' : 'Show price breakdown' }}
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
    AjaxButton
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
      return this.$store.state.cart.loyaltyDiscount?.label || 'Loyalty Discount'
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

      return days === 1 ? ' · ends today' : ` · ${days} days left`
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
          .filter(row => parseFloat(row?.vat_rate || 0) > 0)
          .map(row => !!row?.vat_inclusive)
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

    productsDiscount() {
      const saving = Number(this.originalTotal) - Number(this.cartPrice.totalPriceWithOffer)

      return saving > 0 ? saving : 0
    },

    /*
     * The original prices, added as displayed.
     *
     * Addable because every term is a price as the shop states it, and because
     * nothing is done to this figure except subtract the saving from it. The
     * VAT that some of these carry and others do not is handled once, further
     * down, by adding it only to the lines that lack it.
     *
     * line_list is the server's, per line; this only sums.
     */
    originalTotal() {
      return (this.checkedProduct || []).reduce((sum, row) => {
        const quantity = parseInt(row?.quantity || 0)
        const unit = Number(row?.original_price ?? 0)

        const lineList = row?.line_list != null
          ? Number(row.line_list)
          : this.formatPrice(unit * quantity)

        return sum + lineList
      }, 0)
    },
    /*
     * One row per rate actually charged, highest first, the way a VAT invoice
     * states them. Every figure is a sum of server splits; nothing here
     * applies a rate to anything.
     */
    vatBreakdown() {
      return Object.values(this.cartPrice.byRate || {})
        .filter(row => row.inc > 0)
        .sort((a, b) => b.rate - a.rate)
    },
    /*
     * Where the "You Save" figure came from, line by line.
     *
     * The saving on an ex-VAT line is quoted net — €25 off a €699 net price —
     * but the summary is gross throughout, so what the customer actually saves
     * is that €25 plus the VAT that would have been charged on it. Adding a
     * net saving to a gross one is the mistake this section exists to make
     * visible rather than hide: both ends are the server's gross figures and
     * the VAT on the saving is the difference between them, never a rate
     * applied here.
     */
    savingsDetails() {
      return (this.checkedProduct || []).map((row) => {
        const quantity = parseInt(row?.quantity || 0)

        const listInc = Number(
          row?.line_list_inc_vat != null ? row.line_list_inc_vat : (row?.line_inc_vat || 0)
        )
        const chargedInc = Number(row?.line_inc_vat || 0)
        const incSaving = listInc - chargedInc

        if (!(incSaving > 0.004)) {
          return null
        }

        const unitFrom = Number(row?.original_price || 0)
        const unitTo = Number(row?.unit_price ?? row?.upsell_price ?? 0)

        // The saving as the line itself is quoted, before any VAT on it.
        const baseSaving = Math.max(0, (unitFrom - unitTo)) * quantity

        return {
          id: row?.id,
          title: row?.flash_product?.title || row?.product?.title || '',
          inclusive: !!row?.vat_inclusive,
          quantity,
          unitFrom,
          unitTo,
          unitSaving: Math.max(0, unitFrom - unitTo),
          baseSaving,
          // Only meaningful on a net line; on a gross one it is already inside.
          vatOnSaving: Math.max(0, incSaving - baseSaving),
          incSaving,
        }
      }).filter(Boolean)
    },
    /*
     * ---- what the summary's info icons open ---------------------------
     *
     * Each one explains the figure on its own row, not VAT in general. Every
     * amount is the one already rendered beside it — the same server splits
     * the rows read — so the popover can never disagree with the summary.
     */

    /*
     * The normalisation, shown as working: each line's original stated net,
     * then totalled. It is the answer to "why is my products total not the sum
     * of the numbers above it" — because one of those numbers had its VAT
     * taken out first.
     */
    /*
     * Each line at the price actually charged, as displayed, then the total.
     * The basis is named per row because the column mixes the two, and naming
     * it is what stops the list being read as one convention.
     */
    productsExplainerRows() {
      const rows = (this.checkedProduct || []).map((row) => {
        const quantity = parseInt(row?.quantity || 0)
        const unit = Number(row?.unit_price ?? row?.upsell_price ?? 0)
        const rated = parseFloat(row?.vat_rate || 0) > 0
        const basis = rated ? (row?.vat_inclusive ? 'Inc-VAT' : 'Ex-VAT') : ''

        return {
          label: `${row?.flash_product?.title || ''}${basis ? ` (${basis})` : ''}`,
          value: `${this.money(unit)} × ${quantity}`,
        }
      })

      if (this.vatIncluded > 0.004) {
        rows.push({ label: 'At the prices shown', value: this.money(this.cartPrice.totalPriceWithOffer) })
        rows.push({ label: 'Less VAT inside Inc-VAT prices', value: `-${this.money(this.vatIncluded)}` })
      }

      rows.push({
        label: this.hasVat ? 'Products total (Ex-VAT)' : 'Products total',
        value: this.money(this.productsNet),
        strong: true,
      })

      return rows
    },

    /* The basket's original price, normalised to net. Only the VAT popover
       uses this now: it is what makes the two conventions comparable. */
    originalExVat() {
      return (this.checkedProduct || []).reduce(
        (sum, row) => sum + Number(row?.line_list_ex_vat ?? row?.line_ex_vat ?? 0), 0
      )
    },

    /*
     * The saving on an ex-VAT line is quoted net, but this summary is gross
     * throughout, so the working shows that saving being grossed up. Adding a
     * net saving straight to a gross one is the mistake this exists to rule
     * out, which is why each line states its own basis.
     */
    savingsExplainerGroups() {
      return this.savingsDetails.map((row) => {
        const basis = row.inclusive ? 'Inc-VAT' : 'Ex-VAT'

        return {
          name: row.title,
          lines: [`${this.money(row.unitFrom)} → ${this.money(row.unitTo)} ${basis}`],
          total: `= ${this.money(row.baseSaving)} off × ${row.quantity}`,
        }
      })
    },

    savingsExplainerRows() {
      return [
        { label: 'Total you save', value: this.money(this.productsDiscount), strong: true },
      ]
    },

    /*
     * On a mixed basket the saving is worth one sentence, because the ex-VAT
     * part of it grows by the VAT that is added further down. €25 off a net
     * price takes €30.75 off the amount finally charged; the summary never
     * says so, and this is where someone checking the arithmetic finds it.
     */
    savingsExplainerNote() {
      if (!this.hasMixedVatBasis) return ''

      return 'Savings are shown at the prices displayed. A saving on an Ex-VAT '
        + 'product reduces the VAT added below as well, so it takes a little '
        + 'more than this off your total.'
    },

    /*
     * The VAT working, in the order it is done: net, tax, gross.
     *
     * This is the whole of what the "VAT breakdown" table below the button
     * used to show, and it is the only place the basket states it now. Where
     * more than one rate is charged each gets its own line above the totals —
     * a single "VAT 23%" across a basket holding a 23% and a 13.5% product
     * would be a fiction, and the rates come from each product's own tax rule
     * rather than from anything written here.
     */
    /*
     * Both VAT figures, because the row can only show one of them.
     *
     * The row shows what is ADDED — the tax on the ex-VAT lines, which is the
     * part that moves the amount charged. It is not the VAT contained in the
     * order: an inc-VAT line's tax is already inside the price and never
     * appears in that row, so on the brief's basket the row reads €465.06
     * while the order actually carries €633.17.
     *
     * An invoice needs the second figure, so it is here, under the icon on the
     * row that would otherwise be mistaken for it.
     */
    /*
     * The VAT already inside Inc-VAT prices: the order's VAT less the part
     * added for Ex-VAT lines. Shown, never added to the total.
     */
    vatIncluded() {
      const included = Number(this.cartPrice.vat || 0) - Number(this.cartPrice.exclusiveTax || 0)
      return included > 0 ? included : 0
    },

    /* All the VAT on the order, added and included: the single VAT row. */
    vatTotal() {
      return Number(this.cartPrice.exclusiveTax || 0) + this.vatIncluded
    },

    hasVat() {
      return this.vatTotal > 0.004
    },

    /*
     * The goods before VAT: the card prices less the VAT already inside the
     * Inc-VAT ones. Derived from the same figures the total uses, so
     * productsNet + vatTotal is exactly totalPriceWithOffer + exclusiveTax
     * and the rows always add up to the Total (Inc-VAT).
     */
    productsNet() {
      return this.cartPrice.totalPriceWithOffer - this.vatIncluded
    },

    vatExplainerRows() {
      const added = Number(this.cartPrice.exclusiveTax)
      const total = Number(this.cartPrice.vat)
      const included = total - added

      const rows = [
        {
          label: this.vatRateLabel ? `VAT added (${this.vatRateLabel})` : 'VAT added',
          value: this.money(added),
        },
      ]

      // Only when some of the basket carries its VAT internally; on an all
      // ex-VAT basket the two figures are the same and a zero row says nothing.
      if (included > 0.004) {
        rows.push({ label: 'Inside Inc-VAT prices', value: this.money(included) })
        rows.push({ label: 'Total VAT in this order', value: this.money(total), strong: true })
      }

      rows.push({ label: 'Net Ex-VAT', value: this.money(this.cartPrice.exVat) })
      rows.push({ label: 'Total Inc-VAT', value: this.money(this.cartPrice.incVat) })

      return rows
    },

    vatExplainerNote() {
      if (this.vatRateLabel) return ''

      return 'Your basket has products at more than one VAT rate.'
    },

    totalPrice() {
      /*
       * Never below zero. The discounts are capped server-side, but the
       * shopper must not be shown a negative total while the preview and the
       * server are momentarily out of step.
       */
      return Math.max(0,
        this.cartPrice.totalPriceWithOffer
        + this.cartPrice.shippingPrice
        + this.cartPrice.exclusiveTax
        - this.cartPrice.voucher
        - this.flashDiscount
        - this.bundleDiscount
        - this.loyaltyDiscount
        - this.pointsDiscount)
    },
    cartPrice() {
      let cp = {
        priceBeforeOffer: 0,
        totalItems: 0,
        totalPriceWithOffer: 0,
        totalPrice: 0,
        // VAT, split server-side per line. exVat + vat = incVat for the
        // goods; exclusiveTax is the part actually added to the price and
        // is the only one that moves the amount charged.
        exVat: 0,
        vat: 0,
        incVat: 0,
        // list price of the goods, gross: the "Products (Inc-VAT)" row
        listIncVat: 0,
        vatRates: new Set(),
        // goods grouped by the rate charged on them, for the VAT breakdown
        byRate: {},
        exclusiveTax: 0,
        shippingPrice: 0,
        voucher: 0,
        // lines that arrived with no VAT split at all — see below
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

      this.checkedProduct.forEach((curr) => {
        /*
         * The unit price the server has decided for this line, which is the
         * one the order will be charged at.
         *
         * It accounts for things this component cannot see: the business and
         * trade quantity bands the signed-in customer is entitled to, and
         * whether that entitlement still stands. Recomputing the retail ladder
         * here instead showed a business customer the retail total and then
         * took a different amount at the gateway.
         *
         * upsell_price is the same figure under the name the API has always
         * used for it; the fallback keeps a cached or older payload working.
         */
        const serverPrice = curr?.unit_price ?? curr?.upsell_price
        const hasServerPrice = serverPrice !== null && serverPrice !== undefined && parseFloat(serverPrice) > 0
        const productPrice = hasServerPrice
          ? parseFloat(serverPrice)
          : this.currentInventoryPriceCalc(curr?.updated_inventory, curr?.flash_product);

        const qty = parseInt(curr?.quantity || 0)
        const bundleDeal = curr?.flash_product?.bundle_deal
        cp.totalItems += qty

        // Use upsell price if available, otherwise use regular price
        const bundleOffer = (bundleDeal?.buy <= qty) ? (productPrice * parseInt(bundleDeal?.free || 0)) : 0
        cp.totalPriceWithOffer += qty * productPrice - bundleOffer

        /*
         * Units actually paid for: the ordered quantity less anything a
         * "buy X get Y free" bundle gives away. Tax follows this rather than
         * the ordered quantity, because Pricing::unitTax is multiplied by the
         * payable quantity at checkout (QA audit ORD-03) — taxing the free
         * ones here overstated the VAT on screen against the amount charged.
         */
        const freeUnits = (bundleDeal?.buy <= qty) ? parseInt(bundleDeal?.free || 0) : 0
        const payableQty = Math.max(qty - freeUnits, 0)

        /*
         * VAT, as the server split it for this line.
         *
         * This used to be worked out here: the rule's rate times the price,
         * for both conventions. That is right only for a price quoted ex-VAT.
         * For the 111 products whose prices already contain their VAT it
         * overstated the tax by 23% of the tax - €699 gross was reported as
         * carrying €160.77 of VAT when it carries €130.71 - and the figure was
         * only ever kept in `inclusiveTax`, which nothing displayed, so the
         * error sat unnoticed on the one screen where being wrong means
         * charging the wrong amount.
         *
         * Pricing::vatBreakdown now does it once, server-side, and the same
         * numbers reach the order, the confirmation and the invoice.
         */
        cp.exVat += Number(curr?.line_ex_vat || 0)
        cp.vat += Number(curr?.line_vat || 0)
        cp.incVat += Number(curr?.line_inc_vat || 0)

        /*
         * The list price of the line, gross, so the summary can open with
         * "Products (Inc-VAT)". Falls back to the charged gross when the
         * server did not send one, which shows no saving rather than
         * inventing one.
         */
        cp.listIncVat += Number(
          curr?.line_list_inc_vat != null
            ? curr.line_list_inc_vat
            : (curr?.line_inc_vat || 0)
        )

        if (curr?.vat_rate > 0) {
          cp.vatRates.add(Number(curr.vat_rate))
        }

        /*
         * The same figures grouped by the rate that produced them, for the
         * breakdown a VAT invoice states. Aggregation only — each line was
         * already split server-side on its own basis, and adding up two
         * conventions is exactly why this cannot be re-derived from a single
         * total and a single rate.
         */
        const rateKey = Number(curr?.vat_rate || 0).toFixed(2)

        if (!cp.byRate[rateKey]) {
          cp.byRate[rateKey] = {
            rate: Number(curr?.vat_rate || 0), ex: 0, vat: 0, inc: 0,
          }
        }

        cp.byRate[rateKey].ex += Number(curr?.line_ex_vat || 0)
        cp.byRate[rateKey].vat += Number(curr?.line_vat || 0)
        cp.byRate[rateKey].inc += Number(curr?.line_inc_vat || 0)

        /*
         * A line that carries no VAT split at all.
         *
         * Not the same thing as a zero-rated line, which has vat_rate 0 and a
         * real split of zero. This is a row that predates the split being part
         * of the payload — a basket rehydrated from localStorage by an older
         * build, which is how the payment page came to show "VAT €0.00" under
         * a Products Total of €699.00 on an ex-VAT product.
         *
         * It is flagged rather than patched, because the frontend must not
         * work VAT out for itself: the page has to refresh from the server
         * instead. `staleLines` is what the summary reads to say so out loud.
         */
        if (curr?.vat_rate === undefined || curr?.vat_rate === null) {
          cp.staleLines += 1
        }

        /*
         * The VAT that is *added* to the price, which is the part that moves
         * the amount charged. Nothing is added to an inclusive line, so this
         * stays zero there and the total is unaffected.
         */
        if (!curr?.vat_inclusive) {
          cp.exclusiveTax += Number(curr?.line_vat || 0)
        }

        cp.totalPrice += qty * productPrice

        /*
         * The struck-through "before" figure the Subtotal and Discount rows
         * are built from.
         *
         * original_price is decided by the same server pass as the unit price
         * (CartsController::repriceForCustomer) and is the list price of the
         * variant on the line. Reading products.selling here instead ignored
         * the variant, so a product whose variant is priced away from the
         * product-level figure showed a Subtotal — and therefore a Discount —
         * that neither the basket nor the order agreed with.
         */
        const serverOriginal = curr?.original_price
        const hasServerOriginal = serverOriginal !== null
          && serverOriginal !== undefined
          && parseFloat(serverOriginal) > 0

        const listPrice = hasServerOriginal
          ? parseFloat(serverOriginal)
          : this.sellPriceCalc(curr?.flash_product)

        cp.priceBeforeOffer += listPrice * qty
      })
      cp.voucher = this.voucherResult?.offered || 0

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

     Products (Inc-VAT) - You Save = Total Products (Inc-VAT)
     Total Products - order-wide discounts + shipping = Total (Inc-VAT)

   Every figure is gross, so a basket holding one ex-VAT and one inc-VAT
   product adds up without ever putting a net figure next to a gross one. The
   detail behind each figure is behind the info icon on the row it belongs to,
   never a second row or a panel of its own.

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
