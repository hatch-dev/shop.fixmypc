<template>
  <account-layout
    class="user-profile-wrapper"
    active-route="profile"
    :class="{'email-login': !loggedInWithEmail}"
  >
    <template v-slot:rightArea>

      <div class="acct-page">

        <!-- ══════════ PAGE HEAD ══════════ -->
        <header class="acct-page-head">
          <h1 class="acct-page-title">{{ $t('ui.userProfile.title') }}</h1>
          <p class="acct-page-lede">
            {{ $t('ui.userProfile.lede') }}
          </p>
        </header>

        <!--
          fetchingProfileData was declared false and never set true by anything,
          so this gate never closed: the profile form and the sessions list were
          both on screen before fetchSessions() had returned.
        -->
        <account-skeleton
          v-if="fetchingProfileData"
          :fields="6"
          :panels="2"
          heading
          :label="$t('ui.userProfile.loadingProfile')"
        />

        <div v-else class="acct-stack">

          <!-- ══════════ 1 · PROFILE OVERVIEW ══════════ -->
          <section class="acct-card acct-overview" aria-labelledby="ov-h">

            <h2 id="ov-h" class="sr-only">{{ $t('ui.userProfile.profileOverview') }}</h2>

            <div class="acct-ov-identity">

              <div class="acct-ov-avatar">
                <img :src="userImage" class="acct-avatar" alt="">
                <input
                  ref="fileInput"
                  type="file"
                  accept="image/*"
                  hidden
                  @change="handleImageUpload"
                >
                <button
                  type="button"
                  class="acct-avatar-edit"
                  :aria-label="$t('ui.userProfile.changePicture')"
                  @click="$refs.fileInput.click()"
                >
                  <i class="fa-solid fa-camera" aria-hidden="true" />
                </button>
              </div>

              <div class="acct-ov-text">

                <p class="acct-ov-name">{{ name || $t('ui.userProfile.yourAccount') }}</p>
                <p class="acct-ov-email">{{ email }}</p>

                <!--
                  Status is never colour alone: each pill carries its own
                  words, so it still reads correctly in monochrome or to a
                  screen reader.
                -->
                <div class="acct-ov-badges">

                  <span class="acct-pill" :class="`acct-pill-${accountType}`">
                    {{ $t('ui.userProfile.accountPill', { type: accountTypeLabel }) }}
                  </span>

                  <span class="acct-pill" :class="`acct-pill-${statusTone}`">
                    {{ statusLabel }}
                  </span>

                  <span
                    v-if="accountType === 'trade'"
                    class="acct-pill"
                    :class="`acct-pill-${tradeTone}`"
                  >
                    {{ $t('ui.userProfile.tradePill', { status: tradeStatusLabel }) }}
                  </span>

                </div>

              </div>

            </div>

            <div v-if="selectedImageFile" class="acct-ov-avatar-save">
              <p class="acct-help">{{ $t('ui.userProfile.newPictureChosen') }}</p>
              <div class="acct-actions">
                <button type="button" class="acct-btn acct-btn-ghost" @click="cancelAvatar">
                  {{ $t('ui.userProfile.cancel') }}
                </button>
                <ajax-button
                  class="acct-btn acct-btn-primary"
                  type="button"
                  :fetching-data="profileSubmitting"
                  :text="$t('ui.userProfile.savePicture')"
                  @clicked="saveAvatar"
                />
              </div>
            </div>

          </section>

          <!-- ══════════ 2 · PERSONAL INFORMATION ══════════ -->
          <section class="acct-card" aria-labelledby="pi-h">

            <div class="acct-card-head">
              <h2 id="pi-h" class="acct-card-title">{{ $t('ui.userProfile.personalInfo') }}</h2>
              <button
                v-if="!editing.personal"
                type="button"
                class="acct-btn acct-btn-ghost acct-btn-sm"
                @click="startEdit('personal')"
              >
                <i class="fa-solid fa-pen" aria-hidden="true" /> {{ $t('ui.userProfile.edit') }}
              </button>
            </div>

            <div class="acct-card-body">

              <!-- view -->
              <dl v-if="!editing.personal" class="acct-grid acct-grid-pair">
                <div class="acct-field">
                  <dt class="acct-label">{{ $t('ui.userProfile.firstName') }}</dt>
                  <dd class="acct-value">{{ firstName || '—' }}</dd>
                </div>
                <div class="acct-field">
                  <dt class="acct-label">{{ $t('ui.userProfile.lastName') }}</dt>
                  <dd class="acct-value">{{ lastName || '—' }}</dd>
                </div>
              </dl>

              <!-- edit -->
              <form v-else class="acct-grid" @submit.prevent="savePersonal">

                <div class="acct-field">
                  <label class="acct-label" for="pi-first">
                    {{ $t('ui.userProfile.firstName') }} <span class="acct-req" aria-hidden="true">*</span>
                  </label>
                  <input
                    id="pi-first"
                    v-model="firstName"
                    type="text"
                    class="acct-input"
                    :class="{ 'is-bad': !firstName && hasProfileError }"
                    autocomplete="given-name"
                    :aria-invalid="!firstName && hasProfileError ? 'true' : 'false'"
                    aria-describedby="pi-first-err"
                  >
                  <p
                    v-if="!firstName && hasProfileError"
                    id="pi-first-err"
                    class="acct-error"
                  >
                    {{ $t('ui.userProfile.enterFirstName') }}
                  </p>
                </div>

                <div class="acct-field">
                  <label class="acct-label" for="pi-last">{{ $t('ui.userProfile.lastName') }}</label>
                  <input
                    id="pi-last"
                    v-model="lastName"
                    type="text"
                    class="acct-input"
                    autocomplete="family-name"
                  >
                </div>

                <div class="acct-field acct-field-wide">
                  <div class="acct-actions">
                    <button
                      type="button"
                      class="acct-btn acct-btn-ghost"
                      @click="cancelEdit('personal')"
                    >
                      {{ $t('ui.userProfile.cancel') }}
                    </button>
                    <ajax-button
                      class="acct-btn acct-btn-primary"
                      type="button"
                      :fetching-data="profileSubmitting"
                      :text="$t('ui.userProfile.saveChanges')"
                      @clicked="savePersonal"
                    />
                  </div>
                </div>

              </form>

            </div>

          </section>

          <!-- ══════════ 3 · CONTACT INFORMATION ══════════ -->
          <section class="acct-card" aria-labelledby="ci-h">

            <div class="acct-card-head">
              <h2 id="ci-h" class="acct-card-title">{{ $t('ui.userProfile.contactInfo') }}</h2>
              <button
                v-if="!editing.contact"
                type="button"
                class="acct-btn acct-btn-ghost acct-btn-sm"
                @click="startEdit('contact')"
              >
                <i class="fa-solid fa-pen" aria-hidden="true" /> {{ $t('ui.userProfile.edit') }}
              </button>
            </div>

            <div class="acct-card-body">

              <dl v-if="!editing.contact" class="acct-grid">

                <div class="acct-field">
                  <dt class="acct-label">{{ $t('ui.userProfile.emailAddress') }}</dt>
                  <dd class="acct-value acct-value-break">
                    {{ email || '—' }}
                    <span class="acct-inline-note">
                      <i class="fa-solid fa-lock" aria-hidden="true" /> {{ $t('ui.userProfile.cannotChangeHere') }}
                    </span>
                  </dd>
                </div>

                <div class="acct-field">
                  <dt class="acct-label">{{ $t('ui.userProfile.mobileNumber') }}</dt>
                  <dd class="acct-value">
                    <!--
                      Flag plus the number in its own country's international
                      format, so a customer can tell at a glance which country
                      the number on file belongs to.
                    -->
                    <span
                      v-if="phoneCountry"
                      class="fi acct-flag"
                      :class="`fi-${phoneCountry.toLowerCase()}`"
                      :title="phoneCountryName"
                      aria-hidden="true"
                    />
                    <span>{{ phoneDisplay || '—' }}</span>
                    <span
                      v-if="phone"
                      class="acct-pill acct-pill-sm"
                      :class="phoneVerified ? 'acct-pill-good' : 'acct-pill-wait'"
                    >
                      {{ phoneVerified ? $t('ui.userProfile.confirmed') : $t('ui.userProfile.notConfirmed') }}
                    </span>
                  </dd>
                </div>

              </dl>

              <form v-else class="acct-grid" @submit.prevent="saveContact">

                <div class="acct-field">
                  <label class="acct-label" for="ci-email">{{ $t('ui.userProfile.emailAddress') }}</label>
                  <input
                    id="ci-email"
                    :value="email"
                    type="email"
                    class="acct-input"
                    disabled
                    aria-describedby="ci-email-help"
                  >
                  <p id="ci-email-help" class="acct-help">
                    {{ $t('ui.userProfile.emailHelp') }}
                  </p>
                </div>

                <div class="acct-field">
                  <label class="acct-label" for="ci-phone">
                    {{ $t('ui.userProfile.mobileNumber') }} <span class="acct-req" aria-hidden="true">*</span>
                  </label>
                  <!--
                    The same control the registration form uses: country flag
                    and dial code, formatting as you type, and validation
                    against the chosen country's own numbering rules. The
                    value it emits is E.164, which is what the API and the
                    SMS provider need.
                  -->
                  <phone-field
                    input-id="ci-phone"
                    :value="phone"
                    :names="countryList"
                    :is-invalid="phoneBad"
                    @change="onPhoneChange"
                  />
                  <p
                    v-if="phoneBad"
                    id="ci-phone-err"
                    class="acct-error"
                    role="alert"
                  >
                    {{ phoneError }}
                  </p>
                  <p id="ci-phone-help" class="acct-help">
                    {{ $t('ui.userProfile.phoneHelp') }}
                  </p>
                </div>

                <div class="acct-field acct-field-wide">
                  <div class="acct-actions">
                    <button
                      type="button"
                      class="acct-btn acct-btn-ghost"
                      @click="cancelEdit('contact')"
                    >
                      {{ $t('ui.userProfile.cancel') }}
                    </button>
                    <ajax-button
                      class="acct-btn acct-btn-primary"
                      type="button"
                      :fetching-data="profileSubmitting"
                      :text="$t('ui.userProfile.saveChanges')"
                      @clicked="saveContact"
                    />
                  </div>
                </div>

              </form>

            </div>

          </section>

          <!-- ══════════ 4 · ADDRESSES ══════════ -->
          <section class="acct-card" aria-labelledby="ad-h">

            <div class="acct-card-head">
              <h2 id="ad-h" class="acct-card-title">{{ $t('ui.userProfile.addresses') }}</h2>
              <nuxt-link to="/user/addresses" class="acct-btn acct-btn-ghost acct-btn-sm">
                {{ $t('ui.userProfile.manageAddresses') }}
              </nuxt-link>
            </div>

            <div class="acct-card-body">

              <p v-if="loadingAddresses" class="acct-help">{{ $t('ui.userProfile.loadingAddresses') }}</p>

              <!-- empty state -->
              <div v-else-if="!addresses.length" class="acct-empty">
                <i class="fa-solid fa-location-dot acct-empty-ico" aria-hidden="true" />
                <p class="acct-empty-title">{{ $t('ui.userProfile.noAddresses') }}</p>
                <p class="acct-help">
                  {{ $t('ui.userProfile.noAddressesHelp') }}
                </p>
                <nuxt-link to="/user/addresses" class="acct-btn acct-btn-primary acct-btn-sm">
                  {{ $t('ui.userProfile.addAddress') }}
                </nuxt-link>
              </div>

              <ul v-else class="acct-addr-list">
                <li
                  v-for="address in addresses"
                  :key="address.id"
                  class="acct-addr"
                >
                  <div class="acct-addr-top">
                    <span class="acct-addr-type">
                      <i
                        class="fa-solid"
                        :class="address.type === 'office' ? 'fa-building' : 'fa-house'"
                        aria-hidden="true"
                      />
                      {{ addressTypeLabel(address) }}
                    </span>
                    <span
                      v-if="Number(address.default) === 1"
                      class="acct-pill acct-pill-sm acct-pill-brand"
                    >
                      {{ $t('ui.userProfile.default') }}
                    </span>
                  </div>
                  <p class="acct-addr-name">{{ address.name }}</p>
                  <p class="acct-addr-lines">{{ addressLines(address) }}</p>
                  <p v-if="address.phone" class="acct-addr-phone">{{ address.phone }}</p>
                </li>
              </ul>

            </div>

          </section>

          <!--
            ══════════ 5 · BUSINESS / TRADE ══════════

            Only rendered for accounts that have company details — a personal
            account is not shown an empty trade card.

            Type, trade status and verification are staff decisions and are
            read-only here; the backend ignores them even if posted.
          -->
          <section
            v-if="accountType !== 'personal'"
            class="acct-card"
            aria-labelledby="bz-h"
          >

            <div class="acct-card-head">
              <h2 id="bz-h" class="acct-card-title">
                {{ accountType === 'trade' ? $t('ui.userProfile.tradeInformation') : $t('ui.userProfile.businessInformation') }}
              </h2>
              <button
                v-if="!editing.business"
                type="button"
                class="acct-btn acct-btn-ghost acct-btn-sm"
                @click="startEdit('business')"
              >
                <i class="fa-solid fa-pen" aria-hidden="true" /> {{ $t('ui.userProfile.edit') }}
              </button>
            </div>

            <div class="acct-card-body">

              <!-- staff-controlled facts, always read-only -->
              <dl class="acct-grid acct-grid-3 acct-locked">

                <div class="acct-field">
                  <dt class="acct-label">{{ $t('ui.userProfile.accountType') }}</dt>
                  <dd class="acct-value">
                    <span class="acct-pill" :class="`acct-pill-${accountType}`">
                      {{ accountTypeLabel }}
                    </span>
                  </dd>
                </div>

                <div v-if="accountType === 'trade'" class="acct-field">
                  <dt class="acct-label">{{ $t('ui.userProfile.tradeStatus') }}</dt>
                  <dd class="acct-value">
                    <span class="acct-pill" :class="`acct-pill-${tradeTone}`">
                      {{ tradeStatusLabel }}
                    </span>
                  </dd>
                </div>

                <div class="acct-field">
                  <dt class="acct-label">{{ $t('ui.userProfile.companyDetails') }}</dt>
                  <dd class="acct-value">
                    <span class="acct-pill" :class="`acct-pill-${verificationTone}`">
                      {{ verificationLabel }}
                    </span>
                  </dd>
                </div>

                <div v-if="accountType === 'business'" class="acct-field">
                  <dt class="acct-label">{{ $t('ui.userProfile.creditAccount') }}</dt>
                  <dd class="acct-value">
                    <span class="acct-pill" :class="creditLocked ? 'acct-pill-wait' : 'acct-pill-idle'">
                      {{ creditLocked ? $t('ui.userProfile.appliedFor') : $t('ui.userProfile.notAppliedFor') }}
                    </span>
                  </dd>
                </div>

              </dl>

              <p class="acct-locked-note">
                <i class="fa-solid fa-lock" aria-hidden="true" />
                <i18n path="ui.userProfile.lockedNote" :tag="false">
                  <template #link><nuxt-link to="/page/contact-us">{{ $t('ui.userProfile.contactUs') }}</nuxt-link></template>
                </i18n>
              </p>

              <hr class="acct-rule">

              <!-- customer-owned company details -->
              <dl v-if="!editing.business" class="acct-grid">

                <div class="acct-field">
                  <dt class="acct-label">{{ $t('ui.userProfile.companyName') }}</dt>
                  <dd class="acct-value">{{ business.company_name || '—' }}</dd>
                </div>

                <div v-if="accountType === 'business'" class="acct-field">
                  <dt class="acct-label">{{ $t('ui.userProfile.sector') }}</dt>
                  <dd class="acct-value">{{ business.sector || '—' }}</dd>
                </div>

                <div class="acct-field">
                  <dt class="acct-label">{{ $t('ui.userProfile.vatNumber') }}</dt>
                  <dd class="acct-value">{{ business.vat_number || '—' }}</dd>
                </div>

                <div class="acct-field">
                  <dt class="acct-label">{{ $t('ui.userProfile.croNumber') }}</dt>
                  <dd class="acct-value">{{ business.cro_number || '—' }}</dd>
                </div>

                <div v-if="accountType === 'trade'" class="acct-field">
                  <dt class="acct-label">{{ $t('ui.userProfile.website') }}</dt>
                  <dd class="acct-value acct-value-break">{{ business.website || '—' }}</dd>
                </div>

                <div class="acct-field acct-field-wide">
                  <dt class="acct-label">{{ $t('ui.userProfile.billingAddress') }}</dt>
                  <dd class="acct-value acct-value-pre">{{ business.billing_address || '—' }}</dd>
                </div>

                <div v-if="accountType !== 'personal'" class="acct-field">
                  <dt class="acct-label">{{ $t('ui.userProfile.poRequired') }}</dt>
                  <dd class="acct-value">{{ business.requires_po ? $t('ui.userProfile.yes') : $t('ui.userProfile.no') }}</dd>
                </div>

              </dl>

              <form v-else class="acct-grid" @submit.prevent="saveBusinessDetails">

                <div class="acct-field">
                  <label class="acct-label" for="bz-company">{{ $t('ui.userProfile.companyName') }}</label>
                  <input
                    id="bz-company"
                    v-model.trim="business.company_name"
                    type="text"
                    class="acct-input"
                    autocomplete="organization"
                  >
                </div>

                <div v-if="accountType === 'business'" class="acct-field">
                  <label class="acct-label" for="bz-sector">{{ $t('ui.userProfile.sector') }}</label>
                  <select
                    id="bz-sector"
                    v-model="business.sector"
                    class="acct-input"
                  >
                    <option value="">{{ $t('ui.userProfile.chooseOne') }}</option>
                    <option v-for="i in sectors" :key="i">{{ i }}</option>
                  </select>
                </div>

                <div class="acct-field">
                  <label class="acct-label" for="bz-vat">{{ $t('ui.userProfile.vatNumber') }}</label>
                  <input
                    id="bz-vat"
                    v-model.trim="business.vat_number"
                    type="text"
                    class="acct-input"
                    aria-describedby="bz-vat-help"
                  >
                  <p id="bz-vat-help" class="acct-help">
                    {{ $t('ui.userProfile.vatHelp') }}
                  </p>
                </div>

                <div class="acct-field">
                  <label class="acct-label" for="bz-cro">{{ $t('ui.userProfile.croNumber') }}</label>
                  <input
                    id="bz-cro"
                    v-model.trim="business.cro_number"
                    type="text"
                    class="acct-input"
                    inputmode="numeric"
                    aria-describedby="bz-cro-help"
                  >
                  <p id="bz-cro-help" class="acct-help">{{ $t('ui.userProfile.digitsOnly') }}</p>
                </div>

                <div v-if="accountType === 'trade'" class="acct-field">
                  <label class="acct-label" for="bz-web">{{ $t('ui.userProfile.websiteOrSocial') }}</label>
                  <input
                    id="bz-web"
                    v-model.trim="business.website"
                    type="text"
                    class="acct-input"
                    placeholder="yourshop.ie"
                  >
                </div>

                <div class="acct-field acct-field-wide">
                  <label class="acct-label" for="bz-billing">{{ $t('ui.userProfile.billingAddress') }}</label>
                  <textarea
                    id="bz-billing"
                    v-model.trim="business.billing_address"
                    rows="4"
                    class="acct-input acct-textarea"
                  />
                </div>

                <template v-if="accountType !== 'personal'">

                  <div class="acct-field acct-field-wide">
                    <label class="acct-check" for="bz-po">
                      <input
                        id="bz-po"
                        v-model="business.requires_po"
                        type="checkbox"
                        class="acct-check-box"
                      >
                      <span class="acct-check-text">
                        <span class="acct-check-title">{{ $t('ui.userProfile.requirePo') }}</span>
                        <span class="acct-help">
                          {{ $t('ui.userProfile.requirePoHelp') }}
                        </span>
                      </span>
                    </label>
                  </div>

                  <div v-if="accountType === 'business'" class="acct-field acct-field-wide">
                    <label class="acct-check" for="bz-credit" :class="{ 'is-locked': creditLocked }">
                      <input
                        id="bz-credit"
                        v-model="business.credit_application"
                        type="checkbox"
                        class="acct-check-box"
                        :disabled="creditLocked"
                      >
                      <span class="acct-check-text">
                        <span class="acct-check-title">{{ $t('ui.userProfile.applyCredit') }}</span>
                        <span class="acct-help">{{ creditLine }}</span>
                      </span>
                    </label>
                  </div>

                </template>

                <div class="acct-field acct-field-wide">
                  <div class="acct-actions">
                    <button
                      type="button"
                      class="acct-btn acct-btn-ghost"
                      @click="cancelEdit('business')"
                    >
                      {{ $t('ui.userProfile.cancel') }}
                    </button>
                    <ajax-button
                      class="acct-btn acct-btn-primary"
                      type="button"
                      :fetching-data="businessSubmitting"
                      :text="$t('ui.userProfile.saveChanges')"
                      @clicked="saveBusinessDetails"
                    />
                  </div>
                </div>

              </form>

              <!--
                What the trade application said. Staff approved the account on
                the strength of these, so they are shown rather than edited.
              -->
              <template v-if="accountType === 'trade' && businessProfile">

                <hr class="acct-rule">

                <dl class="acct-grid acct-grid-3 acct-locked">
                  <div class="acct-field">
                    <dt class="acct-label">{{ $t('ui.userProfile.typeOfTrade') }}</dt>
                    <dd class="acct-value">{{ businessProfile.trade || '—' }}</dd>
                  </div>
                  <div class="acct-field">
                    <dt class="acct-label">{{ $t('ui.userProfile.expectedSpend') }}</dt>
                    <dd class="acct-value">{{ businessProfile.expected_spend || '—' }}</dd>
                  </div>
                  <div class="acct-field">
                    <dt class="acct-label">{{ $t('ui.userProfile.resaleDeclaration') }}</dt>
                    <dd class="acct-value">
                      {{ businessProfile.resale_confirmed ? $t('ui.userProfile.confirmed') : $t('ui.userProfile.notConfirmed') }}
                    </dd>
                  </div>
                </dl>

                <p class="acct-locked-note">
                  <i class="fa-solid fa-lock" aria-hidden="true" />
                  {{ $t('ui.userProfile.tradeAppNote') }}
                </p>

              </template>

            </div>

          </section>

          <!-- ══════════ 6 · PREFERENCES ══════════ -->
          <section
            id="preferences"
            class="acct-card acct-anchor"
            :class="{'acct-anchor-flash': flashSection === 'preferences'}"
            aria-labelledby="pr-h"
          >

            <div class="acct-card-head">
              <h2 id="pr-h" class="acct-card-title">{{ $t('ui.userProfile.commsPrefs') }}</h2>
            </div>

            <div class="acct-card-body">

              <ul class="acct-prefs">

                <li>
                  <label class="acct-check" for="pref-email">
                    <input
                      id="pref-email"
                      v-model="preferences.pref_email_offers"
                      type="checkbox"
                      class="acct-check-box"
                    >
                    <span class="acct-check-text">
                      <span class="acct-check-title">{{ $t('ui.userProfile.offersEmail') }}</span>
                      <span class="acct-help">
                        {{ $t('ui.userProfile.offersEmailHelp') }}
                      </span>
                    </span>
                  </label>
                </li>

                <li>
                  <label class="acct-check" for="pref-sms">
                    <input
                      id="pref-sms"
                      v-model="preferences.pref_sms_offers"
                      type="checkbox"
                      class="acct-check-box"
                    >
                    <span class="acct-check-text">
                      <span class="acct-check-title">{{ $t('ui.userProfile.offersText') }}</span>
                      <span class="acct-help">
                        {{ $t('ui.userProfile.offersTextHelp') }}
                      </span>
                    </span>
                  </label>
                </li>

                <li>
                  <label class="acct-check" for="pref-recs">
                    <input
                      id="pref-recs"
                      v-model="preferences.pref_recommendations"
                      type="checkbox"
                      class="acct-check-box"
                    >
                    <span class="acct-check-text">
                      <span class="acct-check-title">{{ $t('ui.userProfile.recommendations') }}</span>
                      <span class="acct-help">
                        {{ $t('ui.userProfile.recommendationsHelp') }}
                      </span>
                    </span>
                  </label>
                </li>

                <!-- Trade accounts only, matching registration. -->
                <li v-if="accountType === 'trade'">
                  <label class="acct-check" for="pref-trade">
                    <input
                      id="pref-trade"
                      v-model="preferences.pref_trade_list"
                      type="checkbox"
                      class="acct-check-box"
                    >
                    <span class="acct-check-text">
                      <span class="acct-check-title">{{ $t('ui.userProfile.tradeList') }}</span>
                      <span class="acct-help">
                        {{ $t('ui.userProfile.tradeListHelp') }}
                      </span>
                    </span>
                  </label>
                </li>

              </ul>

              <p class="acct-help acct-prefs-note">
                {{ $t('ui.userProfile.prefsNote') }}
              </p>

              <div class="acct-actions acct-actions-start">
                <ajax-button
                  class="acct-btn acct-btn-primary"
                  type="button"
                  :fetching-data="preferencesSubmitting"
                  :text="$t('ui.userProfile.savePreferences')"
                  @clicked="savePreferences"
                />
              </div>

            </div>

          </section>

          <!-- ══════════ 7 · SECURITY ══════════ -->
          <section class="acct-card" aria-labelledby="se-h">

            <div class="acct-card-head">
              <h2 id="se-h" class="acct-card-title">{{ $t('ui.userProfile.security') }}</h2>
              <span
                class="acct-pill"
                :class="twoFactorOn ? 'acct-pill-good' : 'acct-pill-idle'"
              >
                <i
                  class="fa-solid"
                  :class="twoFactorOn ? 'fa-shield-halved' : 'fa-shield'"
                  aria-hidden="true"
                />
                {{ twoFactorOn ? $t('ui.userProfile.twoFactorOn') : $t('ui.userProfile.twoFactorOff') }}
              </span>
            </div>

            <div class="acct-card-body">

              <div class="acct-sec-grid">

                <!-- password -->
                <div
                  id="password"
                  class="acct-sec-block acct-sec-panel acct-anchor"
                  :class="{'acct-anchor-flash': flashSection === 'password'}"
                >

                  <div class="acct-sec-head">
                    <h3 class="acct-sub-title">{{ $t('ui.userProfile.password') }}</h3>
                    <button
                      v-if="!editing.password"
                      type="button"
                      class="acct-btn acct-btn-ghost acct-btn-sm"
                      @click="startEdit('password')"
                    >
                      {{ $t('ui.userProfile.changePassword') }}
                    </button>
                  </div>

                  <p v-if="!editing.password" class="acct-value acct-password-mask">
                    ••••••••••
                  </p>
                  <p v-if="!editing.password" class="acct-help">
                    {{ $t('ui.userProfile.passwordHelp') }}
                  </p>

                  <form v-else @submit.prevent="updatePassword">

                    <div class="acct-field">
                      <label class="acct-label" for="pw-current">{{ $t('ui.userProfile.currentPassword') }}</label>
                      <password-field
                        input-id="pw-current"
                        :value="currentPassword"
                        @change="currentPassword = $event"
                      />
                      <p v-if="!currentPassword && hasPasswordError" class="acct-error">
                        {{ $t('ui.userProfile.enterCurrentPassword') }}
                      </p>
                    </div>

                    <div class="acct-field">
                      <label class="acct-label" for="pw-new">{{ $t('ui.userProfile.newPassword') }}</label>
                      <password-field
                        input-id="pw-new"
                        :value="newPassword"
                        @change="newPassword = $event"
                      />
                      <p v-if="!newPassword && hasPasswordError" class="acct-error">
                        {{ $t('ui.userProfile.enterNewPassword') }}
                      </p>
                      <p v-else-if="invalidPassword && hasPasswordError" class="acct-error">
                        {{ $t('ui.userProfile.passwordRule') }}
                      </p>
                    </div>

                    <div class="acct-field">
                      <label class="acct-label" for="pw-confirm">{{ $t('ui.userProfile.confirmNewPassword') }}</label>
                      <password-field
                        input-id="pw-confirm"
                        :value="confirmPassword"
                        @change="confirmPassword = $event"
                      />
                      <p v-if="!confirmPassword && hasPasswordError" class="acct-error">
                        {{ $t('ui.userProfile.confirmYourNewPassword') }}
                      </p>
                      <p
                        v-else-if="confirmPassword !== newPassword && hasPasswordError"
                        class="acct-error"
                      >
                        {{ $t('ui.userProfile.passwordsMismatch') }}
                      </p>
                    </div>

                    <div class="acct-actions acct-actions-start">
                      <button
                        type="button"
                        class="acct-btn acct-btn-ghost"
                        @click="cancelEdit('password')"
                      >
                        {{ $t('ui.userProfile.cancel') }}
                      </button>
                      <ajax-button
                        class="acct-btn acct-btn-primary"
                        type="button"
                        :fetching-data="passwordSubmitting"
                        :text="$t('ui.userProfile.updatePassword')"
                        @clicked="updatePassword"
                      />
                    </div>

                  </form>

                </div>

                <!-- two-factor -->
                <div class="acct-sec-block">
                  <two-factor-panel
                    ref="twoFactor"
                    @changed="onTwoFactorChanged"
                  />
                </div>

              </div>

              <hr class="acct-rule">

              <!-- active sessions -->
              <div class="acct-sec-head">
                <h3 class="acct-sub-title">{{ $t('ui.userProfile.activeSessions') }}</h3>
                <button
                  v-if="sessions.length > 1"
                  type="button"
                  class="acct-btn acct-btn-danger-ghost acct-btn-sm"
                  @click="logoutAllSession()"
                >
                  {{ $t('ui.userProfile.signOutEverywhere') }}
                </button>
              </div>

              <p class="acct-help">
                {{ $t('ui.userProfile.sessionsHelp') }}
              </p>

              <p v-if="loadingSessions" class="acct-help">{{ $t('ui.userProfile.loadingSessions') }}</p>

              <p v-else-if="!sessions.length" class="acct-help">
                {{ $t('ui.userProfile.noSessions') }}
              </p>

              <ul v-else class="acct-sessions">
                <li
                  v-for="session in visibleSessions"
                  :key="session.id"
                  class="acct-session"
                  :class="{ 'is-current': isCurrentSession(session) }"
                >
                  <span class="acct-session-ico" aria-hidden="true">
                    <i class="fa-solid" :class="getDeviceIcon(session)" />
                  </span>

                  <span class="acct-session-text">
                    <span class="acct-session-name">
                      <span class="acct-session-title">{{ sessionTitle(session) }}</span>
                      <span v-if="isCurrentSession(session)" class="acct-pill acct-pill-sm acct-pill-brand">
                        {{ $t('ui.userProfile.thisDevice') }}
                      </span>
                    </span>
                    <span class="acct-help">
                      <template v-if="isCurrentSession(session)">{{ $t('ui.userProfile.activeNow') }}</template>
                      <template v-else>{{ sessionStatus(session) }}</template>
                      <template v-if="session.location"> · {{ session.location }}</template>
                    </span>
                  </span>

                  <button
                    v-if="!isCurrentSession(session)"
                    type="button"
                    class="acct-btn acct-btn-danger-ghost acct-btn-sm"
                    :aria-label="$t('ui.userProfile.signOutDevice', { device: sessionTitle(session) })"
                    @click="logoutSession(session.session_id)"
                  >
                    {{ $t('ui.userProfile.signOut') }}
                  </button>
                </li>
              </ul>

              <!--
                Seven identical rows each with its own button was most of the
                page on a phone. The three most recent show; the rest are one
                tap away.
              -->
              <button
                v-if="!loadingSessions && sessions.length > sessionPreview"
                type="button"
                class="acct-sessions-more"
                :aria-expanded="showAllSessions ? 'true' : 'false'"
                @click="showAllSessions = !showAllSessions"
              >
                {{ showAllSessions ? $t('ui.userProfile.showFewer') : $t('ui.userProfile.showAllDevices', { count: sessions.length }) }}
                <i class="fa-solid" :class="showAllSessions ? 'fa-chevron-up' : 'fa-chevron-down'" aria-hidden="true" />
              </button>

            </div>

          </section>

          <!-- ══════════ 8 · ACCOUNT STATUS ══════════ -->
          <section class="acct-card" aria-labelledby="as-h">

            <div class="acct-card-head">
              <h2 id="as-h" class="acct-card-title">{{ $t('ui.userProfile.accountStatus') }}</h2>
            </div>

            <div class="acct-card-body">

              <p v-if="account && account.headline" class="acct-headline">
                {{ account.headline }}
              </p>

              <dl class="acct-grid acct-grid-3 acct-locked acct-grid-pair">

                <div class="acct-field">
                  <dt class="acct-label">{{ $t('ui.userProfile.accountType') }}</dt>
                  <dd class="acct-value">
                    <span class="acct-pill" :class="`acct-pill-${accountType}`">
                      {{ accountTypeLabel }}
                    </span>
                  </dd>
                </div>

                <div class="acct-field">
                  <dt class="acct-label">{{ $t('ui.userProfile.status') }}</dt>
                  <dd class="acct-value">
                    <span class="acct-pill" :class="`acct-pill-${statusTone}`">
                      {{ statusLabel }}
                    </span>
                  </dd>
                </div>

                <div v-if="accountType === 'trade'" class="acct-field">
                  <dt class="acct-label">{{ $t('ui.userProfile.tradeStatus') }}</dt>
                  <dd class="acct-value">
                    <span class="acct-pill" :class="`acct-pill-${tradeTone}`">
                      {{ tradeStatusLabel }}
                    </span>
                  </dd>
                </div>

              </dl>

              <p v-if="account && account.status_note" class="acct-note">
                {{ account.status_note }}
              </p>

              <p
                v-if="accountType === 'trade' && account && account.trade_review_note"
                class="acct-note"
              >
                <strong>{{ $t('ui.userProfile.noteFromUs') }}</strong> {{ account.trade_review_note }}
              </p>

              <p class="acct-locked-note">
                <i class="fa-solid fa-lock" aria-hidden="true" />
                <i18n
                  :path="upgradeOptions.length ? 'ui.userProfile.contactNoteUpgrade' : 'ui.userProfile.contactNote'"
                  :tag="false"
                >
                  <template #link><nuxt-link to="/page/contact-us">{{ $t('ui.userProfile.getInTouch') }}</nuxt-link></template>
                </i18n>
              </p>

            </div>

          </section>

          <!--
            ══════════ 8b · UPGRADE ══════════

            The customer moving their own account up a type. Business is
            immediate; trade is an application our team reviews, and nothing
            about the account changes until they approve it - a business
            customer keeps business pricing the whole time.

            Rules and state come from GET user/account-upgrade
            (AccountUpgradesController); the card is hidden when the account
            has nothing it may move to and nothing waiting.
          -->
          <section
            v-if="showUpgradeCard"
            id="upgrade"
            class="acct-card acct-anchor"
            :class="{'acct-anchor-flash': flashSection === 'upgrade'}"
            aria-labelledby="up-h"
          >

            <div class="acct-card-head">
              <h2 id="up-h" class="acct-card-title">
                {{ upgradePending ? $t('ui.userProfile.yourTradeApplication') : $t('ui.userProfile.upgradeYourAccount') }}
              </h2>
              <span v-if="upgradePending" class="acct-pill acct-pill-wait">{{ $t('ui.userProfile.underReview') }}</span>
            </div>

            <div class="acct-card-body">

              <!-- ── waiting for our team ── -->
              <template v-if="upgradePending">

                <p class="acct-headline">
                  {{ $t('ui.userProfile.pendingSent', { date: upgradeDate(upgradePending.submitted_at) }) }}
                  <template v-if="accountType === 'business'">
                    {{ $t('ui.userProfile.pendingBusiness') }}
                  </template>
                  <template v-else>
                    {{ $t('ui.userProfile.pendingRetail') }}
                  </template>
                </p>

                <dl class="acct-grid acct-locked">
                  <div class="acct-field">
                    <dt class="acct-label">{{ $t('ui.userProfile.tradingName') }}</dt>
                    <dd class="acct-value">{{ pendingDetail('company_name') }}</dd>
                  </div>
                  <div class="acct-field">
                    <dt class="acct-label">{{ $t('ui.userProfile.trade') }}</dt>
                    <dd class="acct-value">{{ pendingDetail('trade') }}</dd>
                  </div>
                  <div class="acct-field">
                    <dt class="acct-label">{{ $t('ui.userProfile.vatNumber') }}</dt>
                    <dd class="acct-value">{{ pendingDetail('vat_number') }}</dd>
                  </div>
                  <div class="acct-field">
                    <dt class="acct-label">{{ $t('ui.userProfile.expectedSpend') }}</dt>
                    <dd class="acct-value">{{ pendingDetail('expected_spend') }}</dd>
                  </div>
                </dl>

                <div class="acct-actions acct-actions-start acct-upgrade-withdraw">
                  <button
                    v-if="!withdrawConfirm"
                    type="button"
                    class="acct-btn acct-btn-ghost"
                    @click="withdrawConfirm = true"
                  >
                    {{ $t('ui.userProfile.withdrawApplication') }}
                  </button>
                  <template v-else>
                    <p class="acct-help acct-upgrade-confirm">
                      {{ $t('ui.userProfile.withdrawConfirm') }}
                    </p>
                    <button
                      type="button"
                      class="acct-btn acct-btn-ghost"
                      @click="withdrawConfirm = false"
                    >
                      {{ $t('ui.userProfile.keepIt') }}
                    </button>
                    <ajax-button
                      class="acct-btn acct-btn-danger"
                      type="button"
                      :fetching-data="withdrawing"
                      :text="$t('ui.userProfile.yesWithdraw')"
                      @clicked="withdrawUpgrade"
                    />
                  </template>
                </div>

              </template>

              <!-- ── choosing a type ── -->
              <template v-else-if="!upgradeChoice">

                <p v-if="lastRejection" class="acct-note">
                  <strong>{{ $t('ui.userProfile.lastRejected') }}</strong>
                  <template v-if="lastRejection.review_note">
                    {{ lastRejection.review_note }}
                  </template>
                  {{ $t('ui.userProfile.applyAgain') }}
                </p>

                <div class="acct-upgrade-options">
                  <article
                    v-for="option in upgradeOptionCards"
                    :key="option.type"
                    class="acct-upgrade-option"
                  >
                    <span class="acct-pill" :class="`acct-pill-${option.type}`">
                      {{ option.label }}
                    </span>
                    <p class="acct-upgrade-lede">{{ option.lede }}</p>
                    <ul class="acct-upgrade-points">
                      <li v-for="point in option.points" :key="point">{{ point }}</li>
                    </ul>
                    <button
                      type="button"
                      class="acct-btn acct-btn-primary"
                      @click="startUpgrade(option.type)"
                    >
                      {{ option.cta }}
                    </button>
                  </article>
                </div>

              </template>

              <!-- ── the form ── -->
              <form
                v-else
                class="acct-grid"
                novalidate
                @submit.prevent="submitUpgrade"
              >

                <p class="acct-field acct-field-wide acct-headline acct-upgrade-intro">
                  <template v-if="upgradeChoice === 'business'">
                    {{ $t('ui.userProfile.introBusiness') }}
                  </template>
                  <template v-else>
                    {{ $t('ui.userProfile.introTrade') }}
                    <template v-if="accountType === 'business'">
                      {{ $t('ui.userProfile.introTradeFromBusiness') }}
                    </template>
                  </template>
                </p>

                <div class="acct-field">
                  <label class="acct-label" for="up-company">
                    {{ upgradeChoice === 'trade' ? $t('ui.userProfile.tradingName') : $t('ui.userProfile.companyName') }}
                    <span class="acct-req" aria-hidden="true">*</span>
                  </label>
                  <input
                    id="up-company"
                    v-model.trim="upgradeForm.company_name"
                    type="text"
                    class="acct-input"
                    :class="{'is-bad': upgradeBad('company_name')}"
                    autocomplete="organization"
                    @input="clearUpgradeError('company_name')"
                  >
                  <p v-if="upgradeBad('company_name')" class="acct-error">
                    {{ upgradeMessage('company_name', $t('ui.userProfile.errors.companyName')) }}
                  </p>
                </div>

                <div v-if="upgradeChoice === 'business'" class="acct-field">
                  <label class="acct-label" for="up-sector">
                    {{ $t('ui.userProfile.sector') }} <span class="acct-req" aria-hidden="true">*</span>
                  </label>
                  <select
                    id="up-sector"
                    v-model="upgradeForm.sector"
                    class="acct-input"
                    :class="{'is-bad': upgradeBad('sector')}"
                    @change="clearUpgradeError('sector')"
                  >
                    <option value="">{{ $t('ui.userProfile.chooseOne') }}</option>
                    <option v-for="i in sectors" :key="i">{{ i }}</option>
                  </select>
                  <p v-if="upgradeBad('sector')" class="acct-error">
                    {{ upgradeMessage('sector', $t('ui.userProfile.errors.pickClosest')) }}
                  </p>
                </div>

                <div v-else class="acct-field">
                  <label class="acct-label" for="up-trade">
                    {{ $t('ui.userProfile.trade') }} <span class="acct-req" aria-hidden="true">*</span>
                  </label>
                  <select
                    id="up-trade"
                    v-model="upgradeForm.trade"
                    class="acct-input"
                    :class="{'is-bad': upgradeBad('trade')}"
                    @change="clearUpgradeError('trade')"
                  >
                    <option value="">{{ $t('ui.userProfile.chooseOne') }}</option>
                    <option v-for="i in trades" :key="i">{{ i }}</option>
                  </select>
                  <p v-if="upgradeBad('trade')" class="acct-error">
                    {{ upgradeMessage('trade', $t('ui.userProfile.errors.pickClosest')) }}
                  </p>
                </div>

                <div class="acct-field">
                  <label class="acct-label" for="up-vat">
                    {{ $t('ui.userProfile.vatNumber') }}
                    <span v-if="upgradeChoice === 'trade'" class="acct-req" aria-hidden="true">*</span>
                  </label>
                  <input
                    id="up-vat"
                    v-model.trim="upgradeForm.vat_number"
                    type="text"
                    class="acct-input"
                    :class="{'is-bad': upgradeBad('vat_number')}"
                    placeholder="IE1234567FA"
                    @input="clearUpgradeError('vat_number')"
                  >
                  <p v-if="upgradeBad('vat_number')" class="acct-error">
                    {{ upgradeMessage('vat_number', upgradeChoice === 'trade'
                      ? $t('ui.userProfile.errors.vatTrade')
                      : $t('ui.userProfile.errors.vatFormat')) }}
                  </p>
                </div>

                <div class="acct-field">
                  <label class="acct-label" for="up-cro">{{ $t('ui.userProfile.croNumber') }}</label>
                  <input
                    id="up-cro"
                    v-model.trim="upgradeForm.cro_number"
                    type="text"
                    class="acct-input"
                    :class="{'is-bad': upgradeBad('cro_number')}"
                    inputmode="numeric"
                    @input="clearUpgradeError('cro_number')"
                  >
                  <p v-if="upgradeBad('cro_number')" class="acct-error">
                    {{ upgradeMessage('cro_number', $t('ui.userProfile.digitsOnly')) }}
                  </p>
                </div>

                <template v-if="upgradeChoice === 'trade'">

                  <div class="acct-field">
                    <label class="acct-label" for="up-spend">
                      {{ $t('ui.userProfile.expectedSpend') }} <span class="acct-req" aria-hidden="true">*</span>
                    </label>
                    <select
                      id="up-spend"
                      v-model="upgradeForm.expected_spend"
                      class="acct-input"
                      :class="{'is-bad': upgradeBad('expected_spend')}"
                      @change="clearUpgradeError('expected_spend')"
                    >
                      <option value="">{{ $t('ui.userProfile.chooseOne') }}</option>
                      <option v-for="i in spends" :key="i">{{ i }}</option>
                    </select>
                    <p v-if="upgradeBad('expected_spend')" class="acct-error">
                      {{ upgradeMessage('expected_spend', $t('ui.userProfile.errors.spend')) }}
                    </p>
                  </div>

                  <div class="acct-field">
                    <label class="acct-label" for="up-web">{{ $t('ui.userProfile.websiteOrSocial') }}</label>
                    <input
                      id="up-web"
                      v-model.trim="upgradeForm.website"
                      type="text"
                      class="acct-input"
                      :class="{'is-bad': upgradeBad('website')}"
                      placeholder="yourshop.ie"
                      @input="clearUpgradeError('website')"
                    >
                    <p v-if="upgradeBad('website')" class="acct-error">
                      {{ upgradeMessage('website', $t('ui.userProfile.errors.website')) }}
                    </p>
                  </div>

                </template>

                <div class="acct-field acct-field-wide">
                  <label class="acct-label" for="up-billing">
                    {{ upgradeChoice === 'trade' ? $t('ui.userProfile.businessAddress') : $t('ui.userProfile.billingAddress') }}
                    <span class="acct-req" aria-hidden="true">*</span>
                  </label>
                  <textarea
                    id="up-billing"
                    v-model.trim="upgradeForm.billing_address"
                    rows="3"
                    class="acct-input acct-textarea"
                    :class="{'is-bad': upgradeBad('billing_address')}"
                    :placeholder="$t('ui.userProfile.addressPlaceholder')"
                    @input="clearUpgradeError('billing_address')"
                  />
                  <p v-if="upgradeBad('billing_address')" class="acct-error">
                    {{ upgradeMessage('billing_address', $t('ui.userProfile.errors.address')) }}
                  </p>
                </div>

                <template v-if="upgradeChoice === 'business'">

                  <div class="acct-field acct-field-wide">
                    <label class="acct-check" for="up-po">
                      <input
                        id="up-po"
                        v-model="upgradeForm.requires_po"
                        type="checkbox"
                        class="acct-check-box"
                      >
                      <span class="acct-check-text">
                        <span class="acct-check-title">{{ $t('ui.userProfile.requirePo') }}</span>
                        <span class="acct-help">
                          {{ $t('ui.userProfile.requirePoHelp') }}
                        </span>
                      </span>
                    </label>
                  </div>

                  <div class="acct-field acct-field-wide">
                    <label class="acct-check" for="up-credit">
                      <input
                        id="up-credit"
                        v-model="upgradeForm.credit_application"
                        type="checkbox"
                        class="acct-check-box"
                      >
                      <span class="acct-check-text">
                        <span class="acct-check-title">{{ $t('ui.userProfile.applyCredit') }}</span>
                        <span class="acct-help">
                          {{ $t('ui.userProfile.creditHelpUpgrade') }}
                        </span>
                      </span>
                    </label>
                  </div>

                </template>

                <template v-else>

                  <div class="acct-field acct-field-wide">
                    <label class="acct-check" for="up-resale">
                      <input
                        id="up-resale"
                        v-model="upgradeForm.resale_confirmed"
                        type="checkbox"
                        class="acct-check-box"
                        @change="clearUpgradeError('resale_confirmed')"
                      >
                      <span class="acct-check-text">
                        <span class="acct-check-title">
                          {{ $t('ui.userProfile.resaleText') }}
                          <span class="acct-req" aria-hidden="true">*</span>
                        </span>
                      </span>
                    </label>
                    <p v-if="upgradeBad('resale_confirmed')" class="acct-error">
                      {{ upgradeMessage('resale_confirmed', $t('ui.userProfile.errors.resale')) }}
                    </p>
                  </div>

                  <div class="acct-field acct-field-wide">
                    <label class="acct-check" for="up-list">
                      <input
                        id="up-list"
                        v-model="upgradeForm.pref_trade_list"
                        type="checkbox"
                        class="acct-check-box"
                      >
                      <span class="acct-check-text">
                        <span class="acct-check-title">{{ $t('ui.userProfile.tradeList') }}</span>
                        <span class="acct-help">{{ $t('ui.userProfile.tradeListHelpUpgrade') }}</span>
                      </span>
                    </label>
                  </div>

                </template>

                <hr class="acct-rule acct-field-wide">

                <div class="acct-field">
                  <label class="acct-label" for="up-password">
                    {{ $t('ui.userProfile.yourPassword') }} <span class="acct-req" aria-hidden="true">*</span>
                  </label>
                  <password-field
                    input-id="up-password"
                    :value="upgradePassword"
                    @change="upgradePassword = $event; clearUpgradeError('password')"
                  />
                  <p v-if="upgradeBad('password')" class="acct-error">
                    {{ upgradeMessage('password', $t('ui.userProfile.errors.password')) }}
                  </p>
                </div>

                <div class="acct-field acct-field-wide">
                  <div class="acct-actions">
                    <button
                      type="button"
                      class="acct-btn acct-btn-ghost"
                      @click="cancelUpgrade"
                    >
                      {{ $t('ui.userProfile.cancel') }}
                    </button>
                    <ajax-button
                      class="acct-btn acct-btn-primary"
                      type="button"
                      :fetching-data="upgradeSubmitting"
                      :text="upgradeChoice === 'business' ? $t('ui.userProfile.upgradeToBusiness') : $t('ui.userProfile.sendApplication')"
                      @clicked="submitUpgrade"
                    />
                  </div>
                </div>

              </form>

            </div>

          </section>

          <!-- ══════════ 9 · DANGER ZONE ══════════ -->
          <section class="acct-card acct-card-danger" aria-labelledby="dz-h">

            <div class="acct-card-body acct-danger">
              <div>
                <h2 id="dz-h" class="acct-sub-title acct-danger-title">{{ $t('ui.userProfile.deleteAccount') }}</h2>
                <p class="acct-help">
                  {{ $t('ui.userProfile.deleteHelp') }}
                </p>
              </div>
              <ajax-button
                class="acct-btn acct-btn-danger"
                type="button"
                :fetching-data="deletingAccount"
                :text="$t('ui.userProfile.deleteAccount')"
                @clicked="deleteAccount"
              />
            </div>

          </section>

        </div>

      </div>

    </template>
  </account-layout>
