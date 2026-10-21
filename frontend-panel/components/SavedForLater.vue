<template>
  <div v-if="saved.length || fetching" class="cart-card saved-card">
    <div class="cart-card-head">
      <h5 class="cart-card-title">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"
            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        {{ $t('ui.savedForLater.title') }}
        <span class="cart-count">
          ({{ $tc('ui.savedForLater.itemCount', saved.length, { count: saved.length }) }})
        </span>
      </h5>
    </div>

    <transition name="fade" mode="out-in">
      <!--
        Rows on the same 64px / text / action grid as the real ones, so the
        panel keeps its height and the basket below it does not jump when the
        saved items arrive.
      -->
      <div v-if="fetching" class="saved-skeleton">
        <div v-for="index in 3" :key="`sk-saved-${index}`" class="saved-row">
          <div class="saved-img">
            <skeleton-base :width="64" :height="64" :radius="8" />
          </div>
          <div class="saved-info">
            <skeleton-base :height="13" width="78%" :mb="10" />
            <skeleton-base :height="12" width="40%" />
          </div>
          <skeleton-base :height="32" width="86px" :radius="8" />
        </div>
      </div>

      <div v-else>
        <div v-for="item in saved" :key="item.id" class="saved-row">
          <div class="saved-img">
            <nuxt-link :to="productLink(item.product)" :title="item.product.title">
              <lazy-image
                :data-src="getThumbImageURL(item.product.image)"
                :title="item.product.title"
                :alt="item.product.title"
              />
            </nuxt-link>
          </div>

          <div class="saved-info">
            <h6 class="saved-title">
              <nuxt-link class="ellipsis-1" :to="productLink(item.product)" :title="item.product.title">
                {{ item.product.title }}
              </nuxt-link>
            </h6>
            <div class="saved-meta">
              <price-format class="saved-price" :price="itemPrice(item)" />
              <span class="saved-qty">{{ $t('ui.savedForLater.qty', { count: item.quantity }) }}</span>
            </div>
            <p v-if="!inStock(item)" class="saved-stock-out">{{ $t('ui.savedForLater.outOfStock') }}</p>
          </div>

          <div class="saved-actions">
            <button
              type="button"
              class="saved-move-btn"
              :disabled="busyId === item.id || !inStock(item)"
              @click.prevent="moveToCart(item)"
            >
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M3 4h2l2.4 11.2a2 2 0 0 0 2 1.6h7.5a2 2 0 0 0 2-1.6L20.5 8H6"
                  stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <circle cx="10" cy="20" r="1.4" fill="currentColor"/>
                <circle cx="17" cy="20" r="1.4" fill="currentColor"/>
              </svg>
              {{ busyId === item.id ? $t('ui.savedForLater.moving') : $t('ui.savedForLater.moveToCart') }}
            </button>
            <button
              type="button"
              class="saved-remove-btn"
              :disabled="busyId === item.id"
              :aria-label="$t('ui.savedForLater.removeSaved')"
              @click.prevent="removeItem(item)"
            >
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 11v6M14 11v6"
                  stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </button>
          </div>
        </div>
      </div>
    </transition>
  </div>
</template>

