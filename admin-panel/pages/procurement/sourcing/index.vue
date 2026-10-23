<template>
  <div class="sb-sourcing">

    <div class="table-top">
      <h2>{{ $t('procurement.sourcing') }}</h2>
      <p class="sb-muted">{{ $t('pages.procurementSourcing.intro') }}</p>
    </div>

    <!--
      Filters describe what is still true of an order rather than a stored
      column: the state is derived from its quotes, so a stored copy would
      drift the moment one changed.
    -->
    <div class="table-top filter-toolbar sb-filters">
      <label class="sb-filter">
        <span>{{ $t('pages.procurementSourcing.order') }}</span>
        <input
          type="search"
          v-model.trim="filters.q"
          :placeholder="$t('pages.procurementSourcing.orderNumber')"
          @input="debouncedReload"
        >
      </label>

      <label class="sb-filter">
        <span>{{ $t('pages.procurementSourcing.supplier') }}</span>
        <select v-model="filters.supplier_id" @change="reload">
          <option value="">{{ $t('pages.procurementSourcing.anySupplier') }}</option>
          <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
        </select>
      </label>

      <label class="sb-filter">
        <span>{{ $t('pages.procurementSourcing.state') }}</span>
        <select v-model="filters.state" @change="reload">
          <option value="">{{ $t('pages.procurementSourcing.anythingOutstanding') }}</option>
          <option value="needs_quote">{{ $t('pages.procurementSourcing.waitingOnQuotes') }}</option>
          <option value="needs_award">{{ $t('pages.procurementSourcing.readyToAward') }}</option>
          <option value="unpaid">{{ $t('pages.procurementSourcing.awardedUnpaid') }}</option>
          <option value="settled">{{ $t('pages.procurementSourcing.settled') }}</option>
        </select>
      </label>

      <button v-if="filtered" class="lite-btn" @click="clearFilters">{{ $t('pages.procurementSourcing.clear') }}</button>
    </div>

    <div v-if="loading" class="sb-state-box">{{ $t('pages.procurementSourcing.loading') }}</div>

    <div v-else-if="error" class="sb-state-box sb-state-box--error">{{ error }}</div>

    <div v-else-if="!orders.length" class="sb-state-box">
      <template v-if="filtered">
        {{ $t('pages.procurementSourcing.noMatch') }}
        <button class="lite-btn" @click="clearFilters">{{ $t('pages.procurementSourcing.clearFilters') }}</button>
      </template>
      <template v-else>
        {{ $t('pages.procurementSourcing.empty') }}
      </template>
    </div>

    <div v-else>
      <!--
        One card per ORDER, not per line. A basket can hold several
        procurement products, and they are one piece of work: the header
        carries what the whole order still needs.
      -->
      <section class="sb-order-card" v-for="order in orders" :key="order.order_id">

        <!--
          A div, not a <header>: assets/styles/header.styl styles the bare
          element as the admin top bar -- position fixed, left 0, right 0,
          z-index 20 -- so a <header> here broke out of the card and sat on
          top of the sidebar.
        -->
        <div class="sb-order-head">
          <div>
            <strong v-if="order.order_number">{{ $t('pages.procurementSourcing.orderNumberLabel', { number: order.order_number }) }}</strong>
            <strong v-else class="sb-muted">{{ $t('pages.procurementSourcing.orderGone') }}</strong>
            <span class="sb-muted">{{ $t('pages.procurementSourcing.askedAt', { date: formatDate(order.asked_at) }) }}</span>
          </div>

          <div class="sb-order-state">
            <span class="sb-pill" :class="order.awarded_count === order.line_count ? 'sb-pill--ok' : 'sb-pill--wait'">
              {{ $t('pages.procurementSourcing.awardedOf', { awarded: order.awarded_count, total: order.line_count }) }}
            </span>
            <span v-if="order.total_payable > 0" class="sb-pill sb-pill--owed">
              {{ $t('pages.procurementSourcing.toPay', { amount: money(order.total_payable) }) }}
            </span>
            <span v-else-if="order.settled_count === order.line_count" class="sb-pill sb-pill--ok">
              {{ $t('pages.procurementSourcing.settled') }}
            </span>
            <span v-if="order.expired" class="sb-pill sb-pill--off">{{ $t('pages.procurementSourcing.linksExpired') }}</span>
          </div>
        </div>

        <!-- Each procurement product on the order -->
        <div class="sb-line" v-for="line in order.lines" :key="line.key">

          <div class="sb-line-head">
            <div class="sb-line-what">
              <strong>{{ line.product ? line.product.title : $t('pages.procurementSourcing.productRemoved') }}</strong>
              <span class="sb-muted">
                {{ $tc('pages.procurementSourcing.suppliersAsked', line.asked_count, { count: line.asked_count }) }}
                <template v-if="line.awarded_to"> · {{ $t('pages.procurementSourcing.awardedTo', { name: line.awarded_to }) }}</template>
              </span>
            </div>

            <dl class="sb-line-facts">
              <div><dt>{{ $t('pages.procurementSourcing.qty') }}</dt><dd>{{ line.quantity !== null ? line.quantity : '—' }}</dd></div>
              <div><dt>{{ $t('pages.procurementSourcing.soldAt') }}</dt><dd>{{ line.sold_price !== null ? money(line.sold_price) : '—' }}</dd></div>
              <div><dt>{{ $t('pages.procurementSourcing.costNow') }}</dt><dd>{{ line.cost_price !== null ? money(line.cost_price) : '—' }}</dd></div>
            </dl>
          </div>

          <p v-if="!line.quotes.length" class="sb-no-quotes">
            {{ $t('pages.procurementSourcing.noQuotes') }}
          </p>

          <table v-else class="sb-quotes">
            <colgroup>
              <col class="sb-c-sup">
            <col class="sb-c-sm">
            <col class="sb-c-md">
            <col class="sb-c-md">
            <col class="sb-c-md">
            <col>
            </colgroup>
            <!--
            No lite-bold: table.styl gives `.lite-bold th` text-align
            center !important, which no amount of specificity can beat,
            and these columns are a mix of left and right aligned. The
            header styling this row needs is defined below instead.
          -->
          <tr class="sb-head-row">
              <th>{{ $t('pages.procurementSourcing.supplier') }}</th>
              <th>{{ $t('pages.procurementSourcing.grade') }}</th>
              <th class="sb-num">{{ $t('pages.procurementSourcing.perUnit') }}</th>
              <th class="sb-num">{{ $t('pages.procurementSourcing.lineTotal') }}</th>
              <th class="sb-num">{{ $t('pages.procurementSourcing.margin') }}</th>
              <th>&nbsp;</th>
            </tr>

            <tr
              v-for="quote in line.quotes"
              :key="quote.id"
              :class="['sb-q-' + quote.status, { 'sb-q-best': isBest(line, quote) }]"
            >
              <td>
                <span class="sb-sup">{{ quote.supplier ? quote.supplier.name : $t('pages.procurementSourcing.supplierRemoved') }}</span>
                <span class="sb-muted sb-small" v-if="quote.supplier">{{ quote.supplier.email }}</span>
                <span class="sb-pill sb-pill--ok" v-if="quote.status === 'accepted'">{{ $t('pages.procurementSourcing.awarded') }}</span>
                <span class="sb-pill sb-pill--off" v-else-if="quote.status === 'rejected'">{{ $t('pages.procurementSourcing.rejected') }}</span>
                <span class="sb-pill sb-pill--best" v-else-if="isBest(line, quote)">{{ $t('pages.procurementSourcing.lowest') }}</span>
              </td>

              <td>{{ quote.grade }}</td>
              <td class="sb-num">{{ money(quote.price) }}</td>
              <td class="sb-num sb-strong">{{ money(quote.payable) }}</td>

              <td class="sb-num">
                <span v-if="quote.margin !== null" :class="quote.margin < 0 ? 'sb-loss' : 'sb-gain'">
                  {{ quote.margin < 0 ? '−' : '+' }}{{ money(Math.abs(quote.margin)) }}
                </span>
                <span
                  v-else
                  class="sb-muted"
                  :title="$t('pages.procurementSourcing.marginNaTitle')"
                >{{ $t('pages.procurementSourcing.notApplicable') }}</span>
              </td>

              <td class="sb-actions">
                <template v-if="quote.paid_at">
                  <span class="sb-paid">
                    {{ $t('pages.procurementSourcing.paidAmount', { amount: money(quote.paid_amount) }) }} · {{ methodLabel(quote.payment_method) }}
                    <template v-if="quote.payment_reference">· {{ quote.payment_reference }}</template>
                  </span>
                  <button class="lite-btn" :disabled="busy === quote.id" @click="reversePayment(quote)">
                    {{ $t('pages.procurementSourcing.undoPayment') }}
                  </button>
                </template>

                <template v-else-if="quote.status === 'accepted'">
                  <button class="lite-btn primary" :disabled="busy === quote.id" @click="openPayment(line, quote)">
                    {{ $t('pages.procurementSourcing.recordPayment') }}
                  </button>
                  <button class="lite-btn" :disabled="busy === quote.id" @click="decide(quote, 'rejected')">
                    {{ $t('pages.procurementSourcing.undoAward') }}
                  </button>
                </template>

                <template v-else-if="quote.status === 'pending'">
                  <button class="lite-btn primary" :disabled="busy === quote.id" @click="decide(quote, 'accepted')">
                    {{ $t('pages.procurementSourcing.award') }}
                  </button>
                  <button class="lite-btn" :disabled="busy === quote.id" @click="decide(quote, 'rejected')">
                    {{ $t('pages.procurementSourcing.reject') }}
                  </button>
                </template>

                <template v-else>
                  <button class="lite-btn" :disabled="busy === quote.id" @click="decide(quote, 'accepted')">
                    {{ $t('pages.procurementSourcing.awardAnyway') }}
                  </button>
                </template>
              </td>
            </tr>
          </table>

          <!--
            Recording what left the bank. Not a payment being taken: the
            gateways here are customer-facing, so this is the note of a
            transfer made elsewhere.
          -->
          <form v-if="paying && paying.lineKey === line.key" class="sb-pay-form" @submit.prevent="submitPayment">
            <div class="sb-pay-field">
              <label :for="`amt-${paying.quote.id}`">{{ $t('pages.procurementSourcing.amountPaid') }}</label>
              <input :id="`amt-${paying.quote.id}`" type="number" step="0.01" min="0.01" v-model="paying.amount">
            </div>
            <div class="sb-pay-field">
              <label :for="`mth-${paying.quote.id}`">{{ $t('pages.procurementSourcing.method') }}</label>
              <select :id="`mth-${paying.quote.id}`" v-model="paying.method">
                <option v-for="m in methods" :key="m" :value="m">{{ methodLabel(m) }}</option>
              </select>
            </div>
            <div class="sb-pay-field sb-pay-field--wide">
              <label :for="`ref-${paying.quote.id}`">{{ $t('pages.procurementSourcing.reference') }}</label>
              <input
                :id="`ref-${paying.quote.id}`"
                type="text"
                maxlength="120"
                v-model="paying.reference"
                :placeholder="$t('pages.procurementSourcing.referencePlaceholder')"
              >
            </div>
            <div class="sb-pay-field">
              <label :for="`on-${paying.quote.id}`">{{ $t('pages.procurementSourcing.paidOn') }}</label>
              <input :id="`on-${paying.quote.id}`" type="date" :max="today" v-model="paying.paid_on">
            </div>
            <div class="sb-pay-actions">
              <button type="submit" class="lite-btn primary" :disabled="busy === paying.quote.id">{{ $t('pages.procurementSourcing.save') }}</button>
              <button type="button" class="lite-btn" @click="paying = null">{{ $t('pages.procurementSourcing.cancel') }}</button>
            </div>
            <p v-if="paying.error" class="sb-pay-error">{{ paying.error }}</p>
          </form>
        </div>
      </section>

      <!--
        The shared paginator, so this page pages like every other list.
        Keyed on the filters: it holds its own currentPage, and filtering
        resets the board to page one -- without the key the pager would go
        on highlighting a page that is no longer there.
      -->
      <div class="sb-pager">
        <span class="sb-count">
          {{ $tc('pages.procurementSourcing.orderCount', total, { count: total }) }}
          <template v-if="lastPage > 1">· {{ $t('pages.procurementSourcing.pageOf', { page, last: lastPage }) }}</template>
        </span>

        <pagination
          v-if="lastPage > 1"
          :key="filterKey"
          :total-page="lastPage"
          :changing-route="false"
          @fetching-data="onPage"
        />
      </div>
    </div>

  </div>
