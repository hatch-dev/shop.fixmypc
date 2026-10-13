<template>
  <!--
    ACCESSORIES & UPGRADES.

    Better versions of the product already on screen. Choosing one REPLACES
    the customer's configuration -- it is a different radio button, not a
    second thing in the basket -- which is why every group renders as a radio
    list with the current variant ticked at the top rather than as a row of
    add buttons.

    The whole section is absent when there is nothing to offer. No empty
    heading, no "no upgrades available".
  -->
  <div v-if="hasGroups" class="product-upgrades">
    <div class="accessories-upgrades-card-header">
      <h6>{{ $t('upgrades.heading') }}</h6>
      <span
        class="badge"
        :class="anythingInStock ? 'bg-instock' : 'bg-outstock'"
      >● {{ anythingInStock ? $t('upgrades.inStock') : $t('upgrades.outOfStock') }}</span>
    </div>

    <div
      v-for="group in groups"
      :key="group.id || group.attribute_id"
      class="accessories-upgrades-cards"
    >
      <h5 class="accessories-upgrades-cards-service-title">
        <span class="a-u-s-icon">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M12 19V5M12 5l-6 6M12 5l6 6" stroke="#05B942" stroke-width="1.9"
              stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </span>
        {{ group.name }}
      </h5>

      <div class="accessories-upgrades-text">
        <!--
          The variant the customer already has, ticked and priced as
          "Included". It is the first option rather than a caption because the
          group is a choice between siblings, and a list whose current state is
          not one of the choices cannot be read.
        -->
        <div class="p_dt_checkbox-list upgrade-row is-current">
          <label class="accessories-upgrades-checkbox-span">
            <input
              type="radio"
              :name="`upgrade-${group.attribute_id}`"
              :checked="!pendingFor(group)"
              @change="revert(group)"
            >
            <span class="upgrade-label">{{ group.current.label }}</span>
          </label>

          <span class="accessories-upgrades-price-span upgrade-included">
            {{ $t('upgrades.included') }}
          </span>
        </div>

        <div
          v-for="option in group.options"
          :key="option.inventory_id"
          class="p_dt_checkbox-list upgrade-row"
          :class="{'is-unavailable': !option.available}"
        >
          <label class="accessories-upgrades-checkbox-span">
            <input
              type="radio"
              :name="`upgrade-${group.attribute_id}`"
              :disabled="!option.available || busy"
              :checked="pendingFor(group) === option.inventory_id"
              @change="choose(group, option)"
            >
            <span class="upgrade-label">
              {{ option.label }}
              <!-- Says WHY it cannot be chosen, rather than leaving a greyed
                   row with no explanation. -->
              <em v-if="!option.available" class="upgrade-note">
                {{ option.in_stock ? $t('upgrades.notEnough') : $t('upgrades.soldOut') }}
              </em>
              <em v-else-if="option.backorder && !option.in_stock" class="upgrade-note">
                {{ $t('upgrades.backorder') }}
              </em>
            </span>
          </label>

          <span class="accessories-upgrades-price-span">
            <strong>{{ differenceLabel(option) }}</strong>
          </span>
        </div>
      </div>

      <p v-if="error" class="upgrade-error">{{ error }}</p>
    </div>
  </div>
</template>