<script>
  import { mapGetters, mapActions } from 'vuex'
  import util from '~/mixin/util'
  import LazyImage from '~/components/LazyImage'
  import PriceFormat from '~/components/PriceFormat'

  export default {
    name: 'SavedForLater',
    components: {
      LazyImage,
      PriceFormat
    },
    mixins: [util],
    data() {
      return {
        saved: [],
        fetching: false,
        busyId: 0
      }
    },
    computed: {
      ...mapGetters('language', ['langCode'])
    },
    methods: {
      /*
       * A guest's saved list belongs to its token, the same identity that owns
       * its basket, so every call has to carry one.
       */
      async ownerToken() {
        return this.$auth.loggedIn ? null : await this.getUserToken()
      },
      itemPrice(item) {
        const offered = Number(item?.product?.offered)

        return offered > 0 ? offered : Number(item?.product?.selling || 0)
      },
      inStock(item) {
        // A back-order line (is_active) can be moved back even at zero stock,
        // which is the same rule the product page applies.
        if (parseInt(item?.inventory?.is_active) === 1) return true

        return Number(item?.inventory?.quantity || 0) >= Number(item?.quantity || 1)
      },
      async fetchSaved() {
        this.fetching = true

        try {
          const data = await this.getRequest({
            params: { user_token: await this.ownerToken() },
            api: 'saveForLaterAll',
            requiredToken: true,
            lang: this.langCode
          })

          this.saved = data?.status === 200 ? (data.data || []) : []

        } catch (e) {
          this.saved = []
        }

        this.fetching = false
      },
      async moveToCart(item) {
        this.busyId = item.id

        try {
          // Go back through the cart endpoint rather than moving the row
          // server-side, so the line gets the same stock and pricing checks as
          // any other add.
          await this.cartAction({
            payload: {
              user_token: await this.ownerToken(),
              apiVal: {
                user_token: await this.ownerToken(),
                product_id: item.product_id,
                inventory_id: item.inventory_id,
                quantity: item.quantity
              },
              isBundle: false,
              storeVal: {
                product: {
                  id: item.product.id,
                  title: item.product.title,
                  offered: item.product.offered,
                  selling: this.itemPrice(item),
                  image: item.product.image
                },
                inventory: item.inventory,
                quantity: item.quantity,
                selected: 1,
                offered: 0,
                shipping_type: 1
              }
            },
            lang: this.langCode
          })

          await this.removeItem(item, false)

          this.$emit('moved')

        } catch (e) {
          this.setToastError(e.message)
        }

        this.busyId = 0
      },
      async removeItem(item, notify = true) {
        this.busyId = item.id

        try {
          const data = await this.deleteRequest({
            id: item.id,
            params: { user_token: await this.ownerToken() },
            api: 'saveForLaterDelete',
            requiredToken: true
          })

          if (data?.status === 200) {
            this.saved = this.saved.filter(i => i.id !== item.id)

            if (notify) {
              this.setToastMessage(this.$t('ui.savedForLater.removed'))
            }
          } else if (notify) {
            this.setToastError(data?.message)
          }

        } catch (e) {
          if (notify) {
            this.setToastError(e.message)
          }
        }

        this.busyId = 0
      },
      ...mapActions('common', ['getRequest', 'deleteRequest', 'setToastMessage', 'setToastError']),
      ...mapActions('cart', ['cartAction']),
      ...mapActions('user', ['getUserToken'])
    },
    async mounted() {
      await this.fetchSaved()
    }
  }
</script>

<style scoped>
.saved-row {
  display: grid;
  grid-template-columns: 64px 1fr auto;
  align-items: center;
  gap: 12px;
  padding: 12px 0;
  border-top: 1px solid #f0f1f6;
}
.saved-row:first-child {
  border-top: 0;
}
.saved-img img {
  width: 64px;
  height: 64px;
  object-fit: contain;
  border-radius: 8px;
  border: 1px solid #eef0f6;
  background: #fff;
}
.saved-title {
  margin: 0 0 4px;
  font-size: 13px;
  font-weight: 600;
}
.saved-title a {
  color: #130e2b;
}
.saved-meta {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 12px;
}
.saved-price {
  font-weight: 700;
  color: #130e2b;
}
.saved-qty {
  color: #8a8fa3;
}
.saved-stock-out {
  margin: 4px 0 0;
  font-size: 12px;
  color: #d64545;
}
.saved-actions {
  display: flex;
  align-items: center;
  gap: 8px;
}
/*
 * Matches the tinted indigo chip the rest of the cart page uses for its
 * secondary actions (the bundle "+" buttons), rather than the hard black
 * outline it had, which read as a stray box next to the summary card.
 * Height is locked to the remove button beside it so the pair sits level.
 */
.saved-move-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  height: 32px;
  padding: 0 14px;
  border: 1px solid #d7d9e6;
  border-radius: 8px;
  background: #f6f5fd;
  color: var(--primary-color, #33319a);
  font-size: 12px;
  font-weight: 600;
  line-height: 1;
  white-space: nowrap;
  cursor: pointer;
  transition: background .15s ease, border-color .15s ease;
}
.saved-move-btn svg {
  flex: 0 0 auto;
}
.saved-move-btn:hover:not(:disabled) {
  background: #e9e8fb;
  border-color: var(--primary-color, #33319a);
}
.saved-move-btn:disabled,
.saved-remove-btn:disabled {
  opacity: .5;
  cursor: not-allowed;
}
.saved-remove-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border: 1px solid #e8eaf1;
  border-radius: 8px;
  background: #fff;
  color: #6b7280;
  cursor: pointer;
}
.saved-remove-btn:hover:not(:disabled) {
  color: #d64545;
  border-color: #d64545;
}

@media (max-width: 575px) {
  .saved-row {
    grid-template-columns: 56px 1fr;
    grid-template-areas:
      "img info"
      "actions actions";
  }
  .saved-img { grid-area: img; }
  .saved-info { grid-area: info; }
  .saved-actions {
    grid-area: actions;
    justify-content: flex-end;
  }
  .saved-img img {
    width: 56px;
    height: 56px;
  }
}
</style>