</template>

<script>
import Pagination from '~/components/partials/Pagination'
import util from '~/mixin/util'
import { mapActions } from 'vuex'

export default {
  name: 'procurement-sourcing',
  middleware: ['common-middleware', 'auth'],
  components: { Pagination },
  mixins: [util],

  data() {
    return {
      // Vue templates cannot see globals, and the margin cell needs abs().
      Math,
      orders: [],
      suppliers: [],
      filters: { q: '', supplier_id: '', state: '' },
      searchTimer: null,
      methods: ['Bank transfer', 'Card', 'Cash', 'Credit note', 'Other'],
      page: 1,
      lastPage: 1,
      total: 0,
      loading: true,
      error: '',
      // The quote whose request is in flight, so only its buttons disable.
      busy: null,
      paying: null
    }
  },

  computed: {
    /** Only the filters actually set, so empty ones stay out of the query. */
    activeFilters() {
      return Object.keys(this.filters).reduce((out, key) => {
        if (this.filters[key] !== '' && this.filters[key] !== null) out[key] = this.filters[key]
        return out
      }, {})
    },

    filtered() {
      return Object.keys(this.activeFilters).length > 0
    },

    /* Changes whenever a filter does, which remounts the paginator. */
    filterKey() {
      return JSON.stringify(this.activeFilters)
    },

    today() {
      return new Date().toISOString().slice(0, 10)
    }
  },

  mounted() {
    this.load()
  },

  beforeDestroy() {
    clearTimeout(this.searchTimer)
  },

  methods: {
    ...mapActions('admin', [
      'getSourcingBoard', 'decideProcurementQuote', 'payProcurementQuote'
    ]),

    /*
     * The cheapest quote still in play. Rejected ones are kept for the
     * record but are not candidates, and a line already awarded has its
     * answer -- highlighting a rival then would be noise.
     */
    isBest(line, quote) {
      if (quote.status !== 'pending' || line.accepted_quote_id) return false

      const open = line.quotes.filter(q => q.status === 'pending')

      if (open.length < 2) return false

      return Math.min(...open.map(q => Number(q.price))) === Number(quote.price)
    },

    /*
     * The method is stored and sent in English; only its label is translated.
     * Anything unknown (older rows) shows as stored.
     */
    methodLabel(m) {
      const keys = {
        'Bank transfer': 'methodBankTransfer',
        'Card': 'methodCard',
        'Cash': 'methodCash',
        'Credit note': 'methodCreditNote',
        'Other': 'methodOther'
      }

      return keys[m] ? this.$t('pages.procurementSourcing.' + keys[m]) : m
    },

    money(value) {
      return `€${Number(value || 0).toFixed(2)}`
    },

    /*
     * Every timestamp here is UTC with a trailing Z, so the browser renders
     * it in the reader's own zone.
     */
    formatDate(value) {
      if (!value) return '—'

      const d = new Date(value)

      if (isNaN(d.getTime())) return '—'

      return d.toLocaleString(undefined, {
        day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'
      })
    },

    async load() {
      this.loading = true
      this.error = ''

      try {
        const body = await this.getSourcingBoard({ page: this.page, ...this.activeFilters })

        /*
         * A refusal arrives as HTTP 200 with status 403 in the body, the way
         * every admin endpoint here reports one, so it is read off the
         * payload rather than caught.
         */
        if (body.status !== 200) {
          this.error = body.message || this.$t('pages.procurementSourcing.noAccess')
          this.orders = []
          return
        }

        this.orders = body.data.orders.data || []
        this.lastPage = body.data.orders.last_page || 1
        this.total = body.data.orders.total || 0
        this.suppliers = body.data.suppliers || []

      } catch (e) {
        this.error = this.$t('pages.procurementSourcing.loadFailed')
      } finally {
        this.loading = false
      }
    },

    /*
     * Any filter change goes back to page one: page 4 of an unfiltered list
     * is rarely page 4 of a filtered one, and landing on an empty page reads
     * as "no results".
     */
    reload() {
      this.page = 1
      this.paying = null
      this.load()
    },

    /* Typing a number should not fire a request per keystroke. */
    debouncedReload() {
      clearTimeout(this.searchTimer)
      this.searchTimer = setTimeout(this.reload, 350)
    },

    clearFilters() {
      this.filters = { q: '', supplier_id: '', state: '' }
      this.reload()
    },

    /* The paginator emits the page it moved to. */
    onPage(params) {
      this.page = (params && params.page) || 1
      this.paying = null
      this.load()
    },

    go(page) {
      this.page = page
      this.paying = null
      this.load()
    },

    /*
     * Awarding commits money and rejects the rival quotes for the line, so
     * it is confirmed rather than done on a single click.
     */
    async decide(quote, decision) {
      const supplier = quote.supplier ? quote.supplier.name : this.$t('pages.procurementSourcing.thisSupplier')

      const ask = decision === 'accepted'
        ? this.$t('pages.procurementSourcing.confirmAward', { supplier, total: this.money(quote.payable), unit: this.money(quote.price) })
        : this.$t('pages.procurementSourcing.confirmReject', { supplier })

      if (!window.confirm(ask)) return

      const reason = decision === 'rejected' && quote.status === 'accepted'
        ? 'Award undone'
        : null

      this.busy = quote.id

      try {
        const body = await this.decideProcurementQuote({ id: quote.id, decision, reason })

        if (body.status !== 200) {
          this.toast(body, this.$t('pages.procurementSourcing.decisionFailed'))
          return
        }

        this.$store.dispatch('ui/setToastMessage', body.message, { root: true })
        await this.load()

      } catch (e) {
        this.$store.dispatch('ui/setToastError', this.$t('pages.procurementSourcing.decisionFailed'), { root: true })
      } finally {
        this.busy = null
      }
    },

    /*
     * Opens on the quoted line total and today's date, because that is what
     * usually leaves the bank -- but both stay editable: a supplier invoice
     * can differ from the quote, and payments get recorded after the fact.
     */
    openPayment(line, quote) {
      this.paying = {
        lineKey: line.key,
        quote,
        amount: Number(quote.payable).toFixed(2),
        method: this.methods[0],
        reference: '',
        paid_on: this.today,
        error: ''
      }
    },

    async submitPayment() {
      const { quote, amount, method, reference, paid_on: paidOn } = this.paying

      this.paying.error = ''
      this.busy = quote.id

      try {
        const body = await this.payProcurementQuote({
          id: quote.id, amount, method, reference, paid_on: paidOn
        })

        if (body.status !== 200) {
          this.paying.error = this.firstError(body) || this.$t('pages.procurementSourcing.paymentFailed')
          return
        }

        this.$store.dispatch('ui/setToastMessage', body.message, { root: true })
        this.paying = null
        await this.load()

      } catch (e) {
        this.paying.error = this.$t('pages.procurementSourcing.paymentFailed')
      } finally {
        this.busy = null
      }
    },

    async reversePayment(quote) {
      if (!window.confirm(this.$t('pages.procurementSourcing.confirmReversePayment'))) {
        return
      }

      this.busy = quote.id

      try {
        const body = await this.payProcurementQuote({ id: quote.id, reverse: 1 })

        if (body.status !== 200) {
          this.toast(body, this.$t('pages.procurementSourcing.reverseFailed'))
          return
        }

        this.$store.dispatch('ui/setToastMessage', body.message, { root: true })
        await this.load()

      } catch (e) {
        this.$store.dispatch('ui/setToastError', this.$t('pages.procurementSourcing.reverseFailed'), { root: true })
      } finally {
        this.busy = null
      }
    },

    /** The first message the server put against a field, if any. */
    firstError(body) {
      const fields = body && body.data && body.data.fields

      if (!fields) return body && body.message

      const first = Object.keys(fields)[0]

      return first ? fields[first][0] : body.message
    },

    toast(body, fallback) {
      this.$store.dispatch('ui/setToastError', this.firstError(body) || fallback, { root: true })
    }
  }
}
</script>

