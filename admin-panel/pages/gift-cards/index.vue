<template>
  <div class="gc-page">

    <!-- What has been sold, and what is still owed to customers. -->
    <div v-if="summary" class="gc-tiles">
      <div class="gc-tile">
        <span class="gc-tile-label">{{ $t('pages.giftCards.cardsSold') }}</span>
        <strong class="gc-tile-value">{{ summary.cards }}</strong>
      </div>
      <div class="gc-tile">
        <span class="gc-tile-label">{{ $t('pages.giftCards.valueSold') }}</span>
        <strong class="gc-tile-value">{{ currencyIcon }}{{ summary.sold }}</strong>
      </div>
      <div class="gc-tile">
        <span class="gc-tile-label">{{ $t('pages.giftCards.spentOnOrders') }}</span>
        <strong class="gc-tile-value">{{ currencyIcon }}{{ summary.spent }}</strong>
        <span class="gc-tile-note">{{ $t('pages.giftCards.addedOfCards', { added: summary.added, cards: summary.cards }) }}</span>
      </div>
      <div class="gc-tile gc-tile-accent">
        <span class="gc-tile-label">{{ $t('pages.giftCards.outstanding') }}</span>
        <strong class="gc-tile-value">{{ currencyIcon }}{{ summary.outstanding }}</strong>
      </div>
    </div>

    <div class="gc-filters" role="tablist" :aria-label="$t('pages.giftCards.filterByStatus')">
      <button
        v-for="f in filters"
        :key="f.key"
        type="button"
        role="tab"
        class="gc-filter"
        :class="{ active: currentStatus === f.key }"
        :aria-selected="currentStatus === f.key ? 'true' : 'false'"
        @click="setStatus(f.key)"
      >
        {{ f.label }}
      </button>
    </div>

    <list-page
      ref="listPage"
      list-api="getGiftCards"
      route-name="gift-cards"
      :name="$t('pages.giftCards.listName')"
      :add-button="false"
      :order-options="orderOptions"
      gate="gift_card"
      @list="itemList = $event"
    >
      <template v-slot:table="{list, from}">
        <tr class="lite-bold">
          <th class="w-50x mx-w-50x">#</th>
          <th>{{ $t('pages.giftCards.colCode') }}</th>
          <th>{{ $t('pages.giftCards.colValue') }}</th>
          <th>{{ $t('pages.giftCards.colDesign') }}</th>
          <th>{{ $t('pages.giftCards.colBoughtBy') }}</th>
          <th>{{ $t('pages.giftCards.colFor') }}</th>
          <th>{{ $t('pages.giftCards.colStatus') }}</th>
          <th>{{ $t('pages.giftCards.colAddedToVouchers') }}</th>
          <th>{{ $t('pages.giftCards.colBought') }}</th>
          <th>&nbsp;</th>
        </tr>

        <tr v-for="(item, index) in list" :key="item.id">
          <td class="w-50x mx-w-50x">{{ from + index }}</td>

          <td><span class="gc-code">{{ item.code }}</span></td>

          <td><span class="gc-strong">{{ currencyIcon }}{{ item.amount }}</span></td>

          <td>{{ item.design }}</td>

          <td>
            <template v-if="item.buyer">
              <span class="gc-strong">{{ item.buyer.name }}</span>
              <span class="gc-sub">{{ item.buyer.email }}</span>
            </template>
            <span v-else class="gc-sub">{{ $t('pages.giftCards.accountRemoved') }}</span>
            <span class="gc-sub">{{ item.payment_method === 'wallet' ? $t('pages.giftCards.paidByWallet') : $t('pages.giftCards.paidByCard') }}</span>
          </td>

          <td>
            <template v-if="item.recipient">
              <span class="gc-strong">{{ item.recipient.name || $t('pages.giftCards.gift') }}</span>
              <span class="gc-sub">{{ item.recipient.email }}</span>
              <span v-if="item.message" class="gc-sub gc-msg" :title="item.message">“{{ item.message }}”</span>
            </template>
            <span v-else class="gc-sub">{{ $t('pages.giftCards.themselves') }}</span>
            <span v-if="item.sent_at" class="gc-sub">{{ $t('pages.giftCards.emailedAt', { date: item.sent_at }) }}</span>
            <span v-else class="gc-sub gc-warn">{{ $t('pages.giftCards.notEmailed') }}</span>
          </td>

          <td>
            <span class="gc-pill" :class="`gc-pill-${item.state}`">{{ stateLabel(item.state) }}</span>
            <span v-if="item.state === 'void' && item.void_reason" class="gc-sub">{{ item.void_reason }}</span>
            <span v-if="item.state === 'available' && item.expires" class="gc-sub">{{ $t('pages.giftCards.notAddedUntil', { date: item.expires }) }}</span>
            <template v-if="['in_vouchers', 'partly_used', 'used_up'].includes(item.state)">
              <span class="gc-sub">
                {{ $t('pages.giftCards.amountSpent', { amount: currencyIcon + item.spent }) }} · <strong>{{ $t('pages.giftCards.amountLeft', { amount: currencyIcon + item.left }) }}</strong>
              </span>
              <span class="gc-meter" aria-hidden="true">
                <span :style="{ width: Math.min(100, (item.spent / item.amount) * 100) + '%' }"></span>
              </span>
            </template>
            <span v-if="item.state === 'wallet'" class="gc-sub">{{ $t('pages.giftCards.beforeVouchers') }}</span>
          </td>

          <td>
            <template v-if="item.redeemed_by">
              <span class="gc-strong">{{ item.redeemed_by.name }}</span>
              <span class="gc-sub">{{ item.redeemed_at }}</span>
            </template>
            <span v-else class="gc-sub">—</span>
          </td>

          <td>{{ item.created }}</td>

          <td class="gc-actions">
            <template v-if="item.state === 'available' && $can('gift_card', 'edit')">
              <button type="button" class="lite-btn" :disabled="busyId === item.id" @click="resend(item)">
                {{ $t('pages.giftCards.resend') }}
              </button>
              <button type="button" class="delete-btn lite-btn" :disabled="busyId === item.id" @click="voidCard(item)">
                {{ $t('pages.giftCards.void') }}
              </button>
            </template>
          </td>
        </tr>
      </template>
    </list-page>
  </div>
