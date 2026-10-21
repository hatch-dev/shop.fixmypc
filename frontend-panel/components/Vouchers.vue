<template>

  <div class="container-fluid">

    <!--
      The spinner used to render above the page rather than instead of it, so
      the voucher cards, the wallet figures and the empty states were all on
      screen while the three requests behind them were still in flight.
    -->
    <account-skeleton
      v-if="fetchingVoucherData"
      :cards="3"
      :tiles="4"
      heading
      :label="$t('ui.vouchersPanel.loading')"
    />

    <template v-else>

    <div class="card-box p-4 mb-4 d-flex justify-content-between align-items-center">
        <div>
            <h5 class="mb-1 order-hrading">{{ $t('ui.vouchersPanel.title') }}</h5>
            <small class="text-muted">{{ $t('ui.vouchersPanel.subtitle') }}</small>
        </div>
    </div>

    <div class="row g-3 mb-4 voucher-stats">
      <div class="col-4">
          <div class="voucher-stat position-relative">
              <div class="stat-icon"><i class="fa-solid fa-ticket"
                      style="color: rgb(82, 90, 107);"></i></div>
              <small>{{ $t('ui.vouchersPanel.stats.total') }}</small>
              <h5 class="total-vouchers">{{ totalVouchers }}</h5>
              <span class="text-muted small">{{ $t('ui.vouchersPanel.stats.totalSub') }}</span>
          </div>
      </div>
      <div class="col-4">
          <div class="voucher-stat position-relative">
              <div class="stat-icon"><i class="fa-solid fa-circle-check"
                      style="color: rgb(82, 90, 107);"></i></div>

              <small>{{ $t('ui.vouchersPanel.stats.active') }}</small>
              <h5 class="total-vouchers">{{ activeVouchers }}</h5>
              <span class="text-muted small">{{ $t('ui.vouchersPanel.stats.activeSub') }}</span>
          </div>
      </div>
      <div class="col-4">
          <div class="voucher-stat position-relative">
              <div class="stat-icon"><i class="fa-solid fa-euro-sign"
                      style="color: rgb(82, 90, 107);"></i></div>

              <small>{{ $t('ui.vouchersPanel.stats.saved') }}</small>
              <!-- null until /user/savings answers; a dash rather than a guess -->
              <h5 class="total-vouchers">{{ totalSaved === null ? '—' : currencyIcon + totalSaved }}</h5>
              <span class="text-muted small">{{ $t('ui.vouchersPanel.stats.savedSub') }}</span>
          </div>
      </div>
    </div>

    <!--
      Redeem a gift card. Was "Apply Voucher Code": an input bound to nothing
      and a button with no handler. A code, bought here or received, becomes
      a voucher in the list below, applied at checkout; what an order does not
      use stays on it for the next one.
    -->
    <form class="card-box voucher-box p-4 gift-redeem" @submit.prevent="redeemGift(redeemCode)">
        <div class="gift-redeem-head">
          <span class="gift-redeem-icon" aria-hidden="true"><i class="fa-solid fa-gift"></i></span>
          <div>
            <h6 class="mb-1">{{ $t('ui.vouchersPanel.redeem.title') }}</h6>
            <small class="text-muted d-block">
              {{ $t('ui.vouchersPanel.redeem.help') }}
            </small>
          </div>
        </div>
        <div class="voucher-form mb-0 mt-3">
            <label for="gift-redeem" class="sr-only">{{ $t('ui.vouchersPanel.redeem.codeLabel') }}</label>
            <input
              id="gift-redeem"
              v-model.trim="redeemCode"
              type="text"
              class="voucher-input gift-code-input"
              placeholder="GIFT-XXXX-XXXX"
              autocomplete="off"
              autocapitalize="characters"
              spellcheck="false"
              maxlength="20"
            >
            <button type="submit" class="apply-btn" :disabled="!redeemCode || redeeming">
              {{ redeeming ? $t('ui.vouchersPanel.redeem.adding') : $t('ui.vouchersPanel.redeem.add') }}
            </button>
        </div>
    </form>
    
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3 mb-2 voucher-list-head">
        <h6 class="mb-0">
          {{ filterType === 'active' ? $t('ui.vouchersPanel.list.active') :
            filterType === 'expired' ? $t('ui.vouchersPanel.list.expired') :
            $t('ui.vouchersPanel.list.all') }}
        </h6>

        <div class="d-flex align-items-center gap-2">
            <small class="text-muted">{{ $t('ui.vouchersPanel.sort.label') }}</small>
            <select v-model="sortBy" class="sort-select" :disabled="filterType === 'expired'">
              <option value="high_discount">{{ $t('ui.vouchersPanel.sort.highDiscount') }}</option>
              <option value="low_discount">{{ $t('ui.vouchersPanel.sort.lowDiscount') }}</option>
              <option value="expiring_soon">{{ $t('ui.vouchersPanel.sort.expiringSoon') }}</option>
              <option value="best_value">{{ $t('ui.vouchersPanel.sort.bestValue') }}</option>
            </select>
        </div>
    </div>
    <div v-if="filterType !== 'expired' && !filteredActiveList.length" class="text-muted mt-3">
      {{ $t('ui.vouchersPanel.noActive') }}
    </div>
    <div v-if="filterType !== 'expired'">
        <div v-for="item in filteredActiveList" :key="item.id" class="voucher-card mt-3">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                <div>
                    <div class="d-flex gap-2">
                        <h6 class="off-next-purchase">{{ item.title }}</h6>
                        <span class="btn-active" v-if="getDaysLeftNumber(item) > 0">
                          {{ $t('ui.vouchersPanel.badgeActive') }}
                        </span>
                    </div>
                    <p v-if="item.is_gift_card" class="text-muted small mt-1 mb-3">
                        {{ $t('ui.vouchersPanel.giftCardDesc', { balance: currencyIcon + item.gift_balance, value: currencyIcon + Number(item.gift_value).toFixed(2) }) }}
                    </p>
                    <p v-else-if="item.user_id" class="text-muted small mt-1 mb-3">
                        {{ $t('ui.vouchersPanel.giftVoucherDesc') }}
                    </p>
                    <p v-else class="text-muted small mt-1 mb-3">
                        {{ $t('ui.vouchersPanel.minSpendDesc', { amount: currencyIcon + item.min_spend }) }}
                    </p>
                    <div class="d-flex gap-5 flex-wrap expire-boxed voucher-meta">
                        <div>
                            <small class="text-muted">{{ $t('ui.vouchersPanel.code') }}</small><br>
                            <div class="code-box">
                                {{ item.code }}
                              </div>
                              <span 
                                class="copy-icon-wrapper"
                                @click="copyCode(item)"
                              >
                                <i class="fa-solid fa-copy"></i>

                                <span class="tooltip-text">
                                  {{ copiedVouchedId === item.id ? $t('ui.vouchersPanel.copiedBang') : $t('ui.vouchersPanel.copy') }}
                                </span>
                              </span>
                        </div>
                        <div v-if="item.is_gift_card">
                            <small class="text-muted">{{ $t('ui.vouchersPanel.balance') }}</small><br>
                            <strong>{{ currencyIcon }}{{ item.gift_balance }}</strong>
                        </div>
                        <div v-else>
                            <small class="text-muted">{{ $t('ui.vouchersPanel.discount') }}</small><br>
                            <strong>{{ item.type === 2 ? item.price + '%' : currencyIcon + item.price }}</strong>
                        </div>
                        <div>
                            <small class="text-muted">{{ $t('ui.vouchersPanel.expires') }}</small><br>
                            <strong>{{ item.end_time }}</strong>
                        </div>
                    </div>
                </div>
                <button class="btn-use-now" @click="goToCart">{{ $t('ui.vouchersPanel.useNow') }}</button>
            </div>

            <div class="voucher-progress-wrapper mt-3">
                <div class="voucher-progress">
                    <div class="voucher-fill" :style="{ width: getProgress(item) + '%', background: getProgressColor(item) }"></div>
                </div>
                <span class="days-left">{{ getDaysLeft(item) }}</span>
            </div>
        </div>
    </div>
    <div class="mt-3" v-if="filterType !== 'active'">
      <h6>{{ $t('ui.vouchersPanel.list.expired') }}</h6>
    </div>
    <div v-if="filterType !== 'active' && !expiredList.length" class="text-muted mt-3">
      {{ $t('ui.vouchersPanel.noExpired') }}
    </div>
    <div v-if="filterType !== 'active'" v-for="item in expiredList" :key="item.id" class="voucher-card expired-voucher mt-3">
      <div class="d-flex justify-content-between align-items-start flex-wrap">
          <div>
              <strong>{{ item.title }}</strong>
              <span class="badge-expired ms-2">{{ isUsedUp(item) ? $t('ui.vouchersPanel.badgeUsed') : $t('ui.vouchersPanel.badgeExpired') }}</span>
              <!-- was the same "Welcome discount for new customers" text on every expired voucher -->
              <p v-if="isUsedUp(item)" class="text-muted small mt-1 mb-3">
                  {{ $t('ui.vouchersPanel.usedUpDesc', { value: currencyIcon + Number(item.gift_value).toFixed(2) }) }}
              </p>
              <p v-else class="text-muted small mt-1 mb-3">
                  {{ $t('ui.vouchersPanel.wasValidDesc', { amount: currencyIcon + item.min_spend }) }}
              </p>
              <div class="d-flex gap-5 flex-wrap expire-boxed voucher-meta">
                  <div>
                      <small class="text-muted">{{ $t('ui.vouchersPanel.code') }}</small><br>
                      <div class="code-box">{{ item.code }}</div>
                  </div>
                  <div>
                      <small class="text-muted">{{ $t('ui.vouchersPanel.discount') }}</small><br>
                      <strong>{{ item.type === 2 ? item.price + '%' : currencyIcon + item.price }}</strong>
                  </div>
                  <div>
                      <small class="text-muted">{{ isUsedUp(item) ? $t('ui.vouchersPanel.validUntil') : $t('ui.vouchersPanel.expiredOn') }}</small><br>
                      <strong>{{ item.end_time }}</strong>
                  </div>
              </div>
          </div>
          <button class="btn btn-light btn-sm" disabled>{{ isUsedUp(item) ? $t('ui.vouchersPanel.used') : $t('ui.vouchersPanel.expired') }}</button>
      </div>
    </div>

    <!--
      Every gift card bought, one code each: who it went to, and whether it
      has been used. A card for yourself can be added to your vouchers here.
    -->
    <section v-if="giftCodes.length" ref="giftCodesSection" class="card-box p-4 mt-4 gift-codes">
      <div class="gift-codes-head">
        <div>
          <h6 class="mb-1">{{ $t('ui.vouchersPanel.giftCodes.title') }}</h6>
          <small class="text-muted">
            {{ $t('ui.vouchersPanel.giftCodes.sub') }}
          </small>
        </div>
        <span class="gift-codes-count">{{ giftCodes.length }}</span>
      </div>

      <ul class="gift-code-list">
        <li v-for="gc in giftCodes" :key="gc.id" class="gift-code" :class="`is-${gc.state}`">
          <div class="gift-code-value">{{ currencyIcon }}{{ gc.amount }}</div>

          <div class="gift-code-main">
            <div class="gift-code-row">
              <span class="gift-code-text">{{ gc.code }}</span>
              <button
                v-if="gc.state === 'available'"
                type="button"
                class="gift-code-copy"
                :aria-label="$t('ui.vouchersPanel.giftCodes.copyCode', { code: gc.code })"
                @click="copyText(gc.code, gc.id)"
              >
                <i class="fa-regular" :class="copiedGiftId === gc.id ? 'fa-circle-check' : 'fa-copy'" aria-hidden="true"></i>
                {{ copiedGiftId === gc.id ? $t('ui.vouchersPanel.copied') : $t('ui.vouchersPanel.copy') }}
              </button>
            </div>
            <span class="gift-code-meta">
              {{ gc.title }} · {{ $t('ui.vouchersPanel.giftCodes.bought', { date: shortDate(gc.purchased_at) }) }}
              <template v-if="gc.state === 'available' && gc.expires_at"> · {{ $t('ui.vouchersPanel.giftCodes.validUntil', { date: shortDate(gc.expires_at) }) }}</template>
            </span>
            <span v-if="gc.recipient" class="gift-code-meta">
              <i class="fa-regular fa-envelope" aria-hidden="true"></i>
              {{ $t('ui.vouchersPanel.giftCodes.sentTo', { name: gc.recipient }) }}
            </span>
          </div>

          <div class="gift-code-side">
            <span class="gift-code-pill" :class="`pill-${gc.state}`">{{ giftStateLabel(gc) }}</span>
            <button
              v-if="gc.state === 'available' && !gc.recipient"
              type="button"
              class="gift-code-add"
              :disabled="redeeming"
              @click="redeemGift(gc.code)"
            >
              {{ $t('ui.vouchersPanel.redeem.add') }}
            </button>
          </div>
        </li>
      </ul>
    </section>

    <!--
      Gift card builder. One card preview and one set of steps, for whichever
      design is picked: it used to be a grid of identical mini-forms, one per
      design, each with its own value, quantity, gift and payment controls.
      Purchase logic is unchanged (purchaseVoucher / giftFor / paymentMethod,
      all keyed by the design id).
    -->
    <section v-if="giftVouchers.length && activeDesign" class="card-box gb mt-4 mb-4">
      <div class="gb-head">
        <h6 class="gb-title">{{ $t('ui.vouchersPanel.builder.title') }}</h6>
        <p class="gb-sub">
          {{ $t('ui.vouchersPanel.builder.sub') }}
        </p>
      </div>

      <div class="gb-body">

        <!-- ── the card ── -->
        <div class="gb-visual">
          <div class="gb-card" :class="`gb-theme-${themeIndex}`" aria-hidden="true">
            <div class="gb-card-top">
              <span class="gb-card-brand">FixMyPC</span>
              <span class="gb-card-kind">{{ $t('ui.vouchersPanel.builder.cardKind') }}</span>
            </div>
            <div class="gb-card-value">
              <span v-if="gbAmount">{{ currencyIcon }}{{ formatMoney(gbAmount) }}</span>
              <span v-else class="gb-card-placeholder">{{ currencyIcon }}—</span>
            </div>
            <div class="gb-card-bottom">
              <span class="gb-card-for">
                {{ gbGift.on ? (gbGift.name ? $t('ui.vouchersPanel.builder.forName', { name: gbGift.name }) : $t('ui.vouchersPanel.builder.forSomeone')) : $t('ui.vouchersPanel.builder.forYou') }}
              </span>
              <span class="gb-card-design">{{ activeDesign.title }}</span>
            </div>
            <i class="fa-solid fa-gift gb-card-mark"></i>
          </div>

          <p v-if="gbGift.on && gbGift.message" class="gb-card-message">
            “{{ gbGift.message }}”
          </p>

          <!-- design picker, only when there is a choice -->
          <div v-if="giftVouchers.length > 1" class="gb-designs" role="radiogroup" :aria-label="$t('ui.vouchersPanel.builder.design')">
            <button
              v-for="(design, i) in giftVouchers"
              :key="design.id"
              type="button"
              role="radio"
              class="gb-design"
              :class="{ active: design.id === activeDesign.id }"
              :aria-checked="design.id === activeDesign.id ? 'true' : 'false'"
              @click="pickDesign(design.id)"
            >
              <span class="gb-design-swatch" :class="`gb-theme-${i % 4}`">
                <i class="fa-solid fa-gift" aria-hidden="true"></i>
              </span>
              <span class="gb-design-name">{{ design.title }}</span>
            </button>
          </div>

          <p v-if="activeDesign.description" class="gb-design-desc">{{ activeDesign.description }}</p>
        </div>

        <!-- ── the steps ── -->
        <div class="gb-steps">

          <div class="gb-step">
            <span class="gb-step-label"><span class="gb-num">1</span> {{ $t('ui.vouchersPanel.builder.chooseValue') }}</span>
            <div class="gb-values" role="radiogroup" :aria-label="$t('ui.vouchersPanel.builder.value')">
              <button
                v-for="amt in activeDesign.amounts"
                :key="amt"
                type="button"
                role="radio"
                class="gb-value"
                :class="{ active: gbAmount === amt }"
                :aria-checked="gbAmount === amt ? 'true' : 'false'"
                @click="selectAmount(activeDesign.id, amt)"
              >
                {{ currencyIcon }}{{ formatMoney(amt) }}
              </button>
            </div>
          </div>

          <div class="gb-step">
            <span class="gb-step-label"><span class="gb-num">2</span> {{ $t('ui.vouchersPanel.builder.howMany') }}</span>
            <div class="gb-qty-row">
              <div class="gb-qty">
                <button
                  type="button"
                  :aria-label="$t('ui.vouchersPanel.builder.oneFewer')"
                  :disabled="gbQty <= activeDesign.min_quantity"
                  @click="decreaseQty(activeDesign)"
                >−</button>
                <span aria-live="polite">{{ gbQty }}</span>
                <button
                  type="button"
                  :aria-label="$t('ui.vouchersPanel.builder.oneMore')"
                  :disabled="gbQty >= activeDesign.max_quantity"
                  @click="increaseQty(activeDesign)"
                >+</button>
              </div>
              <span class="gb-muted">
                {{ gbQty > 1 ? $t('ui.vouchersPanel.builder.separateCodes', { count: gbQty }) : $t('ui.vouchersPanel.builder.oneCode') }} ·
                {{ $t('ui.vouchersPanel.builder.upTo', { max: activeDesign.max_quantity }) }}
              </span>
            </div>
          </div>

          <div class="gb-step">
            <span class="gb-step-label"><span class="gb-num">3</span> {{ $t('ui.vouchersPanel.builder.whoFor') }}</span>
            <div class="gb-seg" role="radiogroup" :aria-label="$t('ui.vouchersPanel.builder.recipient')">
              <button
                type="button"
                role="radio"
                :class="{ active: !gbGift.on }"
                :aria-checked="!gbGift.on ? 'true' : 'false'"
                @click="gbGift.on = false"
              >
                <i class="fa-regular fa-user" aria-hidden="true"></i> {{ $t('ui.vouchersPanel.builder.forMe') }}
              </button>
              <button
                type="button"
                role="radio"
                :class="{ active: gbGift.on }"
                :aria-checked="gbGift.on ? 'true' : 'false'"
                @click="gbGift.on = true"
              >
                <i class="fa-solid fa-gift" aria-hidden="true"></i> {{ $t('ui.vouchersPanel.builder.asGift') }}
              </button>
            </div>

            <div v-if="gbGift.on" class="gb-recipient">
              <div class="gb-field-row">
                <div class="gb-field">
                  <label class="gb-label" for="gb-recipient-name">{{ $t('ui.vouchersPanel.builder.theirName') }} <span aria-hidden="true">*</span></label>
                  <input
                    id="gb-recipient-name"
                    ref="gbName"
                    v-model.trim="gbGift.name"
                    type="text"
                    class="gift-field"
                    :class="{ invalid: gbErrors.name }"
                    :placeholder="$t('ui.vouchersPanel.builder.namePlaceholder')"
                    maxlength="120"
                    autocomplete="off"
                    :aria-invalid="gbErrors.name ? 'true' : 'false'"
                    @blur="touchGift('name')"
                  >
                  <small v-if="gbErrors.name" class="gb-error" role="alert">{{ gbErrors.name }}</small>
                </div>
                <div class="gb-field">
                  <label class="gb-label" for="gb-recipient-email">{{ $t('ui.vouchersPanel.builder.theirEmail') }} <span aria-hidden="true">*</span></label>
                  <input
                    id="gb-recipient-email"
                    ref="gbEmail"
                    v-model.trim="gbGift.email"
                    type="email"
                    class="gift-field"
                    :class="{ invalid: gbErrors.email }"
                    placeholder="name@example.com"
                    maxlength="190"
                    autocomplete="off"
                    inputmode="email"
                    :aria-invalid="gbErrors.email ? 'true' : 'false'"
                    @blur="touchGift('email')"
                  >
                  <small v-if="gbErrors.email" class="gb-error" role="alert">{{ gbErrors.email }}</small>
                </div>
              </div>
              <div class="gb-field">
                <label class="gb-label" for="gb-recipient-message">{{ $t('ui.vouchersPanel.builder.message') }} <span class="gb-optional">{{ $t('ui.vouchersPanel.builder.optional') }}</span></label>
                <div class="gb-message-wrap">
                  <textarea
                    id="gb-recipient-message"
                    ref="gbMessage"
                    v-model="gbGift.message"
                    class="gift-field gift-message"
                    :class="{ invalid: gbErrors.message }"
                    rows="2"
                    maxlength="300"
                    :placeholder="$t('ui.vouchersPanel.builder.messagePlaceholder')"
                    :aria-invalid="gbErrors.message ? 'true' : 'false'"
                    @blur="touchGift('message')"
                  ></textarea>
                  <span class="gb-count">{{ (gbGift.message || '').length }}/300</span>
                </div>
                <small v-if="gbErrors.message" class="gb-error" role="alert">{{ gbErrors.message }}</small>
              </div>
              <p v-if="gbGiftValid" class="gb-sending">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                <i18n path="ui.vouchersPanel.builder.sendingTo" :tag="false"><template #name><strong>{{ gbGift.name }}</strong></template><template #email><strong>{{ gbGift.email }}</strong></template></i18n>
              </p>
              <p class="gb-muted gb-note">
                <i class="fa-regular fa-envelope" aria-hidden="true"></i>
                {{ gbQty > 1 ? $t('ui.vouchersPanel.builder.emailThemMany') : $t('ui.vouchersPanel.builder.emailThemOne') }}
              </p>
            </div>
            <p v-else class="gb-muted gb-note">
              <i class="fa-regular fa-envelope" aria-hidden="true"></i>
              {{ gbQty > 1 ? $t('ui.vouchersPanel.builder.toYouMany') : $t('ui.vouchersPanel.builder.toYouOne') }}
            </p>
          </div>

          <div class="gb-step">
            <span class="gb-step-label"><span class="gb-num">4</span> {{ $t('ui.vouchersPanel.builder.payWith') }}</span>
            <div class="gb-pay" role="radiogroup" :aria-label="$t('ui.vouchersPanel.builder.paymentMethod')">
              <label class="gb-pay-opt" :class="{ active: gbMethod === 'wallet', disabled: !gbWalletCovers }">
                <input
                  v-model="paymentMethod[activeDesign.id]"
                  type="radio"
                  value="wallet"
                  :name="`gb-pay-${activeDesign.id}`"
                  :disabled="!gbWalletCovers"
                >
                <span class="gb-pay-icon"><i class="fa-solid fa-wallet" aria-hidden="true"></i></span>
                <span class="gb-pay-text">
                  <strong>{{ $t('ui.vouchersPanel.builder.wallet') }}</strong>
                  <small>{{ $t('ui.vouchersPanel.builder.available', { amount: currencyIcon + Number(wallet?.balance || 0).toFixed(2) }) }}</small>
                </span>
              </label>
              <label class="gb-pay-opt" :class="{ active: gbMethod === 'sumup' }">
                <input
                  v-model="paymentMethod[activeDesign.id]"
                  type="radio"
                  value="sumup"
                  :name="`gb-pay-${activeDesign.id}`"
                >
                <span class="gb-pay-icon"><i class="fa-regular fa-credit-card" aria-hidden="true"></i></span>
                <span class="gb-pay-text">
                  <strong>{{ $t('ui.vouchersPanel.builder.card') }}</strong>
                  <small>{{ $t('ui.vouchersPanel.builder.cardSub') }}</small>
                </span>
              </label>
            </div>
            <p v-if="gbAmount && !gbWalletCovers" class="gb-muted gb-note">
              <i18n path="ui.vouchersPanel.builder.walletShort" :tag="false"><template #amount>{{ currencyIcon }}{{ gbTotal.toFixed(2) }}</template><template #link><nuxt-link to="/user/savers-club">{{ $t('ui.vouchersPanel.builder.topUp') }}</nuxt-link></template></i18n>
            </p>
          </div>

          <!-- summary + pay -->
          <div class="gb-summary">
            <div class="gb-summary-line">
              <span v-if="gbAmount">{{ gbQty }} × {{ currencyIcon }}{{ formatMoney(gbAmount) }}</span>
              <span v-else class="gb-muted">{{ $t('ui.vouchersPanel.builder.chooseToSeeTotal') }}</span>
              <strong>{{ currencyIcon }}{{ gbTotal.toFixed(2) }}</strong>
            </div>
            <button
              type="button"
              class="gb-buy"
              :disabled="!gbAmount || purchasingId === activeDesign.id"
              @click="purchaseVoucher(activeDesign)"
            >
              <template v-if="purchasingId === activeDesign.id">
                <i class="fa-solid fa-circle-notch fa-spin" aria-hidden="true"></i> {{ $t('ui.vouchersPanel.builder.processing') }}
              </template>
              <template v-else-if="!gbAmount">{{ $t('ui.vouchersPanel.builder.chooseValue') }}</template>
              <template v-else-if="gbGift.on && !gbGiftValid">{{ $t('ui.vouchersPanel.builder.addDetails') }}</template>
              <template v-else-if="gbMethod === 'wallet'">
                {{ $t('ui.vouchersPanel.builder.payWallet', { amount: currencyIcon + gbTotal.toFixed(2) }) }}
              </template>
              <template v-else>
                {{ $t('ui.vouchersPanel.builder.payCard', { amount: currencyIcon + gbTotal.toFixed(2) }) }} <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
              </template>
            </button>
          </div>
        </div>
      </div>
    </section>

    <div class="voucher-card p-4 mb-4">
        <h6 class="mb-3  voucher-terms-heading">{{ $t('ui.vouchersPanel.terms.title') }}</h6>
        <ul class="vouchers-terms-list">
            <li>{{ $t('ui.vouchersPanel.terms.singleUse') }}</li>
            <li>{{ $t('ui.vouchersPanel.terms.noCombine') }}</li>
            <li>{{ $t('ui.vouchersPanel.terms.noCash') }}</li>
            <li>{{ $t('ui.vouchersPanel.terms.checkTerms') }}</li>
            <li>{{ $t('ui.vouchersPanel.terms.noReactivate') }}</li>
            <li>{{ $t('ui.vouchersPanel.terms.nonRefundable') }}</li>
        </ul>
    </div>

    <!-- <div
      v-if="voucherList && !voucherList.length"
      class="info-msg"
    >
      {{ $t('vouchers.noVoucher') }}
    </div>

    <div class="flex wrap start align-start">
      <div
        class="card p-15 pt-10 pb-5 mb-15"
        v-for="(value, index) in voucherList"
        :key="index"
      >
        <div class="flex sided gap-15">
          <h5 class="semi-bold mx-w-400x mb-5">
            {{ value.title }}
          </h5>
          <h4 class="semi-bold mb-5">
            {{ getPriceType(value) }}
          </h4>
        </div>
        <div class="flex sided f-9">
          <h6 class="semi-bold voucher mb-5">{{ value.code }}</h6>
          <button
            aria-label="submit"
            @click.prevent="copyTpClipboard(value)"
            class="lite-btn mb-5"
          >
            {{ copiedVouchedId === value.id ? $t('filter.copied') : $t('filter.copy') }}
          </button>
        </div>

        <div class="flex sided f-9 gap-15">
          <p class="mb-5 color-lite">
              <span class=" mr-5">
                {{ $t('vouchers.minSpend') }}
              </span>
            <b>
              <price-format
                :price="value.min_spend"
              />
            </b>
          </p>
          <p class="f-9 mb-5 color-danger">
              <span class="mr-5">
                {{ $t('vouchers.valid') }}
              </span>
            {{ value.end_time }}
          </p>
        </div>
      </div>
    </div> -->
    <div class="flow-hidden">
      <pagination
        ref="voucherPagination"
        :total-page="totalPage"
        :page="currentPage"
        :changing-route="changingRoute"
        @fetching-data="fetchingData"
      />
    </div>

    </template>

  </div>

