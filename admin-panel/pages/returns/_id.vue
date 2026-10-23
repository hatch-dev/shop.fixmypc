<template>
  <div class="return-page p-20">
    <nuxt-link to="/returns" class="rd-back">← {{ $t('pages.returns.returns') }}</nuxt-link>

    <div v-if="loading" class="rd-card">{{ $t('pages.returns.loading') }}</div>
    <div v-else-if="loadError" class="rd-card rd-error">{{ loadError }}</div>

    <template v-else-if="r">
      <div class="rd-card rd-head">
        <div>
          <h3 class="rd-title">{{ r.rma_number }}</h3>
          <p class="rd-sub">
            <nuxt-link v-if="r.order" class="link" :to="`/orders/${r.order.id}`">{{ $t('pages.returns.orderNumber', { number: r.order.order }) }}</nuxt-link>
            <template v-if="r.order && r.order.contact_name"> · {{ r.order.contact_name }}</template>
            <template v-if="r.order && r.order.contact_email"> · {{ r.order.contact_email }}</template>
          </p>
        </div>
        <div class="rd-pills">
          <span v-if="isExchange" class="pill pill-type">{{ $t('pages.returns.exchange') }}</span>
          <span class="pill" :class="pillClass">{{ r.status_label }}</span>
        </div>
      </div>

      <p v-if="r.refund_error" class="rd-error-line" role="alert">
        <strong>{{ r.status === 'refunding' ? $t('pages.returns.refundNeedsChecking') : $t('pages.returns.lastRefundFailed') }}</strong>
        {{ r.refund_error }}
      </p>

      <div class="rd-grid">
        <div class="rd-card">
          <h4>{{ $t('pages.returns.returnHeading') }}</h4>
          <dl class="rd-dl">
            <dt>{{ $t('pages.returns.reason') }}</dt><dd>{{ r.reason_label }}</dd>
            <template v-if="r.customer_note"><dt>{{ $t('pages.returns.customerSaid') }}</dt><dd>{{ r.customer_note }}</dd></template>
            <template v-if="r.staff_note"><dt>{{ $t('pages.returns.staffNote') }}</dt><dd>{{ r.staff_note }}</dd></template>
            <dt>{{ $t('pages.returns.opened') }}</dt><dd>{{ dateTime(r.created_at) }}</dd>
            <template v-if="r.received_at"><dt>{{ $t('pages.returns.received') }}</dt><dd>{{ dateTime(r.received_at) }}</dd></template>
            <template v-if="r.refunded_at"><dt>{{ $t('pages.returns.refunded') }}</dt><dd>{{ dateTime(r.refunded_at) }}</dd></template>
            <template v-if="r.closed_at && !r.refunded_at"><dt>{{ $t('pages.returns.closed') }}</dt><dd>{{ dateTime(r.closed_at) }}</dd></template>
          </dl>
        </div>

        <div v-if="isExchange" class="rd-card">
          <h4>{{ r.status === 'exchanged' ? $t('pages.returns.exchangeMade') : $t('pages.returns.exchange') }}</h4>
          <dl v-if="r.status === 'exchanged'" class="rd-dl">
            <template v-if="r.replacement_order">
              <dt>{{ $t('pages.returns.replacementOrder') }}</dt>
              <dd><nuxt-link class="link" :to="`/orders/${r.replacement_order.id}`">#{{ r.replacement_order.order }}</nuxt-link></dd>
            </template>
            <dt>{{ $t('pages.returns.difference') }}</dt><dd>{{ differenceText(r.exchange_difference) }}</dd>
            <template v-if="r.amount_charged > 0"><dt>{{ $t('pages.returns.charged') }}</dt><dd>{{ currencyIcon }}{{ money(r.amount_charged) }} <span class="rd-hint-inline">{{ r.payment_reference }}</span></dd></template>
            <template v-if="r.exchange_difference > 0 && !(r.amount_charged > 0)"><dt>{{ $t('pages.returns.charged') }}</dt><dd>{{ $t('pages.returns.nothingWaived') }}</dd></template>
            <template v-if="r.refund_amount > 0"><dt>{{ $t('pages.returns.refunded') }}</dt><dd>{{ currencyIcon }}{{ money(r.refund_amount) }} <span class="rd-hint-inline">{{ methodLabel(r.refund_method) }}</span></dd></template>
            <template v-if="r.refund_reference"><dt>{{ $t('pages.returns.reference') }}</dt><dd class="rd-mono">{{ r.refund_reference }}</dd></template>
          </dl>
          <!-- Waiting on a pay link: the amount asked for, not today's quote (its units are held). -->
          <dl v-else-if="r.status === 'awaiting_payment' && r.pay_link" class="rd-dl">
            <dt>{{ $t('pages.returns.difference') }}</dt><dd>{{ differenceText(r.pay_link.amount) }}</dd>
            <dt>{{ $t('pages.returns.payingBy') }}</dt><dd>{{ $t('pages.returns.cardByLinkBelow') }}</dd>
          </dl>
          <p v-else-if="!r.exchange_quote" class="rd-hint">{{ r.status_label }}.</p>
          <template v-else>
            <dl class="rd-dl">
              <dt>{{ $t('pages.returns.difference') }}</dt><dd>{{ differenceText(r.exchange_quote.difference) }}</dd>
              <template v-if="r.exchange_quote.difference < 0"><dt>{{ $t('pages.returns.canRefund') }}</dt><dd>{{ currencyIcon }}{{ money(r.exchange_quote.refundable) }}</dd></template>
            </dl>
            <p class="rd-hint">{{ $t('pages.returns.todaysPricesHint') }}</p>
            <p v-if="!r.exchange_quote.in_stock" class="rd-error-line" role="alert">{{ $t('pages.returns.swapOutOfStock') }}</p>
          </template>
        </div>

        <div v-else class="rd-card">
          <h4>{{ done ? $t('pages.returns.whatWentBack') : $t('pages.returns.refundIfAccepted') }}</h4>
          <dl v-if="done" class="rd-dl">
            <dt>{{ $t('pages.returns.money') }}</dt><dd>{{ currencyIcon }}{{ money(r.refund_amount) }} <span class="rd-hint-inline">{{ methodLabel(r.refund_method) }}</span></dd>
            <template v-if="r.shipping_refund > 0"><dt>{{ $t('pages.returns.ofWhichDelivery') }}</dt><dd>{{ currencyIcon }}{{ money(r.shipping_refund) }}</dd></template>
            <template v-if="r.gift_card_amount > 0"><dt>{{ $t('pages.returns.toGiftCard') }}</dt><dd>{{ currencyIcon }}{{ money(r.gift_card_amount) }}</dd></template>
            <template v-if="r.points_returned > 0"><dt>{{ $t('pages.returns.pointsBack') }}</dt><dd>{{ r.points_returned }}</dd></template>
            <template v-if="r.points_taken > 0"><dt>{{ $t('pages.returns.pointsTakenBack') }}</dt><dd>{{ r.points_taken }}</dd></template>
            <template v-if="r.refund_reference"><dt>{{ $t('pages.returns.reference') }}</dt><dd class="rd-mono">{{ r.refund_reference }}</dd></template>
          </dl>
          <dl v-else-if="activeQuote" class="rd-dl">
            <dt>{{ $t('pages.returns.items') }}</dt><dd>{{ currencyIcon }}{{ money(activeQuote.items_refund) }}</dd>
            <dt>{{ $t('pages.returns.delivery') }}</dt><dd>{{ currencyIcon }}{{ money(r.quote.with_shipping.shipping_refund) }} <span class="rd-hint-inline">{{ $t('pages.returns.ifRefunded') }}</span></dd>
            <template v-if="activeQuote.gift_card > 0"><dt>{{ $t('pages.returns.toGiftCard') }}</dt><dd>{{ currencyIcon }}{{ money(activeQuote.gift_card) }}</dd></template>
            <template v-if="activeQuote.points_returned > 0"><dt>{{ $t('pages.returns.pointsBack') }}</dt><dd>{{ activeQuote.points_returned }}</dd></template>
            <template v-if="activeQuote.points_taken > 0"><dt>{{ $t('pages.returns.pointsTakenBack') }}</dt><dd>{{ activeQuote.points_taken }}</dd></template>
            <dt>{{ $t('pages.returns.leftOnOrder') }}</dt><dd>{{ currencyIcon }}{{ money(activeQuote.money_left) }}</dd>
          </dl>
          <p v-else class="rd-hint">{{ $t('pages.returns.nothingToRefund') }}</p>
        </div>
      </div>

      <div class="rd-card">
        <h4>{{ $t('pages.returns.items') }}</h4>
        <table class="rd-table">
          <tr>
            <th>{{ $t('pages.returns.item') }}</th><th>{{ $t('pages.returns.units') }}</th>
            <th>{{ r.status === 'awaiting_return' ? $t('pages.returns.putBackInStock') : $t('pages.returns.backInStock') }}</th>
            <th v-if="isExchange">{{ $t('pages.returns.swapTo') }}</th>
            <th v-if="done && !isExchange">{{ $t('pages.returns.itemShare') }}</th>
          </tr>
          <tr v-for="item in r.items" :key="item.id">
            <td>
              <strong>{{ item.title }}</strong>
              <span v-if="item.variant" class="rd-hint-inline"> · {{ item.variant }}</span>
            </td>
            <td>{{ item.quantity }}</td>
            <td>
              <label v-if="r.status === 'awaiting_return' && canEdit" class="rd-check">
                <input type="checkbox" v-model="restock[item.id]"> {{ $t('pages.returns.yes') }}
              </label>
              <template v-else-if="item.restock === null">—</template>
              <template v-else>{{ item.restock ? $t('pages.returns.yesCount', { count: item.restocked_qty }) : $t('pages.returns.noNotSellable') }}</template>
            </td>
            <td v-if="isExchange">
              {{ item.exchange_label || '—' }}
              <template v-if="exchangeLine(item)">
                <span class="rd-hint-inline">· {{ $t('pages.returns.eachPaid', { price: currencyIcon + money(exchangeLine(item).new_unit_price), paid: currencyIcon + money(exchangeLine(item).old_unit_price) }) }}</span>
                <span v-if="!exchangeLine(item).available" class="pill pill-bad">{{ $t('pages.returns.outOfStock') }}</span>
              </template>
            </td>
            <td v-if="done && !isExchange">{{ currencyIcon }}{{ money(item.line_refund) }}</td>
          </tr>
        </table>
      </div>

      <!-- ============================ Actions ============================ -->
      <div v-if="canEdit && r.status === 'requested'" class="rd-card rd-card-attn">
        <h4>{{ $t('pages.returns.reviewRequest') }}</h4>
        <p class="rd-hint">
          {{ r.customer_note ? $t('pages.returns.askedFromOrderPageWhy') : $t('pages.returns.askedFromOrderPage') }}
          {{ isExchange ? $t('pages.returns.approveEmailsItem') : $t('pages.returns.approveEmailsItems') }}
        </p>
        <label class="rd-label" for="rt-anote">{{ $t('pages.returns.noteOptional') }}</label>
        <input id="rt-anote" v-model="note" type="text" maxlength="400">
        <label class="rd-check rd-gap">
          <input type="checkbox" v-model="notifyApprove"> {{ $t('pages.returns.emailCustomer') }}
        </label>
        <p v-if="actionError" class="rd-error-line" role="alert">{{ actionError }}</p>
        <div class="rd-actions">
          <button type="button" class="outline-btn rd-danger" :disabled="busy" @click="rejectOpen = true">{{ $t('pages.returns.reject') }}</button>
          <button type="button" class="primary-btn" :disabled="busy" @click="approve">{{ busy ? $t('pages.returns.saving') : $t('pages.returns.approve') }}</button>
        </div>
      </div>

      <div v-if="r.status === 'awaiting_payment' && r.pay_link" class="rd-card rd-card-attn">
        <h4>{{ $t('pages.returns.waitingForPayment') }}</h4>
        <dl class="rd-dl">
          <dt>{{ $t('pages.returns.amount') }}</dt><dd>{{ $t('pages.returns.amountByCard', { amount: currencyIcon + money(r.pay_link.amount), gateway: r.pay_link.gateway }) }}</dd>
          <dt>{{ $t('pages.returns.link') }}</dt>
          <dd class="rd-link-row">
            <span class="rd-mono rd-link-text">{{ r.pay_link.url }}</span>
            <button type="button" class="outline-btn xs" @click="copyLink">{{ copied ? $t('pages.returns.copied') : $t('pages.returns.copy') }}</button>
          </dd>
          <dt>{{ $t('pages.returns.validUntil') }}</dt>
          <dd>{{ dateTime(r.pay_link.expires_at) }}<span v-if="r.pay_link.status === 'expired'" class="pill pill-bad rd-gap-left">{{ $t('pages.returns.expired') }}</span></dd>
          <dt>{{ $t('pages.returns.started') }}</dt><dd>{{ r.pay_link.started ? $t('pages.returns.customerOpenedCardForm') : $t('pages.returns.notYet') }}</dd>
        </dl>
        <p class="rd-hint">{{ r.items.length > 1 ? $t('pages.returns.newUnitsHeld') : $t('pages.returns.newUnitHeld') }}</p>
        <p v-if="actionError" class="rd-error-line" role="alert">{{ actionError }}</p>
        <div v-if="canEdit" class="rd-actions">
          <button type="button" class="outline-btn rd-danger" :disabled="busy" @click="run({ action: 'cancel_pay_link', note })">{{ $t('pages.returns.cancelLink') }}</button>
        </div>
      </div>
      <div v-if="canEdit && r.status === 'awaiting_return'" class="rd-card">
        <h4>{{ $t('pages.returns.whenParcelArrives') }}</h4>
        <p class="rd-hint">{{ $t('pages.returns.tickUnitsHint') }}</p>
        <label class="rd-label" for="rt-rnote">{{ $t('pages.returns.noteOptional') }}</label>
        <input id="rt-rnote" v-model="note" type="text" maxlength="400" :placeholder="$t('pages.returns.receiveNotePlaceholder')">
        <p v-if="actionError" class="rd-error-line" role="alert">{{ actionError }}</p>
        <div class="rd-actions">
          <button type="button" class="outline-btn rd-danger" :disabled="busy" @click="rejectOpen = true">{{ $t('pages.returns.rejectReturn') }}</button>
          <button type="button" class="primary-btn" :disabled="busy" @click="receive">{{ busy ? $t('pages.returns.saving') : $t('pages.returns.markReceived') }}</button>
        </div>
      </div>

      <!-- ========================= Complete exchange ========================= -->
      <div v-if="canEdit && r.status === 'received' && isExchange && r.exchange_quote && !refundInstead" class="rd-card">
        <h4>{{ $t('pages.returns.completeTheExchange') }}</h4>
        <p class="rd-hint">{{ $t('pages.returns.completeExchangeHint') }}</p>

        <template v-if="r.exchange_quote.difference > 0">
          <p class="rd-callout">{{ $t('pages.returns.customerOwes', { amount: currencyIcon + money(r.exchange_quote.difference) }) }}</p>
          <label class="rd-label" for="ex-amount">{{ $t('pages.returns.amountTaken') }}</label>
          <div class="rd-inline">
            <input id="ex-amount" v-model="exAmount" type="number" min="0" step="0.01" :max="r.exchange_quote.difference" :placeholder="money(r.exchange_quote.difference)">
            <span class="rd-hint-inline">{{ $t('pages.returns.leaveEmptyDifference') }}</span>
          </div>
          <template v-if="exFigure > 0">
            <label class="rd-label" for="ex-ref">{{ $t('pages.returns.wherePaymentTaken') }}</label>
            <input id="ex-ref" v-model="exReference" type="text" maxlength="255" :placeholder="$t('pages.returns.paymentRefPlaceholder')">
          </template>
        </template>

        <template v-else-if="r.exchange_quote.difference < 0">
          <p class="rd-callout">{{ $t('pages.returns.newVariantCheaper', { amount: currencyIcon + money(r.exchange_quote.refundable) }) }}</p>
          <div class="rd-methods" role="radiogroup" :aria-label="$t('pages.returns.howDifferenceRefunded')">
            <label class="rd-check" :class="{disabled: r.order && r.order.cash_on_delivery}">
              <input type="radio" value="original" v-model="exMethod" :disabled="r.order && r.order.cash_on_delivery"> {{ $t('pages.returns.toOriginalPayment') }}
            </label>
            <label class="rd-check"><input type="radio" value="manual" v-model="exMethod"> {{ $t('pages.returns.alreadyRefunded') }}</label>
            <label class="rd-check"><input type="radio" value="none" v-model="exMethod"> {{ $t('pages.returns.doNotRefundDifference') }}</label>
          </div>
          <template v-if="exMethod !== 'none'">
            <label class="rd-label" for="ex-ramount">{{ $t('pages.returns.amount') }}</label>
            <div class="rd-inline">
              <input id="ex-ramount" v-model="exAmount" type="number" min="0" step="0.01" :max="r.exchange_quote.refundable" :placeholder="money(r.exchange_quote.refundable)">
              <span class="rd-hint-inline">{{ $t('pages.returns.upTo', { amount: currencyIcon + money(r.exchange_quote.refundable) }) }}</span>
            </div>
            <template v-if="exMethod === 'manual'">
              <label class="rd-label" for="ex-rref">{{ $t('pages.returns.whereRefunded') }}</label>
              <input id="ex-rref" v-model="exReference" type="text" maxlength="255">
            </template>
          </template>
        </template>

        <p v-else class="rd-callout">{{ $t('pages.returns.samePrice') }}</p>

        <label class="rd-label" for="ex-note">{{ $t('pages.returns.noteOptional') }}</label>
        <input id="ex-note" v-model="note" type="text" maxlength="400">

        <p v-if="actionError" class="rd-error-line" role="alert">{{ actionError }}</p>
        <div class="rd-actions">
          <button type="button" class="outline-btn" :disabled="busy" @click="refundInstead = true">{{ $t('pages.returns.refundInstead') }}</button>
          <button
            v-if="r.exchange_quote.difference >= 0.5 && r.exchange_quote.in_stock"
            type="button" class="outline-btn" :disabled="busy" @click="payLinkOpen = true"
          >{{ $t('pages.returns.sendPaymentLink') }}</button>
          <button type="button" class="primary-btn" :disabled="busy || !exchangeValid" @click="exchangeOpen = true">{{ $t('pages.returns.completeExchange') }}</button>
        </div>
      </div>

      <div v-if="canEdit && r.status === 'received' && r.quote && (!isExchange || refundInstead)" class="rd-card">
        <h4>{{ $t('pages.returns.refund') }}</h4>
        <p v-if="isExchange" class="rd-hint">
          {{ $t('pages.returns.refundClosesExchange') }}
          <button type="button" class="rd-linkbtn" @click="refundInstead = false">{{ $t('pages.returns.backToExchange') }}</button>
        </p>

        <div class="rd-methods" role="radiogroup" :aria-label="$t('pages.returns.howRefundMade')">
          <label class="rd-check" :class="{disabled: r.order && r.order.cash_on_delivery}">
            <input type="radio" value="original" v-model="method" :disabled="r.order && r.order.cash_on_delivery">
            {{ $t('pages.returns.toOriginalPayment') }}
            <span class="rd-hint-inline">{{ r.order && r.order.wallet_amount > 0 ? $t('pages.returns.cardFirstThenWallet') : '' }}</span>
          </label>
          <label class="rd-check">
            <input type="radio" value="manual" v-model="method"> {{ $t('pages.returns.alreadyRefunded') }}
          </label>
          <label class="rd-check">
            <input type="radio" value="none" v-model="method"> {{ $t('pages.returns.noRefundClose') }}
          </label>
        </div>
        <p v-if="r.order && r.order.cash_on_delivery" class="rd-hint">{{ $t('pages.returns.codRefundYourself') }}</p>

        <template v-if="method !== 'none'">
          <label v-if="r.quote.with_shipping.shipping_refund > 0" class="rd-check rd-gap">
            <input type="checkbox" v-model="refundShipping"> {{ $t('pages.returns.refundDeliveryToo', { amount: currencyIcon + money(r.quote.with_shipping.shipping_refund) }) }}
          </label>

          <label class="rd-label" for="rt-amount">{{ $t('pages.returns.amount') }}</label>
          <div class="rd-inline">
            <input id="rt-amount" v-model="amount" type="number" min="0" step="0.01" :max="activeQuote.refund" :placeholder="money(activeQuote.refund)">
            <span class="rd-hint-inline">{{ $t('pages.returns.upToDeduction', { amount: currencyIcon + money(activeQuote.refund) }) }}</span>
          </div>

          <template v-if="method === 'manual'">
            <label class="rd-label" for="rt-ref">{{ $t('pages.returns.whereRefunded') }}</label>
            <input id="rt-ref" v-model="reference" type="text" maxlength="255" :placeholder="$t('pages.returns.refundRefPlaceholder')">
          </template>
        </template>

        <label class="rd-label" for="rt-fnote">{{ $t('pages.returns.noteOptional') }}</label>
        <input id="rt-fnote" v-model="note" type="text" maxlength="400">

        <p v-if="actionError" class="rd-error-line" role="alert">{{ actionError }}</p>
        <div class="rd-actions">
          <button type="button" class="primary-btn" :disabled="busy || !refundValid" @click="refundOpen = true">
            {{ method === 'none' ? $t('pages.returns.closeWithoutRefund') : $t('pages.returns.refundAmount', { amount: currencyIcon + money(refundFigure) }) }}
          </button>
        </div>
      </div>

      <div class="rd-card">
        <h4>{{ $t('pages.returns.history') }}</h4>
        <ul class="rd-history">
          <li v-for="(h, i) in r.history" :key="i">
            <span class="rd-when">{{ dateTime(h.at) }}</span>
            <strong>{{ statusName(h.to) }}</strong>
            <span v-if="h.note"> — {{ h.note }}</span>
            <span v-if="h.admin" class="rd-hint-inline"> {{ $t('pages.returns.byAdmin', { admin: h.admin }) }}</span>
          </li>
        </ul>
        <div v-if="canEdit" class="rd-inline rd-gap">
          <input v-model="newNote" type="text" maxlength="400" :placeholder="$t('pages.returns.addNote')" :aria-label="$t('pages.returns.addNote')">
          <button type="button" class="outline-btn xs" :disabled="busy || !newNote.trim()" @click="addNote">{{ $t('pages.returns.add') }}</button>
        </div>
      </div>
    </template>

    <confirm-dialog
      :open="refundOpen"
      :title="method === 'none' ? $t('pages.returns.closeWithoutRefundTitle') : $t('pages.returns.makeRefundTitle')"
      tone="primary"
      icon="fa-solid fa-rotate-left"
      :confirm-label="method === 'none' ? $t('pages.returns.closeReturn') : $t('pages.returns.refund')"
      :busy-label="$t('pages.returns.working')"
      :busy="busy"
      :warning="method === 'original' ? $t('pages.returns.refundWarning') : ''"
      @cancel="refundOpen = false"
      @confirm="refund"
    >
      <template v-slot:subject>
        <template v-if="method === 'none'">{{ $t('pages.returns.itemsStayNothingBack') }}</template>
        <template v-else>{{ currencyIcon }}{{ money(refundFigure) }} {{ methodLabel(method) }}<template v-if="activeQuote && activeQuote.gift_card > 0">{{ $t('pages.returns.plusGiftCard', { amount: currencyIcon + money(activeQuote.gift_card) }) }}</template>.</template>
      </template>
    </confirm-dialog>

    <confirm-dialog
      :open="payLinkOpen"
      :title="$t('pages.returns.emailPayLinkTitle')"
      tone="primary"
      icon="fa-solid fa-link"
      :confirm-label="$t('pages.returns.sendLink')"
      :busy-label="$t('pages.returns.sending')"
      :busy="busy"
      @cancel="payLinkOpen = false"
      @confirm="sendPayLink"
    >
      <template v-slot:subject>
        {{ $t('pages.returns.payLinkBody', { amount: currencyIcon + money(r && r.exchange_quote ? r.exchange_quote.difference : 0) }) }}
        {{ r && r.items.length > 1 ? $t('pages.returns.payLinkUnitsHeld') : $t('pages.returns.payLinkUnitHeld') }}
      </template>
    </confirm-dialog>

    <confirm-dialog
      :open="exchangeOpen"
      :title="$t('pages.returns.completeExchangeTitle')"
      tone="primary"
      icon="fa-solid fa-right-left"
      :confirm-label="$t('pages.returns.completeExchange')"
      :busy-label="$t('pages.returns.working')"
      :busy="busy"
      :warning="r && r.exchange_quote && r.exchange_quote.difference < 0 && exMethod === 'original' && exFigure > 0 ? $t('pages.returns.exchangeRefundWarning') : ''"
      @cancel="exchangeOpen = false"
      @confirm="completeExchange"
    >
      <template v-slot:subject>{{ $t('pages.returns.exchangeSubject') }}</template>
    </confirm-dialog>

    <confirm-dialog
      :open="rejectOpen"
      :title="$t('pages.returns.rejectTitle')"
      :confirm-label="$t('pages.returns.reject')"
      :busy-label="$t('pages.returns.rejecting')"
      :busy="busy"
      @cancel="rejectOpen = false"
      @confirm="reject"
    >
      <template v-slot:subject>{{ $t('pages.returns.rejectSubject') }}</template>
      <div class="rd-form">
        <label class="rd-label" for="rt-jnote">{{ $t('pages.returns.reasonStaffOnly') }}</label>
        <input id="rt-jnote" v-model="note" type="text" maxlength="400">
        <label class="rd-check rd-gap">
          <input type="checkbox" v-model="notifyReject"> {{ $t('pages.returns.emailNotAccepted') }}
        </label>
      </div>
    </confirm-dialog>
  </div>
