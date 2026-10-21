<template>

  <div class="guest">

    <nuxt-link
      v-if="step === 'details'"
      to="/login"
      class="auth-back"
    >
      <span aria-hidden="true">&larr;</span> {{ $t('ui.guestCheckout.backToSignIn') }}
    </nuxt-link>

    <button
      v-else
      type="button"
      class="auth-back"
      @click="step = step === 'payment' ? 'verify' : 'details'"
    >
      <span aria-hidden="true">&larr;</span>
      {{ step === 'payment' ? $t('ui.guestCheckout.backToEmail') : $t('ui.guestCheckout.backToDetails') }}
    </button>

    <auth-steps
      v-if="!loading"
      :steps="wizardSteps"
      :current="step"
    />

    <h1 class="auth-h1">
      {{ headings.title }}
    </h1>

    <p
      v-if="step === 'verify'"
      class="auth-lede"
    >
      <i18n path="ui.guestCheckout.codeSent" :tag="false"><template #email><b>{{ form.email }}</b></template></i18n>
    </p>

    <p
      v-else
      class="auth-lede"
    >
      {{ headings.lede }}
    </p>

    <!--
      Guest checkout is a narrow column of panels, so the wait is drawn as
      those panels. A bare spinner here collapsed the column to nothing and
      then snapped the pay button into view, which on a payment step is the
      one place a page should not move under a cursor.
    -->
    <div
      v-if="loading"
      class="guest-loading"
    >
      <skeleton-base :height="18" width="180px" :mb="18" />
      <skeleton-list :rows="2" :avatar-size="56" :lines="2" trailing />
      <skeleton-base class="guest-loading-gap" :height="14" width="60%" :mb="14" />
      <skeleton-base :height="48" width="100%" :radius="10" />
    </div>

    <!-- ═══════════ STEP 3 · PAYMENT ═══════════ -->
    <template v-else-if="step === 'payment'">

      <div class="auth-note auth-note-go guest-note-top">
        <i18n path="ui.guestCheckout.emailConfirmed" :tag="false"><template #email><b>{{ form.email }}</b></template></i18n>
      </div>

      <!-- ─────────── ORDER SUMMARY ─────────── -->
      <div class="guest-cart">

        <div
          v-for="line in productLines"
          :key="line.id"
          class="guest-cart-line"
        >
          <span>{{ line.label }}</span>
          <b><price-format :price="formatPrice(line.price)" /></b>
        </div>

        <div class="guest-cart-line">
          <span>{{ fulfilmentLabel }}</span>
          <b>
            <price-format
              v-if="deliveryPrice > 0"
              :price="formatPrice(deliveryPrice)"
            />
            <template v-else>{{ $t('ui.guestCheckout.free') }}</template>
          </b>
        </div>

        <div
          v-if="taxTotal > 0"
          class="guest-cart-line"
        >
          <span>{{ $t('ui.guestCheckout.vat') }}</span>
          <b><price-format :price="formatPrice(taxTotal)" /></b>
        </div>

        <!--
          One row per Essentials Bundle, named, because this discount is the
          one the shopper can lose without touching it: removing a single
          item of a bundle ends the set price for the rest of it.
        -->
        <div
          v-for="bundle in bundleDiscounts"
          :key="`guest-bundle-${bundle.id}`"
          class="guest-cart-line"
        >
          <span>{{ bundle.title || $t('ui.guestCheckout.bundleSaving') }}</span>
          <b class="guest-cart-off">
            &minus; <price-format :price="formatPrice(bundle.discount)" />
          </b>
        </div>

        <div
          v-if="voucherDiscount > 0"
          class="guest-cart-line"
        >
          <span>{{ $t('ui.guestCheckout.voucherLine', { code: appliedVoucherCode }) }}</span>
          <b class="guest-cart-off">
            &minus; <price-format :price="formatPrice(voucherDiscount)" />
          </b>
        </div>

        <div class="guest-cart-line guest-cart-total">
          <span>{{ $t('ui.guestCheckout.totalToPay') }}</span>
          <b><price-format :price="formatPrice(payableTotal)" /></b>
        </div>

      </div>

      <!-- ─────────── WHERE IT'S GOING ─────────── -->
      <div class="guest-recap">

        <div class="guest-recap-body">

          <div class="guest-recap-head">
            {{ isCollection ? $t('ui.guestCheckout.collectingInStore') : $t('ui.guestCheckout.deliveringTo') }}
          </div>

          <div class="guest-recap-line">{{ form.name }}</div>

          <div
            v-if="!isCollection"
            class="guest-recap-line"
          >
            {{ deliveryAddressLine }}
          </div>

          <div class="guest-recap-line">
            {{ form.email }}<template v-if="form.phone"> &middot; {{ form.phone }}</template>
          </div>

        </div>

        <button
          type="button"
          class="guest-recap-edit"
          @click="step = 'details'"
        >
          {{ $t('ui.guestCheckout.edit') }}
        </button>

      </div>

      <!-- ─────────── VOUCHER ─────────── -->
      <div class="auth-form-group">

        <label
          class="auth-label"
          for="g-voucher"
        >
          {{ $t('ui.guestCheckout.voucherCode') }}
        </label>

        <div class="guest-voucher">

          <input
            id="g-voucher"
            v-model.trim="voucher"
            class="auth-input"
            :class="{ invalid: !!voucherError }"
            :readonly="voucherDiscount > 0"
            :placeholder="$t('ui.guestCheckout.enterCode')"
          >

          <button
            v-if="voucherDiscount > 0"
            type="button"
            class="auth-btn-ghost guest-voucher-btn"
            @click="clearVoucher"
          >
            {{ $t('ui.guestCheckout.remove') }}
          </button>

          <button
            v-else
            type="button"
            class="auth-btn-ghost guest-voucher-btn"
            :disabled="!voucher || checkingVoucher"
            @click="checkVoucher"
          >
            {{ checkingVoucher ? $t('ui.guestCheckout.checking') : $t('ui.guestCheckout.apply') }}
          </button>

        </div>

        <small
          v-if="voucherError"
          class="auth-error"
        >
          {{ voucherError }}
        </small>

      </div>

      <!-- ─────────── HOW TO PAY ─────────── -->
      <h2 class="auth-sub-head guest-sub-head">
        {{ $t('ui.guestCheckout.howToPay') }}
      </h2>

      <payment-gateways
        ref="paymentGateways"
        class="guest-payment"
        :total-price="payableTotal"
        :voucher="voucherResult"
      />

      <div class="auth-btn-stack">
        <button
          type="button"
          class="auth-btn-ghost"
          @click="$router.push('/cart')"
        >
          {{ $t('ui.guestCheckout.backToBasket') }}
        </button>
      </div>

    </template>

    <!-- ═══════════ STEP 2 · CONFIRM EMAIL ═══════════ -->
    <template v-else-if="step === 'verify'">

      <div
        v-if="errors.length"
        class="auth-error-box"
      >
        <div
          v-for="(message, index) in errors"
          :key="index"
        >
          {{ message }}
        </div>
      </div>

      <form @submit.prevent="submitCode">

        <div class="auth-form-group">

          <label class="auth-label">
            {{ $t('ui.guestCheckout.confirmationCode') }}
            <span class="auth-label-required">*</span>
          </label>

          <otp-input
            ref="otp"
            :value="code"
            :is-invalid="codeInvalid"
            @change="onCodeChange"
            @complete="submitCode"
          />

          <small
            v-if="codeInvalid"
            class="auth-error"
          >
            {{ $t('ui.guestCheckout.codeExpired') }}
          </small>

        </div>

        <ajax-button
          class="auth-btn-primary"
          :fetching-data="submitting"
          :loading-text="$t('ui.guestCheckout.confirming')"
          :text="$t('ui.guestCheckout.confirmContinue')"
        />

      </form>

      <div class="auth-btn-stack">
        <button
          type="button"
          class="auth-btn-ghost"
          :disabled="submitting"
          @click="sendCode(true)"
        >
          {{ $t('ui.guestCheckout.sendNewCode') }}
        </button>
        <button
          type="button"
          class="auth-btn-ghost"
          @click="step = 'details'"
        >
          {{ $t('ui.guestCheckout.useDifferentEmail') }}
        </button>
      </div>

      <div class="auth-note auth-note-warn guest-note-spaced">
        {{ $t('ui.guestCheckout.spamNote') }}
      </div>

    </template>

    <!-- ═══════════ STEP 1 · YOUR DETAILS ═══════════ -->
    <template v-else>

      <!-- ─────────── ORDER SUMMARY ─────────── -->
      <div class="guest-cart">

        <div
          v-for="line in productLines"
          :key="line.id"
          class="guest-cart-line"
        >
          <span>{{ line.label }}</span>
          <b><price-format :price="formatPrice(line.price)" /></b>
        </div>

        <div class="guest-cart-line">
          <span>{{ fulfilmentLabel }}</span>
          <b>
            <price-format
              v-if="deliveryPrice > 0"
              :price="formatPrice(deliveryPrice)"
            />
            <template v-else>{{ $t('ui.guestCheckout.free') }}</template>
          </b>
        </div>

        <div
          v-if="taxTotal > 0"
          class="guest-cart-line"
        >
          <span>{{ $t('ui.guestCheckout.vat') }}</span>
          <b><price-format :price="formatPrice(taxTotal)" /></b>
        </div>

        <div class="guest-cart-line guest-cart-total">
          <span>{{ $t('ui.guestCheckout.total') }}</span>
          <b><price-format :price="formatPrice(orderTotal)" /></b>
        </div>

      </div>

      <p class="auth-hint guest-cart-note">
        {{ $t('ui.guestCheckout.voucherOnPayment') }}
      </p>

      <form @submit.prevent="submit">

        <!-- ─────────── CONTACT ─────────── -->
        <div class="auth-form-group">

          <label
            class="auth-label"
            for="g-name"
          >
            {{ $t('ui.guestCheckout.fullName') }}
            <span class="auth-label-required">*</span>
          </label>

          <input
            id="g-name"
            v-model="form.name"
            class="auth-input"
            :class="{ invalid: showError('name') }"
            type="text"
            autocomplete="name"
            :placeholder="$t('ui.guestCheckout.enterName')"
          >

          <small
            v-if="showError('name')"
            class="auth-error"
          >
            {{ fieldErrors.name }}
          </small>

        </div>

        <div class="auth-form-group">

          <label
            class="auth-label"
            for="g-email"
          >
            {{ $t('ui.guestCheckout.emailAddress') }}
            <span class="auth-label-required">*</span>
          </label>

          <input
            id="g-email"
            v-model.trim="form.email"
            class="auth-input"
            :class="{ invalid: showError('email') }"
            type="email"
            inputmode="email"
            autocomplete="email"
            :placeholder="$t('ui.guestCheckout.enterEmail')"
          >

          <small
            v-if="showError('email')"
            class="auth-error"
          >
            {{ fieldErrors.email }}
          </small>

          <small
            v-else
            class="auth-hint"
          >
            {{ $t('ui.guestCheckout.emailHint') }}
          </small>

        </div>

        <!--
          The flag picker doubles as the country control, exactly as it does on
          the signed-in address form: choosing one sets the delivery country
          below and swaps in that country's counties.
        -->
        <div class="auth-form-group">

          <label
            class="auth-label"
            for="g-phone"
          >
            {{ $t('ui.guestCheckout.contactNumber') }}
            <span class="auth-label-required">*</span>
          </label>

          <phone-field
            :value="form.phone"
            :names="countryList"
            :default-country="form.country || 'IE'"
            :is-invalid="showError('phone')"
            @change="onPhoneChange"
            @blur="phoneTouched = true"
          />

          <small
            v-if="showError('phone')"
            class="auth-error"
          >
            {{ fieldErrors.phone }}
          </small>

        </div>

        <!-- ─────────── DELIVERY OR COLLECTION ─────────── -->
        <div class="auth-form-group">
          <label class="auth-label">{{ $t('ui.guestCheckout.howDoYouWantIt') }}</label>
        </div>

        <div class="guest-seg">

          <label class="guest-seg-opt">

            <input
              v-model="deliveryType"
              type="radio"
              name="fulfil"
              value="delivery"
            >

            <span class="guest-seg-face">
              <span class="guest-seg-dot" />
              <span>
                <b>{{ $t('ui.guestCheckout.delivery') }}</b>
                <span>{{ $t('ui.guestCheckout.sentByPost', { suffix: deliverySubLabel }) }}</span>
              </span>
            </span>

          </label>

          <label class="guest-seg-opt">

            <input
              v-model="deliveryType"
              type="radio"
              name="fulfil"
              value="collection"
            >

            <span class="guest-seg-face">
              <span class="guest-seg-dot" />
              <span>
                <b>{{ $t('ui.guestCheckout.collectInStore') }}</b>
                <span>{{ $t('ui.guestCheckout.collectReady', { price: collectionSubLabel }) }}</span>
              </span>
            </span>

          </label>

        </div>

        <!--
          Lines sold only in other countries. The server refuses the order the
          same way; this says so as soon as the country is picked, and lets the
          guest take the line out of this order.
        -->
        <div
          v-if="restrictedLines.length"
          id="guestRestricted"
          class="guest-restricted"
          role="alert"
        >
          <p class="guest-restricted-title">
            {{ isCollection
              ? (restrictedLines.length === 1 ? $t('ui.guestCheckout.restrictedOneCollect') : $t('ui.guestCheckout.restrictedManyCollect'))
              : (restrictedLines.length === 1 ? $t('ui.guestCheckout.restrictedOneSend', { country: activeQuote.country_name }) : $t('ui.guestCheckout.restrictedManySend', { country: activeQuote.country_name })) }}:
          </p>
          <ul>
            <li v-for="line in restrictedLines" :key="line.id">
              <span>{{ line.title }}</span>
              <a href="#" role="button" @click.prevent="removeFromOrder(line.id)">{{ $t('ui.guestCheckout.removeFromOrder') }}</a>
            </li>
          </ul>
        </div>

        <!-- ─────────── DELIVERY ADDRESS ─────────── -->
        <template v-if="!isCollection">

          <div class="guest-row">

            <div class="auth-form-group">

              <label
                class="auth-label"
                for="g-country"
              >
                {{ $t('ui.guestCheckout.country') }}
                <span class="auth-label-required">*</span>
              </label>

              <!--
                Follows the flag on the contact number until it is picked
                here; either way it goes through applyCountry, which clears
                the county, town and postcode under a real change.
              -->
              <select
                id="g-country"
                :value="form.country"
                class="auth-input"
                @change="applyCountry($event.target.value)"
                :class="{ invalid: showError('country') }"
              >
                <option value="">
                  {{ $t('ui.guestCheckout.selectCountry') }}
                </option>
                <option
                  v-for="country in countryOptions"
                  :key="country.code2"
                  :value="country.code2"
                >
                  {{ country.name }}
                </option>
              </select>

              <small
                v-if="showError('country')"
                class="auth-error"
              >
                {{ fieldErrors.country }}
              </small>

              <small
                v-else-if="undeliverableMessage"
                class="auth-error"
                role="alert"
              >
                {{ undeliverableMessage }} {{ $t('ui.guestCheckout.chooseDeliverCountry') }}
              </small>

              <small
                v-else
                class="auth-hint"
              >
                {{ $t('ui.guestCheckout.flagHint') }}
              </small>

            </div>

            <!--
              One control, not two: for Ireland this list already is the 26
              counties, and the name of whatever is picked is what gets stored.
            -->
            <div class="auth-form-group">

              <label
                class="auth-label"
                for="g-state"
              >
                {{ regionLabel }}
                <span
                  v-if="hasRegions"
                  class="auth-label-required"
                >*</span>
              </label>

              <select
                id="g-state"
                v-model="form.state"
                class="auth-input"
                :class="{ invalid: showError('state') }"
                :disabled="!form.country || !hasRegions"
                @change="onRegionSelect"
              >
                <option value="">
                  {{ regionPlaceholder }}
                </option>
                <option
                  v-for="(state, key) in states"
                  :key="key"
                  :value="state.code"
                >
                  {{ state.name }}
                </option>
              </select>

              <small
                v-if="showError('state')"
                class="auth-error"
              >
                {{ fieldErrors.state }}
              </small>

            </div>

          </div>

          <div class="auth-form-group">

            <label
              class="auth-label"
              for="g-address-1"
            >
              {{ $t('ui.guestCheckout.address1') }}
              <span class="auth-label-required">*</span>
            </label>

            <input
              id="g-address-1"
              v-model="form.address_1"
              class="auth-input"
              :class="{ invalid: showError('address_1') }"
              type="text"
              autocomplete="address-line1"
              :placeholder="$t('ui.guestCheckout.enterAddress')"
            >

            <small
              v-if="showError('address_1')"
              class="auth-error"
            >
              {{ fieldErrors.address_1 }}
            </small>

          </div>

          <div class="auth-form-group">

            <label
              class="auth-label"
              for="g-address-2"
            >
              {{ $t('ui.guestCheckout.address2') }}
            </label>

            <input
              id="g-address-2"
              v-model="form.address_2"
              class="auth-input"
              type="text"
              autocomplete="address-line2"
              :placeholder="$t('ui.guestCheckout.address2Placeholder')"
            >

          </div>

          <div class="guest-row">

            <div class="auth-form-group">

              <label
                class="auth-label"
                for="g-city"
              >
                {{ $t('ui.guestCheckout.townCity') }}
                <span class="auth-label-required">*</span>
              </label>

              <!--
                Picked from the county's towns where we hold a list. No list
                of Irish towns is complete, so the last option always opens a
                text field rather than leaving anyone unable to order.
              -->
              <select
                v-if="hasTownOptions"
                id="g-town"
                v-model="townChoice"
                class="auth-input"
                :class="{ invalid: showError('city') }"
                :disabled="!townReady"
              >
                <option value="">
                  {{ townPlaceholder }}
                </option>
                <option
                  v-for="town in townOptions"
                  :key="town"
                  :value="town"
                >
                  {{ town }}
                </option>
                <option :value="townOther">
                  {{ $t('ui.guestCheckout.townOtherOption') }}
                </option>
              </select>

              <input
                v-if="!hasTownOptions || townChoice === townOther"
                id="g-city"
                v-model="form.city"
                class="auth-input"
                :class="{ invalid: showError('city'), 'guest-town-typed': hasTownOptions }"
                type="text"
                autocomplete="address-level2"
                :disabled="!townReady"
                :placeholder="hasTownOptions ? $t('ui.guestCheckout.typeTown') : townPlaceholder"
                @input="townTyped = true"
              >

              <small
                v-if="showError('city')"
                class="auth-error"
              >
                {{ fieldErrors.city }}
              </small>

            </div>

            <div class="auth-form-group">

              <label
                class="auth-label"
                for="g-zip"
              >
                {{ postcodeLabel }}
                <span
                  v-if="postcodeRequired"
                  class="auth-label-required"
                >*</span>
              </label>

              <input
                id="g-zip"
                :value="form.zip"
                class="auth-input guest-upper"
                :class="{ invalid: showError('zip') }"
                type="text"
                autocomplete="postal-code"
                :maxlength="postcodeMaxLength"
                :disabled="!postcodeRequired"
                :placeholder="postcodePlaceholder"
                @input="onPostcodeInput"
              >

              <small
                v-if="showError('zip')"
                class="auth-error"
              >
                {{ fieldErrors.zip }}
              </small>

              <!--
                An Eircode carries its county in the routing key, so a
                disagreement is worth saying - as a warning, because the key
                list moves and a wrong warning must not cost an order.
              -->
              <small
                v-else-if="postcodeWarning"
                class="auth-warning"
              >
                {{ postcodeWarning }}
              </small>

              <small
                v-else-if="postcodeHint"
                class="auth-hint"
              >
                {{ postcodeHint }}
              </small>

            </div>

          </div>

          <div class="auth-form-group">

            <label
              class="auth-label"
              for="g-type"
            >
              {{ $t('ui.guestCheckout.addressType') }}
            </label>

            <select
              id="g-type"
              v-model="form.type"
              class="auth-input"
            >
              <option value="home">
                {{ $t('ui.guestCheckout.home') }}
              </option>
              <option value="office">
                {{ $t('ui.guestCheckout.work') }}
              </option>
            </select>

          </div>

        </template>

        <div
          v-else
          class="auth-note"
        >
          <i18n path="ui.guestCheckout.collectionNote" :tag="false"><template #email><b>{{ form.email || $t('ui.guestCheckout.you') }}</b></template></i18n>
        </div>

        <!-- ─────────── TERMS ─────────── -->
        <div class="auth-form-group guest-terms">

          <label class="auth-checkbox-label guest-check">

            <input
              v-model="form.terms"
              type="checkbox"
            >

            <span class="auth-custom-checkbox" />

            <span>
              <i18n path="ui.guestCheckout.agreeTerms" :tag="false"><template #link><nuxt-link
                to="/page/privacy-policy"
                class="auth-foot-link"
                target="_blank"
              >{{ $t('ui.guestCheckout.privacyNotice') }}</nuxt-link></template></i18n>
              <span class="auth-label-required">*</span>
            </span>

          </label>

          <small
            v-if="hasErrors && !form.terms"
            class="auth-error"
          >
            {{ $t('ui.guestCheckout.tickBox') }}
          </small>

        </div>

        <ajax-button
          class="auth-btn-primary"
          :fetching-data="submitting"
          :loading-text="$t('ui.guestCheckout.savingDetails')"
          :text="emailAlreadyConfirmed ? $t('ui.guestCheckout.continueToPayment') : $t('ui.guestCheckout.continueConfirmEmail')"
        />

      </form>

      <div class="auth-btn-stack">
        <button
          type="button"
          class="auth-btn-ghost"
          @click="$router.push('/cart')"
        >
          {{ $t('ui.guestCheckout.backToBasket') }}
        </button>
      </div>

      <p class="auth-foot">
        {{ $t('ui.guestCheckout.wantHistory') }}
        <nuxt-link
          to="/register"
          class="auth-foot-link"
        >
          {{ $t('ui.guestCheckout.createAccount') }}
        </nuxt-link>
      </p>

    </template>

  </div>