</template>

<script>

  import util from '~/mixin/util'
  import validation from '~/mixin/validation'
  import AccountLayout from '~/components/AccountLayout'
  import Spinner from '~/components/Spinner'
  import AccountSkeleton from '~/components/AccountSkeleton'
  import {mapGetters, mapState, mapActions} from 'vuex'
  import AjaxButton from "~/components/AjaxButton";
  import PasswordField from "~/components/PasswordField";
  import PhoneField from "~/components/PhoneField";
  import TwoFactorPanel from "~/components/TwoFactorPanel";
  import global from '~/mixin/global'
  import {SECTORS, TRADES, SPENDS, companyFieldErrors, companyPayload} from '~/mixin/accountFields'
  import {parsePhoneNumberFromString} from 'libphonenumber-js'
  // The flag sprite the view-mode number uses. PhoneField pulls the same
  // stylesheet in; webpack emits it once.
  import 'flag-icons/css/flag-icons.min.css'

  export default {
    middleware: ['common-middleware', 'auth'],
    head() {
      return {
        title: this.$t('ui.userProfile.pageTitle'),
        meta: []
      }
    },
    data() {
      return {
        sessions: [],
        loadingSessions: false,
        showAllSessions: false,
        sessionPreview: 3,
        currentSessionId: null,
        image: null,
        selectedImageFile: null,
        name: '',
        email: '',
        phone: '',
        phoneVerified: false,
        /*
         * Whether the number currently typed is a real one for the country
         * chosen in the flag picker. Starts true so an untouched form is not
         * shouting at a number the customer has not edited.
         */
        phoneValid: true,
        phoneTouched: false,
        currentPassword: '',
        newPassword: '',
        confirmPassword: '',
        hasProfileError: false,
        deletingAccount: false,
        hasPasswordError: false,
        profileSubmitting: false,
        passwordSubmitting: false,
        preferencesSubmitting: false,
        businessSubmitting: false,
        loadingAddresses: false,
        /*
         * Which sections are in edit mode. Everything reads as text until the
         * customer asks to change it — a page of permanently-open inputs is
         * hard to scan and invites accidental edits.
         */
        editing: {
          personal: false,
          contact: false,
          business: false,
          password: false
        },
        // Values as they were when editing started, so Cancel truly cancels.
        snapshot: {},
        // Mirrors User::twoFactorEnabled(), kept in step by the panel's
        // `changed` event so the header badge cannot drift from reality.
        twoFactorOn: false,
        /*
         * Marketing preferences. Held separately from `profile` so the boxes
         * can be toggled without mutating the store, and posted on their own
         * — the backend only writes the preference keys it is actually sent.
         */
        preferences: {
          pref_email_offers: false,
          pref_sms_offers: false,
          pref_recommendations: false,
          pref_trade_list: false
        },
        business: {
          company_name: '',
          vat_number: '',
          cro_number: '',
          billing_address: '',
          sector: '',
          website: '',
          requires_po: false,
          credit_application: false
        },
        // Shared with registration (mixin/accountFields.js).
        sectors: SECTORS,
        trades: TRADES,
        spends: SPENDS,
        /*
         * The account-upgrade card. `upgrade` is GET user/account-upgrade:
         * options, any application waiting, the last decision. Null until
         * it has loaded, and the card stays hidden while it is.
         */
        upgrade: null,
        // 'business' | 'trade' while the form is open.
        upgradeChoice: null,
        upgradeForm: {},
        upgradePassword: '',
        upgradeShowErrors: false,
        upgradeServerErrors: {},
        upgradeSubmitting: false,
        withdrawConfirm: false,
        withdrawing: false,
        // Which #section was just scrolled to; outlines it briefly.
        flashSection: null,
        // True until mounted() has finished; see the skeleton in the template.
        fetchingProfileData: true
      }
    },
    components: {
      PasswordField,
      PhoneField,
      AjaxButton,
      AccountLayout,
      Spinner,
      AccountSkeleton,
      TwoFactorPanel
    },

    watch: {
      // Already on the profile page when a #section link is followed.
      '$route.hash'() {
        if (!this.fetchingProfileData) {
          this.scrollToHashSection()
        }
      },
      profile(value) {
        if (this.profile) {
          this.email = value?.email
          this.name = value?.name
          this.phone = value?.phone
          this.image = value?.image
          this.hydrateAccount(value)
        }
      },
    },
    mixins: [util, validation, global],
    computed: {
      // This device first, then the most recently used.
      orderedSessions() {
        return [...(this.sessions || [])].sort((a, b) => {
          const cur = (this.isCurrentSession(b) ? 1 : 0) - (this.isCurrentSession(a) ? 1 : 0)
          if (cur) return cur
          return new Date(b.last_activity || 0) - new Date(a.last_activity || 0)
        })
      },
      visibleSessions() {
        return this.showAllSessions
          ? this.orderedSessions
          : this.orderedSessions.slice(0, this.sessionPreview)
      },
      userImage() {
        if (this?.image) {
          const path = this.image
          if ( path.startsWith('http://') || path.startsWith('https://') || path.startsWith('blob:')) {
            return path
          }
          return this.getImageURL(this.image)
        }

        /*
         * Seeded from the name rather than background=random, which handed the
         * same customer a different colour on every page load.
         */
        return 'https://ui-avatars.com/api/?name=' + encodeURIComponent(this?.name || 'Guest User')
          + '&background=33319A&color=fff&size=150'
      },
      firstName: {
        get() {
          return (this.name || '').trim().split(/\s+/)[0] || ''
        },
        set(val) {
          this.name = [val, this.lastName].filter(Boolean).join(' ')
        }
      },

      /*
       * Everything after the first word, not just the second.
       *
       * The getter took [1] and the setter rebuilt the name from two parts, so
       * saving "Mary Jane Smith" silently wrote back "Mary Jane" — the profile
       * form quietly deleted a name it could not display.
       */
      lastName: {
        get() {
          return (this.name || '').trim().split(/\s+/).slice(1).join(' ')
        },
        set(val) {
          return this.name = [this.firstName, val].filter(Boolean).join(' ')
        }
      },
      loggedInWithGoogle() {
        return this.profile && this.profile?.google_id
      },
      loggedInWithFacebook() {
        return this.profile && this.profile?.facebook_id
      },
      loggedInWithEmail() {
        return this.profile && !this.profile?.facebook_id && !this.profile?.google_id
      },
      loggedInWith() {
        if (this.profile) {
          if (this.loggedInWithGoogle) {
            return this.$t('profile.google')
          } else if (this.loggedInWithFacebook) {
            return this.$t('profile.facebook')
          } else {
            return this.$t('addressPopup.email')
          }
        }
      },
      invalidPassword() {
        return !this.isValidLength(this.newPassword)
      },

      /* ── mobile number ── */

      /**
       * The stored number, parsed once. Everything below reads this rather
       * than re-parsing, and it tolerates the older rows that were saved
       * before the number was held in E.164.
       */
      parsedPhone() {

        if (!this.phone) {
          return null
        }

        try {
          return parsePhoneNumberFromString(String(this.phone)) || null
        } catch (e) {
          return null
        }
      },

      phoneCountry() {
        return this.parsedPhone?.country || null
      },

      phoneCountryName() {
        return this.countryList?.[this.phoneCountry]?.name || this.phoneCountry || ''
      },

      /* "+91 78945 61230" rather than "+917894561230". */
      phoneDisplay() {

        if (!this.phone) {
          return ''
        }

        return this.parsedPhone
          ? this.parsedPhone.formatInternational()
          : this.phone
      },

      phoneBad() {
        return (this.phoneTouched && !this.phoneValid)
          || (!this.phone && this.hasProfileError)
      },

      phoneError() {
        return this.phoneTouched && !this.phoneValid
          ? this.$t('ui.userProfile.errors.phoneCountry')
          : this.$t('ui.userProfile.errors.phoneEmpty')
      },
      passwordValid() {
        return this.newPassword && !this.invalidPassword
      },

      /*
       * Entitlement and standing come from the server (User::accountSummary)
       * — the page never works them out from account_type on its own, which
       * is how the dashboard already does it.
       */
      account() {
        return this.profile?.account || null
      },

      accountType() {
        return this.account?.account_type || 'personal'
      },

      accountTypeLabel() {
        return {
          personal: this.$t('ui.userProfile.accountTypes.personal'),
          business: this.$t('ui.userProfile.accountTypes.business'),
          trade: this.$t('ui.userProfile.accountTypes.trade')
        }[this.accountType] || this.$t('ui.userProfile.accountTypes.personal')
      },

      statusLabel() {
        return {
          active: this.$t('ui.userProfile.statuses.active'),
          suspended: this.$t('ui.userProfile.statuses.suspended'),
          rejected: this.$t('ui.userProfile.statuses.rejected'),
          pending: this.$t('ui.userProfile.statuses.pending')
        }[this.account?.status] || this.$t('ui.userProfile.statuses.active')
      },

      statusTone() {
        const status = this.account?.status

        if (status === 'suspended' || status === 'rejected') return 'bad'
        if (status === 'pending') return 'wait'

        return 'good'
      },

      businessProfile() {
        return this.profile?.business_profile || null
      },

      upgradeOptions() {
        return this.upgrade?.options || []
      },

      upgradePending() {
        return this.upgrade?.pending || null
      },

      /* Only worth saying while the customer could apply again. */
      lastRejection() {
        const last = this.upgrade?.last_decision

        return last && last.status === 'rejected' && this.upgradeOptions.includes(last.to_type)
          ? last
          : null
      },

      showUpgradeCard() {
        return !!this.upgrade && (this.upgradeOptions.length > 0 || !!this.upgradePending)
      },

      /*
       * What each upgrade gets the customer. Worded against what the
       * account actually does today: trade and business are charged the
       * same quantity bands, so trade is not sold as cheaper.
       */
      upgradeOptionCards() {

        const cards = {
          business: {
            label: this.$t('ui.userProfile.cards.business.label'),
            lede: this.$t('ui.userProfile.cards.business.lede'),
            points: [
              this.$t('ui.userProfile.cards.business.point1'),
              this.$t('ui.userProfile.cards.business.point2'),
              this.$t('ui.userProfile.cards.business.point3')
            ],
            cta: this.$t('ui.userProfile.upgradeToBusiness')
          },
          trade: {
            label: this.$t('ui.userProfile.cards.trade.label'),
            lede: this.$t('ui.userProfile.cards.trade.lede'),
            points: [
              this.$t('ui.userProfile.cards.trade.point1'),
              this.accountType === 'business'
                ? this.$t('ui.userProfile.cards.trade.point2Business')
                : this.$t('ui.userProfile.cards.trade.point2'),
              this.$t('ui.userProfile.cards.trade.point3')
            ],
            cta: this.$t('ui.userProfile.cards.trade.cta')
          }
        }

        return this.upgradeOptions.map(type => ({type, ...cards[type]})).filter(card => card.label)
      },

      upgradeBadFields() {

        const bad = companyFieldErrors(this.upgradeChoice, this.upgradeForm)

        if (!this.upgradePassword) {
          bad.password = true
        }

        return bad
      },

      tradeStatusLabel() {
        return {
          approved: this.$t('ui.userProfile.tradeStatuses.approved'),
          rejected: this.$t('ui.userProfile.tradeStatuses.rejected'),
          suspended: this.$t('ui.userProfile.tradeStatuses.suspended'),
          pending: this.$t('ui.userProfile.tradeStatuses.pending')
        }[this.account?.trade_status] || this.$t('ui.userProfile.tradeStatuses.pending')
      },

      tradeTone() {

        const status = this.account?.trade_status

        if (status === 'approved') return 'good'
        if (status === 'rejected' || status === 'suspended') return 'bad'

        return 'wait'
      },

      verificationLabel() {
        return {
          verified: this.$t('ui.userProfile.verification.verified'),
          rejected: this.$t('ui.userProfile.verification.rejected'),
          pending: this.$t('ui.userProfile.verification.pending'),
          not_required: this.$t('ui.userProfile.verification.notRequired')
        }[this.account?.verification_status] || this.$t('ui.userProfile.verification.pending')
      },

      verificationTone() {

        const status = this.account?.verification_status

        if (status === 'verified') return 'good'
        if (status === 'rejected') return 'bad'
        if (status === 'not_required') return 'idle'

        return 'wait'
      },

      /*
       * A credit application already decided is not reopened from here, so
       * the box is locked once it has been sent.
       */
      creditLocked() {
        return !!this.businessProfile?.credit_application
      },

      creditLine() {
        return this.creditLocked
          ? this.$t('ui.userProfile.creditApplied')
          : this.$t('ui.userProfile.creditAsk')
      },

      /* Saved addresses, shown read-only here; managed on the Address Book. */
      addresses() {
        const rows = this.allAddress?.data || this.allAddress || []

        return Array.isArray(rows) ? rows.slice(0, 4) : []
      },

      ...mapState('resource', ['countryList']),
      ...mapGetters('user', ['profile', 'allAddress'])
    },
    methods: {
      /*
       * device_name is the user-agent parser's engine ("WebKit", "Blink"),
       * which told the customer nothing: every device read "WebKit". Browser
       * and platform are the two facts a person recognises.
       */
      sessionTitle(session) {
        const browser = session.browser && session.browser !== 'Unknown' ? session.browser : ''
        const platform = session.platform && session.platform !== 'Unknown' ? session.platform : ''

        if (browser && platform) return this.$t('ui.userProfile.browserOnPlatform', { browser, platform })

        const engines = ['WebKit', 'Blink', 'Gecko', 'Trident', 'Unknown']
        const device = engines.includes(session.device_name) ? '' : session.device_name

        return browser || platform || device || this.$t('ui.userProfile.unknownDevice')
      },
      getDeviceIcon(session) {
        if (session.device_type === 'Mobile') {
          return 'fa-mobile-screen'
        }

        if (session.device_type === 'Tablet') {
          return 'fa-tablet-screen-button'
        }
        return 'fa-desktop'
      },
      isCurrentSession(session) {
        return session.session_id === this.currentSessionId
      },
      sessionStatus(session) {
        const lastActivity = new Date(session.last_activity)
        const diffMinutes =
          (Date.now() - lastActivity.getTime()) / 60000

        if (diffMinutes < 5) {
          return this.$t('ui.userProfile.activeNow')
        }

        if (diffMinutes < 60) {
          return this.$t('ui.userProfile.minAgo', { n: Math.floor(diffMinutes) })
        }

        if (diffMinutes < 1440) {
          return this.$t('ui.userProfile.hoursAgo', { n: Math.floor(diffMinutes / 60) })
        }

        return this.$t('ui.userProfile.daysAgo', { n: Math.floor(diffMinutes / 1440) })
      },

      addressTypeLabel(address) {
        const type = (address?.type || 'home').toString()

        const key = 'ui.userProfile.addressTypes.' + type

        return this.$te(key) ? this.$t(key) : type.charAt(0).toUpperCase() + type.slice(1)
      },

      /**
       * One readable line for an address card.
       *
       * Deliberately not the addressHelper version: that one indexes
       * countryList without checking it, and this page can render before the
       * country list has been fetched.
       */
      addressLines(address) {
        if (!address) {
          return ''
        }

        const town = [address.city, address.zip].filter(Boolean).join(' ')
        const country = this.countryList?.[address.country]?.name

        return [address.address_1, address.address_2, town, country]
          .filter(Boolean)
          .join(', ')
      },

      onPhoneChange({e164, national, valid}) {
        // E.164 stored, so the API and the SMS provider get an unambiguous
        // number; the control keeps the pretty version on screen itself.
        this.phone = e164 || national
        this.phoneValid = valid
        this.phoneTouched = !!national
        this.hasProfileError = false
      },

      /**
       * Country names for the picker. Shared with the registration form
       * through the resource store, so this is a no-op once either has run.
       */
      async fetchCountries() {

        if (this.countryList) {
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
          // The field still works; the picker just shows ISO codes.
        }
      },

      async fetchAddresses() {
        try {
          this.loadingAddresses = true

          await this.userAddressAll({
            user_token: await this.getUserToken()
          })

        } catch (e) {
          // The Address Book link still works; an empty card is acceptable.
        } finally {
          this.loadingAddresses = false
        }
      },

      /* ── edit mode ── */

      /*
       * Arriving with #password or #preferences (the dashboard's Account
       * Security buttons). The router's own hash scroll runs while the
       * skeleton is still up, finds nothing and leaves the page at the top,
       * so this waits for the real sections. #password also opens the
       * change form, since that is what "Update" asked for.
       */
      scrollToHashSection() {
        const target = (this.$route.hash || '').replace('#', '')

        if (!['password', 'preferences', 'upgrade'].includes(target)) {
          return
        }

        if (target === 'password' && !this.editing.password) {
          this.startEdit('password')
        }

        this.$nextTick(() => {
          const el = document.getElementById(target)

          if (!el) {
            return
          }

          /*
           * Measured, not a fixed scroll-margin: the sticky header carries
           * the promo banner, so it is ~200px on a desktop with the banner,
           * less once it is dismissed and on a phone. A fixed 150px left the
           * section heading underneath it.
           */
          const header = document.querySelector('header')
          const headerHeight = header && getComputedStyle(header).position === 'sticky'
            ? header.getBoundingClientRect().height
            : 0

          window.scrollTo({
            top: el.getBoundingClientRect().top + window.pageYOffset - headerHeight - 16,
            behavior: 'smooth'
          })

          const first = el.querySelector('input, select, textarea')

          if (first && first.focus) {
            first.focus({preventScroll: true})
          }

          this.flashSection = target
          clearTimeout(this.flashTimer)
          this.flashTimer = setTimeout(() => { this.flashSection = null }, 1800)
        })
      },

      startEdit(section) {
        this.snapshot = {
          ...this.snapshot,
          name: this.name,
          phone: this.phone,
          business: {...this.business}
        }

        this.$set(this.editing, section, true)
      },

      cancelEdit(section) {

        if (section === 'personal') {
          this.name = this.snapshot.name
          this.hasProfileError = false
        }

        if (section === 'contact') {
          this.phone = this.snapshot.phone
          this.hasProfileError = false
          // The restored number is the one already on the account, so it is
          // not "touched" and must not come back wearing an error.
          this.phoneValid = true
          this.phoneTouched = false
        }

        if (section === 'business') {
          this.business = {...this.snapshot.business}
        }

        if (section === 'password') {
          this.currentPassword = ''
          this.newPassword = ''
          this.confirmPassword = ''
          this.hasPasswordError = false
        }

        this.$set(this.editing, section, false)
      },

      async fetchSessions() {
        try {
          this.loadingSessions = true
          const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'
          const response = await this.$axios.get(`${baseUrl}api/v1/sessions`, {
            headers: {
              Authorization: this.$auth.strategy.token.get()
            }
          })

          this.sessions = response.data.data || response.data

          this.currentSessionId =
            localStorage.getItem('session_id')

        } catch (error) {
          console.error(error)
          this.setToastError(this.$t('ui.userProfile.toast.loadSessionsFailed'))
        } finally {
          this.loadingSessions = false
        }
      },
      async logoutAllSession(){
        const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'
          const response = await this.$axios.post(
            `${baseUrl}api/v1/logout-other-devices`,
            {
              session_id: localStorage.getItem('session_id')
            },
            {
              headers: {
                Authorization: this.$auth.strategy.token.get()
              }
            }
          )

          await this.fetchSessions();

          this.setToastMessage(response.data.message)
      },
      async logoutSession(sessionId) {
        try {
          const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'
          const response = await this.$axios.post(
            `${baseUrl}api/v1/logout-device`,
            {
              session_id: sessionId
            },
            {
              headers: {
                Authorization: this.$auth.strategy.token.get()
              }
            }
          )

          await this.fetchSessions();

          this.setToastMessage(response.data.message)

        } catch (error) {
          console.error(error)
          this.setToastError(this.$t('ui.userProfile.toast.logoutSessionFailed'))
        }
      },
      handleImageUpload(e) {
        const file = e.target.files[0]
        if (!file) return

        this.selectedImageFile = file
        this.image = URL.createObjectURL(file)
      },

      cancelAvatar() {
        this.selectedImageFile = null
        this.image = this.profile?.image || null
      },

      async deleteAccount() {

        if (confirm(this.$t('cartProductTile.deleteAlert'))) {
          this.deletingAccount = true

          const data = await this.deleteRequest({
            api: 'deleteAccount',
            requiredToken: true,
            lang: this.langCode,
          })
          this.deletingAccount = false


          if(data?.status === 200){
            this.setToastMessage(data.message)
            this.logout()
          }else {
            this.setToastError(data.data.form.join(', '))
          }
          // Boolean, not 0: AjaxButton declares fetchingData as a Boolean and
          // Vue warned on every delete attempt.
          this.deletingAccount = false
        }

      },

      async updatePassword() {

        if (this.currentPassword && this.newPassword && !this.invalidPassword
          && (this.newPassword === this.confirmPassword)) {

          this.passwordSubmitting = true
          const data = await this.updateUserPassword({
            current_password: this.currentPassword,
            new_password: this.newPassword
          })
          if (data?.status === 201) {
            this.setToastError(data.data.form.join(', '))
          } else if (data?.status === 200) {
            this.loggingOut()
            this.setToastMessage(data.message)
          }
          this.passwordSubmitting = false
        } else {
          this.hasPasswordError = true
        }
      },
      async loggingOut() {
        try {
          await this.logout()
          this.emptyCartProduct()
        } catch (e) {
          return this.$nuxt.error(e)
        }
      },

      /**
       * Fill the preference boxes and company fields from the profile the
       * server returned.
       *
       * Read with `!!` rather than a truthiness fallback: the API can send
       * these as 0/1 or as booleans depending on the driver, and `|| default`
       * would silently turn a stored "off" back into the default "on".
       */
      hydrateAccount(value) {

        if (!value) {
          return
        }

        this.phoneVerified = !!value.phone_verified_at

        this.preferences = {
          pref_email_offers: !!value.pref_email_offers,
          pref_sms_offers: !!value.pref_sms_offers,
          pref_recommendations: !!value.pref_recommendations,
          pref_trade_list: !!value.pref_trade_list
        }

        const profile = value.business_profile

        if (profile) {
          this.business = {
            company_name: profile.company_name || '',
            vat_number: profile.vat_number || '',
            cro_number: profile.cro_number || '',
            billing_address: profile.billing_address || '',
            sector: profile.sector || '',
            website: profile.website || '',
            requires_po: !!profile.requires_po,
            credit_application: !!profile.credit_application
          }
        }
      },

      /**
       * Push whatever the server sends back into the store and nuxt-auth, so
       * every other screen sees the change without a reload.
       */
      applySaved(saved) {

        if (!saved) {
          return
        }

        /*
         * Drop keys the server did not actually send.
         *
         * Spreading `{account: undefined}` does not leave the existing value
         * alone - the key is present, so it overwrites.
         */
        const present = Object.keys(saved).reduce((carry, key) => {

          if (saved[key] !== undefined) {
            carry[key] = saved[key]
          }

          return carry
        }, {})

        /*
         * Merge onto the CURRENT profile, not onto $auth.user.
         *
         * $auth.user is the sign-in payload - id, name, email, token - and it
         * has never carried `account`, `business_profile` or the verification
         * flags. Rebuilding from it threw away everything the profile fetch
         * had supplied and kept only what this particular save happened to
         * echo back. Saving Preferences passes just the preferences, so
         * `account` disappeared, accountType fell back to its 'personal'
         * default, and the whole Business / Trade card - which is rendered on
         * that - vanished until the page was reloaded.
         *
         * submitProfile() next door already merges this way; these two had
         * simply drifted apart.
         */
        const updated = {...this.profile, ...present}

        this.$auth.setUser({...this.$auth.user, ...present})
        // The store action commits `params.data` (it is built for the raw
        // /profile response), so the profile goes in wrapped. Passed bare, it
        // stored undefined and every card keyed on the profile vanished.
        this.setProfile({data: updated})
        this.hydrateAccount(updated)
      },

      /**
       * The shared write path. Every section posts the whole name because the
       * endpoint requires it, and nothing else it did not put on screen — the
       * backend only writes keys it is actually sent, so a section save cannot
       * disturb a field belonging to another one.
       */
      async submitProfile(payload, {section, success, asForm = false} = {}) {

        this.profileSubmitting = true

        try {

          let body = payload

          if (asForm) {
            body = new FormData()
            Object.keys(payload).forEach(key => body.append(key, payload[key]))

            if (this.selectedImageFile) {
              body.append('image', this.selectedImageFile)
            }
          }

          const data = await this.updateProfile(body)

          if (data?.status === 200) {

            const updatedUser = {...this.$auth.user}
            updatedUser.name = data.data.name
            updatedUser.phone = data.data.phone
            updatedUser.image = data.data.image
            this.$auth.setUser(updatedUser)
            this.setProfile({data: {...this.profile, ...updatedUser}})

            this.image = data.data.image
            this.selectedImageFile = null
            this.hasProfileError = false

            if (section) {
              this.$set(this.editing, section, false)
            }

            this.setToastMessage(success || data.message)

            /*
             * A new mobile number un-confirms the number and, if the second
             * factor was SMS, switches that off — decided server-side. Re-read
             * the security panel so it cannot sit there claiming a second
             * factor that no longer exists.
             */
            if (this.$refs.twoFactor) {
              this.$refs.twoFactor.load()
            }

            return true
          }

          if (data?.status === 201) {
            this.setToastError(data.data?.form?.join(', ') || data.message)
          } else {
            this.hasError(data)
          }

          return false

        } catch (e) {
          this.setToastError(this.$t('ui.userProfile.toast.saveFailed'))
          return false

        } finally {
          this.profileSubmitting = false
        }
      },

      savePersonal() {

        if (!this.firstName) {
          this.hasProfileError = true
          return
        }

        return this.submitProfile({name: this.name}, {
          section: 'personal',
          success: this.$t('ui.userProfile.toast.detailsSaved')
        })
      },

      saveContact() {

        if (!this.phone) {
          this.hasProfileError = true
          return
        }

        /*
         * Refuse a number that is not valid for the country on the flag.
         * The server enforces uniqueness but not shape, and a malformed
         * number is one the SMS second factor can never reach.
         */
        if (!this.phoneValid) {
          this.phoneTouched = true
          return
        }

        return this.submitProfile({name: this.name, phone: this.phone}, {
          section: 'contact',
          success: this.$t('ui.userProfile.toast.contactSaved')
        })
      },

      saveAvatar() {
        return this.submitProfile({name: this.name}, {
          asForm: true,
          success: this.$t('ui.userProfile.toast.pictureUpdated')
        })
      },

      async savePreferences() {

        this.preferencesSubmitting = true

        try {

          /*
           * Name rides along because the endpoint requires it; everything
           * else is left out on purpose, so this save cannot disturb the
           * company details or the phone number.
           */
          const payload = {
            name: this.name,
            pref_email_offers: this.preferences.pref_email_offers ? 1 : 0,
            pref_sms_offers: this.preferences.pref_sms_offers ? 1 : 0,
            pref_recommendations: this.preferences.pref_recommendations ? 1 : 0
          }

          if (this.accountType === 'trade') {
            payload.pref_trade_list = this.preferences.pref_trade_list ? 1 : 0
          }

          const data = await this.updateProfile(payload)

          if (data?.status === 200) {
            this.applySaved(data.data?.preferences)
            this.setToastMessage(this.$t('ui.userProfile.toast.preferencesSaved'))
          } else {
            this.setToastError(data?.data?.form?.join(', ') || data?.message)
          }

        } catch (e) {
          this.setToastError(this.$t('ui.userProfile.toast.preferencesFailed'))
        } finally {
          this.preferencesSubmitting = false
        }
      },

      async saveBusinessDetails() {

        this.businessSubmitting = true

        try {

          const payload = {
            name: this.name,
            company_name: this.business.company_name,
            vat_number: this.business.vat_number,
            cro_number: this.business.cro_number,
            billing_address: this.business.billing_address
          }

          if (this.accountType !== 'personal') {
            payload.requires_po = this.business.requires_po ? 1 : 0
          }

          if (this.accountType === 'business') {
            payload.sector = this.business.sector

            // Only ever sent to raise an application, never to withdraw one.
            if (!this.creditLocked && this.business.credit_application) {
              payload.credit_application = 1
            }
          }

          if (this.accountType === 'trade') {
            payload.website = this.business.website
          }

          const data = await this.updateProfile(payload)

          if (data?.status === 200) {
            this.applySaved({
              account: data.data?.account,
              business_profile: data.data?.business_profile
            })
            this.$set(this.editing, 'business', false)
            this.setToastMessage(this.$t('ui.userProfile.toast.businessSaved'))
          } else {
            this.setToastError(data?.data?.form?.join(', ') || data?.message)
          }

        } catch (e) {
          this.setToastError(this.$t('ui.userProfile.toast.businessFailed'))
        } finally {
          this.businessSubmitting = false
        }
      },

      /* ── account upgrade ── */

      async fetchUpgrade() {
        try {
          const data = await this.getRequest({params: {}, api: 'accountUpgrade', requiredToken: true})

          if (data?.status === 200) {
            this.upgrade = data.data
          }
        } catch (e) {
          // Nothing to offer is the safe reading: the card stays hidden.
        }
      },

      startUpgrade(type) {

        // A business account starts from the details it already has.
        const p = this.businessProfile || {}

        this.upgradeForm = {
          company_name: p.company_name || '',
          vat_number: p.vat_number || '',
          cro_number: p.cro_number || '',
          billing_address: p.billing_address || '',
          website: p.website || '',
          sector: '',
          requires_po: false,
          credit_application: false,
          trade: '',
          expected_spend: '',
          resale_confirmed: false,
          pref_trade_list: true
        }

        this.upgradePassword = ''
        this.upgradeShowErrors = false
        this.upgradeServerErrors = {}
        this.upgradeChoice = type

        this.$nextTick(() => {
          const first = document.getElementById('up-company')

          if (first && first.focus) {
            first.focus()
          }
        })
      },

      cancelUpgrade() {
        this.upgradeChoice = null
        this.upgradePassword = ''
      },

      upgradeBad(name) {
        return !!this.upgradeServerErrors[name] ||
          (this.upgradeShowErrors && !!this.upgradeBadFields[name])
      },

      upgradeMessage(name, fallback) {
        return this.upgradeServerErrors[name] || fallback
      },

      clearUpgradeError(name) {
        if (this.upgradeServerErrors[name]) {
          this.$delete(this.upgradeServerErrors, name)
        }
      },

      async submitUpgrade() {

        this.upgradeShowErrors = true

        if (Object.keys(this.upgradeBadFields).length || this.upgradeSubmitting) {
          return
        }

        this.upgradeSubmitting = true

        try {

          const data = await this.postRequest({
            params: {
              to_type: this.upgradeChoice,
              password: this.upgradePassword,
              ...companyPayload(this.upgradeChoice, this.upgradeForm)
            },
            api: 'accountUpgrade',
            requiredToken: true
          })

          if (data?.status === 200) {

            this.upgrade = data.data
            this.applySaved({
              account: data.data?.account,
              business_profile: data.data?.business_profile
            })

            this.upgradeChoice = null
            this.upgradePassword = ''
            this.upgradeShowErrors = false

            this.setToastMessage(data.message || this.$t('ui.userProfile.toast.accountUpdated'))

            /*
             * A business upgrade changes what the basket costs. Prices are
             * worked out on every basket load, so a refresh is all it takes.
             */
            if (data.data?.account?.has_business_pricing) {
              this.getCartByUser({lang: this.langCode}).catch(() => {})
            }

            return
          }

          // Pinned to a field where the server can say which one.
          const fields = data?.data?.fields || {}
          const inline = {}

          Object.keys(fields).forEach(name => {
            const message = Array.isArray(fields[name]) ? fields[name][0] : fields[name]

            if (message) {
              inline[name] = message
            }
          })

          this.upgradeServerErrors = inline

          const shown = Object.values(inline)
          const rest = (data?.data?.form || [data?.message]).filter(m => m && !shown.includes(m))

          if (rest.length) {
            this.setToastError(rest.join(' '))
          }

        } catch (e) {
          this.setToastError(this.$t('ui.userProfile.toast.upgradeFailed'))
        } finally {
          this.upgradeSubmitting = false
        }
      },

      async withdrawUpgrade() {

        this.withdrawing = true

        try {

          const data = await this.postRequest({params: {}, api: 'accountUpgradeWithdraw', requiredToken: true})

          if (data?.status === 200) {
            this.upgrade = data.data
            this.withdrawConfirm = false
            this.setToastMessage(data.message || this.$t('ui.userProfile.toast.withdrawn'))
          } else {
            this.setToastError(data?.data?.form?.join(' ') || data?.message)
            // Decided in the meantime: show where it actually stands.
            await this.fetchUpgrade()
          }

        } catch (e) {
          this.setToastError(this.$t('ui.userProfile.toast.withdrawFailed'))
        } finally {
          this.withdrawing = false
        }
      },

      pendingDetail(name) {
        return this.upgradePending?.details?.[name] || '—'
      },

      upgradeDate(iso) {
        const date = iso ? new Date(iso) : null

        return date && !isNaN(date)
          ? date.toLocaleDateString('en-IE', {day: 'numeric', month: 'long', year: 'numeric'})
          : this.$t('ui.userProfile.recently')
      },

      onTwoFactorChanged({enabled}) {
        this.twoFactorOn = enabled
      },

      ...mapActions('cart', ['emptyCartProduct', 'getCartByUser']),
      ...mapActions('resource', ['setCountryList', 'setPhoneList']),
      ...mapActions('common', [
        'setToastMessage', 'setToastError', 'deleteRequest', 'getRequest', 'postRequest'
      ]),
      ...mapActions('user', [
        'updateProfile', 'updateUserPassword', 'logout', 'setProfile',
        // Both needed by fetchAddresses: the list call is scoped to the token.
        'userAddressAll', 'getUserToken'
      ])
    },
    async mounted() {
      try {
        if (this.profile) {
          this.email = this.profile?.email
          this.name = this.profile?.name
          this.phone = this.profile?.phone
          this.image = this.profile?.image
          this.hydrateAccount(this.profile)
        }

        await Promise.all([
          this.fetchSessions(),
          this.fetchAddresses(),
          this.fetchCountries(),
          this.fetchUpgrade()
        ])

      } catch (e) {
        console.error('Profile load failed:', e)
      } finally {
        // In finally, so a failed session lookup still reveals the form.
        this.fetchingProfileData = false
        this.scrollToHashSection()
      }
    },
    beforeDestroy() {
      clearTimeout(this.flashTimer)
    },
  }
