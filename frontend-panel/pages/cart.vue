<template>
  <client-only>
    <div>
     <Breadcrumbs />
      <navigation-step /> 
      <div class="container-fluid cart-page my-5">
        <!--
          The page head spans both columns: "Clear Cart" is an action on the
          whole basket, so it sits at the edge of the page rather than inside
          the list it empties.
        -->
        <div class="cart-page-head">
          <h1 class="cart-page-title">{{ $t('ui.cartPage.title') }}</h1>
          <span v-if="hasLines" class="cart-count">
            ({{ $tc('ui.cartPage.itemCount', cartProducts.length, { count: cartProducts.length }) }})
          </span>

          <button
            v-if="hasLines"
            type="button"
            class="cart-clear-btn"
            :disabled="bulkBusy"
            @click="clearCart"
          >
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 11v6M14 11v6"
                stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            {{ $t('ui.cartPage.clearCart') }}
          </button>
        </div>

        <div class="row g-4">
          <!-- Nothing sits beside the basket once it is empty, so the column
               takes the full width rather than leaving the summary's gap. -->
          <div :class="cartEmpty ? 'col-12' : 'col-lg-8'">
            <!-- the lines are the cards now, so their container is a plain
                 stack; it keeps its panel only for the empty and error states -->
            <div :class="['cart-card', (cartEmpty || cartError) ? 'cart-state-card' : 'cart-list-card']">
              <!-- An empty basket used to render as an empty white box: the
                   list drew nothing, and the only thing on the page telling
                   the shopper why was "(0 items)" in grey. -->
              <div v-if="cartError" class="cart-error">
                <span class="cart-error-icon" aria-hidden="true">
                  <svg width="30" height="30" viewBox="0 0 24 24" fill="none">
                    <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/>
                    <path d="M12 7.5v5.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                    <circle cx="12" cy="16.4" r="1.15" fill="currentColor"/>
                  </svg>
                </span>
                <h6 class="cart-error-title">{{ $t('ui.cartPage.loadErrorTitle') }}</h6>
                <p class="cart-error-text">{{ cartError }}</p>
                <button
                  type="button"
                  class="cart-empty-btn"
                  :disabled="ajaxing"
                  @click="fetchingData()"
                >
                  {{ ajaxing ? $t('ui.cartPage.tryingAgain') : $t('ui.cartPage.tryAgain') }}
                </button>
              </div>

              <div v-else-if="cartEmpty" class="cart-empty">
                <span class="cart-empty-icon" aria-hidden="true">
                  <svg width="34" height="34" viewBox="0 0 24 24" fill="none">
                    <path d="M3 4h2l2.4 11.2a2 2 0 0 0 2 1.6h7.5a2 2 0 0 0 2-1.55L20.5 8H6.2"
                      stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                    <circle cx="10" cy="20" r="1.4" fill="currentColor"/>
                    <circle cx="17" cy="20" r="1.4" fill="currentColor"/>
                  </svg>
                </span>
                <h6 class="cart-empty-title">{{ $t('ui.cartPage.emptyTitle') }}</h6>
                <p class="cart-empty-text">
                  {{ $t('ui.cartPage.emptyText') }}
                </p>
                <button type="button" class="cart-empty-btn" @click="goToShopping">
                  {{ $t('ui.cartPage.startShopping') }}
                </button>
              </div>

              <cart-list
                v-else
                :cart-products="cartProducts"
                :ajaxing="ajaxing"
                :checked="checked"
                @saved-for-later="refreshSaved"
              />
            </div>

            <!--
              Under the lines, in the basket's own column: the summary beside
              it is a single grid cell, and anything added next to it there
              takes that cell and pushes the summary out of the row.
            -->
            <p
              v-if="!cartEmpty && blockedBasketLines.length"
              class="basket-blocked-note"
              role="status"
            >
              {{ $t('cart.blockedNote') }}
            </p>

            <!--
              Actions on the selection rather than on a single line. "Save for
              later" used to live on every card, which put the same link beside
              each row and still left no way to act on several at once.
            -->
            <div v-if="hasLines && !cartError" class="cart-bulk">
              <label class="cart-bulk-all">
                <input
                  type="checkbox"
                  :checked="allSelected"
                  @change="allSelected ? deselectAllItems() : selectAllItems()"
                >
                <span class="cart-bulk-box"></span>
                {{ $t('ui.cartPage.selectAll', { count: cartProducts.length }) }}
              </label>

              <span class="cart-bulk-sep"></span>

              <button type="button" class="cart-bulk-btn" :disabled="!checkedCount || bulkBusy"
                @click="removeSelected">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                  <path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 11v6M14 11v6"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <span class="cart-bulk-label">{{ $t('ui.cartPage.remove') }}<span class="cart-bulk-word">&nbsp;{{ $t('ui.cartPage.removeSelectedSuffix') }}</span></span>
              </button>

              <button type="button" class="cart-bulk-btn" :disabled="!checkedCount || bulkBusy"
                @click="saveSelectedForLater">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                  <path d="M20.8 5.6a5 5 0 0 0-7.1 0L12 7.3l-1.7-1.7a5 5 0 1 0-7.1 7.1l8.8 8.8 8.8-8.8a5 5 0 0 0 0-7.1Z"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <span class="cart-bulk-label">{{ $t('ui.cartPage.save') }}<span class="cart-bulk-word">&nbsp;{{ $t('ui.cartPage.saveForLaterSuffix') }}</span></span>
              </button>
            </div>

            <!-- Items taken out of the basket but kept. Without this the
                 "Save for later" link was a one-way trip: the line left the
                 cart and there was nowhere to see it again.

                 Moving one back reloads the basket for the same reason the
                 bundle adds do: the optimistic row the cart store pushes is
                 not shaped like an API row, so the list can only draw the
                 line once it has been fetched. -->
            <saved-for-later ref="savedForLater" @moved="fetchingData" />

            <!-- Frequently bought together, inline rather than only as a
                 popup at checkout, so the offer is visible while the basket
                 is still being edited -->
            <!--
              Rule 6: the same card at the same size while the first answer is
              on its way, so the basket below it does not jump when the tiles
              arrive.

              First load only. Once the list has answered, adding one of these
              re-reads it in place rather than collapsing the block back to
              grey bars, which is the "Loading... products... Loading..."
              flicker the rule is about. And a block that is never going to
              have anything in it shows this once and then nothing -- the
              skeleton is not an empty state.
            -->
            <div v-if="loadingUpsells && !upsellsLoaded" class="cart-card cart-fbt" aria-hidden="true">
              <h5 class="cart-card-title cart-fbt-title">
                <skeleton-base :width="150" :height="16" />
              </h5>
              <div class="cart-fbt-grid">
                <div v-for="n in 4" :key="`fbt-sk-${n}`" class="cart-fbt-item">
                  <div class="cart-fbt-img">
                    <skeleton-base :width="88" :height="88" :radius="8" />
                  </div>
                  <skeleton-base width="90%" :height="12" :mb="6" />
                  <skeleton-base width="60%" :height="12" :mb="10" />
                  <skeleton-base :width="64" :height="14" />
                </div>
              </div>
            </div>

            <div v-else-if="bundleProducts.length" class="cart-card cart-fbt">
              <h5 class="cart-card-title cart-fbt-title">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                  <rect x="3" y="8" width="18" height="13" rx="2" stroke="currentColor" stroke-width="1.8"/>
                  <path d="M3 12h18M12 8v13" stroke="currentColor" stroke-width="1.8"/>
                  <path d="M12 8S10.5 3.5 8 3.5a2.5 2.5 0 0 0 0 5M12 8s1.5-4.5 4-4.5a2.5 2.5 0 0 1 0 5"
                    stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                {{ $t('ui.cartPage.youMightAlsoLike') }}
                <a href="#" class="cart-fbt-seeall" @click.prevent="goToShopping">{{ $t('ui.cartPage.seeAll') }}</a>
              </h5>

              <div class="cart-fbt-grid">
                <div v-for="item in bundleProducts" :key="item.id" class="cart-fbt-item">
                  <div class="cart-fbt-img">
                    <img :src="getImageURL(item.image)" :alt="item.title" @error="onBundleImgError" />
                  </div>
                  <p class="cart-fbt-name">{{ item.title }}</p>
                  <div class="cart-fbt-foot">
                    <span class="cart-fbt-price">
                      <strong>€{{ getUpsellPrice(item).toFixed(2) }}</strong>
                      <s v-if="hasDiscount(item)">€{{ getOriginalPrice(item).toFixed(2) }}</s>
                    </span>
                  </div>
                  <!--
                    A product with a real choice to make goes to its own page
                    to make it. There is no room for a variant picker in a
                    tile this size, and adding one without a choice is
                    refused by the server anyway -- which the customer would
                    only discover after clicking.
                  -->
                  <nuxt-link
                    v-if="item.requires_option"
                    class="cart-fbt-add"
                    :to="productLink(item)"
                  >{{ $t('crossSell.chooseOne') }}</nuxt-link>
                  <!-- the offer is worth a named action, not a "+" the shopper
                       has to interpret -->
                  <button
                    v-else
                    class="cart-fbt-add"
                    :disabled="addingToCart === item.id || item.addedToCart"
                    :aria-label="$t('ui.cartPage.addItemToCart', { title: item.title })"
                    @click="addBundleItem(item)"
                  >
                    <svg v-if="!item.addedToCart" width="15" height="15" viewBox="0 0 24 24" fill="none"
                      aria-hidden="true">
                      <circle cx="9" cy="20" r="1.4" stroke="currentColor" stroke-width="2"/>
                      <circle cx="18" cy="20" r="1.4" stroke="currentColor" stroke-width="2"/>
                      <path d="M2 3h3l2.6 12.2h10.6L21 7H6.2" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span v-if="item.addedToCart">&#10003; {{ $t('ui.cartPage.added') }}</span>
                    <span v-else-if="addingToCart === item.id">{{ $t('ui.cartPage.adding') }}</span>
                    <span v-else>{{ $t('ui.cartPage.addToCart') }}</span>
                  </button>
                </div>
              </div>

              <div v-if="bundleSavings > 0" class="cart-bundle-bar">
                <div class="cart-bundle-copy">
                  <strong>{{ $t('ui.cartPage.addFbt') }}</strong>
                  <span>{{ $t('ui.cartPage.bundleSave', { amount: '€' + bundleSavings.toFixed(2) }) }}</span>
                </div>
                <button
                  class="cart-bundle-btn"
                  :disabled="addingBundle || !bundleRemaining.length"
                  @click="addWholeBundle"
                >
                  {{ addingBundle ? $t('ui.cartPage.adding') : $t('ui.cartPage.addBundle') }}
                </button>
              </div>
            </div>

           <!-- Nothing to deliver and nothing to pay for until there is a
                line in the basket. -->
           <!--
             Delivery and payment as open cards, the way the checkout page
             shows its Payment section: a header with a one-line hint, then
             the options as one joined list with the chosen row tinted and
             marked on its leading edge. They were collapsible panels, which
             hid the two things the shopper most needs to confirm here.
           -->
           <section
             v-if="!cartEmpty"
             id="deliveryAccordion"
             class="cart-sec"
             aria-labelledby="cart-sec-address-title"
           >
             <div class="cart-sec-head">
               <span class="cart-sec-ico" aria-hidden="true">
                 <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21Z" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="9.5" r="2.5" stroke="currentColor" stroke-width="1.8"/></svg>
               </span>
               <div class="cart-sec-heading">
                 <h2 id="cart-sec-address-title" class="cart-sec-title">{{ $t('ui.cartPage.deliveryAddress') }}</h2>
                 <p class="cart-sec-sub">{{ $t('ui.cartPage.deliveryAddressHint') }}</p>
               </div>
             </div>
             <div class="cart-sec-body">
               <user-address
                 ref="shippingAddress"
                 :has-radio="false"
                 @editing="editAddress"
                 @add-address="addressPopup = true"
               />
             </div>
           </section>

           <section
             v-if="!cartEmpty"
             id="paymentAccordion"
             class="cart-sec"
             aria-labelledby="cart-sec-payment-title"
           >
             <div class="cart-sec-head">
               <span class="cart-sec-ico" aria-hidden="true">
                 <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><rect x="2.5" y="5" width="19" height="14" rx="2.5" stroke="currentColor" stroke-width="1.8"/><path d="M2.5 10h19" stroke="currentColor" stroke-width="1.8"/></svg>
               </span>
               <div class="cart-sec-heading">
                 <h2 id="cart-sec-payment-title" class="cart-sec-title">{{ $t('ui.cartPage.paymentMethod') }}</h2>
                 <p class="cart-sec-sub">
                   <svg class="cart-sec-lock" viewBox="0 0 24 24" width="13" height="13" fill="none" aria-hidden="true"><rect x="5" y="10.5" width="14" height="10" rx="2" stroke="currentColor" stroke-width="1.8"/><path d="M8.5 10.5V7.5a3.5 3.5 0 0 1 7 0v3" stroke="currentColor" stroke-width="1.8"/></svg>
                   {{ $t('ui.cartPage.paymentMethodHint') }}
                 </p>
               </div>
             </div>
             <div class="cart-sec-body">
               <payment-gateways
                 ref="paymentGateways"
                 :total-price="totalPrice"
                 :voucher="voucherResult"
                 :hide-confirm-btn="true"
                 :readonly="true"
               />
             </div>
           </section>

            <!--
              The store-wide flash discount, below the payment section rather
              than wedged between the basket lines and the Select All row where
              it used to sit. It is a summary of the whole basket, so it reads
              as one once the lines, the delivery address and the payment
              method are all above it.
            -->
            <flash-discount class="mt-3 mb-3"/>
          </div>
          <!--
            mobile-bar: on a phone the summary below is replaced by a fixed
            bar above the tab bar, so the total and "Proceed to checkout"
            stay reachable while the shopper is still editing the basket.
            The basket is the only page that asks for it - checkout, shipping
            and payment each end in their own submit.
          -->
          <checkout-right
            v-if="!cartEmpty"
            :disabled="preventGoing"
            :checked-product="checkedProduct"
            mobile-bar
            @calculated-price="cartPrice = $event"
            @go-next="goToAddress"
          />
          <!-- Sign-in required. Checking out is the one action in the basket
               that needs an account, so the ask happens here in context rather
               than throwing the shopper onto the sign-in page unannounced. -->
          <transition name="login-prompt">
            <div
              v-if="loginPromptVisible"
              class="login-prompt-overlay"
              @click.self="closeLoginPrompt"
            >
              <div
                class="login-prompt-card"
                role="dialog"
                aria-modal="true"
                aria-labelledby="login-prompt-title"
              >
                <!-- Reads as a sheet you can pull down on a phone; hidden on
                     desktop, where the card is centred instead. -->
                <span class="login-prompt-grip" aria-hidden="true"></span>

                <button
                  type="button"
                  class="login-prompt-close"
                  :aria-label="$t('ui.cartPage.close')"
                  @click="closeLoginPrompt"
                >
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M18 6 6 18M6 6l12 12"
                      stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                  </svg>
                </button>

                <span class="login-prompt-icon" aria-hidden="true">
                  <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
                    <rect x="4" y="10" width="16" height="11" rx="2.5"
                      stroke="currentColor" stroke-width="2"/>
                    <path d="M8 10V7a4 4 0 0 1 8 0v3"
                      stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <circle cx="12" cy="15.5" r="1.4" fill="currentColor"/>
                  </svg>
                </span>

                <h3 id="login-prompt-title" class="login-prompt-title">
                  {{ $t('ui.cartPage.loginRequired') }}
                </h3>
                <p class="login-prompt-text">
                  {{ $t('ui.cartPage.loginRequiredText') }}
                </p>

                <div class="login-prompt-actions">
                  <button ref="loginPromptPrimary" type="button" class="login-prompt-primary" @click="goToLogin">
                    {{ $t('ui.cartPage.login') }}
                  </button>
                  <button type="button" class="login-prompt-secondary" @click="goToRegister">
                    {{ $t('ui.cartPage.createAccount') }}
                  </button>
                </div>

                <button
                  v-if="setting && setting.guest_checkout"
                  type="button"
                  class="login-prompt-guest"
                  @click="continueAsGuest"
                >
                  {{ $t('ui.cartPage.continueAsGuest') }}
                </button>
              </div>
            </div>
          </transition>

          <!-- Upsell Popup -->
          <div v-if="showUpsellModal" class="modal-overlay">
            <div class="modal-content upsell-modal">
              <div class="modal-header">
                <h3>{{ $t('cart.upsellTitle') }}</h3>
                <button class="modal-close" @click="closeUpsellPopup">
                  <i class="icon close"></i>
                </button>
              </div>
              
              <div class="modal-body">
                <div v-if="loadingUpsells" class="upsell-skeleton">
                  <div
                    v-for="index in 3"
                    :key="`sk-upsell-${index}`"
                    class="upsell-product"
                  >
                    <div class="product-image">
                      <skeleton-base :width="80" :height="80" :radius="4" />
                    </div>
                    <div class="product-info">
                      <skeleton-base :height="14" width="82%" :mb="12" />
                      <skeleton-base :height="16" width="45%" />
                    </div>
                  </div>
                </div>
                
                <div v-else-if="upsellError" class="error-message">
                  <i class="icon error"></i>
                  <p>{{ upsellError }}</p>
                  <button class="retry-btn" @click="loadUpsellProducts">
                    {{ $t('cart.retry') }}
                  </button>
                </div>
                
                <!--
                  `bundleProducts`, not the raw list: one definition of what
                  is on offer, shared with the block further up the page, so
                  the popup and the basket page can never disagree about
                  which recommendations are live.
                -->
                <div v-else-if="bundleProducts.length" class="upsell-products">
                  <div class="upsell-product" v-for="product in bundleProducts" :key="product.id">
                    <div class="product-image lazy-media">
                      <lazy-image
                        :data-src="getThumbImageURL(product.image)"
                        :alt="product.title"
                        :title="product.title"
                      />
                    </div>
                    
                    <div class="product-info">
                      <h4>{{ product.title }}</h4>
                        
                        <template v-if="hasDiscount(product)">
                          <price-format 
                            :price="getOriginalPrice(product)" 
                            class="original-price strike-through"
                          />
                          <span class="discount-percent">
                            {{ $t('ui.cartPage.percentOff', { percent: getDiscountPercent(product) }) }}
                          </span>
                        </template>
                        
                        <div class="product-price">
                        <price-format 
                          :price="getUpsellPrice(product)" 
                          class="current-price"
                        />
                      </div>
                      
                      <!--
                        Only what happened just now, on THIS click.

                        There is no longer an availability error to render
                        beside a card: a product that cannot be bought is not
                        in this list at all. What is left is the narrow case
                        rule 9 is about -- it sold out between the list being
                        drawn and the button being pressed -- and that is a
                        message about an action, not a label on a product.
                      -->
                      <p v-if="product.addToCartError" class="add-to-cart-error">
                        {{ product.addToCartError }}
                      </p>
                    </div>
                    
                    <div class="product-actions">
                      <nuxt-link
                        v-if="product.requires_option"
                        class="add-to-cart-btn ajax-btn w-100 w-sm-50 primary-btn mtb-10 mlr-sm-2-5"
                        :to="productLink(product)"
                      >{{ $t('crossSell.chooseOne') }}</nuxt-link>
                      <button 
                        v-else
                        class="add-to-cart-btn ajax-btn w-100 w-sm-50 primary-btn mtb-10 mlr-sm-2-5"
                        :class="{
                          'added-to-cart': product.addedToCart,
                          'adding-to-cart': addingToCart === product.id,
                          'error-state': product.addToCartError
                        }"
                        @click="addUpsellToCart(product)"
                        :disabled="addingToCart === product.id || product.addedToCart"
                      >
                        <span v-if="addingToCart === product.id">
                          <spinner :radius="15" color="white" />
                        </span>
                        <span v-else-if="product.addedToCart">
                          {{ $t('cart.addedToCart') }}
                        </span>
                        <span v-else-if="product.addToCartError">
                          {{ $t('cart.unavailable') }}
                        </span>
                        <span v-else>{{ $t('cart.addToCart') }}</span>
                      </button>
                    </div>
                  </div>

                  <div class="upsell-footer">
                    <button 
                      class="skip-btn ajax-btn w-100 w-sm-50 primary-btn mtb-10 mlr-sm-2-5"
                      @click="closeUpsellPopup"
                      :disabled="addingToCart !== null"
                    >
                      {{ hasAddedItems ? $t('cart.continueToCheckout') : $t('cart.skipOffer') }}
                    </button>
                  </div>
                </div>
                
                <div v-else class="no-upsells">
                  <p>{{ $t('cart.noUpsellsAvailable') }}</p>
                  <button class="continue-btn ajax-btn primary-btn w-100" @click="goToAddress">
                    {{ $t('cart.continueToCheckout') }}
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </client-only>
</template>