</template>

<script>
import ConfirmDialog from '~/components/ConfirmDialog'
import util from '~/mixin/util'
import { mapGetters, mapActions } from 'vuex'

// i18n keys; translated in statusName()
const STATUS = {
  awaiting_return: 'pages.returns.statusAwaitingReturn',
  received: 'pages.returns.statusReceived',
  refunding: 'pages.returns.statusRefunding',
  refunded: 'pages.returns.statusRefunded',
  closed: 'pages.returns.statusClosed',
  rejected: 'pages.returns.statusRejected',
  exchanged: 'pages.returns.statusExchanged',
  requested: 'pages.returns.statusRequested',
  awaiting_payment: 'pages.returns.statusAwaitingPayment',
  cancelled: 'pages.returns.statusCancelled'
}

export default {
  name: 'return-detail',
  middleware: ['common-middleware', 'auth'],
  components: { ConfirmDialog },
  mixins: [util],

  data() {
    return {
      r: null,
      loading: true,
      loadError: '',
      busy: false,
      actionError: '',
      restock: {},
      note: '',
      newNote: '',
      method: 'original',
      refundShipping: false,
      amount: '',
      reference: '',
      refundOpen: false,
      rejectOpen: false,
      exchangeOpen: false,
      payLinkOpen: false,
      notifyApprove: true,
      copied: false,
      refundInstead: false,
      exAmount: '',
      exReference: '',
      exMethod: 'original',
      notifyReject: true
    }
  },

  computed: {
    currencyIcon() {
      return this.setting?.currency_icon || '€'
    },
    canEdit() {
      return this.$can('order', 'edit')
    },
    done() {
      return ['refunded', 'closed', 'exchanged'].includes(this.r?.status)
    },
    isExchange() {
      return this.r?.type === 'exchange'
    },
    exFigure() {
      const q = this.r?.exchange_quote
      if (!q) return 0
      const typed = String(this.exAmount).trim()
      if (q.difference > 0) return typed === '' ? Number(q.difference) : Number(typed)
      if (q.difference < 0) return this.exMethod === 'none' ? 0 : (typed === '' ? Number(q.refundable) : Number(typed))
      return 0
    },
    exchangeValid() {
      const q = this.r?.exchange_quote
      if (!q || !q.in_stock) return false
      const max = q.difference > 0 ? Number(q.difference) : Number(q.refundable)
      if (isNaN(this.exFigure) || this.exFigure < 0 || this.exFigure > max + 0.001) return false
      if (q.difference > 0 && this.exFigure > 0 && !this.exReference.trim()) return false
      if (q.difference < 0 && this.exMethod === 'manual' && this.exFigure > 0 && !this.exReference.trim()) return false
      return true
    },
    activeQuote() {
      if (!this.r?.quote) return null
      return this.refundShipping ? this.r.quote.with_shipping : this.r.quote.without_shipping
    },
    refundFigure() {
      if (this.method === 'none') return 0
      const typed = String(this.amount).trim()
      return typed === '' ? Number(this.activeQuote?.refund || 0) : Number(typed)
    },
    refundValid() {
      if (this.method === 'none') return true
      const max = Number(this.activeQuote?.refund || 0)
      if (isNaN(this.refundFigure) || this.refundFigure < 0 || this.refundFigure > max + 0.001) return false
      return this.method !== 'manual' || this.reference.trim() !== ''
    },
    pillClass() {
      return {
        awaiting_return: 'pill-wait', received: 'pill-wait', refunding: 'pill-bad',
        refunded: 'pill-on', exchanged: 'pill-on', closed: 'pill-off', rejected: 'pill-off',
        requested: 'pill-wait', awaiting_payment: 'pill-wait', cancelled: 'pill-off'
      }[this.r?.status] || 'pill-off'
    },
    ...mapGetters('setting', ['setting'])
  },

  methods: {
    money(v) {
      return Number(v || 0).toFixed(2)
    },
    dateTime(v) {
      if (!v) return ''
      const d = new Date(String(v).replace(' ', 'T'))
      return isNaN(d.getTime()) ? v : d.toLocaleString(this.$i18n?.locale || 'en', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
    },
    statusName(s) {
      return STATUS[s] ? this.$t(STATUS[s]) : s
    },
    differenceText(v) {
      const n = Number(v || 0)
      if (n > 0) return this.$t('pages.returns.customerPays', { amount: this.currencyIcon + this.money(n) })
      if (n < 0) return this.$t('pages.returns.customerIsOwed', { amount: this.currencyIcon + this.money(-n) })
      return this.$t('pages.returns.noneSamePrice')
    },
    exchangeLine(item) {
      return this.r?.exchange_quote?.items?.[item.id] || null
    },
    approve() {
      return this.run({ action: 'approve', note: this.note, notify: this.notifyApprove })
    },
    async sendPayLink() {
      await this.run({ action: 'send_pay_link' })
      this.payLinkOpen = false
    },
    async copyLink() {
      try {
        await navigator.clipboard.writeText(this.r.pay_link.url)
        this.copied = true
        setTimeout(() => { this.copied = false }, 2000)
      } catch (e) {
        this.copied = false
      }
    },
    async completeExchange() {
      const q = this.r.exchange_quote
      const typed = String(this.exAmount).trim()
      await this.run({
        action: 'complete_exchange',
        amount: typed === '' ? null : this.exAmount,
        reference: this.exReference,
        method: q && q.difference < 0 ? this.exMethod : null,
        note: this.note
      })
      this.exchangeOpen = false
      if (this.actionError) this.load()
    },
    methodLabel(m) {
      return { original: this.$t('pages.returns.methodOriginal'), manual: this.$t('pages.returns.methodManual'), none: '' }[m] || ''
    },
    show(data) {
      this.r = data
      this.note = ''
      this.amount = ''
      this.reference = ''
      this.exAmount = ''
      this.exReference = ''
      this.refundInstead = false
      this.actionError = ''
      // Faulty or damaged goods default to not going back on sale.
      const resell = !['faulty', 'damaged'].includes(data.reason)
      const restock = {}
      data.items.forEach(item => { restock[item.id] = resell })
      this.restock = restock
      if (data.order?.cash_on_delivery && this.method === 'original') this.method = 'manual'
    },
    async load() {
      this.loading = true
      try {
        this.show(await this.getById({ id: this.$route.params.id, params: {}, api: 'getOrderReturn' }))
      } catch (e) {
        this.loadError = e?.message || this.$t('pages.returns.loadFailed')
      } finally {
        this.loading = false
      }
    },
    async run(params) {
      this.busy = true
      this.actionError = ''
      try {
        const data = await this.setById({ id: this.r.id, params, api: 'setOrderReturnAction' })
        if (data) {
          this.show(data)
          return true
        }
        // refused: setById leaves the message in ui/errors and resolves with nothing
        const errors = this.$store.getters['ui/errors']
        this.actionError = (errors?.form || [])[0] || this.$t('pages.returns.actionFailed')
      } catch (e) {
        this.actionError = e?.message || this.$t('pages.returns.actionFailed')
      } finally {
        this.busy = false
      }
      return false
    },
    receive() {
      return this.run({ action: 'receive', restock: this.restock, note: this.note })
    },
    async refund() {
      await this.run({
        action: 'refund',
        method: this.method,
        amount: this.method === 'none' ? null : (String(this.amount).trim() === '' ? null : this.amount),
        refund_shipping: this.method !== 'none' && this.refundShipping,
        reference: this.reference,
        note: this.note
      })
      this.refundOpen = false
      // a refusal may mean the case moved on (another tab, a stuck refund): show where it is now
      if (this.actionError) this.load()
    },
    async reject() {
      await this.run({ action: 'reject', note: this.note, notify: this.notifyReject })
      this.rejectOpen = false
    },
    async addNote() {
      const note = this.newNote
      if (await this.run({ action: 'note', note })) this.newNote = ''
    },
    ...mapActions('common', ['getById', 'setById'])
  },

  mounted() {
    this.load()
  }
}
</script>

<style scoped>
.rd-back { display: inline-block; margin-bottom: 12px; font-size: 13px; color: #4f6ef7; }
.rd-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 20px; margin-bottom: 12px; }
.rd-card h4 { font-size: 15px; font-weight: 600; margin: 0 0 12px; }
.rd-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
.rd-title { margin: 0; font-size: 20px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.rd-sub { margin: 4px 0 0; color: #6b7280; font-size: 13px; }
.rd-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px; }
.rd-grid .rd-card { margin-bottom: 0; }
.rd-dl { display: grid; grid-template-columns: 140px 1fr; gap: 8px 12px; font-size: 13px; margin: 0; }
.rd-dl dt { color: #6b7280; }
.rd-dl dd { margin: 0; word-break: break-word; }
.rd-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; }
.rd-table { width: 100%; font-size: 13px; border-collapse: collapse; }
.rd-table th, .rd-table td { text-align: left; padding: 10px 6px; border-bottom: 1px solid #f2f4f7; vertical-align: middle; }
.rd-label { display: block; font-size: 13px; font-weight: 600; margin: 14px 0 6px; }
.rd-card input[type="text"], .rd-card input[type="number"], .rd-form input[type="text"] { width: 100%; height: 40px; padding: 0 10px; border: 1px solid #d0d5dd; border-radius: 8px; font-size: 14px; }
.rd-inline { display: flex; gap: 10px; align-items: center; }
.rd-inline input { flex: 0 0 160px; }
.rd-inline input[type="text"] { flex: 1; }
.rd-methods { display: flex; flex-direction: column; gap: 8px; }
.rd-check { display: flex; gap: 8px; align-items: center; font-size: 13px; cursor: pointer; margin: 0; }
/* auto collapses a checkbox to a dot here; the admin's own convention is 16px (label.checkbox) */
.rd-check input { flex: 0 0 16px; width: 16px !important; height: 16px !important; margin: 0; }
.rd-check.disabled { opacity: .5; cursor: not-allowed; }
.rd-gap { margin-top: 12px; }
.rd-hint { font-size: 12px; color: #6b7280; margin: 6px 0 0; }
.rd-hint-inline { font-size: 12px; color: #6b7280; font-weight: normal; }
.rd-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 16px; }
.rd-actions button { height: 42px; line-height: 40px; padding: 0 20px; width: auto; }
.rd-danger { color: #b42318 !important; border-color: #fecdca !important; }
.rd-error { color: #b42318; }
.rd-error-line { font-size: 13px; color: #b42318; background: #fef3f2; border: 1px solid #fecdca; border-radius: 6px; padding: 8px 10px; margin: 0 0 12px; }
.rd-card .rd-error-line { margin: 12px 0 0; }
.rd-history { list-style: none; margin: 0; padding: 0; font-size: 13px; }
/* base.styl makes every li inline-block, which ran the entries into one line */
.rd-history li { display: block; padding: 6px 0; border-bottom: 1px solid #f2f4f7; }
.rd-when { color: #6b7280; margin-right: 8px; }
.rd-form { text-align: left; }
.pill { display: inline-block; padding: 3px 12px; border-radius: 999px; font-size: 12px; font-weight: 600; white-space: nowrap; }
.pill-on { background: #ecfdf3; color: #027a48; }
.pill-type { background: #eef2ff; color: #33319a; }
.rd-card-attn { border-color: #fedf89; box-shadow: inset 4px 0 0 #f79009; }
.rd-link-row { display: flex; gap: 8px; align-items: center; }
.rd-link-text { overflow-wrap: anywhere; }
.rd-gap-left { margin-left: 8px; }
.rd-pills { display: flex; gap: 8px; align-items: center; }
.rd-callout { font-size: 14px; background: #f9fafb; border: 1px solid #eaecf0; border-radius: 8px; padding: 10px 12px; margin: 12px 0 0; }
.rd-linkbtn { background: none !important; border: 0; padding: 0; height: auto; line-height: inherit; width: auto; color: #4f6ef7; font-size: 12px; text-decoration: underline; cursor: pointer; }
.pill-wait { background: #fffaeb; color: #b54708; }
.pill-off { background: #f2f4f7; color: #475467; }
.pill-bad { background: #fef3f2; color: #b42318; }
@media (max-width: 900px) { .rd-grid { grid-template-columns: 1fr; } .rd-inline { flex-wrap: wrap; } }
</style>
