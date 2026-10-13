<template>
  <transition name="xs-fade">
    <component
      :is="inline ? 'section' : 'div'"
      v-if="open"
      :class="inline ? 'xs-inline' : 'xs-overlay'"
      :role="inline ? null : 'dialog'"
      :aria-modal="inline ? null : 'true'"
      :aria-label="$t('crossSell.title')"
      @click.self="onBackdrop"
    >
      <div class="xs-panel" ref="panel">
        <button
          type="button"
          class="xs-close"
          :aria-label="$t('crossSell.close')"
          @click="close"
        >&times;</button>

        <header class="xs-panel-head">
          <h3>{{ $t('crossSell.title') }}</h3>
          <p>{{ $t('crossSell.blurb') }}</p>
        </header>

        <!-- LOADING. Skeletons in the shape of the rows, so nothing shifts
             when the real ones arrive. -->
        <div v-if="loading" class="xs-rows">
          <div v-for="n in 3" :key="`sk-${n}`" class="xs-row is-skeleton">
            <skeleton-base width="20px" height="20px" radius="4" />
            <skeleton-base width="56px" height="56px" radius="8" />
            <div class="xs-row-main">
              <skeleton-base width="70%" height="13" mb="8" />
              <skeleton-base width="40%" height="11" />
            </div>
            <skeleton-base width="58px" height="16" />
          </div>
        </div>

        <!-- ERROR. Section 27: the primary product is already in the basket,
             so this says the recommendations failed and nothing more
             alarming. -->
        <div v-else-if="error" class="xs-state">
          <p>{{ $t('crossSell.loadFailed') }}</p>
          <button type="button" class="xs-link" @click="$emit('retry')">
            {{ $t('crossSell.tryAgain') }}
          </button>
        </div>

        <!-- EMPTY. Section 26: the normal shopping flow continues. -->
        <div v-else-if="!items.length" class="xs-state">
          <p>{{ $t('crossSell.empty') }}</p>
        </div>

        <template v-else>
          <div class="xs-rows">
            <div
              v-for="item in items"
              :key="item.id"
              class="xs-row"
              :class="{'is-selected': isSelected(item)}"
            >
              <input
                type="checkbox"
                class="xs-check"
                :id="`xs-${item.id}`"
                :checked="isSelected(item)"
                :disabled="needsChoice(item)"
                @change="toggle(item)"
              >

              <img
                class="xs-row-img"
                :src="getImageURL(item.image)"
                :alt="item.name"
                loading="lazy"
                @error="onImageError"
              >

              <div class="xs-row-main">
                <label class="xs-row-name" :for="`xs-${item.id}`">{{ item.name }}</label>

                <small v-if="item.sku && !item.requires_option" class="xs-row-sku">
                  {{ item.sku }}
                </small>

                <!--
                  Section 14. A product with a real choice gets a real choice;
                  nothing is picked on the customer's behalf, and the tick stays
                  disabled until they pick.
                -->
                <select
                  v-if="item.requires_option"
                  class="xs-row-option"
                  :aria-label="$t('crossSell.chooseOption', {product: item.name})"
                  :value="chosenOption(item)"
                  @change="chooseOption(item, $event.target.value)"
                >
                  <option value="">{{ $t('crossSell.chooseOne') }}</option>
                  <option
                    v-for="option in item.options"
                    :key="option.inventory_id"
                    :value="option.inventory_id"
                  >{{ option.label || option.sku }}</option>
                </select>

                <span v-if="!item.in_stock && item.backorder" class="xs-row-stock">
                  {{ $t('crossSell.backorder') }}
                </span>

                <!-- Quantity, only once it is actually being bought. -->
                <div v-if="isSelected(item)" class="xs-row-qty">
                  <button
                    type="button"
                    :aria-label="$t('crossSell.decrease')"
                    :disabled="quantityOf(item) <= 1"
                    @click="setQuantity(item, quantityOf(item) - 1)"
                  >−</button>
                  <span>{{ quantityOf(item) }}</span>
                  <button
                    type="button"
                    :aria-label="$t('crossSell.increase')"
                    @click="setQuantity(item, quantityOf(item) + 1)"
                  >+</button>
                </div>
              </div>

              <div class="xs-row-price">
                <s v-if="showsWas(item)">{{ money(priceOf(item).old) }}</s>
                <strong>
                  <span v-if="item.from && !chosenOption(item)" class="xs-from">
                    {{ $t('crossSell.from') }}
                  </span>
                  {{ money(priceOf(item).now) }}
                </strong>
              </div>
            </div>
          </div>

          <footer class="xs-panel-foot">
            <div class="xs-totals">
              <span>{{ $t('crossSell.selectedCount', {count: selectedCount}) }}</span>
              <strong>{{ $t('crossSell.additionalTotal') }} {{ money(additionalTotal) }}</strong>
            </div>

            <div class="xs-actions">
              <button
                type="button"
                class="xs-btn primary"
                :disabled="!selectedCount || adding"
                @click="add"
              >{{ adding ? $t('crossSell.adding') : $t('crossSell.addSelected') }}</button>

              <button type="button" class="xs-btn ghost" @click="close">
                {{ $t('crossSell.continue') }}
              </button>
            </div>
          </footer>
        </template>
      </div>
    </component>
  </transition>
