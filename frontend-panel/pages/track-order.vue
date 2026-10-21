<template>
  <client-only>
    <div
      class="mtb-20 pb-30"
    >
      <div class="container">
        <breadcrumb
          :page="pageTitle"
        />

        <div class="track-container">
          <div class="form-wrapper">

            <h1 class="page-title mt-10">{{$t('trackOrder.trackOrder')}}</h1>
            <p class="mt-10 mb-30 mb-sm-15 f-12">{{$t('trackOrder.haveAnOrder')}}</p>
            <form
              class="track-form user-form"
              @submit.prevent="formSubmit"
            >
              <ul
                class="error-list mb-15"
                v-if="errors.length"
              >
                <li>
                  {{ $t('forgotPassword.errorOccurred') }}
                </li>
                <li
                  v-for="(value, index) in errors"
                  :key="index"
                >
                  {{ value }}
                </li>
              </ul>

              <h4 class="mb-30 mb-sm-15"><b>{{$t('trackOrder.enterCode')}}</b></h4>


              <div
                class="input-wrap"
                :class="{invalid: !trackingId && hasFormError}"
              >
                <label>{{ $t('addressPopup.order') }}</label>

                <div class="icon-input">
                  <i
                    class="icon order-icon"
                  />
                  <input
                    type="text"
                    v-model="trackingId"
                    :placeholder="$t('contact.your', { type: $t('addressPopup.order') }) + '. ' + $t('ui.trackOrderPage.example', { example: 'ORD1578945' })"
                  >
                </div>

                <span
                  class="error"
                  v-if="!trackingId && hasFormError"
                >
                  {{ $t('addressPopup.isRequired', {type: $t('addressPopup.order') }) }}
                </span>
              </div>


              <h6 class="mb-30 mb-sm-15">{{$t('trackOrder.knowProgress')}}</h6>

              <div class="flex right no-space">
                <ajax-button

                  class="primary-btn plr-20 w-50"
                  :fetching-data="formSubmitting"
                  :loading-text="$t('forgotPassword.registering')"
                >
                  {{ $t('ajaxButton.submit') }}
                </ajax-button>
              </div>
            </form>

          </div>

          <div class="img-wrap">
            <img
              src="~/assets/images/track-order.png"
              :alt="$t('ui.trackOrderPage.imageAlt')"
              height="50"
              width="50"
            >
          </div>
        </div>

        <!--
          The result. Everything here comes from the public tracking payload
          (FrontendController::trackOrder), which carries no name, email,
          phone or street address: the order number is all it takes to see
          this, so it must be safe for anyone holding one. The total and the
          link to the full order appear only for the signed-in owner.
        -->
        <section
          v-if="order"
          ref="result"
          class="tr"
          aria-live="polite"
        >

          <!-- summary -->
          <div class="tr-card tr-head" :class="`tr-head-${tone}`">
            <div class="tr-head-main">
              <p class="tr-kicker">{{ $t('ui.trackOrderPage.orderNumber', { number: order.order }) }}</p>
              <h2 class="tr-headline">{{ headline }}</h2>
              <p v-if="subline" class="tr-sub">{{ subline }}</p>
            </div>

            <div class="tr-head-side">
              <span class="tr-pill" :class="`tr-pill-${tone}`">{{ statusLabel }}</span>
              <p class="tr-meta">{{ $t('ui.trackOrderPage.placed', { date: fmtDate(order.placed_at) }) }}</p>
              <p v-if="order.last_update" class="tr-meta">
                {{ $t('ui.trackOrderPage.lastUpdate', { date: fmtDateTime(order.last_update) }) }}
              </p>
            </div>
          </div>

          <!-- progress -->
          <div class="tr-card">
            <h3 class="tr-title">{{ $t('ui.trackOrderPage.progress') }}</h3>

            <ol
              class="tr-steps"
              :class="{ 'tr-steps-stopped': stopped }"
            >
              <li
                v-for="step in steps"
                :key="step.status"
                class="tr-step"
                :class="{ 'is-done': step.done, 'is-current': step.current }"
              >
                <span class="tr-dot" aria-hidden="true">
                  <svg v-if="step.done" viewBox="0 0 24 24" focusable="false">
                    <path d="M6 12.4 10.2 16.5 18 8.5" />
                  </svg>
                  <span v-else>{{ step.index }}</span>
                </span>
                <span class="tr-step-label">
                  {{ step.label }}
                  <span v-if="step.current" class="sr-only">{{ $t('ui.trackOrderPage.currentStep') }}</span>
                </span>
                <span class="tr-step-date">{{ step.at ? fmtShort(step.at) : '' }}</span>
              </li>
            </ol>

            <p v-if="stoppedNote" class="tr-stopped">{{ stoppedNote }}</p>
          </div>

          <!--
            The parcel, as AfterShip last reported it. The tracking number,
            the courier's scans and its link are only in the payload for the
            signed-in owner or the signed link from the shipping email; anyone
            else holding the order number sees the courier and the status.
          -->
          <div v-if="shipment" class="tr-card tr-ship">
            <h3 class="tr-title">
              {{ $t('ui.trackOrderPage.parcel') }}
              <span class="tr-pill" :class="`tr-pill-${shipmentTone}`">{{ shipment.status }}</span>
            </h3>

            <dl class="tr-facts">
              <dt>{{ $t('ui.trackOrderPage.courier') }}</dt>
              <dd>{{ shipment.courier }}</dd>

              <dt>{{ $t('ui.trackOrderPage.trackingNumber') }}</dt>
              <dd class="tr-ship-number">{{ shipment.tracking_number }}</dd>

              <template v-if="shipment.detail && shipment.detail !== shipment.status">
                <dt>{{ $t('ui.trackOrderPage.latest') }}</dt>
                <dd>{{ shipment.detail }}</dd>
              </template>

              <template v-if="shipment.expected_delivery">
                <dt>{{ $t('ui.trackOrderPage.courierEstimate') }}</dt>
                <dd>{{ fmtDay(shipment.expected_delivery.slice(0, 10)) }}</dd>
              </template>

              <template v-if="shipment.updated_at">
                <dt>{{ $t('ui.trackOrderPage.updated') }}</dt>
                <dd>{{ fmtDateTime(shipment.updated_at) }}</dd>
              </template>
            </dl>

            <ul v-if="shipment.checkpoints && shipment.checkpoints.length" class="tr-history tr-ship-scans">
              <li
                v-for="(scan, i) in shipment.checkpoints"
                :key="i"
                class="tr-history-item"
              >
                <span class="tr-history-dot" aria-hidden="true" />
                <div>
                  <strong>{{ scan.message || scan.status }}</strong>
                  <span class="tr-history-at">
                    {{ scan.at ? fmtDateTime(scan.at) : '' }}<template v-if="scan.place"> &middot; {{ scan.place }}</template>
                  </span>
                </div>
              </li>
            </ul>

            <p v-if="!shipment.full" class="tr-note">
              {{ $t('ui.trackOrderPage.fullTrackingNote') }}
            </p>

            <a
              v-if="shipment.url"
              :href="shipment.url"
              target="_blank"
              rel="noopener noreferrer"
              class="tr-btn tr-btn-primary tr-ship-link"
            >
              {{ $t('ui.trackOrderPage.trackWith', { courier: shipment.courier }) }}
            </a>
          </div>

          <div class="tr-grid">

            <!-- items -->
            <div class="tr-card">
              <h3 class="tr-title">
                {{ $t('ui.trackOrderPage.items') }}
                <span class="tr-count">{{ $tc('ui.trackOrderPage.itemCount', order.item_count, { count: order.item_count }) }}</span>
              </h3>

              <ul class="tr-items">
                <li
                  v-for="(item, i) in order.items"
                  :key="i"
                  class="tr-item"
                >
                  <nuxt-link
                    v-if="item.slug"
                    :to="productLink(item)"
                    class="tr-thumb"
                  >
                    <img
                      :src="getThumbImageURL(item.image || defaultImage)"
                      :alt="item.title"
                      loading="lazy"
                    >
                  </nuxt-link>

                  <div class="tr-item-body">
                    <nuxt-link
                      v-if="item.slug"
                      :to="productLink(item)"
                      class="tr-item-title"
                    >
                      {{ item.title }}
                    </nuxt-link>
                    <span v-else class="tr-item-title">{{ item.title || $t('ui.trackOrderPage.item') }}</span>

                    <span v-if="item.variant" class="tr-item-variant">{{ item.variant }}</span>
                    <span class="tr-item-qty">{{ $t('ui.trackOrderPage.qty', { qty: item.quantity }) }}</span>
                  </div>
                </li>
              </ul>
            </div>

            <!-- delivery + payment -->
            <div class="tr-card">
              <h3 class="tr-title">
                {{ isCollection ? $t('ui.trackOrderPage.clickCollect') : $t('ui.trackOrderPage.delivery') }}
              </h3>

              <dl class="tr-facts">
                <template v-if="order.estimate">
                  <dt>{{ isCollection ? $t('ui.trackOrderPage.estimatedReady') : $t('ui.trackOrderPage.estimatedDelivery') }}</dt>
                  <dd :class="{ 'tr-late': order.estimate.late }">
                    {{ fmtDay(order.estimate.date) }}
                    <span v-if="order.estimate.late" class="tr-late-tag">{{ $t('ui.trackOrderPage.runningLate') }}</span>
                  </dd>
                </template>

                <template v-if="isCollection && order.collection_point">
                  <dt>{{ $t('ui.trackOrderPage.collectFrom') }}</dt>
                  <dd>{{ order.collection_point.address }}</dd>

                  <template v-if="order.collection_point.phone">
                    <dt>{{ $t('ui.trackOrderPage.storePhone') }}</dt>
                    <dd>
                      <a :href="`tel:${order.collection_point.phone}`">{{ order.collection_point.phone }}</a>
                    </dd>
                  </template>
                </template>

                <template v-else-if="order.destination">
                  <dt>{{ $t('ui.trackOrderPage.deliveringTo') }}</dt>
                  <dd>{{ order.destination }}</dd>
                </template>

                <dt>{{ $t('ui.trackOrderPage.payment') }}</dt>
                <dd>
                  <!-- "Cash on delivery · Pay on collection" said it twice -->
                  <span v-if="order.payment_method && !isCash">{{ order.payment_method }} · </span>
                  <span :class="order.paid ? 'tr-paid' : 'tr-unpaid'">
                    {{ paymentLabel }}
                  </span>
                </dd>

                <template v-if="order.is_owner && order.total_amount">
                  <dt>{{ $t('ui.trackOrderPage.orderTotal') }}</dt>
                  <dd><strong>{{ money(order.total_amount) }}</strong></dd>
                </template>
              </dl>

              <p v-if="isCollection && order.state === 'active'" class="tr-note">
                {{ $t('ui.trackOrderPage.collectNote') }}
              </p>
            </div>
          </div>

          <!-- history -->
          <div class="tr-card">
            <h3 class="tr-title">{{ $t('ui.trackOrderPage.orderHistory') }}</h3>

            <ul class="tr-history">
              <li
                v-for="(event, i) in history"
                :key="i"
                class="tr-history-item"
              >
                <span class="tr-history-dot" aria-hidden="true" />
                <div>
                  <strong>{{ event.label }}</strong>
                  <span class="tr-history-at">{{ fmtDateTime(event.at) }}</span>
                </div>
              </li>
            </ul>
          </div>

          <!-- help -->
          <div class="tr-card tr-help">
            <div>
              <h3 class="tr-title tr-title-flush">{{ $t('ui.trackOrderPage.needHelp') }}</h3>
              <i18n path="ui.trackOrderPage.quoteOrder" tag="p" class="tr-sub"><template #number><strong>{{ order.order }}</strong></template></i18n>
            </div>

            <div class="tr-help-actions">
              <nuxt-link
                v-if="order.is_owner && order.id"
                :to="`/user/order/${order.id}`"
                class="tr-btn tr-btn-primary"
              >
                {{ $t('ui.trackOrderPage.viewFullOrder') }}
              </nuxt-link>
              <a
                v-if="supportEmail"
                :href="`mailto:${supportEmail}?subject=${encodeURIComponent('Order ' + order.order)}`"
                class="tr-btn"
              >
                {{ $t('ui.trackOrderPage.emailUs') }}
              </a>
              <a
                v-if="supportPhone"
                :href="`tel:${supportPhone}`"
                class="tr-btn"
              >
                {{ $t('ui.trackOrderPage.call', { phone: supportPhone }) }}
              </a>
            </div>
          </div>
        </section>

      </div>
    </div>
  </client-only>
