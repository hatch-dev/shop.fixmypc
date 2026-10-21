<template>
  <div>
      <Breadcrumbs />
        <navigation-step /> 
        <div class="container-fluid my-5">
          <div class="row g-4">
            <div class="col-lg-8">
             <!--
               The four sections are open cards in the checkout Payment style:
               a header with a one-line hint, then the choices as one joined
               list, the chosen row tinted with an accent on its leading edge.
               Styles: assets/css/checkout-sections.css. The ids are the
               targets revealBlocker scrolls to.
             -->
             <section class="cart-sec" aria-labelledby="ship-sec-method-title">
               <div class="cart-sec-head">
                 <span class="cart-sec-ico" :class="{ 'is-done': !!deliveryType }" aria-hidden="true">
                   <svg v-if="deliveryType" width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M20 6 9 17l-5-5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                   <svg v-else width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M3 6h11v10H3zM14 9h4l3 3.5V16h-7" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><circle cx="7" cy="17.5" r="1.8" fill="#fff" stroke="currentColor" stroke-width="1.6"/><circle cx="17.5" cy="17.5" r="1.8" fill="#fff" stroke="currentColor" stroke-width="1.6"/></svg>
                 </span>
                 <div class="cart-sec-heading">
                   <h2 id="ship-sec-method-title" class="cart-sec-title">{{ $t('ui.shippingPage.methodTitle') }}</h2>
                   <p v-if="productSummary.length" class="cart-sec-sub">
                     {{ productSummary.map(item => item.text).join(', ') }}
                   </p>
                 </div>
               </div>
               <div class="cart-sec-body">
                 <div
                   id="delivery-options"
                   class="ship-choices"
                   role="radiogroup"
                   :aria-label="$t('ui.shippingPage.methodAria')"
                 >
                   <div
                     :class="['ship-choice', { active: deliveryType === 'delivery' }]"
                     role="radio"
                     tabindex="0"
                     :aria-checked="String(deliveryType === 'delivery')"
                     @click="setDeliveryType('delivery')"
                     @keydown.enter.prevent="setDeliveryType('delivery')"
                     @keydown.space.prevent="setDeliveryType('delivery')"
                   >
                     <span class="ship-choice-mark" aria-hidden="true"></span>
                     <span class="ship-choice-body">
                       <span class="ship-choice-title">{{ $t('ui.shippingPage.sendByPost') }}</span>
                       <span class="ship-choice-sub">{{ $t('ui.shippingPage.sendByPostSub') }}</span>
                     </span>
                     <span class="ship-choice-ico" aria-hidden="true">
                       <svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M3 6h11v10H3zM14 9h4l3 3.5V16h-7" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><circle cx="7" cy="17.5" r="1.8" fill="#fff" stroke="currentColor" stroke-width="1.5"/><circle cx="17.5" cy="17.5" r="1.8" fill="#fff" stroke="currentColor" stroke-width="1.5"/></svg>
                     </span>
                   </div>

                   <div
                     :class="['ship-choice', { active: deliveryType === 'collection' }]"
                     role="radio"
                     tabindex="0"
                     :aria-checked="String(deliveryType === 'collection')"
                     @click="setDeliveryType('collection')"
                     @keydown.enter.prevent="setDeliveryType('collection')"
                     @keydown.space.prevent="setDeliveryType('collection')"
                   >
                     <span class="ship-choice-mark" aria-hidden="true"></span>
                     <span class="ship-choice-body">
                       <span class="ship-choice-title">{{ $t('ui.shippingPage.collectionInStore') }} <span v-if="collectionFree" class="ship-choice-free">{{ $t('ui.shippingPage.free') }}</span></span>
                       <span class="ship-choice-sub">{{ $t('ui.shippingPage.collectionInStoreSub') }}</span>
                     </span>
                     <span class="ship-choice-ico" aria-hidden="true">
                       <svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M4 9.5 5.5 4h13L20 9.5M4 9.5h16M4 9.5V20h16V9.5M9.5 20v-5h5v5" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>
                     </span>
                   </div>
                 </div>

                 <!--
                   Sits outside the radiogroup: a link inside an element with
                   role="radio" would pick the option every time it was clicked.
                 -->
                  <div v-if="isCollection" class="pickup">
                    <template v-if="collectionPoints.length">
                      <p class="pickup-heading">
                        {{ collectionPoints.length > 1 ? $t('ui.shippingPage.chooseStore') : $t('ui.shippingPage.yourStore') }}
                        <span v-if="collectionPoints.length > 1" class="pickup-count">{{ $t('ui.shippingPage.storeCount', { count: collectionPoints.length }) }}</span>
                      </p>

                      <!--
                        A real radio per store inside a label: the whole card
                        picks it, arrow keys move between stores, and the phone
                        and directions links still work on their own (a click
                        on a link inside a label does not tick the radio).
                      -->
                      <div
                        class="pickup-list"
                        :role="collectionPoints.length > 1 ? 'radiogroup' : null"
                        :aria-label="$t('ui.shippingPage.storeAria')"
                      >
                        <component
                          :is="collectionPoints.length > 1 ? 'label' : 'div'"
                          v-for="point in collectionPoints"
                          :key="point.id"
                          class="pickup-card"
                          :class="{
                            'is-choosable': collectionPoints.length > 1,
                            'is-chosen': collectionPoints.length === 1 || pickupPlaceId === point.id
                          }"
                        >
                          <input
                            v-if="collectionPoints.length > 1"
                            class="pickup-radio"
                            type="radio"
                            name="collection-point"
                            :value="point.id"
                            :checked="pickupPlaceId === point.id"
                            @change="choosePickup(point.id)"
                          >
                          <span v-if="collectionPoints.length > 1" class="pickup-mark" aria-hidden="true"></span>
                          <span v-else class="pickup-store-ico" aria-hidden="true">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M4 9.5 5.5 4h13L20 9.5M4 9.5h16M4 9.5V20h16V9.5M9.5 20v-5h5v5" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>
                          </span>

                          <span class="pickup-body">
                            <span class="pickup-top">
                              <span class="pickup-name">{{ point.name }}</span>
                              <span class="pickup-price" :class="{ 'is-free': point.free }">
                                {{ point.free ? $t('ui.shippingPage.free') : point.priceLabel }}
                              </span>
                            </span>

                            <address class="pickup-address">
                              {{ point.address }}
                              <span v-if="point.country" class="pickup-country">{{ point.country }}</span>
                            </address>

                            <span class="pickup-actions">
                              <a
                                v-if="point.phone"
                                class="pickup-action"
                                :href="'tel:' + point.phone"
                              >
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>
                                {{ point.phone }}
                              </a>
                              <a
                                class="pickup-action"
                                :href="point.mapUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                              >
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 21s7-5.2 7-11a7 7 0 1 0-14 0c0 5.8 7 11 7 11Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><circle cx="12" cy="10" r="2.6" stroke="currentColor" stroke-width="1.7"/></svg>
                                {{ $t('ui.shippingPage.getDirections') }}
                                <span class="visually-hidden">{{ $t('ui.shippingPage.opensNewTab') }}</span>
                              </a>
                            </span>
                          </span>
                        </component>
                      </div>

                      <p class="pickup-note">
                        {{ $t('ui.shippingPage.pickupNote') }}
                      </p>
                    </template>

                    <!--
                      Better than an empty box: a shopper choosing collection
                      needs to know where to go, and if no rule carries a
                      pickup address there is nothing to show.
                    -->
                    <p v-else class="collection-point-missing">
                      {{ setting && setting.phone ? $t('ui.shippingPage.collectionMissingCall', { phone: supportPhone }) : $t('ui.shippingPage.collectionMissingCallUs') }}
                    </p>
                  </div>
               </div>
             </section>

             <section
               v-if="deliveryType && !isCollection"
               id="deliveryAccordion"
               class="cart-sec"
               aria-labelledby="ship-sec-address-title"
             >
               <div class="cart-sec-head">
                 <span class="cart-sec-ico" aria-hidden="true">
                   <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21Z" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="9.5" r="2.5" stroke="currentColor" stroke-width="1.8"/></svg>
                 </span>
                 <div class="cart-sec-heading">
                   <h2 id="ship-sec-address-title" class="cart-sec-title">{{ $t('ui.shippingPage.deliveryAddress') }}</h2>
                   <p class="cart-sec-sub">{{ $t('ui.shippingPage.deliveryAddressSub') }}</p>
                 </div>
               </div>
               <div class="cart-sec-body">
                 <user-address
                   ref="shippingAddress"
                   :has-radio="true"
                   :invalid="showBlockingError && !selectedCurrentAddress"
                   @editing="editAddress"
                   @selected-address="selectedCurrentAddress = $event"
                   @add-address="addressPopup = true"
                 />

                 <div
                   v-if="undeliverableMessage && selectedCurrentAddress"
                   class="ship-restricted ship-undeliverable"
                   role="alert"
                 >
                   <p class="ship-restricted-title">{{ undeliverableMessage }}</p>
                   <p class="ship-undeliverable-hint">
                     {{ $t('ui.shippingPage.undeliverableHint') }}
                   </p>
                 </div>
               </div>
             </section>

             <!--
               Products the shopper cannot receive this way: sold only in other
               countries. The server refuses the order with the same message;
               this says so before they press continue, and lets them take the
               line out of this order without leaving the page.
             -->
             <div
               v-if="restrictedLines.length"
               id="restrictedItems"
               class="ship-restricted"
               role="alert"
             >
               <p class="ship-restricted-title">
                 {{ isCollection ? $tc('ui.shippingPage.restrictedCollect', restrictedLines.length) : $tc('ui.shippingPage.restrictedSend', restrictedLines.length, { country: quote.country_name }) }}
               </p>
               <ul class="ship-restricted-list">
                 <li v-for="line in restrictedLines" :key="line.id">
                   <span>{{ line.title }}</span>
                   <a
                     href="#"
                     role="button"
                     class="ship-restricted-remove"
                     @click.prevent="removeFromOrder(line.id)"
                   >{{ $t('ui.shippingPage.removeFromOrder') }}</a>
                 </li>
               </ul>
             </div>

             <!--
               Nothing is being delivered on a collection order, so no address
               is asked for. The store still has to be able to reach the
               customer when the order is ready, and the address form was
               previously the only place a guest ever gave a name, email and
               phone - so those are asked for on their own instead.
             -->
             <section
               v-if="isCollection"
               id="contactAccordion"
               class="cart-sec"
               aria-labelledby="ship-sec-contact-title"
             >
               <div class="cart-sec-head">
                 <span class="cart-sec-ico" aria-hidden="true">
                   <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="1.8"/><path d="M4 21c1.2-4 4.2-6 8-6s6.8 2 8 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                 </span>
                 <div class="cart-sec-heading">
                   <h2 id="ship-sec-contact-title" class="cart-sec-title">{{ $t('ui.shippingPage.contactDetails') }}</h2>
                   <p class="cart-sec-sub">{{ $t('ui.shippingPage.contactDetailsSub') }}</p>
                 </div>
               </div>
               <div class="cart-sec-body">
                    <div class="address-form-panel">
                      <div v-if="isLoggedIn" class="collection-contact-summary">
                        <strong>{{ contactDetails.name }}</strong>
                        <span>{{ contactDetails.email }}</span>
                        <span v-if="contactDetails.phone">{{ $t('ui.shippingPage.telPrefix', { phone: contactDetails.phone }) }}</span>
                      </div>

                      <form v-else class="address-form" @submit.prevent>
                        <div class="address-form-grid">
                          <div class="address-field">
                            <label for="collection-name">{{ $t('ui.shippingPage.fullName') }}</label>
                            <input
                              id="collection-name"
                              v-model="contactDetails.name"
                              type="text"
                              class="address-input"
                              :placeholder="$t('ui.shippingPage.enterName')"
                              :class="{ 'field-invalid': hasContactErrors && !contactDetails.name.trim() }"
                            >
                          </div>

                          <div class="address-field">
                            <label for="collection-email">{{ $t('ui.shippingPage.emailAddress') }}</label>
                            <input
                              id="collection-email"
                              v-model="contactDetails.email"
                              type="email"
                              class="address-input"
                              :placeholder="$t('ui.shippingPage.enterEmail')"
                              :class="{ 'field-invalid': hasContactErrors && !isValidEmail(contactDetails.email) }"
                            >
                          </div>

                          <div class="address-field">
                            <label for="collection-phone">{{ $t('ui.shippingPage.contactNumber') }}</label>
                            <input
                              id="collection-phone"
                              v-model="contactDetails.phone"
                              type="text"
                              class="address-input"
                              placeholder="123 456 7890"
                              :class="{ 'field-invalid': hasContactErrors && !isValidNumber(contactDetails.phone) }"
                            >
                          </div>
                        </div>
                      </form>
                    </div>
               </div>
             </section>

             <!--
               Read-only here (2026-09-26): the list says what we accept, and
               the choice is made on the Payment step, which asks for it
               itself (require-choice on /checkout).
             -->
             <section id="paymentAccordion" class="cart-sec" aria-labelledby="ship-sec-payment-title">
               <div class="cart-sec-head">
                 <span class="cart-sec-ico" aria-hidden="true">
                   <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><rect x="2.5" y="5" width="19" height="14" rx="2.5" stroke="currentColor" stroke-width="1.8"/><path d="M2.5 10h19" stroke="currentColor" stroke-width="1.8"/></svg>
                 </span>
                 <div class="cart-sec-heading">
                   <h2 id="ship-sec-payment-title" class="cart-sec-title">{{ $t('ui.shippingPage.paymentMethod') }}</h2>
                   <p class="cart-sec-sub">
                     <svg class="cart-sec-lock" viewBox="0 0 24 24" width="13" height="13" fill="none" aria-hidden="true"><rect x="5" y="10.5" width="14" height="10" rx="2" stroke="currentColor" stroke-width="1.8"/><path d="M8.5 10.5V7.5a3.5 3.5 0 0 1 7 0v3" stroke="currentColor" stroke-width="1.8"/></svg>
                     {{ $t('ui.shippingPage.paymentMethodSub') }}
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
                   :collection-order="isCollection"
                 />
               </div>
             </section>
            </div>
            <!--
              The button stays live while something is missing. Greying it out
              left the shopper with a dead control and no clue what it wanted;
              now the note below says what is outstanding, and pressing it
              jumps to the section that needs filling in.
            -->
            <checkout-right
              route-link="checkout"
              :checked-product="checkedProduct"
              :has-shipping="true"
              :shipping-choice="cartShipping"
              :shipping-quote="quote"
              :btn-text="$t('date.ptp')"
              mobile-bar
              @calculated-price="priceCalculated"
              :submitting="checkingOut"
              :disabled="fetchingAddressData || Object.keys(cartShipping).length === 0"
              @go-next="goToCheckout"
            >
              <template #checkout>
                <p
                  v-if="blockingReason"
                  class="checkout-blocked"
                  :class="{ 'checkout-blocked-error': showBlockingError }"
                  role="status"
                >
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/>
                    <path d="M12 7.5v5.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <circle cx="12" cy="16.5" r="1.15" fill="currentColor"/>
                  </svg>
                  <span>{{ blockingReason }}</span>
                </p>
              </template>
            </checkout-right>
          </div>
        </div>
      </div>
