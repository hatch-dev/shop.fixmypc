<template>
  <div class="bid-page">

    <header class="bid-head">
      <h1 v-if="orderNumber">{{ $t('ui.supplierBid.orderNumber', { number: orderNumber }) }}</h1>
      <!--
        Names who is asking. The supplier arrives from a message with no
        account and no branding around the form, so "give us your price" does
        not say who "us" is.
      -->
      <i18n v-if="!loading && !loadError && bidItems.length" :path="bidItems.length === 1 ? 'ui.supplierBid.introOne' : 'ui.supplierBid.introMany'" tag="p">
        <template #who><strong v-if="store">{{ store }}</strong><span v-else>{{ $t('ui.supplierBid.we') }}</span></template>
        <template #count>{{ bidItems.length }}</template>
      </i18n>
    </header>

    <div v-if="loading" class="bid-state">{{ $t('ui.supplierBid.loading') }}</div>

    <div v-else-if="loadError" class="bid-state bid-state--error">{{ loadError }}</div>

    <div v-else-if="!bidItems.length" class="bid-state">
      {{ $t('ui.supplierBid.nothingToQuote') }}
    </div>

    <ul v-else class="bid-list">
      <li class="bid-card" v-for="item in bidItems" :key="item.id">

        <div class="bid-media">
          <img
            v-if="!imgErrors[item.id]"
            :src="getProductImage(item)"
            :alt="productTitle(item)"
            loading="lazy"
            @error="imageFailed(item.id)"
          >
          <span v-else class="bid-media__fallback" aria-hidden="true">{{ $t('ui.supplierBid.noImage') }}</span>
        </div>

        <div class="bid-body">
          <h2 class="bid-title">{{ productTitle(item) }}</h2>

          <!--
            Not `badge`: Bootstrap 5 is loaded site-wide and its .badge sets
            white text, which on a light chip is invisible. Prefixed names
            throughout for the same reason.
          -->
          <ul class="bid-attrs" v-if="attributesFor(item).length">
            <li v-for="attr in attributesFor(item)" :key="attr.id">
              <span class="bid-attrs__key">{{ attr.label }}</span>
              <span class="bid-attrs__val">{{ attr.value }}</span>
            </li>
          </ul>

          <p class="bid-qty">
            {{ $t('ui.supplierBid.quantityNeeded') }} <strong>{{ item.quantity }}</strong>
            <span v-if="item.ordered_price != null">
              · {{ $t('ui.supplierBid.orderedAt') }} <strong>{{ vatNote(item) }}</strong>
            </span>
          </p>

          <!--
            Deliberately not gated on our stock. This page exists because the
            item has been sold and has to be sourced, and the order has
            already taken the stock down.
          -->
          <div class="bid-form">
            <label class="bid-field bid-field--price">
              <span class="bid-label">{{ $t('ui.supplierBid.yourPrice') }}</span>
              <span class="bid-price">
                <span class="bid-price__cur">€</span>
                <input
                  type="number"
                  inputmode="decimal"
                  min="0.01"
                  step="0.01"
                  v-model="item.price"
                  placeholder="0.00"
                  :disabled="item.saving"
                >
              </span>
            </label>

            <label class="bid-field">
              <span class="bid-label">{{ $t('ui.supplierBid.condition') }}</span>
              <select v-model="item.grade" :disabled="item.saving">
                <option disabled value="">{{ $t('ui.supplierBid.select') }}</option>
                <option v-for="grade in grades" :key="grade" :value="grade">
                  {{ grade }}
                </option>
              </select>
            </label>

            <button
              type="button"
              class="bid-submit"
              :disabled="item.saving"
              @click="submitBid(item)"
            >
              {{ item.saving ? $t('ui.supplierBid.sending') : (item.submitted ? $t('ui.supplierBid.update') : $t('ui.supplierBid.submit')) }}
            </button>
          </div>

          <p v-if="item.error" class="bid-msg bid-msg--error">{{ item.error }}</p>

          <!--
            Two different moments, two different sentences. "Price sent" is a
            confirmation of something just done; on a page the supplier has
            only opened, the same words claim an action nobody took. A quote
            already on file is reported as a standing fact, with when it was
            given.
          -->
          <p v-else-if="item.justSaved" class="bid-msg bid-msg--ok">
            {{ store
              ? $t('ui.supplierBid.priceSentTo', { store: store, price: money(item.submitted.price), grade: item.submitted.grade })
              : $t('ui.supplierBid.priceSent', { price: money(item.submitted.price), grade: item.submitted.grade }) }}
          </p>

          <p v-else-if="item.submitted" class="bid-msg bid-msg--note">
            <i18n path="ui.supplierBid.currentQuote" tag="span"><template #price><strong>€{{ money(item.submitted.price) }}</strong></template><template #grade>{{ item.submitted.grade }}</template></i18n><span v-if="quotedAt(item)"> · {{ $t('ui.supplierBid.sentAt', { when: quotedAt(item) }) }}</span>.
            {{ $t('ui.supplierBid.changeHint') }}
          </p>
        </div>
      </li>
    </ul>

  </div>