</template>

<script>
import util from '~/mixin/util'
import ListPage from '~/components/partials/ListPage'
import {mapActions, mapGetters} from 'vuex'

/*
 * Every gift card sold. A card is a code worth its value once: redeeming it
 * adds it to the redeemer's vouchers. Staff can email a card again
 * (to a corrected address if needed) and void one that has not been
 * redeemed, e.g. after a chargeback.
 */
export default {
  name: 'gift-cards',
  middleware: ['common-middleware', 'auth'],
  components: {ListPage},
  mixins: [util],
  data() {
    return {
      itemList: [],
      summary: null,
      busyId: null,
      filters: [
        {key: '', label: this.$t('pages.giftCards.filterAll')},
        {key: 'available', label: this.$t('pages.giftCards.stateNotAdded')},
        {key: 'in_vouchers', label: this.$t('pages.giftCards.stateInVouchers')},
        {key: 'partly_used', label: this.$t('pages.giftCards.statePartlyUsed')},
        {key: 'used_up', label: this.$t('pages.giftCards.stateUsedUp')},
        {key: 'void', label: this.$t('pages.giftCards.stateVoid')},
        {key: 'expired', label: this.$t('pages.giftCards.stateExpired')}
      ],
      orderOptions: {
        created_at: {title: this.$t('pages.giftCards.orderDateBought')},
        amount: {title: this.$t('pages.giftCards.orderValue')},
        redeemed_at: {title: this.$t('pages.giftCards.orderDateAdded')}
      }
    }
  },
  computed: {
    ...mapGetters('setting', ['setting']),
    currencyIcon() {
      return this.setting?.currency_icon || '€'
    },
    currentStatus() {
      return this.$route.query.status || ''
    }
  },
  methods: {
    ...mapActions('common', ['getRequest', 'setRequest']),
    stateLabel(state) {
      return {
        available: this.$t('pages.giftCards.stateNotAdded'),
        in_vouchers: this.$t('pages.giftCards.stateInVouchers'),
        partly_used: this.$t('pages.giftCards.statePartlyUsed'),
        used_up: this.$t('pages.giftCards.stateUsedUp'),
        wallet: this.$t('pages.giftCards.stateWallet'),
        redeemed: this.$t('pages.giftCards.stateRedeemed'),
        void: this.$t('pages.giftCards.stateVoid'),
        expired: this.$t('pages.giftCards.stateExpired')
      }[state] || state
    },
    async loadSummary() {
      try {
        const data = await this.getRequest({params: {page: 1}, api: 'getGiftCards'})
        this.summary = data?.summary || null
      } catch (e) {
        this.summary = null
      }
    },
    async refresh() {
      await Promise.all([this.$refs.listPage.fetchingData(), this.loadSummary()])
    },
    async setStatus(status) {
      const query = {...this.$route.query, page: 1}
      if (status) {
        query.status = status
      } else {
        delete query.status
      }
      await this.$router.push({query})
      this.$refs.listPage.fetchingData()
    },
    async resend(item) {
      const current = item.recipient ? item.recipient.email : item.sent_to
      const email = prompt(this.$t('pages.giftCards.resendPrompt'), current || '')

      if (email === null) return

      this.busyId = item.id
      try {
        await this.setRequest({
          params: {id: item.id, email: email && email !== current ? email.trim() : null},
          api: 'resendGiftCard'
        })
        await this.refresh()
      } catch (e) {
        this.$store.dispatch('ui/setToastError', e?.message || this.$t('pages.giftCards.resendFailed'))
      } finally {
        this.busyId = null
      }
    },
    async voidCard(item) {
      const reason = prompt(this.$t('pages.giftCards.voidPrompt', { code: item.code, amount: this.currencyIcon + item.amount }), '')

      if (reason === null) return

      this.busyId = item.id
      try {
        await this.setRequest({params: {id: item.id, reason: reason.trim()}, api: 'voidGiftCard'})
        await this.refresh()
      } catch (e) {
        this.$store.dispatch('ui/setToastError', e?.message || this.$t('pages.giftCards.voidFailed'))
      } finally {
        this.busyId = null
      }
    }
  },
  mounted() {
    this.loadSummary()
  }
}
</script>

