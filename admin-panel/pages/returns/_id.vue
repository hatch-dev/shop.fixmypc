<template>
  <div class="return-page p-20">
    <nuxt-link to="/returns" class="rd-back">← Returns</nuxt-link>

    <div v-if="loading" class="rd-card">Loading…</div>
    <div v-else-if="loadError" class="rd-card rd-error">{{ loadError }}</div>

    <template v-else-if="r">
      <div class="rd-card rd-head">
        <div>
          <h3 class="rd-title">{{ r.rma_number }}</h3>
          <p class="rd-sub">
            <nuxt-link v-if="r.order" class="link" :to="`/orders/${r.order.id}`">Order #{{ r.order.order }}</nuxt-link>
            <template v-if="r.order && r.order.contact_name"> · {{ r.order.contact_name }}</template>
            <template v-if="r.order && r.order.contact_email"> · {{ r.order.contact_email }}</template>
          </p>
        </div>
        <div class="rd-pills">
          <span v-if="isExchange" class="pill pill-type">Exchange</span>
          <span class="pill" :class="pillClass">{{ r.status_label }}</span>
        </div>
      </div>

      <p v-if="r.refund_error" class="rd-error-line" role="alert">
        <strong>{{ r.status === 'refunding' ? 'Refund needs checking:' : 'Last refund attempt failed:' }}</strong>
        {{ r.refund_error }}
      </p>

      <div class="rd-grid">
        <div class="rd-card">
          <h4>Return</h4>
          <dl class="rd-dl">
            <dt>Reason</dt><dd>{{ r.reason_label }}</dd>
            <template v-if="r.customer_note"><dt>Customer said</dt><dd>{{ r.customer_note }}</dd></template>
            <template v-if="r.staff_note"><dt>Staff note</dt><dd>{{ r.staff_note }}</dd></template>
            <dt>Opened</dt><dd>{{ dateTime(r.created_at) }}</dd>
            <template v-if="r.received_at"><dt>Received</dt><dd>{{ dateTime(r.received_at) }}</dd></template>
            <template v-if="r.refunded_at"><dt>Refunded</dt><dd>{{ dateTime(r.refunded_at) }}</dd></template>
            <template v-if="r.closed_at && !r.refunded_at"><dt>Closed</dt><dd>{{ dateTime(r.closed_at) }}</dd></template>
          </dl>
        </div>

        <div v-if="isExchange" class="rd-card">
          <h4>{{ r.status === 'exchanged' ? 'Exchange made' : 'Exchange' }}</h4>
          <dl v-if="r.status === 'exchanged'" class="rd-dl">
            <template v-if="r.replacement_order">
              <dt>Replacement order</dt>
              <dd><nuxt-link class="link" :to="`/orders/${r.replacement_order.id}`">#{{ r.replacement_order.order }}</nuxt-link></dd>
            </template>
            <dt>Difference</dt><dd>{{ differenceText(r.exchange_difference) }}</dd>
            <template v-if="r.amount_charged > 0"><dt>Charged</dt><dd>{{ currencyIcon }}{{ money(r.amount_charged) }} <span class="rd-hint-inline">{{ r.payment_reference }}</span></dd></template>
            <template v-if="r.exchange_difference > 0 && !(r.amount_charged > 0)"><dt>Charged</dt><dd>Nothing (waived)</dd></template>
            <template v-if="r.refund_amount > 0"><dt>Refunded</dt><dd>{{ currencyIcon }}{{ money(r.refund_amount) }} <span class="rd-hint-inline">{{ methodLabel(r.refund_method) }}</span></dd></template>
            <template v-if="r.refund_reference"><dt>Reference</dt><dd class="rd-mono">{{ r.refund_reference }}</dd></template>
          </dl>
          <!-- Waiting on a pay link: the amount asked for, not today's quote (its units are held). -->
          <dl v-else-if="r.status === 'awaiting_payment' && r.pay_link" class="rd-dl">
            <dt>Difference</dt><dd>{{ differenceText(r.pay_link.amount) }}</dd>
            <dt>Paying by</dt><dd>Card, by the link below</dd>
          </dl>
          <p v-else-if="!r.exchange_quote" class="rd-hint">{{ r.status_label }}.</p>
          <template v-else>
            <dl class="rd-dl">
              <dt>Difference</dt><dd>{{ differenceText(r.exchange_quote.difference) }}</dd>
              <template v-if="r.exchange_quote.difference < 0"><dt>Can refund</dt><dd>{{ currencyIcon }}{{ money(r.exchange_quote.refundable) }}</dd></template>
            </dl>
            <p class="rd-hint">At today's prices for this customer: the new variant against what they paid for the old one.</p>
            <p v-if="!r.exchange_quote.in_stock" class="rd-error-line" role="alert">A variant to swap to is out of stock. Restock it, or refund instead.</p>
          </template>
        </div>

        <div v-else class="rd-card">
          <h4>{{ done ? 'What went back' : 'Refund if accepted' }}</h4>
          <dl v-if="done" class="rd-dl">
            <dt>Money</dt><dd>{{ currencyIcon }}{{ money(r.refund_amount) }} <span class="rd-hint-inline">{{ methodLabel(r.refund_method) }}</span></dd>
            <template v-if="r.shipping_refund > 0"><dt>of which delivery</dt><dd>{{ currencyIcon }}{{ money(r.shipping_refund) }}</dd></template>
            <template v-if="r.gift_card_amount > 0"><dt>To gift card</dt><dd>{{ currencyIcon }}{{ money(r.gift_card_amount) }}</dd></template>
            <template v-if="r.points_returned > 0"><dt>Points back</dt><dd>{{ r.points_returned }}</dd></template>
            <template v-if="r.points_taken > 0"><dt>Points taken back</dt><dd>{{ r.points_taken }}</dd></template>
            <template v-if="r.refund_reference"><dt>Reference</dt><dd class="rd-mono">{{ r.refund_reference }}</dd></template>
          </dl>
          <dl v-else-if="activeQuote" class="rd-dl">
            <dt>Items</dt><dd>{{ currencyIcon }}{{ money(activeQuote.items_refund) }}</dd>
            <dt>Delivery</dt><dd>{{ currencyIcon }}{{ money(r.quote.with_shipping.shipping_refund) }} <span class="rd-hint-inline">if refunded</span></dd>
            <template v-if="activeQuote.gift_card > 0"><dt>To gift card</dt><dd>{{ currencyIcon }}{{ money(activeQuote.gift_card) }}</dd></template>
            <template v-if="activeQuote.points_returned > 0"><dt>Points back</dt><dd>{{ activeQuote.points_returned }}</dd></template>
            <template v-if="activeQuote.points_taken > 0"><dt>Points taken back</dt><dd>{{ activeQuote.points_taken }}</dd></template>
            <dt>Left on the order</dt><dd>{{ currencyIcon }}{{ money(activeQuote.money_left) }}</dd>
          </dl>
          <p v-else class="rd-hint">Nothing to refund.</p>
        </div>
      </div>

      <div class="rd-card">
        <h4>Items</h4>
        <table class="rd-table">
          <tr>
            <th>Item</th><th>Units</th>
            <th>{{ r.status === 'awaiting_return' ? 'Put back in stock' : 'Back in stock' }}</th>
            <th v-if="isExchange">Swap to</th>
            <th v-if="done && !isExchange">Item share</th>
          </tr>
          <tr v-for="item in r.items" :key="item.id">
            <td>
              <strong>{{ item.title }}</strong>
              <span v-if="item.variant" class="rd-hint-inline"> · {{ item.variant }}</span>
            </td>
            <td>{{ item.quantity }}</td>
            <td>
              <label v-if="r.status === 'awaiting_return' && canEdit" class="rd-check">
                <input type="checkbox" v-model="restock[item.id]"> Yes
              </label>
              <template v-else-if="item.restock === null">—</template>
              <template v-else>{{ item.restock ? `Yes (${item.restocked_qty})` : 'No, not sellable' }}</template>
            </td>
            <td v-if="isExchange">
              {{ item.exchange_label || '—' }}
              <template v-if="exchangeLine(item)">
                <span class="rd-hint-inline">· {{ currencyIcon }}{{ money(exchangeLine(item).new_unit_price) }} each (paid {{ currencyIcon }}{{ money(exchangeLine(item).old_unit_price) }})</span>
                <span v-if="!exchangeLine(item).available" class="pill pill-bad">out of stock</span>
              </template>
            </td>
            <td v-if="done && !isExchange">{{ currencyIcon }}{{ money(item.line_refund) }}</td>
          </tr>
        </table>
      </div>

      <!-- ============================ Actions ============================ -->
      <div v-if="canEdit && r.status === 'requested'" class="rd-card rd-card-attn">
        <h4>Review this request</h4>
        <p class="rd-hint">
          The customer asked for this from their order page{{ r.customer_note ? ' and told us why' : '' }}.
          Approving emails them the return number and where to send the {{ isExchange ? 'item' : 'items' }}.
        </p>
        <label class="rd-label" for="rt-anote">Note (optional)</label>
        <input id="rt-anote" v-model="note" type="text" maxlength="400">
        <label class="rd-check rd-gap">
          <input type="checkbox" v-model="notifyApprove"> Email the customer
        </label>
        <p v-if="actionError" class="rd-error-line" role="alert">{{ actionError }}</p>
        <div class="rd-actions">
          <button type="button" class="outline-btn rd-danger" :disabled="busy" @click="rejectOpen = true">Reject</button>
          <button type="button" class="primary-btn" :disabled="busy" @click="approve">{{ busy ? 'Saving…' : 'Approve' }}</button>
        </div>
      </div>

      <div v-if="r.status === 'awaiting_payment' && r.pay_link" class="rd-card rd-card-attn">
        <h4>Waiting for the customer to pay</h4>
        <dl class="rd-dl">
          <dt>Amount</dt><dd>{{ currencyIcon }}{{ money(r.pay_link.amount) }} by card ({{ r.pay_link.gateway }})</dd>
          <dt>Link</dt>
          <dd class="rd-link-row">
            <span class="rd-mono rd-link-text">{{ r.pay_link.url }}</span>
            <button type="button" class="outline-btn xs" @click="copyLink">{{ copied ? 'Copied' : 'Copy' }}</button>
          </dd>
          <dt>Valid until</dt>
          <dd>{{ dateTime(r.pay_link.expires_at) }}<span v-if="r.pay_link.status === 'expired'" class="pill pill-bad rd-gap-left">expired</span></dd>
          <dt>Started</dt><dd>{{ r.pay_link.started ? 'The customer has opened the card form' : 'Not yet' }}</dd>
        </dl>
        <p class="rd-hint">The new {{ r.items.length > 1 ? 'units are' : 'unit is' }} held out of stock. The exchange completes by itself the moment the payment is confirmed.</p>
        <p v-if="actionError" class="rd-error-line" role="alert">{{ actionError }}</p>
        <div v-if="canEdit" class="rd-actions">
          <button type="button" class="outline-btn rd-danger" :disabled="busy" @click="run({ action: 'cancel_pay_link', note })">Cancel link</button>
        </div>
      </div>
      <div v-if="canEdit && r.status === 'awaiting_return'" class="rd-card">
        <h4>When the parcel arrives</h4>
        <p class="rd-hint">Tick the units that can be sold again; the rest stay out of stock. You refund in the next step.</p>
        <label class="rd-label" for="rt-rnote">Note (optional)</label>
        <input id="rt-rnote" v-model="note" type="text" maxlength="400" placeholder="e.g. Box opened, phone unused">
        <p v-if="actionError" class="rd-error-line" role="alert">{{ actionError }}</p>
        <div class="rd-actions">
          <button type="button" class="outline-btn rd-danger" :disabled="busy" @click="rejectOpen = true">Reject return</button>
          <button type="button" class="primary-btn" :disabled="busy" @click="receive">{{ busy ? 'Saving…' : 'Mark as received' }}</button>
        </div>
      </div>

      <!-- ========================= Complete exchange ========================= -->
      <div v-if="canEdit && r.status === 'received' && isExchange && r.exchange_quote && !refundInstead" class="rd-card">
        <h4>Complete the exchange</h4>
        <p class="rd-hint">Creates the replacement order, confirmed and ready to ship, and takes the new units from stock.</p>

        <template v-if="r.exchange_quote.difference > 0">
          <p class="rd-callout">The customer owes {{ currencyIcon }}{{ money(r.exchange_quote.difference) }}. Take the payment, then record it here.</p>
          <label class="rd-label" for="ex-amount">Amount taken</label>
          <div class="rd-inline">
            <input id="ex-amount" v-model="exAmount" type="number" min="0" step="0.01" :max="r.exchange_quote.difference" :placeholder="money(r.exchange_quote.difference)">
            <span class="rd-hint-inline">Leave empty for the full difference; 0 to waive it.</span>
          </div>
          <template v-if="exFigure > 0">
            <label class="rd-label" for="ex-ref">Where the payment was taken</label>
            <input id="ex-ref" v-model="exReference" type="text" maxlength="255" placeholder="e.g. card terminal receipt, bank transfer ref">
          </template>
        </template>

        <template v-else-if="r.exchange_quote.difference < 0">
          <p class="rd-callout">The new variant is cheaper: the customer is owed {{ currencyIcon }}{{ money(r.exchange_quote.refundable) }}.</p>
          <div class="rd-methods" role="radiogroup" aria-label="How the difference is refunded">
            <label class="rd-check" :class="{disabled: r.order && r.order.cash_on_delivery}">
              <input type="radio" value="original" v-model="exMethod" :disabled="r.order && r.order.cash_on_delivery"> To the original payment
            </label>
            <label class="rd-check"><input type="radio" value="manual" v-model="exMethod"> Already refunded another way (record it)</label>
            <label class="rd-check"><input type="radio" value="none" v-model="exMethod"> Do not refund the difference</label>
          </div>
          <template v-if="exMethod !== 'none'">
            <label class="rd-label" for="ex-ramount">Amount</label>
            <div class="rd-inline">
              <input id="ex-ramount" v-model="exAmount" type="number" min="0" step="0.01" :max="r.exchange_quote.refundable" :placeholder="money(r.exchange_quote.refundable)">
              <span class="rd-hint-inline">Up to {{ currencyIcon }}{{ money(r.exchange_quote.refundable) }}.</span>
            </div>
            <template v-if="exMethod === 'manual'">
              <label class="rd-label" for="ex-rref">Where it was refunded</label>
              <input id="ex-rref" v-model="exReference" type="text" maxlength="255">
            </template>
          </template>
        </template>

        <p v-else class="rd-callout">Same price: nothing to pay either way.</p>

        <label class="rd-label" for="ex-note">Note (optional)</label>
        <input id="ex-note" v-model="note" type="text" maxlength="400">

        <p v-if="actionError" class="rd-error-line" role="alert">{{ actionError }}</p>
        <div class="rd-actions">
          <button type="button" class="outline-btn" :disabled="busy" @click="refundInstead = true">Refund instead</button>
          <button
            v-if="r.exchange_quote.difference >= 0.5 && r.exchange_quote.in_stock"
            type="button" class="outline-btn" :disabled="busy" @click="payLinkOpen = true"
          >Send payment link</button>
          <button type="button" class="primary-btn" :disabled="busy || !exchangeValid" @click="exchangeOpen = true">Complete exchange</button>
        </div>
      </div>

      <div v-if="canEdit && r.status === 'received' && r.quote && (!isExchange || refundInstead)" class="rd-card">
        <h4>Refund</h4>
        <p v-if="isExchange" class="rd-hint">
          Refunding closes this exchange without a replacement.
          <button type="button" class="rd-linkbtn" @click="refundInstead = false">Back to the exchange</button>
        </p>

        <div class="rd-methods" role="radiogroup" aria-label="How the refund is made">
          <label class="rd-check" :class="{disabled: r.order && r.order.cash_on_delivery}">
            <input type="radio" value="original" v-model="method" :disabled="r.order && r.order.cash_on_delivery">
            To the original payment
            <span class="rd-hint-inline">{{ r.order && r.order.wallet_amount > 0 ? '(card first, then wallet)' : '' }}</span>
          </label>
          <label class="rd-check">
            <input type="radio" value="manual" v-model="method"> Already refunded another way (record it)
          </label>
          <label class="rd-check">
            <input type="radio" value="none" v-model="method"> No refund (close the return)
          </label>
        </div>
        <p v-if="r.order && r.order.cash_on_delivery" class="rd-hint">Cash on delivery: refund the customer yourself, then record it.</p>

        <template v-if="method !== 'none'">
          <label v-if="r.quote.with_shipping.shipping_refund > 0" class="rd-check rd-gap">
            <input type="checkbox" v-model="refundShipping"> Refund delivery too ({{ currencyIcon }}{{ money(r.quote.with_shipping.shipping_refund) }})
          </label>

          <label class="rd-label" for="rt-amount">Amount</label>
          <div class="rd-inline">
            <input id="rt-amount" v-model="amount" type="number" min="0" step="0.01" :max="activeQuote.refund" :placeholder="money(activeQuote.refund)">
            <span class="rd-hint-inline">Up to {{ currencyIcon }}{{ money(activeQuote.refund) }}. Leave empty for the full amount; lower it for a deduction.</span>
          </div>

          <template v-if="method === 'manual'">
            <label class="rd-label" for="rt-ref">Where it was refunded</label>
            <input id="rt-ref" v-model="reference" type="text" maxlength="255" placeholder="e.g. bank transfer ref, till receipt">
          </template>
        </template>

        <label class="rd-label" for="rt-fnote">Note (optional)</label>
        <input id="rt-fnote" v-model="note" type="text" maxlength="400">

        <p v-if="actionError" class="rd-error-line" role="alert">{{ actionError }}</p>
        <div class="rd-actions">
          <button type="button" class="primary-btn" :disabled="busy || !refundValid" @click="refundOpen = true">
            {{ method === 'none' ? 'Close without refund' : `Refund ${currencyIcon}${money(refundFigure)}` }}
          </button>
        </div>
      </div>

      <div class="rd-card">
        <h4>History</h4>
        <ul class="rd-history">
          <li v-for="(h, i) in r.history" :key="i">
            <span class="rd-when">{{ dateTime(h.at) }}</span>
            <strong>{{ statusName(h.to) }}</strong>
            <span v-if="h.note"> — {{ h.note }}</span>
            <span v-if="h.admin" class="rd-hint-inline"> by {{ h.admin }}</span>
          </li>
        </ul>
        <div v-if="canEdit" class="rd-inline rd-gap">
          <input v-model="newNote" type="text" maxlength="400" placeholder="Add a note" aria-label="Add a note">
          <button type="button" class="outline-btn xs" :disabled="busy || !newNote.trim()" @click="addNote">Add</button>
        </div>
      </div>
    </template>

    <confirm-dialog
      :open="refundOpen"
      :title="method === 'none' ? 'Close this return without a refund?' : 'Make this refund?'"
      tone="primary"
      icon="fa-solid fa-rotate-left"
      :confirm-label="method === 'none' ? 'Close return' : 'Refund'"
      busy-label="Working…"
      :busy="busy"
      :warning="method === 'original' ? 'Money is sent back through the payment provider straight away. This cannot be undone here.' : ''"
      @cancel="refundOpen = false"
      @confirm="refund"
    >
      <template v-slot:subject>
        <template v-if="method === 'none'">The items stay as recorded; the customer gets nothing back.</template>
        <template v-else>{{ currencyIcon }}{{ money(refundFigure) }} {{ methodLabel(method) }}<template v-if="activeQuote && activeQuote.gift_card > 0">, plus {{ currencyIcon }}{{ money(activeQuote.gift_card) }} back on the gift card</template>.</template>
      </template>
    </confirm-dialog>

    <confirm-dialog
      :open="payLinkOpen"
      title="Email a payment link?"
      tone="primary"
      icon="fa-solid fa-link"
      confirm-label="Send link"
      busy-label="Sending…"
      :busy="busy"
      @cancel="payLinkOpen = false"
      @confirm="sendPayLink"
    >
      <template v-slot:subject>
        The customer gets a link to pay {{ currencyIcon }}{{ money(r && r.exchange_quote ? r.exchange_quote.difference : 0) }} by card.
        The new {{ r && r.items.length > 1 ? 'units are' : 'unit is' }} held until it is paid or you cancel the link, and the exchange completes as soon as it is paid.
      </template>
    </confirm-dialog>

    <confirm-dialog
      :open="exchangeOpen"
      title="Complete this exchange?"
      tone="primary"
      icon="fa-solid fa-right-left"
      confirm-label="Complete exchange"
      busy-label="Working…"
      :busy="busy"
      :warning="r && r.exchange_quote && r.exchange_quote.difference < 0 && exMethod === 'original' && exFigure > 0 ? 'The difference is refunded through the payment provider straight away.' : ''"
      @cancel="exchangeOpen = false"
      @confirm="completeExchange"
    >
      <template v-slot:subject>A replacement order is created and the new units leave stock.</template>
    </confirm-dialog>

    <confirm-dialog
      :open="rejectOpen"
      title="Reject this return?"
      confirm-label="Reject"
      busy-label="Rejecting…"
      :busy="busy"
      @cancel="rejectOpen = false"
      @confirm="reject"
    >
      <template v-slot:subject>The units become returnable again on the order.</template>
      <div class="rd-form">
        <label class="rd-label" for="rt-jnote">Reason (staff only)</label>
        <input id="rt-jnote" v-model="note" type="text" maxlength="400">
        <label class="rd-check rd-gap">
          <input type="checkbox" v-model="notifyReject"> Email the customer that the return was not accepted
        </label>
      </div>
    </confirm-dialog>
  </div>
