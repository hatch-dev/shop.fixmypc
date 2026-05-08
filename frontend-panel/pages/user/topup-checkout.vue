<template>
    <div class="form-group mt-3">
        <div id="sumup-topup-card"></div>
    </div>
</template>

<script>
import { set } from 'lodash';
import { mapActions } from 'vuex'
export default {
    data(){
        return {
            
        }
    },
    methods: {
        ...mapActions('common', ['setToastMessage', 'setToastError']),
        loadSumupScript(){
            if (window.SumUpCard) {
                this.mountWidget()
                return
            }
        },
        mountWidget(){
            const checkoutId = this.$route.query.checkoutId
            if (!checkoutId) {
                console.error("No checkoutId found")
                return
            }

            window.SumUpCard.mount({
                id: 'sumup-topup-card',
                checkoutId: checkoutId,
                onResponse: async (type, body) => {
                    if (type === 'success') {
                        var topUpId = body.checkout_reference;
                        var transactionId = body.transaction_id;
                        var amount = body.amount;
                        await this.confirmTopup(topUpId, transactionId, amount)
                    }

                    if (type === 'error') {
                        this.setToastError('Payment failed. Please try again.')
                        return false;
                    }
                }
            });
        },
        async confirmTopup(topUpId, transactionId, amount) {
            try{
                const token = this.$auth?.strategy?.token?.get()
                const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'

                const { data } = await this.$axios.post(
                    `${baseUrl}api/v1/user/wallet/topup/confirm`,
                    {
                        checkout_reference: topUpId,
                        transaction_id: transactionId,
                        amount: amount
                    },
                    {
                        headers: {
                            Authorization: `Bearer ${token}`
                        }
                    }
                )
                this.setToastMessage('Wallet topped up successfully')
                setTimeout(() => {
                    this.$router.push('/user/dashboard')
                }, 750)
                this.$emit('refreshWallet')
            } catch (e) {
                console.error(e)
            }
        }
    },
    async mounted() {
        if (process.client) {
            this.loadSumupScript()
        }
    },
}
</script>