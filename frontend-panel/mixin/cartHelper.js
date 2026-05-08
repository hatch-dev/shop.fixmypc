import {mapGetters, mapActions} from 'vuex'

export default {
  data() {
    return {
      ajaxingWishlist: false,
      ajaxing: false,
      buyingNow: false,
      quantity: 1,
      cartError: {
        attribute: null,
        quantity: null,
      },
    }
  },
  computed:{
    wishListed() {
      return this.$auth?.user?.id && this.product?.wishlisted
    },
    maxQuantity() {
      if (this.productInventory?.is_active == 1) return 9999
      return parseInt(this.productInventory?.quantity || 0)
    },
    isInStock() {
      if (!this.productInventory) return false
      if(this.productInventory.is_active == 1) return true
      return this.productInventory.quantity > 0
    },
    ...mapGetters('common', ['currencyIcon', 'setting']),
    ...mapGetters('language', ['langCode']),
  },
  methods: {

    emitCartError() {

      this.$emit('cart-error', this.cartError)

    },
    async buyNowProduct() {
      return new Promise(async resolve => {

        this.buyingNow = true

        // const finalPrice = this.finalUnitPrice || this.product.selling

        const offered = Number(this.product.offered)
        const selling = Number(this.product.selling)
        // const finalPrice = !isNaN(offered) && offered > 0 ? offered : selling
        const finalPrice = this.selectedVoucher ? Number(this.discountedPrice) : (offered > 0 ? offered : selling);
        await this.buyNow({
          payload: {
            user_token: await this.getUserToken(),
            product_id: this.product.id,
            inventory_id: this.productInventory.id,
            quantity: this.quantity,
            price: finalPrice
          },
          lang: this.langCode
        }).then(() => {
          this.buyingNow = false
          resolve()
        })
      })
    },
    async addToCart(isBuyNow = false) {
      if (!this.setting?.guest_checkout) {
        if (!this.$auth.loggedIn) {
          this.$auth.redirect('login')
          return false
        }
      }

      this.cartError = {
        attribute: null,
        quantity: null
      }


      const inventory = this.productInventory || {}
      const isBackOrder = inventory?.is_active == 1
      const stockQty = Number(inventory?.quantity || 0)

      

      if (Object.values(this.productInventory).length === 0) {
        const attr = this.product?.attribute.map(i=>{
          return i?.title
        })
        this.cartError.attribute = this.$t('detailRight.requiredAttributes')
        if(attr.length){
          this.cartError.attribute += `(${attr.join(' / ')})`
        }
        this.emitCartError()
        return false
      }

      if (!isBackOrder && stockQty <= 0) {
        this.setToastError(this.$t('detailRight.outOfStock'))
        return false
      }

      if (!isBackOrder && this.productInventory.quantity < this.quantity) {
        this.cartError.quantity = this.$t('detailRight.exceedsInventory')
        this.emitCartError()
        return false
      }
      if (isBuyNow) {
        await this.buyNowProduct()
          .then(() => {

            setTimeout(() => {
              this.$router.push({path: '/shipping'})
            }, 300)

          })

      } else {
        await this.cartAdd()
        this.$emit('added-to-cart')
      }
    },
    async cartAdd() {
      this.ajaxing = true
      // const finalPrice = this.finalUnitPrice || this.product.selling
      const offered = Number(this.product.offered)
      const selling = Number(this.product.selling)
      // const finalPrice = !isNaN(offered) && offered > 0 ? offered : selling
      const finalPrice = this.selectedVoucher ? Number(this.discountedPrice) : (offered > 0 ? offered : selling);
      await this.cartAction({
        payload: {
          user_token: await this.getUserToken(),
          apiVal: {
            user_token: await this.getUserToken(),
            product_id: this.product.id,
            inventory_id: this.productInventory?.id,
            quantity: this.quantity,

            price: finalPrice,
            voucher_code: this.selectedVoucher?.code || null,
            voucher_discount: this.selectedVoucher ? Number(this.voucherDiscountAmount) : 0,
            original_price: Number(this.product.offered) > 0 ? Number(this.product.offered) : Number(this.product.selling)
          },
          isBundle: !!this.product?.bundle_deal,
          storeVal: {
            product: {
              id: this.product.id,
              title: this.product.title,
              offered: this.product.offered,
              selling: finalPrice,
              voucher_code: this.selectedVoucher?.code || null,
              voucher_discount: this.selectedVoucher ? Number(this.voucherDiscountAmount) : 0,
              original_price: Number(this.product.offered) > 0 ? Number(this.product.offered) : Number(this.product.selling),
              image: this.product.image,
              shipping_rule: this.product.shipping_rule
            },
            inventory: this.productInventory,
            quantity: this.quantity,
            selected: 1,
            offered: 0,
            bundle_deal: this.product?.bundle_deal,
            shipping_type: 1
          }
        },
        lang: this.langCode

      })
      this.ajaxing = false
    },
    ...mapActions('cart', ['cartAction', 'buyNow']),
    ...mapActions('wishlist', ['userWishlistAction']),
    ...mapActions('common', ['setToastMessage', 'setToastError']),
    ...mapActions('user', ['getUserToken']),
  }
}