</template>
<script>
  import Breadcrumb from "../components/Breadcrumb";
  import AjaxButton from "../components/AjaxButton";
  import util from "~/mixin/util";
  import {mapGetters, mapActions} from 'vuex'
  import global from '~/mixin/global'

  // The five steps an order moves through. 6-8 end it early.
  const STEPS = [
    { status: 1, key: 'placed' },
    { status: 2, key: 'confirmed' },
    { status: 3, key: 'pickedUp' },
    { status: 4, key: 'onTheWay' },
    { status: 5, key: 'delivered' }
  ]

  export default {
    middleware: ['common-middleware'],
    components: {AjaxButton, Breadcrumb},
    head() {
      return {
        title: this.$t('header.trackOrder'),
        description: this.$t('header.trackOrder')
      }
    },
    data() {
      return {
        formSubmitting: false,
        hasFormError: false,
        errors: [],
        order: null,
        trackingId: null,
      }
    },
    mixins: [util, global],
    computed: {
      pageTitle() {
        return this.$t('header.trackOrder')
      },
      ...mapGetters('language', ['langData', 'currentLanguage', 'langCode']),
      ...mapGetters('common', ['setting']),

      supportEmail() {
        return this.setting?.email
      },
      supportPhone() {
        return this.setting?.phone
      },
      isCollection() {
        return this.order?.fulfilment === 'collection'
      },
      // Cancelled, returned or refunded: the steps stop where they stopped.
      stopped() {
        return ['cancelled', 'returned', 'refunded'].includes(this.order?.state)
      },
      reachedAt() {
        const map = {}
        ;(this.order?.timeline || []).forEach(t => { map[t.status] = t.at })
        return map
      },
      steps() {
        const status = parseInt(this.order?.status) || 1
        const delivered = this.order?.state === 'delivered'

        return STEPS.map((step, i) => {
          // A stopped order only marks the steps it actually reached.
          const done = this.stopped
            ? !!this.reachedAt[step.status] || step.status === 1
            : step.status <= status

          return {
            ...step,
            index: i + 1,
            label: step.status === 5 && this.isCollection ? this.$t('ui.trackOrderPage.status.collected') : this.stepLabel(step.key),
            done,
            current: !this.stopped && !delivered && step.status === status,
            at: this.reachedAt[step.status] || null
          }
        })
      },
      statusLabel() {
        const state = this.order?.state

        if (state === 'cancelled') return this.$t('ui.trackOrderPage.status.cancelled')
        if (state === 'returned') return this.$t('ui.trackOrderPage.status.returned')
        if (state === 'refunded') return this.$t('ui.trackOrderPage.status.refunded')
        if (state === 'delivered') return this.isCollection ? this.$t('ui.trackOrderPage.status.collected') : this.$t('ui.trackOrderPage.status.delivered')
        if (this.order?.estimate?.late) return this.$t('ui.trackOrderPage.runningLate')

        const step = STEPS.find(s => s.status === parseInt(this.order?.status))
        return step ? this.stepLabel(step.key) : this.$t('ui.trackOrderPage.steps.placed')
      },
      tone() {
        const state = this.order?.state

        if (state === 'delivered') return 'good'
        if (this.stopped) return 'bad'
        if (this.order?.estimate?.late) return 'warn'
        return 'info'
      },
      headline() {
        const o = this.order
        const at = this.reachedAt

        switch (o?.state) {
          case 'delivered':
            if (at[5]) {
              return this.isCollection
                ? this.$t('ui.trackOrderPage.headline.collectedOn', { date: this.fmtDay(at[5]) })
                : this.$t('ui.trackOrderPage.headline.deliveredOn', { date: this.fmtDay(at[5]) })
            }
            return this.isCollection ? this.$t('ui.trackOrderPage.status.collected') : this.$t('ui.trackOrderPage.status.delivered')
          case 'cancelled':
            return this.$t('ui.trackOrderPage.headline.cancelled')
          case 'returned':
            return this.$t('ui.trackOrderPage.headline.returned')
          case 'refunded':
            return this.$t('ui.trackOrderPage.headline.refunded')
        }

        if (o?.estimate?.late) {
          return this.$t('ui.trackOrderPage.headline.late')
        }

        if (o?.estimate) {
          return this.isCollection
            ? this.$t('ui.trackOrderPage.headline.readyBy', { date: this.fmtDay(o.estimate.date) })
            : this.$t('ui.trackOrderPage.headline.arrivingBy', { date: this.fmtDay(o.estimate.date) })
        }

        return this.$t('ui.trackOrderPage.headline.received')
      },
      subline() {
        const o = this.order

        if (o?.state === 'cancelled' || o?.state === 'refunded') {
          if (o.refund) {
            return o.refund.at
              ? this.$t('ui.trackOrderPage.sub.refundIssuedOn', { date: this.fmtDay(o.refund.at) })
              : this.$t('ui.trackOrderPage.sub.refundIssued')
          }

          return o.paid
            ? this.$t('ui.trackOrderPage.sub.ifCharged')
            : this.$t('ui.trackOrderPage.sub.notCharged')
        }

        if (o?.state === 'delivered') {
          return this.$t('ui.trackOrderPage.sub.delivered')
        }

        if (o?.estimate?.late) {
          return this.$t('ui.trackOrderPage.sub.late', { date: this.fmtDay(o.estimate.date) })
        }

        if (o?.estimate) {
          return this.$t('ui.trackOrderPage.sub.estimate')
        }

        return ''
      },
      stoppedNote() {
        const o = this.order
        const code = {cancelled: 6, returned: 7, refunded: 8}[o?.state]

        if (!code) {
          return ''
        }

        const when = this.reachedAt[code]

        return when
          ? this.$t('ui.trackOrderPage.stoppedOn', { status: this.statusLabel, date: this.fmtDay(when) })
          : this.$t('ui.trackOrderPage.stopped', { status: this.statusLabel })
      },
      // Newest first.
      shipment() {
        return this.order?.shipment || null
      },
      // Same three tones as the summary pill.
      shipmentTone() {
        const tag = this.shipment?.tag
        if (tag === 'Delivered') return 'good'
        if (tag === 'Exception') return 'bad'
        if (['AttemptFail', 'Expired'].includes(tag)) return 'warn'
        return 'info'
      },
      history() {
        return [...(this.order?.timeline || [])].reverse()
      },
      isCash() {
        return /cash/i.test(this.order?.payment_method || '')
      },
      paymentLabel() {
        if (this.order?.paid) {
          return this.$t('ui.trackOrderPage.pay.paid')
        }

        // A stopped order that was never paid is not waiting for money.
        if (this.stopped) {
          return this.$t('ui.trackOrderPage.pay.notCharged')
        }

        // Cash is paid at the door or the counter, not missing.
        return this.isCash
          ? (this.isCollection ? this.$t('ui.trackOrderPage.pay.onCollection') : this.$t('ui.trackOrderPage.pay.onDelivery'))
          : this.$t('ui.trackOrderPage.pay.awaiting')
      }
    },
    methods: {
      stepLabel(key) {
        return {
          placed: this.$t('ui.trackOrderPage.steps.placed'),
          confirmed: this.$t('ui.trackOrderPage.steps.confirmed'),
          pickedUp: this.$t('ui.trackOrderPage.steps.pickedUp'),
          onTheWay: this.$t('ui.trackOrderPage.steps.onTheWay'),
          delivered: this.$t('ui.trackOrderPage.steps.delivered')
        }[key]
      },
      /*
       * Dates arrive as ISO strings, or as a plain YYYY-MM-DD for the
       * estimate. A plain date is read as local midnight - new Date() would
       * read it as UTC and could show the day before.
       */
      toDate(value) {
        if (!value) return null
        const plain = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value)
        const d = plain ? new Date(+plain[1], +plain[2] - 1, +plain[3]) : new Date(value)
        return isNaN(d.getTime()) ? null : d
      },
      fmtDay(value) {
        const d = this.toDate(value)
        return d ? d.toLocaleDateString('en-IE', { weekday: 'short', day: 'numeric', month: 'short' }) : ''
      },
      fmtDate(value) {
        const d = this.toDate(value)
        return d ? d.toLocaleDateString('en-IE', { day: 'numeric', month: 'short', year: 'numeric' }) : ''
      },
      fmtShort(value) {
        const d = this.toDate(value)
        return d ? d.toLocaleDateString('en-IE', { day: 'numeric', month: 'short' }) : ''
      },
      fmtDateTime(value) {
        const d = this.toDate(value)
        return d
          ? `${this.fmtDate(value)}, ${d.toLocaleTimeString('en-IE', { hour: '2-digit', minute: '2-digit' })}`
          : ''
      },
      money(value) {
        return `€${Number(value || 0).toFixed(2)}`
      },

      async formSubmit() {
        const id = (this.trackingId || '').trim()

        if (!id) {
          this.hasFormError = true
          return
        }

        this.trackingId = id
        this.formSubmitting = true

        try {
          const params = { tracking_id: id }

          /*
           * The signed link from the shipping email unlocks the tracking
           * number and courier scans for that one order. Only sent with the
           * order it was issued for, not with a number typed in afterwards.
           */
          const token = (this.$route.query.t || '').toString()
          if (token && id === (this.$route.query.order_id || '').toString().trim()) {
            params.t = token
          }

          const data = await this.getRequest({
            params,
            lang: this.langCode,
            api: 'trackOder',
            // Signed in, the owner also gets the total and a link to the order.
            requiredToken: !!this.$auth?.loggedIn
          })

          if (data?.status === 200) {
            this.hasFormError = false
            this.errors = []
            this.order = data.data

            this.$nextTick(() => this.scrollToResult())

          } else {
            this.order = null
            // The API's own "We couldn't found anything." is not a sentence.
            this.errors = data?.status === 201
              ? [this.$t('ui.trackOrderPage.errors.notFound')]
              : (data?.data?.form || [this.$t('ui.trackOrderPage.errors.lookupFailed')])
          }

        } catch (e) {
          this.order = null
          this.errors = [this.$t('ui.trackOrderPage.errors.lookupFailed')]
        } finally {
          this.formSubmitting = false
        }
      },
      /*
       * Below the sticky header, which is measured: it carries the promo
       * banner, so its height changes between pages, widths and sessions.
       */
      scrollToResult() {
        const el = this.$refs.result
        if (!el) return

        const header = document.querySelector('header')
        const offset = header && getComputedStyle(header).position === 'sticky'
          ? header.getBoundingClientRect().height
          : 0

        window.scrollTo({
          top: el.getBoundingClientRect().top + window.pageYOffset - offset - 16,
          behavior: 'smooth'
        })
      },
      /*
       * "Track order" on My Orders links here as /track-order?order_id=...
       * but the id was never read, so the customer landed on an empty form
       * and had to type an order number the site had just been showing them.
       * Fill it in and look it up straight away.
       */
      trackFromQuery() {
        const id = (this.$route.query.order_id || '').toString().trim()

        if (!id) {
          return
        }

        this.trackingId = id
        this.formSubmit()
      },
      ...mapActions('common', ['getRequest']),
    },
    watch: {
      // Another "Track order" link followed while already on this page.
      '$route.query.order_id'() {
        this.trackFromQuery()
      }
    },
    async mounted() {
      this.trackFromQuery()
    }
  }