</template>

<script>
import ConfirmDialog from '~/components/ConfirmDialog'
import util from '~/mixin/util'
import { mapGetters, mapActions } from 'vuex'

const STATUS = {
  awaiting_return: 'Awaiting return',
  received: 'Received',
  refunding: 'Refunding',
  refunded: 'Refunded',
  closed: 'Closed',
  rejected: 'Rejected',
  exchanged: 'Exchanged',
  requested: 'Requested',
  awaiting_payment: 'Awaiting payment',
  cancelled: 'Cancelled'
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
      return isNaN(d.getTime()) ? v : d.toLocaleString('en-IE', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
    },
    statusName(s) {
      return STATUS[s] || s
    },
    differenceText(v) {
      const n = Number(v || 0)
      if (n > 0) return `Customer pays ${this.currencyIcon}${this.money(n)}`
      if (n < 0) return `Customer is owed ${this.currencyIcon}${this.money(-n)}`
      return 'None (same price)'
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
      return { original: 'to the original payment', manual: 'recorded as refunded elsewhere', none: '' }[m] || ''
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
        this.loadError = e?.message || 'This return could not be loaded.'
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
        this.actionError = (errors?.form || [])[0] || 'That could not be done.'
      } catch (e) {
        this.actionError = e?.message || 'That could not be done.'
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