</template>

<script>
  import {mapActions, mapGetters} from 'vuex'
  import util from '@/mixin/util.js'

  /**
   * "Complete Your Setup" -- the recommendations shown after an add.
   *
   * It renders and it collects ticks. It decides nothing: every price comes
   * from the server, priced for this customer and for the exact variant, and
   * the add goes back to the server to be proved all over again. A block that
   * worked a total out for itself is how a shopper is quoted one figure and
   * charged another.
   *
   * It is also allowed to fail. The primary product is already in the basket
   * by the time this opens (section 8), so an error state here says the
   * recommendations could not be loaded and offers to try again -- it never
   * suggests anything is wrong with the basket, because nothing is.
   */
  export default {
    name: 'CrossSellModal',

    mixins: [util],

    props: {
      open: {
        type: Boolean,
        default: false
      },
      /* Passed back to the server so it can prove these are its own. */
      primaryProductId: {
        type: [Number, String],
        default: null
      },

      /*
       * Sits IN the page rather than over it.
       *
       * A modal is right straight after an add -- the customer has just acted
       * and is deciding what comes next. On the basket page they are
       * reviewing, and a panel that covers what they came to look at is in
       * the way. Same rows, same footer, same store.
       */
      inline: {
        type: Boolean,
        default: false
      }
    },

    data() {
      return {
        // Restored when the panel closes, so a background page that was
        // scrolled is where the customer left it.
        previousOverflow: ''
      }
    },

    computed: {
      items() {
        return this.crossSellItems
      },

      loading() {
        return this.crossSellLoading
      },

      adding() {
        return this.crossSellAdding
      },

      error() {
        return this.crossSellError
      },

      selectedCount() {
        return this.crossSellSelectedCount
      },

      additionalTotal() {
        return this.crossSellAdditionalTotal
      },

      ...mapGetters('crossSell', [
        'crossSellItems', 'crossSellSelected', 'crossSellLoading',
        'crossSellAdding', 'crossSellError', 'crossSellSelectedCount',
        'crossSellAdditionalTotal'
      ]),

      /* What priceFormat needs, and the language the basket re-read runs in. */
      ...mapGetters('common', ['currencyIcon', 'currencyPosition', 'setting']),
      ...mapGetters('language', ['langCode'])
    },

    watch: {
      /*
       * The page behind a modal must not scroll. Restored rather than set to
       * '', because this panel can open over a page that had already locked
       * scrolling for its own reasons.
       */
      open(isOpen) {
        if (this.inline || typeof document === 'undefined') return

        if (isOpen) {
          this.previousOverflow = document.body.style.overflow
          document.body.style.overflow = 'hidden'
        } else {
          document.body.style.overflow = this.previousOverflow
        }
      }
    },

    methods: {
      isSelected(item) {
        return !!this.crossSellSelected[Number(item.id)]
      },

      /*
       * A product with options the customer has not chosen between cannot be
       * ticked. The server refuses it as well, and that is the half that
       * counts -- this is what stops them finding out only after clicking.
       */
      needsChoice(item) {
        return !!item.requires_option && !this.chosenOption(item)
      },

      chosenOption(item) {
        return this.crossSellSelected[Number(item.id)]?.inventory_id || ''
      },

      quantityOf(item) {
        return this.crossSellSelected[Number(item.id)]?.quantity || 1
      },

      /**
       * The price to show: the chosen option's, or the card's headline.
       *
       * Both come from the server. The headline is the cheapest buyable
       * option, which is why it is shown as "from" until a choice is made --
       * quoting one variant's price and charging another's is the defect this
       * avoids.
       */
      priceOf(item) {
        const inventoryId = this.chosenOption(item)

        const option = inventoryId
          ? (item.options || []).find(row => Number(row.inventory_id) === Number(inventoryId))
          : null

        return {
          now: Number(option ? option.price : item.price) || 0,
          old: Number(option ? option.old_price : item.old_price) || 0
        }
      },

      showsWas(item) {
        const price = this.priceOf(item)

        return price.old > price.now
      },

      /*
       * The same string <price-format :decimals="2"> renders, as plain text --
       * currency symbol, position and separators all from the store's
       * settings. Written out rather than taken from productPriceHelper,
       * whose money() is part of a mixin that assumes a single product on
       * the component and would bring a dozen computeds this panel has no
       * product for.
       */
      money(amount) {
        return this.priceFormat(
          this.currencyPosition, this.currencyIcon, Number(amount || 0), this.setting, 2
        )
      },

      toggle(item) {
        if (this.needsChoice(item)) return

        this.crossSellToggle({item})

        this.$emit('selection-changed', {
          product_id: item.id,
          selected: this.isSelected(item)
        })
      },

      /*
       * Choosing an option ticks the row as well. The customer picking a
       * colour has said they want it, and making them then tick a box they
       * could not reach a moment ago reads as the control being broken.
       */
      chooseOption(item, rawId) {
        const inventoryId = rawId ? Number(rawId) : null

        if (!inventoryId) {
          this.crossSellToggle({item, inventoryId: null})

          return
        }

        this.crossSellSetOption({productId: item.id, inventoryId})
      },

      setQuantity(item, quantity) {
        this.crossSellSetQuantity({productId: item.id, quantity})
      },

      /* Swap a missing thumbnail for the placeholder rather than the browser's
         broken-image box with the alt text spilling out of a 56px tile. */
      onImageError(event) {
        const fallback = this.getImageURL(this.defaultImage)

        if (event.target.src !== fallback) {
          event.target.src = fallback
        }
      },

      async add() {
        const result = await this.crossSellAddSelected({
          primaryProductId: this.primaryProductId,
          lang: this.langCode
        })

        /*
         * Whatever could not be added is named, one message per product, so a
         * shopper whose second tick sold out is told which one and why rather
         * than being left to compare the basket against what they ticked.
         */
        ;(result?.skipped || []).forEach((row) => {
          this.$store.commit('common/SET_TOAST_ERROR', row.message, {root: true})
        })

        this.$emit('added', result)

        /*
         * Only a clean run closes, and only when this is a modal. A partial
         * run leaves the panel open with the failures reported beside the
         * ticks that caused them; an inline block never closes at all, it just
         * reloads and finds the added products are no longer recommended.
         */
        if (!this.inline && result && result.added?.length && !(result.skipped || []).length) {
          this.close()
        }
      },

      /*
       * Clicking away closes the overlay. The inline block has nothing to
       * click away from, and dismissing it on a stray click inside the basket
       * page would take the recommendations off the screen for no reason the
       * customer would recognise.
       */
      onBackdrop() {
        if (this.inline) return

        this.close()
      },

      close() {
        this.crossSellDismiss()

        this.$emit('close')
      },

      ...mapActions('crossSell', {
        crossSellToggle: 'toggle',
        crossSellSetOption: 'setOption',
        crossSellSetQuantity: 'setQuantity',
        crossSellAddSelected: 'addSelected',
        crossSellDismiss: 'dismiss'
      })
    },

    beforeDestroy() {
      if (typeof document !== 'undefined') {
        document.body.style.overflow = this.previousOverflow
      }
    }
  }
</script>
