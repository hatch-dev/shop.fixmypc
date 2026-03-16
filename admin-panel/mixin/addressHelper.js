
export default {
  data() {
    return {
    }
  },
  methods: {
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
  }
}