<script>
  import {mapGetters, mapActions} from 'vuex'
  import AjaxButton from '~/components/AjaxButton'
  import CheckoutRight from '~/components/CheckoutRight'
  import CartList from '~/components/CartList'
  import util from '~/mixin/util'
  import productHelper from "~/mixin/productHelper";
  import PriceFormat from "~/components/PriceFormat";
  import global from '~/mixin/global'
  import loyaltyHelper from '~/mixin/loyaltyHelper'
  import Spinner from '~/components/Spinner'
  import productPriceHelper from '~/mixin/productPriceHelper'
  import NavigationStep from '../components/NavigationStep.vue'
  import Breadcrumbs from '../components/Breadcrumbs.vue'
  import UserAddress from '~/components/UserAddress'
  import '~/assets/css/checkout-sections.css'
  import PaymentGateways from "~/components/PaymentGateways";
  import SavedForLater from '~/components/SavedForLater'

  export default {
    middleware: ['common-middleware'],
    data() {
      return {
        voucherResult: null,
        addressPopup: false,
        editing: 0,
        preventGoing: true,
        checked: [],
        // bundleList: [],
        ajaxing: false,
        // one in-flight bulk action at a time: clearing and removing both walk
        // the basket line by line, and a second pass over a list that is still
        // shrinking deletes the wrong rows
        bulkBusy: false,
        cartError: null,
        isMounting: false,
        hasBundle: false,
        cartPrice: {
          totalItems: 0,
          totalPriceWithOffer: 0,
          totalPrice: 0,
        },
        addressData: {
          id: '',
          name: '',
          phone: '',
          city: '',
          country: '',
          state: '',
          zip: '',
          address_1: '',
          address_2: '',
          delivery_instruction: ''
        },
        // Upsell popup data
        showUpsellModal: false,
        upsellProducts: [],
        /*
         * True from the start, because the first render happens before
         * mounted() has finished asking. Starting false put a blank gap on
         * screen, then a skeleton, then the tiles -- two jumps where the
         * point of the skeleton is to have none.
         */
        loadingUpsells: true,
        addingToCart: null,
        upsellError: null,
        /*
         * Separates "not asked yet" from "asked, and there is nothing" -- the
         * skeleton needs the difference, and without it a basket with no
         * recommendations shimmers forever.
         */
        upsellsLoaded: false,
        autoCloseTimer: null, // Add timer reference
        loginPromptVisible: false,
        addingBundle: false
      }
    },
    components: {
      PriceFormat,
      AjaxButton,
      CheckoutRight,
      CartList,
      Spinner,
      UserAddress,
      PaymentGateways,
      SavedForLater
    },
    mixins: [util, productHelper, global, productPriceHelper, loyaltyHelper],
    watch: {
      /*
       * Show the loyalty saving in the basket, not just at checkout, and keep
       * it in step as items are added, removed or deselected: a group can
       * carry a minimum cart value and a cap, so the discount moves with the
       * subtotal.
       */
      'cartPrice.totalPriceWithOffer': {
        immediate: true,
        handler(subtotal) {
          this.loadLoyaltyDiscount(subtotal)

          /*
           * The Essentials Bundle saving is re-read on the same signal, and
           * for a sharper reason than the loyalty one: it stands only while
           * the basket holds a WHOLE bundle, so removing a single item ends it
           * for the rest. Asked of the server rather than worked out here --
           * the page cannot know what still qualifies, and the order endpoint
           * decides it again anyway.
           */
          this.$store.dispatch('cart/loadBundleDiscount')
        }
      },
      /*
       * A sheet that leaves the page scrolling behind it feels broken on a
       * phone, and one that cannot be dismissed with Escape feels broken on a
       * keyboard. Both are bound only while it is actually open.
       */
      loginPromptVisible(open) {
        if (typeof document === 'undefined') {
          return
        }

        if (open) {
          document.addEventListener('keydown', this.onLoginPromptKey)
          document.body.style.overflow = 'hidden'
          // Focus the action they came for, so Enter carries straight on.
          this.$nextTick(() => this.$refs.loginPromptPrimary?.focus())
          return
        }

        this.releaseLoginPrompt()
      }
    },
    computed: {
      totalPrice() {
        return this.cartPrice?.totalPrice || 0
      },
      /*
       * Cross-sells for the basket, shown inline. The same list previously
       * only appeared in a popup after pressing checkout, which is the one
       * moment a shopper is least interested in browsing extras.
       * Anything already in the basket is filtered out.
       */
      bundleProducts() {
        const inCart = this.cartProducts.map(c => Number(c.product_id))

        return this.upsellProducts.filter(p => !inCart.includes(Number(p.id)))
      },
      /*
       * What "Add Bundle" would actually add.
       *
       * Anything still needing a variant chosen is left out: the server
       * refuses it without one, so counting it here would advertise a saving
       * on something the button cannot buy.
       */
      bundleRemaining() {
        return this.bundleProducts.filter(p => !p.addedToCart && !p.requires_option)
      },
      // What the shopper keeps by taking the whole bundle rather than paying
      // each item's normal price.
      bundleSavings() {
        const saving = this.bundleRemaining.reduce((total, item) => {
          return total + Math.max(0, this.getOriginalPrice(item) - this.getUpsellPrice(item))
        }, 0)

        return saving > 0.005 ? saving : 0
      },
      checkedProduct() {
        this.checked = []
        let checkedP = []
        this.cartProducts.forEach(obj => {
          if (parseInt(obj.selected) === 1) {
            this.checked.push(obj.id)
            checkedP.push(obj)
          }
        })
        // Nothing ticked, or a ticked line that cannot be bought as it stands
        // (sold out, unpublished, more than is left): the checkout would only
        // be refused at the end, so it is not started.
        this.preventGoing = checkedP.length === 0 || this.blockedBasketLines.length > 0
        return checkedP
      },
      cartCount() {
        return this.$auth?.user?.cart_count
      },
      /*
       * Only once the fetch has finished. `cartProducts` is empty during the
       * first load too, and flashing "No items in your cart yet" at someone
       * whose basket is on its way is worse than the blank box was.
       */
      cartEmpty() {
        return !this.ajaxing && !this.cartProducts.length
      },
      /*
       * There is at least one line to act on.
       *
       * Not `!cartEmpty`: that is false while the first fetch is still in
       * flight, which put "(0 items)", a live "Clear Cart" and a
       * "Select All (0)" bar on screen over an empty basket every time the
       * page was opened.
       */
      hasLines() {
        return this.cartProducts.length > 0
      },
      // Check if any upsell items have been added to cart
      hasAddedItems() {
        return this.upsellProducts.some(product => product.addedToCart);
      },
      /*
       * selectAllItems leaves holes in `checked` (CartList deletes by index
       * rather than splicing), so the length of the array is not the number of
       * selected lines. Everything that counts the selection goes through here.
       */
      checkedCount() {
        return this.checked.filter(id => id !== undefined && id !== null).length
      },

      allSelected() {
        return this.cartProducts.length > 0 && this.checkedCount === this.cartProducts.length
      },
      ...mapGetters('language', ['langCode']),
      ...mapGetters('common', ['currencyIcon', 'setting']),
      ...mapGetters('cart', ['cartProducts', 'blockedBasketLines'])
    },
    methods: {
      goToShopping() {
        this.$router.push('/categories')
      },
      // A line just left the basket for the saved list, so reload that panel.
      refreshSaved() {
        this.$refs.savedForLater?.fetchSaved()
      },
      editAddress(value) {
        this.addressPopup = true
        this.editing = value.id
        this.addressData = Object.assign({}, value)
        this.states = this.countryList[value.country].states
      },

      /*
       * What a recommendation costs, and what it is struck through against.
       *
       * Both figures come from the server, which priced this exact product
       * for this exact customer with the same call the basket prices a line
       * with -- so the card and the till agree by construction. Nothing is
       * worked out here.
       *
       * These used to guess across four possible fields
       * (`offered_price || price || current_price`) because the legacy
       * endpoint answered with whichever it happened to have, and read the
       * "was" price from `selling_price` -- the product-level figure, not the
       * variant's, so a product whose cheapest buyable variant cost more than
       * the headline was advertised below what it charges.
       */
      getUpsellPrice(product) {
        return Number(product.price) || 0;
      },

      getOriginalPrice(product) {
        return Number(product.old_price) || 0;
      },

      hasDiscount(product) {
        return this.getOriginalPrice(product) > this.getUpsellPrice(product);
      },
      
      getDiscountPercent(product) {
        if (!this.hasDiscount(product)) return 0;
        
        const originalPrice = this.getOriginalPrice(product);
        const upsellPrice = this.getUpsellPrice(product);
        const discount = originalPrice - upsellPrice;
        const discountPercent = (discount / originalPrice) * 100;
        
        return Math.round(discountPercent);
      },
      
      async showUpsellPopup() {
        if (!this.$auth?.user) {
          this.loginPromptVisible = true
          return
        }
        /*
         * Rule 7: the recommendations are asked for BEFORE the popup opens,
         * and if nothing comes back the customer goes straight on to the
         * address step.
         *
         * It used to open on whether a basket line carried a legacy
         * `upsell_id`, which said nothing about whether that group still had
         * anything buyable in it -- so a shopper trying to check out was
         * regularly stopped by a panel reading "No offers available" with a
         * button to carry on doing what they had already asked to do.
         */
        await this.loadUpsellProducts();

        if (!this.bundleProducts.length) {
          this.goToAddress();

          return;
        }

        this.showUpsellModal = true;
      },

      // async loadBundleDeals(){
      //   try{
      //     const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/';
      //     const response = await this.$axios.get(`${baseUrl}api/v1/bundle-deals`);
      //     const data = response?.data?.data;
      //     this.bundleList = data;
      //   }catch(error){
      //     console.error("error", error);
      //   }
      // },
      
      /*
       * Load the cross-sells for whatever is in the basket so the inline
       * bundle can render on arrival. Silent on failure: a missing offer must
       * never stop somebody checking out.
       */
      async loadCartBundle() {
        if (!this.cartProducts.length) {
          this.upsellProducts = []
          this.loadingUpsells = false
          this.upsellsLoaded = true

          return
        }

        /*
         * Asked for the basket as a whole rather than for one line's group.
         *
         * This used to find the first line carrying an `upsell_id` and pull
         * that group's products. A group meant every product sharing it
         * offered the same accessories, the group was read straight out of
         * the table with no check that any of it was still buyable, and a
         * basket of five things only ever showed the first line's.
         */
        try {
          await this.loadUpsellProducts()
        } catch (e) {
          this.upsellProducts = []
        }
      },
      /*
       * Add one bundle item and reload the basket, so the new line and the
       * order summary update straight away rather than only on the next
       * navigation.
       */
      async addBundleItem(item) {
        await this.addUpsellToCart(item)
        await this.fetchingData()
      },
      // Add every bundle item that is not already in the basket.
      async addWholeBundle() {
        if (this.addingBundle) {
          return
        }

        this.addingBundle = true

        try {
          // snapshot first: the list is recomputed as items land in the basket
          const items = [...this.bundleRemaining]

          for (const item of items) {
            await this.addUpsellToCart(item)
          }

          await this.fetchingData()
        } finally {
          this.addingBundle = false
        }
      },
      // A missing image file must not render a broken-image box in the tile.
      onBundleImgError(e) {
        const fallback = this.getImageURL(this.defaultImage)

        if (e.target.src !== fallback) {
          e.target.src = fallback
        }
      },
      /**
       * The recommendations for what is in the basket.
       *
       * ONE request, and everything in the answer is already buyable: the
       * server checks status, stock, backorder, variants and this customer's
       * own eligibility, drops whatever fails and drops what the basket
       * already holds -- so this list is rendered as it arrives and the page
       * never has to decide whether a card deserves to be shown.
       *
       * What it replaces is worth naming, because both halves were wrong.
       * It read a legacy upsell GROUP straight out of the table -- no status
       * check and no stock check at all -- and then made one
       * `inventory/find/{product}` call PER product to work out availability
       * on the client. Ten recommendations were eleven round trips, the
       * answer only ever looked at the FIRST inventory row (so a product
       * whose second variant was the one in stock read as sold out), it
       * ignored the backorder flag the basket itself honours, and anything
       * that failed was drawn as a card saying "Out of Stock" next to an
       * "Add to cart" button.
       */
      async loadUpsellProducts() {
        this.loadingUpsells = true;
        this.upsellError = null;

        try {
          const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/';

          const response = await this.$axios.get(`${baseUrl}api/v1/cart/cross-sells`, {
            params: {user_token: await this.getUserToken()}
          });

          this.upsellProducts = (response.data?.data?.items || []).map(item => ({
            ...item,
            /*
             * The cards in this page were written against the catalogue's
             * own column names. The recommendation carries `name`, so it is
             * given a `title` as well rather than every template being
             * rewritten around it.
             */
            title: item.name,
            addedToCart: false,
            addToCartError: null
          }));

        } catch (error) {
          /*
           * Rule 15: the basket is what this page is for, and it is already
           * on screen and working. A recommendation block that could not be
           * built says so in its own corner and changes nothing else.
           */
          console.error('Error loading cross-sell products:', error);
          this.upsellError = this.$t('cart.upsellLoadError');
          this.upsellProducts = [];
        } finally {
          this.loadingUpsells = false;
          this.upsellsLoaded = true;
        }
      },

      async addUpsellToCart(product) {
        // Clear any existing auto-close timer
        if (this.autoCloseTimer) {
          clearTimeout(this.autoCloseTimer);
          this.autoCloseTimer = null;
        }

        this.addingToCart = product.id;
        this.$set(product, 'addToCartError', null);
        
        try {
          const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/';
          const userToken = await this.getUserToken();

          /*
           * Added through the cross-sell endpoint, which proves the product
           * again before it writes anything: still published, still offered
           * under a live promotion, still has this variant, still buyable.
           *
           * Rule 9 -- stock can change between the card being drawn and this
           * click, and the answer to that is a clear message, not a silent
           * failure and not a basket line for something that cannot ship.
           *
           * No price is sent. The server prices the line from the catalogue
           * for this customer, the same way every other basket write does.
           * The old call sent `price` and the server ignored it, which made
           * the field look meaningful when it never was.
           */
          const {data} = await this.$axios.post(`${baseUrl}api/v1/cart/cross-sell`, {
            user_token: userToken,
            products: [{
              product_id: Number(product.id),
              inventory_id: product.inventory_id || undefined,
              quantity: 1
            }]
          });

          await this.getCartByUser({
            lang: this.langCode,
            params: {
              user_token: userToken
            }
          });

          /*
           * A refusal arrives as HTTP 201 with the reason in the body, which
           * axios reads as success -- so it is read out of `status`, never
           * from the presence of a message. The old check was
           * `response.data.message != ''`, which is true of the SUCCESS
           * message too: every successful add was relabelled "out of stock"
           * and left untickable.
           */
          const refusal = data?.status === 200
            ? (data.data?.skipped || [])[0]
            : {message: data?.message};

          if (refusal) {
            this.$set(product, 'addToCartError',
              refusal.message || this.$t('cart.outStockMessage'));
            this.$set(product, 'addedToCart', false);
            this.addingToCart = null;

            /*
             * Re-read, so a recommendation that has just been refused for
             * being unavailable leaves the page instead of sitting there
             * inviting a second try.
             */
            await this.loadUpsellProducts();

            return;
          }

          this.$set(product, 'addedToCart', true);

          // Set a timer to auto-close after success, but don't close immediately
          this.autoCloseTimer = setTimeout(() => {
            if (this.addingToCart === product.id) {
              this.addingToCart = null;
            }
          }, 1500);

        } catch (error) {
          console.error('Error adding to cart:', error);
          
          let errorMessage = this.$t('cart.addToCartError');
          
          if (error.response?.data?.message) {
            errorMessage = error.response.data.message;
          } else if (error.message) {
            errorMessage = error.message;
          }
          
          this.$set(product, 'addToCartError', errorMessage);
          //this.$toast.error(errorMessage);
          
        } finally {
          this.addingToCart = null;
        }
      },
      
      closeUpsellPopup() {
        // Clear any pending auto-close timer
        if (this.autoCloseTimer) {
          clearTimeout(this.autoCloseTimer);
          this.autoCloseTimer = null;
        }
        
        this.showUpsellModal = false;
        this.upsellError = null;
        this.goToAddress();
      },
      
      /*
       * Checking out without an account is a choice the shopper makes, not one
       * made for them: everyone signed out gets the sign-in prompt, which
       * offers guest checkout alongside signing in and creating an account.
       */
      goToAddress() {
        if (!this.$auth?.user) {
          this.loginPromptVisible = true
          return
        }
        this.$router.push('/shipping');
      },

      closeLoginPrompt() {
        this.loginPromptVisible = false
      },

      onLoginPromptKey(e) {
        if (e.key === 'Escape' || e.key === 'Esc') {
          this.closeLoginPrompt()
        }
      },

      releaseLoginPrompt() {
        if (typeof document === 'undefined') {
          return
        }

        document.removeEventListener('keydown', this.onLoginPromptKey)
        document.body.style.overflow = ''
      },

      /*
       * nuxt-auth redirects to its "home" target the moment the user is logged
       * in, and with rewriteRedirects on it prefers whatever sits in its own
       * redirect storage. Parking /shipping there is what carries the shopper
       * back into checkout rather than to the shop home, and it survives the
       * two-factor screen too, since that finishes through the same store.
       */
      rememberCheckoutRedirect() {
        try {
          this.$auth?.$storage?.setUniversal('redirect', '/shipping')
        } catch (e) {
          // Storage unavailable; sign-in still works, it just lands on home.
        }
      },

      goToLogin() {
        this.rememberCheckoutRedirect()
        this.loginPromptVisible = false
        this.$router.push('/login')
      },

      goToRegister() {
        this.rememberCheckoutRedirect()
        this.loginPromptVisible = false
        this.$router.push('/register')
      },

      continueAsGuest() {
        this.loginPromptVisible = false
        this.$router.push('/guest-checkout')
      },
      
      async selectAllItems() {
        this.checked = []
        this.cartProducts.forEach((obj) => {
          this.checked.push(obj.id)
        })
        await this.cartChanged({
          payload: {
            checked: this.checked
          },
          lang: this.langCode
        })
        await this.cartChangedApi()
      },
      
      async deselectAllItems() {
        this.checked = []
        await this.cartChanged({
          payload: {
            checked: this.checked
          },
          lang: this.langCode
        })
        await this.cartChangedApi()
      },
      
      /*
       * Bulk actions on the basket.
       *
       * There is no bulk endpoint, so each of these walks the lines one at a
       * time. They collect the ids first: cartDelete refetches the basket on
       * every success, so iterating cartProducts directly would be walking a
       * list that is being replaced underneath the loop.
       */
      async removeLines(ids) {
        const userToken = await this.getUserToken()
        let removed = 0

        for (const id of ids) {
          try {
            await this.cartDelete({
              payload: { id: id, user_token: userToken },
              lang: this.langCode
            })
            removed++
          } catch (e) {
            this.setToastError(e?.message || this.$t('ui.cartPage.removeItemError'))
            break
          }
        }

        return removed
      },

      async clearCart() {
        if (this.bulkBusy || this.cartEmpty) return
        if (!confirm(this.$t('ui.cartPage.clearCartConfirm'))) return

        this.bulkBusy = true

        try {
          const ids = this.cartProducts.map(obj => obj.id)
          const removed = await this.removeLines(ids)

          if (removed) this.setToastMessage(this.$t('ui.cartPage.cartCleared'))

          await this.fetchingData()
        } finally {
          this.bulkBusy = false
        }
      },

      async removeSelected() {
        if (this.bulkBusy || !this.checkedCount) return
        if (!confirm(this.$tc('ui.cartPage.removeSelectedConfirm', this.checkedCount, { count: this.checkedCount }))) return

        this.bulkBusy = true

        try {
          const ids = this.checked.filter(id => id !== undefined && id !== null)
          const removed = await this.removeLines(ids)

          if (removed) {
            this.setToastMessage(this.$tc('ui.cartPage.removedCount', removed, { count: removed }))
          }

          await this.fetchingData()
        } finally {
          this.bulkBusy = false
        }
      },

      /*
       * The same endpoint the per-line save used, once per selected line. A
       * guest is identified by the token that owns the basket the line is being
       * taken out of.
       */
      async saveSelectedForLater() {
        if (this.bulkBusy || !this.checkedCount) return

        this.bulkBusy = true

        try {
          const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'
          const userToken = this.$auth?.loggedIn ? null : await this.getUserToken()
          const ids = this.checked.filter(id => id !== undefined && id !== null)
          let saved = 0

          for (const id of ids) {
            try {
              const { data } = await this.$axios.post(`${baseUrl}api/v1/user/save-for-later/action`, {
                cart_id: id,
                user_token: userToken
              })

              if (data?.status !== 200) {
                this.setToastError(data?.message || this.$t('ui.cartPage.saveItemError'))
                break
              }

              saved++
            } catch (e) {
              this.setToastError(e?.message || this.$t('ui.cartPage.saveItemError'))
              break
            }
          }

          if (saved) {
            this.setToastMessage(this.$tc('ui.cartPage.movedToSaved', saved, { count: saved }))
          }

          await this.fetchingData()
          this.refreshSaved()
        } finally {
          this.bulkBusy = false
        }
      },

      async cartChangedApi(bundleDeal = false) {
        await this.cartChanged({
          payload: {
            checked: this.checked,
            unchecked: this.unchecked,
            isBundle: bundleDeal
          },
          lang: this.langCode
        })
      },
      
      settingChecked() {
        this.checkedProduct = []
        this.checked = []

        this.cartProducts.forEach(obj => {
          if (parseInt(obj.selected) === 1) {
            this.checked.push(obj.id)
            this.checkedProduct.push(obj)
          }
        })
      },
      
      /*
       * A basket that cannot be refreshed is a failed request, not a broken
       * route. This used to call $nuxt.error(), which replaced the entire page
       * - header, nav and all - with "Something went wrong", most often when
       * the guest token had not been issued yet or the API dropped one call of
       * the several this page fires at once. Now the failure stays inside the
       * basket card, with the reason and a way to try again.
       */
      async fetchingData({ retry = true } = {}) {
        this.ajaxing = true
        this.cartError = null

        try {
          await this.getCartByUser({
            lang: this.langCode,
            params: {
              user_token: await this.getUserToken()
            }
          })
        } catch (e) {

          console.error('Cart could not be loaded', e)

          if (retry) {
            // Most of these are transient - a queued request, a token that
            // arrived a moment later - and one quiet retry clears them.
            await new Promise(resolve => setTimeout(resolve, 1200))
            return this.fetchingData({ retry: false })
          }

          this.cartError = e?.message || this.$t('ui.cartPage.loadError')
        }


        this.ajaxing = false
      },

      ...mapActions('resource', ['setCountryList', 'setPhoneList']),
      ...mapActions('common', ['fetchLocation', 'setToastMessage', 'setToastError', 'getRequest']),
      ...mapActions('cart', [
        'getCartByUser', 'cartChanged', 'cartDelete', 'updateCartShipping',
        'cartAction', 'cancelBuyNow'
      ]),
      ...mapActions('user', ['getUserToken', 'userAddressAction'])
    },
    async asyncData({store, $auth, error}) {
      try {
        if(!store.state?.common?.setting?.guest_checkout) {
          if (!$auth.loggedIn) {
            $auth.redirect('login')
            return false
          }
        }
        if(!store.state.common.paymentGateway){
          const data = await store.dispatch('common/getRequest', {
            params: {},
            api: 'paymentGateway'
          })
          store.commit('common/SET_PAYMENT_GATEWAY', data.data)
        }
      } catch (e) {
        error(e)
      }
    },
    async mounted() {
      // Back at the basket: any Buy Now checkout is over, so the next checkout
      // started here is the basket's (Buy Now never touches the basket).
      await this.cancelBuyNow()
      await this.fetchingData()
      await this.loadCartBundle()
      // await this.loadBundleDeals();
    },

    /*
     * One hook, because a component is one object literal: this was declared
     * twice, so the earlier of the two never ran at all. The timer was left
     * pending and releaseLoginPrompt() never fired, which is why navigating
     * away from the basket with the sign-in sheet open left the next page
     * unable to scroll.
     */
    beforeDestroy() {
      if (this.autoCloseTimer) {
        clearTimeout(this.autoCloseTimer)
      }

      this.releaseLoginPrompt()

      /*
       * The discount is priced against the basket as it stands now. Leaving it
       * in the store would show a stale saving on the next page before the
       * fresh lookup lands.
       */
      this.clearLoyaltyDiscount()
    }
  }