</template>
<script>
import Service from '~/services/service'

export default {
  layout: 'blank',

  data() {
    return {
      token: '',
      orderNumber: '',
      supplierId: '',
      grades: [],
      // Who the price goes to, for the page to say so out loud.
      store: '',
      bidItems: [],
      loading: true,
      loadError: '',
      // Product ids whose image could not be loaded.
      imgErrors: {}
    }
  },

  mounted() {
    this.token = this.$route.params.token

    if (!this.token) {
      this.loading = false
      this.loadError = this.$t('ui.supplierBid.linkInvalid')
      return
    }

    this.fetchOrder()
  },

  methods: {


    async fetchOrder() {
      this.loading = true
      this.loadError = ''

      try {
        /*
         * Through the shared service, which resolves the API host the same
         * way every other page does: API_BASE when it is set, and the page's
         * own origin when it is empty (the storefront served by Laravel).
         * Building the URL here sent the call to whatever host the page was
         * on -- the dev server on :3000, which has no API behind it.
         */
        const { data } = await Service.supplierRequest(this.token)

        this.orderNumber = data.order_number
        this.supplierId = data.supplier_id
        this.grades = data.grades || []
        this.store = data.store || ''

        this.bidItems = (data.data || []).map(item => ({
          ...item,
          /*
           * A bid already given wins -- a supplier who reopens the link sees
           * what they quoted. Otherwise the box opens on what the line was
           * charged at, as a starting point rather than a blank field.
           */
          price: item.bid
            ? item.bid.price
            : (item.ordered_price !== null && item.ordered_price !== undefined
                ? String(item.ordered_price)
                : ''),
          grade: item.bid ? item.bid.grade : '',
          submitted: item.bid || null,
          // Only true for a quote given in this visit, not one loaded with
          // the page.
          justSaved: false,
          saving: false,
          error: ''
        }))

      } catch (error) {
        this.loadError = this.messageFrom(
          error,
          this.$t('ui.supplierBid.loadFailed')
        )
      } finally {
        this.loading = false
      }
    },

    /*
     * Every accessor below tolerates a missing relation. inventory_id is
     * nullable on supplier_notifications, and a product can be deleted after
     * the request goes out -- either used to throw during render and leave
     * the supplier a blank page with nothing in it to explain why.
     */
    /*
     * Which VAT convention this line used. The catalogue runs both and the
     * order records which one applied, so it is read rather than assumed --
     * and never re-derived by dividing by a rate.
     */
    money(value) {
      return Number(value || 0).toFixed(2)
    },

    /*
     * When the quote on file was last given. Read off the bid rather than
     * assumed to be "just now", which is the whole point of the distinction.
     */
    quotedAt(item) {
      const at = item.submitted && item.submitted.updated_at

      if (!at) return ''

      const when = new Date(at)

      if (isNaN(when.getTime())) return ''

      return when.toLocaleString(undefined, {
        day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit'
      })
    },

    vatNote(item) {
      const price = `€${Number(item.ordered_price).toFixed(2)}`

      return item.ordered_price_includes_vat ? this.$t('ui.supplierBid.incVat', { price }) : this.$t('ui.supplierBid.exVat', { price })
    },

    productTitle(item) {
      return (item.product && item.product.title) || this.$t('ui.supplierBid.itemNotListed')
    },

    attributesFor(item) {
      const attrs = (item.inventory && item.inventory.inventory_attributes) || []

      return attrs
        .filter(a => a.attribute_value && a.attribute_value.attribute)
        .map(a => ({
          id: a.attribute_value.id,
          label: a.attribute_value.attribute.title,
          value: a.attribute_value.title
        }))
    },

    /*
     * Remember the failure in state and let the template swap in a
     * placeholder. Reaching into the DOM does not survive: Vue owns the
     * element and re-applies its attributes on the next patch.
     */
    imageFailed(id) {
      this.$set(this.imgErrors, id, true)
    },

    getProductImage(item) {
      const product = item.product || {}
      const images = product.product_images || []
      const image = images.length ? images[0].image : product.image

      if (!image) {
        return this.$store.state.defaultImage
          ? `${this.uploadsBase()}${this.$store.state.defaultImage}`
          : ''
      }

      return `${this.uploadsBase()}${image}`
    },

    uploadsBase() {
      /*
       * Set from the common settings call. The fallback is relative to the
       * page's own origin, which is right wherever the storefront is served
       * from the same host as its uploads.
       */
      return this.$store.state.imgSrcUrl || '/uploads/'
    },

    async submitBid(item) {
      item.error = ''

      const price = parseFloat(item.price)

      if (!price || price <= 0) {
        item.error = this.$t('ui.supplierBid.enterPrice')
        return
      }

      if (!item.grade) {
        item.error = this.$t('ui.supplierBid.selectGrade')
        return
      }

      item.saving = true

      try {
        /*
         * The token goes up with the bid and the server reads the supplier
         * and order out of it. Nothing here names a supplier_id: this page
         * held one, never assigned it, and posted an empty string.
         */
        const { data } = await Service.submitSupplierBid({
          token: this.token,
          product_id: item.product_id,
          inventory_id: item.inventory_id,
          price: price,
          grade: item.grade
        })

        item.submitted = data.data
        item.justSaved = true

      } catch (error) {
        item.error = this.messageFrom(
          error,
          this.$t('ui.supplierBid.saveFailed')
        )
      } finally {
        item.saving = false
      }
    },

    messageFrom(error, fallback) {
      const data = error && error.response && error.response.data

      if (data && data.errors) {
        const first = Object.keys(data.errors)[0]
        if (first) return data.errors[first][0]
      }

      return (data && data.message) || fallback
    }
  }
}
</script>

