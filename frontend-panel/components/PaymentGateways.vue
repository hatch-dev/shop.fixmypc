<template>
  <div>
    <div
      class="pos-rel"
    >
      <!-- Dialog form: only for an order that already exists (order page). -->
      <stripe-payment
        v-if="showStripe && !isCheckout"
        :stripe-key="paymentGateway.stripe_key"
        :order-id="orderId"
        :order-number="orderData && orderData.order ? String(orderData.order) : ''"
        :amount="stripeAmount"
        :currency="currencyData"
        :name="userName"
        :site-name="site_setting.siteName"
        :email="userEmail"
        @success="orderPlaced('success', $event)"
        @closed="stripeClosed"
      />
      
      <razorpay-payment
        v-else-if="showRazorpay"
        :order-id="orderId"
        :razorpay-key="paymentGateway.razorpay_key"
        :razorpay-payment-token="razorpayPaymentToken"
        :amount="parseFloat(amount)"
        :site-name="site_setting.siteName"
        :currency="currencyData"
        :name="userName"
        :email="userEmail"
        @success="orderPlaced('success', $event)"
        @closed="orderPlaced('closed', $event)"
        @error="orderPlaced('error', $event)"
      />

      <transition
        name="fade"
        mode="out-in"
      >
        <div
          class="spinner-wrapper flex layer-white"
          v-if="loading || payFastLoader"
        >
          <spinner
            :radius="100"
          />
        </div>
      </transition>

      <p
        v-if="orderError"
        class="f-13 error mb-15"
      >
        <span v-for="i in orderError" class="block">{{ i }}</span>
      </p>
      <form
        v-if="paymentGateway"
      >
        <p
          v-if="noPaymentMethod"
          class="info mt-15"
        >
          {{ $t('checkout.noPayment') }}
        </p>

        <div
          class="heading-tab-wrapper"
        >

          <!--
            Two modes. Where the order is actually placed this is a picker:
            each row is a <label> around its own radio, so the browser selects
            it natively and the keyboard reaches it, with a delegated handler
            on the group as a second path. In readonly mode - the basket, where
            nothing is being paid for yet - there are no radios and no
            handlers at all: it is a list of what we accept, and the choice is
            made at checkout.
          -->
          <p v-if="readonly" class="payment-note">
            {{ $t('ui.paymentGateways.chooseAtCheckout') }}
          </p>

          <div
            class="payment-options mt-10"
            :class="{ 'is-readonly': readonly }"
            :role="readonly ? 'list' : 'radiogroup'"
            :aria-label="readonly ? $t('ui.paymentGateways.methodsWeAccept') : $t('ui.paymentGateways.paymentMethod')"
            @click.capture="onOptionClick"
          >

            <component
              :is="rowTag"
              v-if="$route.path === '/checkout' && points?.points > 0"
              class="payment-option"
              :class="{ active: usePoints }"
            >
              <input
                v-if="!readonly"
                type="checkbox"
                class="payment-mark"
                v-model="usePoints"
              >
              <span class="payment-body">
                <span class="payment-title">{{ $t('ui.paymentGateways.useCherryPoints') }}</span>
                <span class="payment-sub">
                  {{ $t('ui.paymentGateways.pointsAvailable', { points: points.points, value: currencyIcon + points.value }) }}
                </span>
              </span>
              <span class="payment-logo" aria-hidden="true">⭐</span>
            </component>

            <!--
              Split payment: part from the wallet, the rest by the method
              chosen below. Offered when there is a balance that does not
              cover the whole order (a balance that does is the Wallet
              method itself). Off until the shopper ticks it.
            -->
            <component
              :is="rowTag"
              v-if="walletSplitOffered"
              class="payment-option payment-wallet-split"
              :class="{ active: useWallet }"
            >
              <input
                type="checkbox"
                class="payment-mark"
                v-model="useWallet"
              >
              <span class="payment-body">
                <span class="payment-title">{{ $t('ui.paymentGateways.useWallet') }}</span>
                <span class="payment-sub">
                  <template v-if="useWallet">
                    {{ $t('ui.paymentGateways.walletSplitOn', { applied: money(walletApplied), left: money(finalPayableAmount) }) }}
                  </template>
                  <template v-else>
                    {{ $t('ui.paymentGateways.walletSplitOff', { balance: money(walletBalance) }) }}
                  </template>
                </span>
              </span>
              <span class="payment-logo" aria-hidden="true">
                <i class="fa-solid fa-wallet"></i>
              </span>
            </component>

            <!-- CARD: one row, SumUp or Stripe as chosen in admin settings -->
            <component
              :is="rowTag"
              v-if="cardMethod"
              class="payment-option"
              :class="{ active: !readonly && paymentType === cardMethod }"
              :data-method="cardMethod"
            >
              <input
                v-if="!readonly"
                type="radio"
                class="payment-mark"
                name="payment"
                :value="cardMethod"
                v-model="paymentType"
              >
              <span class="payment-body">
                <span class="payment-title">{{ $t('ui.paymentGateways.card') }}</span>
                <span class="payment-sub">{{ $t('ui.paymentGateways.cardSub', { provider: cardMethod === orderMethods.STRIPE ? 'Stripe' : 'SumUp' }) }}</span>
              </span>
              <span class="payment-logo payment-cards" aria-hidden="true">
                <span class="card-chip card-visa">VISA</span>
                <span class="card-chip card-mc"><i></i><i></i></span>
                <span class="card-chip card-amex">AMEX</span>
              </span>
            </component>

            <!--
              Stripe's card form, in the page under its row; the page's own
              confirm button pays (initStripe). Outside the row's label on
              purpose, so a click inside the form cannot reach the row.
            -->
            <div v-if="inlineStripe" class="stripe-card-panel">
              <stripe-payment
                ref="stripeCard"
                inline
                :stripe-key="paymentGateway.stripe_key"
                :amount="finalPayableAmount"
                :currency="currencyData"
                :name="userName"
                :email="userEmail"
                :place-order="placeStripeOrder"
                @success="orderPlaced('success', $event)"
              />
            </div>

            <!-- CASH -->
            <component
              :is="rowTag"
              v-if="cashOffered"
              class="payment-option"
              :class="{ active: !readonly && paymentType === orderMethods.CASH_ON_DELIVERY }"
              :data-method="orderMethods.CASH_ON_DELIVERY"
            >
              <input
                v-if="!readonly"
                type="radio"
                class="payment-mark"
                name="payment"
                :value="orderMethods.CASH_ON_DELIVERY"
                v-model="paymentType"
              >
              <span class="payment-body">
                <span class="payment-title">{{ $t('ui.paymentGateways.cash') }}</span>
                <span class="payment-sub">{{ $t('ui.paymentGateways.cashSub') }}</span>
              </span>
              <span class="payment-logo" aria-hidden="true">
                <i class="fa-solid fa-money-bill-1"></i>
              </span>
            </component>

            <!-- BANK -->
            <component
              :is="rowTag"
              v-if="parseInt(paymentGateway.bank) === status.PUBLIC"
              class="payment-option"
              :class="{ active: !readonly && paymentType === orderMethods.BANK }"
              :data-method="orderMethods.BANK"
            >
              <input
                v-if="!readonly"
                type="radio"
                class="payment-mark"
                name="payment"
                :value="orderMethods.BANK"
                v-model="paymentType"
              >
              <span class="payment-body">
                <span class="payment-title">{{ $t('ui.paymentGateways.bank') }}</span>
                <span class="payment-sub">{{ $t('ui.paymentGateways.bankSub') }}</span>
              </span>
              <span class="payment-logo" aria-hidden="true">
                <i class="fa-solid fa-building-columns"></i>
              </span>
            </component>

            <!--
              WALLET, as the whole payment: listed only when the balance
              covers the order (or on the read-only list of what we accept).
              A smaller balance is used through "Use my wallet balance" above,
              so a greyed row saying it is not enough only added noise.
            -->
            <component
              :is="rowTag"
              v-if="readonly || walletCovers"
              class="payment-option"
              :class="{ active: !readonly && paymentType === orderMethods.WALLET, 'is-disabled': !readonly && !walletCovers }"
              :data-method="orderMethods.WALLET"
            >
              <input
                v-if="!readonly"
                type="radio"
                class="payment-mark"
                name="payment"
                :value="orderMethods.WALLET"
                :disabled="!walletCovers"
                v-model="paymentType"
              >
              <span class="payment-body">
                <span class="payment-title">{{ $t('ui.paymentGateways.wallet') }}</span>
                <span class="payment-sub">
                  {{ $t('ui.paymentGateways.balance', { amount: currencyIcon + walletBalance }) }}<template v-if="!readonly && !walletCovers && walletSplitOffered"> — {{ $t('ui.paymentGateways.notEnoughSplit') }}</template><template v-else-if="!readonly && !walletCovers"> — {{ $t('ui.paymentGateways.notEnough') }}</template>
                </span>
              </span>
              <span class="payment-logo" aria-hidden="true">
                <i class="fa-solid fa-wallet"></i>
              </span>
            </component>

          </div>

          <!-- <div
            class="tab-heading"
          >

            <label
              v-if="parseInt(paymentGateway.stripe) === status.PUBLIC"
              :class="{active: paymentType === orderMethods.STRIPE}"
            >
              <input
                type="radio"
                name="payment"
                :value="orderMethods.STRIPE"
                v-model="paymentType"
              >
              <i class="icon stripe-icon"/>
              <span>{{ $t('payment.stripe') }}</span>
            </label>

            <label
              v-if="parseInt(paymentGateway.sumup) === status.PUBLIC"
              :class="{active: paymentType === orderMethods.SUMUP}"
            >
              <input
                type="radio"
                name="payment"
                :value="orderMethods.SUMUP"
                v-model="paymentType"
              >
              <svg width="85" height="45" viewBox="0 0 444 128" fill="none" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
                <path d="M299.853 35.6328H299.577C294.658 35.6328 290.198 37.6507 287.026 40.9069C283.854 37.6966 279.394 35.6787 274.475 35.6787H274.199C264.544 35.6787 256.637 43.4752 256.637 53.0603V86.9979H256.683C256.821 89.612 258.981 91.7217 261.648 91.7217C264.314 91.7217 266.475 89.612 266.613 86.9979H266.659V53.0603C266.659 48.9327 270.061 45.5389 274.245 45.5389H274.521C278.613 45.5389 281.923 48.7034 282.061 52.7392C282.061 52.831 282.061 52.9227 282.061 53.0144V86.9979C282.107 88.0069 282.428 88.8783 282.98 89.6579C283.9 90.942 285.371 91.7675 287.026 91.7675C289.692 91.7675 291.853 89.6579 291.991 87.0438H292.037V53.1061C292.037 53.0144 292.037 52.8768 292.037 52.7851C292.175 48.7951 295.531 45.5848 299.577 45.5848H299.853C304.037 45.5848 307.439 48.9327 307.439 53.1061V86.7228C307.439 86.7686 307.439 86.7686 307.439 86.8145C307.439 86.8603 307.439 86.9062 307.439 86.9062V87.0438C307.577 89.6579 309.737 91.7675 312.404 91.7675C315.07 91.7675 317.231 89.6579 317.369 87.0438H317.415V53.0603C317.369 43.4293 309.508 35.6328 299.853 35.6328Z" fill="black">
                </path>
                <path d="M240.819 35.6328C238.107 35.6328 235.946 37.7425 235.808 40.3566V74.3401C235.808 78.4676 232.36 81.8155 228.13 81.8155H227.855C223.625 81.8155 220.223 78.4676 220.223 74.3401V40.6776C220.223 40.6317 220.223 40.6317 220.223 40.5859V40.4942V40.3566C220.085 37.7425 217.924 35.6328 215.212 35.6328C212.499 35.6328 210.338 37.7425 210.2 40.3566H210.154V74.3401C210.154 83.9252 218.108 91.7216 227.9 91.7216H228.176C237.969 91.7216 245.923 83.9252 245.923 74.3401V40.3566C245.693 37.7425 243.532 35.6328 240.819 35.6328Z" fill="black">
                </path>
                <path d="M359.108 35.6328C356.396 35.6328 354.235 37.7425 354.097 40.3566H354.051V74.3401C354.051 78.4676 350.603 81.8155 346.419 81.8155H346.144C341.914 81.8155 338.512 78.4676 338.512 74.3401V40.6776C338.512 40.6317 338.512 40.6317 338.512 40.5859V40.4942V40.3566C338.374 37.7425 336.213 35.6328 333.501 35.6328C330.788 35.6328 328.627 37.7425 328.489 40.3566H328.443V74.3401C328.443 83.9252 336.397 91.7216 346.19 91.7216H346.465C356.258 91.7216 364.212 83.9252 364.212 74.3401V40.3566H364.166C364.028 37.7425 361.821 35.6328 359.108 35.6328Z" fill="black">
                </path>
                <path d="M392.396 35.6328H392.12C382.19 35.6328 374.19 43.6127 374.19 53.3813V73.9273C374.19 74.5235 374.144 75.1197 374.19 75.7159V114.836C374.19 117.542 376.443 119.743 379.202 119.743C381.96 119.743 384.213 117.542 384.213 114.836V89.2451C386.098 90.8961 389.27 91.7216 392.12 91.7216H392.396C402.327 91.7216 409.913 83.2372 409.913 73.4687V52.9227C409.913 43.1083 402.327 35.6328 392.396 35.6328ZM400.166 73.9732C400.166 79.339 396.672 81.6321 392.396 81.6321H392.12C387.845 81.6321 384.351 79.339 384.351 73.9732V53.4271C384.351 49.2079 387.845 45.7682 392.12 45.7682H392.396C396.672 45.7682 400.166 49.2079 400.166 53.4271V73.9732Z" fill="black">
                </path>
                <path d="M185.553 57.784C179.898 55.5827 176.45 54.0692 176.45 50.7672C176.45 48.1531 178.565 45.4931 183.3 45.4931C186.289 45.4931 188.771 46.7772 190.564 49.2079C191.76 50.7672 193.093 51.5468 194.564 51.5468C197.414 51.5468 199.805 49.2537 199.805 46.5479C199.805 45.4931 199.529 44.53 198.978 43.7503C195.851 38.9807 189.415 35.6328 183.3 35.6328C174.887 35.6328 166.381 40.8152 166.381 50.7672C166.381 60.9943 174.841 64.2505 181.599 66.8646C187.024 68.9743 191.714 70.7629 191.714 75.2573C191.714 78.5594 188.633 81.9073 182.702 81.9073C180.909 81.9073 177.507 81.4945 175.255 78.7887C174.059 77.4128 172.634 76.6332 171.255 76.6332C168.542 76.6332 166.014 79.018 166.014 81.6321C166.014 82.6869 166.335 83.65 166.979 84.6131C170.105 89.291 177.231 91.7675 182.657 91.7675C191.943 91.7675 201.782 85.989 201.782 75.3032C201.782 64.1129 192.771 60.6274 185.553 57.784Z" fill="black">
                </path>
                <path d="M119.902 0H9.2409C4.13772 0 0 4.08169 0 9.12648V118.874C0 123.918 4.13772 128 9.2409 128H119.902C125.005 128 129.143 123.918 129.143 118.874V9.12648C129.097 4.08169 125.005 0 119.902 0ZM81.0992 96.3554C69.8355 107.546 51.8134 108.05 39.9519 97.823C39.906 97.7772 39.814 97.7313 39.7681 97.6396C39.0325 96.9058 39.0325 95.7592 39.7221 95.0254L79.766 55.3092C80.5016 54.6213 81.6509 54.6213 82.3865 55.3551C92.8687 67.1415 92.409 85.1193 81.0992 96.3554ZM89.4206 32.9287L49.3767 72.6449C48.6412 73.3329 47.4918 73.3329 46.7562 72.5991C36.274 60.8585 36.6878 42.8807 48.0435 31.6446C59.3073 20.4543 77.3293 19.9957 89.1908 30.177C89.2367 30.2229 89.3287 30.2687 89.3747 30.3604C90.1103 31.0484 90.1103 32.1949 89.4206 32.9287Z" fill="black">
                </path>
                <path d="M433.221 35.6328C427.704 35.6328 423.244 40.0814 423.244 45.5389C423.244 50.9965 427.704 55.4451 433.221 55.4451C438.738 55.4451 443.197 50.9965 443.197 45.5389C443.197 40.0814 438.738 35.6328 433.221 35.6328ZM433.221 53.0602C429.037 53.0602 425.681 49.7123 425.681 45.5389C425.681 41.4114 429.083 38.0635 433.221 38.0635C437.404 38.0635 440.76 41.4114 440.76 45.5389C440.76 49.6665 437.358 53.0602 433.221 53.0602Z" fill="black">
                </path>
                <path d="M434.328 45.9106C435.616 45.6812 436.443 44.718 436.443 43.3878C436.443 41.6907 435.248 40.5898 433.409 40.5898H430.926C430.374 40.5898 429.914 40.9568 429.914 41.5989V48.5709C429.914 49.3048 430.328 49.6718 430.926 49.6718C431.523 49.6718 431.937 49.3048 431.937 48.5709V46.0023H431.983L434.512 49.1672C434.788 49.4883 434.972 49.6718 435.478 49.6718C436.26 49.6718 436.535 49.0296 436.535 48.7085C436.535 48.3875 436.397 48.1123 436.122 47.837L434.328 45.9106ZM433.317 44.4887H431.983V42.5163H433.317C433.914 42.5163 434.374 42.8833 434.374 43.4796C434.374 44.03 433.96 44.4887 433.317 44.4887Z" fill="black">
                </path>
              </svg>
              <span class="sumup">SumUp</span>
            </label>


            <label
              v-if="parseInt(paymentGateway.flutterwave) === status.PUBLIC"
              :class="{active: paymentType === orderMethods.FLUTTERWAVE}"
            >
              <input
                type="radio"
                name="payment"
                :value="orderMethods.FLUTTERWAVE"
                v-model="paymentType"
              >
              <i class="icon flutterwave-icon"/>
              <span>{{ $t('payment.flutterwave') }}</span>
            </label>


            <label
              v-if="parseInt(paymentGateway.razorpay) === status.PUBLIC"
              :class="{active: paymentType === orderMethods.RAZORPAY}"
            >
              <input
                type="radio"
                name="payment"
                :value="orderMethods.RAZORPAY"
                v-model="paymentType"
              >
              <i class="icon razorpay-icon"/>
              <span>{{ $t('payment.razorpay') }}</span>
            </label>


            <label
              v-if="parseInt(paymentGateway.paypal) === status.PUBLIC"
              :class="{active: paymentType === orderMethods.PAYPAL}"
            >
              <span
                class="spinner-wrapper flex layer-white"
                v-if="!paypaLoaded && paymentType === orderMethods.PAYPAL"
              >
                <spinner
                  :radius="50"
                />
              </span>

              <input
                type="radio"
                name="payment"
                :value="orderMethods.PAYPAL"
                v-model="paymentType"
              >
              <i class="icon paypal-icon"/>
              <span>{{$t('payment.paypal')}}</span>
            </label>


            <label
              v-if="parseInt(paymentGateway.iyzico_payment) === status.PUBLIC"
              :class="{active: paymentType === orderMethods.IYZICO_PAYMENT}"
            >
              <input
                type="radio"
                name="payment"
                :value="orderMethods.IYZICO_PAYMENT"
                v-model="paymentType"
              >
              <i class="icon iyzico-icon"/>
              <span>{{ $t('filter.payIyzico') }}</span>
            </label>


            <label
              v-if="parseInt(paymentGateway.payfast_payment) === status.PUBLIC"
              :class="{active: paymentType === orderMethods.PAYFAST}"
            >
              <input
                type="radio"
                name="payment"
                :value="orderMethods.PAYFAST"
                v-model="paymentType"
              >
              <i class="icon payfast-icon"/>
              <span>{{ $t('invent.pf') }}</span>
            </label>



            <label
              v-if="cashOffered"
              :class="{active: paymentType === orderMethods.CASH_ON_DELIVERY}"
            >
              <input
                type="radio"
                name="payment"
                :value="orderMethods.CASH_ON_DELIVERY"
                v-model="paymentType"
              >
              <i class="icon cod-icon"/>
              <span>{{ $t('orderTabbing.cod') }}</span>
            </label>


            <label
              v-if="parseInt(paymentGateway.bank) === status.PUBLIC && orderMethod !== orderMethods.BANK"
              :class="{active: paymentType === orderMethods.BANK}"
            >
              <input
                type="radio"
                name="payment"
                :value="orderMethods.BANK"
                v-model="paymentType"
              >
              <i class="icon bank-icon mb-5"/>
              <span>{{ $t('date.bt') }}</span>
            </label>

          </div> -->

          <div
            class="tab-content mb-10" v-if="!hideConfirmBtn"
          >
            <p v-if="blocked && blockedReason" class="payment-blocked" :class="{ 'external-confirm-btn': externalConfirm }">
              {{ blockedReason }}
            </p>

            <ajax-button

              :class="{ 'external-confirm-btn': externalConfirm }"
              v-if="paymentType === orderMethods.STRIPE"
              class="primary-btn  plr-30 plr-sm-15"
              type="button"
              :fetching-data="placingOrder"
              :disabled="cannotPlaceOrder"
              :text="paymentBtnText"
              @clicked="initStripe"
            />

            <ajax-button

              :class="{ 'external-confirm-btn': externalConfirm }"
              v-else-if="paymentType === orderMethods.SUMUP"
              class="primary-btn plr-30 plr-sm-15"
              type="button"
              :fetching-data="placingOrder"
              :disabled="cannotPlaceOrder"
              :text="paymentBtnText"
              @clicked="initSumup"
            />

            <ajax-button

              :class="{ 'external-confirm-btn': externalConfirm }"
              v-else-if="paymentType === orderMethods.RAZORPAY"
              class="primary-btn  plr-30 plr-sm-15"
              type="button"
              :fetching-data="placingOrder"
              :disabled="cannotPlaceOrder"
              :text="paymentBtnText"
              @clicked="initRazorpay"
            />

            <div
              v-else-if="paymentType === orderMethods.PAYFAST"
            >

              <ajax-button

                :class="{ 'external-confirm-btn': externalConfirm }"
                class="primary-btn  plr-30 plr-sm-15"
                type="button"
                :fetching-data="placingOrder"
                :disabled="cannotPlaceOrder"
                :text="paymentBtnText"
                @clicked="initPayFast"
              />

              <div v-if="payFastData" ref="payFastContainer" v-html="payFastForm"></div>

            </div>


            <div
              v-else-if="paymentType === orderMethods.CASH_ON_DELIVERY"
            >
              <ajax-button
                :class="{ 'external-confirm-btn': externalConfirm }"
                class="primary-btn  plr-30 plr-sm-15"
                type="button"
                :fetching-data="placingOrder"
                :disabled="cannotPlaceOrder"
                :text="$t('checkout.confirmOrder')"
                @clicked="onConfirmOrder"
              />
            </div>


            <div
              v-else-if="paymentType === orderMethods.BANK"
            >
              <ajax-button
                :class="{ 'external-confirm-btn': externalConfirm }"
                class="primary-btn plr-30 plr-sm-15"
                type="button"
                :fetching-data="placingOrder"
                :disabled="cannotPlaceOrder"
                :text="$t('checkout.confirmOrder')"
                @clicked="confirmBankOrder"
              />
            </div>

            <div
              v-else-if="paymentType === orderMethods.IYZICO_PAYMENT"
            >
              <iyzico-payment
                ref="iyzicoPayment"
                :order="orderData"
                :btn-text="paymentBtnText"
                @clicked="payWithIyzicoPayment"
                @success="izcoOrderPlaces"
                @closed="orderPlaced('closed', $event)"
                @error="orderPlaced('error', $event)"
              />
            </div>


            <div
              v-else-if="paymentType === orderMethods.FLUTTERWAVE"
            >
              <flutterwave-pay-btn
                ref="flutterWave"
                :order="orderData"
                :public-key="paymentGateway.fw_public_key"
                :amount="parseFloat(amount)"
                :currency="currency"
                :btn-text="paymentBtnText"
                :name="userName"
                :loading="!flutterwaveLoaded"
                :user-id="`${userId}`"
                :email="userEmail"
                :site-name="siteName"
                :header-logo="headerLogo"
                @clicked="payWithFlutterWave"
                @success="orderPlaced('success', $event)"
                @closed="orderPlaced('closed', $event)"
                @error="orderPlaced('error', $event)"
              />
            </div>

            <div
              v-else-if="paymentType === orderMethods.WALLET"
            >
              <ajax-button
                :class="{ 'external-confirm-btn': externalConfirm }"
                class="primary-btn plr-30 plr-sm-15"
                type="button"
                :fetching-data="placingOrder"
                :disabled="cannotPlaceOrder || walletBalance < baseAmount"
                :text="walletBalance >= baseAmount 
                  ? paymentBtnText 
                  : $t('ui.paymentGateways.insufficientBtn')"
                @clicked="payWithWallet"
              />
            </div>

            <!--
              Nothing picked yet - a reload loses the choice made on /shipping.
              A button saying what it wants beats the panel simply ending.
            -->
            <ajax-button
              :class="{ 'external-confirm-btn': externalConfirm }"
              v-else-if="!paymentType"
              class="primary-btn plr-30 plr-sm-15"
              type="button"
              :fetching-data="false"
              :disabled="true"
              :text="$t('ui.paymentGateways.chooseMethod')"
            />

            <div
              v-if="parseInt(paymentGateway.paypal) === status.PUBLIC"
              class="paypal-tab"
              :class="{'paypal-active': paymentType === orderMethods.PAYPAL}"
            >
              <div
                ref="paypal"
              />
            </div>

          </div>

        </div>
      </form>
    </div>
  </div>
