
import { postcodeRule } from '~/mixin/addressRules'

export default {
  data() {
    return {
      fetchingAddressData: false,
      /*
       * A saved-address list that will not load is a failure of this panel,
       * not of the page.
       *
       * fetchingData used to hand that failure to hasError(), which is
       * $nuxt.error(): the whole route is replaced by the error screen. The
       * address list is mounted inside the basket, so a shopper whose identity
       * the API no longer recognised - a sign-in that had gone stale, a guest
       * token the server would not issue - pressed the basket and got
       * "SORRY / Something went wrong" in place of their cart, with the items
       * still in it and nothing on screen saying what had actually failed.
       */
      addressError: null
    }
  },
  methods: {
    async addressAction() {
      /*
       * A postcode is only part of a complete address where the country runs
       * one; requiring it everywhere left customers in the UAE, Hong Kong and
       * the like unable to save an address at all.
       */
      const needsPostcode = !!postcodeRule(this.addressData.country)

      if (this.addressData.email && this.addressData.city && this.addressData.phone && this.addressData.name &&
        (!needsPostcode || this.addressData.zip) && this.addressData.country && this.addressData.address_1) {

        this.submittingAddressData = true
        const data = await this.userAddressAction({
          ...this.addressData,
          ...{
            user_token: await this.getUserToken()
          }
        })

        if(data?.status === 200){
          this.hasAddressErrors = false
          this.setToastMessage(data.message)

        } else if(data?.status === 201) {
          this.setToastError(data.data?.form?.join(', '))

        } else {
          /*
           * A refused save is reported the same way as the 201 above rather
           * than through hasError(): losing the page also loses the form, and
           * with it everything the customer had just typed.
           */
          console.error('Address could not be saved', data)
          this.setToastError(this.addressFailureMessage(data, 'We could not save that address. Please try again.'))
        }
        this.submittingAddressData = false

      } else {
        this.hasAddressErrors = true
      }
    },
    async deleting(address) {
      if (confirm(this.$t('cartProductTile.deleteAlert'))) {
        this.ajaxDeleting = address.id
        const data = await this.userAddressDelete({
          id: address.id,
          params: {
            user_token: await this.getUserToken()
          }
        })

        if(data?.status === 200){
          this.setToastMessage(data.message)
          await this.fetchingData()
        }else {
          this.setToastError(data.data.form.join(', '))
        }
        this.ajaxDeleting = 0
      }
    },
    /*
     * Whatever the API said, if it said anything a customer can act on.
     *
     * The generic envelope carries __('lang.went_wrong') - "Something went
     * wrong" - which is the string that used to end up as the page title. It
     * tells nobody anything, so it is dropped in favour of the caller's own
     * wording, which at least names what failed.
     */
    addressFailureMessage(data, fallback) {
      const form = data?.data?.form
      const message = (Array.isArray(form) ? form.join(', ') : null) || data?.message

      if (!message || /something went wrong/i.test(message)) {
        return fallback
      }

      return message
    },
    formatAddress(obj) {
      let addArr = []

      addArr.push(obj.address_1)
      if (obj.address_2) {
        addArr.push(obj.address_2)
      }
      addArr.push(obj.city + '-' + obj.zip)

      if (this.countryList[obj.country]) {
        const country = this.countryList[obj.country]

        if (country.states[obj.state]) {
          addArr.push(country.states[obj.state].name)
        }

        addArr.push(country.name)
      }


      let addressStr = addArr.filter(function (el) {
        return el != null;
      }).join(', ')

      return addressStr
    },
    /*
     * Loads the address list and owns fetchingAddressData while it does.
     *
     * The body used to sit in a bare setTimeout, so the promise this returned
     * resolved the moment the timer was *scheduled* rather than when the
     * request came back. Callers that awaited it -- the address book's
     * mounted() among them -- carried on immediately and cleared the loading
     * flag before the fetch had even started, which made the skeleton blink
     * out and back in. The timer is kept (it lets the route param settle
     * first) but is now wrapped so awaiting means what it says.
     */
    fetchingData() {
      this.fetchingAddressData = true
      this.addressError = null

      return new Promise((resolve) => {
        setTimeout(async () => {
          try {
            this.settingRouteParam()

            const data = await this.userAddressAll({
              time_zone: this.timeZone,
              order_by: this.orderBy,
              type: this.orderByType,
              page: this.page,
              user_token: await this.getUserToken(),
              q: this.search
            })

            if (data?.status !== 200) {
              // Kept out of the page-level error handler on purpose - see
              // addressError above.
              console.error('Address list could not be loaded', data)
              this.addressError = this.addressFailureMessage(data,
                'We could not load your saved addresses just now.')
            }
          } catch (e) {
            console.error('Address list could not be loaded', e)
            this.addressError = 'We could not load your saved addresses just now.'
          } finally {
            // In finally: a thrown request used to return early and leave the
            // flag stuck on.
            this.fetchingAddressData = false
            resolve()
          }
        }, 100)
      })
    }
    }
}