</script>

<style>
/* Shown above "Proceed to checkout" while a ticked line cannot be bought. */
.basket-blocked-note {
  margin: 12px 0;
  padding: 10px 14px;
  border-radius: 8px;
  background: #fff4e5;
  color: #8a4b00;
  font-size: 14px;
  line-height: 1.4;
}
.modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background-color: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

.modal-content {
  background: white;
  border-radius: 8px;
  max-width: 90%;
  max-height: 90%;
  overflow: auto;
}

.upsell-modal {
  width: 800px;
  max-width: 95%;
}

/* --- "you need an account to check out" sheet ---------------------------- */

/* Its own overlay rather than the shared .modal-overlay, so the sheet can sit
   at the bottom of a phone screen without dragging the upsell popup with it. */
.login-prompt-overlay {
  position: fixed;
  inset: 0;
  z-index: 1100;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  background: rgba(19, 14, 43, .55);
  -webkit-backdrop-filter: blur(2px);
  backdrop-filter: blur(2px);
}

.login-prompt-card {
  position: relative;
  width: 100%;
  max-width: 400px;
  max-height: calc(100vh - 40px);
  overflow-y: auto;
  padding: 28px 26px 24px;
  border-radius: 16px;
  background: #fff;
  box-shadow: 0 20px 50px rgba(19, 14, 43, .25);
  text-align: center;
}