</template>
<script>
  import StripePayment from '~/components/StripePayment'
  import RazorpayPayment from '~/components/RazorpayPayment'
  import util from '~/mixin/util'
  import {mapActions, mapGetters} from 'vuex'
  import productHelper from "~/mixin/productHelper"
  import productPriceHelper from "~/mixin/productPriceHelper"
  import paymentHelper from '~/mixin/paymentHelper'
  import Spinner from "./Spinner";
  import AjaxButton from "./AjaxButton";
  import FlutterwavePayBtn from "./FlutterwavePayBtn";
  import IyzicoPayment from "./IyzicoPayment";

  export default {
    middleware: ['auth'],
    data() {
      return {
        points: null,
        usePoints: false,
        // split payment: part of the order from the wallet
        useWallet: false,

        loading: false,
        payFastLoader: false,
        payFastData: null,
        flutterwaveLoaded: false,
        paypaLoaded: false,
        showRazorpay: false,
        showStripe: false,
        paymentType: 1,
        // mounted() assigns the gateway's default method. That is not someone
        // choosing cash, so the collection-store check has to stay quiet until
        // the form is actually in the shopper's hands.
        acceptingChoices: false,
        /*
         * The order is away. Placing it strips the bought rows out of the
         * basket, so every "is this a collection order" read below flips to
         * "delivery" a moment later - not because the shopper changed their
         * mind, but because there is nothing left to collect. Once this is set,
         * the cash guard stops answering.
         */
        orderFinalised: false,
        orderData: null,
        orderError: null,
        submitting: false,
        placingOrder: false,
        checkedProductQty: 0,
      }
    },
    props: {
      /*
       * The purchase order number this order is to be invoiced against.
       *
       * Whether one is needed is the server's decision, taken from the stored
       * business profile; this is only the text the customer typed. Passing it
       * through rather than reading it here keeps the field itself on the
       * checkout form, next to the other order details.
       */
      purchaseOrderNumber: {
        type: String,
        default: ''
      },
      hideConfirmBtn: {
        type: Boolean,
        default: false
      },
      /*
       * The page draws the order button itself (the Payment step puts it in
       * the Order Summary, under the total). The panel then only reports what
       * the button should say and whether it may be pressed ('confirm-state'),
       * and places the order when the page calls confirmAction(). Gateways
       * that render their own button (PayPal, Flutterwave, Iyzico) keep it
       * here, and the state says the page's button is not available for them.
       */
      externalConfirm: {
        type: Boolean,
        default: false
      },
      /*
       * The basket lists what we accept; it does not take the choice. Nothing
       * is being paid for there yet, so a radio group would be asking a
       * question that has no effect.
       */
      readonly: {
        type: Boolean,
        default: false
      },
      /*
       * /shipping takes the choice now, so the panel has to be able to hold
       * "not answered yet". mounted() otherwise picks the gateway default,
       * which would answer for the shopper and let them straight past the gate.
       */
      requireChoice: {
        type: Boolean,
        default: false
      },
      /*
       * The method already chosen on an earlier step, so /checkout opens on it
       * instead of reverting to the default and losing the answer.
       */
      preselect: {
        type: Number,
        default: null
      },
      /*
       * The page's own answer to "is this a collection order", for pages that
       * own the delivery choice. null means it is not saying, and
       * needsCollectionForCash falls back to reading the basket.
       */
      collectionOrder: {
        type: Boolean,
        default: null
      },
      /*
       * Set by the page when something it owns is still missing - a delivery
       * order with no address, for one. The payment panel cannot see those
       * requirements itself, so it takes the answer and refuses to place the
       * order until it clears.
       */
      blocked: {
        type: Boolean,
        default: false
      },
      blockedReason: {
        type: String,
        default: ''
      },
      voucher: {
        type: Object,
        default() {
          return null
        }
      },
      order: {
        type: Object,
        default() {
          return null
        }
      },
      page: {
        type: String,
        default: 'checkout'
      },
      totalPrice: {
        type: Number,
        default: 0
      }
    },
    watch: {
      paymentType(value) {
        this.$emit('selected-method', value)
      },
      /*
       * Payment has started, by whichever method: tell the server, so the
       * basket reservation (CartHold) is not emptied while the card is being
       * charged. Fire and forget - paying must not wait on it.
       */
      placingOrder(busy) {
        if (busy) this.holdBasketForPayment()
      },
      walletApplied: {
        immediate: true,
        handler(amount) {
          this.$emit('wallet-applied', amount)
        }
      },
      confirmState: {
        immediate: true,
        handler(state) {
          if (this.externalConfirm) this.$emit('confirm-state', state)
        }
      },
      /*
       * Cash is no longer on offer - the shopper switched to a posted order.
       * A selection pointing at a row that has just left the screen would be
       * invisible and still refused at the end, so drop it and say why.
       */
      cashOffered(offered) {
        if (offered || this.readonly || !this.acceptingChoices) {
          return
        }

        /*
         * The basket emptying because the order went through is not the
         * shopper switching to a posted order. Without this the cash-only
         * notice landed on top of "Order placed successfully" on every
         * collection order paid in cash.
         */
        if (this.orderFinalised) {
          return
        }

        if (parseInt(this.paymentType) !== this.orderMethods.CASH_ON_DELIVERY) {
          return
        }

        this.paymentType = null

        this.setToastMessage(
          this.$t('ui.paymentGateways.cashCollectionOnly')
        )
      },
      payFastForm() {
        this.$nextTick(() => {
          this.$refs.payFastContainer?.querySelector('#frmPayment')?.submit()
          //this.payFastLoader = false
        })
      },
      
    },
    components: {
      IyzicoPayment,
      FlutterwavePayBtn,
      AjaxButton,
      Spinner,
      RazorpayPayment,
      StripePayment
    },
    mixins: [util, productHelper, paymentHelper, productPriceHelper],
    computed: {
      rowTag() {
        return this.readonly ? 'div' : 'label'
      },
      /*
       * The one gateway behind the card row: SumUp or Stripe, never both. The
       * server resolves it (Payment::cardGateway) and refuses the other; the
       * flags are only a fallback for a response from before that field.
       */
      cardMethod() {
        const g = this.paymentGateway
        if (!g) return null
        if ('card_gateway' in g) return g.card_gateway ? parseInt(g.card_gateway) : null
        if (parseInt(g.sumup) === this.status.PUBLIC) return this.orderMethods.SUMUP
        if (parseInt(g.stripe) === this.status.PUBLIC) return this.orderMethods.STRIPE
        return null
      },
      // What the card is charged: the order less any wallet part taken.
      // Stripe's card form sits in the page under the selected card row.
      inlineStripe() {
        return this.isCheckout && !this.readonly &&
          this.cardMethod === this.orderMethods.STRIPE &&
          parseInt(this.paymentType) === this.orderMethods.STRIPE
      },
      stripeAmount() {
        const total = parseFloat(this.orderData?.total_amount) || 0
        const wallet = parseFloat(this.orderData?.wallet_amount) || 0
        return Math.max(0, Math.round((total - wallet) * 100) / 100)
      },
      /*
       * Cash is collection-only, so on a posted order it is simply not offered.
       * Letting it be picked and then refusing it - which is what the popup
       * here used to do - asks the shopper to discover a rule that could have
       * been expressed by leaving the row out.
       *
       * readonly is the exception: there the list states what the shop accepts
       * rather than offering a choice, so nothing is withheld.
       */
      cashOffered() {
        if (parseInt(this.paymentGateway?.cash_on_delivery) !== this.status.PUBLIC) {
          return false
        }

        return this.readonly || !this.needsCollectionForCash
      },
      needsCollectionForCash() {
        /*
         * Where the page owns the delivery choice it can simply say. /shipping
         * takes the payment choice before that choice has been written anywhere
         * the basket can see, so the fallback below reports "delivery" there
         * even once Collection Store has been picked.
         */
        if (this.collectionOrder !== null) {
          return !this.collectionOrder
        }

        /*
         * carts.shipping_type defaults to 1 (delivery) and nothing ever writes
         * it back - the /shipping choice lives only in that page's local
         * cartShipping map, api/v1/update-shipping has no caller, and the
         * cart store's SET_PRODUCT_SHIPPING_TYPE mutation is never committed.
         * So the column reports "delivery" for a collection order too, which
         * is what used to withhold cash from people who had already chosen
         * collection. The store's collectionContact is set by /shipping when
         * collection is picked, so it is the one record of the real choice.
         */
        if (this.$store.state.cart.collectionContact) {
          return false
        }

        return (this.cartProducts || []).some(product =>
          parseInt(product.selected) === 1 &&
          parseInt(product.shipping_type) === 1
        )
      },
      cannotPlaceOrder() {
        return !this.finalPayableAmount || this.noPaymentMethod || this.blocked
      },
      walletCovers() {
        const balance = Number(this.walletBalance || 0)
        return balance > 0 && balance >= Number(this.baseAmount || 0)
      },
      pointsDiscount() {
        if (!this.usePoints) {
          return 0
        }
        return Number(this.points?.value || 0)
      },
      payFastForm(){
        return this.payFastData?.payfast
      },
      orderMethod(){
        return this.order?.order_method
      },
      /* The order button's label and state, for a page that draws it (externalConfirm). */
      confirmState() {
        const m = this.orderMethods
        const type = this.paymentType
        const ownButton = [m.PAYPAL, m.FLUTTERWAVE, m.IYZICO_PAYMENT].includes(type)

        let text = this.paymentBtnText
        let disabled = this.cannotPlaceOrder

        if (!type) {
          text = this.$t('ui.paymentGateways.chooseMethod')
          disabled = true
        } else if (type === m.CASH_ON_DELIVERY || type === m.BANK) {
          text = this.$t('checkout.confirmOrder')
        } else if (type === m.WALLET) {
          const covers = this.walletBalance >= this.baseAmount
          text = covers ? this.paymentBtnText : this.$t('ui.paymentGateways.insufficientBtn')
          disabled = this.cannotPlaceOrder || !covers
        }

        return {
          available: !ownButton,
          text,
          disabled: !!disabled,
          loading: !!this.placingOrder,
          blockedReason: this.blocked ? (this.blockedReason || '') : ''
        }
      },
      paymentBtnText() {
        return this.$t('checkout.confirmOrderAnd', {amount: this.formattedPrice})
      },
      /* From the store: loaded on /shipping already, so it is here on arrival. */
      walletBalance() {
        return Number(this.$store.state.user.walletBalance || 0)
      },
      /* The order after its discounts and points: what a full payment covers. */
      baseAmount() {
        return Math.max(0, this.totalPrice - this.pointsDiscount)
      },
      /* A balance that helps but cannot pay the whole order on its own. */
      walletSplitOffered() {
        const balance = Number(this.walletBalance || 0)

        return !this.readonly && this.$route.path === '/checkout' &&
          balance > 0 && balance < Number(this.baseAmount || 0)
      },
      /*
       * The wallet part, worked out exactly as the server does
       * (WalletSplit::apply): the balance, but always leaving at least a cent
       * for the chosen method to charge.
       */
      walletApplied() {
        if (!this.useWallet || !this.walletSplitOffered ||
          this.paymentType === this.orderMethods.WALLET) {
          return 0
        }

        const take = Math.min(Number(this.walletBalance || 0), this.baseAmount - 0.01)
        return take > 0 ? Math.round(take * 100) / 100 : 0
      },
      /* What the chosen method is asked to charge. */
      finalPayableAmount(){
          return Math.max(
            0,
            Math.round((this.baseAmount - this.walletApplied) * 100) / 100
          )
      },
      formattedPrice() {
        return this.priceFormat(this.currencyPosition, this.currencyIcon, this.finalPayableAmount, this.setting)
      },
      voucherResult() {
        return this.voucher
      },
      isCheckout() {
        return this.page === 'checkout'
      },
      userEmail() {
        return this.orderData?.email || this.profile?.email || this.$auth?.user?.email
      },
      headerLogo() {
        return this.imageURL({'image': this.site_setting.header_logo})
      },
      siteName() {
        return this.site_setting?.site_name
      },
      currencyData() {
        return this.orderData?.currency || this.currency
      },
      userId() {
        return this.$auth?.user?.id
      },
      userName() {
        return this.orderData?.userName || this.$auth?.user?.name
      },
      razorpayPaymentToken() {
        return this.orderData?.payment_token || null
      },
      amount() {
        return parseFloat(this.orderData?.total_amount).toFixed(2) || 0
      },
      orderId() {
        return this.orderData?.id || null
      },
      noPaymentMethod() {
        return !this.cardMethod &&
          parseInt(this.paymentGateway?.razorpay) !== this.status.PUBLIC &&
          parseInt(this.paymentGateway?.paypal) !== this.status.PUBLIC &&
          parseInt(this.paymentGateway?.iyzico_payment) !== this.status.PUBLIC &&
          parseInt(this.paymentGateway?.flutterwave) !== this.status.PUBLIC &&
          parseInt(this.paymentGateway?.bank) !== this.status.PUBLIC &&
          parseInt(this.paymentGateway?.cash_on_delivery) !== this.status.PUBLIC&&
          parseInt(this.paymentGateway?.payfast_payment) !== this.status.PUBLIC
      },
      checkedProduct() {
        return this.cartProducts.filter(obj => {
          return parseInt(obj.selected) === 1
        })
      },
      ...mapGetters('user', ['profile']),
      ...mapGetters('language', ['langCode']),
      ...mapGetters('common', ['currencyIcon', 'setting', 'currency', 'currencyPosition', 'paymentGateway', 'site_setting']),
      ...mapGetters('cart', {cartProducts: 'checkoutProducts'}),
    },
    methods: {
      async fetchPoints() {
        const token = this.$auth?.strategy?.token?.get()
        const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'
        const { data } = await this.$axios.get(
          `${baseUrl}api/v1/user/points`,
          {
            headers: {
              Authorization: `Bearer ${token}`
            }
          }
        )
        this.points = data
      },
      /*
       * The <label> already checks its radio natively. This runs off the row's
       * own click as well, so the choice registers even where that native path
       * is interfered with, and it makes the whole row the target rather than
       * just the 18px circle. Assigning the value it already holds is a no-op,
       * so the two paths cannot fight.
       */
      choose(method) {
        if (this.paymentType !== method) {
          this.paymentType = method
        }
      },
      onOptionClick(event) {
        if (this.readonly) {
          return
        }

        const target = event.target

        if (!target || typeof target.closest !== 'function') {
          return
        }

        const row = target.closest('[data-method]')

        if (!row || row.classList.contains('is-disabled')) {
          return
        }

        const method = Number(row.dataset.method)

        if (!Number.isNaN(method)) {
          this.choose(method)
        }
      },
      /* What the in-panel button for the chosen method does, for externalConfirm. */
      confirmAction() {
        const m = this.orderMethods

        switch (this.paymentType) {
          case m.STRIPE: return this.initStripe()
          case m.SUMUP: return this.initSumup()
          case m.RAZORPAY: return this.initRazorpay()
          case m.PAYFAST: return this.initPayFast()
          case m.CASH_ON_DELIVERY: return this.onConfirmOrder()
          case m.BANK: return this.confirmBankOrder()
          case m.WALLET: return this.payWithWallet()
          default: return undefined
        }
      },
      async payWithWallet() {
        try {
          this.placingOrder = true

          if (this.walletBalance < this.baseAmount) {
            this.setToastError(this.$t('ui.paymentGateways.insufficientToast'))
            return
          }

          await this.confirmOrder()

          await this.paymentDoneFn(
            this.orderId,
            this.orderId,
            this.orderMethods.WALLET
          )

          this.orderPlaced('success', this.orderId)

        } catch (e) {
          console.log('Wallet error:', e)
          this.reportPaymentFailure(this.$t('ui.paymentGateways.walletFailed'))
        } finally {
          this.placingOrder = false
        }
      },
      async holdBasketForPayment() {
        try {
          const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'
          const headers = {}
          const token = this.$auth?.loggedIn ? this.$auth.strategy.token.get() : null

          if (token) headers.Authorization = token

          await this.$axios.post(baseUrl + 'api/v1/cart/hold-for-payment', {
            user_token: this.$auth?.loggedIn ? null : await this.getUserToken()
          }, { headers })
        } catch (e) {
          console.error('Basket hold for payment failed', e)
        }
      },
      fetchWallet() {
        return this.$store.dispatch('user/loadWalletBalance')
      },
      /*
       * Before a gateway that takes the money first (SumUp): can every line of
       * this checkout still be bought? A basket whose reservation ran out no
       * longer holds its units, so an item can sell out before the payment, and finding out only
       * when the order is created left the shopper charged with no order.
       *
       * Only an explicit refusal stops the payment. If the question cannot be
       * asked at all, the order's own check still runs before anything is
       * created - so a hiccup here costs nothing, rather than blocking every
       * card payment.
       */
      async checkoutStillAvailable() {
        let data = null

        try {
          const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'
          const headers = {}
          const token = this.$auth?.loggedIn ? this.$auth.strategy.token.get() : null

          if (token) headers.Authorization = token

          const response = await this.$axios.post(baseUrl + 'api/v1/cart/checkout-check', {
            user_token: this.$auth?.loggedIn ? null : await this.getUserToken()
          }, { headers })

          data = response?.data
        } catch (e) {
          console.warn('Checkout availability check failed; continuing to payment', e)
          return true
        }

        if (!data || data.status === 200 || data.status === undefined) {
          return true
        }

        const product = data?.data?.product?.[0]

        this.refreshBasketAfterRefusal()

        this.orderError = product
          ? Object.values(product).flat().filter(message => typeof message === 'string')
          : (data?.data?.form || this.refusalMessages(data?.data))

        return false
      },
      /*
       * A refused checkout may be a basket the 20-minute timer has just
       * emptied. Re-reading it brings the notice that says so, and
       * BasketNotices takes the shopper to the basket - rather than leaving a
       * bare "nothing to order" on a page still listing the old items.
       */
      refreshBasketAfterRefusal() {
        this.$store.dispatch('cart/getCartByUser', {
          lang: this.$store.getters['language/langCode'] || null
        }).catch(() => {})
      },
      async initSumup(){
        try {
          this.placingOrder = true

          if (!(await this.checkoutStillAvailable())) {
            return
          }

          const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'
          const response = await this.$axios.post(
            `${baseUrl}api/create-sumup-checkout`,
            {
              "amount": this.finalPayableAmount,
              "currency": this.currency
            },
            {
              headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
              }
            }
          );
          const data = response?.data;
          if (data?.id) {
            /*
             * The checkout above was priced at finalPayableAmount - voucher,
             * flash discount and points all taken off. /payment builds the
             * order once the card clears, and the server checks the order
             * total against what SumUp actually took: it has to be given the
             * same discounts or the two cannot agree, and a paid order is left
             * reading "Not Paid".
             */
            this.$store.commit('cart/SET_PENDING_PAYMENT', {
              voucher: this.voucherResult?.voucher || '',
              use_points: this.usePoints ? 1 : 0,
              points_discount: this.pointsDiscount,
              // the card was charged the remainder after this wallet part
              use_wallet: this.walletApplied > 0 ? 1 : 0,
              amount: this.finalPayableAmount,
              // /payment builds the order after the card clears, on a page
              // that never saw the checkout form.
              purchase_order_number: this.purchaseOrderNumber || '',
              /*
               * The card was charged with this discount taken off, so the
               * order built on the other side has to claim it too -- the
               * server checks the order total against what SumUp took.
               */
              apply_flash_discount: this.$store.state.cart.flashDiscountOptIn ? 1 : 0
            })

            this.$router.push({
              path: '/payment',
              query: { checkoutId: data.id, checkoutReference: data.checkout_reference }
            })
          }
        }catch (e) {
          console.log(e.message)
          this.reportPaymentFailure(this.$t('ui.paymentGateways.initFailed'))
        }finally {
          this.placingOrder = false
        }
      },
      async confirmBankOrder() {
        try {
          this.placingOrder = true

          if (!this.orderData) {
            await this.confirmOrder()
          }

          const orderId = this.orderData?.id || this.order?.id

          await this.paymentDoneFn(
            orderId,
            orderId,
            this.orderMethods.BANK
          )

          this.setToastMessage(this.$t('ui.paymentGateways.bankPlaced'))

          this.$router.push('/user/order/bank/' + orderId)

        } catch (e) {
          console.log("BANK ERROR:", e)
          this.reportPaymentFailure(this.$t('ui.paymentGateways.bankFailed'))
        } finally {
          this.placingOrder = false
        }
      },
      /*
       * A refused order now settles its promise rather than hanging, so these
       * have to stop before handing off to the gateway: the refusal message is
       * already on screen, and opening a payment sheet for an order that was
       * never created is the worst of both.
       */
      async payWithIyzicoPayment() {
        try {
          await this.confirmOrder()
        } catch (e) {
          this.placingOrder = false
          return
        }

        this.$refs.iyzicoPayment.makePayment(!!this.isCheckout)
      },
      async payWithFlutterWave() {
        try {
          await this.confirmOrder()
        } catch (e) {
          this.placingOrder = false
          return
        }

        this.$refs.flutterWave.makePayment()
      },
      setLoaded(resp) {
        window.paypal
          .Buttons({
            style: {
              label: 'pay'
            },
            createOrder: async (data, actions) => {
              if (this.isCheckout) {
                return this.confirmOrder()
                  .then(() => {
                    return actions.order.create({
                      purchase_units: [
                        {
                          description: '',
                          amount: {
                            currency_code: this.currency,
                            value: this.amount
                          }
                        }
                      ]
                    });
                  })
              } else {
                this.orderData = this.order
                return actions.order.create({
                  purchase_units: [
                    {
                      description: '',
                      amount: {
                        currency_code: this.currency,
                        value: this.amount
                      }
                    }
                  ]
                });
              }
            },
            onApprove: async () => {
              await this.paymentDoneFn(this.orderId, this.orderId, this.orderMethods.PAYPAL)
              this.orderPlaced('success', this.orderId)
            },
            onError: err => {
              this.orderPlaced('closed', err)
            }
          })
          .render(this.$refs.paypal)
      },
      async initIyzico() {
        try {
          await this.confirmOrder()
        } catch (e) {
          console.log(e)
        }
      },
      async initPayFast() {
        try {
          this.payFastData = await this.confirmOrder()

          console.log(this.payFastData)
          this.payFastLoader = true
        } catch (e) {
          console.log(e)
        }
      },
      async initRazorpay() {
        try {
          await this.confirmOrder()
          this.showStripe = false
          this.showRazorpay = true
        } catch (e) {
          console.log(e)
        }
      },
      /*
       * Checkout pays first, in the page: the confirm button has the inline
       * card form authorise the card, and only then is the order placed
       * (placeStripeOrder) - so nothing exists until the card is good, and a
       * shopper who leaves has left nothing behind. An order that already
       * exists (the order page) is charged from the dialog, as before.
       */
      async initStripe() {
        if (this.isCheckout) {
          const card = this.$refs.stripeCard
          if (!card || this.placingOrder) return
          // placingOrder drives the confirm button's spinner and holds the basket.
          this.placingOrder = true
          try {
            await card.pay()
          } finally {
            this.placingOrder = false
          }
          return
        }
        try {
          await this.confirmOrder()
          this.showRazorpay = false
          this.showStripe = true
        } catch (e) {
          console.log(e)
        }
      },
      /*
       * Called by the Stripe popup once the card is authorised. The server
       * places the order and takes the money in the same request, or refuses
       * and releases the authorisation; the popup shows the refusal.
       */
      async placeStripeOrder(paymentIntent) {
        try {
          const data = await this.confirmOrder({payment_intent: paymentIntent})
          if (!data?.id) {
            return {ok: false, message: this.$t('ui.paymentGateways.notPlacedNotCharged')}
          }
          return {ok: true, orderId: data.id}
        } catch (e) {
          return {ok: false, message: this.$t('ui.paymentGateways.reasonNotCharged', { reason: e?.message || this.$t('ui.paymentGateways.notPlaced') })}
        }
      },
      stripeClosed(orderId) {
        this.showStripe = false
        // At checkout nothing was placed: stay here with the basket as it was.
        if (!this.isCheckout) this.orderPlaced('closed', orderId)
      },
      /*
       * The cash/collection button's own click handler.
       *
       * confirmOrder() REJECTS when the server refuses the order, on purpose:
       * the callers that await it need their finally{} to run and their
       * spinners to stop. Every other payment button awaits it inside a
       * try/catch. This one was bound straight to @clicked -- and a promise
       * returned from a v-on handler is not left alone. Vue 2.6 runs handlers
       * through invokeWithErrorHandling, which attaches its own .catch and
       * sends the rejection to Vue.config.errorHandler; Nuxt's handler then
       * calls app.error(err) and swaps the page for the full-screen error
       * layout.
       *
       * So an order refused for a missing purchase order number replaced the
       * entire checkout with "SORRY - Enter the purchase order number for this
       * order.", taking the basket, the summary and the very field the
       * customer had to fill in with it.
       *
       * By the time this settles the refusal is already where it belongs:
       * placeOrder() has put it in orderError and emitted field-error, which
       * checkout.vue turns into a message under the purchase order input and a
       * focus on the box. Nothing is lost by stopping the rejection here -- it
       * carries no information the customer is not already looking at.
       */
      onConfirmOrder() {
        return this.confirmOrder().catch(() => {})
      },
      confirmOrder(extra = {}) {
        /*
         * Last line of defence for a cash order that is not a collection. The
         * row is not offered on a posted order, so this only catches a stale
         * selection; it asks needsCollectionForCash rather than reading
         * shipping_type off the basket, which defaults to "delivery" and is
         * never written back - that read refused collection orders too.
         */
        if (parseInt(this.paymentType) === this.orderMethods.CASH_ON_DELIVERY &&
          this.needsCollectionForCash) {

          this.paymentType = null

          this.setToastError(
            this.$t('ui.paymentGateways.cashCollectionOnly2')
          )

          return
        }
        return new Promise(async (resolve, reject) => {
          if (this.isCheckout) {
            this.orderError = ''
            this.placeOrder(extra)
              .then(result => {
                const data = result?.data
                // Cash and bank answer with the order itself, which already
                // carries total_amount; the gateways answer with an amount.
                if (parseInt(data.order_method) !== this.orderMethods.CASH_ON_DELIVERY &&
                  parseInt(data.order_method) !== this.orderMethods.BANK
                ) {
                  data['total_amount'] = data.amount
                }
                this.orderData = data
                resolve(data)
              })
              /*
               * A refused order has to settle this promise.
               *
               * Without the rejection the caller's `await confirmOrder()`
               * never returned, so its `finally` never ran and the Place Order
               * button span for ever — on top of showing no reason. The
               * message is already on screen by this point; the callers turn
               * this into their own "payment failed" toast.
               */
              .catch(reject)
          } else {
            this.orderData = this.order

            let payDone = null

            if (parseInt(this.paymentType) === this.orderMethods.CASH_ON_DELIVERY) {
              this.placingOrder = true
              const cash = await this.paymentDoneFn(this.order.id, this.order.id, this.orderMethods.CASH_ON_DELIVERY)
              this.placingOrder = false

              // A refusal (not a collection order, or cancelled) is already
              // on screen via paymentDoneFn; do not follow it with "placed".
              if (cash?.status === 200) {
                this.orderPlaced('success', this.order.id)
              }

            }else if (parseInt(this.paymentType) === this.orderMethods.BANK) {
              this.placingOrder = true
              const bank = await this.paymentDoneFn(this.order.id, this.order.id, this.orderMethods.BANK)
              this.placingOrder = false

              // Refused (a cancelled order): the reason is already on screen.
              if (bank?.status !== 200) {
                return resolve(null)
              }

              // Not "complete": nothing is paid yet, and the bank details
              // page is the only place that says where to send the money.
              this.orderFinalised = true
              this.$emit('order-status', true)
              this.$router.push('/user/order/bank/' + this.order.id)

            } else if (parseInt(this.paymentType) === this.orderMethods.IYZICO_PAYMENT) {
              const {data} = await this.paymentDoneFn(this.order.id, this.order.id, this.orderMethods.IYZICO_PAYMENT)

              this.orderData = {...this.orderData, ...data}

            }else if (parseInt(this.paymentType) === this.orderMethods.PAYFAST) {
              const {data} = await this.paymentDoneFn(this.order.id, this.order.id, this.orderMethods.PAYFAST)
              payDone = data
            }
            resolve(payDone)
          }
        })
      },
      izcoOrderPlaces(evt) {
        this.orderPlaced('success', evt?.id, evt?.redirect, false)
      },
      orderPlaced(type = 'success', event, redirect = true, showToast = true) {
        if (type === 'success') {

          // Gateways that place the order elsewhere land here without having
          // gone through placeOrder, so the guard is set on this path too.
          this.orderFinalised = true

          if(showToast){
            this.setToastMessage(this.$t('payButton.placedSuccess'))
          }

          if (redirect) {
            this.$router.push({path: '/user/order/complete/' + event})
          }

          this.$emit('order-status', true)

        } else if (type === 'error') {

          this.$router.push({path: '/user/order/' + this.orderId})
          this.setToastError(event)

        } else if (type === 'closed') {

          // this.setToastMessage(this.$t('payButton.placedSuccess'))
          this.$router.push({path: '/user/order/' + this.orderId})
          this.$emit('order-status', false)
        }
      },
      /*
       * Every message out of a refusal body, whatever it is keyed by.
       *
       * Validation::error keys the body by the field it is about, so the shape
       * is {fieldName: ["message"]} — and the field is whichever one the
       * server refused, not a fixed list this component can enumerate.
       */
      /*
       * A generic "payment failed" toast, unless the server already told the
       * customer something specific.
       *
       * A refused order now settles its promise, so these catch blocks run on
       * a plain validation refusal too — and "Bank payment failed" on top of
       * "Enter the purchase order number for this order." is worse than the
       * precise message on its own.
       */
      reportPaymentFailure(message) {
        if (this.orderError && this.orderError.length) {
          return
        }

        this.setToastError(message)
      },
      refusalMessages(data) {
        const messages = []

        Object.values(data || {}).forEach((value) => {
          if (Array.isArray(value)) {
            value.forEach((item) => {
              if (typeof item === 'string') messages.push(item)
            })
          } else if (typeof value === 'string') {
            messages.push(value)
          }
        })

        return messages.length ? messages : [this.$t('ui.paymentGateways.couldNotPlace')]
      },
      async placeOrder(extra = {}) {
        return new Promise(((resolve, reject) => {
          if (this.checkedProduct.length) {
            const params = []
            this.checkedProduct.forEach(async (obj) => {

              let shippingPrice = 0
              if (parseInt(obj.shipping_type) === 1) {
                shippingPrice = parseInt(obj?.shipping_place?.price)
              } else if (parseInt(obj.shipping_type) === 2) {
                shippingPrice = parseInt(obj?.shipping_place?.pickup_price)
              }
              const currentInventoryPrice = this.currentInventoryPriceCalc(obj.inventory, obj.flash_product)
              const currentPrice = parseInt(obj?.quantity) * currentInventoryPrice
              const currentOffer = currentInventoryPrice * parseInt(obj?.offered)
              this.checkedProductQty += parseInt(obj?.quantity)

              params.push({
                cart: obj.id,
                bundle_offer: currentOffer,
                shipping_price: shippingPrice,
                selling: currentPrice,
              })
            })
            this.loading = true


            this.getUserToken()
              .then(userToken => {
                this.orderAction({
                  lang: this.langCode,
                  payload: {
                    data: this.phpEncryption({
                      user_token: userToken,
                      order_method: this.paymentType,
                      voucher: this.voucherResult?.voucher || '',
                      time_zone: this.timeZone,
                      use_points: this.usePoints ? 1 : 0,
                      points_discount: this.pointsDiscount,
                      use_wallet: this.walletApplied > 0 ? 1 : 0,
                      purchase_order_number: this.purchaseOrderNumber || '',
                      /*
                       * Whether the shopper asked for the store-wide flash
                       * discount. Only the choice travels; the server works
                       * out what it is worth from the live promotion.
                       */
                      apply_flash_discount: this.$store.state.cart.flashDiscountOptIn ? 1 : 0,
                      // Per method: the Stripe authorisation, for one.
                      ...extra
                    })
                  }
                })
                  .then(res => {
                    this.loading = false
                    if (res.status === 200) {

                      // Set before the basket is emptied below, so the watchers
                      // that fire on it know the order is already placed.
                      this.orderFinalised = true

                      setTimeout(async () => {
                        this.sendOrderEmail({
                          payload: {
                            id: res.data.id,
                            time_zone: this.timeZone,
                            user_token: await this.getUserToken()
                          },
                          lang: this.langCode,
                        })
                      }, 100)

                      this.subtractCartProductCount({
                        qty: this.checkedProductQty,
                        status: this.status
                      })

                      if (parseInt(res.data.order_method) === this.orderMethods.CASH_ON_DELIVERY ||
                        parseInt(res.data.order_method) === this.orderMethods.BANK
                      ) {
                        this.orderPlaced('success', res.data.id)
                      }
                      resolve(res)

                    } else if (res.status === 201) {

                      this.refreshBasketAfterRefusal()

                      if (res?.data?.form) {
                        this.orderError = res?.data?.form

                      } else if (res?.data?.product) {
                        const productError = []
                        Object.values(res?.data?.product[0]).forEach((obj) => {
                          obj.forEach(o => {
                            productError.push(o)
                          })
                        })

                        this.orderError = productError

                      } else {
                        /*
                         * A refusal about one field, keyed by that field's
                         * name — Validation::error($token, $message, $field).
                         *
                         * Only 'form' and 'product' were handled, so a
                         * checkout refused for a missing purchase order number
                         * showed the customer nothing at all: no banner, no
                         * reason, and the order simply never appeared.
                         */
                        this.orderError = this.refusalMessages(res?.data)

                        // The page owns the field, so it can highlight the box
                        // as well as show the message.
                        this.$emit('field-error', res?.data || {})
                      }

                      reject(new Error(this.orderError?.[0] || this.$t('ui.paymentGateways.orderRefused')))
                    }
                  })
              })
          } else {
            this.setToastError(this.$t('listingLayout.noProductFound'))
            this.$router.push('cart')
          }
        }))
      },
      ...mapActions('user', ['getUserToken']),
      ...mapActions('common', ['setToastMessage', 'setToastError', 'postRequest']),
      ...mapActions('order', ['orderAction', 'voucherValidity', 'sendOrderEmail', 'paymentDone']),
      ...mapActions('cart', ['getCartByUser', 'subtractCartProductCount', 'emptyCartProduct']),
    },
    async mounted() {
      // First: the wallet rows depend on it, and every request after this one
      // queues ahead of it otherwise.
      this.fetchWallet()
      this.fetchPoints();
      //DEFAULT SELECTED
      if (this.preselect) {
        // Already chosen on an earlier step; a default here would quietly
        // overwrite an answer the shopper has given.
        this.paymentType = Number(this.preselect)

      } else if (this.requireChoice && !this.noPaymentMethod) {
        // Left unanswered on purpose, so the page gating on it has something
        // to gate on. Not when there is nothing to pick from - that would trap
        // the shopper on a page with no way forward.
        this.paymentType = null

      } else if(this.paymentGateway?.default){
        this.paymentType = this.paymentGateway?.default

      } else if (this.cardMethod) {

        this.paymentType = this.cardMethod
      }

      /*
       * A stored choice or the default can name the card gateway that is not
       * the one in use now. Both are "Credit / Debit Card" to the shopper, so
       * it becomes the card row rather than a method with no row to show it.
       */
      const cardish = [this.orderMethods.SUMUP, this.orderMethods.STRIPE]
      if (cardish.includes(parseInt(this.paymentType)) && parseInt(this.paymentType) !== this.cardMethod) {
        this.paymentType = this.cardMethod
      }

      /*
       * A stored answer from an earlier step, or the gateway's own default, can
       * name a method this order cannot use - cash on a posted order. Its row
       * is not on the list, so it could be neither seen nor changed. The
       * cashOffered watcher only catches a later switch, not a start like this.
       */
      if (parseInt(this.paymentType) === this.orderMethods.CASH_ON_DELIVERY &&
        !this.cashOffered) {

        this.paymentType = null
      }

      /*
       * Not synchronously. A watcher is queued, not run, when the value it
       * watches changes - so flipping this on the next line let the flag be
       * true by the time the watcher for the assignment above finally ran, and
       * the default was read as a choice. That is how the collection-store
       * popup came up over an untouched form.
       */
      this.$nextTick(() => {
        this.acceptingChoices = true
      })

      // Where it starts, not only where it goes: the gate has to know before
      // anything has been touched.
      this.$emit('selected-method', this.paymentType)

      if (parseInt(this.paymentGateway?.paypal) === this.status.PUBLIC) {
        const recaptchaScript = document.createElement('script')
        recaptchaScript.setAttribute('src',
          `https://www.paypal.com/sdk/js?client-id=${this.paymentGateway?.paypal_key}&components=buttons,marks&disable-funding=paylater,card`
        )
        recaptchaScript.setAttribute('async', true)
        document.head.appendChild(recaptchaScript)
        recaptchaScript.addEventListener("load", () => {
          this.setLoaded()
          this.paypaLoaded = true
        });
      } else {
        this.paypaLoaded = true
      }

      if (parseInt(this.paymentGateway?.flutterwave) === this.status.PUBLIC) {

        const recaptchaScript = document.createElement('script')
        recaptchaScript.setAttribute('src',
          `https://checkout.flutterwave.com/v3.js`
        )
        recaptchaScript.setAttribute('async', true)
        document.head.appendChild(recaptchaScript)
        recaptchaScript.addEventListener("load", () => {
          this.flutterwaveLoaded = true
        });

      } else {
        this.flutterwaveLoaded = true
      }
    },
  }
