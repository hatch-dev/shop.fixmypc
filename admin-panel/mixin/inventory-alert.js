/**
 * Presentation for an inventory alert row.
 *
 * The severity itself is always the backend's - "low", "out" and "negative"
 * are three business states decided from the inventory rows, and the browser
 * must never re-derive them from a single number. This mixin only turns a
 * classified row into words, and it is shared by the dashboard panel and the
 * full alerts page so the two can never disagree about what a state is called.
 */
export default {
  methods: {
    /** @returns {{label:string, detail:string, icon:string, variantNote:string}} */
    inventoryAlertView(p) {
      const stock = Number(p.stock) || 0
      const variants = Number(p.variant_count) || 0
      const affected = Number(p.affected_variants) || 0

      // Name the variant only when there is more than one to choose from.
      const variantNote = variants > 1
        ? this.$t('index.variantsAffected', { count: affected, total: variants })
        : (p.sku || '')

      if (p.severity === 'negative') {
        return {
          ...p,
          label: this.$t('index.inventoryIssue'),
          detail: this.$t('index.oversoldBy', {
            count: Number(p.oversold_by) || Math.abs(stock)
          }),
          icon: 'fa-circle-exclamation',
          variantNote
        }
      }

      if (p.severity === 'out') {
        return {
          ...p,
          label: this.$t('index.outOfStock'),
          detail: this.$t('index.unitsCount', { count: 0 }),
          icon: 'fa-circle-xmark',
          variantNote
        }
      }

      return {
        ...p,
        label: this.$t('index.lowStockState'),
        detail: stock === 1
          ? this.$t('index.unitRemaining')
          : this.$t('index.unitsRemaining', { count: stock }),
        icon: 'fa-triangle-exclamation',
        variantNote
      }
    }
  }
}