</template>

<script>
  import util from '~/mixin/util'
  import routeParamHelper from '~/mixin/routeParamHelper'
  import productHelper from '~/mixin/productHelper'
  import {mapGetters, mapActions} from 'vuex'
  import Pagination from "./Pagination";
  import Spinner from "./Spinner";
  import AccountSkeleton from "./AccountSkeleton";
  import PriceFormat from "./PriceFormat";

  export default {
    name: 'Vouchers',
    data() {
      return {
        // True until mounted() has settled every request.
        fetchingVoucherData: true,
        copiedVouchedId: '',
        sortBy: 'expiring_soon',
        filterType: 'all',
        giftVouchers: [],
        selectedVoucherAmounts: {},
        selectedVoucherQty: {},
        paymentMethod: {},
        wallet: {},
        /*
         * Lifetime savings from the server: what vouchers really took off
         * this customer's orders. It used to add up the face value of every
         * voucher on the current page, used or not, expired or not, with a
         * "15%" voucher counted as 15 euro.
         */
        totalSaved: null,
        // gift codes bought, and the redeem box
        giftCodes: [],
        redeemCode: '',
        redeeming: false,
        copiedGiftId: null,
        purchasingId: null,
        // per gift card design: {on, name, email, message}
        giftForm: {},
        // recipient fields the customer has left (or tried to submit), and
        // errors the server sent back, keyed by field
        giftTouched: {},
        giftServerErrors: {},
        // the design shown in the gift card builder (null = the first)
        selectedDesignId: null,
      }
    },
    watch: {
      // an edit makes the server's last word on that form stale
      giftForm: {
        deep: true,
        handler() {
          if (Object.keys(this.giftServerErrors).length) this.giftServerErrors = {}
        }
      },
      selectedVoucherAmounts: {
        deep: true,
        handler() {
          this.checkWalletAvailability()
        }
      },
      selectedVoucherQty: {
        deep: true,
        handler() {
          this.checkWalletAvailability()
        }
      },
      // an empty wallet cannot be the selected way to pay
      'wallet.balance'() {
        this.checkWalletAvailability()
      },
      giftVouchers() {
        this.checkWalletAvailability()
      }
    },
    props: {
      changingRoute: {
        type: Boolean,
        default: true
      }
    },
    components: {
      PriceFormat,
      Spinner,
      AccountSkeleton,
      Pagination
    },
    computed: {
      filteredActiveList() {
        let list = [...this.activeList]

        if (this.sortBy === 'high_discount') {
          list.sort((a, b) => Number(b.price) - Number(a.price))
        }

        if (this.sortBy === 'low_discount') {
          list.sort((a, b) => Number(a.price) - Number(b.price))
        }

        if (this.sortBy === 'expiring_soon') {
          list.sort((a, b) => {
            return this.getDaysLeftNumber(a) - this.getDaysLeftNumber(b)
          })
        }

        if (this.sortBy === 'best_value') {
          list.sort((a, b) => {
            const scoreA = Number(a.price) * this.getDaysLeftNumber(a)
            const scoreB = Number(b.price) * this.getDaysLeftNumber(b)
            return scoreB - scoreA
          })
        }

        return list
      },
      activeList() {
        const now = new Date()
        return this.voucherList.filter(v => {
          const end = this.parseDate(v.end_time)
          return end && end >= now && !this.isUsedUp(v)
        })
      },
      // expired, or a gift card with nothing left on it
      expiredList() {
        const now = new Date()
        return this.voucherList.filter(v => {
          const end = this.parseDate(v.end_time)
          return !end || end < now || this.isUsedUp(v)
        })
      },
      currentPage() {
        return this.vouchers?.current_page
      },
      totalPage() {
        return this.vouchers?.last_page
      },
      voucherList() {
        return this.vouchers?.data || []
      },
      totalVouchers() {
        return this.voucherList.length
      },
      activeVouchers() {
        return this.activeList.length
      },
      ...mapGetters('language', ['langCode']),
      ...mapGetters('common', ['currencyIcon', 'currencyPosition', 'setting']),
      ...mapGetters('user', ['vouchers', 'profile']),
      /* ---- gift card builder ---- */
      activeDesign() {
        return this.giftVouchers.find(d => d.id === this.selectedDesignId) || this.giftVouchers[0] || null
      },
      themeIndex() {
        const i = this.giftVouchers.indexOf(this.activeDesign)
        return i < 0 ? 0 : i % 4
      },
      gbAmount() {
        return this.activeDesign ? this.selectedVoucherAmounts[this.activeDesign.id] || null : null
      },
      gbQty() {
        return this.activeDesign ? (this.selectedVoucherQty[this.activeDesign.id] || this.activeDesign.min_quantity || 1) : 1
      },
      // Errors to show: a field's own error once it has been left or a buy
      // was attempted, else whatever the server said about it.
      gbErrors() {
        const gift = this.gbGift
        if (!gift.on) return {}
        const own = this.validateGift(gift)
        const out = {}
        ;['name', 'email', 'message'].forEach(k => {
          const msg = (this.giftTouched[k] && own[k]) || this.giftServerErrors[k]
          if (msg) out[k] = msg
        })
        return out
      },
      gbGiftValid() {
        return this.gbGift.on && !Object.keys(this.validateGift(this.gbGift)).length
      },
      gbGift() {
        return this.activeDesign ? this.giftFor(this.activeDesign.id) : {on: false}
      },
      gbMethod() {
        return this.activeDesign ? (this.paymentMethod[this.activeDesign.id] || 'wallet') : 'wallet'
      },
      gbTotal() {
        return this.gbAmount ? Math.round(this.gbAmount * this.gbQty * 100) / 100 : 0
      },
      gbWalletCovers() {
        return Number(this.wallet?.balance || 0) >= this.gbTotal && Number(this.wallet?.balance || 0) > 0
      },
    },
    mixins: [util, routeParamHelper, productHelper],
    methods: {
      ...mapActions('common', ['setToastMessage', 'setToastError']),
      goToCart() {
        this.$router.push('/cart')
      },
      isUsedUp(v) {
        return !!v.is_gift_card && Number(v.gift_balance) <= 0
      },
      async fetchWallet() {
          try {
              const token = this.$auth?.strategy?.token?.get()
              const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'

              const { data } = await this.$axios.get(
                  `${baseUrl}api/v1/user/wallet`,
                  {
                      headers: {
                          Authorization: `Bearer ${token}`
                      }
                  }
              )

              this.wallet = data

          } catch (e) {
              console.error('Wallet fetch error:', e)
          }
      },
      checkWalletAvailability() {
        this.giftVouchers.forEach(voucher => {
          const total = this.getVoucherTotal(voucher)

          const balance = Number(this.wallet?.balance || 0)
          if (balance <= 0 || balance < total) {
            this.$set(this.paymentMethod, voucher.id, 'sumup')
          }
        })
      },
      async purchaseVoucher(voucher) {
        const amount = this.selectedVoucherAmounts[voucher.id]
        const qty = this.selectedVoucherQty[voucher.id] || voucher.min_quantity
        const method = this.paymentMethod[voucher.id] || 'wallet'
        const gift = this.giftFor(voucher.id)

        if (!amount) {
          this.setToastError(this.$t('ui.vouchersPanel.toast.chooseValueFirst'))
          return
        }

        if (qty < voucher.min_quantity || qty > voucher.max_quantity) {
          this.setToastError(this.$t('ui.vouchersPanel.toast.chooseBetween', { min: voucher.min_quantity, max: voucher.max_quantity }))
          return
        }

        if (gift.on) {
          const errs = this.validateGift(gift)
          if (Object.keys(errs).length) {
            this.giftTouched = {name: true, email: true, message: true}
            this.focusGiftField(errs)
            return
          }
        }

        // The wallet cannot cover it: say so rather than send a request that will be refused.
        if (method === 'wallet' && (this.wallet?.balance || 0) < amount * qty) {
          this.$set(this.paymentMethod, voucher.id, 'sumup')
          this.setToastError(this.$t('ui.vouchersPanel.toast.notEnoughWallet'))
          return
        }

        // amount is ONE card's face value; the server multiplies by quantity
        const payload = {
          voucher_id: voucher.id,
          amount,
          quantity: qty,
          recipient_email: gift.on ? gift.email : null,
          recipient_name: gift.on ? gift.name : null,
          sender_name: gift.on ? (this.profile?.name || null) : null,
          message: gift.on ? (gift.message || '').trim() || null : null
        }

        this.purchasingId = voucher.id
        try {
          if (method === 'sumup') {
            // The purchase (value, quantity, recipient) is fixed on the server
            // before paying; the checkout page only confirms the payment.
            const {data} = await this.$axios.post(`${this.apiBase()}api/v1/user/purchase-gift-voucher/start`, payload)

            if (!data?.checkout_id) {
              this.setToastError(data?.message || this.$t('ui.vouchersPanel.toast.paymentStartFailed'))
              return
            }

            this.$router.push({
              path: '/user/topup-checkout',
              query: {checkoutId: data.checkout_id, purpose: 'gift'}
            })
          } else {
            const {data} = await this.$axios.post(`${this.apiBase()}api/v1/user/purchase-gift-voucher`, {
              ...payload,
              payment_method: 'wallet'
            })

            this.setToastMessage(data?.message || this.$t('ui.vouchersPanel.toast.bought'))

            this.$delete(this.selectedVoucherAmounts, voucher.id)
            this.$set(this.selectedVoucherQty, voucher.id, voucher.min_quantity)
            this.$set(this.giftForm, voucher.id, {on: false, name: '', email: '', message: ''})
            this.giftTouched = {}
            this.giftServerErrors = {}
            await Promise.all([this.fetchWallet(), this.fetchGiftCodes()])
            this.$nextTick(() => this.scrollToEl(this.$refs.giftCodesSection))
          }
        } catch (e) {
          const res = e.response?.data || {}
          // The server's field errors go under their fields.
          const fieldMap = {recipient_name: 'name', recipient_email: 'email', message: 'message'}
          const mapped = {}
          Object.entries(res.errors || {}).forEach(([k, v]) => {
            if (fieldMap[k]) mapped[fieldMap[k]] = Array.isArray(v) ? v[0] : v
          })
          if (Object.keys(mapped).length && gift.on) {
            this.giftServerErrors = mapped
            this.focusGiftField(mapped)
          } else {
            this.setToastError(res.message || this.$t('ui.vouchersPanel.toast.purchaseFailed'))
          }
        } finally {
          this.purchasingId = null
        }
      },
      // The rules the server applies, so nothing is sent that will be refused.
      validateGift(gift) {
        const errs = {}
        const name = (gift.name || '').trim()
        const email = (gift.email || '').trim()
        const own = (this.profile?.email || this.$auth?.user?.email || '').trim().toLowerCase()

        if (!name) errs.name = this.$t('ui.vouchersPanel.errors.nameRequired')
        else if (name.length < 2) errs.name = this.$t('ui.vouchersPanel.errors.nameShort')
        else if (name.length > 120) errs.name = this.$t('ui.vouchersPanel.errors.nameLong')
        else if (!/\p{L}/u.test(name)) errs.name = this.$t('ui.vouchersPanel.errors.nameLetters')

        if (!email) errs.email = this.$t('ui.vouchersPanel.errors.emailRequired')
        else if (email.length > 190) errs.email = this.$t('ui.vouchersPanel.errors.emailLong')
        else if (!/^[^\s@]+@[^\s@.]+(\.[^\s@.]+)+$/.test(email)) errs.email = this.$t('ui.vouchersPanel.errors.emailInvalid')
        else if (own && email.toLowerCase() === own) errs.email = this.$t('ui.vouchersPanel.errors.emailOwn')

        if ((gift.message || '').length > 300) errs.message = this.$t('ui.vouchersPanel.errors.messageLong')
        return errs
      },
      touchGift(field) {
        this.$set(this.giftTouched, field, true)
      },
      focusGiftField(errs) {
        const ref = errs.name ? 'gbName' : errs.email ? 'gbEmail' : errs.message ? 'gbMessage' : null
        this.$nextTick(() => {
          const el = ref && this.$refs[ref]
          if (el) {
            el.scrollIntoView({behavior: 'smooth', block: 'center'})
            el.focus({preventScroll: true})
          }
        })
      },
      pickDesign(id) {
        this.selectedDesignId = id
      },
      // 10 -> "10", 12.5 -> "12.50"
      formatMoney(v) {
        const n = Number(v)
        return n % 1 === 0 ? String(n) : n.toFixed(2)
      },
      // Per-card "send as a gift" state, created on first use.
      giftFor(id) {
        if (!this.giftForm[id]) {
          this.$set(this.giftForm, id, {on: false, name: '', email: '', message: ''})
        }
        return this.giftForm[id]
      },
      apiBase() {
        return process.env.apiBase || 'https://shop.fixmypc.ie/'
      },
      async fetchSavings() {
        try {
          const {data} = await this.$axios.get(`${this.apiBase()}api/v1/user/savings`)
          this.totalSaved = data?.total_saved ?? null
        } catch (e) {
          this.totalSaved = null
        }
      },
      async fetchGiftCodes() {
        try {
          const {data} = await this.$axios.get(`${this.apiBase()}api/v1/user/gift-voucher-codes`)
          this.giftCodes = data?.data || []
        } catch (e) {
          this.giftCodes = []
        }
      },
      /* Add a gift code to this account's vouchers (a bought one, or a gift). */
      async redeemGift(code) {
        if (!code || this.redeeming) return
        this.redeeming = true
        try {
          const {data} = await this.$axios.post(`${this.apiBase()}api/v1/user/gift-voucher-codes/redeem`, {code})
          this.setToastMessage(data?.message || this.$t('ui.vouchersPanel.toast.added'))
          this.redeemCode = ''
          await Promise.all([this.fetchGiftCodes(), this.refreshVoucherList()])
        } catch (e) {
          this.setToastError(e.response?.data?.message || this.$t('ui.vouchersPanel.toast.redeemFailed'))
        } finally {
          this.redeeming = false
        }
      },
      // The voucher list, reloaded without the full-page skeleton.
      async refreshVoucherList() {
        try {
          await this.userVouchers({
            params: {time_zone: this.timeZone, order_by: 'id', type: 'desc', page: 1},
            lang: this.langCode,
          })
        } catch (e) {}
      },
      giftStateLabel(gc) {
        if (gc.state === 'redeemed') return gc.redeemed_by_me ? this.$t('ui.vouchersPanel.giftState.inVouchers') : this.$t('ui.vouchersPanel.giftState.redeemed')
        return {available: gc.recipient ? this.$t('ui.vouchersPanel.giftState.sentNotRedeemed') : this.$t('ui.vouchersPanel.giftState.available'), void: this.$t('ui.vouchersPanel.giftState.cancelled'), expired: this.$t('ui.vouchersPanel.giftState.expired')}[gc.state] || gc.state
      },
      shortDate(value) {
        const d = value ? new Date(value) : null
        return d && !isNaN(d.getTime())
          ? d.toLocaleDateString('en-IE', {day: 'numeric', month: 'short', year: 'numeric'})
          : ''
      },
      async copyText(text, id) {
        try {
          await navigator.clipboard.writeText(text)
        } catch (e) {
          const t = document.createElement('textarea')
          t.value = text
          document.body.appendChild(t)
          t.select()
          document.execCommand('copy')
          t.remove()
        }
        this.copiedGiftId = id
        clearTimeout(this.copiedGiftTimer)
        this.copiedGiftTimer = setTimeout(() => { this.copiedGiftId = null }, 1800)
      },
      scrollToEl(el) {
        if (!el) return
        const header = document.querySelector('header')
        const offset = header && getComputedStyle(header).position === 'sticky'
          ? header.getBoundingClientRect().height : 0
        window.scrollTo({top: el.getBoundingClientRect().top + window.pageYOffset - offset - 16, behavior: 'smooth'})
      },
      getVoucherTotal(voucher) {
        const amount = this.selectedVoucherAmounts[voucher.id] || 0
        const qty = this.selectedVoucherQty[voucher.id] || voucher.min_quantity
        return amount * qty
      },
      selectAmount(voucherId, amount) {
        this.$set(this.selectedVoucherAmounts, voucherId, amount)
      },

      increaseQty(voucher) {
        let current = this.selectedVoucherQty[voucher.id] || voucher.min_quantity

        if (current < voucher.max_quantity) {
          this.$set(this.selectedVoucherQty, voucher.id, current + 1)
        }
      },

      decreaseQty(voucher) {
        let current = this.selectedVoucherQty[voucher.id] || voucher.min_quantity

        if (current > voucher.min_quantity) {
          this.$set(this.selectedVoucherQty, voucher.id, current - 1)
        }
      },
      async fetchGiftVouchers() {
        const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'
        const res = await this.$axios.get(`${baseUrl}api/v1/user/gift-vouchers`)
        this.giftVouchers = res.data.data || []
        this.giftVouchers.forEach(v => {
          this.$set(this.paymentMethod, v.id, 'wallet')
          // created here, not during render
          this.$set(this.giftForm, v.id, {on: false, name: '', email: '', message: ''})
        })
      },
      parseDate(dateStr) {
        if (!dateStr) return null
        return new Date(dateStr.replace(',', '').replace(/\b(\d{2})$/, '20$1'))
      },
      getProgressColor(item) {
        const days = this.getDaysLeftNumber(item)
        if (days < 5) return '#dc3545'
        if (days < 10) return '#ffc107'
        return '#28a745'
      },
      getProgress(item) {
        const start = this.parseDate(item.start_time)
        const end = this.parseDate(item.end_time)
        const now = new Date()

        if (!start || !end) return 0

        const total = end - start
        const passed = now - start

        if (total <= 0) return 0

        return Math.min(Math.max((passed / total) * 100, 0), 100)
      },
      getDaysLeftNumber(item) {
        const end = this.parseDate(item.end_time)
        const now = new Date()
        if (!end || end < now) return 0
        const days = Math.ceil((end - now) / (1000 * 60 * 60 * 24))
        return days;
      },
      getDaysLeft(item) {
        const end = this.parseDate(item.end_time)
        const now = new Date()
        if (!end || end < now) return this.$t('ui.vouchersPanel.expired')
        const days = Math.ceil((end - now) / (1000 * 60 * 60 * 24))
        return this.$t('ui.vouchersPanel.daysLeft', { count: days })
      },
      copyCode(item) {
        navigator.clipboard.writeText(item.code)

        this.copiedVouchedId = item.id

        setTimeout(() => {
          this.copiedVouchedId = ''
        }, 2000)
      },
      loadData() {
        this.$refs.voucherPagination.routeParam()
      },
      async fetchingData() {
        this.fetchingVoucherData = true
        setTimeout(async () => {
          try {
            this.settingRouteParam()
            const data = await this.userVouchers({
              params: {
                time_zone: this.timeZone,
                order_by: this.orderBy,
                type: this.orderByType,
                page: this.page,
                q: this.search
              },
              lang: this.langCode,
            })
            if (data?.status === 201) {
              this.hasError(data)
            }
          } catch (e) {
            return this.$nuxt.error(e)
          }
          this.fetchingVoucherData = false
        }, 100)
      },
      ...mapActions('user', ['userVouchers', 'user']),
    },
    created() {
    },
    async mounted() {
      /*
       * fetchingVoucherData only ever cleared inside fetchingData(), which is
       * skipped when the vouchers are already in the store -- and the wallet
       * and gift-voucher calls below it were awaited one after another while
       * the page was already on screen. Cleared here instead, once all three
       * have settled, and run together rather than in series.
       */
      try {
        await Promise.allSettled([
          this.vouchers ? Promise.resolve() : this.fetchingData(),
          this.fetchWallet(),
          this.fetchGiftVouchers(),
          this.fetchGiftCodes(),
          this.fetchSavings()
        ])
      } catch (e) {
        console.error('Vouchers load failed:', e)
      } finally {
        this.fetchingVoucherData = false
      }
    }
  }