</script>

<style scoped>

/*
 * One place for the account page's scale and palette.
 *
 * Every value below is the one already used elsewhere in the account area
 * (AccountLayout's sidebar, the dashboard's status panels) — this only stops
 * each card re-deciding them, which is what left cards on this page with
 * three different radii and four different greys.
 */
.acct-page {
    --brand: #33319A;
    /*
     * Green on hover is this application's signature on a primary action —
     * the same pair the Orders "Track package" and the old profile Save
     * button use. Kept rather than replaced with a darker purple.
     */
    --brand-hover: #05B942;
    --ink: #130E2B;
    --body: #374151;
    --muted: #6B7280;
    --line: #E3E3EF;
    --line-soft: #ECECF3;
    --soft: #F4F4FA;
    --head: #F3F3FA;
    --danger: #E5484D;

    /* 14px cards and 8px controls, matching the other account pages. */
    --r-card: 14px;
    --r-ctl: 8px;

    --s-1: 4px;
    --s-2: 8px;
    --s-3: 12px;
    --s-4: 16px;
    --s-5: 20px;
    --s-6: 24px;
    --s-7: 32px;

    /*
     * PhoneField was built for the sign-up screens and reads --auth-* for its
     * palette, falling back to that page's 14px radius and #2D2DA0 blue. Every
     * one of those is a custom property, and custom properties inherit through
     * a component boundary — so mapping them here dresses the shared control
     * in the account palette without forking the component.
     */
    --auth-brand: #33319A;
    --auth-brand-50: #F5F5FF;
    --auth-ink: #130E2B;
    --auth-muted: #6B7280;
    --auth-line: #ECECF3;
    --auth-line-strong: #E3E3EF;
    --auth-wash: #F4F4FA;
    --auth-danger: #E5484D;
    --auth-radius: 8px;

    font-family: 'Inter', 'Noto Sans', sans-serif;
    color: var(--body);
    /*
     * Fills the column like the dashboard. The 960px cap left a strip of
     * empty page on the right of every desktop.
     */
    max-width: none;

    /* spacing that scales with the screen instead of jumping at 768px */
    --s-5: clamp(16px, .85rem + .5vw, 20px);
    --s-6: clamp(20px, 1rem + .6vw, 24px);
}

