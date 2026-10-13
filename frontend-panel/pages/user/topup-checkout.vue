<template>
    <div class="form-group mt-3">
        <div v-show="!declined" id="sumup-topup-card"></div>

        <!-- a declined card: say so, and offer a fresh attempt -->
        <div v-if="declined" class="pay-declined" role="alert">
            <span class="pay-declined-icon" aria-hidden="true"><i class="fa-solid fa-credit-card"></i></span>
            <h2 class="pay-declined-title">Payment declined</h2>
            <p class="pay-declined-text">
                Your card was not charged. Check the card details, or try a different card.
            </p>
            <nuxt-link :to="retryLink" class="pay-declined-btn">Try again</nuxt-link>
        </div>
    </div>
</template>

<script>
import { mapActions } from 'vuex'
export default {
    middleware: ['common-middleware', 'auth'],
    data(){
        return {
            confirming: false,
            // SumUp declined the card: show the way back instead of the form
            declined: false
        }
    },
    computed: {
        // where to start a fresh checkout from
        retryLink() {
            return this.$route.query.purpose === 'gift' ? '/user/vouchers' : '/user/dashboard'
        }
    },
    methods: {
        ...mapActions('common', ['setToastMessage', 'setToastError']),
        /*
         * The SDK is a global script from nuxt.config; on a direct visit it can
         * still be downloading when this page mounts, which used to leave a
         * blank page. Wait up to 10s for it before giving up.
         */
        loadSumupScript(startedAt = Date.now()){
            if (window.SumUpCard) {
                this.mountWidget()
                return
            }

            if (Date.now() - startedAt > 10000) {
                this.setToastError('The payment form could not be loaded. Please refresh the page.')
                return
            }

            setTimeout(() => this.loadSumupScript(startedAt), 200)
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
                    /*
                     * SumUp reports "success" when the card form was processed,
                     * not when the payment went through: a declined card also
                     * arrives as success, with the checkout status FAILED. That
                     * used to be sent on for confirmation and came back as
                     * "If your card was charged, please contact us" - for a card
                     * that was never charged.
                     */
                    const status = String(body?.status || '').toUpperCase()

                    if (type === 'fail' || (type === 'success' && status && status !== 'PAID')) {
                        this.declined = true
                        this.setToastError('Your card was declined, so nothing was charged.')
                        return false
                    }

                    if (type === 'success') {
                        if (this.$route.query.purpose === 'gift') {
                            await this.confirmGift(checkoutId)
                        } else {
                            await this.confirmTopup(checkoutId, body?.amount || this.$route.query.amount)
                        }
                    }

                    if (type === 'error') {
                        this.setToastError('Payment failed. Please try again.')
                        return false;
                    }
                }
            });
        },
        /*
         * A gift voucher paid by card. It used to be confirmed as a wallet
         * top-up, so no voucher was ever recorded; the server now checks the
         * checkout was paid for this voucher's total and records it once.
         */
        async confirmGift(checkoutId) {
            if (this.confirming) return
            this.confirming = true
            try {
                const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'
                // everything else was fixed on the server when the checkout was opened
                const {data} = await this.$axios.post(`${baseUrl}api/v1/user/purchase-gift-voucher/confirm`, {
                    checkout_id: checkoutId
                })
                this.setToastMessage(data?.message || 'Gift card bought')
                setTimeout(() => this.$router.push('/user/vouchers'), 750)
            } catch (e) {
                console.error(e)
                this.setToastError(e?.response?.data?.message
                    || 'We could not confirm this payment. If your card was charged, please contact us.')
            } finally {
                this.confirming = false
            }
        },
        /*
         * The server reads the checkout back from SumUp and credits what SumUp
         * charged; the amount here is only checked against it.
         */
        async confirmTopup(checkoutId, amount) {
            if (this.confirming) return
            this.confirming = true

            try{
                const token = this.$auth?.strategy?.token?.get()
                const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'

                const { data } = await this.$axios.post(
                    `${baseUrl}api/v1/user/wallet/topup/confirm`,
                    {
                        checkout_id: checkoutId,
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
                    // back to the page the top-up started from
                    this.$router.push(this.$route.query.from === 'savers-club' ? '/user/savers-club' : '/user/dashboard')
                }, 750)
            } catch (e) {
                // Used to be console-only: a refused top-up looked like nothing happened.
                console.error(e)
                this.setToastError(e?.response?.data?.message
                    || 'We could not confirm this payment. If your card was charged, please contact us.')
            } finally {
                this.confirming = false
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
<style scoped>
.pay-declined {
    max-width: 460px;
    margin: 24px auto;
    padding: 28px 24px;
    text-align: center;
    background: #fff;
    border: 1px solid #F5C2C0;
    border-radius: 16px;
}

.pay-declined-icon {
    width: 52px;
    height: 52px;
    margin: 0 auto 12px;
    border-radius: 14px;
    background: #FEE2E2;
    color: #B42318;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
}

.pay-declined-title {
    margin: 0 0 6px;
    font-size: 20px;
    font-weight: 700;
    color: #130E2B;
}

.pay-declined-text {
    margin: 0 0 18px;
    font-size: 14.5px;
    color: #6B7280;
    line-height: 1.55;
}

.pay-declined-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 46px;
    padding: 0 28px;
    border-radius: 12px;
    background: #33319A;
    color: #fff;
    font-weight: 700;
    text-decoration: none;
}

.pay-declined-btn:hover { background: #28267D; color: #fff; }
</style>