<style scoped>
/* Tokens from assets/styles/variables.sb-styl, not local greys. */

.table-top h2 { margin: 0; font-size: 18px; color: var(--text-strong); }
.table-top .sb-muted { margin: 6px 0 0; font-size: 13px; max-width: 78ch; }

.sb-muted { color: var(--text-muted); font-weight: 400; }
.sb-small { font-size: 12px; }
.sb-strong { font-weight: 600; color: var(--text-strong); }

.sb-state-box {
  background: var(--surface-color);
  border: 1px solid var(--border-color);
  border-radius: var(--radius-lg);
  padding: 40px 30px;
  text-align: center;
  color: var(--text-muted);
  font-size: 14px;
  line-height: 1.6;
}

.sb-state-box--error { border-color: var(--danger-text); color: var(--danger-text); }

/*
 * .table-top.filter-toolbar already draws the surface, border, radius
 * and padding that every list page's filter bar uses; only the layout
 * of the controls inside it belongs here.
 */
.sb-filters {
  display: flex;
  align-items: flex-end;
  flex-wrap: wrap;
  gap: 12px;
}

.sb-filter { display: flex; flex-direction: column; gap: 4px; }

.sb-filter span {
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .4px;
  color: var(--text-muted);
}

.sb-filter input,
.sb-filter select {
  width: 100%;
  min-width: 190px;
  height: 36px;
  line-height: 34px;
  padding: 0 10px;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  background: var(--surface-color);
  font-size: 13px;
  box-sizing: border-box;
}