</template>
<script>

  import {mapActions, mapGetters} from 'vuex'
  import {parsePhoneNumberFromString} from 'libphonenumber-js'
  import AjaxButton from '~/components/AjaxButton'
  import AuthSteps from '~/components/AuthSteps'
  import OtpInput from '~/components/OtpInput'
  import PaymentGateways from '~/components/PaymentGateways'
  import PhoneField from '~/components/PhoneField'
  import PriceFormat from '~/components/PriceFormat'
  import util from '~/mixin/util'
  import validation from '~/mixin/validation'
  import productPriceHelper from '~/mixin/productPriceHelper'
  /*
   * Country -> county -> town -> postcode, from the same module the signed-in
   * address form uses, so a guest address is held to exactly the standard an
   * account address is.
   */
  import addressRules, { TOWN_OTHER } from '~/mixin/addressRules'

  /*
   * The guest half of the auth flow, modelled on reference/shop_auth_v4.html.
   *
   * It stands in for /shipping when there is no account: the same three things
   * that page collects - who you are, how you want the order, and where it goes
   * - asked once, in the auth shell, before handing over to /checkout for
   * payment. Signed-in shoppers never see it; they have addresses on file, so
   * they go to /shipping instead.
   *
   * The address half is the signed-in form field for field - same controls,
   * same labels, same messages - because it writes the same user_addresses row
   * and is checked by the same server rules.
   */
  export default {
    layout: 'empty',
    middleware: ['common-middleware'],
    components: {
      AjaxButton,
      AuthSteps,
      OtpInput,
      PaymentGateways,
      PhoneField,
      PriceFormat
    },
    mixins: [util, validation, productPriceHelper, addressRules],
    data() {
      return {
        loading: true,
        submitting: false,
        hasErrors: false,
        /*
         * 'details' collects who and where, 'verify' proves the email and
         * 'payment' takes the money - all three in this same panel, so the
         * basket, the address and the total never leave the screen.
         */
        step: 'details',
        code: '',
        codeInvalid: false,
        errors: [],
        voucher: '',
        voucherError: null,
        voucherResult: null,
        appliedVoucherCode: '',
        checkingVoucher: false,
        /*
         * The address the code was last sent to. Coming back and editing the
         * email drops the confirmation, so the code screen is never showing a
         * code that went somewhere else.
         */
        verifiedEmail: '',
        phoneValid: false,
        phoneTouched: false,
        // the town was typed rather than picked, so the text field stays open
        townTyped: false,
        /*
         * The flag emits on every keystroke; this is how a real country change
         * is told apart from the same country arriving again.
         */
        lastPhoneCountry: '',
        deliveryType: 'delivery',
        checkedProduct: [],
        cartShipping: {},
        /*
         * The server's answer for each way of receiving the order
         * (cart/shipping-quote): delivery for the country and county being
         * typed, collection for the store's collection point. The page never
         * picks a shipping row; products have no rule to read one from.
         */
        quotes: {delivery: null, collection: null},
        quoteSeq: {delivery: 0, collection: 0},
        states: {},
        /*
         * The address row created on the first attempt is reused on any later
         * one, so going back and forth does not leave a trail of near-identical
         * addresses against the guest token.
         */
        savedAddressId: '',
        form: {
          name: '',
          email: '',
          phone: '',
          type: 'home',
          country: '',
          zip: '',
          address_1: '',
          address_2: '',
          city: '',
          state: '',
          county: '',
          terms: false
        }
      }
    },
    computed: {
      wizardSteps() {
        return [
          {key: 'details', label: this.$t('ui.guestCheckout.steps.details')},
          {key: 'verify', label: this.$t('ui.guestCheckout.steps.verify')},
          {key: 'payment', label: this.$t('ui.guestCheckout.steps.payment')}
        ]
      },
      headings() {

        if (this.step === 'verify') {
          return {title: this.$t('ui.guestCheckout.headings.verifyTitle'), lede: ''}
        }

        if (this.step === 'payment') {
          return {
            title: this.$t('ui.guestCheckout.headings.paymentTitle'),
            lede: this.$t('ui.guestCheckout.headings.paymentLede')
          }
        }

        return {
          title: this.$t('ui.guestCheckout.headings.detailsTitle'),
          lede: this.$t('ui.guestCheckout.headings.detailsLede')
        }
      },
      voucherDiscount() {
        return parseFloat(this.voucherResult?.offered || 0)
      },
      /*
       * The Essentials Bundle savings, as the server worked them out for THIS
       * basket. Granted only while the basket still holds a whole bundle, and
       * the browser is not entitled to decide that -- same as CheckoutRight.
       */
      bundleDiscounts() {
        return this.$store.state.cart.bundleDiscount?.bundles || []
      },
      bundleDiscount() {
        return Number(this.$store.state.cart.bundleDiscount?.total) || 0
      },
      /* What the pay button is for; the server recalculates it either way. */
      payableTotal() {
        return Math.max(0, this.orderTotal - this.voucherDiscount - this.bundleDiscount)
      },
      deliveryAddressLine() {

        const county = this.states?.[this.form.state]?.name || ''
        const country = this.countryList?.[this.form.country]?.name || ''

        return [
          this.form.address_1,
          this.form.address_2,
          this.form.city,
          county,
          this.form.zip,
          country
        ].filter(Boolean).join(', ')
      },
      isCollection() {
        return this.deliveryType === 'collection'
      },
      countryOptions() {
        return Object.values(this.countryList || {})
          .filter(c => c && c.code2 && c.name)
          .sort((a, b) => a.name.localeCompare(b.name))
      },
      /* what the shared rules read to work out everything below the country */
      addressCountry() {
        return (this.form.country || '').toUpperCase()
      },
      addressState() {
        return this.form.state || ''
      },
      addressCity() {
        return this.form.city || ''
      },
      townOther() {
        return TOWN_OTHER
      },
      /*
       * The select and the record hold the same town. A town that is not on
       * the list - one restored from a previous order, or one the list never
       * had - shows as "Other" with the text field open on it.
       */
      townChoice: {
        get() {
          if (this.townTyped) {
            return TOWN_OTHER
          }

          if (!this.addressCity) {
            return ''
          }

          return this.townOptions.includes(this.addressCity) ? this.addressCity : TOWN_OTHER
        },
        set(value) {
          const previous = this.addressCity

          this.townTyped = value === TOWN_OTHER
          this.form.city = this.townTyped ? '' : value

          // a different town means a different Eircode - but one typed in
          // before any town was picked is left alone
          if (previous && previous !== this.form.city) {
            this.form.zip = ''
          }
        }
      },
      postcodeWarning() {
        return this.postcodeRegionWarning(this.form.zip, this.addressState)
      },
      /*
       * The messages are the signed-in form's, word for word. A guest who is
       * told something different about the same field would reasonably think
       * a different rule applied.
       */
      fieldErrors() {

        const data = this.form
        const errors = {}
        const name = (data.name || '').trim()

        if (!name) {
          errors.name = this.$t('ui.guestCheckout.errors.nameRequired')
        } else if (name.length < 2) {
          errors.name = this.$t('ui.guestCheckout.errors.nameShort')
        }

        if (!data.email) {
          errors.email = this.$t('ui.guestCheckout.errors.emailRequired')
        } else if (!this.isValidEmail(data.email)) {
          errors.email = this.$t('ui.guestCheckout.errors.emailInvalid')
        }

        if (!data.phone) {
          errors.phone = this.$t('ui.guestCheckout.errors.phoneRequired')
        } else if (!this.phoneValid) {
          errors.phone = this.$t('ui.guestCheckout.errors.phoneInvalid')
        }

        // Nothing is posted on a collection order, so nothing below applies.
        if (this.isCollection) {
          return errors
        }

        if (!data.country) {
          errors.country = this.$t('ui.guestCheckout.errors.countryRequired')
        }

        if (!(data.address_1 || '').trim()) {
          errors.address_1 = this.$t('ui.guestCheckout.errors.address1Required')
        }

        /*
         * From here down each check is only worth making once the level above
         * it has been answered, so a customer is never shown three complaints
         * for the one thing they have not got to yet.
         */
        if (this.hasRegions && !data.state) {
          errors.state = this.$t('ui.guestCheckout.errors.regionRequired', { region: this.regionLabel.toLowerCase() })
        }

        if (this.townReady) {
          if (!(data.city || '').trim()) {
            errors.city = this.$t('ui.guestCheckout.errors.cityRequired')
          } else if (!this.isValidTown(data.city)) {
            errors.city = this.$t('ui.guestCheckout.errors.cityInvalid')
          }
        }

        // countries with no postal system are never asked for one
        if (this.postcodeReady && this.postcodeRequired) {
          const label = this.postcodeLabel.toLowerCase()

          if (!(data.zip || '').trim()) {
            errors.zip = /^[aeiou]/.test(label)
              ? this.$t('ui.guestCheckout.errors.postcodeRequiredAn', { label })
              : this.$t('ui.guestCheckout.errors.postcodeRequiredA', { label })
          } else if (!this.isValidPostcode(data.zip)) {
            errors.zip = this.postcodeExample
              ? this.$t('ui.guestCheckout.errors.postcodeInvalidExample', { label, example: this.postcodeExample })
              : this.$t('ui.guestCheckout.errors.postcodeInvalid', { label })
          }
        }

        return errors
      },
      hasFieldErrors() {
        return Object.keys(this.fieldErrors).length > 0
      },
      /*
       * Only for the address that was actually proved. Editing the email after
       * confirming sends them round the code step again, which is the same
       * rule the server applies before it will accept the order.
       */
      emailAlreadyConfirmed() {
        return !!this.verifiedEmail &&
          this.verifiedEmail.toLowerCase() === (this.form.email || '').toLowerCase()
      },
      productLines() {
        return this.checkedProduct.map(product => ({
          id: product.id,
          label: `${product.quantity} × ${product?.flash_product?.title || ''}`,
          price: this.linePrice(product)
        }))
      },
      subtotal() {
        return this.checkedProduct.reduce(
          (total, product) => total + this.linePrice(product), 0
        )
      },
      /* What postage would cost, whichever option is showing. */
      postagePrice() {
        return this.shippingTotal(false)
      },
      deliveryPrice() {
        return this.shippingTotal(this.isCollection)
      },
      /*
       * Tax already inside the price needs no line of its own; only the
       * exclusive kind is added on, exactly as CheckoutRight adds it.
       */
      taxTotal() {

        return this.checkedProduct.reduce((total, product) => {

          const rule = product?.flash_product?.tax_rules

          if (rule?.id !== 1 || parseInt(product?.flash_product?.excludeVAT) !== 1) {
            return total
          }

          return total + this.priceByType(
            this.unitPrice(product), rule.price || 0, rule.type
          ) * parseInt(product?.quantity || 0)
        }, 0)
      },
      orderTotal() {
        return this.subtotal + this.deliveryPrice + this.taxTotal
      },
      deliverySubLabel() {

        return this.postagePrice > 0
          ? `, ${this.priceLabel(this.postagePrice)}`
          : this.$t('ui.guestCheckout.commaFree')
      },
      fulfilmentLabel() {
        return this.isCollection ? this.$t('ui.guestCheckout.collectionInStore') : this.$t('ui.guestCheckout.deliveryByPost')
      },
      activeQuote() {
        return this.isCollection ? this.quotes.collection : this.quotes.delivery
      },
      /*
       * Nowhere delivers to the address's country. Said at the address, as
       * soon as it is chosen - not only in the summary box or as a toast
       * after pressing continue. Items sold only elsewhere have their own
       * list below, so this is only the whole-country refusal.
       */
      undeliverableMessage() {
        const quote = this.activeQuote

        if (!quote || quote.ok || this.isCollection || this.restrictedLines.length) {
          return null
        }

        return quote.country_name ? quote.message : null
      },
      restrictedLines() {
        const byCart = this.activeQuote?.restricted_carts || {}

        return this.checkedProduct
          .filter(line => byCart[line.id])
          .map(line => ({id: line.id, title: line?.flash_product?.title || ''}))
      },
      collectionSubLabel() {
        const price = this.shippingTotal(true)

        return price > 0 ? this.priceLabel(price) : this.$t('ui.guestCheckout.free')
      },
      ...mapGetters('language', ['langCode']),
      ...mapGetters('cart', {cartProducts: 'checkoutProducts'}),
      ...mapGetters('resource', ['countryList', 'phoneList']),
      ...mapGetters('common', ['location', 'setting', 'currencyIcon', 'currencyPosition'])
    },
    watch: {
      /*
       * Switching between post and collection changes the shipping type sent
       * for every line, and with it the price shown in the summary.
       */
      deliveryType() {
        this.buildCartShipping()
      },
      addressCountry() {
        this.refreshQuote('delivery')
      },
      addressState() {
        this.refreshQuote('delivery')
      }
    },
    methods: {
      /*
       * Errors stay quiet until the form has been submitted once, except the
       * phone, which reports as soon as the field has been left - the same
       * timing the signed-in address form uses.
       */
      showError(field) {

        if (field === 'phone') {
          return !!this.fieldErrors.phone && (this.hasErrors || this.phoneTouched)
        }

        return !!this.fieldErrors[field] && this.hasErrors
      },
      isPhoneValid(value, country) {

        if (!value) {
          return false
        }

        const parsed = parsePhoneNumberFromString(value, country || 'IE')

        return !!(parsed && parsed.isValid())
      },
      onPhoneChange({e164, national, country, valid}) {

        // E.164 is what the API and Twilio want; the display stays separate.
        this.form.phone = e164 || national
        this.phoneValid = valid
        this.phoneTouched = this.phoneTouched || !!national

        /*
         * Picking a flag sets the delivery country and swaps in its counties.
         * Only on a real change, so a country chosen by hand below is not
         * overwritten on the next keystroke.
         */
        if (country && country !== this.lastPhoneCountry) {
          this.lastPhoneCountry = country
          this.applyCountry(country)
        }
      },
      /*
       * The country is the root of the form: changing it invalidates the
       * county below it, the town below that and the postcode below that, so
       * all three are cleared rather than left holding another country's
       * answer.
       */
      applyCountry(code) {

        const changed = (this.form.country || '') !== (code || '')

        this.form.country = code || ''
        this.states = code ? (this.countryList?.[code]?.states || {}) : {}

        if (!this.states[this.form.state]) {
          this.form.state = ''
        }

        if (changed) {
          this.form.city = ''
          this.form.zip = ''
          this.townTyped = false
        }

        this.syncCounty()
      },
      onRegionSelect() {

        /*
         * A town picked from the old county's list is stale, and its
         * Eircode with it. A town typed in before a county was chosen, or an
         * Eircode given ahead of the town, is the customer's own answer and
         * is kept.
         */
        if (!this.townTyped) {
          if (this.form.city) {
            this.form.zip = ''
          }
          this.form.city = ''
        }

        this.syncCounty()
      },
      /* County is a plain name on the record; the select carries the code. */
      syncCounty() {
        this.form.county = this.states?.[this.form.state]?.name || ''
      },
      /* Re-spaced as it is typed, in whatever way the country prints it. */
      onPostcodeInput(event) {

        const value = this.formatPostcode(event.target.value)

        this.form.zip = value
        event.target.value = value
      },
      priceLabel(price) {
        return this.priceFormat(
          this.currencyPosition,
          this.currencyIcon,
          this.formatPrice(price),
          this.setting
        )
      },
      /*
       * Delivery is charged once per order, at the price the server quoted
       * for the address (or the collection point) - the figure the order
       * will take. Nothing until it has answered yes.
       */
      shippingTotal(collection) {
        const quote = collection ? this.quotes.collection : this.quotes.delivery

        return quote?.ok ? parseFloat(quote.price || 0) : 0
      },
      async refreshQuote(kind) {
        const seq = ++this.quoteSeq[kind]

        if (kind === 'delivery' && !this.addressCountry) {
          this.quotes = {...this.quotes, delivery: null}
          return
        }

        try {
          const data = await this.shippingQuote({
            shipping_type: kind === 'collection' ? 2 : 1,
            country: kind === 'delivery' ? this.addressCountry : null,
            state: kind === 'delivery' ? this.addressState : null,
            user_token: await this.getUserToken()
          })

          if (seq !== this.quoteSeq[kind]) return

          this.quotes = {...this.quotes, [kind]: data?.status === 200 ? data.data : null}
        } catch (e) {
          if (seq === this.quoteSeq[kind]) {
            this.quotes = {...this.quotes, [kind]: null}
          }
        }
      },
      // Untick a line that cannot be received this way; it stays in the basket.
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

        await this.cartChangedAction({payload: {checked, user_token: await this.getUserToken()}, lang: this.langCode})

        this.buildCartShipping()

        if (!this.checkedProduct.length) {
          this.$router.push('/cart')
          return
        }

        this.refreshQuote('delivery')
        this.refreshQuote('collection')
      },
      unitPrice(product) {
        return product?.upsell_price && parseFloat(product.upsell_price) > 0
          ? parseFloat(product.upsell_price)
          : this.currentInventoryPriceCalc(
            product?.updated_inventory, product?.flash_product
          )
      },
      linePrice(product) {

        const quantity = parseInt(product?.quantity || 0)
        const unit = this.unitPrice(product)
        const bundle = product?.flash_product?.bundle_deal
        const free = (bundle?.buy <= quantity)
          ? unit * parseInt(bundle?.free || 0)
          : 0

        return (quantity * unit) - free
      },
      buildCartShipping() {

        const shipping = {}

        this.checkedProduct = this.cartProducts.filter(
          product => parseInt(product.selected) === 1
        )

        this.checkedProduct.forEach(product => {
          shipping[product.id] = {
            cart: product.id,
            // The server decides the row; only a collection point is named.
            shipping_place: this.isCollection && this.quotes.collection?.place
              ? {id: this.quotes.collection.place.id}
              : null,
            single_shipping: true,
            shipping_type: this.isCollection ? 2 : 1
          }
        })

        this.cartShipping = shipping
      },
      /*
       * The county select is left empty rather than guessed at: the signed-in
       * form makes it a required choice, and pre-picking Antrim because it
       * sorts first would ship parcels to whatever the shopper failed to notice.
       */
      settingCountry() {

        const code = this.location?.countryCode

        this.form.country = this.countryList?.[code] ? code : 'IE'

        this.states = this.countryList?.[this.form.country]?.states || {}

        this.form.state = this.states[this.location?.region]
          ? this.location.region
          : ''

        this.lastPhoneCountry = this.form.country

        this.syncCounty()
      },
      /*
       * Everything on this page is component state, so a refresh - or coming
       * back from a payment redirect - would otherwise land on an empty form
       * and ask a guest to confirm an address the server already knows they
       * confirmed. The guest record and their saved address are the copies
       * that survive, so the form is filled from those.
       */
      async hydrateFromGuestRecord() {

        try {

          const userToken = await this.getUserToken()

          if (!userToken) {
            return
          }

          const {data} = await this.getRequest({
            params: {user_token: userToken},
            lang: this.langCode,
            api: 'profile'
          })

          if (!data) {
            return
          }

          this.form.name = data.name || this.form.name
          this.form.email = data.email || this.form.email
          this.form.phone = data.phone || this.form.phone

          if (this.form.phone) {
            this.phoneValid = this.isPhoneValid(this.form.phone, this.form.country)
          }

          /*
           * Only the address that was actually proved counts, which is the
           * same comparison the server makes before it will take the order.
           */
          if (data.verified_email && data.email_verified_at &&
            data.verified_email.toLowerCase() === (data.email || '').toLowerCase()) {

            this.verifiedEmail = data.verified_email
          }

          await this.hydrateAddress(userToken)

        } catch (e) {
          // A guest with nothing on file simply types it in; not an error.
        }
      },
      async hydrateAddress(userToken) {

        const data = await this.userAddressAll({user_token: userToken})

        const address = data?.data?.data?.[0]

        if (!address) {
          return
        }

        this.savedAddressId = address.id
        this.form.type = address.type || 'home'
        this.form.address_1 = address.address_1 || ''
        this.form.address_2 = address.address_2 || ''

        /*
         * Down the hierarchy, not up: the country has to be applied before
         * the town and postcode are filled, because applying it clears
         * whatever was under it.
         */
        if (address.country) {
          this.applyCountry(address.country)
        }

        if (address.state && this.states[address.state]) {
          this.form.state = address.state
          this.syncCounty()
        }

        this.form.city = address.city || ''
        this.form.zip = address.zip || ''
      },
      /* Delivery needs an address row the order can be attached to. */
      async saveAddress(userToken) {

        const parts = (this.form.name || '').trim().split(/\s+/).filter(Boolean)
        const firstName = parts.shift() || ''
        const lastName = parts.join(' ')

        const payload = {
          id: this.savedAddressId,
          name: [firstName, lastName].filter(Boolean).join(' '),
          first_name: firstName,
          last_name: lastName,
          email: this.form.email,
          phone: this.form.phone,
          type: this.form.type || 'home',
          country: this.form.country,
          state: this.form.state,
          county: this.form.county,
          zip: this.form.zip,
          address_1: this.form.address_1,
          address_2: this.form.address_2,
          city: this.form.city,
          default: 1,
          user_token: userToken
        }

        const data = await this.userAddressAction(payload)

        if (data?.status !== 200) {
          this.setToastError(
            data?.data?.form?.join(', ') || this.$t('ui.guestCheckout.toast.addressSaveFailed')
          )
          return null
        }

        this.savedAddressId = data.data?.id

        return data.data
      },
      async submit() {

        this.hasErrors = true
        this.phoneTouched = true

        if (this.hasFieldErrors || !this.form.terms) {

          this.setToastError(this.$t('ui.guestCheckout.toast.correctFields'))

          this.$nextTick(() => {

            // The phone control is a component, so it flags itself, not an input.
            const el = this.$el.querySelector('.auth-input.invalid, .pf-control.invalid')

            if (el) {
              el.scrollIntoView({behavior: 'smooth', block: 'center'})
              el.focus({preventScroll: true})
            }
          })

          return
        }

        if (!this.checkedProduct.length) {
          this.setToastError(this.$t('shipping.noProductSelected'))
          this.$router.push('/cart')
          return
        }

        // A line that cannot go there, or nowhere to deliver to.
        if (this.activeQuote && !this.activeQuote.ok) {
          this.setToastError(this.activeQuote.message)

          const el = this.$el.querySelector('#guestRestricted')
          if (el) el.scrollIntoView({behavior: 'smooth', block: 'center'})

          return
        }

        try {

          this.submitting = true

          const userToken = await this.getUserToken()

          let address = null

          if (!this.isCollection) {

            address = await this.saveAddress(userToken)

            if (!address) {
              this.submitting = false
              return
            }
          }

          const contact = {
            name: this.form.name,
            email: this.form.email,
            phone: this.form.phone
          }

          this.$store.commit(
            'cart/SET_SELECTED_ADDRESS', this.isCollection ? null : address
          )
          this.$store.commit(
            'cart/SET_COLLECTION_CONTACT', this.isCollection ? {...contact} : null
          )

          const data = await this.updateCartShipping({
            cart: this.cartShipping,
            shipping_type: this.isCollection ? 2 : 1,
            pickup_place_id: this.isCollection ? (this.quotes.collection?.place?.id || null) : null,
            user_token: userToken,
            selected_address: this.isCollection ? null : address?.id,
            contact_name: this.isCollection ? contact.name : null,
            contact_email: this.isCollection ? contact.email : null,
            contact_phone: this.isCollection ? contact.phone : null
          })

          this.submitting = false

          if (data?.status === 200) {

            /*
             * Details are saved. The email is what the receipt, the tracking
             * link and the account credentials go to, so it is proved before
             * payment - and if this address was already proved, that stands
             * and there is nothing to ask again.
             */
            if (this.emailAlreadyConfirmed) {
              this.goToPayment()
              return
            }

            await this.sendCode()
            return
          }

          if (data?.data?.form) {
            this.setToastError(data.data.form[0])
            return
          }

          if (data?.data?.product) {

            const first = Object.values(data.data.product[0] || {})[0]

            this.setToastError(Array.isArray(first) ? first[0] : first)
          }

        } catch (e) {
          this.submitting = false
          return this.$nuxt.error(e)
        }
      },
      /*
       * Payment is a step in this panel, not another page. The basket, the
       * address and the confirmed email stay in view, and the shopper never
       * leaves the shell they started guest checkout in.
       */
      goToPayment() {

        this.step = 'payment'

        this.$nextTick(() => window.scrollTo({top: 0, behavior: 'smooth'}))
      },
      async checkVoucher() {

        if (!this.voucher || this.checkingVoucher) {
          return
        }

        this.voucherError = null
        this.checkingVoucher = true

        try {

          const res = await this.voucherValidity({
            payload: {
              voucher: this.voucher,
              user_token: await this.getUserToken(),
              price: this.orderTotal
            },
            lang: this.langCode
          })

          if (res?.status === 201) {
            this.voucherError = res.data?.form?.[0] || this.$t('ui.guestCheckout.toast.voucherInvalid')
            return
          }

          this.voucherResult = res.data
          this.appliedVoucherCode = res.data?.code || this.voucher

          this.setToastMessage(this.$t('ui.guestCheckout.toast.voucherApplied'))

        } catch (e) {
          this.voucherError = this.$t('ui.guestCheckout.toast.voucherCheckFailed')
        } finally {
          this.checkingVoucher = false
        }
      },
      clearVoucher() {
        this.voucher = ''
        this.voucherError = null
        this.voucherResult = null
        this.appliedVoucherCode = ''
      },
      /* ── step 2: confirm the email ── */
      async sendCode(resend = false) {

        this.errors = []
        this.codeInvalid = false
        this.code = ''
        this.submitting = true

        try {

          const data = await this.guestVerifyEmail({
            params: {
              user_token: await this.getUserToken(),
              email: this.form.email,
              name: this.form.name,
              phone: this.form.phone
            },
            lang: this.langCode
          })

          if (data?.status === 200) {

            this.step = 'verify'

            if (resend) {
              this.setToastMessage(this.$t('ui.guestCheckout.toast.newCodeSent'))
            }

            this.$nextTick(() => {
              if (this.$refs.otp && this.$refs.otp.focusFirst) {
                this.$refs.otp.focusFirst()
              }
            })

            return
          }

          /*
           * An address or number that already has an account is refused here,
           * so the message belongs beside the details, not on a code screen
           * the guest can do nothing about.
           */
          this.step = 'details'
          this.errors = data?.data?.form || [data?.message]
          this.setToastError(this.errors[0])

        } catch (e) {
          return this.$nuxt.error(e)
        } finally {
          this.submitting = false
        }
      },
      onCodeChange(code) {

        this.code = code

        if (code.length < 6) {
          this.codeInvalid = false
          this.errors = []
        }
      },
      async submitCode() {

        if (this.code.length !== 6 || this.submitting) {
          return
        }

        this.errors = []
        this.submitting = true

        try {

          const data = await this.guestVerifyEmailCheck({
            params: {
              user_token: await this.getUserToken(),
              email: this.form.email,
              code: this.code
            },
            lang: this.langCode
          })

          if (data?.status === 200) {
            this.verifiedEmail = this.form.email
            this.goToPayment()
            return
          }

          this.codeInvalid = true
          this.code = ''

          this.$nextTick(() => {
            if (this.$refs.otp && this.$refs.otp.focusFirst) {
              this.$refs.otp.focusFirst()
            }
          })

        } catch (e) {
          return this.$nuxt.error(e)
        } finally {
          this.submitting = false
        }
      },
      ...mapActions('resource', ['setCountryList', 'setPhoneList']),
      ...mapActions('user', [
        'userAddressAction', 'userAddressAll', 'getUserToken',
        'guestVerifyEmail', 'guestVerifyEmailCheck'
      ]),
      ...mapActions('cart', ['getCartByUser', 'updateCartShipping', 'shippingQuote']),
      ...mapActions('cart', {cartChangedAction: 'cartChanged'}),
      ...mapActions('order', ['voucherValidity']),
      ...mapActions('common', ['setToastError', 'setToastMessage', 'getRequest'])
    },
    /*
     * Guest checkout can be switched off in the admin, and a signed-in shopper
     * has no business here - both are sent to the flow that does apply to them.
     */
    async asyncData({store, $auth, redirect, error}) {

      if ($auth.loggedIn) {
        redirect('/shipping')
        return
      }

      if (!store.state?.common?.setting?.guest_checkout) {
        redirect('/login')
        return
      }

      try {

        // Payment is a step on this page now, so the gateways have to be here.
        if (!store.state.common.paymentGateway) {

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

      try {

        if (!this.countryList || !this.phoneList) {

          const {data} = await this.getRequest({
            params: null,
            lang: this.langCode,
            api: 'countriesPhones'
          })

          this.setCountryList(data?.countries)
          this.setPhoneList(data?.phones)
        }

        this.settingCountry()

        await this.hydrateFromGuestRecord()

        if (!this.cartProducts.length) {
          await this.getCartByUser({
            lang: this.langCode,
            params: {
              user_token: await this.getUserToken()
            }
          })
        }

        this.buildCartShipping()

        this.refreshQuote('delivery')
        this.refreshQuote('collection')

        /*
         * What the Essentials Bundles in this basket are worth.
         *
         * Checkout applies this discount from `carts.bundle_deal_id` whoever
         * is buying, guests included -- so without this call the page showed
         * a "Total to pay" HIGHER than the amount actually taken, with no row
         * explaining the difference. The cart and checkout pages have always
         * asked for it; this one never did.
         */
        this.$store.dispatch('cart/loadBundleDiscount')

        this.loading = false

        // Nothing to check out; the basket is the only useful place to be.
        if (!this.checkedProduct.length) {
          this.$router.push('/cart')
        }

      } catch (e) {
        this.loading = false
        return this.$nuxt.error(e)
      }
    }
  }
</script>
<style scoped>

.guest-loading {
    display: block;
    padding: 8px 0 24px;
}

.guest-loading-gap {
    margin-top: 22px;
}

/* ── order summary ── */
.guest-cart {
    border: 1px solid var(--auth-line);
    background: var(--auth-wash);
    border-radius: var(--auth-radius);
    padding: 16px;
    margin-bottom: 8px;
}

.guest-cart-note {
    margin-bottom: 24px;
}

.guest-cart-line {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 5px 0;
    font-size: 13.5px;
    color: var(--auth-ink-soft);
}

.guest-cart-line b {
    color: var(--auth-ink);
    white-space: nowrap;
}

.guest-cart-total {
    border-top: 1px solid var(--auth-line-strong);
    margin-top: 9px;
    padding-top: 10px;
    font-size: 16px;
    font-weight: 800;
}

/* ── lines that cannot be received this way (sell only in) ── */
.guest-restricted {
  margin: 0 0 16px;
  padding: 12px 14px;
  border: 1px solid #f2c4c4;
  border-radius: 10px;
  background: #fdf1f1;
  color: #a12321;
  font-size: 13.5px;
  line-height: 1.5;
}

.guest-restricted-title {
  margin: 0 0 6px;
  font-weight: 600;
}

.guest-restricted ul {
  margin: 0;
  padding: 0;
  list-style: none;
}

.guest-restricted li {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: 4px 12px;
  padding: 5px 0;
  color: #3b1a1a;
}

.guest-restricted a {
  color: #a12321;
  font-weight: 600;
  text-decoration: underline;
  white-space: nowrap;
}

/* ── delivery / collection ── */
.guest-seg {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 20px;
}

.guest-seg-opt {
    display: block;
    position: relative;
    cursor: pointer;
    margin: 0;
}

.guest-seg-opt input {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}

.guest-seg-face {
    display: flex;
    align-items: flex-start;
    gap: 11px;
    height: 100%;
    padding: 14px 14px 13px;
    background: #FFFFFF;
    border: 1px solid var(--auth-line-strong);
    border-radius: var(--auth-radius);
    transition: border-color .15s, box-shadow .15s, background .15s;
}

.guest-seg-opt input:checked + .guest-seg-face {
    border-color: var(--auth-brand);
    background: var(--auth-brand-50);
    box-shadow: 0 0 0 3px rgba(45, 45, 160, .09);
}

.guest-seg-dot {
    position: relative;
    flex: 0 0 auto;
    width: 18px;
    height: 18px;
    margin-top: 2px;
    border: 2px solid var(--auth-line-strong);
    border-radius: 50%;
}

.guest-seg-opt input:checked + .guest-seg-face .guest-seg-dot {
    border-color: var(--auth-go);
}

.guest-seg-opt input:checked + .guest-seg-face .guest-seg-dot::after {
    content: '';
    position: absolute;
    inset: 3px;
    border-radius: 50%;
    background: var(--auth-go);
}

.guest-seg-face b {
    display: block;
    font-size: 14.5px;
    font-weight: 700;
    color: var(--auth-ink);
}

.guest-seg-face span span {
    display: block;
    margin-top: 2px;
    font-size: 12.5px;
    line-height: 1.35;
    color: var(--auth-muted);
}

/* ── address block ── */
.guest-row {
    display: flex;
    align-items: flex-start;
    gap: 14px;
}

.guest-row > * {
    flex: 1;
    min-width: 0;
    width: 100%;
}

.guest-upper {
    text-transform: uppercase;
}

/* the text field that opens under the town select on "Other" */
.guest-town-typed {
    margin-top: 8px;
}

/* The county select is disabled for a country that has no regions. */
.guest select.auth-input:disabled {
    background: var(--auth-wash);
    color: var(--auth-muted);
    cursor: not-allowed;
}

.guest-terms {
    margin-top: 4px;
    margin-bottom: 22px;
}

.guest-note-spaced {
    margin-top: 24px;
    margin-bottom: 0;
}

.guest-note-top {
    margin-bottom: 20px;
}

.guest-cart-off {
    color: var(--auth-go);
}

/* ── what was agreed on the earlier steps, kept in view while paying ── */
.guest-recap {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    padding: 14px 16px;
    margin-bottom: 22px;
    border: 1px solid var(--auth-line-strong);
    border-radius: var(--auth-radius);
    background: #FFFFFF;
}

.guest-recap-body {
    flex: 1;
    min-width: 0;
}

.guest-recap-head {
    font-size: 12px;
    font-weight: 800;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: var(--auth-muted);
    margin-bottom: 6px;
}

.guest-recap-line {
    font-size: 13.5px;
    line-height: 1.5;
    color: var(--auth-ink-soft);
    word-break: break-word;
}

.guest-recap-line:first-of-type {
    font-weight: 700;
    color: var(--auth-ink);
}

.guest-recap-edit {
    flex: 0 0 auto;
    height: auto;
    padding: 0;
    background: none;
    border: 0;
    font: inherit;
    font-size: 13px;
    font-weight: 700;
    color: var(--auth-brand);
    cursor: pointer;
}

.guest-recap-edit:hover {
    text-decoration: underline;
}

/* ── voucher ── */
.guest-voucher {
    display: flex;
    align-items: stretch;
    gap: 10px;
}

.guest-voucher .auth-input {
    flex: 1;
    min-width: 0;
}

.guest-voucher-btn {
    flex: 0 0 auto;
    width: auto;
    padding-left: 22px;
    padding-right: 22px;
    /* "Checking..." is wider than "Apply" and must not wrap the button. */
    white-space: nowrap;
}

.guest-sub-head {
    font-size: 12px;
    font-weight: 800;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--auth-muted);
    margin: 28px 0 14px;
    padding-top: 18px;
    border-top: 1px solid var(--auth-line);
}

