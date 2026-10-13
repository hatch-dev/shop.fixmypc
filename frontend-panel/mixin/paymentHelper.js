export default {
  data() {
    return {}
  },
  mounted() {
  },
  methods: {
    phpEncryption(data) {
      data = JSON.stringify(data)
      let key = this.CryptoJS.enc.Hex.parse("0123456470abcdef0123456789abcdef");
      let iv = this.CryptoJS.enc.Hex.parse("abcdef1876343516abcdef9876543210");
      return this.CryptoJS.AES.encrypt(data, key, {iv: iv, padding: this.CryptoJS.pad.ZeroPadding}).toString()
    },
    async paymentDoneFn(orderId, paymentToken, orderMethod) {

      const data = await this.paymentDone({
        lang: this.langCode,
        payload: {
          data: this.phpEncryption({
            id: orderId,
            payment_token: paymentToken,
            order_method: orderMethod,
            user_token: await this.getUserToken()
          })
        }
      })

      this.submitting = false
      // Stripe: the bank wants 3-D Secure first. Not paid yet, not failed.
      if (data?.status === 200 && data?.data?.requires_action) {
        return data
      }
      if (data?.status === 200) {
        this.$emit('success', orderId)
      } else {
        /*
         * Not every refusal comes back shaped like a form error - a gateway
         * timeout or a proxy page does not - and reaching blindly into
         * data.data.form threw a TypeError that took out whatever was midway
         * through placing the order.
         */
        const message = Array.isArray(data?.data?.form)
          ? data.data.form.join(', ')
          : (data?.message || 'Payment could not be confirmed')

        this.errorText = message
        this.$emit('error', message)

      }
      return data

    },
  }
}