</script>
<style>
/*
 * externalConfirm: from 768px up the page shows the order button in the
 * Order Summary, so the panel's copy is hidden. Below that the summary folds
 * into the bottom bar, which only scrolls here - so the button stays here.
 */
@media (min-width: 768px) {
  .tab-content .external-confirm-btn {
    display: none !important;
  }
}
.sumup{
  font-weight: bold;
  margin-top: 5px;
  opacity: 0.8;
}
/* ---------------------------------------------------------------------------
   Payment methods.

   One row per method: mark, name and description, brand. The active row used
   to grow a 2px border, which shifted every other row by a pixel as the
   selection moved; the border stays 1px and an inset ring does the
   highlighting instead.
   --------------------------------------------------------------------------- */
.payment-options {
  display: grid;
  gap: 10px;
}

.payment-option {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  align-items: center;
  gap: 14px;
  margin: 0;
  padding: 14px 16px;
  border: 1px solid #e3e5ef;
  border-radius: 12px;
  background: #fff;
  cursor: pointer;
  transition: border-color .15s ease, background-color .15s ease, box-shadow .15s ease;
}

/*
 * The Stripe card form, under the selected card row. Full width even where
 * the rows sit two to a line; the checkout page restyles it as part of the
 * selected row (checkout.vue).
 */