.sr-only {
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

/* ── page head ── */

.acct-page-head {
    margin-bottom: var(--s-6);
}

.acct-page-title {
    font-size: clamp(22px, 1.1rem + 1.2vw, 30px);
    line-height: 1.25;
    font-weight: 700;
    color: var(--ink);
    margin: 0 0 var(--s-1);
}

.acct-page-lede {
    font-size: 15px;
    line-height: 1.5;
    color: var(--muted);
    margin: 0;
}

/* ── card shell ── */

.acct-stack {
    display: flex;
    flex-direction: column;
    gap: var(--s-5);
}

.acct-card {
    background: #fff;
    border: 1px solid var(--line);
    border-radius: var(--r-card);
    overflow: hidden;
}

.acct-card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--s-3);
    flex-wrap: wrap;
    padding: var(--s-4) var(--s-5);
    background: var(--head);
    border-bottom: 1px solid var(--line);
}

.acct-card-title {
    font-size: clamp(16px, .9rem + .3vw, 18px);
    line-height: 1.35;
    font-weight: 600;
    color: var(--ink);
    margin: 0;
}

.acct-card-body {
    padding: var(--s-5);
}

.acct-sub-title {
    font-size: 16px;
    line-height: 1.4;
    font-weight: 600;
    color: var(--ink);
    margin: 0;
}

