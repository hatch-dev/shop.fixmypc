<template>
  <tr class="item-row">
    <!--
      Everything that identifies the product, in one cell: the picture, the
      name, the SKU, the variant and how it is being fulfilled. These were five
      separate columns, which is what made the table wider than the page and
      forced the line total off the right-hand edge.
    -->
    <td class="col-product">
      <div class="item-media">
        <router-link class="item-thumb" :to="`/products/${product.id}`">
          <img
            :src="thumbSrc"
            :alt="product.title"
            @error="onImageError"
          >
        </router-link>

        <div class="item-info">
          <router-link class="item-name" :to="`/products/${product.id}`">
            {{ product.title }}
          </router-link>

          <span v-if="sku" class="item-meta">{{ $t('fSale.sku') }}: {{ sku }}</span>

          <span v-if="variantLabel" class="item-meta">{{ variantLabel }}</span>

          <span class="item-tag">{{ shippingType[orderedProduct.shipping_type] }}</span>
        </div>
      </div>
    </td>

    <td class="num col-qty">{{ orderedProduct.quantity }}</td>

    <!--
      One unit, three facts: what was charged, what it was listed at, and what
      came off. The row used to show `selling` alone, so on a business or trade
      order the quantity discount was invisible to whoever was answering the
      phone about it - €689 with no sign it had ever been €699.

      Every figure is frozen on the order (OrderSummary::decorate); nothing here
      consults the product's price today.
    -->
    <td class="num col-unit">
      <span class="unit-now">{{ priceFormatting(finalPrice) }}</span>
      <!--
        Which side of VAT the charged price sits on, said in the row
        rather than left for the reader to infer. This catalogue quotes
        some products net and some gross, so '€689' alone is ambiguous.
      -->
      <span class="unit-before">{{ vatSideLabel }}</span>
      <span v-if="hasDiscount" class="unit-before">
        <s>{{ priceFormatting(actualPrice) }}</s>
        <em class="unit-off">- {{ priceFormatting(lineDiscount) }}</em>
      </span>
      <span v-if="bundleOfferValue > 0" class="unit-before">
        {{ $t('fSale.bOffer') }}: {{ priceFormatting(bundleOfferValue) }}
      </span>
    </td>

    <td class="num col-tax">
      <template v-if="taxTotal > 0">
        {{ priceFormatting(taxTotal) }}
        <span v-if="taxRate" class="unit-before">{{ taxRate }}</span>
      </template>
      <span v-else class="item-none">&mdash;</span>
    </td>

    <!--
      The line total on the side of VAT this line was actually charged on —
      the same side the unit price above is labelled with.

      It used to lead with the net figure for every line and hang "Inc-VAT"
      underneath. On the products whose prices already contain their VAT that
      put a number the customer was never charged in the column the reader
      takes as the line total, under a header that just says "Line total".
      The gross figure now follows only where the charged price is net, which
      is the one case the net figure does not settle.
    -->
    <td class="num col-total">
      <span class="unit-now">{{ priceFormatting(lineTotalPrimary) }}</span>
      <span v-if="lineVatCounterpart !== null" class="unit-before">
        {{ $t('fSale.incVat') }} {{ priceFormatting(lineVatCounterpart) }}
      </span>
    </td>
  </tr>
</template>