</template>
<script>
  import '~/assets/css/cart-flow.css'
  import '~/assets/css/checkout-sections.css'
  import CartList from '~/components/CartList'
  import AjaxButton from '~/components/AjaxButton'
  import Dropdown from '~/components/Dropdown'
  import PopOver from '~/components/PopOver'
  import CheckoutRight from '~/components/CheckoutRight'
  import util from '~/mixin/util'
  import {mapGetters, mapActions} from 'vuex'
  import UserAddress from '~/components/UserAddress'
  import addressHelper from '~/mixin/addressHelper'
  import validation from "~/mixin/validation"
  import Spinner from "~/components/Spinner"
  import global from '~/mixin/global'
  import PaymentGateways from "~/components/PaymentGateways";
  import Breadcrumbs from '../components/Breadcrumbs.vue'
  export default {
    middleware: ['common-middleware'],
    data() {
      return {
        // Deliberately unset. Defaulting to 'delivery' meant a shopper who
        // never looked at the two tiles was silently opted into paid postage.
        deliveryType: null,
        voucherResult: null,
        addressPopup: false,
        cartShipping: {},
        checked: [],
        cartPrice: {
          totalItems: 0,
          totalPriceWithOffer: 0,
          totalPrice: 0,
        },
        cartPopOver: false,
        editing: 0,
        checkingOut: false,
        states: {},
        loading: false,
        checkedProduct: [],
        singleShippingCart: {},
        hasAddressErrors: false,
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
        submittingAddressData: false,
        selectedCurrentAddress: null,
        // Stands in for the address on a collection order.
        contactDetails: {
          name: '',
          email: '',
          phone: ''
        },
        hasContactErrors: false,
        // Turns the "what is missing" note red once they have actually pressed
        // the button; before that it is only a hint, not a complaint.
        showBlockingError: false,
        errorFromApi: null,
        /*
         * What the server says this order would cost to receive, for the
         * current choice and address (cart/shipping-quote). The page never
         * picks a shipping row itself any more - products have no rule.
         */
        quote: null,
        quoteSeq: 0,
        // The collection point chosen, when there is more than one.
        pickupPlaceId: null
      }
    },
    watch: {
      deliveryType() {
        this.refreshQuote()
      },
      selectedCurrentAddress(address, previous) {
        if (address?.id !== previous?.id) {
          this.refreshQuote()
        }
      },
      blockingReason(reason) {
        if (!reason) {
          this.showBlockingError = false
        }
      },
      profile() {
        this.addressData.name = this.profile.name
        this.addressData.email = this.profile.email
        this.fillContactFromProfile()
      },
      location() {
        this.settingCountry()
      },
      /*
       * After a reload the basket is first restored from localStorage with
       * its money stripped, and the server's priced rows replace it a moment
       * later as new objects. checkedProduct held the stripped ones, so the
       * summary sat on "Updating prices..." for good. Re-read on every
       * replacement, the way /checkout does.
       */
      cartProducts() {
        this.getCheckedProducts()
      },
    },
    components: {
      Spinner,
      CheckoutRight,
      Dropdown,
      AjaxButton,
      CartList,
      PopOver,
      UserAddress,
      PaymentGateways
    },
    mixins: [util, addressHelper, validation, global],
    computed: {
      isCollection() {
        return this.deliveryType === 'collection'
      },
      isLoggedIn() {
        return this.$auth.loggedIn
      },
      /*
       * A signed-in shopper's contact details come off their account, so there
       * is nothing to fill in and nothing to validate. A guest has to type them,
       * because the address form they are no longer shown was the only other
       * place they would have been asked.
       */
      contactValid() {
        if (this.isLoggedIn) {
          return !!this.contactDetails.email
        }

        return !!this.contactDetails.name.trim() &&
          this.isValidEmail(this.contactDetails.email) &&
          this.isValidNumber(this.contactDetails.phone)
      },
      readyToContinue() {
        const addressed = this.isCollection ? this.contactValid : !!this.selectedCurrentAddress

        return addressed
      },
      /*
       * What is still outstanding, in the shopper's words. What counts differs
       * by delivery method: a posted order needs an address, a collection order
       * needs none - it needs contact details so the store can say when the
       * order is ready.
       */
      blockingReason() {
        // Still loading, or the basket has not been read yet: nothing to say.
        if (this.fetchingAddressData || !Object.keys(this.cartShipping).length) {
          return null
        }

        if (!this.deliveryType) {
          return this.$t('ui.shippingPage.blockChooseMethod')
        }

        if (this.isCollection) {
          if (!this.contactValid) {
            return this.$t('ui.shippingPage.blockContact')
          }
        } else if (!this.currentAddresses) {
          /*
           * The address list only mounts once delivery has been picked, so for
           * a moment there is no list rather than an empty one. Undefined means
           * "not fetched yet" - claiming they have no address would be a guess.
           */
          return null
        } else if (!this.currentAddresses.length) {
          return this.$t('ui.shippingPage.blockAddAddress')
        } else if (!this.selectedCurrentAddress) {
          return this.$t('ui.shippingPage.blockSelectAddress')
        }

        // Items that cannot go to this address, or nowhere to deliver to.
        if (this.quote && !this.quote.ok) {
          return this.quote.message
        }

        // How to pay is asked on the Payment step, not here.
        return null
      },
      /*
       * Nowhere delivers to the address's country. Said at the address, as
       * soon as it is chosen - not only in the summary box or as a toast
       * after pressing continue. Items sold only elsewhere have their own
       * list below, so this is only the whole-country refusal.
       */
      undeliverableMessage() {
        const quote = this.quote

        if (!quote || quote.ok || this.isCollection || this.restrictedLines.length) {
          return null
        }

        return quote.country_name ? quote.message : null
      },
      restrictedLines() {
        const byCart = this.quote?.restricted_carts || {}

        return this.checkedProduct
          .filter(line => byCart[line.id])
          .map(line => ({
            id: line.id,
            title: line?.flash_product?.title || '',
            message: byCart[line.id]
          }))
      },
      collectionFree() {
        const point = (this.quote?.collection_points || [])[0]

        return !point || (this.quote?.collection_points || []).every(p => !(parseFloat(p.pickup_price) > 0))
      },
      productSummary() {
        if (!this.checkedProduct.length) return []
        return this.checkedProduct.map(p => ({
          text: `${p.quantity} x ${p?.flash_product?.title}`
        }))
      },
      totalPrice() {
        return this.cartPrice?.totalPrice || 0
      },
      invalidNumber() {
        return !this.isValidNumber(this.addressData?.phone)
      },
      numberValid() {
        return this.addressData.phone && !this.invalidNumber
      },
      supportPhone() {
        return this.setting?.phone || 'us'
      },
      /*
       * The store's collection points - the shipping rows with pickup
       * switched on - as the server's quote lists them. Products no longer
       * carry a shipping rule to read them from.
       */
      collectionPoints() {
        const seen = {}
        const points = []

        ;[this.quote?.collection_points || []].forEach(places => {

          places.forEach(place => {

            if (parseInt(place?.pickup_point) !== 1 || seen[place.id]) {
              return
            }

            // Postal order, not the order the columns happen to sit in.
            const lines = [
              place.pickup_address_line_1,
              place.pickup_address_line_2,
              [place.pickup_city, place.pickup_state].filter(i => i).join(', '),
              place.pickup_zip,
              place.pickup_country
            ].filter(i => i && String(i).trim())

            if (!lines.length) {
              return
            }

            seen[place.id] = true

            const price = parseFloat(place.pickup_price) || 0
            const country = (place.pickup_country || '').trim()

            points.push({
              id: place.id,
              lines: lines,
              // the first address line names the store; the rest reads as one line
              name: lines[0],
              address: [
                place.pickup_address_line_2,
                place.pickup_city,
                place.pickup_state,
                place.pickup_zip
              ].filter(i => i && String(i).trim()).join(', '),
              country: country,
              free: price <= 0,
              priceLabel: (this.$store.getters['common/currencyIcon'] || '€') + price.toFixed(2),
              phone: place.pickup_phone,
              mapUrl: 'https://www.google.com/maps/search/?api=1&query='
                + encodeURIComponent(lines.join(', '))
            })
          })
        })

        return points
      },
      selectedSippingPlace(){
        const cartShippingValues = Object.values(this.cartShipping)
        if(cartShippingValues?.length) {

          const sp = cartShippingValues[0]?.shipping_place

          if(!sp?.pickup_point){
            return null
          }

          const addrArr = [sp.pickup_address_line_1, sp.pickup_address_line_2,
            sp.pickup_zip, sp.pickup_state, sp.pickup_city, sp.pickup_country]

          let addr = addrArr.filter(i => i).join(', ')

          if(sp.pickup_phone) {
            addr = `${addr}, <span class="block">${this.$t('date.tl')}: ${sp.pickup_phone}</span>`
          }
          return  addr
        }
        return null
      },

      invalidEmail() {
        return !this.isValidEmail(this.addressData.email)
      },
      emailValid() {
        return this.addressData.email && !this.invalidEmail
      },
      currentAddresses() {
        return this.allAddress?.data
      },
      ...mapGetters('resource', ['countryList', 'phoneList']),
      ...mapGetters('language', ['langCode']),
      ...mapGetters('user', ['allAddress', 'profile']),
      ...mapGetters('common', ['location', 'setting']),
      ...mapGetters('cart', {cartProducts: 'checkoutProducts'}),
    },
    methods: {
      fillContactFromProfile() {
        // $auth.user is there from the moment the page loads; the profile
        // getter arrives a little later and carries the phone number.
        const account = this.profile || this.$auth.user

        if (!this.isLoggedIn || !account) {
          return
        }

        this.contactDetails = {
          name: account.name || '',
          email: account.email || '',
          phone: account.phone || ''
        }
      },
      setDeliveryType(type) {
        this.deliveryType = type
        Object.keys(this.cartShipping).forEach(key => {
          this.cartShipping[key].shipping_type = type === 'delivery' ? 1 : 2
        })
      },
      /*
       * Ask the server what this order would cost to receive. Answers that
       * arrive out of order are dropped: the shopper can switch between post
       * and collection, or between addresses, faster than the network.
       */
      async refreshQuote() {
        const seq = ++this.quoteSeq

        if (!this.deliveryType || (!this.isCollection && !this.selectedCurrentAddress?.id)) {
          this.quote = null
          return
        }

        try {
          const data = await this.shippingQuote({
            shipping_type: this.isCollection ? 2 : 1,
            selected_address: this.isCollection ? null : this.selectedCurrentAddress.id,
            pickup_place_id: this.isCollection ? this.pickupPlaceId : null,
            user_token: await this.getUserToken()
          })

          if (seq !== this.quoteSeq) return

          this.quote = data?.status === 200 ? data.data : null

          // One collection point: it is the one, nothing to choose.
          const points = this.quote?.collection_points || []
          if (this.isCollection && !this.pickupPlaceId && points.length === 1) {
            this.pickupPlaceId = points[0].id
          }
        } catch (e) {
          if (seq === this.quoteSeq) this.quote = null
        }
      },
      choosePickup(id) {
        this.pickupPlaceId = id
        this.refreshQuote()
      },
      // Untick a line the shopper cannot receive here; it stays in the basket.
      async removeFromOrder(cartId) {
        /*
         * A Buy Now checkout is one item: removing it ends the Buy Now and
         * goes back to the basket, which it never touched. Re-posting the
         * selection here (below) would untick every basket line instead.
         */
        if (this.$store.getters['cart/buyNowInProgress']) {
          await this.$store.dispatch('cart/cancelBuyNow')
          this.$router.push('/cart')
          return
        }

        const checked = this.checkedProduct
          .map(line => parseInt(line.id))
          .filter(id => id !== parseInt(cartId))

        await this.cartChangedAction({payload: {checked}, lang: this.langCode})

        this.getCheckedProducts()

        if (!this.checkedProduct.length) {
          this.$router.push('/cart')
          return
        }

        this.refreshQuote()
      },
      initAddress() {
        this.addressData = {
          id: '',
          email: '',
          name: '',
          phone: '',
          city: '',
          country: '',
          state: '',
          zip: '',
          address_1: '',
          address_2: '',
          delivery_instruction: ''
        }
      },
      loadData() {
        this.$refs.shippingAddress.loadData()
      },
      cartChanged(evt) {
        this.singleShippingCart = []

        if (evt) {
          this.getCheckedProducts()
        }
      },
      shippingChanged(evt) {
        this.cartShipping = evt
      },
      currentShipping({cart, shipping}) {
        if(this.cartShipping[cart]) {

          this.cartShipping[cart].shipping_place = shipping

          const sr = shipping?.shipping_rule

          if(sr?.single_price && (!this.singleShippingCart[sr?.id] || (this.singleShippingCart[sr?.id] === cart))) {

            this.singleShippingCart[sr?.id] = cart
            this.cartShipping[cart].single_shipping = true

          } else if(sr?.single_price && this.singleShippingCart[sr?.id]){

            this.cartShipping[cart].single_shipping = false
          }
        }
      },
      priceCalculated(evt) {
        this.cartPrice = evt
      },
      /*
       * The summary sits in its own column, so on a long page the section that
       * needs attention can be well off screen when the button is pressed.
       */
      revealBlocker(id) {
        if (typeof document === 'undefined') {
          return
        }

        this.$nextTick(() => {
          const target = document.getElementById(id)

          if (target && target.scrollIntoView) {
            target.scrollIntoView({behavior: 'smooth', block: 'center'})
          }
        })
      },
      getCheckedProducts() {
        this.checked = []
        this.checkedProduct = []

        this.cartProducts.forEach(obj => {
          if (parseInt(obj.selected) === 1) {
            this.checked.push(obj.id)

            this.checkedProduct.push(obj)

            /*
             * No shipping row is chosen here. Which row applies, and what it
             * costs, is decided by the server from the address (or the
             * collection point) - the old "first row of the product's rule"
             * charged Irish postage to a UK address.
             */
            this.cartShipping = {
              ...this.cartShipping,
              ...{
                [obj.id]: {
                  cart: obj.id,
                  shipping_place: this.isCollection && this.pickupPlaceId ? {id: this.pickupPlaceId} : null,
                  single_shipping: true,
                  // null until the shopper picks; setDeliveryType fills it in
                  shipping_type: this.deliveryType
                    ? (this.deliveryType === 'delivery' ? 1 : 2)
                    : null,
                }
              }
            }
          }
        })

      },
      async goToCheckout() {
        // Only the collection point is ever sent; the server picks the row.
        Object.keys(this.cartShipping).forEach(key => {
          this.cartShipping[key].shipping_place =
            this.isCollection && this.pickupPlaceId ? {id: this.pickupPlaceId} : null
        })
        if (!this.checkedProduct.length) {
          this.cartPopOver = false
          this.setToastError(this.$t('shipping.noProductSelected'))
          this.$router.push('/cart')
          return
        }
        /*
         * Posted or collected is the shopper's call, and nothing below it means
         * anything until it is made.
         */
        if (!this.deliveryType) {
          this.showBlockingError = true
          this.setToastError(this.$t('shipping.chooseDeliveryType'))
          this.revealBlocker('delivery-options')
          return
        }

        /*
         * A collection order has no address to select; it needs contact details
         * instead, which the API stores against the shopper so they are still
         * there when the order is created.
         */
        if (this.isCollection && !this.contactValid) {
          this.hasContactErrors = true
          this.showBlockingError = true
          this.setToastError(this.$t('shipping.addContactDetails'))
          this.revealBlocker('contactAccordion')
          return
        }

        /*
         * A posted order does need one. This was only ever enforced by greying
         * the button out, so a shopper who had not picked an address had no way
         * to find out why nothing happened.
         */
        if (!this.isCollection && !this.selectedCurrentAddress) {
          this.showBlockingError = true
          this.setToastError(
            this.currentAddresses && this.currentAddresses.length
              ? this.$t('shipping.selectAddress')
              : this.$t('shipping.addAddress')
          )
          this.revealBlocker('deliveryAccordion')
          return
        }

        /*
         * Something in the basket cannot be sent there, or nowhere delivers
         * to that country. The server would refuse the same way; saying so
         * here points at the line to take out instead of a toast alone.
         */
        if (this.quote && !this.quote.ok) {
          this.showBlockingError = true
          this.setToastError(this.quote.message)
          this.revealBlocker(this.restrictedLines.length
            ? 'restrictedItems'
            : (this.isCollection ? 'delivery-options' : 'deliveryAccordion'))
          return
        }

        try {
          this.checkingOut = true
          this.$store.commit('cart/SET_SELECTED_ADDRESS', this.isCollection ? null : this.selectedCurrentAddress)
          this.$store.commit('cart/SET_COLLECTION_CONTACT', this.isCollection ? {...this.contactDetails} : null)
          const data = await this.updateCartShipping({
            cart: this.cartShipping,
            shipping_type: this.isCollection ? 2 : 1,
            pickup_place_id: this.isCollection ? this.pickupPlaceId : null,
            user_token: await this.getUserToken(),
            selected_address: this.isCollection ? null : this.selectedCurrentAddress?.id,
            contact_name: this.isCollection ? this.contactDetails.name : null,
            contact_email: this.isCollection ? this.contactDetails.email : null,
            contact_phone: this.isCollection ? this.contactDetails.phone : null
          })
          this.checkingOut = false
          if (data?.status === 200) {
            this.cartPopOver = false
            this.$router.push('/checkout')
          } else {
            if (data.data?.form) {
              this.setToastError(data.data?.form[0])
            } else if (data.data?.product) {
              const productErrors = data.data.product[0] || {}
              this.errorFromApi = productErrors
              const firstError = Object.values(productErrors)[0]
              const errorMessage = Array.isArray(firstError)
                ? firstError[0]
                : firstError
              this.setToastError(errorMessage)
            }
          }
        } catch (e) {
          return this.$nuxt.error(e)
        }
      },
      goToShipping() {
        if (!this.currentAddresses.length) {
          this.setToastError(this.$t('shipping.addAddress'))
          return
        }
        if (!this.checkedProduct.length) {
          this.cartPopOver = false
          this.setToastError(this.$t('shipping.noProductSelected'))
          this.$router.push({path: 'cart'})
          return
        }

        this.$router.push({path: 'checkout'})
        //this.cartPopOver = true
      },
      clearData() {

        this.addressPopup = false

        this.initAddress()
        this.submittingAddressData = false
        this.editing = 0
        this.settingCountry()
        this.hasAddressErrors = false
      },
      async savingAddressData() {
        if (this.numberValid && this.emailValid) {
          await this.addressAction()
          if (!this.hasAddressErrors) {
            this.clearData()
          }
        } else {
          this.hasAddressErrors = true
        }
      },
      selectCountry(evt) {

        this.addressData = {...this.addressData, ...{country: evt.value?.code2}}
        this.states = evt.value?.states
        this.addressData.state = Object.keys(evt.value?.states).length ? Object.values(evt.value?.states)[0]?.code : ''
      },
      selectState(evt) {
        this.addressData.state = evt.value.code
      },
      settingCountry() {
        if (this.addressData) {
          if(this.location.countryCode && this.countryList[this.location.countryCode]){
            this.addressData.country = this.location.countryCode
          } else{

            this.addressData.country = Object.keys(this.countryList)[0]
          }

          this.states = this.addressData?.country ? this.countryList[this.addressData.country].states : ''
          this.addressData.state = this.location.region
        }
      },
      async deleting(address) {
        if (confirm(this.$t('deleteAlert.cartProductTile'))) {
          this.ajaxDeleting = address.id
          await this.addressDelete(address.id)
          this.ajaxDeleting = 0
        }
      },
      closeAddressPopup(){
        this.addressPopup = false
        this.addressData = {}

        this.addressData.country = this.location?.countryCode
        this.states = this.countryList[this.location?.countryCode]?.states
        this.addressData.state = this.location?.region
        this.addressData.email = this.profile?.email
      },
      editAddress(value) {
        this.addressPopup = true
        this.editing = value.id
        this.addressData = Object.assign({}, value)
        this.states = this.countryList[value.country].states
      },
      ...mapActions('resource', ['setCountryList', 'setPhoneList']),
      ...mapActions('user', ['userAddressAction', 'getUserToken']),
      ...mapActions('common', ['fetchLocation', 'setToastMessage', 'setToastError', 'getRequest']),
      ...mapActions('cart', ['getCartByUser', 'updateCartShipping', 'shippingQuote']),
      ...mapActions('cart', {cartChangedAction: 'cartChanged'})
    },
    async asyncData({store, $auth, error, redirect}) {
      try {
        if(!store.state?.common?.setting?.guest_checkout) {
          if (!$auth.loggedIn) {
            $auth.redirect('login')
            return false
          }
        } else if (!$auth.loggedIn) {
          /*
           * Guests get their own version of this step, which asks for the
           * contact details an account would already have supplied.
           */
          redirect('/guest-checkout')
          return false
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
      // The payment step shows this; loading it now means it is there on arrival.
      this.$store.dispatch('user/loadWalletBalance')

      /*
       * The store-wide promotion, so the summary on this page can show the
       * discount the shopper opted into on the basket. Only the basket
       * carries the banner, so without this a checkout reached directly --
       * a deep link, or a refresh -- had no promotion to derive the amount
       * from and quietly dropped the row.
       */
      this.$store.dispatch('cart/loadFlashPromotion')

      try {
        if (!this.countryList || !this.phoneList) {
          this.loading = true

          const {data} = await this.getRequest({
            params: null,
            lang: this.langCode,
            api: 'countriesPhones'
          })
          this.setCountryList(data?.countries)
          this.setPhoneList(data?.phones)
          this.loading = false
        }
        if (this.cartProducts.length === 0) {
          await this.getCartByUser({
            lang: this.langCode,
            params: {
              user_token: await this.getUserToken()
            }
          })
          this.getCheckedProducts()
        } else {
          if (this.cartProducts.length) {
            this.getCheckedProducts()
          }
        }
      } catch (e) {
        return this.$nuxt.error(e)
      }
      this.initAddress()
      this.$nextTick(() => {
        if (this.profile) {
          this.addressData.name = this.profile?.name
          this.addressData.email = this.profile?.email
        }
        this.fillContactFromProfile()
      })
      if (!this.addressData.country) {
        this.settingCountry()
      }
    },
  }
</script>
<style scoped>
  /* Lines that cannot be sent to the chosen address (sell only in). */
  .ship-restricted {
    margin: 0 0 16px;
    padding: 14px 16px;
    border: 1px solid #f2c4c4;
    border-radius: 12px;
    background: #fdf1f1;
    color: #a12321;
    font-size: 13.5px;
    line-height: 1.5;
  }

  .ship-restricted-title {
    margin: 0 0 8px;
    font-weight: 600;
  }

  .ship-restricted-list {
    margin: 0;
    padding: 0;
    list-style: none;
  }

  .ship-restricted-list li {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    justify-content: space-between;
    gap: 4px 12px;
    padding: 6px 0;
    border-top: 1px solid rgba(161, 35, 33, .12);
    color: #3b1a1a;
  }

  .ship-restricted-list li:first-child {
    border-top: 0;
  }

  .ship-undeliverable {
    margin: 12px 0 0;
  }

  .ship-undeliverable .ship-restricted-title {
    margin-bottom: 0;
  }

  .ship-undeliverable-hint {
    margin: 4px 0 0;
    color: #3b1a1a;
  }

  .ship-restricted-remove {
    color: #a12321;
    font-weight: 600;
    text-decoration: underline;
    white-space: nowrap;
  }

  /* ---- Where a collection order is collected from ---- */
  .pickup {
    margin-top: 18px;
  }

  .pickup-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin: 0 0 10px;
    font-size: 14px;
    font-weight: 600;
    color: #151438;
  }

  .pickup-count {
    font-size: 12.5px;
    font-weight: 500;
    color: #6b7280;
  }

  .pickup-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
  }

  .pickup-card {
    position: relative;
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    align-items: flex-start;
    gap: 14px;
    margin: 0;
    padding: 16px 18px;
    border: 1px solid #E3E3EF;
    border-radius: 12px;
    background: #fff;
    font-weight: 400;
    transition: border-color .15s ease, background-color .15s ease, box-shadow .15s ease;
  }

  .pickup-card.is-choosable {
    cursor: pointer;
  }

  .pickup-card.is-choosable:hover {
    border-color: #C7CBE4;
    background: #FAFAFE;
  }

  .pickup-card.is-chosen {
    border-color: #33319A;
    background: #F7F7FE;
    box-shadow: 0 0 0 1px #33319A inset;
  }

  /* the native radio stays in the page for keyboard and screen readers */
  .pickup-radio {
    position: absolute;
    opacity: 0;
    width: 1px;
    height: 1px;
    pointer-events: none;
  }

  .pickup-mark {
    width: 20px;
    height: 20px;
    margin-top: 1px;
    border: 1.5px solid #C7CBE4;
    border-radius: 50%;
    background: #fff;
    transition: border-color .15s ease;
  }

  .pickup-card.is-choosable:hover .pickup-mark {
    border-color: #33319A;
  }

  .pickup-card.is-chosen .pickup-mark {
    border: 6px solid #33319A;
  }

  .pickup-radio:focus-visible + .pickup-mark {
    box-shadow: 0 0 0 3px rgba(51, 49, 154, .25);
  }

  .pickup-store-ico {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: #EEEEFC;
    color: #33319A;
  }

  .pickup-body {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 0;
  }

  .pickup-top {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 12px;
  }

  .pickup-name {
    font-size: 15px;
    font-weight: 600;
    line-height: 1.35;
    color: #151438;
    overflow-wrap: anywhere;
  }

  .pickup-card.is-chosen .pickup-name {
    color: #33319A;
  }

  .pickup-price {
    flex: 0 0 auto;
    font-size: 13.5px;
    font-weight: 600;
    color: #151438;
  }

  .pickup-price.is-free {
    padding: 2px 8px;
    border-radius: 999px;
    background: #E7F8EE;
    color: #05803A;
    font-size: 11px;
    font-weight: 700;
    line-height: 1.4;
  }

  .pickup-address {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px 8px;
    margin: 0;
    font-style: normal;
    font-size: 13.5px;
    line-height: 1.5;
    color: #4b5262;
  }

  .pickup-country {
    padding: 1px 8px;
    border-radius: 999px;
    background: #F1F2F7;
    color: #4b5262;
    font-size: 11.5px;
    font-weight: 600;
  }

  .pickup-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 6px 18px;
    margin-top: 6px;
  }

  .pickup-action {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    font-weight: 600;
    color: #33319A;
    text-decoration: none;
  }

  .pickup-action:hover {
    text-decoration: underline;
  }

  .pickup-note {
    margin: 12px 0 0;
    font-size: 12.5px;
    line-height: 1.5;
    color: #6b7280;
  }

  .visually-hidden {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
  }

  @media (max-width: 575px) {
    .pickup-card {
      gap: 12px;
      padding: 14px;
    }

    .pickup-name {
      font-size: 14.5px;
    }
  }

  /*
   * Sits under "Proceed to Payment" and says what is still outstanding. Amber
   * while it is only a hint; red once the button has been pressed and the
   * shopper is owed an answer.
   */
  .checkout-blocked {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    margin: 10px 0 0;
    padding: 10px 12px;
    border: 1px solid #f0dfae;
    border-radius: 10px;
    background: #fdf8ec;
    font-size: 12px;
    line-height: 1.55;
    color: #7a5c14;
    text-align: left;
  }

  .checkout-blocked svg {
    flex: 0 0 auto;
    margin-top: 1px;
  }

  /* The same red the address section wears once the button has been pressed
     and the question is still unanswered. */
  .payment-invalid {
    border-color: #e0a3a3;
    box-shadow: 0 0 0 1px #e0a3a3;
    border-radius: 12px;
  }

  .checkout-blocked-error {
    border-color: #f2c4c4;
    background: #fdf1f1;
    color: #a12321;
  }

  /*
   * The collection contact form mirrors the delivery address form, whose own
   * styles are scoped to UserAddress.vue and so do not reach this page.
   */
  .address-form-panel {
    width: 100%;
  }

  .collection-contact-note {
    margin: 0 0 18px;
    color: #565a75;
    font-size: 14px;
    line-height: 1.4;
  }

  .collection-contact-summary {
    display: flex;
    flex-direction: column;
    gap: 4px;
    color: #130e2b;
    font-size: 14px;
    line-height: 1.4;
  }

  .address-form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    column-gap: 18px;
    row-gap: 13px;
  }

  .address-field {
    display: flex;
    flex-direction: column;
    min-width: 0;
  }

  .address-field label {
    margin-bottom: 8px;
    color: rgba(19, 14, 43, 1);
    font-size: 14px;
    font-weight: 500;
    line-height: 1.2;
  }

  .address-input {
    width: 100%;
    height: 55px;
    padding: 0 16px;
    border: 1px solid #d8dce8;
    border-radius: 8px;
    background: #fff;
    color: #000;
    font-size: 14px;
    outline: none;
    transition: border-color .2s ease, box-shadow .2s ease;
  }

  .address-input::placeholder {
    color: #8d91a6;
    opacity: 1;
  }

  .address-input:focus {
    border-color: #333199;
  }

  .field-invalid {
    border-color: #d83d4a;
  }

  @media (max-width: 767px) {
    .address-form-grid {
      grid-template-columns: minmax(0, 1fr);
    }
  }
</style>