.login-prompt-grip {
  display: none;
}

.login-prompt-close {
  position: absolute;
  top: 12px;
  right: 12px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  padding: 0;
  border: 0;
  border-radius: 50%;
  background: transparent;
  color: #8a8fa3;
  cursor: pointer;
  transition: background .15s ease, color .15s ease;
}
.login-prompt-close:hover {
  background: #f2f3f8;
  color: #130e2b;
}

.login-prompt-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 52px;
  height: 52px;
  margin-bottom: 14px;
  border-radius: 50%;
  background: #f0effb;
  color: var(--primary-color, #33319a);
}

.login-prompt-title {
  margin: 0 0 8px;
  font-size: 18px;
  font-weight: 700;
  color: #130e2b;
}

.login-prompt-text {
  margin: 0 auto 22px;
  max-width: 300px;
  font-size: 13px;
  line-height: 1.65;
  color: #6b7280;
}

.login-prompt-actions {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

/* Not .primary-btn: that one is a fixed 50px with a baked-in line-height, which
   clips as soon as a label wraps on a narrow phone. */
.login-prompt-primary,
.login-prompt-secondary {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  min-height: 46px;
  padding: 12px 18px;
  border-radius: 10px;
  font-size: 14px;
  font-weight: 700;
  line-height: 1.2;
  cursor: pointer;
  transition: background .18s ease, border-color .18s ease, color .18s ease;
}

.login-prompt-primary {
  border: 1px solid transparent;
  background: var(--primary-color, #33319a);
  color: #fff;
}
.login-prompt-primary:hover {
  background: var(--primary-hover-color, #16155f);
}

.login-prompt-secondary {
  border: 1px solid #d7d9e6;
  background: #fff;
  color: #130e2b;
}
.login-prompt-secondary:hover {
  border-color: var(--primary-color, #33319a);
  color: var(--primary-color, #33319a);
}

.login-prompt-guest {
  display: block;
  width: 100%;
  margin-top: 14px;
  padding: 6px;
  border: 0;
  background: none;
  font-size: 13px;
  color: #6b7280;
  text-decoration: underline;
  cursor: pointer;
}
.login-prompt-guest:hover {
  color: #130e2b;
}

.login-prompt-primary:focus-visible,
.login-prompt-secondary:focus-visible,
.login-prompt-guest:focus-visible,
.login-prompt-close:focus-visible {
  outline: 2px solid var(--primary-color, #33319a);
  outline-offset: 2px;
}

/* Fades on desktop; the phone rules below turn it into a slide-up. */
.login-prompt-enter-active,
.login-prompt-leave-active {
  transition: opacity .2s ease;
}
.login-prompt-enter-active .login-prompt-card,
.login-prompt-leave-active .login-prompt-card {
  transition: transform .22s ease, opacity .22s ease;
}
.login-prompt-enter,
.login-prompt-leave-to {
  opacity: 0;
}
.login-prompt-enter .login-prompt-card,
.login-prompt-leave-to .login-prompt-card {
  opacity: 0;
  transform: translateY(12px) scale(.97);
}

@media (max-width: 575px) {
  /* A bottom sheet: the actions land under the thumb instead of floating in
     the middle of the screen where they are hardest to reach. */
  .login-prompt-overlay {
    align-items: flex-end;
    padding: 0;
  }
  .login-prompt-card {
    max-width: none;
    max-height: 88vh;
    padding: 8px 20px 20px;
    padding-bottom: calc(20px + env(safe-area-inset-bottom));
    border-radius: 20px 20px 0 0;
  }
  .login-prompt-grip {
    display: block;
    width: 40px;
    height: 4px;
    margin: 0 auto 16px;
    border-radius: 4px;
    background: #e3e5ee;
  }
  .login-prompt-close {
    top: 14px;
    right: 10px;
  }
  .login-prompt-icon {
    width: 46px;
    height: 46px;
    margin-bottom: 12px;
  }
  .login-prompt-title {
    font-size: 17px;
  }
  .login-prompt-text {
    max-width: none;
  }
  /* Comfortable tap targets, per the 44px minimum. */
  .login-prompt-primary,
  .login-prompt-secondary {
    min-height: 48px;
  }
  .login-prompt-guest {
    padding: 10px 6px;
  }
  .login-prompt-enter .login-prompt-card,
  .login-prompt-leave-to .login-prompt-card {
    transform: translateY(100%);
    opacity: 1;
  }
}

/* A short landscape phone has no room for a sheet; centre it and let the card
   scroll instead of pushing the buttons off-screen. */
@media (max-height: 460px) {
  .login-prompt-overlay {
    align-items: center;
    padding: 12px;
  }
  .login-prompt-card {
    max-height: calc(100vh - 24px);
    border-radius: 14px;
    padding-top: 20px;
  }
  .login-prompt-grip {
    display: none;
  }
  .login-prompt-icon {
    display: none;
  }
}

@media (prefers-reduced-motion: reduce) {
  .login-prompt-enter-active,
  .login-prompt-leave-active,
  .login-prompt-enter-active .login-prompt-card,
  .login-prompt-leave-active .login-prompt-card {
    transition: none;
  }
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 20px;
  border-bottom: 1px solid #eee;
}

.modal-close {
  background: none;
  border: none;
  font-size: 24px;
  cursor: pointer;
}

.modal-body {
  padding: 20px;
}

.error-message {
  text-align: center;
  padding: 20px;
  color: #d32f2f;
}

.retry-btn {
  margin-top: 10px;
  padding: 8px 16px;
  background: #1976d2;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
}

.upsell-products {
  display: grid;
  gap: 20px;
}

.upsell-product {
  display: flex;
  gap: 15px;
  padding: 15px;
  border: 1px solid #eee;
  border-radius: 8px;
  align-items: center;
}

.product-image {
  width: 80px;
  height: 80px;
  position: relative;
}

.product-image img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  border-radius: 4px;
}

.product-info {
  flex: 1;
}

.product-info h4 {
  margin: 0 0 8px 0;
  font-size: 16px;
}

.product-price {
  margin: 0 0 8px 0;
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.current-price {
  font-weight: bold;
  color: #1976d2;
  font-size: 20px;
}

.original-price {
  text-decoration: line-through;
  color: #999;
  font-size: 14px;
}

.discount-percent {
  color: #d32f2f;
  font-weight: bold;
  font-size: 14px;
  background: #ffebee;
  padding: 2px 6px;
  border-radius: 4px;
}

.inventory-error,
.add-to-cart-error {
  color: #d32f2f;
  margin: 5px 0 0 0;
  font-size: 14px;
}

.product-actions {
  min-width: 120px;
}

.add-to-cart-btn {
  padding: 10px 15px;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-weight: bold;
  transition: all 0.3s;
}

.add-to-cart-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.add-to-cart-btn.added-to-cart {
  background: green !important;
  opacity:1 !important;
}

.add-to-cart-btn.error-state {
  background: #d32f2f !important;
}

.add-to-cart-btn.adding-to-cart {
  opacity: 0.8;
}

.upsell-footer {
  margin-top: 30px;
  text-align: center;
}

.skip-btn {
  padding: 12px 24px;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-weight: bold;
}

.skip-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.no-upsells {
  text-align: center;
  padding: 40px 20px;
}

.continue-btn {
  padding: 12px 24px;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-weight: bold;
  margin-top: 20px;
}

.ajax-btn {
  position: relative;
}

.ajax-btn:disabled::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(255, 255, 255, 0.5);
}

.strike-through {
  text-decoration: line-through;
}

.save-address {
 width: 330px;
}

.address-card {
  border: 1px solid #dcdde1;
  border-radius: 12px;
  padding: 18px;
  position: relative;
  background: #fff;
  cursor: pointer;
  transition: 0.3s;
  height: 100%;
}

.address-card.active {
  border: 2px solid #333199;
  background: #f3f4ff;
}

.check-icon {
  position: absolute;
  top: -10px;
  right: -10px;
  background: #333199;
  color: #fff;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  display: none;
  align-items: center;
  justify-content: center;
  font-size: 14px;
}

.address-card.active .check-icon {
  display: flex;
}

.new-address {
  width: 160px;
}

.add-card {
  border: 2px dashed #dcdde1;
  border-radius: 16px;
  background: #f8f9fc;
  cursor: pointer;
  min-height: 180px;
  transition: 0.3s;
}

.add-card:hover {
  background: #eef0ff;
  border-color: #4b4bff;
}

.plus-circle {
  width: 50px;
  height: 50px;
  border-radius: 50%;
  background: #fff;
  border: 1px solid #dcdde1;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 26px;
  color: #333;
}

.form-label {
    margin-bottom: 10px;
    color: #130E2B;
    font-size: 14px;
    font-weight: 500;
}
.delivery-filed {
    padding: 12px 12px !important;
    border-radius: 10px;
    font-size: 14px;
}
span.default-address {
    font-size: 14px;
    font-weight: 500;
    color: #130E2B;
}
/* The cart line spaces its own price row now. The 35px forced here pushed the
   price a full line away from the badge above it at every breakpoint, and the
   !important meant the component could not correct it. */

@media (max-width: 768px) {
  .upsell-product {
    flex-direction: column;
    text-align: center;
  }
  
  .product-actions {
    width: 100%;
  }
  
  .modal-content {
    margin: 20px;
  }
  
  .product-price {
    justify-content: center;
  }
}

/* ---------------------------------------------------------------------------
   Cart layout: each section is its own white card, matching the rest of the
   checkout flow. Previously the left column was bare content on the page
   background, which made the basket, the address and the payment picker read
   as one undifferentiated block.
   --------------------------------------------------------------------------- */
.cart-card {
  background: #fff;
  border: 1px solid #e8eaf1;
  border-radius: 14px;
  padding: 16px;
  margin-bottom: 14px;
}
.cart-card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 14px;
}
.cart-card-title {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0;
  font-size: 14px;
  font-weight: 700;
  color: #130e2b;
}
.cart-count {
  font-size: 12px;
  font-weight: 400;
  color: #8a8fa3;
}
.cart-select-link {
  font-size: 12px;
  font-weight: 500;
  text-decoration: underline;
}

/* -----------------------------------------------------------------------
   Laid out in normal flow with margins of its own rather than as a flex
   column with `gap`. The flex version put the button over the last line of
   the copy - "...ready for checkout." was half covered by the pill - and
   block-level children with explicit margins cannot overlap whatever else
   the page's stylesheets have to say about p and button.
   ----------------------------------------------------------------------- */
.cart-empty {
  text-align: center;
  padding: 56px 20px 52px;
}
.cart-empty-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 72px;
  height: 72px;
  margin-bottom: 18px;
  border-radius: 50%;
  background: #f2f4fb;
  color: #6b6f8c;
}
.cart-empty-title {
  display: block;
  margin: 0 0 8px;
  font-size: 17px;
  font-weight: 700;
  color: #130e2b;
}
.cart-empty-text {
  display: block;
  max-width: 360px;
  margin: 0 auto 26px;
  font-size: 13px;
  line-height: 1.6;
  color: #8a8fa3;
}
.cart-empty-btn {
  display: inline-block;
  margin: 0;
  padding: 12px 32px;
  border: 0;
  border-radius: 30px;
  background: #232159;
  color: #fff;
  font-size: 14px;
  font-weight: 600;
  line-height: 1.2;
}
.cart-empty-btn:hover {
  background: #1a1845;
}
.cart-empty-btn:disabled {
  opacity: .65;
}

.cart-error {
  text-align: center;
  padding: 48px 20px 44px;
}
.cart-error-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 64px;
  height: 64px;
  margin-bottom: 16px;
  border-radius: 50%;
  background: #fdeceb;
  color: #d14343;
}
.cart-error-title {
  display: block;
  margin: 0 0 8px;
  font-size: 17px;
  font-weight: 700;
  color: #130e2b;
}
.cart-error-text {
  display: block;
  max-width: 380px;
  margin: 0 auto 24px;
  font-size: 13px;
  line-height: 1.6;
  color: #8a8fa3;
}

/* the accordions are cards too, so drop the framework's own chrome */
.cart-accordion .accordion-item {
  border: 0;
  background: transparent;
}
.cart-accordion .accordion-button {
  padding: 0;
  background: transparent;
  box-shadow: none;
  font-size: 16px;
  font-weight: 600;
  color: #130e2b;
}
.cart-accordion .accordion-button::after {
  margin-left: auto;
}
/* the icon labels each section at a glance, matching the summary card */
.cart-accordion .acc-ico {
  flex: 0 0 auto;
  margin-right: 9px;
  color: #33319a;
}

/* --- frequently bought together ------------------------------------------ */
.cart-fbt-title {
  margin-bottom: 14px;
  color: #33319a;
}
.cart-fbt-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 150px), 1fr));
  gap: 12px;
}
.cart-fbt-item {
  display: flex;
  flex-direction: column;
  border: 1px solid #eceef4;
  border-radius: 10px;
  padding: 10px;
  min-width: 0;
}
.cart-fbt-img {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 92px;
  margin-bottom: 8px;
}
.cart-fbt-img img {
  max-width: 100%;
  max-height: 92px;
  object-fit: contain;
}
.cart-fbt-name {
  margin: 0 0 10px;
  font-size: 13px;
  line-height: 1.4;
  color: #130e2b;
  /* two lines, then ellipsis, so tiles in a row stay the same height */
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.cart-fbt-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-top: auto;
}
.cart-fbt-price {
  display: flex;
  align-items: baseline;
  flex-wrap: wrap;
  gap: 6px;
  min-width: 0;
}
.cart-fbt-price strong {
  font-size: 15px;
  color: #130e2b;
}
.cart-fbt-price s {
  font-size: 12px;
  color: #9aa0b5;
}
.cart-fbt-item .cart-fbt-add {
  flex: 0 0 auto;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0;
  width: 28px;
  height: 28px;
  line-height: 1;
  font-size: 16px;
  border: 1px solid #d7d9e6;
  border-radius: 8px;
  background: #f6f5fd;
  color: #33319a;
  cursor: pointer;
}
.cart-fbt-item .cart-fbt-add:hover:not(:disabled) {
  background: #e9e8fb;
}
.cart-fbt-item .cart-fbt-add:disabled {
  color: #0f9d58;
  border-color: #bfe6cf;
  background: #eaf7ef;
  cursor: default;
}

