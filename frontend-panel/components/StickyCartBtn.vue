  <template>
    <div class="sticky-cart-wrap">
      <div class="container-fluid">
        <div class="sticky-cart">
          <div class="sticky-title">
            <lazy-image
              :data-src="thumbImageURL(product)"
              :title="productTitle"
              :alt="productTitle"
            />
            <h5 class="ellipsis ellipsis-2">{{ productTitle }}</h5>
          </div>
          <div class="btn-wrap">

            <ajax-button
              id="sticky-add-to-cart"
              class="btn add-cart-bt"
              :disabled="disabled"
              type="button"
              :fetching-data="ajaxing"
              @clicked="addToCart"
              :loading-text="$t('detailRight.adding')"
              :text="$t('detailRight.addToCart')"
            />
            <h2 class="price-wrapper">
                  <template v-if="selectedVoucher">
                    <span class="strike-through f-7 mr-10">
                      <price-format
                        :price="displayUnitPrice"
                      />
                    </span>
                    <span class="color-deep price voucher-price">
                      <price-format
                        :price="discountedPrice"
                      />
                    </span>
                  </template>
                    <template v-else>
                      <span
                           class="color-deep price"
                      >
                        <price-format
                          :price="displayUnitPrice"
                        />
                      </span>
                      <span
                        class="strike-through f-7"
                        v-if="displayOldPrice"
                      >
                        <price-format
                          :price="displayOldPrice"
                        />
                      </span>
                    </template>
            </h2>
            <button
              :aria-label="$t('ui.stickyCartBtn.submitAria')"
              class="compare-btn"
              @click.prevent="wishListAction"
            >

              <spinner
                v-if="ajaxingWishlist"
                class="mr-15"
              />
              <i v-else class="icon" :class="favouriteIcon"/>
            </button>

          </div>
        </div>
      </div>
    </div>

  </template>

  <script>
    import util from '~/mixin/util';
    import LazyImage from "./LazyImage";
    import AjaxButton from "./AjaxButton";
    import cartHelper from '~/mixin/cartHelper'
    import productHelper from '~/mixin/productHelper'
    import Spinner from "./Spinner";

    export default {
      name: 'StickyCartBtn',
      data() {
        return {}
      },
      watch: {},
      props: {
          product: Object,
          disabled: Boolean,
          productInventory: Object,

          displayUnitPrice: {
            type: [Number, String],
            default: 0
          },

          displayOldPrice: {
            type: [Number, String],
            default: 0
          },

          discountedPrice: {
            type: [Number, String],
            default: 0
          },

          selectedVoucher: {
            type: Object,
            default: null
          }
       },
      components: {
        Spinner,
        AjaxButton,
        LazyImage

      },
      computed: {
        favouriteIcon(){
          return this.wishListed ? 'heart-fill-icon' : 'heart-icon'
        },

        productTitle() {
          return this.product?.title;
        },
      },
      mixins: [util, cartHelper, productHelper],
      methods: {},
      created() {
      },
      async mounted() {
      }
    }
  </script>
<style scoped>
.sticky-cart .color-deep.price {
    font-size: 22px;
}
.sticky-cart-wrap .price-wrapper .strike-through {
    font-size: 18px;
    line-height: normal;
}
</style>