<template>
  <div>
    <client-only>
      <vue-html2pdf
        :show-layout="false"
        :float-layout="true"
        :enable-download="true"
        :preview-modal="false"
        :paginate-elements-by-height="1120"
        :filename="`Invoice-${order.order}`"
        :pdf-quality="2"
        :manual-pagination="false"
        :html-to-pdf-options="pdfOptions"
        pdf-format="a4"
        pdf-orientation="portrait"
        ref="html2Pdf"
      >
        <div slot="pdf-content" class="inv">

          <!-- ========================= Header ========================= -->
          <div class="inv-head">
            <div class="inv-brand">
              <img
                v-if="base64SiteLogo"
                class="inv-logo"
                :src="`data:image/png;base64,${base64SiteLogo}`"
                alt=""
              >
              <div class="inv-company">{{ companyName }}</div>
              <div class="inv-company-lines">
                <span v-for="(line, i) in companyAddressLines" :key="`co-${i}`">{{ line }}</span>
                <span v-if="companyPhone">{{ $t('profile.phone') }}: {{ companyPhone }}</span>
                <span v-if="companyEmail">{{ $t('profile.email') }}: {{ companyEmail }}</span>
                <span v-if="companyWebsite">{{ companyWebsite }}</span>
              </div>
            </div>

            <div class="inv-meta">
              <div class="inv-title">{{ $t('setting.inv') }}</div>
              <table class="inv-meta-table">
                <tr>
                  <td class="k">{{ $t('fSale.invoiceNo') }}</td>
                  <td class="v">{{ invoiceNumber }}</td>
                </tr>
                <tr>
                  <td class="k">{{ $t('fSale.orderUp') }}</td>
                  <td class="v">#{{ order.order }}</td>
                </tr>
                <tr>
                  <td class="k">{{ $t('fSale.invoiceDate') }}</td>
                  <td class="v">{{ invoiceDate }}</td>
                </tr>
                <tr>
                  <td class="k">{{ $t('setting.od') }}</td>
                  <td class="v">{{ order.created }}</td>
                </tr>
                <!--
                  A business customer's accounts department matches this
                  invoice to its own purchase order by this number. Without it
                  the invoice cannot be paid, which is the whole reason the
                  account was asked for one at signup.
                -->
                <tr v-if="order.purchase_order_number" class="inv-po">
                  <td class="k">{{ $t('fSale.poNo') }}</td>
                  <td class="v">{{ order.purchase_order_number }}</td>
                </tr>
              </table>
            </div>
          </div>

          <!-- ===================== Bill to / Ship to ==================== -->
          <div class="inv-parties">
            <div class="inv-party">
              <div class="inv-party-head">{{ $t('fSale.billTo') }}</div>
              <div class="inv-party-body">
                <b v-if="customer.company_name">{{ customer.company_name }}</b>
                <span v-if="customer.name" class="inv-line">{{ customer.name }}</span>
                <span v-for="(line, i) in billingLines" :key="`b-${i}`" class="inv-line">{{ line }}</span>
                <span v-if="customer.email" class="inv-line">{{ customer.email }}</span>
                <span v-if="customer.phone" class="inv-line">{{ customer.phone }}</span>

                <!-- Only the fields that apply to this order's account type. -->
                <template v-if="isBusinessOrder">
                  <span v-if="customer.vat_number" class="inv-line inv-strong">
                    {{ $t('fSale.vatNo') }}: {{ customer.vat_number }}
                  </span>
                  <span v-if="customer.cro_number" class="inv-line">
                    {{ $t('fSale.croNo') }}: {{ customer.cro_number }}
                  </span>
                </template>
              </div>
            </div>

            <div class="inv-party">
              <div class="inv-party-head">
                {{ isCollection ? $t('fSale.collectionAt') : $t('fSale.shipTo') }}
              </div>
              <div class="inv-party-body">
                <template v-if="isCollection">
                  <b>{{ $t('fSale.collection') }}</b>
                  <span v-for="(line, i) in pickupLines" :key="`p-${i}`" class="inv-line">{{ line }}</span>
                </template>

                <template v-else>
                  <b>{{ shippingName }}</b>
                  <span v-for="(line, i) in shippingLines" :key="`s-${i}`" class="inv-line">{{ line }}</span>
                  <span v-if="shippingPhone" class="inv-line">{{ shippingPhone }}</span>
                </template>
              </div>
            </div>
          </div>

          <!-- ========================== Items ========================== -->
          <table class="inv-items">
            <thead>
              <tr>
                <th class="w-item">{{ $t('fSale.item') }}</th>
                <th class="num">{{ $t('fSale.qty') }}</th>
                <th class="num">{{ $t('fSale.origUnit') }}</th>
                <th class="num">{{ $t('fSale.discount') }}</th>
                <th class="num">{{ $t('fSale.unitPrice') }}</th>
                <th class="num">{{ $t('fSale.tax') }}</th>
                <th class="num">{{ $t('fSale.lineTotal') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(value, index) in orderedProducts" :key="index">
                <td class="w-item">
                  <span class="inv-item-name">{{ itemTitle(value) }}</span>
                  <span v-if="itemSku(value)" class="inv-item-sub">
                    {{ $t('fSale.sku') }}: {{ itemSku(value) }}
                  </span>
                  <span v-if="itemVariant(value)" class="inv-item-sub">{{ itemVariant(value) }}</span>
                </td>
                <td class="num">{{ value.quantity }}</td>
                <td class="num">{{ priceFormatting(value.actual_price) }}</td>
                <td class="num">
                  <template v-if="Number(value.line_discount) > 0">- {{ priceFormatting(value.line_discount) }}</template>
                  <template v-else>&mdash;</template>
                </td>
                <td class="num">{{ priceFormatting(value.price) }}</td>
                <td class="num">
                  <template v-if="Number(value.tax_total) > 0">{{ priceFormatting(value.tax_total) }}</template>
                  <template v-else>&mdash;</template>
                </td>
                <td class="num inv-strong">{{ priceFormatting(value.line_total) }}</td>
              </tr>
            </tbody>
          </table>

          <!--
            Totals, payment and footer are kept together and out of the item
            table's way: html2pdf paginates by height, so a block that must not
            be split across a page break says so.
          -->
          <div class="inv-close">
            <div class="inv-close-grid">

              <!-- ==================== Payment ==================== -->
              <div class="inv-payment">
                <div class="inv-party-head">{{ $t('fSale.pInformation') }}</div>
                <table class="inv-kv">
                  <tr>
                    <td class="k">{{ $t('fSale.pMethod') }}</td>
                    <td class="v">{{ paymentTypes[order.order_method] || $t('prod.na') }}</td>
                  </tr>
                  <tr>
                    <td class="k">{{ $t('fSale.pStatus') }}</td>
                    <td class="v">{{ paymentDone ? $t('fSale.paid') : $t('fSale.unpaid') }}</td>
                  </tr>
                  <tr>
                    <td class="k">{{ $t('fSale.pAmount') }}</td>
                    <td class="v">{{ priceFormatting(totalPrice) }}</td>
                  </tr>
                  <!-- Only when one exists: a cash order has no gateway
                       reference and must not appear to have one. -->
                  <tr v-if="order.trans_id">
                    <td class="k">{{ $t('title.ti') }}</td>
                    <td class="v">{{ order.trans_id }}</td>
                  </tr>
                  <tr v-if="order.paid_on">
                    <td class="k">{{ $t('fSale.pDate') }}</td>
                    <td class="v">{{ order.paid_on }}</td>
                  </tr>
                </table>

                <p v-if="isCashOnDelivery && !paymentDone" class="inv-note">
                  {{ isCollection ? $t('fSale.payOnCollection') : $t('fSale.payOnDelivery') }}
                </p>
              </div>

              <!-- ==================== Totals ==================== -->
              <div class="inv-totals">
                <table class="inv-kv">
                  <tr>
                    <td class="k">{{ $t('fSale.sTotal') }}</td>
                    <td class="v">{{ priceFormatting(subtotalPrice) }}</td>
                  </tr>
                  <tr v-if="productDiscount">
                    <td class="k">{{ $t('fSale.pDiscount') }}</td>
                    <td class="v">- {{ priceFormatting(productDiscount) }}</td>
                  </tr>
                  <tr v-if="bundleOffer">
                    <td class="k">{{ $t('fSale.bOffer') }}</td>
                    <td class="v">- {{ priceFormatting(bundleOffer) }}</td>
                  </tr>
                  <tr v-if="voucherPrice">
                    <td class="k">{{ $t('fSale.voucher') }}</td>
                    <td class="v">- {{ priceFormatting(voucherPrice) }}</td>
                  </tr>
                  <tr v-if="flashDiscount">
                    <td class="k">{{ $t('fSale.flashDiscount') }}</td>
                    <td class="v">- {{ priceFormatting(flashDiscount) }}</td>
                  </tr>
                  <!--
                    The Essentials Bundle saving. Not the same thing as
                    "Bundle offer" above, which is the value of the units a
                    buy-X-get-Y-free deal gave away.
                  -->
                  <tr v-if="bundleDiscount">
                    <td class="k">{{ $t('fSale.bundleDiscount') }}</td>
                    <td class="v">- {{ priceFormatting(bundleDiscount) }}</td>
                  </tr>
                  <tr v-if="loyaltyDiscount">
                    <td class="k">{{ loyaltyLabel }}</td>
                    <td class="v">- {{ priceFormatting(loyaltyDiscount) }}</td>
                  </tr>
                  <tr v-if="pointsDiscount">
                    <td class="k">{{ $t('fSale.points') }}</td>
                    <td class="v">- {{ priceFormatting(pointsDiscount) }}</td>
                  </tr>
                  <!--
                    The VAT statement. Net, then VAT, then the total,
                    so the three read as the document they belong to.
                  -->
                  <tr class="inv-net">
                    <td class="k">{{ $t('fSale.netExVat') }}</td>
                    <td class="v">{{ priceFormatting(netExVat) }}</td>
                  </tr>
                  <tr>
                    <td class="k">
                      {{ $t('fSale.vat') }}<template v-if="vatRateLabel"> ({{ vatRateLabel }})</template>
                    </td>
                    <td class="v">{{ priceFormatting(vatAmount) }}</td>
                  </tr>
                  <tr>
                    <td class="k">{{ $t('fSale.sCost') }}</td>
                    <td class="v">
                      <template v-if="isFreeShipping">{{ $t('ship.fre') }}</template>
                      <template v-else>{{ priceFormatting(shippingPrice) }}</template>
                    </td>
                  </tr>
                  <tr class="inv-grand">
                    <td class="k">{{ $t('fSale.totalIncVat') }}</td>
                    <td class="v">{{ priceFormatting(totalPrice) }}</td>
                  </tr>
                  <!-- A split payment: how the total was paid. -->
                  <template v-if="walletPaid > 0">
                    <tr>
                      <td class="k">Paid from wallet</td>
                      <td class="v">- {{ priceFormatting(walletPaid) }}</td>
                    </tr>
                    <tr>
                      <td class="k">Paid by order method</td>
                      <td class="v">{{ priceFormatting(amountDue) }}</td>
                    </tr>
                  </template>
                </table>
              </div>
            </div>

            <!--
              VAT SUMMARY.

              A VAT invoice has to state the net and the tax against each rate
              it charged, not just one total. Rows come from the order's own
              lines - only rates the order actually used appear, so a
              single-rate invoice gets one row and a mixed basket gets one per
              rate.
            -->
            <div class="inv-vat" v-if="vatRates.length">
              <div class="inv-party-head">{{ $t('fSale.vatSummary') }}</div>
              <table class="inv-vat-table">
                <thead>
                  <tr>
                    <th>{{ $t('fSale.vatRate') }}</th>
                    <th class="num">{{ $t('fSale.netAmount') }}</th>
                    <th class="num">{{ $t('fSale.vatAmount') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(row, i) in vatRates" :key="`vat-${i}`">
                    <td>{{ formatRate(row.rate) }}</td>
                    <td class="num">{{ priceFormatting(row.net) }}</td>
                    <td class="num">{{ priceFormatting(row.vat) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <!-- ==================== Footer ==================== -->
            <div class="inv-foot">
              <div class="inv-foot-main">
                <b>{{ $t('fSale.thankYou') }}</b>
                <span>{{ $t('fSale.quoteOrder') }}</span>
              </div>
              <div class="inv-foot-support">
                <span v-if="companyPhone">{{ $t('fSale.support') }}: {{ companyPhone }}</span>
                <span v-if="companyEmail">{{ companyEmail }}</span>
              </div>
            </div>
          </div>
        </div>
      </vue-html2pdf>
    </client-only>

    <!--
      The action lives in the page header now, where an admin looks for it.
      This button is only rendered when the component is used somewhere that
      has no header of its own (the bulk-print list).
    -->
    <div v-if="!hideButton" class="dply-felx j-right mt-20 mt-sm-15">
      <button class="plr-20 dply-felx outline-btn" @click.prevent="generatingPdf">
        <i class="icon print-icon mr-10" />
        {{ $t('setting.pi') }}
      </button>
    </div>
  </div>
</template>

<script>
  import VueHtml2pdf from 'vue-html2pdf'
  import {mapGetters, mapActions} from 'vuex'
  import util from "~/mixin/util"

  /**
   * The order as a printable invoice.
   *
   * Every figure comes from the order's own persisted values, decorated
   * server-side by OrderSummary. There is deliberately no arithmetic in this
   * component beyond formatting: an invoice is a historical document, and a
   * second opinion about a price the order already holds is exactly how the
   * document and the order come to disagree.
   */
  export default {
    name: 'Invoice',
    data() {
      return {
      }
    },
    props: {
      order: {
        type: Object
      },
      /*
       * The order detail screen drives this component from its own header
       * button, so it hides the one below the layout. The bulk print on the
       * order list renders the component on its own and keeps it.
       */
      hideButton: {
        type: Boolean,
        default: false
      },
    },
    mixins: [util],
    directives: {},
    components: {VueHtml2pdf},
    watch: {},
    computed: {
      /*
       * A4 at a scale that keeps the type crisp without producing a file too
       * large to email, and a margin so nothing sits against the paper edge.
       * `avoid-all` keeps a row or a card from being cut in half by a page
       * break; `.inv-close` additionally declares itself unbreakable, so the
       * totals can never be split from the table they close.
       */
      pdfOptions() {
        return {
          margin: [12, 10, 14, 10],
          filename: `Invoice-${this.order?.order}.pdf`,
          image: { type: 'jpeg', quality: 0.98 },
          html2canvas: { scale: 2, useCORS: true, letterRendering: true },
          jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
          pagebreak: { mode: ['avoid-all', 'css'] },
        }
      },

      /* ------------------------------------------------ the selling company */
      companyName() {
        return this.getDataFromObject(this.storeData, 'name')
          || this.siteSetting?.site_name
          || ''
      },
      companyAddressLines() {
        const s = this.setting

        if (!s) {
          return []
        }

        return [
          [s.address_1, s.address_2].filter(Boolean).join(', '),
          [s.city, s.state].filter(Boolean).join(', '),
          s.zip,
          s.country
        ].filter(Boolean)
      },
      companyPhone() {
        return this.setting?.phone || ''
      },
      companyEmail() {
        return this.setting?.email || ''
      },
      companyWebsite() {
        return this.siteSetting?.site_url || ''
      },

      /* ------------------------------------------------------- identifiers */
      /*
       * This shop has no separate invoice sequence, so the invoice is
       * identified by the order it bills, prefixed so the two cannot be
       * mistaken for one another on the page. Both are printed.
       */
      invoiceNumber() {
        return this.order?.order ? `INV-${this.order.order}` : ''
      },
      /*
       * The invoice documents the order, so it carries the order's date rather
       * than today's: reprinting a six-month-old order must not produce a
       * document dated today.
       */
      invoiceDate() {
        return this.order?.created || ''
      },

      /* -------------------------------------------------------- the buyer */
      /*
       * The customer as the server resolved them: the account first, then the
       * contact captured at checkout, then the address. This used to read
       * `order.user.business_profile`, which the admin endpoint no longer
       * sends - and which was empty on collection orders long before that.
       */
      customer() {
        return this.order?.customer || {}
      },
      isBusinessOrder() {
        const type = this.customer.account_type

        return type === 'business' || type === 'trade'
      },
      /* The registered address a business is invoiced at, which is not
         necessarily where the goods went. */
      billingLines() {
        const stored = this.customer.billing_address

        if (stored) {
          return String(stored).split(',').map(part => part.trim()).filter(Boolean)
        }

        return this.shippingLines
      },

      /* ---------------------------------------------------- where it goes */
      /* Every line on an order shares one fulfilment type; 2 is collection. */
      isCollection() {
        return parseInt(this.order?.ordered_products?.[0]?.shipping_type)
          === this.shippingTypeIn.pickup
      },
      shippingName() {
        return this.getDataFromObject(this.order, 'address.name', '')
          || this.customer.name
          || ''
      },
      shippingPhone() {
        return this.getDataFromObject(this.order, 'address.phone', '') || ''
      },
      shippingLines() {
        const a = this.order?.address

        if (!a) {
          return []
        }

        return [
          [a.address_1, a.address_2].filter(Boolean).join(', '),
          [a.city, this.stateName(a)].filter(Boolean).join(', '),
          a.zip,
          this.countryName(a)
        ].filter(Boolean)
      },
      pickupLines() {
        const place = this.order?.ordered_products?.[0]?.shipping_place

        if (!place) {
          return []
        }

        const lines = [
          [place.pickup_address_line_1, place.pickup_address_line_2].filter(Boolean).join(', '),
          [place.pickup_city, place.pickup_state].filter(Boolean).join(', '),
          place.pickup_zip,
          place.pickup_country
        ].filter(Boolean)

        if (place.pickup_phone) {
          lines.push(`${this.$t('profile.phone')}: ${place.pickup_phone}`)
        }

        return lines
      },

      /* ------------------------------------------------------------ items */
      orderedProducts() {
        return this.order?.ordered_products || []
      },

      /* ----------------------------------------------------------- money */
      isFreeShipping() {
        return !(parseFloat(this.shippingPrice) > 0)
      },
      paymentDone() {
        return parseInt(this.order?.payment_done) === 1
      },
      isCashOnDelivery() {
        return parseInt(this.order?.order_method) === this.orderMethodsIn.CASH_ON_DELIVERY
      },
      currencyPosition() {
        return this.setting?.currency_position
      },
      totalPrice(){
        return this.order?.calculated?.total_price
      },
      voucherPrice(){
        return this.order?.calculated?.voucher_price
      },
      flashDiscount(){
        return Number(this.order?.calculated?.flash_discount || 0)
      },
      bundleDiscount(){
        return Number(this.order?.calculated?.bundle_discount || 0)
      },
      loyaltyDiscount(){
        return Number(this.order?.calculated?.loyalty_discount || 0)
      },
      loyaltyLabel(){
        return this.order?.calculated?.loyalty_label || 'Loyalty Discount'
      },
      pointsDiscount(){
        return Number(this.order?.calculated?.points_discount || 0)
      },
      walletPaid(){
        return Number(this.order?.calculated?.wallet_paid || 0)
      },
      amountDue(){
        return Number(this.order?.calculated?.amount_due || 0)
      },
      bundleOffer(){
        return Number(this.order?.calculated?.bundle_offer || 0)
      },
      shippingPrice(){
        return this.order?.calculated?.shipping_price
      },
      taxPrice(){
        return this.order?.calculated?.tax
      },
      /*
       * The goods at list price when the order carried a product discount, and
       * the charged figure when it did not - so an order with nothing to
       * discount reads exactly as it always has.
       */
      subtotalPrice(){
        return this.productDiscount > 0
          ? this.order?.calculated?.original_subtotal
          : this.order?.calculated?.subtotal
      },
      productDiscount(){
        return Number(this.order?.calculated?.discount || 0)
      },
      /*
       * The rate this order's VAT was actually charged at, derived by the
       * server from the figures the order froze - never from today's tax
       * rules, which would restate an old invoice at a rate never applied to
       * it. Suppressed when the lines disagree, since one percentage would
       * then be a fiction.
       */
      /*
       * Net and VAT for the order, split server-side from what it recorded.
       * The invoice never divides by a rate itself: an old document must state
       * the tax that was charged, not what today's rules would charge.
       *
       * The fallbacks cover an order summarised before these keys existed —
       * the goods as charged, and whatever tax was added.
       */
      netExVat() {
        const stated = this.order?.calculations?.ex_vat

        return Number(stated != null ? stated : (this.order?.calculated?.subtotal || 0))
      },
      vatAmount() {
        const stated = this.order?.calculations?.vat

        return Number(stated != null ? stated : (this.order?.calculated?.tax || 0))
      },
      /* One row per rate the order actually charged. */
      vatRates() {
        return this.order?.calculations?.vat_breakdown || []
      },
      vatRateLabel() {
        const rates = this.orderedProducts
          .map(item => Number(item.tax_rate || 0))
          .filter(rate => rate > 0)

        if (!rates.length) {
          return null
        }

        const first = rates[0]

        if (rates.some(rate => Math.abs(rate - first) > 0.01)) {
          return null
        }

        return `${String(first.toFixed(2)).replace(/\.?0+$/, '')}%`
      },
      currencyIcon() {
        return this.setting?.currency_icon || '$'
      },
      ...mapGetters('admin', ['isVendor']),
      ...mapGetters('resource', ['countryList']),
      ...mapGetters('site-setting', ['siteSetting']),
      ...mapGetters('setting', ['setting', 'base64SiteLogo', 'storeData']),
    },
    methods: {
      /* 23.00 reads as 23%; 13.50 keeps its half. */
      formatRate(rate) {
        return String(Number(rate).toFixed(2)).replace(/\.?0+$/, '') + '%'
      },
      stateName(address) {
        const country = this.countryList?.[address?.country]

        return country?.states?.[address?.state]?.name || address?.state || ''
      },
      countryName(address) {
        return this.countryList?.[address?.country]?.name || address?.country || ''
      },
      itemTitle(item) {
        return item?.product?.title || ''
      },
      itemSku(item) {
        return item?.updated_inventory?.sku || ''
      },
      itemVariant(item) {
        return (item?.updated_inventory?.inventory_attributes || [])
          .map(row => row?.attribute_value?.title)
          .filter(Boolean)
          .join(' / ')
      },
      generatingPdf() {
        this.$nextTick(() => {
          this.$refs.html2Pdf.generatePdf()
        })
      },
      ...mapActions('resource', ['setCountryList']),
      ...mapActions('common', ['getById']),
      ...mapActions('setting', ['setConvertImage']),
    },
    beforeCreate() {

    },
    destroyed() {

    },
    async mounted() {
      if (!this.countryList) {
        this.loading = true

        const data = await this.getById({
          params: null,
          id: 'countries',
          api: 'resource'
        })

        this.setCountryList(data)
        this.loading = false
      }

      if (!this.base64SiteLogo) {
        await this.setConvertImage(this.siteSetting?.email_logo)
      }
    }
  }
</script>

<style>
/*
 * Invoice styles.
 *
 * Deliberately NOT scoped and deliberately plain CSS with explicit colours:
 * html2canvas rasterises this subtree on its own, outside the admin theme, so
 * a CSS variable or an inherited admin class resolves to nothing and the PDF
 * comes out unstyled. Everything the document needs is stated here.
 *
 * Sized in points against A4 at the margins declared in pdfOptions, so the
 * content cannot run past the paper edge.
 */

.inv {
  width: 190mm;
  box-sizing: border-box;
  padding: 0;
  background: #ffffff;
  color: #232159;
  font-family: Arial, Helvetica, sans-serif;
  font-size: 10.5px;
  line-height: 1.55;
}

.inv table { border-collapse: collapse; width: 100%; }
.inv td, .inv th { vertical-align: top; }

/* ------------------------------------------------------------- header */
.inv-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 20px;
  padding-bottom: 14px;
  border-bottom: 2px solid #33319a;
}

.inv-brand { max-width: 95mm; }

.inv-logo {
  height: 34px;
  width: auto;
  margin-bottom: 8px;
  display: block;
}

.inv-company {
  font-size: 14px;
  font-weight: bold;
  color: #232159;
  margin-bottom: 4px;
}

.inv-company-lines span {
  display: block;
  color: #5b6172;
  font-size: 10px;
  word-break: break-word;
}

.inv-meta { min-width: 72mm; text-align: right; }

.inv-title {
  font-size: 26px;
  font-weight: bold;
  letter-spacing: 0.06em;
  color: #33319a;
  margin-bottom: 8px;
}

.inv-meta-table td { padding: 2px 0; font-size: 10.5px; }
.inv-meta-table .k { text-align: left; color: #5b6172; padding-right: 12px; }
.inv-meta-table .v { text-align: right; font-weight: bold; word-break: break-word; }

/* The number the customer's accounts department searches for. */
.inv-po .v { color: #33319a; }

/* -------------------------------------------------------- bill / ship */
.inv-parties {
  display: flex;
  gap: 10px;
  margin-top: 14px;
}

.inv-party {
  flex: 1 1 50%;
  min-width: 0;
  border: 1px solid #e3e4ec;
  border-radius: 4px;
  overflow: hidden;
}

.inv-party-head {
  padding: 6px 10px;
  background: #f4f5fa;
  border-bottom: 1px solid #e3e4ec;
  font-size: 9.5px;
  font-weight: bold;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: #5b6172;
}

.inv-party-body { padding: 10px; }
.inv-party-body b { display: block; color: #232159; margin-bottom: 2px; }

/* Long company names and addresses wrap rather than run off the page. */
.inv-line { display: block; color: #3c4152; word-break: break-word; }
.inv-strong { font-weight: bold; color: #232159; }

/* -------------------------------------------------------------- items */
.inv-items { margin-top: 16px; }

.inv-items thead th {
  padding: 7px 8px;
  background: #33319a;
  color: #ffffff;
  font-size: 9.5px;
  font-weight: bold;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  text-align: left;
}

.inv-items tbody td {
  padding: 8px;
  border-bottom: 1px solid #e9eaf1;
  font-size: 10.5px;
}

/* A row is never split across a page break. */
.inv-items tbody tr { page-break-inside: avoid; break-inside: avoid; }

.inv-items .num { text-align: right; white-space: nowrap; }
.inv-items th.num { text-align: right; }
.inv-items .w-item { width: 42%; }

.inv-item-name { display: block; font-weight: bold; word-break: break-word; }
.inv-item-sub { display: block; color: #5b6172; font-size: 9.5px; word-break: break-word; }

/* --------------------------------------------- totals, payment, footer */
/*
 * One unbreakable block, so the totals can never be paginated away from the
 * table they close or land on a page of their own.
 */
.inv-close { page-break-inside: avoid; break-inside: avoid; margin-top: 16px; }

.inv-close-grid {
  display: flex;
  gap: 14px;
  align-items: flex-start;
}

.inv-payment {
  flex: 1 1 50%;
  min-width: 0;
  border: 1px solid #e3e4ec;
  border-radius: 4px;
  overflow: hidden;
}

.inv-payment .inv-kv { padding: 0; }
.inv-payment .inv-kv td { padding: 4px 10px; font-size: 10.5px; }
.inv-payment .inv-kv tr:first-child td { padding-top: 9px; }
.inv-payment .inv-kv tr:last-child td { padding-bottom: 9px; }

.inv-kv .k { color: #5b6172; }
.inv-kv .v { text-align: right; font-weight: bold; word-break: break-word; }

.inv-note {
  margin: 0;
  padding: 8px 10px;
  background: #f4f5fa;
  border-top: 1px solid #e3e4ec;
  font-size: 9.5px;
  color: #33319a;
}

.inv-totals { flex: 1 1 50%; min-width: 0; }
.inv-totals .inv-kv td { padding: 5px 0; font-size: 11px; }
.inv-totals .inv-kv .k { padding-right: 14px; }

/* The one figure the reader is looking for. */
.inv-grand td {
  padding-top: 9px !important;
  border-top: 2px solid #33319a;
  font-size: 14px !important;
  font-weight: bold;
  color: #33319a;
}

.inv-foot {
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
  gap: 16px;
  margin-top: 18px;
  padding-top: 10px;
  border-top: 1px solid #e3e4ec;
  font-size: 9.5px;
  color: #5b6172;
}

.inv-foot-main b { display: block; color: #232159; font-size: 11px; margin-bottom: 2px; }
.inv-foot-main span { display: block; }
.inv-foot-support { text-align: right; }
.inv-foot-support span { display: block; word-break: break-word; }

/* ------------------------------------------------------- VAT summary */
/* The net and the tax against each rate charged, which a VAT invoice has to
   state separately from the totals beside it. */
.inv-vat {
  margin-top: 14px;
  border: 1px solid #e3e4ec;
  border-radius: 4px;
  overflow: hidden;
  max-width: 92mm;
}

.inv-vat-table td,
.inv-vat-table th {
  padding: 5px 10px;
  font-size: 10px;
  border-bottom: 1px solid #f0f1f6;
}

.inv-vat-table thead th {
  background: #f9f9fc;
  color: #5b6172;
  font-weight: bold;
  text-align: left;
  letter-spacing: 0.03em;
  text-transform: uppercase;
}

.inv-vat-table .num { text-align: right; white-space: nowrap; }
.inv-vat-table tbody tr:last-child td { border-bottom: none; }

/* The net line the VAT is charged on, set apart from the discount rows. */
.inv-net td {
  padding-top: 8px !important;
  border-top: 1px solid #e3e4ec;
}
</style>