<style scoped>
.gc-tiles {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
  margin-bottom: 14px;
}

.gc-tile {
  background: #fff;
  border: 1px solid var(--border-color, #E3E3EF);
  border-radius: 12px;
  padding: 14px 16px;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.gc-tile-accent { border-left: 4px solid var(--primary-color, #33319A); }

.gc-tile-label { font-size: 12.5px; color: #6B7280; }
.gc-tile-value { font-size: 20px; font-weight: 700; color: #130E2B; }

.gc-filters {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 12px;
}

.gc-filter {
  height: 32px;
  line-height: 1;
  padding: 0 14px;
  border: 1px solid var(--border-color, #E3E3EF);
  border-radius: 999px;
  background: #fff;
  color: #374151;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}

.gc-filter:hover { background: #F5F5FF !important; }

.gc-filter.active,
.gc-filter.active:hover {
  background: var(--primary-color, #33319A) !important;
  border-color: var(--primary-color, #33319A);
  color: #fff;
}

.gc-code {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-weight: 700;
  letter-spacing: .04em;
  white-space: nowrap;
}

.gc-strong { display: block; font-weight: 600; }

.gc-sub {
  display: block;
  font-size: 12px;
  color: #6B7280;
}

.gc-msg {
  max-width: 200px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  font-style: italic;
}

.gc-warn { color: #B45309; }

.gc-pill {
  display: inline-flex;
  padding: 3px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 600;
  white-space: nowrap;
}

.gc-pill-available { background: #E6F6EC; color: #1E7E34; }
.gc-pill-redeemed,
.gc-pill-in_vouchers { background: #EBEBFF; color: #33319A; }
.gc-pill-partly_used { background: #FFF4E0; color: #9A5B00; }
.gc-pill-used_up,
.gc-pill-wallet { background: #F3F4F6; color: #374151; }

.gc-meter {
  display: block;
  width: 110px;
  height: 4px;
  margin: 6px auto 0;
  border-radius: 4px;
  background: #E5E7EB;
  overflow: hidden;
}

.gc-meter span {
  display: block;
  height: 100%;
  background: #33319A;
}

.gc-tile-note {
  display: block;
  margin-top: 2px;
  font-size: 12px;
  color: #6B7280;
}
.gc-pill-void { background: #FDECEA; color: #C82333; }
.gc-pill-expired { background: #F3F4F6; color: #6B7280; }

.gc-actions { white-space: nowrap; }

@media (max-width: 991.98px) {
  .gc-tiles { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
</style>