.stripe-card-panel {
  grid-column: 1 / -1;
  padding: 16px;
  border: 1px solid #e3e5ef;
  border-radius: 12px;
  background: #fff;
  cursor: default;
}

.payment-option:hover {
  border-color: #c9c9ee;
  background: #fbfbff;
}

.payment-option.active {
  border-color: #33319A;
  background: #f6f6ff;
  box-shadow: inset 0 0 0 1px #33319A;
}

/* the label is the whole row, so the focus ring belongs on the row too */
.payment-option:focus-within {
  border-color: #33319A;
  box-shadow: 0 0 0 3px rgba(51, 49, 154, .16);
}

/* informational list: no control, so nothing that suggests one is there */
.payment-note {
  margin: 0 0 12px;
  font-size: 12.5px;
  color: #6b7280;
}

.payment-blocked {
  margin: 0 0 12px;
  padding: 10px 12px;
  border: 1px solid #f3c9c9;
  border-radius: 10px;
  background: #fdf2f2;
  color: #b23b3b;
  font-size: 13px;
  line-height: 1.4;
}

.is-readonly .payment-option {
  grid-template-columns: minmax(0, 1fr) auto;
  cursor: default;
}

.is-readonly .payment-option:hover {
  border-color: #e3e5ef;
  background: #fff;
}