.acct-rule {
    border: 0;
    border-top: 1px solid var(--line-soft);
    margin: var(--s-5) 0;
}

/* ── overview ── */

.acct-overview .acct-ov-identity {
    display: flex;
    align-items: center;
    gap: var(--s-5);
    padding: var(--s-5);
}

.acct-ov-avatar {
    position: relative;
    flex: 0 0 auto;
}

.acct-avatar {
    width: 88px;
    height: 88px;
    border-radius: 50%;
    object-fit: cover;
    display: block;
    background: var(--soft);
}

.acct-avatar-edit {
    position: absolute;
    right: -2px;
    bottom: -2px;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: 2px solid #fff;
    background: var(--brand);
    color: #fff;
    font-size: 12px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}

.acct-avatar-edit:hover {
    background: var(--brand-hover);
}

.acct-avatar-edit:focus-visible {
    outline: 2px solid var(--brand);
    outline-offset: 2px;
}

.acct-ov-text {
    min-width: 0;
}

.acct-ov-name {
    font-size: clamp(18px, 1rem + .5vw, 22px);
    line-height: 1.3;
    font-weight: 600;
    color: var(--ink);
    margin: 0 0 var(--s-1);
    overflow-wrap: anywhere;
}

.acct-ov-email {
    font-size: 14px;
    line-height: 1.5;
    color: var(--muted);
    margin: 0 0 var(--s-3);
    overflow-wrap: anywhere;
}

