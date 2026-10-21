<template>

  <div class="auth-wizard">

    <!-- ERRORS (shared across steps) -->
    <div
      v-if="errors.length"
      class="auth-error-box"
    >
      <div
        v-for="(value, index) in errors"
        :key="index"
      >
        {{ value }}
      </div>
    </div>

    <!-- ═══════════ STEP 1 · ACCOUNT TYPE ═══════════ -->
    <section v-if="step === 'type'">

      <nuxt-link
        to="/login"
        class="auth-back"
      >
        <span aria-hidden="true">&larr;</span> {{ $t('ui.registerPage.backToSignIn') }}
      </nuxt-link>

      <auth-steps
        :steps="wizardSteps"
        current="type"
      />

      <h1 class="auth-h1">{{ $t('ui.registerPage.typeTitle') }}</h1>
      <p class="auth-lede">
        {{ $t('ui.registerPage.typeLede') }}
      </p>

      <account-type-cards
        :selected="chosenType"
        @select="chooseType"
      />

      <!-- Hidden on shorter windows: "← Back to sign in" sits directly above
           the heading, so this repeat is what tips the step into scrolling. -->
      <p class="auth-foot auth-foot-duplicate">
        {{ $t('ui.registerPage.alreadyRegistered') }}
        <nuxt-link
          to="/login"
          class="auth-foot-link"
        >
          {{ $t('ui.registerPage.signIn') }}
        </nuxt-link>
      </p>

    </section>

    <!-- ═══════════ STEP 2 · DETAILS ═══════════ -->
    <section v-else-if="step === 'details'">

      <button
        type="button"
        class="auth-back"
        @click="goToStep('type')"
      >
        <span aria-hidden="true">&larr;</span> {{ $t('ui.registerPage.changeAccountType') }}
      </button>

      <auth-steps
        :steps="wizardSteps"
        current="details"
      />

      <h1 class="auth-h1">{{ detailsTitle }}</h1>
      <p class="auth-lede">{{ detailsLede }}</p>

      <div class="auth-chosen">

        <span
          class="auth-chosen-ico"
          aria-hidden="true"
        >
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
        </span>

        <span class="auth-chosen-text">
          <b>{{ chosenLabel }}</b>
          <small>{{ chosenFoot }}</small>
        </span>

        <button
          type="button"
          class="auth-chosen-change"
          @click="goToStep('type')"
        >
          {{ $t('ui.registerPage.change') }}
        </button>

      </div>

      <form @submit.prevent="submitDetails">

        <!-- ── who you are ── -->
        <div class="auth-form-group">
          <label class="auth-label" for="su-name">
            {{ $t('ui.registerPage.fullName') }} <span class="auth-label-required">*</span>
          </label>
          <input
            id="su-name"
            v-model.trim="form.name"
            class="auth-input"
            :class="{ invalid: fieldBad('name') }"
            autocomplete="name"
            :placeholder="$t('ui.registerPage.fullNamePlaceholder')"
          >
          <small v-if="fieldBad('name')" class="auth-error">
            {{ $t('ui.registerPage.fullNameRequired') }}
          </small>
        </div>

        <!-- ── business only ── -->
        <template v-if="accountType === 'business'">

          <h2 class="auth-sub-head">{{ $t('ui.registerPage.business.heading') }}</h2>

          <div class="auth-row">

            <div class="auth-form-group">
              <label class="auth-label" for="bz-company">
                {{ $t('ui.registerPage.business.companyName') }} <span class="auth-label-required">*</span>
              </label>
              <input
                id="bz-company"
                v-model.trim="form.company_name"
                class="auth-input"
                :class="{ invalid: fieldBad('company_name') }"
                autocomplete="organization"
                :placeholder="$t('ui.registerPage.business.companyNamePlaceholder')"
              >
              <small v-if="fieldBad('company_name')" class="auth-error">
                {{ $t('ui.registerPage.business.companyNameRequired') }}
              </small>
            </div>

            <div class="auth-form-group">
              <label class="auth-label" for="bz-sector">
                {{ $t('ui.registerPage.business.sector') }} <span class="auth-label-required">*</span>
              </label>
              <select
                id="bz-sector"
                v-model="form.sector"
                class="auth-input"
                :class="{ invalid: fieldBad('sector') }"
              >
                <option value="">{{ $t('ui.registerPage.chooseOne') }}</option>
                <option v-for="(i, n) in sectors" :key="i" :value="i">{{ optionLabel('sectors', n, i) }}</option>
              </select>
              <small v-if="fieldBad('sector')" class="auth-error">
                {{ $t('ui.registerPage.pickClosest') }}
              </small>
            </div>

          </div>

          <div class="auth-row">
            <div class="auth-form-group">
              <label class="auth-label" for="bz-vat">{{ $t('ui.registerPage.vatNumber') }}</label>
              <input
                id="bz-vat"
                v-model.trim="form.vat_number"
                class="auth-input"
                :class="{ invalid: fieldBad('vat_number') }"
                placeholder="IE1234567FA"
              >
              <small v-if="fieldBad('vat_number')" class="auth-error">
                {{ $t('ui.registerPage.business.vatFormat') }}
              </small>
            </div>
            <div class="auth-form-group">
              <label class="auth-label" for="bz-cro">{{ $t('ui.registerPage.croNumber') }}</label>
              <input
                id="bz-cro"
                v-model.trim="form.cro_number"
                class="auth-input"
                placeholder="123456"
              >
            </div>
          </div>

          <div class="auth-form-group">
            <label class="auth-label" for="bz-billing">
              {{ $t('ui.registerPage.business.billingAddress') }} <span class="auth-label-required">*</span>
            </label>
            <textarea
              id="bz-billing"
              v-model.trim="form.billing_address"
              class="auth-input auth-textarea"
              :class="{ invalid: fieldBad('billing_address') }"
              :placeholder="$t('ui.registerPage.addressPlaceholder')"
            />
            <small v-if="fieldBad('billing_address')" class="auth-error">
              {{ $t('ui.registerPage.business.billingAddressRequired') }}
            </small>
          </div>

          <label class="auth-checkbox-label">
            <input v-model="form.requires_po" type="checkbox">
            <span class="auth-custom-checkbox" />
            <span>{{ $t('ui.registerPage.business.requiresPo') }}</span>
          </label>

          <label class="auth-checkbox-label">
            <input v-model="form.credit_application" type="checkbox">
            <span class="auth-custom-checkbox" />
            <span>
              {{ $t('ui.registerPage.business.creditApplication') }}
              <small class="auth-hint">
                {{ $t('ui.registerPage.business.creditHint') }}
              </small>
            </span>
          </label>

        </template>

        <!-- ── trade only ── -->
        <template v-else-if="accountType === 'trade'">

          <h2 class="auth-sub-head">{{ $t('ui.registerPage.trade.heading') }}</h2>

          <div class="auth-note auth-note-warn">
            {{ $t('ui.registerPage.trade.reviewNote') }}
          </div>

          <div class="auth-row">

            <div class="auth-form-group">
              <label class="auth-label" for="td-trading">
                {{ $t('ui.registerPage.trade.tradingName') }} <span class="auth-label-required">*</span>
              </label>
              <input
                id="td-trading"
                v-model.trim="form.company_name"
                class="auth-input"
                :class="{ invalid: fieldBad('company_name') }"
                autocomplete="organization"
                :placeholder="$t('ui.registerPage.trade.tradingNamePlaceholder')"
              >
              <small v-if="fieldBad('company_name')" class="auth-error">
                {{ $t('ui.registerPage.trade.tradingNameRequired') }}
              </small>
            </div>

            <div class="auth-form-group">
              <label class="auth-label" for="td-trade">
                {{ $t('ui.registerPage.trade.trade') }} <span class="auth-label-required">*</span>
              </label>
              <select
                id="td-trade"
                v-model="form.trade"
                class="auth-input"
                :class="{ invalid: fieldBad('trade') }"
              >
                <option value="">{{ $t('ui.registerPage.chooseOne') }}</option>
                <option v-for="(i, n) in trades" :key="i" :value="i">{{ optionLabel('trades', n, i) }}</option>
              </select>
              <small v-if="fieldBad('trade')" class="auth-error">
                {{ $t('ui.registerPage.pickClosest') }}
              </small>
            </div>

          </div>

          <div class="auth-row">
            <div class="auth-form-group">
              <label class="auth-label" for="td-vat">
                {{ $t('ui.registerPage.vatNumber') }} <span class="auth-label-required">*</span>
              </label>
              <input
                id="td-vat"
                v-model.trim="form.vat_number"
                class="auth-input"
                :class="{ invalid: fieldBad('vat_number') }"
                placeholder="IE1234567FA"
              >
              <small v-if="fieldBad('vat_number')" class="auth-error">
                {{ $t('ui.registerPage.trade.vatRequired') }}
              </small>
            </div>
            <div class="auth-form-group">
              <label class="auth-label" for="td-cro">{{ $t('ui.registerPage.croNumber') }}</label>
              <input
                id="td-cro"
                v-model.trim="form.cro_number"
                class="auth-input"
                placeholder="123456"
              >
            </div>
          </div>

          <div class="auth-form-group">
            <label class="auth-label" for="td-address">
              {{ $t('ui.registerPage.trade.businessAddress') }} <span class="auth-label-required">*</span>
            </label>
            <textarea
              id="td-address"
              v-model.trim="form.billing_address"
              class="auth-input auth-textarea"
              :class="{ invalid: fieldBad('billing_address') }"
              :placeholder="$t('ui.registerPage.addressPlaceholder')"
            />
            <small v-if="fieldBad('billing_address')" class="auth-error">
              {{ $t('ui.registerPage.trade.businessAddressRequired') }}
            </small>
          </div>

          <div class="auth-row">
            <div class="auth-form-group">
              <label class="auth-label" for="td-spend">
                {{ $t('ui.registerPage.trade.expectedSpend') }} <span class="auth-label-required">*</span>
              </label>
              <select
                id="td-spend"
                v-model="form.expected_spend"
                class="auth-input"
                :class="{ invalid: fieldBad('expected_spend') }"
              >
                <option value="">{{ $t('ui.registerPage.chooseOne') }}</option>
                <option v-for="(i, n) in spends" :key="i" :value="i">{{ optionLabel('spends', n, i) }}</option>
              </select>
              <small v-if="fieldBad('expected_spend')" class="auth-error">
                {{ $t('ui.registerPage.trade.expectedSpendRequired') }}
              </small>
            </div>
            <div class="auth-form-group">
              <label class="auth-label" for="td-web">{{ $t('ui.registerPage.trade.website') }}</label>
              <input
                id="td-web"
                v-model.trim="form.website"
                class="auth-input"
                placeholder="yourshop.ie"
              >
            </div>
          </div>

          <label class="auth-checkbox-label">
            <input v-model="form.resale_confirmed" type="checkbox">
            <span class="auth-custom-checkbox" />
            <span>
              {{ $t('ui.registerPage.trade.resaleConfirm') }}
              <span class="auth-label-required">*</span>
            </span>
          </label>
          <small v-if="fieldBad('resale_confirmed')" class="auth-error">
            {{ $t('ui.registerPage.trade.resaleRequired') }}
          </small>

        </template>

        <!-- ── contact + password ── -->
        <h2 class="auth-sub-head">{{ $t('ui.registerPage.signInHeading') }}</h2>

        <div class="auth-row">

          <div class="auth-form-group">
            <label class="auth-label" for="su-email">
              {{ $t('ui.registerPage.email') }} <span class="auth-label-required">*</span>
            </label>
            <input
              id="su-email"
              v-model.trim="form.email"
              class="auth-input"
              :class="{ invalid: fieldBad('email') }"
              type="email"
              autocomplete="email"
              placeholder="you@example.ie"
              @input="clearServerFieldError('email')"
            >
            <p class="auth-hint">{{ $t('ui.registerPage.emailHint') }}</p>
            <small v-if="fieldBad('email')" class="auth-error">
              {{ fieldMessage('email', $t('ui.registerPage.emailInvalid')) }}
            </small>
          </div>

          <div class="auth-form-group">
            <label class="auth-label" for="su-phone">
              {{ $t('ui.registerPage.mobile') }} <span class="auth-label-required">*</span>
            </label>
            <phone-field
              :value="form.phone"
              :names="countryList"
              :is-invalid="fieldBad('phone')"
              @change="onPhoneChange"
            />
            <p class="auth-hint">
              {{ $t('ui.registerPage.mobileHint') }}
            </p>
            <small v-if="fieldBad('phone')" class="auth-error">
              {{ fieldMessage('phone', phoneError) }}
            </small>
          </div>

        </div>

        <div class="auth-row">

          <div class="auth-form-group">
            <label class="auth-label">
              {{ $t('ui.registerPage.password') }} <span class="auth-label-required">*</span>
            </label>
            <auth-password-field
              :value="form.password"
              :is-invalid="fieldBad('password')"
              @change="form.password = $event"
            />
            <div class="auth-meter" :class="`s${passwordScore}`">
              <i /><i /><i /><i />
            </div>
            <p class="auth-hint">{{ passwordHint }}</p>
            <small v-if="fieldBad('password')" class="auth-error">
              {{ $t('ui.registerPage.passwordRule') }}
            </small>
          </div>

          <div class="auth-form-group">
            <label class="auth-label">
              {{ $t('ui.registerPage.confirmPassword') }} <span class="auth-label-required">*</span>
            </label>
            <auth-password-field
              :value="form.confirmPassword"
              :is-invalid="fieldBad('confirmPassword')"
              @change="form.confirmPassword = $event"
            />
            <small v-if="fieldBad('confirmPassword')" class="auth-error">
              {{ $t('ui.registerPage.passwordsMismatch') }}
            </small>
          </div>

        </div>

        <!-- ── preferences ── -->
        <h2 class="auth-sub-head">{{ $t('ui.registerPage.prefs.heading') }}</h2>

        <div class="auth-prefs">

          <label class="auth-checkbox-label">
            <input v-model="form.pref_email_offers" type="checkbox">
            <span class="auth-custom-checkbox" />
            <span>
              <b>{{ $t('ui.registerPage.prefs.emailOffers') }}</b>
              <small class="auth-hint">
                {{ $t('ui.registerPage.prefs.emailOffersHint') }}
              </small>
            </span>
          </label>

          <label class="auth-checkbox-label">
            <input v-model="form.pref_sms_offers" type="checkbox">
            <span class="auth-custom-checkbox" />
            <span>
              <b>{{ $t('ui.registerPage.prefs.smsOffers') }}</b>
              <small class="auth-hint">
                {{ $t('ui.registerPage.prefs.smsOffersHint') }}
              </small>
            </span>
          </label>

          <label class="auth-checkbox-label">
            <input v-model="form.pref_recommendations" type="checkbox">
            <span class="auth-custom-checkbox" />
            <span>
              <b>{{ $t('ui.registerPage.prefs.recommendations') }}</b>
              <small class="auth-hint">
                {{ $t('ui.registerPage.prefs.recommendationsHint') }}
              </small>
            </span>
          </label>

          <label
            v-if="accountType === 'trade'"
            class="auth-checkbox-label"
          >
            <input v-model="form.pref_trade_list" type="checkbox">
            <span class="auth-custom-checkbox" />
            <span>
              <b>{{ $t('ui.registerPage.prefs.tradeList') }}</b>
              <small class="auth-hint">
                {{ $t('ui.registerPage.prefs.tradeListHint') }}
              </small>
            </span>
          </label>

        </div>

        <p class="auth-hint auth-hint-spaced">
          {{ $t('ui.registerPage.prefs.changeLater') }}
        </p>

        <label class="auth-checkbox-label">
          <input v-model="form.terms" type="checkbox">
          <span class="auth-custom-checkbox" />
          <span>
            <i18n
              path="ui.registerPage.agreeTo"
              tag="span"
            >
              <template #link><a
                class="privacy-policy"
                href="#"
                @click.prevent="openPrivacy"
              >{{ $t('ui.registerPage.privacyNoticeLink') }}</a></template>
            </i18n>
            <span class="auth-label-required">*</span>
          </span>
        </label>
        <small v-if="fieldBad('terms')" class="auth-error">
          {{ $t('ui.registerPage.termsRequired') }}
        </small>

        <ajax-button
          class="auth-btn-primary auth-btn-spaced"
          :fetching-data="submitting"
          :loading-text="$t('ui.registerPage.creatingAccount')"
          :text="$t('ui.registerPage.createAccount')"
        >
          {{ $t('ui.registerPage.createAccount') }}
        </ajax-button>

      </form>

      <p class="auth-foot">
        {{ $t('ui.registerPage.alreadyRegistered') }}
        <nuxt-link to="/login" class="auth-foot-link">{{ $t('ui.registerPage.signIn') }}</nuxt-link>
      </p>

    </section>

    <!-- ═══════════ STEP 3 · VERIFY ═══════════ -->
    <section v-else-if="step === 'verify'">

      <auth-steps
        :steps="wizardSteps"
        current="verify"
      />

      <h1 class="auth-h1">{{ $t('ui.registerPage.verify.title') }}</h1>
      <i18n
        path="ui.registerPage.verify.lede"
        tag="p"
        class="auth-lede"
      >
        <template #email><b>{{ form.email }}</b></template>
      </i18n>

      <form @submit.prevent="submitVerify">

        <div class="auth-form-group">
          <label class="auth-label">
            {{ $t('ui.registerPage.verify.code') }} <span class="auth-label-required">*</span>
          </label>
          <otp-input
            ref="otp"
            :value="code"
            :is-invalid="codeInvalid"
            @change="onCodeChange"
            @complete="submitVerify"
          />
          <small v-if="codeInvalid" class="auth-error">
            {{ $t('ui.registerPage.verify.codeInvalid') }}
          </small>
        </div>

        <ajax-button
          class="auth-btn-primary"
          :fetching-data="submitting"
          :loading-text="$t('ui.registerPage.verify.confirming')"
          :text="$t('ui.registerPage.verify.confirm')"
        >
          {{ $t('ui.registerPage.verify.confirm') }}
        </ajax-button>

      </form>

      <div class="auth-btn-stack">
        <button
          type="button"
          class="auth-btn-ghost"
          :disabled="submitting"
          @click="resendCode"
        >
          {{ $t('ui.registerPage.sendNewCode') }}
        </button>
        <button
          type="button"
          class="auth-btn-ghost"
          @click="goToStep('details')"
        >
          {{ $t('ui.registerPage.verify.differentEmail') }}
        </button>
      </div>

      <i18n
        path="ui.registerPage.verify.nothingInInbox"
        tag="div"
        class="auth-note auth-note-warn auth-note-spaced"
      >
        <template #email><b>{{ supportEmail }}</b></template>
      </i18n>

    </section>

    <!-- ═══════════ STEP 4 · SECURITY ═══════════ -->
    <section v-else-if="step === 'security'">

      <auth-steps
        :steps="wizardSteps"
        current="security"
      />

      <!-- 4a · choose a method -->
      <template v-if="securityScreen === 'choose'">

        <h1 class="auth-h1">{{ $t('ui.registerPage.security.title') }}</h1>
        <p class="auth-lede">
          {{ $t('ui.registerPage.security.lede') }}
        </p>

        <div class="auth-pick-methods">

          <button
            type="button"
            class="auth-pick-card"
            @click="startAuthenticator"
          >
            <span class="pick-ico">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="6" y="2" width="12" height="20" rx="2.5"/><path d="M11 18h2"/></svg>
            </span>
            <span>
              <b>
                {{ $t('ui.registerPage.security.app') }}
                <span class="auth-pill">{{ $t('ui.registerPage.security.recommended') }}</span>
              </b>
              <span>
                {{ $t('ui.registerPage.security.appHint') }}
              </span>
            </span>
          </button>

          <!--
            Only offered when the SMS provider can actually deliver a code.
            Showing it otherwise leaves people waiting on a text that is
            never sent — the authenticator app is the working option.
          -->
          <button
            v-if="smsAvailable"
            type="button"
            class="auth-pick-card"
            @click="startSms"
          >
            <span class="pick-ico">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2Z"/></svg>
            </span>
            <span>
              <b>{{ $t('ui.registerPage.security.sms') }}</b>
              <span>{{ $t('ui.registerPage.security.smsHint') }}</span>
            </span>
          </button>

        </div>

        <button
          type="button"
          class="auth-btn-ghost"
          @click="finish"
        >
          {{ $t('ui.registerPage.security.skip') }}
        </button>
        <p class="auth-hint auth-hint-centered">
          {{ $t('ui.registerPage.security.later') }}
        </p>

      </template>

      <!-- 4b · authenticator app -->
      <template v-else-if="securityScreen === 'app'">

        <button
          type="button"
          class="auth-back"
          @click="securityScreen = 'choose'"
        >
          <span aria-hidden="true">&larr;</span> {{ $t('ui.registerPage.security.differentMethod') }}
        </button>

        <h1 class="auth-h1">{{ $t('ui.registerPage.security.appTitle') }}</h1>
        <p class="auth-lede">
          {{ $t('ui.registerPage.security.appLede') }}
        </p>

        <div class="auth-qr-row">

          <img
            v-if="qrDataUrl"
            class="auth-qr"
            :src="qrDataUrl"
            :alt="$t('ui.registerPage.security.qrAlt')"
          >
          <div v-else class="auth-qr auth-qr-loading" />

          <div class="auth-qr-side">
            <p class="auth-hint">{{ $t('ui.registerPage.security.cantScan') }}</p>
            <div class="auth-secret">{{ secretDisplay }}</div>
            <button
              type="button"
              class="auth-btn-ghost auth-btn-sm"
              @click="copySecret"
            >
              {{ secretCopied ? $t('ui.registerPage.security.copied') : $t('ui.registerPage.security.copyKey') }}
            </button>
          </div>

        </div>

        <form @submit.prevent="confirmAuthenticator">

          <div class="auth-form-group">
            <label class="auth-label">
              {{ $t('ui.registerPage.security.appCode') }} <span class="auth-label-required">*</span>
            </label>
            <otp-input
              ref="otp"
              :value="code"
              :is-invalid="codeInvalid"
              @change="onCodeChange"
              @complete="confirmAuthenticator"
            />
            <small v-if="codeInvalid" class="auth-error">
              {{ $t('ui.registerPage.security.appCodeInvalid') }}
            </small>
          </div>

          <ajax-button
            class="auth-btn-primary"
            :fetching-data="submitting"
            :loading-text="$t('ui.registerPage.security.turningOn')"
            :text="$t('ui.registerPage.security.turnOn')"
          >
            {{ $t('ui.registerPage.security.turnOn') }}
          </ajax-button>

        </form>

      </template>

      <!-- 4c · text message -->
      <template v-else-if="securityScreen === 'sms'">

        <button
          type="button"
          class="auth-back"
          @click="securityScreen = 'choose'"
        >
          <span aria-hidden="true">&larr;</span> {{ $t('ui.registerPage.security.differentMethod') }}
        </button>

        <h1 class="auth-h1">{{ $t('ui.registerPage.security.smsTitle') }}</h1>
        <i18n
          path="ui.registerPage.security.smsLede"
          tag="p"
          class="auth-lede"
        >
          <template #phone><b>{{ phoneHint }}</b></template>
        </i18n>

        <form @submit.prevent="confirmSms">

          <div class="auth-form-group">
            <label class="auth-label">
              {{ $t('ui.registerPage.security.smsCode') }} <span class="auth-label-required">*</span>
            </label>
            <otp-input
              ref="otp"
              :value="code"
              :is-invalid="codeInvalid"
              @change="onCodeChange"
              @complete="confirmSms"
            />
            <small v-if="codeInvalid" class="auth-error">
              {{ $t('ui.registerPage.security.smsCodeInvalid') }}
            </small>
          </div>

          <ajax-button
            class="auth-btn-primary"
            :fetching-data="submitting"
            :loading-text="$t('ui.registerPage.security.turningOn')"
            :text="$t('ui.registerPage.security.turnOn')"
          >
            {{ $t('ui.registerPage.security.turnOn') }}
          </ajax-button>

        </form>

        <div class="auth-btn-stack">
          <button
            type="button"
            class="auth-btn-ghost"
            :disabled="submitting"
            @click="startSms"
          >
            {{ $t('ui.registerPage.sendNewCode') }}
          </button>
        </div>

      </template>

      <!-- 4d · recovery codes -->
      <template v-else-if="securityScreen === 'recovery'">

        <h1 class="auth-h1">{{ $t('ui.registerPage.security.recoveryTitle') }}</h1>
        <p class="auth-lede">
          {{ $t('ui.registerPage.security.recoveryLede') }}
        </p>

        <div class="auth-codes">
          <span
            v-for="(item, index) in recoveryCodes"
            :key="index"
          >
            {{ item }}
          </span>
        </div>

        <div class="auth-btn-stack">
          <button
            type="button"
            class="auth-btn-ghost"
            @click="copyRecovery"
          >
            {{ recoveryCopied ? $t('ui.registerPage.security.copied') : $t('ui.registerPage.security.copyCodes') }}
          </button>
        </div>

        <button
          class="auth-btn-primary auth-btn-spaced"
          @click="finish"
        >
          {{ $t('ui.registerPage.security.savedFinish') }}
        </button>

      </template>

    </section>

    <!-- ═══════════ DONE ═══════════ -->
    <section v-else-if="step === 'done'">

      <div class="thank-you-content">
        <svg width="184" height="144" viewBox="0 0 184 144" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M58.2503 84.9997C52.4463 82.8113 39.6503 74.9468 34.8979 60.9962C33.1226 52.995 24.4627 34.9601 9.29225 38.0186C4.46096 38.9926 -2.82421 46.4956 3.35161 55.8674C7.85822 62.706 21.0093 71.3363 37.5609 51.1487L54.563 25.2988" stroke="#33319A" stroke-width="2" stroke-linecap="round" stroke-dasharray="5 5"/>
          <path d="M43.3818 18.9353L71.5384 1L64.4424 33.6079L57.6686 20.8631L43.3818 18.9353Z" stroke="#33319A" stroke-width="2" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M59.9221 17.6177L59.3586 18.4291" stroke="#33319A" stroke-width="2" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M71.1903 1.39331L61.6113 15.1847" stroke="#33319A" stroke-width="2" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round"/>
          <circle cx="112.979" cy="89" r="55" fill="#33319A"/>
          <path d="M92.9795 92.4615L104.744 104L132.979 74" stroke="white" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"/>
          <circle cx="175.56" cy="128.58" r="6.0803" stroke="#33319A" stroke-width="3"/>
        </svg>
        <h2>{{ doneTitle }}</h2>
        <p>{{ doneLede }}</p>
      </div>

      <button
        class="auth-btn-primary"
        @click="goShopping"
      >
        {{ $t('ui.registerPage.done.startShopping') }}
      </button>

    </section>

    <!-- PRIVACY POLICY POPUP -->
    <transition name="fade">
      <div
        v-if="showPrivacyPopup"
        class="privacy-overlay"
        @click.self="closePrivacy"
      >
        <div
          class="privacy-dialog"
          role="dialog"
          aria-modal="true"
          aria-labelledby="privacy-title"
        >

          <header class="privacy-head">
            <div>
              <span class="privacy-eyebrow">{{ $t('ui.registerPage.privacy.eyebrow') }}</span>
              <h2 id="privacy-title">{{ $t('ui.registerPage.privacy.title') }}</h2>
            </div>
            <button
              ref="privacyClose"
              type="button"
              class="privacy-close"
              :aria-label="$t('ui.registerPage.privacy.close')"
              @click="closePrivacy"
            >
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
          </header>

          <div class="privacy-body">

            <div
              v-if="privacyPolicy"
              class="privacy-prose"
              v-dompurify-html="privacyPolicyContent"
            />

            <p
              v-else
              class="privacy-empty"
            >
              <i18n path="ui.registerPage.privacy.empty" tag="span"><template #email><a :href="`mailto:${supportEmail}`">{{ supportEmail }}</a></template></i18n>
            </p>

          </div>

          <footer class="privacy-foot">
            <button
              type="button"
              class="privacy-accept"
              @click="acceptPrivacy"
            >
              {{ $t('ui.registerPage.privacy.accept') }}
            </button>
          </footer>

        </div>
      </div>
    </transition>

  </div>