<script>
  /**
   * The upgrade section on the product page.
   *
   * Reads only. Choosing an option emits the target variant and the PAGE
   * switches to it, through exactly the same path a customer clicking the
   * variation picker takes -- so the images, the price, the SKU, the stock,
   * the quantity cap and the buy box all update through machinery that
   * already exists and is already right.
   *
   * That indirection is deliberate: an upgrade that set the price itself
   * would be a second pricing path on the same page, and the two would
   * disagree the first time either was touched.
   */
  export default {
    name: 'ProductUpgrades',

    props: {
      productId: {
        type: [Number, String],
        default: null
      },
      /* The variant the page is currently showing. */
      inventoryId: {
        type: [Number, String],
        default: null
      },
      /* What the buy box is set to; a band can change what an upgrade costs. */
      quantity: {
        type: Number,
        default: 1
      }
    },

    data() {
      return {
        groups: [],
        busy: false,
        error: '',
        /*
         * Discards the answer to a variant the customer has already moved on
         * from: the page can switch variants faster than this can answer, and
         * a slow earlier reply must not repaint the section with stale
         * options.
         */
        requestId: 0
      }
    },

    computed: {
      hasGroups() {
        return Array.isArray(this.groups) && this.groups.length > 0
      },

      /*
       * The header badge speaks for the section, not for one option: it is
       * green while anything here can still be bought.
       */
      anythingInStock() {
        return this.groups.some(group =>
          group.current.in_stock || group.options.some(option => option.available))
      },

      /*
       * Everything about the caller that changes what a variant costs. Watched
       * rather than read once, because a customer can sign in, or be approved
       * for trade, without the page reloading.
       */
      accountKey() {
        const user = this.$auth && this.$auth.user

        if (!user) return 'guest'

        return [user.id, user.account_type, user.trade_status].join(':')
      }
    },

    methods: {
      async load() {
        if (!this.productId || !this.inventoryId) {
          this.groups = []

          return
        }

        const requestId = ++this.requestId

        try {
          const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'

          const res = await this.$axios.get(
            `${baseUrl}api/v1/product/${this.productId}/upgrades`,
            {
              params: {
                inventory_id: this.inventoryId,
                quantity: this.quantity
              }
            }
          )

          if (requestId !== this.requestId) return

          this.groups = res?.data?.data?.groups || []
          this.error = ''

        } catch (e) {
          if (requestId !== this.requestId) return

          /*
           * Silent. A failed upgrade lookup must never stop somebody buying
           * the product they came for, so the section simply does not appear.
           */
          this.groups = []
        }
      },

      /**
       * Which option of this group the page is currently showing.
       *
       * Read off `inventoryId` rather than held locally, so the section stays
       * correct when the variant changes from anywhere else -- the colour
       * swatches, the back button, a page refresh.
       */
      pendingFor(group) {
        const match = group.options.find(
          option => Number(option.inventory_id) === Number(this.inventoryId)
        )

        return match ? match.inventory_id : null
      },

      choose(group, option) {
        if (!option.available || this.busy) return

        this.error = ''

        this.$emit('select', {
          group,
          option,
          inventory_id: option.inventory_id
        })
      },

      /** Back to the variant this group started from. */
      revert(group) {
        if (this.busy) return

        this.error = ''

        this.$emit('select', {
          group,
          option: group.current,
          inventory_id: group.current.inventory_id
        })
      },

      /**
       * What the upgrade costs, as a signed figure.
       *
       * A flash sale can make a bigger variant the same price or cheaper, and
       * saying so is more honest than hiding an option that is genuinely a
       * better deal.
       */
      differenceLabel(option) {
        const difference = Number(option.difference || 0)

        if (difference === 0) {
          return this.$t('upgrades.noExtra')
        }

        const amount = Math.abs(difference).toFixed(2)

        return difference > 0 ? `+€${amount}` : `−€${amount}`
      }
    },

    watch: {
      /*
       * Tell the parent whether this section has anything in it.
       *
       * The card this sits in also carries the cross-sell block and the
       * bundle deals, and it should not be drawn as an empty bordered box
       * when all three are empty. Only this component can answer for its own
       * half, and `immediate` so the answer is there before the first load
       * rather than one tick late.
       */
      hasGroups: {
        immediate: true,
        handler(has) {
          this.$emit('content', has)
        }
      },
      inventoryId: {
        immediate: true,
        handler() {
          this.load()
        }
      },
      /*
       * A band boundary changes what both sides of the difference cost, so the
       * answer is only true for the quantity it was asked about.
       */
      quantity() {
        this.load()
      },
      /*
       * Signing in, or having an account approved for trade, changes what
       * every variant costs -- so it changes the difference between two of
       * them. Re-asked rather than left, because a stale "+€100" beside a
       * freshly banded price is the sort of disagreement a customer notices at
       * the till.
       */
      accountKey() {
        this.load()
      }
    }
  }
</script>

<style scoped>
/*
 * Built on the page's existing "Accessories & Upgrades" card, which already
 * carries the header, the badge and the card chrome. Only the parts that
 * genuinely differ are written here: a radio row rather than a checkbox row,
 * and a price column that reads as a difference.
 */
.product-upgrades {
  margin-top: 4px;
}

.upgrade-row {
  align-items: flex-start;
  gap: 12px;
  padding: 9px 0;
  border-bottom: 1px solid #F1F2F7;
}

.upgrade-row:last-child {
  border-bottom: 0;
}

.upgrade-row .accessories-upgrades-checkbox-span {
  display: flex;
  align-items: flex-start;
  gap: 9px;
  cursor: pointer;
  line-height: 1.4;
}

/*
 * Round, and ticked with a dot rather than a checkmark.
 *
 * The page styles every input inside .accessories-upgrades-checkbox-span as a
 * square checkbox, which is right for the cross-sell list above -- those are
 * things you add, and you may add several. An upgrade is a CHOICE between
 * siblings, and a square box invites a customer to tick two of them and
 * wonder why the second one untocks the first. Deep selectors because the
 * shape being overridden is set on an unscoped rule.
 */
.upgrade-row >>> input[type="radio"],
.upgrade-row input[type="radio"] {
  margin-top: 2px;
  cursor: pointer;
  flex: 0 0 auto;
  border-radius: 50% !important;
}

.upgrade-row input[type="radio"]:checked {
  background: #fff !important;
  border-color: #05B942 !important;
}

.upgrade-row input[type="radio"]:checked::before {
  content: "";
  position: absolute;
  width: 10px;
  height: 10px;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  border-radius: 50%;
  background-image: none !important;
  background: #05B942;
}

.upgrade-row input[type="radio"]:disabled {
  cursor: default;
  background: #F2F4F8 !important;
}

.upgrade-label {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

/* The current choice is stated, not sold: no colour, no emphasis. */
.upgrade-included {
  color: #6B7280;
  font-size: 13px;
  white-space: nowrap;
}

.upgrade-note {
  font-size: 12px;
  font-style: normal;
  color: #B54708;
}

.upgrade-row.is-unavailable {
  opacity: .55;
}

.upgrade-row.is-unavailable .accessories-upgrades-checkbox-span {
  cursor: default;
}

.upgrade-error {
  margin: 10px 0 0;
  font-size: 13px;
  color: #D64545;
}

.badge.bg-outstock {
  padding: 5px 8px !important;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  font-weight: 600;
  line-height: normal;
  color: #fff;
  background: #98A2B3;
  border-radius: 86px;
}

/*
 * Phone: the price drops under the label rather than being squeezed against
 * it, so "+€100.00" never wraps mid-figure.
 */
@media only screen and (max-width: 600px) {
  .upgrade-row {
    flex-direction: column;
    align-items: stretch;
    gap: 4px;
  }

  .upgrade-row .accessories-upgrades-price-span {
    padding-left: 27px;
  }
}
</style>