/* --- add-the-whole-bundle bar -------------------------------------------- */
.cart-bundle-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
  margin-top: 14px;
  padding: 12px 14px;
  background: #f2f1fd;
  border: 1px solid #e0defa;
  border-radius: 10px;
}
.cart-bundle-copy {
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.cart-bundle-copy strong {
  font-size: 14px;
  color: #130e2b;
}
.cart-bundle-copy span {
  font-size: 12px;
  color: #5c6079;
}
/*
 * Scoped under .cart-bundle-bar to outrank the global button reset in
 * assets/styles/base.styl, which forces every <button> to a fixed
 * height: 50px with line-height: 48px (btn-height) and padding: 0. Left to
 * those, this button ignores its own padding and sizes to 50px regardless of
 * its text, so the height and line-height are reset here explicitly rather
 * than fought with padding.
 */
.cart-bundle-bar .cart-bundle-btn {
  flex: 0 0 auto;
  height: auto;
  min-height: 0;
  line-height: 1.25;
  padding: 10px 18px;
  font-size: 14px;
  font-weight: 600;
  color: #33319a;
  background: #fff;
  border: 1px solid #33319a;
  border-radius: 9px;
  cursor: pointer;
  white-space: nowrap;
  transition: background .15s, color .15s;
}
.cart-bundle-bar .cart-bundle-btn:hover:not(:disabled) {
  background: #33319a;
  color: #fff;
}
.cart-bundle-bar .cart-bundle-btn:disabled {
  opacity: .55;
  cursor: not-allowed;
}

@media (max-width: 575px) {
  .cart-card { padding: 16px; }
  .cart-bundle-bar { flex-direction: column; align-items: stretch; }
  .cart-bundle-bar .cart-bundle-btn { width: 100%; }
}

/* ---------------------------------------------------------------------------
   Mobile spacing.

   The stepper carries py-5 and the page container my-5, so on a phone roughly
   96px of empty space sat between the progress steps and the basket — a whole
   thumb-scroll of nothing before any content.
   --------------------------------------------------------------------------- */
/* the stepper already spaces itself from the basket; my-5 on top of that was
   the second half of the same gap */
.cart-page.my-5 {
  margin-top: 0 !important;
}

@media (max-width: 767px) {
  .cart-page.my-5 {
    margin-bottom: 24px !important;
  }
  .cart-page {
    padding-left: 12px;
    padding-right: 12px;
  }
  .cart-card {
    padding: 14px;
    margin-bottom: 12px;
    border-radius: 12px;
  }
  .cart-card-head {
    margin-bottom: 10px;
  }
  .cart-card-title {
    font-size: 14px;
  }

  /* two across on a phone rather than a single tall column of tiles */
  .cart-fbt-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 8px;
  }
  .cart-fbt-img {
    height: 64px;
  }
  .cart-fbt-img img {
    max-height: 64px;
  }
  .cart-fbt-name {
    font-size: 11px;
  }
  .cart-fbt-price strong {
    font-size: 13px;
  }
  /* three tiles across leaves no room for price and "was" side by side */
  .cart-fbt-price {
    flex-direction: column;
    gap: 0;
    line-height: 1.25;
  }
  .cart-fbt-price s {
    font-size: 10px;
  }
  .cart-fbt-item {
    padding: 8px;
  }
  .cart-fbt-item .cart-fbt-add {
    width: 24px;
    height: 24px;
    font-size: 15px;
    border-radius: 7px;
  }
}