<script>
  import {mapGetters} from "vuex"
  import util from "../../mixin/util";
  import productImageHelper from "../../mixin/productImageHelper";

  export default {
    name: 'OrderedProduct',
    data() {
      return {
      }
    },
    props: {
      orderedProduct: {
        type: Object
      },
    },
    watch:{

    },
    components: {},
    mixins: [util, productImageHelper],
    computed: {
      inventoryAttributes() {
        return this.orderedProduct.updated_inventory?.inventory_attributes
      },
      /*
       * "128GB / Black / Very Good" on one line.
       *
       * The values were listed with their attribute names, each on its own
       * line, which made the product cell three lines taller than the row
       * needed and pushed the table wider. The names add nothing here: nobody
       * reads "Colour: Black" and learns something they would not have known
       * from "Black".
       */
      variantLabel() {
        return (this.inventoryAttributes || [])
          .map(row => row?.attribute_value?.title)
          .filter(Boolean)
          .join(' / ')
      },
      sku() {
        return this.orderedProduct?.updated_inventory?.sku
      },
      product() {
        // An order line always has a product; guarding keeps the row from
        // taking the whole page down while the fetch is in flight.
        return this.orderedProduct?.product || {}
      },
      /*
       * products.image carries two conventions: a bare file name from the
       * older uploader, and one already prefixed with "uploads/" from the
       * newer one. imgSrcUrl ends in /uploads/, so the prefixed form resolved
       * to /uploads/thumb-uploads/... and rendered as a broken image.
       */
      orderedImage() {
        return (this.productImage || '').replace(/^\/?uploads\//, '')
      },
      /*
       * A plain <img>, not the lazy loader.
       *
       * LazyImage sets `data-src` and leaves `src` empty until lozad's observer
       * fires. Inside a table that is rendered after the order arrives the
       * observer had nothing to react to, so the element sat at its declared
       * size showing the browser's empty-image box - the grey "350 x 350"
       * placeholder on this screen. A row thumbnail is 40px and above the fold;
       * there is nothing to defer.
       */
      thumbSrc() {
        return this.orderedImage
          ? this.getThumbImageURL(this.orderedImage)
          : this.getImageURL()
      },
      /* The list price the order froze; falls back to the charged price on an
         order written before that was stored, so no line can show a negative
         discount. */
      actualPrice() {
        const stored = Number(this.orderedProduct?.actual_price || 0)
        const charged = this.finalPrice

        return stored > charged ? stored : charged
      },
      finalPrice() {
        return Number(this.orderedProduct?.price != null
          ? this.orderedProduct.price
          : this.orderedProduct?.selling || 0)
      },
      lineDiscount() {
        return Number(this.orderedProduct?.line_discount || 0)
      },
      hasDiscount() {
        return this.lineDiscount > 0
      },
      /* Units a "buy X get Y free" bundle gave away, as money. */
      bundleOfferValue() {
        return Number(this.orderedProduct?.selling || 0)
          * Number(this.orderedProduct?.bundle_offer || 0)
      },
      /*
       * The line as ex-VAT and inc-VAT, split server-side from what the order
       * recorded. Falls back to the charged figure on an order placed before
       * the VAT treatment was stored, which shows the same number twice rather
       * than inventing a split.
       */
      lineExVat() {
        return Number(this.orderedProduct?.ex_vat != null
          ? this.orderedProduct.ex_vat
          : this.lineTotal)
      },
      lineIncVat() {
        return Number(this.orderedProduct?.inc_vat != null
          ? this.orderedProduct.inc_vat
          : this.lineTotal)
      },
      /*
       * Whether this line was charged gross. Only ever true on an order that
       * recorded its VAT treatment — an older one reports nothing, and is
       * treated as "not known to be inclusive" rather than assumed either way.
       */
      isVatInclusiveLine() {
        return !!(this.orderedProduct?.vat_known && this.orderedProduct?.vat_inclusive)
      },
      /* The line total as charged: gross on an inc-VAT line, net on an ex-VAT one. */
      lineTotalPrimary() {
        return this.isVatInclusiveLine ? this.lineIncVat : this.lineExVat
      },
      /*
       * The gross total, shown only where the line's own figure is net and so
       * does not say what was actually payable. Null on an inc-VAT line (the
       * primary figure is already the gross one) and on an order that never
       * recorded its treatment, where ex and inc are the same number and a
       * second copy would only imply a split that was never stored.
       */
      lineVatCounterpart() {
        if (!this.orderedProduct?.vat_known || this.isVatInclusiveLine) {
          return null
        }

        return this.lineIncVat
      },
      /*
       * Whether the charged unit price is net or gross. Unlabelled, a price in
       * this catalogue is ambiguous: some products are quoted ex-VAT with the
       * tax added at checkout, most are quoted inc-VAT.
       */
      vatSideLabel() {
        if (!this.orderedProduct?.vat_known) {
          return ''
        }

        return this.orderedProduct.vat_inclusive
          ? this.$t('fSale.incVat')
          : this.$t('fSale.exVat')
      },
      taxTotal() {
        return Number(this.orderedProduct?.tax_total || 0)
      },
      /* The rate this line was actually charged at, derived server-side from
         the figures the order froze. 23.00 reads as 23. */
      taxRate() {
        const rate = Number(this.orderedProduct?.tax_rate || 0)

        if (!(rate > 0)) {
          return null
        }

        return String(rate.toFixed(2)).replace(/\.?0+$/, '') + '%'
      },
      lineTotal() {
        if (this.orderedProduct?.line_total != null) {
          return Number(this.orderedProduct.line_total)
        }

        return this.finalPrice * Number(this.orderedProduct?.quantity || 0)
      },
      currencyPosition() {
        return this.setting?.currency_position
      },
      currencyIcon() {
        return this.setting?.currency_icon || '$'
      },
      ...mapGetters('setting', ['setting'])
    },
    methods: {
      /*
       * A product whose file is no longer on disk shows the store placeholder
       * rather than the browser's broken-image icon. Guarded against re-entry:
       * if the placeholder is itself missing the handler would otherwise retry
       * forever against the server.
       */
      onImageError(event) {
        const fallback = this.getImageURL()

        if (event.target.src !== fallback) {
          event.target.src = fallback
        }
      },
    },
    mounted() {

    }
  }
</script>

<style scoped>
/*
 * The product cell. Everything that identifies the line lives here, so it is
 * the only cell allowed to grow, and it wraps rather than pushing the table
 * wider than the page.
 */
.item-media {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  min-width: 0;
}

.item-thumb {
  flex-shrink: 0;
  width: 44px;
  height: 44px;
  border: 1px solid var(--border-color);
  border-radius: 6px;
  background: #fff;
  overflow: hidden;
  display: block;
}

.item-thumb img {
  width: 100%;
  height: 100%;
  object-fit: contain;
  display: block;
}

.item-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

/* A long product name wraps inside its cell instead of widening the column. */
.item-name {
  font-weight: 600;
  color: var(--text-strong);
  line-height: 1.35;
  overflow-wrap: anywhere;
}

.item-meta {
  font-size: 12px;
  color: var(--text-soft);
  overflow-wrap: anywhere;
}

/* Fulfilment, which used to be a column of its own. */
.item-tag {
  align-self: flex-start;
  margin-top: 3px;
  padding: 1px 8px;
  border-radius: 999px;
  background: var(--hover-color);
  font-size: 11px;
  color: var(--text-soft);
  white-space: nowrap;
}

/* ------------------------------------------------------------ price cells */
/*
 * One unit, stacked: what was charged, then what it was before and what came
 * off. Three columns' worth of information in the width of one.
 */
.unit-now {
  display: block;
  font-weight: 600;
  color: var(--text-strong);
}

.unit-before {
  display: block;
  margin-top: 1px;
  font-size: 11.5px;
  line-height: 1.5;
  color: var(--text-soft);
  white-space: nowrap;
}

.unit-off {
  margin-left: 6px;
  font-style: normal;
  font-weight: 600;
  color: #0f9b69;
}

.item-none {
  color: var(--text-soft);
}

.col-total {
  font-weight: 600;
  color: var(--text-strong);
}
</style>