</script>

<style scoped>
/*
 * Tracking result. Same card scale as the account pages (14px radius,
 * #E3E3EF border, #130E2B ink, #33319A brand) so it reads as part of the
 * shop rather than a bolt-on.
 */
.tr {
  display: grid;
  gap: 16px;
  margin-top: 32px;
  color: #130E2B;
}

.tr-card {
  background: #fff;
  border: 1px solid #E3E3EF;
  border-radius: 14px;
  padding: 20px 22px;
  min-width: 0;
}

.tr-title {
  display: flex;
  align-items: baseline;
  gap: 8px;
  font-size: 16px;
  font-weight: 600;
  margin: 0 0 16px;
}

.tr-title-flush { margin-bottom: 4px; }

.tr-count {
  font-size: 13px;
  font-weight: 400;
  color: #6B7280;
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
}

/* ---------- summary ---------- */

.tr-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
  flex-wrap: wrap;
  border-left: 4px solid #33319A;
}

.tr-head-good { border-left-color: #059669; }
.tr-head-warn { border-left-color: #D97706; }
.tr-head-bad  { border-left-color: #DC2626; }

.tr-head-main { min-width: 0; }

.tr-kicker {
  font-size: 13px;
  color: #6B7280;
  margin: 0 0 4px;
  overflow-wrap: anywhere;
}

.tr-headline {
  font-size: 22px;
  font-weight: 600;
  line-height: 1.3;
  margin: 0 0 6px;
}

.tr-sub {
  font-size: 14px;
  color: #4B5563;
  margin: 0;
  line-height: 1.55;
}

.tr-head-side {
  text-align: right;
}

.tr-meta {
  font-size: 12.5px;
  color: #6B7280;
  margin: 6px 0 0;
}

.tr-pill {
  display: inline-block;
  font-size: 12px;
  font-weight: 600;
  padding: 5px 12px;
  border-radius: 999px;
  white-space: nowrap;
}

.tr-pill-info { background: #EBEBFF; color: #33319A; }
.tr-pill-good { background: #ECFDF5; color: #047857; }
.tr-pill-warn { background: #FFFBEB; color: #B45309; }
.tr-pill-bad  { background: #FEF2F2; color: #B91C1C; }

/* ---------- progress ---------- */

.tr-steps {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  list-style: none;
  padding: 0;
  margin: 0;
}

.tr-step {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: 6px;
}

/* the connector to the next step */
.tr-step:not(:last-child)::after {
  content: '';
  position: absolute;
  top: 17px;
  left: calc(50% + 20px);
  right: calc(-50% + 20px);
  height: 3px;
  border-radius: 3px;
  background: #E5E7EB;
}

.tr-step.is-done:not(:last-child)::after { background: #33319A; }
.tr-step.is-current::after { background: #E5E7EB !important; }

.tr-dot {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #F3F4F6;
  color: #6B7280;
  font-size: 14px;
  font-weight: 600;
  border: 2px solid #E5E7EB;
}

.tr-dot svg {
  width: 18px;
  height: 18px;
  fill: none;
  stroke: currentColor;
  stroke-width: 2.6;
  stroke-linecap: round;
  stroke-linejoin: round;
}

.tr-step.is-done .tr-dot {
  background: #33319A;
  border-color: #33319A;
  color: #fff;
}

.tr-step.is-current .tr-dot {
  box-shadow: 0 0 0 5px rgba(51, 49, 154, .15);
}

.tr-step-label {
  font-size: 13px;
  font-weight: 600;
  color: #374151;
}

.tr-step.is-current .tr-step-label { color: #33319A; }

.tr-step-date {
  font-size: 12px;
  color: #6B7280;
  min-height: 16px;
}

.tr-steps-stopped .tr-step:not(.is-done) { opacity: .45; }

.tr-stopped {
  margin: 16px 0 0;
  padding: 10px 12px;
  border-radius: 8px;
  background: #FEF2F2;
  color: #991B1B;
  font-size: 13.5px;
}

/* ---------- items + delivery ---------- */

.tr-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr);
  gap: 16px;
}

.tr-items {
  list-style: none;
  padding: 0;
  margin: 0;
}

.tr-item {
  display: flex;
  gap: 14px;
  align-items: flex-start;
  padding: 12px 0;
  border-top: 1px solid #F0F0F6;
}

.tr-item:first-child { border-top: 0; padding-top: 0; }

.tr-thumb {
  flex: 0 0 64px;
  width: 64px;
  height: 64px;
  border: 1px solid #EEF0F5;
  border-radius: 10px;
  overflow: hidden;
  background: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
}

.tr-thumb img {
  max-width: 100%;
  max-height: 100%;
  object-fit: contain;
}

.tr-item-body {
  display: flex;
  flex-direction: column;
  gap: 3px;
  min-width: 0;
}

.tr-item-title {
  font-size: 14px;
  font-weight: 600;
  color: #130E2B;
  text-decoration: none;
}

a.tr-item-title:hover { color: #33319A; text-decoration: underline; }

.tr-item-variant,
.tr-item-qty {
  font-size: 12.5px;
  color: #6B7280;
}

.tr-facts {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 10px 16px;
  margin: 0;
  font-size: 14px;
}

.tr-facts dt {
  color: #6B7280;
  font-weight: 500;
}

.tr-facts dd {
  margin: 0;
  overflow-wrap: anywhere;
}

.tr-facts a { color: #33319A; }

.tr-late { color: #B45309; }

.tr-late-tag {
  display: inline-block;
  margin-left: 6px;
  padding: 1px 8px;
  border-radius: 999px;
  background: #FFFBEB;
  font-size: 11.5px;
  font-weight: 600;
}

.tr-paid   { color: #047857; font-weight: 600; }
.tr-unpaid { color: #B45309; font-weight: 600; }

.tr-note {
  margin: 16px 0 0;
  font-size: 13px;
  color: #4B5563;
  background: #F8F9FD;
  border-radius: 8px;
  padding: 10px 12px;
}

/* ---------- history ---------- */

.tr-history {
  list-style: none;
  margin: 0;
  padding: 0;
}

.tr-history-item {
  position: relative;
  display: flex;
  gap: 12px;
  padding: 0 0 14px 0;
  font-size: 14px;
}

.tr-history-item:last-child { padding-bottom: 0; }

/* the vertical line between entries */
.tr-history-item:not(:last-child)::before {
  content: '';
  position: absolute;
  left: 5px;
  top: 14px;
  bottom: 0;
  width: 2px;
  background: #E5E7EB;
}

.tr-history-dot {
  flex: 0 0 12px;
  height: 12px;
  margin-top: 4px;
  border-radius: 50%;
  background: #33319A;
}

.tr-history-item:not(:first-child) .tr-history-dot { background: #C7C7D9; }

.tr-history-at {
  display: block;
  font-size: 12.5px;
  color: #6B7280;
}

/* ---------- help ---------- */

.tr-help {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
  flex-wrap: wrap;
  background: #F8F9FD;
}

.tr-help-actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}

/*
 * Links styled as buttons, so base.styl's button metrics never reach them.
 */
.tr-btn {
  display: inline-flex;
  align-items: center;
  height: 40px;
  padding: 0 16px;
  border: 1px solid #D7DAE9;
  border-radius: 8px;
  background: #fff;
  color: #130E2B;
  font-size: 14px;
  font-weight: 500;
  text-decoration: none;
  white-space: nowrap;
}

.tr-btn:hover {
  border-color: #33319A;
  color: #33319A;
}

.tr-btn-primary {
  background: #33319A;
  border-color: #33319A;
  color: #fff;
}

.tr-btn-primary:hover {
  background: #28267d;
  color: #fff;
}

/* ---------- parcel (AfterShip) ---------- */

.tr-ship .tr-title { align-items: center; }
.tr-ship .tr-title .tr-pill { margin-left: auto; }
.tr-ship-number { font-variant-numeric: tabular-nums; letter-spacing: .02em; word-break: break-all; }
.tr-ship-scans { margin-top: 18px; }
.tr-ship-link { margin-top: 16px; }

/* ---------- small screens ---------- */

@media (max-width: 991px) {
  .tr-grid { grid-template-columns: minmax(0, 1fr); }
}

@media (max-width: 767px) {
  .tr-card { padding: 16px; }

  .tr-headline { font-size: 19px; }

  .tr-head-side { text-align: left; }

  /*
   * Five columns do not fit a phone: the steps turn into a vertical list,
   * with the connector running down the left.
   */
  .tr-steps {
    grid-template-columns: minmax(0, 1fr);
    gap: 14px;
  }

  .tr-step {
    flex-direction: row;
    text-align: left;
    gap: 12px;
  }

  .tr-step:not(:last-child)::after {
    top: 38px;
    left: 17px;
    right: auto;
    width: 3px;
    height: 14px;
  }

  .tr-step-date { margin-left: auto; }

  .tr-help-actions { width: 100%; }
  .tr-btn { flex: 1 1 auto; justify-content: center; }
}
</style>