@media (max-width: 359px) {
  /* very small handsets: one tile per row is more legible than two cramped */
  .cart-fbt-grid { grid-template-columns: 1fr; }
}

/* ===========================================================================
   Cart page, compact treatment.

   Appended last so it wins over the panel styling above at equal specificity.
   The page is a stack of tight cards on white: the basket lines carry their
   own borders, so their container is a plain stack rather than a second panel
   drawn around them.
   =========================================================================== */

/* --- page head, spanning both columns ------------------------------------- */
.cart-page-head {
  display: flex;
  align-items: baseline;
  gap: 10px;
  padding-bottom: 10px;
}

.cart-page-title {
  margin: 0;
  font-size: 30px;
  font-weight: 800;
  letter-spacing: -.022em;
  line-height: 1.1;
  color: #0F2547;
}

.cart-page-head .cart-count {
  font-size: 16px;
  font-weight: 400;
  color: #6B7C93;
}

/* destructive, so it is a quiet link rather than a button competing with
   "Proceed to checkout" */
.cart-clear-btn {
  margin-left: auto;
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 0;
  border: 0;
  background: none;
  font-size: 14px;
  font-weight: 500;
  color: #1B3355;
  text-decoration: underline;
  text-underline-offset: 3px;
  cursor: pointer;
}

