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
      return !!this.product?.wishlisted
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
        let data = null

        try {
          data = await this.buyNow({
            payload: {
              user_token: await this.getUserToken(),
              product_id: this.product.id,
              inventory_id: this.productInventory.id,
              quantity: this.quantity,
              price: finalPrice
            },
            lang: this.langCode
          })
        } catch (e) {
          data = null
        } finally {
          this.buyingNow = false
        }

        // Resolves with whether the Buy Now line was created: a refusal
        // (sold out, say) comes back as a 201 body, not a thrown error.
        resolve(data)
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
        const data = await this.buyNowProduct()

        // Only on to checkout if there is something to check out: a refused
        // Buy Now used to land on /shipping with nothing (or the basket) in it.
        if (data?.status !== 200) {
          this.setToastError(data?.data?.form?.[0] || data?.message || this.$t('detailRight.outOfStock'))
          return false
        }

        this.$router.push({path: '/shipping'})

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