/*
 * The payment panel is shop furniture dropped into the auth column. Its own
 * rules come from the global stylesheet; these only pull it back to this
 * column's width and rhythm. Everything here is scoped to .guest-payment, so
 * /checkout and /cart keep the panel exactly as it was.
 */
.guest-payment {
    display: block;
}

/*
 * The shop goes two-up on payment methods at a 992px VIEWPORT. That reads the
 * wrong box here: this column is 560px at its widest however big the window
 * is, so on a desktop the two columns were ~265px each and "Credit / Debit
 * Card" broke across four lines. One method per row, always.
 */
.guest-payment >>> .payment-options {
    grid-template-columns: minmax(0, 1fr);
    margin-top: 0;
    gap: 10px;
}

.guest-payment >>> .payment-option {
    padding: 14px 16px;
    border-color: var(--auth-line-strong);
    border-radius: var(--auth-radius);
    /* Descriptions are one line at this width, so the mark reads better
     * against the title than floating in the middle of a tall card. */
    align-items: center;
}

.guest-payment >>> .payment-option:hover {
    border-color: var(--auth-brand);
    background: #FBFBFF;
}

.guest-payment >>> .payment-option.active {
    border-color: var(--auth-brand);
    background: var(--auth-brand-50);
    box-shadow: inset 0 0 0 1px var(--auth-brand);
}