.acct-ov-badges {
    display: flex;
    flex-wrap: wrap;
    gap: var(--s-2);
}

.acct-ov-avatar-save {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: var(--s-3);
    padding: var(--s-4) var(--s-5);
    border-top: 1px solid var(--line-soft);
    background: #FAFAFD;
}

/* ── status pills ── */

.acct-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    line-height: 1.4;
    font-weight: 600;
    padding: 4px 10px;
    border-radius: 999px;
    background: #F3F4F6;
    color: #374151;
    white-space: nowrap;
}

.acct-pill-sm {
    font-size: 11px;
    padding: 2px 8px;
}

.acct-pill-good { background: #ECFDF5; color: #047857; }
.acct-pill-wait { background: #FFFBEB; color: #B45309; }
.acct-pill-bad  { background: #FEF2F2; color: #B91C1C; }
.acct-pill-idle { background: #F3F4F6; color: #4B5563; }
.acct-pill-brand { background: #EEF0FB; color: var(--brand); }

.acct-pill-personal { background: #F3F4F6; color: #374151; }
.acct-pill-business { background: #EFF6FF; color: #1D4ED8; }
.acct-pill-trade    { background: #F5F3FF; color: #6D28D9; }

/* ── field grid ── */

.acct-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--s-5);
    margin: 0;
}

.acct-grid-3 {
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.acct-field {
    min-width: 0;
    margin: 0;
}

.acct-field-wide {
    grid-column: 1 / -1;
}

.acct-label {
    display: block;
    font-size: 13px;
    line-height: 1.45;
    font-weight: 600;
    color: var(--muted);
    margin: 0 0 var(--s-2);
}

.acct-req {
    color: var(--danger);
}

.acct-value {
    font-size: 15px;
    line-height: 1.55;
    color: var(--ink);
    margin: 0;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--s-2);
}

.acct-value-break {
    overflow-wrap: anywhere;
}

.acct-value-pre {
    white-space: pre-line;
    display: block;
}

/*
 * The flag beside the stored number. flag-icons draws a background image at
 * a fixed 4:3 ratio, so it needs an explicit size and must not be squashed
 * by the flex row it sits in.
 */
.acct-flag {
    flex: 0 0 auto;
    width: 21px;
    height: 16px;
    border-radius: 2px;
    box-shadow: 0 0 0 1px rgba(19, 14, 43, .10);
}

.acct-inline-note {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 12px;
    font-weight: 500;
    color: var(--muted);
}

.acct-help {
    font-size: 13px;
    line-height: 1.5;
    color: var(--muted);
    margin: var(--s-2) 0 0;
}

.acct-error {
    font-size: 13px;
    line-height: 1.5;
    font-weight: 500;
    color: var(--danger);
    margin: var(--s-2) 0 0;
}

.acct-headline {
    font-size: 15px;
    line-height: 1.6;
    color: var(--body);
    margin: 0 0 var(--s-5);
}

/* ── account upgrade ── */

.acct-upgrade-options {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: var(--s-4);
}

.acct-upgrade-option {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: var(--s-3);
    padding: var(--s-5);
    border: 1px solid var(--line);
    border-radius: var(--r-card);
    background: #fff;
}

.acct-upgrade-option .acct-btn {
    margin-top: auto;
}

.acct-upgrade-lede {
    margin: 0;
    font-size: 15px;
    font-weight: 600;
    color: var(--ink);
}

.acct-upgrade-points {
    margin: 0 0 var(--s-2);
    padding-left: 18px;
    font-size: 14px;
    line-height: 1.6;
    color: var(--body);
}

/* base.styl makes every li an unmarked inline-block. */
.acct-upgrade-points li {
    display: list-item;
    list-style: disc;
    line-height: 1.6;
}

.acct-upgrade-intro {
    margin: 0;
}

.acct-upgrade-withdraw {
    flex-wrap: wrap;
    align-items: center;
    margin-top: var(--s-5);
}

.acct-upgrade-confirm {
    flex-basis: 100%;
    margin: 0;
}

.acct-note {
    font-size: 14px;
    line-height: 1.6;
    color: var(--body);
    background: #F9FAFB;
    border-radius: var(--r-ctl);
    padding: var(--s-3) var(--s-4);
    margin: var(--s-4) 0 0;
}

.acct-locked {
    margin-bottom: var(--s-3);
}

/*
 * Deliberately not display:flex.
 *
 * These notes end in a sentence containing a link, and as a flex container
 * the <a> became a flex item: it could not flow with the words around it, so
 * on a narrow screen the sentence broke into ragged columns with "Contact us"
 * stranded on its own line. Block flow with an inline icon keeps it a
 * sentence at every width.
 */
.acct-locked-note {
    font-size: 13px;
    line-height: 1.5;
    color: var(--muted);
    margin: var(--s-3) 0 0;
}

.acct-locked-note > i {
    margin-right: 6px;
}

.acct-locked-note a {
    color: var(--brand);
    font-weight: 500;
}

/* ── controls ── */

/* 48px to the pixel, because PhoneField is 48px and the two sit side by side. */
.acct-input {
    width: 100%;
    min-height: 48px;
    border: 1px solid var(--line);
    border-radius: var(--r-ctl);
    padding: 11px 14px;
    font-size: 15px;
    line-height: 1.5;
    color: var(--ink);
    background: #fff;
    transition: border-color .15s, box-shadow .15s;
}

.acct-input:hover:not(:disabled) {
    border-color: #C9C9DE;
}

.acct-input:focus {
    outline: none;
    border-color: var(--brand);
    box-shadow: 0 0 0 3px rgba(51, 49, 154, .14);
}

.acct-input:disabled {
    background: var(--soft);
    color: var(--muted);
    cursor: not-allowed;
}

.acct-input.is-bad {
    border-color: var(--danger);
}

.acct-input.is-bad:focus {
    box-shadow: 0 0 0 3px rgba(229, 72, 77, .16);
}

/* Four lines, because a street/town/city/postcode address is four lines. */
.acct-textarea {
    resize: vertical;
    min-height: 112px;
    line-height: 1.5;
}

.acct-password-mask {
    letter-spacing: 3px;
    font-size: 18px;
    color: var(--ink);
    margin: 0;
}

/* ── checkbox rows ── */

.acct-prefs {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: var(--s-2);
}

.acct-check {
    display: flex;
    align-items: flex-start;
    gap: var(--s-3);
    /* The whole row is the target, not just the 18px box. */
    padding: var(--s-3);
    margin: 0;
    border-radius: var(--r-ctl);
    cursor: pointer;
    transition: background .15s;
}

.acct-check:hover {
    background: var(--soft);
}

.acct-check.is-locked {
    cursor: not-allowed;
    opacity: .75;
}

.acct-check-box {
    flex: 0 0 auto;
    width: 18px;
    height: 18px;
    margin-top: 2px;
    accent-color: var(--brand);
    cursor: pointer;
}

.acct-check-box:focus-visible {
    outline: 2px solid var(--brand);
    outline-offset: 2px;
}

.acct-check-text {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.acct-check-title {
    font-size: 15px;
    line-height: 1.5;
    font-weight: 500;
    color: var(--ink);
}

.acct-check-text .acct-help {
    margin-top: 2px;
}

.acct-prefs-note {
    margin-top: var(--s-4);
}

/* ── buttons ── */

.acct-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: var(--s-3);
    flex-wrap: wrap;
}

.acct-actions-start {
    justify-content: flex-start;
    margin-top: var(--s-5);
}

/*
 * One button size for the page.
 *
 * AjaxButton's own root element is the <button>, and nuxt-link's is the <a>,
 * so .acct-btn always lands on the real control — no deep selectors needed,
 * and every action on the page shares one height, radius and weight.
 */
.acct-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    /*
     * height, not only min-height: base.styl gives every button
     * height: 50px / line-height: 48px, which made each card's "Edit" 50px
     * tall and the grey header bands twice the height they need.
     */
    height: 40px;
    min-height: 40px;
    padding: 0 18px;
    border-radius: var(--r-ctl);
    border: 1px solid transparent;
    font-family: inherit;
    font-size: 14px;
    font-weight: 500;
    line-height: 1.2;
    text-decoration: none;
    cursor: pointer;
    transition: background .15s ease, border-color .15s ease, color .15s ease;
    white-space: nowrap;
}

.acct-btn-sm {
    height: 34px;
    min-height: 34px;
    padding: 0 12px;
    font-size: 13.5px;
}

.acct-btn-primary {
    background: var(--brand);
    border-color: var(--brand);
    color: #fff;
}

.acct-btn-primary:hover {
    background: var(--brand-hover) !important; /* base.styl button:hover outranks this otherwise */
    border-color: var(--brand-hover);
    color: #fff;
}

.acct-btn-ghost {
    background: #fff;
    border-color: var(--line);
    color: var(--ink);
}

.acct-btn-ghost:hover {
    background: #F5F5FF !important; /* base.styl button:hover outranks this otherwise */
    border-color: var(--brand);
    color: var(--brand);
}

.acct-btn-danger {
    background: var(--danger);
    color: #fff;
}

.acct-btn-danger:hover {
    background: #C93B40 !important; /* base.styl button:hover outranks this otherwise */
    color: #fff;
}

.acct-btn-danger-ghost {
    background: #fff;
    border-color: #F3C9CA;
    color: var(--danger);
}

.acct-btn-danger-ghost:hover {
    background: #FDECEC !important; /* base.styl button:hover outranks this otherwise */
    color: var(--danger);
}

.acct-btn:focus-visible {
    outline: 2px solid var(--brand);
    outline-offset: 2px;
}

/*
 * AjaxButton disables itself while its request is in flight, so this is what
 * stops a second submit — and the min-height above means swapping the label
 * for a spinner does not change the button's size.
 */
.acct-btn:disabled {
    opacity: .6;
    cursor: not-allowed;
}

/* ── addresses ── */

.acct-addr-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--s-4);
}

.acct-addr {
    border: 1px solid var(--line);
    border-radius: var(--r-ctl);
    padding: var(--s-4);
    min-width: 0;
}

.acct-addr-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--s-2);
    margin-bottom: var(--s-2);
}

.acct-addr-type {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    font-size: 14px;
    font-weight: 600;
    color: var(--ink);
}

.acct-addr-name {
    font-size: 14px;
    font-weight: 500;
    color: var(--ink);
    margin: 0 0 2px;
    overflow-wrap: anywhere;
}

.acct-addr-lines,
.acct-addr-phone {
    font-size: 13px;
    line-height: 1.55;
    color: var(--muted);
    margin: 0;
    overflow-wrap: anywhere;
}

.acct-empty {
    text-align: center;
    padding: var(--s-6) var(--s-4);
    border: 1px dashed var(--line);
    border-radius: var(--r-ctl);
}

.acct-empty-ico {
    font-size: 22px;
    color: #A6A6C2;
}

.acct-empty-title {
    font-size: 15px;
    font-weight: 600;
    color: var(--ink);
    margin: var(--s-3) 0 0;
}

.acct-empty .acct-btn {
    margin-top: var(--s-4);
}

/* ── security ── */

.acct-sec-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--s-5);
    /*
     * stretch, not start: the two-factor panel carries its own border, so a
     * bare password column beside it read as a gap rather than a pair. Both
     * are panels now, and both fill the row.
     */
    align-items: stretch;
}