<style scoped>
/*
 * Mobile first: the base rules are the phone layout with the smaller type
 * scale, and the wider screens are the media queries. A supplier reads this
 * on a phone, from a message, far more often than at a desk.
 *
 * Every class is bid-prefixed and the block is scoped, because Bootstrap 5
 * is loaded site-wide -- its .badge (white text) is what made the attribute
 * chips unreadable, and .card, .form-control and .btn would collide just as
 * quietly.
 */

.bid-page {
  --bid-ink: #111827;
  --bid-muted: #6b7280;
  --bid-line: #e5e7eb;
  --bid-surface: #ffffff;
  --bid-accent: #33319a;
  --bid-accent-soft: #eef0fb;
  --bid-ok: #1e7e34;
  --bid-err: #c0392b;

  /*
   * One height for every control in the row.
   *
   * assets/styles/base.styl sets height:50px and line-height:48px on
   * every button, textarea and input site-wide. Left to it, the price box
   * came out 52px, the select 45px and the button 41px -- and the button's
   * label sat off-centre, laid out on a 48px line inside a shorter box.
   * Height and line-height therefore have to be set together here; setting
   * height alone is what produced the misaligned label.
   */
  --bid-control-h: 44px;

  max-width: 880px;
  margin: 0 auto;
  padding: 20px 16px 48px;
  color: var(--bid-ink);
  font-size: 14px;
  line-height: 1.5;
}