.sb-filter input:focus,
.sb-filter select:focus {
  border-color: var(--primary-color);
  box-shadow: 0 0 0 3px var(--primary-soft);
  outline: none;
}

.sb-filters .lite-btn {
  width: auto;
  height: 36px;
  line-height: 34px;
  padding: 0 14px;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  background: var(--surface-color);
  color: var(--text-color);
  font-size: 13px;
}

.sb-state-box .lite-btn {
  width: auto;
  height: 32px;
  line-height: 30px;
  margin-left: 8px;
  padding: 0 14px;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  background: var(--surface-color);
  color: var(--text-color);
  font-size: 13px;
}

/*
 * One card per ORDER, with each procurement line inside it. A basket can
 * hold several sourced products and they are one piece of work -- the old
 * per-line layout repeated the order number down the page and never showed
 * what the order as a whole still owed.
 */
.sb-order-card {
  background: var(--surface-color);
  border: 1px solid var(--border-color);
  border-radius: var(--radius-lg);
  margin-bottom: 16px;
  box-shadow: 0 1px 2px var(--shadow-color);
  overflow: hidden;
}

.sb-order-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  padding: 14px 18px;
  background: var(--page-bg);
  border-bottom: 1px solid var(--border-color);
}

.sb-order-head strong { font-size: 15px; color: var(--text-strong); margin-right: 10px; }
.sb-order-head .sb-muted { font-size: 12.5px; }
.sb-order-state { display: flex; gap: 8px; flex-wrap: wrap; }