.acct-sec-block {
    min-width: 0;
}

.acct-sec-panel {
    border: 1px solid var(--line);
    border-radius: 10px;
    padding: 16px;
}

.acct-sec-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--s-3);
    flex-wrap: wrap;
    margin-bottom: var(--s-3);
}

.acct-sec-block .acct-field + .acct-field {
    margin-top: var(--s-4);
}

/* ── sessions ── */

.acct-sessions {
    list-style: none;
    margin: var(--s-4) 0 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: var(--s-2);
}

.acct-session {
    display: flex;
    align-items: center;
    gap: var(--s-3);
    padding: var(--s-3) var(--s-4);
    border: 1px solid var(--line);
    border-radius: var(--r-ctl);
    min-width: 0;
}

.acct-session.is-current {
    border-color: #C7C6E6;
    background: #FAFAFD;
}

.acct-session-ico {
    flex: 0 0 36px;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: var(--soft);
    color: var(--muted);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
}

.acct-session-text {
    display: flex;
    flex-direction: column;
    min-width: 0;
    flex: 1 1 auto;
}

.acct-session-title {
    font-weight: 600;
    color: var(--ink);
}

.acct-session .acct-btn { flex: 0 0 auto; }

.acct-sessions-more {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    height: 36px;
    line-height: 1;
    margin-top: var(--s-3);
    padding: 0 4px;
    border: 0;
    background: transparent;
    color: var(--brand);
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
}