</script>
<style scoped>
.card-box {
    background: #fff;
    border-radius: 15px;
    border: 1px solid #e6e8f0;
}

/* Stats */
.voucher-stat {
    background: #fff;
    border: 1px solid #e6e8f0;
    border-radius: 12px;
    padding: 15px;
    position: relative;
}

button.btn-Filter {
    background-color: #F7F7FA;
    border: none;
    font-weight: 400;
    font-size: 14px;
    color: #6B7280;
    padding: 0px 20px;
}

/* ICON TOP RIGHT */
.stat-icon {
    position: absolute;
    top: 12px;
    right: 12px;
    font-size: 16px;

    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.voucher-stat {
    background: #fff;
    border: 1px solid #E3E3EF;
    border-radius: 14px;
    padding: 15px;
}

.voucher-stat small {
    font-size: 12px;
    color: #888;
}

.voucher-stat h5 {
    margin: 5px 0;
}

/* Input */
.voucher-input {
    background: #f7f8fc;
    border: 1px solid #e6e8f0;
    border-radius: 10px;
    padding: 10px;
}

/* Wrapper */
.voucher-box {
    border-radius: 14px;
    border: 1px solid #E3E3EF;
}

/* Form layout */
.voucher-form {
    display: flex;
    gap: 12px;
}

h5.total-vouchers {
    font-size: 24px;
    color: #171717;
    font-weight: 400;
}

/* Input */
.voucher-input {
    flex: 1;
    height: 44px;
    background: #F7F7FA;
    border: 1px solid #E3E3EF;
    border-radius: 10px;
    padding: 0 15px;
    font-size: 14px;
    outline: none;
    color: #6B7280;
    font-weight: 400;
}

/* Button */
.apply-btn {
    padding: 0px 22px;
    height: 44px;
    line-height: 1.2;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #05B942;
    color: #fff;
    border: none;
    border-radius: 10px;
    font-size: 14px;
    white-space: nowrap;
    transition: 0.2s;
    font-weight: 500;
}

.apply-btn:hover {
    background: #33319A;
}

/* Voucher card */
.voucher-card {
    border: 1px solid #e6e8f0;
    border-radius: 12px;
    padding: 18px;
    background: #fff;
    transition: all 0.25s ease;
}

/* ACTIVE badge */
.badge-active {
    background: #eef0f6;
    color: #333;
    font-size: 11px;
    padding: 3px 8px;
    border-radius: 6px;
}

/* Code box */
.code-box {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #f7f8fc;
    border: 1px solid #e6e8f0;
    border-radius: 8px;
    padding: 6px 10px;
    font-size: 13px;
    margin-right: 5px;
}

.copy-icon {
    cursor: pointer;
}

/* Progress */
.voucher-progress {
    height: 6px;
    background: #e6e8f0;
    border-radius: 10px;
    overflow: hidden;
}

.voucher-fill {
    height: 100%;
}

/* Sort dropdown */
.sort-select {
    border: 1px solid #E3E3EF;
    border-radius: 8px;
    padding: 9px 12px;
    background: #fff;
    font-size: 12px;
    font-weight: 400;
}


/* Expired card style */
.expired-voucher {
    opacity: 0.6;
    filter: grayscale(50%);
    pointer-events: none;
    /* disables clicks */
}

/* Expired badge */
.badge-expired {
    background: #e6e8f0;
    color: #777;
    font-size: 11px;
    padding: 3px 8px;
    border-radius: 6px;
}

/* Button style */
.expired-voucher .btn {
    background: #e6e8f0;
    color: #777;
    border: none;
    padding: 0px 20px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 400;
}

/* Optional: remove pointer block for button only */
.expired-voucher .btn {
    pointer-events: auto;
}

button.btn-use-now {
    background-color: #33319A;
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 9px 20px;
    font-size: 14px;
    font-weight: 500;
    height: auto;
    line-height: 1.2;
    white-space: nowrap;
}

button.btn-use-now:hover {
    background-color: #05B942;
}

h6.off-next-purchase {
    font-size: 18px;
    font-weight: 400;
    color: #130E2B;
}

span.btn-active {
    border-radius: 4px;
    background-color: #F5F5F5;
    font-size: 10px;
    font-weight: 400;
    color: #130E2B;
    padding: 7px 10px;
    margin-bottom: 0px;
    height: 29px;
}

/* Wrapper for alignment */
.voucher-progress-wrapper {
    display: flex;
    align-items: center;
    gap: 10px;
}

/* Progress bar full width */
.voucher-progress {
    flex: 1;
    height: 6px;
    background: #e6e8f0;
    border-radius: 10px;
    overflow: hidden;
}

/* Fill */
.voucher-fill {
    height: 100%;
    border-radius: 10px;
}

/* Text right */
.days-left {
    font-size: 12px;
    color: #888;
    white-space: nowrap;
}

.expire-boxed {
    gap: 56px !important;
}

.gift-card {
    border: 1px solid #E3E3EF;
    border-radius: 14px;
    padding: 25px 20px;
}

button.btn-purchase {
    background-color: #33319A;
    border-radius: 8px;
    border: none;
    color: #fff;
    font-size: 14px;
    font-weight: 500;
    width: 100%;
    padding: 10px 16px;
    height: auto;
    line-height: 1.2;
}

button.btn-purchase:hover {
    background-color: #05B942;
}

h4.gift-voucher-heading {
    font-size: 24px;
    color: #130E2B;
    font-weight: 400;
}

small.gift-voucher-subheading {
    font-size: 12px;
    color: #6B7280;
    font-weight: 400;
}

h6.voucher-terms-heading {
    font-size: 18px;
    font-weight: 400;
    color: #130E2B;
}

ul.vouchers-terms-list {
    padding-left: 18px;
    color: #6B7280;
    font-size: 14px;
    line-height: 20px;
    font-weight: 400;
    margin-bottom: 0px;
}

ul.vouchers-terms-list li{
  display: list-item;
  list-style: disc;
}

.copy-icon-wrapper {
  position: relative;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
}

/* Tooltip */
.tooltip-text {
  position: absolute;
  bottom: 130%;
  left: 50%;
  transform: translateX(-50%);
  background: #130E2B;
  color: #fff;
  font-size: 11px;
  padding: 4px 8px;
  border-radius: 6px;
  white-space: nowrap;
  opacity: 0;
  pointer-events: none;
  transition: 0.2s;
}

/* Show on hover */
.copy-icon-wrapper:hover .tooltip-text {
  opacity: 1;
}

/* Small arrow */
.tooltip-text::after {
  content: '';
  position: absolute;
  top: 100%;
  left: 50%;
  transform: translateX(-50%);
  border-width: 5px;
  border-style: solid;
  border-color: #130E2B transparent transparent transparent;
}

.voucher-card {
  border: 1px solid #E3E3EF;
  border-radius: 14px;
  background: #fff;
  padding: 18px 20px;
  transition: 0.2s;
}

/* Gift voucher cards have an image flush to the top, so no padding */
.gift-voucher-card {
  padding: 0;
  overflow: hidden;
}
.gift-voucher-card:hover {
  transform: translateY(-3px);
  border-color: #33319A;
}

/* Image */
.voucher-img img {
  width: 100%;
  height: 160px;
  object-fit: contain;
}

/* Body */
.voucher-body {
  padding: 12px;
}

.voucher-title {
  font-size: 14px;
  font-weight: 600;
}

.voucher-desc {
  font-size: 12px;
  color: #666;
  margin-bottom: 10px;
}

/* Amount chips */
.voucher-amounts {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.amount-chip {
  border: 1px solid #4b49ac;
  background: transparent;
  color: #4b49ac;
  border-radius: 8px;
  padding: 0px 15px;
  font-size: 12px;
  cursor: pointer;
}

.amount-chip:hover {
  background: #4b49ac;
  color: #fff;
}
.amount-chip.active {
  background: #4b49ac;
  color: #fff;
}

.qty-box {
  display: flex;
  align-items: center;
  gap: 10px;
}

.qty-box button {
  width: 40px;
  border: none;
  background: #eef0f6;
  border-radius: 6px;
}

.qty-box span {
  min-width: 20px;
  text-align: center;
}

h5.order-hrading {
  font-size: 26px;
  font-weight: 700;
  color: #130E2B;
}

/* Responsive */
@media (max-width: 767px) {
  .container-fluid { padding-left: 0; padding-right: 0; }
  .card-box { padding: 18px !important; }
  h5.order-hrading { font-size: 22px; }
  .voucher-form { flex-direction: column; }
  .apply-btn { width: 100%; }
  .expire-boxed { gap: 12px !important; }
  .voucher-card .d-flex.justify-content-between { flex-wrap: wrap; gap: 10px; }
  button.btn-use-now { width: 100%; }
}

/* ══════════ responsive refresh ══════════
 *
 * One block that restyles the page for every width, instead of more
 * scattered fixes. Buttons set their own height/line-height and hover
 * backgrounds are !important: base.styl's global button rules otherwise win.
 */

/* base.styl sets letter-spacing: 1px on every h6, which read as a typo */
h6,
.card-box h6,
.voucher-card h6,
.voucher-list-head h6 {
  letter-spacing: normal;
  font-weight: 700;
  color: #130E2B;
}

h5.order-hrading { font-size: clamp(22px, 1.1rem + 1.2vw, 30px); }

.card-box h6 { font-size: clamp(15px, .85rem + .3vw, 17px); }

/* stats */
.voucher-stats .voucher-stat {
  height: 100%;
  border-radius: 14px;
}

.voucher-stats h5.total-vouchers {
  font-size: clamp(22px, 1.1rem + 1vw, 30px);
  font-weight: 700;
}

/* list heading + sort */
.voucher-list-head .sort-select { max-width: 100%; }

/* code / discount / expires */
.voucher-card .voucher-meta {
  display: grid !important;
  grid-template-columns: auto auto auto;
  justify-content: start;
  gap: 8px 40px !important;
}

.voucher-meta .code-box { display: inline-flex; }

.voucher-card h6.off-next-purchase {
  font-size: clamp(15px, .85rem + .35vw, 18px);
  margin: 0;
}

button.btn-use-now,
.apply-btn,
button.btn-purchase {
  height: 42px;
  line-height: 1;
}

button.btn-use-now:hover { background: #28267d !important; }
.apply-btn:hover { background: #049636 !important; }
button.btn-purchase:hover:enabled { background: #28267d !important; }

/* gift vouchers */
.gift-row .voucher-img {
  display: flex;
  align-items: center;
  justify-content: center;
  background: #F7F7FC;
}

.gift-row .voucher-img img,
.gift-row .voucher-img ::v-deep img {
  height: 130px;
}

.amount-chip {
  height: 36px;
  line-height: 1;
  min-width: 56px;
  font-weight: 600;
}

.amount-chip:hover { background: #4b49ac !important; color: #fff; }

.qty-box button {
  height: 36px;
  line-height: 1;
  font-size: 16px;
}

.qty-box button:hover { background: #E3E5F0 !important; }

.payment-method {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 16px;
  font-size: 13px;
}

.payment-method label {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  cursor: pointer;
}

/* ---------- phone ---------- */

@media (max-width: 767.98px) {

  /* three compact tiles across instead of three full-width cards */
  .voucher-stats { --bs-gutter-x: 8px; }

  .voucher-stats .voucher-stat {
    padding: 12px 10px;
  }

  .voucher-stats .voucher-stat small {
    font-size: 10.5px;
    letter-spacing: .02em;
    display: block;
    padding-right: 18px;
    line-height: 1.3;
  }

  .voucher-stats .stat-icon { top: 10px; right: 8px; font-size: 12px; }
  .voucher-stats .voucher-stat .text-muted.small { display: none; }

  /* heading and its filter stay on one row */
  .card-box.d-flex { gap: 12px; }
  .card-box.d-flex > div { min-width: 0; }

  .voucher-list-head { align-items: flex-start !important; }
  .voucher-list-head > .d-flex { flex: 1 1 100%; }
  .voucher-list-head .sort-select { flex: 1 1 auto; }

  /* code across the top, discount and expiry beside each other */
  .voucher-card .voucher-meta {
    grid-template-columns: 1fr 1fr;
    gap: 10px 16px !important;
  }

  .voucher-card .voucher-meta > div:first-child { grid-column: 1 / -1; }

  .voucher-card { padding: 16px; }
  .voucher-card > .d-flex > div { width: 100%; }

  /* gift vouchers: a swipeable row instead of six full-height cards */
  .gift-row {
    flex-wrap: nowrap;
    overflow-x: auto;
    scroll-snap-type: x mandatory;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    margin-right: -18px;
    padding-right: 18px;
    padding-bottom: 4px;
  }

  .gift-row::-webkit-scrollbar { display: none; }

  .gift-row > .gift-col {
    flex: 0 0 82%;
    max-width: 82%;
    scroll-snap-align: start;
  }

  .gift-row .voucher-img img,
  .gift-row .voucher-img ::v-deep img { height: 110px; }

  .gift-voucher-card:hover { transform: none; }
}

/* ══════════ gift codes ══════════ */

.sr-only {
  position: absolute; width: 1px; height: 1px; overflow: hidden;
  clip: rect(0, 0, 0, 0); white-space: nowrap;
}

.gift-code-input {
  text-transform: uppercase;
  letter-spacing: .06em;
  font-weight: 600;
}

.apply-btn:disabled { opacity: .55; cursor: not-allowed; }

.gift-codes-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
  margin-bottom: 16px;
}

.gift-codes-count {
  flex-shrink: 0;
  min-width: 28px;
  height: 28px;
  padding: 0 8px;
  border-radius: 999px;
  background: #EEEEFA;
  color: #33319A;
  font-weight: 700;
  font-size: 13px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.gift-code-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 10px;
}

.gift-code {
  display: grid;
  grid-template-columns: 76px minmax(0, 1fr) auto;
  align-items: center;
  gap: 14px;
  padding: 12px 14px;
  border: 1px dashed #C9CAE6;
  border-radius: 14px;
  background: #FBFBFF;
}

.gift-code.is-used,
.gift-code.is-redeemed,
.gift-code.is-expired { opacity: .6; background: #fff; }

.gift-code-value {
  height: 56px;
  border-radius: 12px;
  background: linear-gradient(135deg, #33319A, #5653D1);
  color: #fff;
  font-size: 18px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  font-variant-numeric: tabular-nums;
}

.gift-code-main { min-width: 0; }

.gift-code-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.gift-code-text {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 15px;
  font-weight: 700;
  letter-spacing: .06em;
  color: #130E2B;
}

.gift-code-copy {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 28px;
  line-height: 1;
  padding: 0 10px;
  border: 1px solid #E3E3EF;
  border-radius: 8px;
  background: #fff;
  color: #33319A;
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
}

.gift-code-copy:hover { background: #F5F5FF !important; }

.gift-code-meta {
  display: block;
  margin-top: 4px;
  font-size: 12.5px;
  color: #6B7280;
}

.gift-code-side {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 8px;
}

.gift-code-pill {
  font-size: 12px;
  font-weight: 600;
  padding: 3px 10px;
  border-radius: 999px;
  white-space: nowrap;
  background: #F3F4F6;
  color: #374151;
}

.pill-available { background: #E6F6EC; color: #1E7E34; }
.pill-in_your_vouchers { background: #EBEBFF; color: #33319A; }

.gift-code-add {
  height: 36px;
  line-height: 1;
  padding: 0 14px;
  border: 1px solid #33319A;
  border-radius: 10px;
  background: #33319A;
  color: #fff;
  font-size: 13px;
  font-weight: 600;
  white-space: nowrap;
  cursor: pointer;
}

.gift-code-add:hover:enabled { background: #28267d !important; }
.gift-code-add:disabled { opacity: .6; }

/* purchase card total */
.gift-total {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  margin: 4px 0 10px;
  padding-top: 10px;
  border-top: 1px solid #F0F0F6;
  font-size: 13px;
  color: #6B7280;
}

.gift-total strong { font-size: 17px; color: #130E2B; }
.gift-total-hint { justify-content: flex-start; }

button.btn-purchase:disabled { opacity: .55; cursor: not-allowed; }

@media (max-width: 767.98px) {
  .gift-code {
    grid-template-columns: 60px minmax(0, 1fr);
    grid-template-areas:
      "value main"
      "side  side";
    gap: 10px 12px;
  }

  .gift-code-value { grid-area: value; height: 48px; font-size: 15px; }
  .gift-code-main { grid-area: main; }

  .gift-code-side {
    grid-area: side;
    flex-direction: row;
    align-items: center;
    justify-content: space-between;
  }

  .gift-code-add { flex: 0 0 auto; }
  .gift-code-text { font-size: 14px; }
}

/* ══════════ gift cards v2 ══════════ */

.gift-redeem-head {
  display: flex;
  gap: 14px;
  align-items: flex-start;
}

.gift-redeem-icon {
  flex: 0 0 44px;
  height: 44px;
  border-radius: 12px;
  background: linear-gradient(135deg, #33319A, #5653D1);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
}

.gift-toggle {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  margin: 2px 0 8px;
  font-size: 13.5px;
  font-weight: 600;
  color: #130E2B;
  cursor: pointer;
}

.gift-toggle input { width: 16px; height: 16px; }

.gift-recipient {
  display: grid;
  gap: 8px;
  margin-bottom: 10px;
  padding: 10px;
  border-radius: 10px;
  background: #F7F7FC;
}

.gift-field {
  width: 100%;
  height: 38px;
  padding: 0 10px;
  border: 1px solid #E3E3EF;
  border-radius: 8px;
  background: #fff;
  font-size: 14px;
}

.gift-field:focus {
  outline: none;
  border-color: #33319A;
  box-shadow: 0 0 0 3px rgba(51, 49, 154, .12);
}

.gift-message {
  height: auto;
  min-height: 56px;
  padding: 8px 10px;
  resize: vertical;
}

.gift-code-meta i { margin-right: 4px; }

.pill-redeemed { background: #EBEBFF; color: #33319A; }
.pill-void { background: #FDECEA; color: #C82333; }

@media (max-width: 767.98px) {
  .gift-field { font-size: 16px; } /* no zoom on iOS */
}

/* ══════════ gift card builder ══════════
 * Buttons set height/line-height and every state's background, border and
 * colour with !important: base.styl's global button rules (50px height, a
 * grey hover that also hits disabled buttons) otherwise win.
 */

.gb { padding: clamp(18px, 1rem + 1vw, 28px) !important; }

.gb-head { margin-bottom: 20px; }

.card-box h6.gb-title {
  font-size: clamp(17px, .95rem + .4vw, 20px);
  font-weight: 700;
  margin: 0 0 4px;
}

.gb-sub {
  margin: 0;
  font-size: 13.5px;
  color: #6B7280;
  line-height: 1.55;
  max-width: 640px;
}

.gb-body {
  display: grid;
  grid-template-columns: minmax(0, 360px) minmax(0, 1fr);
  gap: clamp(20px, 1rem + 2vw, 40px);
  align-items: start;
}

/* ---- the card ---- */

.gb-visual { position: sticky; top: 16px; }

.gb-card {
  position: relative;
  overflow: hidden;
  aspect-ratio: 1.586;          /* a real card's proportions */
  border-radius: 18px;
  padding: 20px 22px;
  color: #fff;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  box-shadow: 0 18px 40px rgba(51, 49, 154, .28);
  transition: background .3s ease;
}

.gb-theme-0 { background: linear-gradient(135deg, #33319A 0%, #5B57E0 60%, #8A7CF5 100%); }
.gb-theme-1 { background: linear-gradient(135deg, #0F766E 0%, #14B8A6 100%); }
.gb-theme-2 { background: linear-gradient(135deg, #B4235C 0%, #F0567D 100%); }
.gb-theme-3 { background: linear-gradient(135deg, #1F2937 0%, #4B5563 100%); }

.gb-card::after {             /* soft sheen */
  content: '';
  position: absolute;
  inset: -40% -20% auto auto;
  width: 70%;
  height: 120%;
  background: radial-gradient(closest-side, rgba(255, 255, 255, .22), rgba(255, 255, 255, 0));
  pointer-events: none;
}

.gb-card-top,
.gb-card-bottom {
  position: relative;
  z-index: 1;
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
  gap: 10px;
}

.gb-card-brand { font-weight: 800; font-size: 17px; letter-spacing: .01em; }

.gb-card-kind {
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .12em;
  text-transform: uppercase;
  opacity: .85;
}

.gb-card-value {
  position: relative;
  z-index: 1;
  font-size: clamp(34px, 2rem + 1.4vw, 44px);
  font-weight: 800;
  line-height: 1;
  font-variant-numeric: tabular-nums;
}

.gb-card-placeholder { opacity: .55; }

.gb-card-for {
  font-size: 14px;
  font-weight: 600;
  max-width: 60%;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.gb-card-design {
  font-size: 11.5px;
  opacity: .8;
  text-align: right;
  max-width: 45%;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.gb-card-mark {
  position: absolute;
  right: -10px;
  bottom: -18px;
  font-size: 110px;
  opacity: .1;
  transform: rotate(-12deg);
}

.gb-card-message {
  margin: 14px 4px 0;
  font-size: 13.5px;
  font-style: italic;
  color: #374151;
  line-height: 1.5;
  overflow-wrap: anywhere;
}

.gb-design-desc {
  margin: 12px 4px 0;
  font-size: 13px;
  color: #6B7280;
}

.gb-designs {
  display: flex;
  gap: 8px;
  margin-top: 16px;
  overflow-x: auto;
  scrollbar-width: none;
}

.gb-designs::-webkit-scrollbar { display: none; }

.gb-design {
  flex: 0 0 auto;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  height: 44px !important;
  line-height: 1 !important;
  padding: 0 12px 0 6px;
  border: 1px solid #E3E3EF !important;
  border-radius: 12px;
  background: #fff !important;
  color: #130E2B !important;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}

.gb-design:hover { border-color: #C9CAE6 !important; background: #FAFAFF !important; }

.gb-design.active,
.gb-design.active:hover {
  border-color: #33319A !important;
  background: #F5F5FF !important;
  box-shadow: 0 0 0 3px rgba(51, 49, 154, .12);
}

.gb-design-swatch {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  color: #fff;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
}

.gb-design-name { white-space: nowrap; }

/* ---- steps ---- */

.gb-steps {
  display: flex;
  flex-direction: column;
  gap: 20px;
  min-width: 0;
}

.gb-step-label {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 10px;
  font-size: 14px;
  font-weight: 700;
  color: #130E2B;
}

.gb-num {
  width: 22px;
  height: 22px;
  border-radius: 50%;
  background: #EEEEFA;
  color: #33319A;
  font-size: 12px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.gb-muted { font-size: 13px; color: #6B7280; }
.gb-note { margin: 8px 0 0; line-height: 1.5; }
.gb-note i { margin-right: 4px; }
.gb-note a { color: #33319A; font-weight: 600; }

/* values */
.gb-values {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(92px, 1fr));
  gap: 8px;
}

.gb-value {
  height: 52px !important;
  line-height: 1 !important;
  border: 1.5px solid #E3E3EF !important;
  border-radius: 12px;
  background: #fff !important;
  color: #130E2B !important;
  font-size: 17px;
  font-weight: 700;
  cursor: pointer;
  font-variant-numeric: tabular-nums;
  transition: border-color .15s ease, box-shadow .15s ease;
}

.gb-value:hover { border-color: #A9A7E0 !important; background: #FAFAFF !important; }

.gb-value.active,
.gb-value.active:hover {
  border-color: #33319A !important;
  background: #33319A !important;
  color: #fff !important;
  box-shadow: 0 6px 16px rgba(51, 49, 154, .25);
}

/* quantity */
.gb-qty-row {
  display: flex;
  align-items: center;
  gap: 14px;
  flex-wrap: wrap;
}

.gb-qty {
  display: inline-flex;
  align-items: center;
  height: 44px;
  border: 1px solid #E3E3EF;
  border-radius: 12px;
  overflow: hidden;
}

.gb-qty button {
  width: 44px;
  height: 44px !important;
  line-height: 1 !important;
  border: 0 !important;
  border-radius: 0;
  background: #F7F7FC !important;
  color: #130E2B !important;
  font-size: 18px;
  cursor: pointer;
}

.gb-qty button:hover:enabled { background: #EEEEFA !important; color: #33319A !important; }
.gb-qty button:disabled { opacity: .35; cursor: not-allowed; }

.gb-qty span {
  min-width: 44px;
  text-align: center;
  font-weight: 700;
  font-size: 16px;
  font-variant-numeric: tabular-nums;
}

/* recipient */
.gb-seg {
  display: inline-grid;
  grid-template-columns: 1fr 1fr;
  gap: 4px;
  padding: 4px;
  border-radius: 12px;
  background: #F3F3F9;
  width: 100%;
  max-width: 360px;
}

.gb-seg button {
  height: 40px !important;
  line-height: 1 !important;
  border: 0 !important;
  border-radius: 9px;
  background: transparent !important;
  color: #4B5563 !important;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}

.gb-seg button:hover { color: #130E2B !important; }

.gb-seg button.active,
.gb-seg button.active:hover {
  background: #fff !important;
  color: #33319A !important;
  box-shadow: 0 1px 4px rgba(19, 14, 43, .12);
}

.gb-recipient {
  display: grid;
  gap: 8px;
  margin-top: 12px;
}

.gb-field-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
}

.gb .gift-field {
  height: 44px;
  padding: 0 12px;
  border-radius: 10px;
  font-size: 14px;
  line-height: normal;
}

.gb .gift-message {
  height: auto;
  min-height: 72px;
  padding: 10px 12px;
}

.gb-message-wrap { position: relative; }

.gb-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}

.gb-label {
  font-size: 12px;
  font-weight: 600;
  color: #374151;
  margin: 0;
}

.gb-label span { color: #DC2626; }
.gb-label .gb-optional { color: #9CA3AF; font-weight: 400; }

.gb .gift-field.invalid,
.gb .gift-field.invalid:focus {
  border-color: #DC2626;
  box-shadow: 0 0 0 3px rgba(220, 38, 38, .12);
}

.gb-error {
  font-size: 12px;
  line-height: 1.35;
  color: #DC2626;
}

.gb-sending {
  display: block;
  margin: 0;
  padding: 8px 12px;
  border-radius: 10px;
  background: #ECFDF5;
  color: #065F46;
  font-size: 13px;
  line-height: 1.5;
  word-break: break-word;
}

.gb-sending i { margin-right: 6px; }

.gb-count {
  position: absolute;
  right: 10px;
  bottom: 8px;
  font-size: 11px;
  color: #9CA3AF;
}

/* payment */
.gb-pay {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}

.gb-pay-opt {
  position: relative;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;
  border: 1.5px solid #E3E3EF;
  border-radius: 12px;
  background: #fff;
  cursor: pointer;
  margin: 0;
  transition: border-color .15s ease, background-color .15s ease;
}

.gb-pay-opt input {
  position: absolute;
  opacity: 0;
  pointer-events: none;
}

.gb-pay-opt:hover { border-color: #A9A7E0; }

.gb-pay-opt.active {
  border-color: #33319A;
  background: #F7F7FF;
  box-shadow: 0 0 0 3px rgba(51, 49, 154, .1);
}

.gb-pay-opt.disabled {
  opacity: .5;
  cursor: not-allowed;
}

.gb-pay-opt:focus-within { box-shadow: 0 0 0 3px rgba(51, 49, 154, .2); }

.gb-pay-icon {
  flex: 0 0 38px;
  height: 38px;
  border-radius: 10px;
  background: #EEEEFA;
  color: #33319A;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 15px;
}

.gb-pay-text { display: flex; flex-direction: column; min-width: 0; }
.gb-pay-text strong { font-size: 14px; color: #130E2B; }
.gb-pay-text small { font-size: 12px; color: #6B7280; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* summary */
.gb-summary {
  margin-top: 4px;
  padding-top: 16px;
  border-top: 1px dashed #E3E3EF;
}

.gb-summary-line {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  margin-bottom: 12px;
  font-size: 14px;
  color: #374151;
}

.gb-summary-line strong {
  font-size: 22px;
  color: #130E2B;
  font-variant-numeric: tabular-nums;
}

.gb-buy {
  width: 100%;
  height: 52px !important;
  line-height: 1 !important;
  border: 0 !important;
  border-radius: 12px;
  background: #33319A !important;
  color: #fff !important;
  font-size: 16px;
  font-weight: 700;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  box-shadow: 0 8px 20px rgba(51, 49, 154, .25);
  transition: transform .1s ease, box-shadow .15s ease;
}

.gb-buy:hover:enabled { background: #28267D !important; box-shadow: 0 10px 24px rgba(51, 49, 154, .32); }
.gb-buy:active:enabled { transform: translateY(1px); }

.gb-buy:disabled,
.gb-buy:disabled:hover {
  background: #C9C8E6 !important;
  box-shadow: none;
  cursor: not-allowed;
}

/* ---- tablet and phone ---- */

@media (max-width: 991.98px) {
  .gb-body { grid-template-columns: minmax(0, 1fr); }
  .gb-visual { position: static; max-width: 420px; }
}

@media (max-width: 575.98px) {
  .gb-field-row,
  .gb-pay { grid-template-columns: minmax(0, 1fr); }
  .gb-values { grid-template-columns: repeat(3, minmax(0, 1fr)); }
  .gb-value { height: 48px !important; font-size: 16px; }
  .gb .gift-field { font-size: 16px; } /* no iOS zoom */
  .gb-seg { max-width: none; }
}

@media (prefers-reduced-motion: reduce) {
  .gb-card, .gb-value, .gb-buy { transition: none; }
}
</style>