/* Each procurement product on the order. */
.sb-line { padding: 14px 18px; }
.sb-line + .sb-line { border-top: 1px solid var(--border-color); }

.sb-pill--wait { background: var(--warning-bg); color: var(--warning-text); }
.sb-pill--owed { background: var(--info-bg); color: var(--info-text); }

.sb-line-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  flex-wrap: wrap;
  gap: 14px;
  margin-bottom: 12px;
}

.sb-line-what strong { display: block; font-size: 15px; color: var(--text-strong); }
.sb-line-what .sb-muted { font-size: 12.5px; }

/* The money that frames the decision, read left to right. */
.sb-line-facts { display: flex; gap: 22px; margin: 0; }
.sb-line-facts div { text-align: right; }
.sb-line-facts dt {
  margin: 0;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: .4px;
  color: var(--text-muted);
}
.sb-line-facts dd {
  margin: 2px 0 0;
  font-size: 15px;
  font-weight: 600;
  color: var(--text-strong);
  font-variant-numeric: tabular-nums;
}
.sb-line-facts .sb-owed dd { color: var(--warning-text); }

.sb-no-quotes {
  margin: 0;
  padding: 12px 0 2px;
  border-top: 1px solid var(--border-color);
  font-size: 13px;
  color: var(--text-muted);
}

