/*
 * The three states of a cross-sell promotion, spelled and coloured the same
 * way on the list, the view screen and the product's assignment section.
 *
 * 1 active / 2 inactive match every other promotion here; 3 is draft. One
 * place rather than three, because a status chip that reads "Draft" on one
 * screen and "Inactive" on another is a status an admin stops trusting.
 */
export default {
  methods: {
    statusLabel(status) {
      switch (Number(status)) {
        case 1: return this.$t('crossSell.active')
        case 2: return this.$t('crossSell.inactive')
        default: return this.$t('crossSell.draft')
      }
    },

    statusClass(status) {
      switch (Number(status)) {
        case 1: return 'live'
        case 2: return 'off'
        default: return 'blocked'
      }
    }
  }
}
