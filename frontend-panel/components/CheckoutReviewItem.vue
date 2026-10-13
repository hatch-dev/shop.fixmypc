<template>
  <!--
    One basket line on the Payment step: what is being bought, read-only.
    Quantities and removals belong to the basket; this only has to let the
    shopper recognise each item before paying for it. Figures are the
    server's (line_total and the line's own VAT split), the same ones the
    cart card shows, so the two pages cannot disagree.
  -->
  <div class="review-item">
    <div class="review-item-image">
      <lazy-image
        :data-src="getThumbImageURL(productImage)"
        :title="title"
        :alt="title"
      />
      <span class="review-item-qty" :aria-label="`Quantity ${quantity}`">{{ quantity }}</span>
    </div>

    <div class="review-item-body">
      <div class="review-item-title">{{ title }}</div>
      <div v-if="variantLabel" class="review-item-variant">{{ variantLabel }}</div>
      <div class="review-item-meta">
        <span v-if="cartVatBasis" class="review-item-basis" :class="cartVatBasis.inclusive ? 'is-inclusive' : 'is-exclusive'">
          {{ cartVatBasis.short }}
        </span>
        <span class="review-item-unit">
          <price-format :price="unitPrice" :decimals="2" /> &times; {{ quantity }}
        </span>
      </div>
    </div>

    <div class="review-item-total">
      <price-format class="review-item-amount" :price="lineTotal" :decimals="2" />
      <span v-if="counterpart" class="review-item-counterpart">
        <price-format :price="counterpart.amount" :decimals="2" /> {{ counterpart.label }}
      </span>
    </div>
  </div>
</template>

<script>
import util from '~/mixin/util'
import productPriceHelper from '~/mixin/productPriceHelper'
import productImageHelper from '~/mixin/productImageHelper'

export default {
  name: 'CheckoutReviewItem',
  mixins: [util, productPriceHelper, productImageHelper],
  props: {
    cart: {
      type: Object,
      required: true
    }
  },
  computed: {
    product() {
      return this.cart?.flash_product
    },
    inventoryAttributes() {
      return this.cart?.updated_inventory?.inventory_attributes
    },
    title() {
      return this.product?.title || ''
    },
    quantity() {
      return parseInt(this.cart?.quantity || 0)
    },
    // "128GB • Blue • Very Good", as on the cart card.
    variantLabel() {
      return (this.inventoryAttributes || [])
        .map(i => i?.attribute_value?.title)
        .filter(Boolean)
        .join(' • ')
    },
    unitPrice() {
      const unit = parseFloat(this.cart?.unit_price ?? this.cart?.upsell_price)
      return Number.isFinite(unit) ? unit : 0
    },
    lineTotal() {
      const total = parseFloat(this.cart?.line_total)
      return Number.isFinite(total) ? total : this.unitPrice * this.quantity
    },
    // The same line on the other VAT basis, as the cart card shows it.
    counterpart() {
      if (!this.cartVatBasis) return null

      const amount = parseFloat(this.cartVatBasis.inclusive ? this.cart?.line_ex_vat : this.cart?.line_inc_vat)

      if (!Number.isFinite(amount) || !(amount > 0)) return null

      return { amount, label: this.cartVatBasis.inclusive ? 'Ex-VAT' : 'Inc-VAT' }
    }
  }
}
</script>

<style scoped>
.review-item {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 14px 0;
}

.review-item + .review-item {
  border-top: 1px solid #EEF0F5;
}

.review-item-image {
  position: relative;
  flex: 0 0 64px;
  width: 64px;
  height: 64px;
  border: 1px solid #E3E3EF;
  border-radius: 10px;
  background: #F7F8FC;
}

.review-item-image >>> img {
  width: 100%;
  height: 100%;
  object-fit: contain;
  border-radius: 10px;
}

.review-item-qty {
  position: absolute;
  top: -7px;
  right: -7px;
  min-width: 22px;
  height: 22px;
  padding: 0 6px;
  border-radius: 11px;
  background: #33319A;
  color: #fff;
  font-size: 12px;
  font-weight: 700;
  line-height: 22px;
  text-align: center;
}

.review-item-body {
  flex: 1 1 auto;
  min-width: 0;
}

.review-item-title {
  font-size: 15px;
  font-weight: 600;
  color: #130E2B;
  overflow-wrap: anywhere;
}

.review-item-variant {
  margin-top: 2px;
  font-size: 13px;
  color: #6B7C93;
}

.review-item-meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  margin-top: 6px;
  font-size: 13px;
  color: #6B7C93;
}

.review-item-basis {
  padding: 1px 8px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 600;
}

.review-item-basis.is-exclusive {
  background: #E8EEFF;
  color: #2F3FB3;
}

.review-item-basis.is-inclusive {
  background: #E3F7EA;
  color: #127A3C;
}

.review-item-total {
  flex: 0 0 auto;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 2px;
  text-align: right;
  font-variant-numeric: tabular-nums;
}

.review-item-amount {
  font-size: 16px;
  font-weight: 700;
  color: #130E2B;
}

.review-item-counterpart {
  font-size: 12.5px;
  font-weight: 600;
  color: #6B7C93;
  white-space: nowrap;
}

@media (max-width: 576px) {
  .review-item {
    align-items: flex-start;
    gap: 12px;
  }

  .review-item-image {
    flex-basis: 52px;
    width: 52px;
    height: 52px;
  }

  .review-item-title {
    font-size: 14px;
  }

  .review-item-amount {
    font-size: 15px;
  }
}
</style>