</template>

<script>

  import {mapGetters, mapState, mapActions} from 'vuex'
  import validation from '~/mixin/validation'
  import AjaxButton from '~/components/AjaxButton'
  import AuthPasswordField from '~/components/AuthPasswordField'
  import AuthSteps from '~/components/AuthSteps'
  import AccountTypeCards from '~/components/AccountTypeCards'
  import OtpInput from '~/components/OtpInput'
  import PhoneField from '~/components/PhoneField'
  import global from '~/mixin/global'
  import configJson from '~/config.json'
  import {
    COMPANY_FIELDS, COMPANY_BOOLEAN_FIELDS, COMPANY_FIELD_LABELS,
    SECTORS, TRADES, SPENDS, companyFieldErrors, isValidVat as vatLooksValid
  } from '~/mixin/accountFields'

  /*
   * Server-side errors for these get shown at the control rather than in the
   * box at the top - they are the ones the shopper has to go back and change.
   */
  const INLINE_FIELDS = ['email', 'phone']

  /*
   * Which form fields belong to which account type.
   *
   * The wizard keeps one form object so that moving back to the type step and
   * forward again does not lose what was typed, and so that switching between
   * business and trade keeps the company details both of them ask for. The
   * cost of one object is that every field was being submitted whatever the
   * customer had chosen: picking business, filling it in, then switching to
   * trade sent `sector`, `requires_po` and `credit_application` along with the
   * trade application.
   *
   * The backend nulls those out by account type when it writes the profile, so
   * nothing wrong was stored - but the request said one thing and the account
   * meant another, and nothing in between was checking. This map is what the
   * payload is built from, so the two cannot drift.
   */
  const COMMON_FIELDS = [
    'name', 'email', 'phone', 'password', 'terms',
    'pref_email_offers', 'pref_sms_offers', 'pref_recommendations'
  ]

  // The company fields are shared with the account-upgrade form.
  const TYPE_FIELDS = {
    personal: [],
    business: COMPANY_FIELDS.business,
    trade: COMPANY_FIELDS.trade
  }

  /** Values sent as 1/0 rather than true/false. */
  const BOOLEAN_FIELDS = [
    'terms', 'pref_email_offers', 'pref_sms_offers', 'pref_recommendations'
  ].concat(COMPANY_BOOLEAN_FIELDS)

  /** Human labels, for the warning shown before a switch drops an answer. */
  const FIELD_LABELS = COMPANY_FIELD_LABELS

  /*
   * A field's label in the current language, falling back to the shared
   * English one. Takes the component rather than using `this` so the
   * wizard test, which calls the methods on a plain object, still works.
   */
  function fieldLabel(vm, name) {
    const key = `ui.registerPage.fieldLabels.${name}`

    return vm && vm.$te && vm.$te(key) ? vm.$t(key) : (FIELD_LABELS[name] || name)
  }

  export default {

    middleware: ['common-middleware', 'non-logged-in'],
    layout: "empty",

    components: {
      AjaxButton,
      AuthPasswordField,
      AuthSteps,
      AccountTypeCards,
      OtpInput,
      PhoneField
    },

    mixins: [validation, global],

    data() {
      return {
        form: {
          name: '',
          email: '',
          phone: '',
          password: '',
          confirmPassword: '',
          company_name: '',
          sector: '',
          vat_number: '',
          cro_number: '',
          billing_address: '',
          requires_po: false,
          credit_application: false,
          trade: '',
          expected_spend: '',
          website: '',
          resale_confirmed: false,
          pref_email_offers: true,
          pref_sms_offers: false,
          pref_recommendations: true,
          pref_trade_list: true,
          terms: false
        },
        code: '',
        phoneValid: false,
        phoneTouched: false,
        /*
         * Per-field messages the server sent back, keyed by field name - the
         * uniqueness checks on email and phone are the ones this form cannot
         * make on its own.
         */
        serverFieldErrors: {},
        errors: [],
        showErrors: false,
        codeInvalid: false,
        submitting: false,
        securityScreen: 'choose',
        // Hidden until the status endpoint confirms the provider can send.
        smsAvailable: false,
        secret: '',
        secretDisplay: '',
        secretCopied: false,
        qrDataUrl: '',
        phoneHint: '',
        recoveryCodes: [],
        recoveryCopied: false,
        twoFactorOn: false,
        showPrivacyPopup: false,
        privacyPolicy: null,
        wizardSteps: [
          {key: 'type', label: this.$t('ui.registerPage.steps.type')},
          {key: 'details', label: this.$t('ui.registerPage.steps.details')},
          {key: 'verify', label: this.$t('ui.registerPage.steps.verify')},
          {key: 'security', label: this.$t('ui.registerPage.steps.security')}
        ],
        sectors: SECTORS,
        trades: TRADES,
        spends: SPENDS
      }
    },

    computed: {

      step() {
        const step = this.$route.query?.step

        return ['details', 'verify', 'security', 'done'].includes(step)
          ? step
          : 'type'
      },

      accountType() {
        const type = this.$route.query?.type

        return ['personal', 'business', 'trade'].includes(type)
          ? type
          : 'personal'
      },

      /*
       * The type actually picked, as opposed to the one the form falls back to.
       * A first visit has chosen nothing, and marking "Personal" as the current
       * choice there would be telling the shopper they had already decided.
       */
      chosenType() {
        const type = this.$route.query?.type

        return ['personal', 'business', 'trade'].includes(type) ? type : ''
      },

      /* Wording for each account type, in the current language. */
      typeCopy() {
        return {
        personal: {
          label: this.$t('ui.registerPage.types.personal.label'),
          foot: this.$t('ui.registerPage.types.personal.foot'),
          title: this.$t('ui.registerPage.types.personal.title'),
          lede: this.$t('ui.registerPage.types.personal.lede')
        },
        business: {
          label: this.$t('ui.registerPage.types.business.label'),
          foot: this.$t('ui.registerPage.types.business.foot'),
          title: this.$t('ui.registerPage.types.business.title'),
          lede: this.$t('ui.registerPage.types.business.lede')
        },
        trade: {
          label: this.$t('ui.registerPage.types.trade.label'),
          foot: this.$t('ui.registerPage.types.trade.foot'),
          title: this.$t('ui.registerPage.types.trade.title'),
          lede: this.$t('ui.registerPage.types.trade.lede')
        }
        }
      },

      chosenLabel() {
        return this.typeCopy[this.accountType].label
      },

      chosenFoot() {
        return this.typeCopy[this.accountType].foot
      },

      detailsTitle() {
        return this.typeCopy[this.accountType].title
      },

      detailsLede() {
        return this.typeCopy[this.accountType].lede
      },

      doneTitle() {
        return this.twoFactorOn ? this.$t('ui.registerPage.done.allSet') : this.$t('ui.registerPage.done.welcome')
      },

      doneLede() {

        if (this.accountType === 'trade') {
          return this.$t('ui.registerPage.done.tradeLede')
        }

        return this.$t('ui.registerPage.done.lede')
      },

      supportEmail() {
        return this.site_setting?.email || 'shop@fixmypc.ie'
      },

      /* ── password strength, same bands as the mockup ── */
      passwordScore() {

        const p = this.form.password || ''
        let score = 0

        if (p.length >= 10) score++
        if (/[A-Z]/.test(p) && /[a-z]/.test(p)) score++
        if (/\d/.test(p)) score++
        if (/[^A-Za-z0-9]/.test(p) && p.length >= 12) score++

        return score
      },

      passwordHint() {
        return [
          this.$t('ui.registerPage.strength.s0'),
          this.$t('ui.registerPage.strength.s1'),
          this.$t('ui.registerPage.strength.s2'),
          this.$t('ui.registerPage.strength.s3'),
          this.$t('ui.registerPage.strength.s4')
        ][this.passwordScore]
      },

      passwordStrongEnough() {
        const p = this.form.password || ''

        return p.length >= 10 && /[A-Z]/.test(p) && /\d/.test(p)
      },

      privacyPolicyContent() {
        return this.privacyPolicy || ''
      },

      phoneError() {
        return this.phoneTouched && !this.phoneValid
          ? this.$t('ui.registerPage.phoneWrongCountry')
          : this.$t('ui.registerPage.phoneRequired')
      },

      /* Fields that fail validation right now, keyed by name. */
      badFields() {

        const bad = {}
        const f = this.form

        if (!f.name) bad.name = true
        if (!f.email || !this.isValidEmail(f.email)) bad.email = true
        // Valid per the selected country's own numbering rules, not just present.
        if (!this.phoneValid) bad.phone = true
        if (!this.passwordStrongEnough) bad.password = true
        if (!f.confirmPassword || f.confirmPassword !== f.password) bad.confirmPassword = true
        if (!f.terms) bad.terms = true

        // Company questions: the same rules as the account-upgrade form.
        Object.assign(bad, companyFieldErrors(this.accountType, f))

        return bad
      },

      ...mapGetters('language', ['langCode']),
      ...mapGetters('resource', ['countryList', 'phoneList']),
      ...mapState('common', ['site_setting'])
    },

    watch: {

      step() {
        this.errors = []
        this.showErrors = false
        this.codeInvalid = false
        this.code = ''
      }
    },

    methods: {

      /* ── navigation ── */
      goToStep(step, extra = {}) {

        const query = {...this.$route.query, ...extra}

        if (step === 'type') {
          delete query.step
        } else {
          query.step = step
        }

        this.$router.push({path: this.$route.path, query})
      },

      /** The fields the chosen account type actually uses. */
      fieldsFor(type) {
        return COMMON_FIELDS.concat(TYPE_FIELDS[type] || [])
      },

      /**
       * The request body, built from the field map rather than from the whole
       * form. Anything belonging to an account type the customer is no longer
       * registering as is left behind here - it stays in the wizard so that
       * switching back restores it, but it is never sent.
       */
      registrationPayload() {

        const payload = {account_type: this.accountType}

        this.fieldsFor(this.accountType).forEach(name => {
          payload[name] = BOOLEAN_FIELDS.includes(name)
            ? (this.form[name] ? 1 : 0)
            : this.form[name]
        })

        // Never part of the account; only ever compared against `password`.
        delete payload.confirmPassword

        return payload
      },

      /**
       * Answers that belong to the type being left and that the new one does
       * not ask for. Used to warn before a switch, and named so the warning
       * can say what is at stake rather than guessing.
       */
      strandedBy(nextType) {

        const keep = this.fieldsFor(nextType)

        return this.fieldsFor(this.accountType)
          .filter(name => !keep.includes(name))
          .filter(name => {
            const value = this.form[name]
            return typeof value === 'boolean' ? value : !!value
          })
          .map(name => fieldLabel(this, name))
      },

      /*
       * Switching type is not destructive to what is stored - the values stay
       * in the wizard and come back if the customer switches back - but it does
       * change what gets submitted, and somebody who filled in a whole trade
       * application should be told before that stops counting.
       */
      chooseType(type) {

        if (type !== this.accountType && this.step === 'details') {

          const stranded = this.strandedBy(type)

          if (stranded.length) {

            const warning = this.$t('ui.registerPage.switchWarning.intro') + '\n\n  · '
              + stranded.join('\n  · ')
              + '\n\n' + this.$t('ui.registerPage.switchWarning.outro')

            if (!window.confirm(warning)) {
              return
            }
          }
        }

        /*
         * Errors raised against the type being left would otherwise sit on the
         * new form - "Enter the company name" is not something a personal
         * registration can act on.
         */
        this.clearErrorsOutside(type)

        this.goToStep('details', {type})
      },

      clearErrorsOutside(type) {

        const keep = this.fieldsFor(type)
        const kept = {}

        Object.keys(this.serverFieldErrors).forEach(name => {
          if (keep.includes(name)) {
            kept[name] = this.serverFieldErrors[name]
          }
        })

        this.serverFieldErrors = kept
        this.errors = []
        this.showErrors = false
      },

      goShopping() {
        this.$router.push('/')
      },

      finish() {
        this.goToStep('done')
      },

      /* ── validation display ── */
      fieldBad(name) {
        return !!this.serverFieldErrors[name] ||
          (this.showErrors && !!this.badFields[name])
      },

      /*
       * "That email already has an account" can only be known by the server,
       * so its message wins over the generic one this form would otherwise
       * show for the same field.
       */
      fieldMessage(name, fallback) {
        return this.serverFieldErrors[name] || fallback
      },

      /* A field stops reading as taken the moment it is edited. */
      clearServerFieldError(name) {

        if (this.serverFieldErrors[name]) {
          this.$delete(this.serverFieldErrors, name)
        }
      },

      /*
       * Display text for a sector / trade / spend choice. The option's value
       * stays the shared English string the API expects; only the label is
       * translated, and only while the English message still matches that
       * string - if the list in accountFields.js changes, the old translation
       * is not shown against the wrong choice.
       */
      optionLabel(group, index, value) {
        const key = `ui.registerPage.options.${group}.o${index}`

        return this.$te(key, 'en') && this.$t(key, 'en') === value
          ? this.$t(key)
          : value
      },

      isValidVat(value) {
        return vatLooksValid(value)
      },

      onPhoneChange({e164, national, valid}) {

        this.clearServerFieldError('phone')

        // Store E.164 so the API and Twilio get an unambiguous number.
        this.form.phone = e164 || national
        this.phoneValid = valid
        this.phoneTouched = !!national
      },

      /*
       * A rejected code is cleared and the caret sent back to the first box,
       * so retyping needs no clicking.
       */
      rejectCode() {

        this.codeInvalid = true
        this.code = ''

        this.$nextTick(() => {
          if (this.$refs.otp && this.$refs.otp.focusFirst) {
            this.$refs.otp.focusFirst()
          }
        })
      },

      onCodeChange(code) {
        this.code = code

        if (code.length < 6) {
          this.codeInvalid = false
          this.errors = []
        }
      },

      apiUrl(key) {

        const base = process.env.apiBase?.trim()
          ? process.env.apiBase
          : window.location.origin + '/'

        return base + configJson.api.url + configJson.api[key]
      },

      /* ── step 2 ── */
      async submitDetails() {

        this.showErrors = true

        if (Object.keys(this.badFields).length) {
          return
        }

        this.errors = []
        this.serverFieldErrors = {}
        this.submitting = true

        try {

          const data = await this.registration({
            params: this.registrationPayload(),
            lang: this.langCode
          })

          if (data?.status === 200) {
            this.goToStep('verify')
          } else {
            this.applyServerErrors(data)
          }

        } catch (e) {
          return this.$nuxt.error(e)
        } finally {
          this.submitting = false
        }
      },

      /*
       * Anything the server can pin to a control is shown at that control;
       * only what is left over goes in the box at the top, so a taken email is
       * not reported twice in two places.
       */
      applyServerErrors(data) {

        const fields = data?.data?.fields || {}
        const inline = {}

        INLINE_FIELDS.forEach(name => {

          const message = Array.isArray(fields[name]) ? fields[name][0] : fields[name]

          if (message) {
            inline[name] = message
          }
        })

        this.serverFieldErrors = inline

        const shown = Object.values(inline)

        this.errors = (data?.data?.form || [data?.message])
          .filter(message => message && !shown.includes(message))
      },

      /* ── step 3 ── */
      async submitVerify() {

        if (this.code.length !== 6 || this.submitting) {
          return
        }

        this.errors = []
        this.submitting = true

        try {

          /*
           * Verification signs them in, so it goes through nuxt-auth exactly
           * as the sign-in form does and the token lands in the same place.
           */
          const {data} = await this.$auth.loginWith('local', {
            url: this.apiUrl('verify'),
            data: {
              email: this.form.email,
              code: this.code,
              user_token: await this.getUserToken()
            }
          })

          if (data?.status === 200) {
            this.codeInvalid = false
            this.setProfile(data.data?.user)
            localStorage.setItem('session_id', data.data.session_id)
            this.goToStep('security')
            this.loadSmsAvailability()
          } else {
            this.rejectCode()
            this.errors = data?.data?.form || [data?.message]
          }

        } catch (e) {
          return this.$nuxt.error(e)
        } finally {
          this.submitting = false
        }
      },

      async resendCode() {

        this.errors = []
        this.submitting = true

        try {

          /*
           * A resend re-posts the registration, and the backend overwrites the
           * unverified row wholesale with it - so it has to be the same
           * type-filtered body as the first submission, not the whole form.
           * Terms were already accepted to get this far.
           */
          const data = await this.registration({
            params: {...this.registrationPayload(), terms: 1},
            lang: this.langCode
          })

          if (data?.status === 200) {
            this.code = ''
            this.codeInvalid = false
            this.setToastMessage(this.$t('ui.registerPage.toastNewCode', { email: this.form.email }))
          } else {
            this.errors = data?.data?.form || [data?.message]
          }

        } catch (e) {
          return this.$nuxt.error(e)
        } finally {
          this.submitting = false
        }
      },

      /* ── step 4 ── */
      async startAuthenticator() {

        this.errors = []
        this.code = ''
        this.codeInvalid = false
        this.securityScreen = 'app'

        try {

          const data = await this.twoFactorSetup()

          if (data?.status === 200) {
            this.secret = data.data.secret
            this.secretDisplay = data.data.secret_display
            await this.renderQr(data.data.uri)
          } else {
            this.errors = [data?.message]
            this.securityScreen = 'choose'
          }

        } catch (e) {
          this.errors = [this.$t('ui.registerPage.security.setupFailed')]
          this.securityScreen = 'choose'
        }
      },

      async renderQr(uri) {

        try {
          // Loaded on demand so it never runs during server-side render.
          const QRCode = await import('qrcode')

          this.qrDataUrl = await QRCode.toDataURL(uri, {
            width: 344,
            margin: 1,
            color: {dark: '#16174A', light: '#FFFFFF'}
          })

        } catch (e) {
          // The key is shown for manual entry, so this is not fatal.
          this.qrDataUrl = ''
        }
      },

      async confirmAuthenticator() {

        if (this.code.length !== 6 || this.submitting) {
          return
        }

        this.errors = []
        this.submitting = true

        try {

          const data = await this.twoFactorConfirm({code: this.code})

          if (data?.status === 200) {
            this.twoFactorOn = true
            this.recoveryCodes = data.data.recovery_codes
            this.securityScreen = 'recovery'
          } else {
            this.rejectCode()
          }

        } catch (e) {
          this.rejectCode()
        } finally {
          this.submitting = false
        }
      },

      /**
       * Ask the server whether a text can actually be delivered before
       * offering it. If the check itself fails, leave the option hidden —
       * the authenticator app always works, so a hidden card is a far
       * better outcome than a code that never arrives.
       */
      async loadSmsAvailability() {

        try {
          const data = await this.twoFactorStatus()

          this.smsAvailable = data?.status === 200 && data.data?.sms_available === true

        } catch (e) {
          this.smsAvailable = false
        }
      },

      async startSms() {

        this.errors = []
        this.code = ''
        this.codeInvalid = false
        this.securityScreen = 'sms'
        this.submitting = true

        try {

          const data = await this.twoFactorSendSms()

          if (data?.status === 200) {
            this.phoneHint = data.data.phone_hint
          } else {
            this.errors = [data?.message]
            this.securityScreen = 'choose'
          }

        } catch (e) {
          this.errors = [this.$t('ui.registerPage.security.smsFailed')]
          this.securityScreen = 'choose'
        } finally {
          this.submitting = false
        }
      },

      async confirmSms() {

        if (this.code.length !== 6 || this.submitting) {
          return
        }

        this.errors = []
        this.submitting = true

        try {

          const data = await this.twoFactorConfirmSms({code: this.code})

          if (data?.status === 200) {
            this.twoFactorOn = true
            this.recoveryCodes = data.data.recovery_codes
            this.securityScreen = 'recovery'
          } else {
            this.rejectCode()
          }

        } catch (e) {
          this.rejectCode()
        } finally {
          this.submitting = false
        }
      },

      async copySecret() {
        await this.copyText(this.secret)
        this.secretCopied = true
        setTimeout(() => { this.secretCopied = false }, 2000)
      },

      async copyRecovery() {
        await this.copyText(this.recoveryCodes.join('\n'))
        this.recoveryCopied = true
        setTimeout(() => { this.recoveryCopied = false }, 2000)
      },

      async copyText(text) {
        try {
          await navigator.clipboard.writeText(text)
        } catch (e) {
          // Clipboard blocked; the value is on screen to copy by hand.
        }
      },

      openPrivacy() {
        this.showPrivacyPopup = true
        this.$nextTick(() => this.$refs.privacyClose && this.$refs.privacyClose.focus())
      },

      closePrivacy() {
        this.showPrivacyPopup = false
      },

      /* Reading it through is a fair signal of consent, so tick the box too. */
      acceptPrivacy() {
        this.form.terms = true
        this.closePrivacy()
      },

      onKeydown(event) {
        if (event.key === 'Escape' && this.showPrivacyPopup) {
          this.closePrivacy()
        }
      },

      async fetchPrivacyPolicy() {
        try {
          const {data} = await this.$axios.get(`${this.apiUrl('page')}/privacy-policy`)
          this.privacyPolicy = data?.data?.description || ''
        } catch (e) {
          // No policy page configured; the link just opens an empty dialog.
          this.privacyPolicy = ''
        }
      },

      async fetchCountries() {

        if (this.countryList && this.phoneList) {
          return
        }

        try {
          const {data} = await this.getRequest({
            params: null,
            lang: this.langCode,
            api: 'countriesPhones'
          })

          this.setCountryList(data?.countries)
          this.setPhoneList(data?.phones)

        } catch (e) {
          // The field still works; it just falls back to the default country.
        }
      },

      ...mapActions('resource', ['setCountryList', 'setPhoneList']),
      ...mapActions('user', [
        'registration',
        'setProfile',
        'getUserToken',
        'twoFactorSetup',
        'twoFactorConfirm',
        'twoFactorSendSms',
        'twoFactorConfirmSms',
        'twoFactorStatus'
      ]),
      ...mapActions('common', ['setToastMessage', 'getRequest'])
    },

    mounted() {

      /*
       * A guest claiming the order they just placed arrives with the email the
       * order was made under. It has to match for the merge on verification to
       * find their order, so it is filled in for them rather than retyped.
       */
      const email = this.$route.query?.email

      if (email && !this.form.email) {
        this.form.email = email
      }

      this.fetchPrivacyPolicy()
      this.fetchCountries()
      document.addEventListener('keydown', this.onKeydown)
    },

    beforeDestroy() {
      document.removeEventListener('keydown', this.onKeydown)
    }
  }