.bid-head {
  text-align: center;
  margin-bottom: 20px;
}

.bid-head h1 {
  margin: 0;
  font-size: 19px;
  font-weight: 700;
  line-height: 1.3;
  word-break: break-word;
}

.bid-head p {
  margin: 6px 0 0;
  font-size: 13px;
  color: var(--bid-muted);
}

.bid-state {
  border: 1px solid var(--bid-line);
  border-radius: 12px;
  background: var(--bid-surface);
  padding: 28px 20px;
  text-align: center;
  font-size: 14px;
  color: var(--bid-muted);
}

.bid-state--error {
  border-color: var(--bid-err);
  color: var(--bid-err);
}

.bid-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  /*
   * minmax(0, 1fr), not 1fr. A grid item's min-width defaults to auto, so a
   * long unbroken product title sets a min-content floor the track cannot
   * shrink below -- and the card runs off the side of a phone screen.
   */
  grid-template-columns: minmax(0, 1fr);
  gap: 14px;
}

.bid-card {
  display: flex;
  flex-direction: column;
  min-width: 0;
  gap: 14px;
  border: 1px solid var(--bid-line);
  border-radius: 12px;
  background: var(--bid-surface);
  padding: 14px;
  box-shadow: 0 1px 2px rgba(17, 24, 39, .06);
}

/* Image -------------------------------------------------------------- */

.bid-media {
  flex: none;
  align-self: center;
  width: 128px;
  height: 128px;
  border: 1px solid var(--bid-line);
  border-radius: 10px;
  background: #fafafa;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
}

.bid-media img {
  max-width: 100%;
  max-height: 100%;
  object-fit: contain;
}

/*
 * A product whose image is missing shows this instead of the browser's
 * broken-image icon and the alt text sprawling across the box.
 */
.bid-media__fallback {
  font-size: 11px;
  letter-spacing: .3px;
  text-transform: uppercase;
  color: var(--bid-muted);
}

.bid-body {
  min-width: 0;
  flex: 1;
}

.bid-title {
  margin: 0 0 10px;
  font-size: 16px;
  font-weight: 600;
  line-height: 1.35;
  /* Product titles carry long unspaced model numbers. */
  overflow-wrap: anywhere;
}

/* Attributes --------------------------------------------------------- */