.guest-payment >>> .payment-option:focus-within {
    border-color: var(--auth-brand);
    box-shadow: 0 0 0 3px rgba(45, 45, 160, .16);
}

.guest-payment >>> .payment-mark {
    accent-color: var(--auth-brand);
}

.guest-payment >>> .payment-title {
    font-size: 14.5px;
    color: var(--auth-ink);
}

.guest-payment >>> .payment-sub {
    font-size: 12.5px;
    color: var(--auth-muted);
}

.guest-payment >>> .payment-note,
.guest-payment >>> .payment-blocked {
    font-size: 12.5px;
}

/* The pay button is the primary action of this screen, so it takes the shape
 * every other primary action in the shell has rather than the shop's. */
.guest-payment >>> .tab-content {
    margin: 22px 0 0;
}

.guest-payment >>> .ajax-btn.primary-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: auto;
    /* base.styl sets line-height:48px on every button, which would stack on
     * top of the padding and make this pill ~78px tall. */
    line-height: 1.5;
    padding: 15px 20px;
    background-color: var(--auth-go);
    color: #FFFFFF;
    font-size: 15.5px;
    font-weight: 700;
    border: none;
    border-radius: 999px;
    box-shadow: none;
    transition: transform .12s ease, background .15s;
}

.guest-payment >>> .ajax-btn.primary-btn:hover {
    background-color: var(--auth-go-700);
    transform: translateY(-1px);
}