.acct-sessions-more:hover {
    background: transparent !important;
    text-decoration: underline;
}

.acct-sessions-more i { font-size: 11px; }

.acct-session-name {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--s-2);
    font-size: 15px;
    font-weight: 500;
    color: var(--ink);
    overflow-wrap: anywhere;
}

.acct-session-text .acct-help {
    margin-top: 2px;
}

/* ── danger zone ── */

.acct-card-danger {
    border-color: #F3C9CA;
}

.acct-danger {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--s-4);
    flex-wrap: wrap;
}

.acct-danger-title {
    color: var(--danger);
    margin-bottom: var(--s-1);
}

/* ══════════ TABLET ══════════ */

@media (max-width: 1024px) {

    .acct-grid-3 {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 991.98px) {

    /*
     * The account sidebar becomes a drawer at this width, so the content
     * column owns the full row and no longer needs its desktop ceiling.
     */
    .acct-page {
        max-width: none;
    }

    .acct-sec-grid {
        grid-template-columns: minmax(0, 1fr);
    }
}

/* ══════════ MOBILE ══════════ */

/*
 * Targets of /user/profile#password and #preferences. The room left for the
 * sticky header is measured in scrollToHashSection(), not set here.
 */
.acct-anchor {
    transition: box-shadow .3s ease;
}

.acct-anchor-flash {
    box-shadow: 0 0 0 3px rgba(51, 49, 154, .35);
}


@media (max-width: 767.98px) {

    .acct-page {
        --s-7: 24px;
    }

    .acct-page-lede {
        font-size: 14px;
    }


    /*
     * Short read-only pairs (first/last name, account type/status) stay side
     * by side: stacking two one-word values doubled their height for nothing.
     */
    .acct-grid.acct-grid-pair {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    /* Everything two-up becomes one column; nothing is squeezed side by side. */
    .acct-grid,
    .acct-grid-3,
    .acct-addr-list {
        grid-template-columns: minmax(0, 1fr);
        gap: var(--s-4);
    }

    .acct-card-head,
    .acct-card-body,
    .acct-overview .acct-ov-identity,
    .acct-ov-avatar-save {
        padding: var(--s-4);
    }

    /* avatar beside the name, not a 72px block above it */
    .acct-overview .acct-ov-identity {
        gap: var(--s-4);
    }

    .acct-avatar {
        width: 64px;
        height: 64px;
    }

    .acct-avatar-edit {
        width: 28px;
        height: 28px;
        font-size: 11px;
    }

    .acct-ov-email { margin-bottom: var(--s-2); }

    /* grey header bands: tighter, the Edit button compact */
    .acct-card-head {
        padding-top: 12px;
        padding-bottom: 12px;
    }

    /*
     * Full-width, thumb-sized primary actions — and Cancel below Save rather
     * than beside it, so neither ends up 40px wide.
     */
    .acct-actions {
        flex-direction: column-reverse;
        align-items: stretch;
        width: 100%;
    }

    .acct-actions .acct-btn {
        width: 100%;
        min-height: 46px;
    }

    /* The small Edit buttons in card headers stay inline. */
    .acct-card-head .acct-btn,
    .acct-sec-head .acct-btn {
        width: auto;
    }

    /* one line per device: the button stays on the right, not on its own row */
    .acct-session {
        padding: 10px 12px;
        gap: 10px;
    }

    .acct-session-ico {
        flex-basis: 32px;
        width: 32px;
        height: 32px;
        font-size: 13px;
    }

    .acct-session-name,
    .acct-session-title {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .acct-session .acct-btn {
        height: 32px;
        min-height: 32px;
        padding: 0 10px;
        font-size: 13px;
    }

    /* "Sign out everywhere else" under the heading, full width */
    .acct-sec-head .acct-btn-danger-ghost {
        width: 100%;
    }

    .acct-danger {
        flex-direction: column;
        align-items: stretch;
    }

    .acct-danger .acct-btn {
        width: 100%;
    }
}

/*
 * Very small phones (320px). Only the things that actually run out of room
 * are touched, rather than another whole set of overrides.
 */
@media (max-width: 359.98px) {

    .acct-card-head,
    .acct-card-body,
    .acct-overview .acct-ov-identity {
        padding: var(--s-3);
    }

    .acct-session-ico {
        display: none;
    }
}

@media (prefers-reduced-motion: reduce) {

    .acct-btn,
    .acct-input,
    .acct-check {
        transition: none;
    }
}

</style>