.bid-attrs {
  list-style: none;
  margin: 0 0 10px;
  padding: 0;
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.bid-attrs li {
  display: inline-flex;
  align-items: baseline;
  gap: 5px;
  padding: 4px 9px;
  border-radius: 999px;
  background: var(--bid-accent-soft);
  font-size: 12px;
  line-height: 1.4;
}

.bid-attrs__key {
  color: var(--bid-muted);
}

.bid-attrs__val {
  color: var(--bid-accent);
  font-weight: 600;
}

.bid-qty {
  margin: 0 0 12px;
  font-size: 13px;
  color: var(--bid-muted);
}

.bid-qty strong {
  color: var(--bid-ink);
}

/* The form ----------------------------------------------------------- */

.bid-form {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.bid-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}

.bid-label {
  font-size: 12px;
  font-weight: 600;
  color: var(--bid-muted);
}

.bid-label em {
  font-style: normal;
  font-weight: 400;
  color: var(--bid-muted);
}

.bid-price {
  display: flex;
  align-items: stretch;
  height: var(--bid-control-h);
  border: 1px solid #d1d5db;
  border-radius: 8px;
  overflow: hidden;
  background: #fff;
  box-sizing: border-box;
}

.bid-price:focus-within,
.bid-field select:focus {
  border-color: var(--bid-accent);
  box-shadow: 0 0 0 3px var(--bid-accent-soft);
  outline: none;
}

.bid-price__cur {
  display: flex;
  align-items: center;
  padding: 0 10px;
  background: #f5f6f8;
  border-right: 1px solid #d1d5db;
  font-weight: 600;
  color: var(--bid-muted);
}

.bid-price input {
  flex: 1;
  min-width: 0;
  border: 0;
  outline: none;
  /*
   * 16px on the control itself: iOS Safari zooms the whole page in when a
   * focused input is any smaller, and the supplier is left pinched in on a
   * half-visible form.
   */
  font-size: 16px;
  height: 100%;
  line-height: normal;
  padding: 0 12px;
  background: transparent;
  color: inherit;
}

.bid-field select {
  width: 100%;
  height: var(--bid-control-h);
  line-height: normal;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  padding: 0 12px;
  font-size: 16px;
  background: #fff;
  color: inherit;
  box-sizing: border-box;
}

.bid-price input:disabled,
.bid-field select:disabled {
  background: #f9fafb;
  cursor: not-allowed;
}

.bid-submit {
  /*
   * Flex centring rather than relying on line-height, so the label stays
   * centred whatever the global stylesheet has to say about buttons.
   */
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: var(--bid-control-h);
  line-height: 1;
  padding: 0 24px;
  border: 0;
  border-radius: 8px;
  box-sizing: border-box;
  background: var(--bid-accent);
  color: #fff;
  font-size: 15px;
  font-weight: 600;
  cursor: pointer;
  transition: background .15s, opacity .15s;
}

.bid-submit:hover:not(:disabled) {
  background: #29277f;
}

.bid-submit:disabled {
  opacity: .6;
  cursor: not-allowed;
}

.bid-msg {
  margin: 10px 0 0;
  font-size: 13px;
  line-height: 1.45;
}

.bid-msg--error {
  color: var(--bid-err);
}

.bid-msg--ok {
  color: var(--bid-ok);
  font-weight: 600;
}

/* A quote already on file: a fact, not a fresh confirmation. */
.bid-msg--note {
  color: var(--bid-muted);
}

.bid-msg--note strong {
  color: var(--bid-ink);
}

/* ------------------------------------------------------------------ *
 *  Small tablets and up: the image moves beside the detail, and the
 *  price / condition / button sit on one line.
 * ------------------------------------------------------------------ */

@media (min-width: 560px) {
  .bid-page {
    padding: 28px 20px 56px;
    font-size: 15px;
  }

  .bid-head h1 {
    font-size: 24px;
  }

  .bid-head p {
    font-size: 14px;
  }

  .bid-card {
    flex-direction: row;
    align-items: flex-start;
    gap: 18px;
    padding: 18px;
  }

  .bid-media {
    align-self: flex-start;
    width: 116px;
    height: 116px;
  }

  .bid-title {
    font-size: 17px;
  }

  .bid-form {
    flex-direction: row;
    align-items: flex-end;
    flex-wrap: wrap;
    gap: 12px;
  }

  .bid-field--price {
    flex: 1 1 170px;
  }

  .bid-field {
    flex: 0 1 165px;
  }

  .bid-submit {
    width: auto;
    flex: none;
  }
}

@media (min-width: 860px) {
  .bid-page {
    padding: 36px 24px 64px;
  }

  .bid-head h1 {
    font-size: 26px;
  }

  .bid-media {
    width: 132px;
    height: 132px;
  }

  .bid-title {
    font-size: 18px;
  }
}

/*
 * Respect a reduced-motion preference: the only transitions here are on the
 * button, but the rule costs nothing and keeps the page honest.
 */
@media (prefers-reduced-motion: reduce) {
  .bid-submit {
    transition: none;
  }
}
</style>