/*
 * Scoped to the card. Three admin pages restyle `table` unscoped -- one sets
 * table-layout: fixed -- so a bare element selector would leak and be leaked
 * into.
 */
.sb-line .sb-quotes { width: 100%; border-collapse: collapse; }

.sb-line .sb-quotes { table-layout: fixed; }

/*
 * Fixed widths, and the supplier column capped: left to itself it took
 * every spare pixel and pushed the figures away from the buttons they
 * belong to.
 */
/*
 * Every column fixed except the last. The supplier column used to be the
 * flexible one, so on a wide screen it took all the slack and left a gap
 * between the name and the figures. The actions column absorbs it now,
 * where the payment note has somewhere to go.
 */
.sb-line .sb-quotes col.sb-c-sup { width: 260px; }
.sb-line .sb-quotes col.sb-c-sm  { width: 92px; }
.sb-line .sb-quotes col.sb-c-md  { width: 108px; }

/*
 * table.styl makes every th sticky with its own background so long admin
 * lists keep their header while scrolling. These tables are short and sit
 * inside a card, so a sticky header would float over the card's own edges.
 */
.sb-line .sb-quotes th {
  position: static;
  background: transparent;
  color: var(--text-muted);
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .4px;
}

.sb-line .sb-quotes th,
.sb-line .sb-quotes td {
  text-align: left;
  padding: 9px 10px;
  border-top: 1px solid var(--border-color);
  font-size: 13.5px;
  vertical-align: middle;
}