.cart-clear-btn svg {
  color: #6B7C93;
}

.cart-clear-btn:hover:not(:disabled),
.cart-clear-btn:hover:not(:disabled) svg {
  color: #C8324B;
}

.cart-clear-btn:disabled {
  opacity: .5;
  cursor: default;
}

/* --- the list container --------------------------------------------------- */
.cart-list-card {
  padding: 0;
  margin-bottom: 0;
  border: 0;
  border-radius: 0;
  background: none;
}

/* the empty and error states still need a panel to sit in */
.cart-state-card {
  padding: 16px;
  margin-bottom: 10px;
  border: 1px solid #E3E8F0;
  border-radius: 10px;
  background: #fff;
}

/* --- bulk actions --------------------------------------------------------- */
.cart-bulk {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px 14px;
  padding: 1px 2px 3px;
  margin-bottom: 10px;
  font-size: 13.5px;
  color: #1B3355;
}

.cart-bulk-all {
  position: relative;
  display: inline-flex;
  align-items: center;
  gap: 9px;
  margin: 0;
  font-weight: 500;
  white-space: nowrap;
  cursor: pointer;
}

.cart-bulk-all input {
  position: absolute;
  opacity: 0;
  width: 0;
  height: 0;
}

.cart-bulk-box {
  flex: 0 0 auto;
  position: relative;
  width: 22px;
  height: 22px;
  border: 1.5px solid #D6DEEA;
  border-radius: 6px;
  background: #fff;
  transition: background .12s, border-color .12s;
}