/* base.styl and website-style.css both restyle `button:active`, which beats a
 * single class and strips the fill mid-press. Pin it back. */
.guest-payment >>> .ajax-btn.primary-btn:active,
.guest-payment >>> .ajax-btn.primary-btn:focus {
    background-color: var(--auth-go-700);
    box-shadow: none;
    transform: translateY(0);
}

.guest-payment >>> .ajax-btn.primary-btn[disabled] {
    opacity: .55;
    cursor: not-allowed;
    transform: none;
}

/* The error list sits above the form, not inside a shop grid. */
.guest-payment >>> .f-13.error {
    margin: 0 0 14px;
    font-size: 13px;
    line-height: 1.5;
    color: var(--auth-danger);
}

@media (max-width: 640px) {

    .guest-voucher {
        display: block;
    }

    .guest-voucher-btn {
        width: 100%;
        margin-top: 10px;
    }

    .guest-cart,
    .guest-recap {
        padding: 14px;
    }

    .guest-cart-line {
        font-size: 13px;
    }

    .guest-cart-total {
        font-size: 15px;
    }

    /* Edit drops under the address rather than squeezing it to a word a line. */
    .guest-recap {
        display: block;
    }

    .guest-recap-edit {
        margin-top: 10px;
    }

    .guest-payment >>> .payment-option {
        padding: 12px;
        gap: 11px;
    }

    .guest-payment >>> .ajax-btn.primary-btn {
        font-size: 15px;
    }
}

/* The label runs to two lines at this width, so the box sits with the first. */
.guest-check {
    align-items: flex-start;
    font-size: 13.5px;
    line-height: 1.5;
}

.guest-check .auth-custom-checkbox {
    margin-top: 2px;
}

@media (max-width: 640px) {

    .guest-seg,
    .guest-row {
        display: block;
    }

    .guest-seg-opt + .guest-seg-opt,
    .guest-row > * + * {
        margin-top: 12px;
    }
}

</style>