.sb-line .sb-quotes th:first-child,
.sb-line .sb-quotes td:first-child { padding-left: 0; }
.sb-line .sb-quotes th:last-child,
.sb-line .sb-quotes td:last-child { padding-right: 0; }

.sb-line .sb-quotes th.sb-num,
.sb-line .sb-quotes td.sb-num {
  text-align: right;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.sb-line .sb-quotes .sb-sup {
  display: block;
  color: var(--text-strong);
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
}

.sb-line .sb-quotes .sb-small {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* The payment note is a sentence, so it wraps rather than clipping. */
.sb-line .sb-quotes .sb-actions {
  white-space: normal;
  text-align: right;
}

/* The last button should sit flush with the card edge, not a margin off. */
.sb-line .sb-quotes .sb-actions .lite-btn:last-child { margin-right: 0; }

/* A rejected quote is kept for the record, not for comparison. */
.sb-line .sb-quotes tr.sb-q-rejected { opacity: .55; }
.sb-line .sb-quotes tr.sb-q-accepted { background: var(--success-bg); }

.sb-gain { color: var(--success-text); font-weight: 600; }

/* A quote above what the line sold for loses money on fulfilment. */
.sb-loss { color: var(--danger-text); font-weight: 600; }

.sb-pill {
  display: inline-block;
  margin-top: 4px;
  padding: 1px 8px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 600;
}

.sb-pill--ok   { background: var(--success-bg); color: var(--success-text); }
.sb-pill--off  { background: var(--hover-color); color: var(--text-muted); }
.sb-pill--best { background: var(--info-bg); color: var(--info-text); }

.sb-actions { white-space: normal; }
.sb-paid { display: block; font-size: 12px; color: var(--success-text); margin-bottom: 4px; }

/*
 * base.sb-styl gives every button height 40px and line-height 38px, so both are
 * set here or the label sits off-centre in a smaller button.
 */
.sb-line .lite-btn,
.sb-pager .lite-btn {
  width: auto;
  height: 30px;
  line-height: 28px;
  padding: 0 12px;
  margin-right: 6px;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  background: var(--surface-color);
  color: var(--text-color);
  font-size: 12.5px;
}

.sb-line .lite-btn.primary {
  border-color: var(--primary-color);
  background: var(--primary-color);
  color: var(--primary-foreground);
}

.sb-line .lite-btn:disabled,
.sb-pager .lite-btn:disabled { opacity: .5; cursor: not-allowed; }

/* Recording a transfer made elsewhere, not taking a payment. */
.sb-pay-form {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 12px;
  margin-top: 12px;
  padding: 14px;
  border: 1px solid var(--border-color);
  border-radius: var(--radius-md);
  background: var(--page-bg);
}

.sb-pay-field { display: flex; flex-direction: column; gap: 4px; flex: 0 1 150px; }
.sb-pay-field--wide { flex: 1 1 220px; }

.sb-pay-field label {
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .4px;
  color: var(--text-muted);
}

.sb-pay-field input,
.sb-pay-field select {
  width: 100%;
  height: 34px;
  line-height: 32px;
  padding: 0 10px;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  background: var(--surface-color);
  font-size: 13px;
  box-sizing: border-box;
}

.sb-pay-actions { display: flex; align-items: center; }
.sb-pay-error { flex: 1 1 100%; margin: 0; color: var(--danger-text); font-size: 12.5px; }

.sb-pager {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 14px;
  margin-top: 18px;
  font-size: 13px;
  color: var(--text-muted);
}

.sb-count { font-variant-numeric: tabular-nums; }

/* The shared paginator right-aligns itself; it does not need to grow. */
.sb-pager >>> .pagination { margin: 0; }
</style>