</script>

<style>

/* Form controls come from the shared auth styles in layouts/empty.vue.
 * Only the wizard-specific pieces live here. */

/*
 * The wizard carries a step indicator the other auth screens don't, so its
 * heading block needs more air than the shared defaults give. Kept under
 * .auth-wizard so sign-in and forgot-password are unaffected.
 */
.auth-wizard .auth-h1 {
    margin-bottom: 10px;
}

.auth-wizard .auth-lede {
    margin-bottom: 30px;
}

.auth-sub-head {
    font-size: 11.5px;
    font-weight: 800;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--auth-muted, #6B7191);
    margin: 30px 0 18px;
    padding-top: 22px;
    border-top: 1px solid var(--auth-line, #E4E5F3);
}

/* A long form needs more air between fields than the short sign-in one. */
.auth-wizard .auth-form-group {
    margin-bottom: 20px;
}

.auth-row {
    display: flex;
    /* Columns are uneven — the password one carries a meter and a hint — so
     * they align at the top rather than stretching to match. */
    align-items: flex-start;
    gap: 14px;
}

.auth-row > * {
    flex: 1;
    min-width: 0;
    /* Belt and braces: fields fill their column whichever way the row runs. */
    width: 100%;
}

.auth-textarea {
    min-height: 88px;
    resize: vertical;
    padding-top: 12px;
    padding-bottom: 12px;
    line-height: 1.5;
}

.auth-hint-spaced {
    margin: 10px 0 20px;
}

.auth-hint-centered {
    text-align: center;
    margin-top: 12px;
}

/*
 * Label, supporting line and action were flex siblings, so the text fought the
 * button for width and wrapped mid-phrase. The copy now owns one column.
 */
.auth-chosen {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    border: 1px solid var(--auth-line-strong, #CFD1E8);
    border-radius: var(--auth-radius, 14px);
    background: var(--auth-brand-50, #F1F1FC);
    margin-bottom: 26px;
}

.auth-chosen-ico {
    width: 28px;
    height: 28px;
    flex: 0 0 auto;
    border-radius: 50%;
    background: var(--auth-go, #00A24A);
    color: #fff;
    display: grid;
    place-items: center;
}

.auth-chosen-text {
    flex: 1;
    min-width: 0;
}

.auth-chosen b {
    display: block;
    font-size: 14px;
    line-height: 1.3;
    color: var(--auth-ink, #16174A);
}

.auth-chosen small {
    display: block;
    color: var(--auth-muted, #6B7191);
    font-size: 12.5px;
    line-height: 1.35;
    margin-top: 1px;
}

.auth-chosen-change {
    flex: 0 0 auto;
    align-self: center;
    background: #fff;
    border: 1px solid var(--auth-line-strong, #CFD1E8);
    border-radius: 999px;
    height: auto;
    line-height: 1.5;
    padding: 7px 14px;
    color: var(--auth-brand, #2D2DA0);
    font-weight: 700;
    font-size: 12.5px;
    cursor: pointer;
    font-family: inherit;
    transition: border-color .15s, background .15s;
}

.auth-chosen-change:hover {
    border-color: var(--auth-brand, #2D2DA0);
    background: #fff;
}

/* password meter */
.auth-prefs {
    display: grid;
    gap: 14px;
    padding: 16px;
    border: 1px solid var(--auth-line, #E4E5F3);
    border-radius: var(--auth-radius, 14px);
    background: var(--auth-wash, #F7F7FD);
}

.auth-prefs .auth-checkbox-label {
    align-items: flex-start;
    margin-bottom: 0;
}

.auth-prefs b {
    display: block;
    font-size: 13.5px;
    color: var(--auth-ink, #16174A);
}

.auth-checkbox-label {
    align-items: flex-start;
    margin-bottom: 15px;
}

.auth-btn-spaced {
    margin-top: 22px;
}

.auth-btn-sm {
    width: auto;
    padding: 10px 18px;
    font-size: 14px;
    margin-top: 10px;
}

.auth-note-spaced {
    margin-top: 22px;
}

/* 2FA method cards */
.auth-pick-methods {
    display: grid;
    gap: 12px;
    margin-bottom: 22px;
}

.auth-pick-card {
    display: flex;
    gap: 14px;
    align-items: flex-start;
    padding: 16px;
    border: 1px solid var(--auth-line-strong, #CFD1E8);
    border-radius: var(--auth-radius, 14px);
    cursor: pointer;
    background: #fff;
    text-align: left;
    width: 100%;
    height: auto;
    line-height: 1.45;
    transition: .15s;
}

.auth-pick-card:hover {
    border-color: var(--auth-brand, #2D2DA0);
    background: var(--auth-brand-50, #F1F1FC);
}

.pick-ico {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    background: var(--auth-brand-50, #F1F1FC);
    color: var(--auth-brand, #2D2DA0);
    display: grid;
    place-items: center;
    flex: 0 0 auto;
}

.auth-pick-card b {
    display: block;
    font-size: 15px;
    color: var(--auth-ink, #16174A);
}

.auth-pick-card span span {
    display: block;
    font-size: 13px;
    color: var(--auth-muted, #6B7191);
    margin-top: 3px;
    line-height: 1.4;
}

.auth-pill {
    display: inline-block;
    font-size: 10.5px;
    font-weight: 800;
    letter-spacing: .08em;
    text-transform: uppercase;
    background: var(--auth-go, #00A24A);
    color: #fff;
    padding: 3px 8px;
    border-radius: 999px;
    margin-left: 8px;
    vertical-align: 1px;
}

/* QR + secret */
.auth-qr-row {
    display: flex;
    gap: 18px;
    align-items: center;
    margin-bottom: 18px;
    flex-wrap: wrap;
}

.auth-qr {
    width: 172px;
    height: 172px;
    border-radius: var(--auth-radius, 14px);
    border: 1px solid var(--auth-line-strong, #CFD1E8);
    padding: 10px;
    background: #fff;
    flex: 0 0 auto;
}

.auth-qr-loading {
    background: var(--auth-wash, #F7F7FD);
}

.auth-qr-side {
    min-width: 0;
    flex: 1;
}

.auth-secret {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 14px;
    letter-spacing: .06em;
    background: var(--auth-wash, #F7F7FD);
    border: 1px solid var(--auth-line, #E4E5F3);
    border-radius: 10px;
    padding: 10px 12px;
    word-break: break-all;
    margin-top: 8px;
}

.auth-codes {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    font-family: ui-monospace, Menlo, monospace;
    font-size: 13.5px;
    background: var(--auth-wash, #F7F7FD);
    border: 1px solid var(--auth-line, #E4E5F3);
    border-radius: var(--auth-radius, 14px);
    padding: 14px;
    margin-bottom: 16px;
    color: var(--auth-ink, #16174A);
}

/* thank-you */
.privacy-policy {
    color: var(--auth-brand, #2D2DA0);
    font-weight: 600;
    text-decoration: underline;
    cursor: pointer;
}

.privacy-overlay {
    position: fixed;
    inset: 0;
    background: rgba(22, 23, 74, .45);
    backdrop-filter: blur(2px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 80;
    padding: 24px;
}

.privacy-dialog {
    background: #fff;
    border-radius: 18px;
    width: 100%;
    max-width: 620px;
    max-height: min(78vh, 720px);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    box-shadow: 0 30px 70px rgba(22, 23, 74, .28);
}

.privacy-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    padding: 20px 24px 16px;
    border-bottom: 1px solid var(--auth-line, #E4E5F3);
    flex: 0 0 auto;
}

.privacy-eyebrow {
    display: block;
    font-size: 10.5px;
    font-weight: 800;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--auth-muted, #6B7191);
    margin-bottom: 3px;
}

.privacy-head h2 {
    margin: 0;
    font-size: 18px;
    font-weight: 800;
    letter-spacing: -.01em;
    color: var(--auth-ink, #16174A);
}

.privacy-close {
    flex: 0 0 auto;
    width: 34px;
    height: 34px;
    padding: 0;
    line-height: 1;
    display: grid;
    place-items: center;
    border-radius: 50%;
    border: 1px solid var(--auth-line, #E4E5F3);
    background: #fff;
    color: var(--auth-muted, #6B7191);
    cursor: pointer;
}

.privacy-close:hover {
    border-color: var(--auth-line-strong, #CFD1E8);
    background: var(--auth-wash, #F7F7FD);
    color: var(--auth-ink, #16174A);
}

.privacy-body {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    padding: 22px 24px 26px;
    scrollbar-width: thin;
    scrollbar-color: var(--auth-line-strong, #CFD1E8) transparent;
}

.privacy-body::-webkit-scrollbar {
    width: 8px;
}

.privacy-body::-webkit-scrollbar-thumb {
    background: var(--auth-line-strong, #CFD1E8);
    border-radius: 999px;
}

/*
 * The notice comes out of the CMS as raw HTML with no styling of its own, so
 * the browser defaults were rendering a 32px h1 and oversized body copy. This
 * gives it a proper reading scale inside the dialog.
 */
.privacy-prose {
    font-size: 14px;
    line-height: 1.65;
    color: var(--auth-ink-soft, #3A3C6E);
}

/* The page's own title repeats the dialog heading directly beneath it. */
.privacy-prose > h1:first-child,
.privacy-prose > h2:first-child {
    display: none;
}

.privacy-prose h1,
.privacy-prose h2 {
    font-size: 15.5px;
    font-weight: 800;
    letter-spacing: -.01em;
    color: var(--auth-ink, #16174A);
    margin: 26px 0 8px;
}

.privacy-prose h3,
.privacy-prose h4 {
    font-size: 14px;
    font-weight: 700;
    color: var(--auth-ink, #16174A);
    margin: 20px 0 6px;
}

.privacy-prose p {
    margin: 0 0 12px;
}

.privacy-prose ul,
.privacy-prose ol {
    margin: 0 0 14px;
    padding-left: 20px;
}

.privacy-prose li {
    margin-bottom: 5px;
}

.privacy-prose a {
    color: var(--auth-brand, #2D2DA0);
    text-decoration: underline;
}

.privacy-prose strong,
.privacy-prose b {
    color: var(--auth-ink, #16174A);
    font-weight: 700;
}

.privacy-prose table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 14px;
    font-size: 13px;
}

.privacy-prose th,
.privacy-prose td {
    border: 1px solid var(--auth-line, #E4E5F3);
    padding: 7px 9px;
    text-align: left;
}

.privacy-prose img {
    max-width: 100%;
    height: auto;
}

.privacy-empty {
    margin: 0;
    font-size: 14px;
    color: var(--auth-muted, #6B7191);
}

.privacy-foot {
    flex: 0 0 auto;
    display: flex;
    justify-content: flex-end;
    padding: 16px 24px;
    border-top: 1px solid var(--auth-line, #E4E5F3);
    background: var(--auth-wash, #F7F7FD);
}

.privacy-accept {
    padding: 12px 26px;
    border-radius: 999px;
    border: 0;
    height: auto;
    line-height: 1.5;
    background: var(--auth-brand, #2D2DA0);
    color: #fff;
    font-size: 14.5px;
    font-weight: 700;
    font-family: inherit;
    cursor: pointer;
    transition: background .15s;
}

.privacy-accept:hover,
.privacy-accept:active,
.privacy-accept:focus {
    background: #22227E;
}

@media (min-width: 1025px) and (max-height: 1100px) {

    .auth-foot-duplicate {
        display: none;
    }

    .auth-sub-head {
        margin: 20px 0 12px;
        padding-top: 14px;
    }

    .auth-prefs {
        gap: 10px;
        padding: 14px;
    }

    .auth-checkbox-label {
        margin-bottom: 11px;
    }
}

/*
 * Between 1025 and 1280 the split layout is still on, so the form column is
 * squeezed — two fields to a row would be unreadable there. Below 700 it is
 * simply a phone. In both cases the pairs fall back to one per row.
 */
@media (min-width: 1025px) and (max-width: 1280px) {

    /*
     * align-items governs the cross axis, so once the row is a column it stops
     * meaning "align tops" and starts meaning "shrink to content width" —
     * which left every stacked field a different width. Stretch it back.
     */
    .auth-row {
        flex-direction: column;
        align-items: stretch;
        gap: 0;
    }
}

@media (max-width: 700px) {

    .auth-row {
        flex-direction: column;
        align-items: stretch;
        gap: 0;
    }
}

@media (max-width: 640px) {

    .auth-chosen {
        flex-wrap: wrap;
    }

    .auth-chosen-text {
        flex: 1 1 auto;
    }

    .auth-chosen-change {
        margin-left: 38px;
    }

    .auth-sub-head {
        margin: 26px 0 16px;
        padding-top: 20px;
    }

    .auth-prefs {
        padding: 14px;
        gap: 16px;
    }

    .auth-qr-row {
        flex-direction: column;
        align-items: flex-start;
    }

    .auth-qr {
        align-self: center;
    }

    .auth-codes {
        grid-template-columns: 1fr;
    }

    /* A small centred box wastes a phone screen — use the height instead. */
    .privacy-overlay {
        padding: 0;
        align-items: flex-end;
    }

    .privacy-dialog {
        max-width: none;
        max-height: 92vh;
        border-radius: 18px 18px 0 0;
    }

    .privacy-head {
        padding: 16px 18px 14px;
    }

    .privacy-body {
        padding: 18px 18px 22px;
    }

    .privacy-foot {
        padding: 14px 18px;
        padding-bottom: calc(14px + env(safe-area-inset-bottom));
    }

    .privacy-accept {
        width: 100%;
    }
}

</style>