.payment-option.is-disabled {
  background: #f7f8fb;
  border-color: #e9ebf3;
  cursor: not-allowed;
}

.payment-option.is-disabled .payment-title,
.payment-option.is-disabled .payment-sub,
.payment-option.is-disabled .payment-logo {
  color: #a2a7bb;
}

.payment-mark {
  width: 18px;
  height: 18px;
  margin: 0;
  flex: 0 0 auto;
  accent-color: #33319A;
  cursor: inherit;
}

.payment-body {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.payment-title {
  font-size: 14.5px;
  font-weight: 600;
  color: #151438;
  line-height: 1.3;
}

.payment-sub {
  font-size: 12.5px;
  line-height: 1.35;
  color: #6b7280;
}

.payment-logo {
  display: flex;
  align-items: center;
  gap: 6px;
  flex: 0 0 auto;
  color: #9ca3af;
}

.payment-logo i {
  font-size: 22px;
  color: inherit;
}

/* card marks drawn in CSS: three remote logo requests for a decoration that
   is never the thing being read */
.payment-cards {
  gap: 4px;
}

.card-chip {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 34px;
  height: 22px;
  border: 1px solid #e3e5ef;
  border-radius: 4px;
  background: #fff;
  font-size: 8px;
  font-weight: 700;
  letter-spacing: .02em;
}

.card-visa {
  color: #1a1f71;
}

.card-amex {
  color: #1f72cd;
}

.card-mc {
  gap: 0;
}

.card-mc i {
  width: 11px;
  height: 11px;
  border-radius: 50%;
}

.card-mc i:first-child {
  background: #eb001b;
}

.card-mc i:last-child {
  background: #f79e1b;
  margin-left: -4px;
  mix-blend-mode: multiply;
}

/* two columns once the panel is wide enough for the descriptions to fit */
@media (min-width: 992px) {
  .payment-options {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 575px) {
  .payment-options {
    gap: 8px;
  }
  .payment-option {
    gap: 11px;
    padding: 12px;
    border-radius: 10px;
  }
  .payment-note {
    margin-bottom: 10px;
    font-size: 11.5px;
  }
  .payment-title {
    font-size: 13.5px;
  }
  .payment-sub {
    font-size: 11.5px;
  }
  .payment-logo i {
    font-size: 18px;
  }
  .card-chip {
    width: 28px;
    height: 19px;
    font-size: 7px;
  }
  .card-mc i {
    width: 9px;
    height: 9px;
  }
}

</style>