.cart-bulk-all input:checked + .cart-bulk-box {
  background: #1A56DB;
  border-color: #1A56DB;
}

.cart-bulk-box::after {
  content: "";
  position: absolute;
  left: 7.5px;
  top: 3.5px;
  width: 5px;
  height: 10px;
  border: solid #fff;
  border-width: 0 2.2px 2.2px 0;
  transform: rotate(45deg) scale(0);
  transition: transform .12s;
}

.cart-bulk-all input:checked + .cart-bulk-box::after {
  transform: rotate(45deg) scale(1);
}

.cart-bulk-all input:focus-visible + .cart-bulk-box {
  outline: 2px solid rgba(26, 86, 219, .45);
  outline-offset: 2px;
}

.cart-bulk-sep {
  width: 1px;
  height: 17px;
  background: #D6DEEA;
}

.cart-bulk-btn {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 0;
  border: 0;
  background: none;
  font-size: 13.5px;
  font-weight: 500;
  white-space: nowrap;
  color: #1B3355;
  cursor: pointer;
}

.cart-bulk-btn svg {
  color: #6B7C93;
}

.cart-bulk-btn:hover:not(:disabled),
.cart-bulk-btn:hover:not(:disabled) svg {
  color: #1A56DB;
}

.cart-bulk-btn:disabled {
  opacity: .45;
  cursor: default;
}

/* --- the "you might also like" bundle tiles ------------------------------- */
.cart-fbt {
  padding: 12px;
  margin-bottom: 10px;
  border: 1px solid #E3E8F0;
  border-radius: 10px;
}

.cart-fbt-title {
  gap: 9px;
  margin-bottom: 10px;
  font-size: 17px;
  font-weight: 700;
  letter-spacing: -.015em;
  color: #0F2547;
}

.cart-fbt-title svg {
  flex: 0 0 auto;
  color: #1A56DB;
}

.cart-fbt-seeall {
  margin-left: auto;
  font-size: 13.5px;
  font-weight: 500;
  color: #1A56DB;
  text-decoration: underline;
  text-underline-offset: 3px;
}

.cart-fbt-grid {
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 190px), 1fr));
  gap: 12px;
}

.cart-fbt-item {
  gap: 7px;
  padding: 10px;
  border: 1px solid #E3E8F0;
  border-radius: 10px;
}

.cart-fbt-img {
  height: 96px;
  margin-bottom: 0;
  border-radius: 8px;
  background: #F8FAFD;
}

.cart-fbt-img img {
  max-height: 92px;
}

.cart-fbt-name {
  margin: 0;
  min-height: 37px;
  font-size: 13.5px;
  line-height: 1.35;
  color: #33486A;
}

.cart-fbt-foot {
  margin-top: auto;
}

.cart-fbt-price strong {
  font-size: 16px;
  font-weight: 800;
  letter-spacing: -.02em;
  color: #0F2547;
}

.cart-fbt-price s {
  font-size: 13.5px;
  color: #8C9AB0;
}

/* a named action across the tile, not a "+" the shopper has to interpret */
.cart-fbt-item .cart-fbt-add {
  width: 100%;
  height: 37px;
  gap: 8px;
  font-size: 13.5px;
  font-weight: 600;
  border: 1px solid #1A56DB;
  border-radius: 8px;
  background: #fff;
  color: #1A56DB;
}

.cart-fbt-item .cart-fbt-add:hover:not(:disabled) {
  background: #1A56DB;
  color: #fff;
}

.cart-fbt-item .cart-fbt-add:disabled {
  border-color: #DCF6E4;
  background: #F0FCF4;
  color: #15803D;
}

/* --- the accordions below the basket -------------------------------------- */
/* they keep their panel, but on the same border and radius as everything else */
.cart-accordion {
  margin-bottom: 10px !important;
  margin-top: 0 !important;
  padding: 0;
  border: 1px solid #E3E8F0;
  border-radius: 10px;
}

/* --- phone ---------------------------------------------------------------- */
@media (max-width: 767px) {
  .cart-page-head {
    flex-wrap: wrap;
    gap: 6px 10px;
  }

  .cart-page-title {
    font-size: 24px;
  }

  .cart-fbt-title {
    font-size: 16px;
  }

  /*
    The bulk bar, on one line.

    Three links and a checkbox came to 343px of content and 42px of gaps, and
    a 390px phone gives the column 366px - so "Save for Later" dropped to a
    row of its own and the bar stood 112px tall to offer three actions. The
    measurements are what the sizes below are set from: 11.5px text, an 18px
    box and 7px gaps bring it to ~311px, which leaves 25px spare on the
    narrowest phone that keeps the full labels - enough that a two- or
    three-digit item count cannot push the row off the screen.

    nowrap rather than a narrower wrap: with the widths above there is
    nothing left to wrap, and it is what makes the row's height a constant.
  */
  .cart-bulk {
    flex-wrap: nowrap;
    gap: 7px;
    padding: 1px 2px;
    margin-bottom: 8px;
    font-size: 11.5px;
  }

  .cart-bulk-all {
    gap: 6px;
  }

  .cart-bulk-box {
    width: 18px;
    height: 18px;
    border-radius: 5px;
  }

  /* the tick is drawn at absolute offsets, so it has to come in with the box
     or it sits against the bottom-right corner */
  .cart-bulk-box::after {
    left: 5.8px;
    top: 2.4px;
    width: 4.4px;
    height: 8.6px;
    border-width: 0 2px 2px 0;
  }

  .cart-bulk-sep {
    height: 14px;
  }

  .cart-bulk-btn {
    gap: 5px;
    font-size: 11.5px;
  }
}

/*
  Under 375px the second half of each label goes.

  The cutoff is measured, not guessed: with the full labels a 360px phone has
  3px to spare once the count reaches three digits, which is no margin at all
  for a font that renders a hair wider than Inter did here. 375px is the
  narrowest width that still leaves room for one - it is also the iPhone SE,
  so the phones that lose the words are the 320 and 360 Androids.

  "Remove" and "Save", next to a bin and a heart, under a bar headed "Select
  All", are not ambiguous; and the alternative is the two-row bar this whole
  block exists to remove.
*/
@media (max-width: 374px) {
  .cart-bulk-word {
    display: none;
  }
}

</style>