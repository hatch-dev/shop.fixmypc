/**
 * Reading the server's business pricing quote.
 *
 * Nothing in here works a price out. The bands, the price each band charges
 * and the price for the quantity in the box are all decided by
 * FrontendController::businessProduct, which prices through the same helper
 * the basket and the order use.
 *
 * The page used to re-derive the band discount in the browser from
 * products.offered. The discount hangs off the band's own wholesale_price, so
 * the two disagreed whenever those differed, and an account entitled to 599
 * was quoted 699.
 *
 * The one thing decided locally is *which* band row covers a quantity, so the
 * figure moves as the stepper is clicked rather than waiting for a round trip.
 * It uses the same rule as BusinessProductPricing::coversQuantity, and the
 * server's answer replaces it as soon as it lands.
 */
export default {
  methods: {
    /*
     * A max of 0 — or anything below the minimum — is how the admin screen
     * stores an open-ended "31 and over" band. Testing `qty <= row.max`, as
     * this page used to, skipped that band entirely, so the largest orders
     * fell back to retail.
     */
    bandCoversQuantity(row, quantity) {
      const qty = parseInt(quantity, 10)
      const min = parseInt(row && row.min, 10)

      if (!Number.isFinite(qty) || !Number.isFinite(min) || qty < min) {
        return false
      }

      const max = parseInt(row && row.max, 10)

      return !Number.isFinite(max) || max <= 0 || qty <= max
    },

    /*
     * The cheapest band covering this quantity, mirroring
     * Pricing::businessTier. Overlaps are rejected by the admin screen, but
     * rows predating that check still exist and the two sides have to resolve
     * them the same way.
     */
    bandForQuantity(rows, quantity) {
      let best = null
      let bestPrice = null

      ;(rows || []).forEach((row) => {
        if (!this.bandCoversQuantity(row, quantity)) return

        const price = parseFloat(row.final_price)

        if (!Number.isFinite(price)) return

        if (bestPrice === null || price < bestPrice) {
          best = row
          bestPrice = price
        }
      })

      return best
    },

    /*
     * The unit price for a quantity, from prices the server has already
     * decided. `base` is what the page would charge without any band; a band
     * never raises a price, which is why this is a min and not a replacement
     * (see Pricing::businessPrice).
     */
    bandedUnitPrice(rows, quantity, base) {
      const retail = parseFloat(base)
      const band = this.bandForQuantity(rows, quantity)

      if (!band) {
        return Number.isFinite(retail) ? retail : 0
      }

      const banded = parseFloat(band.final_price)

      if (!Number.isFinite(banded)) {
        return Number.isFinite(retail) ? retail : 0
      }

      return Number.isFinite(retail) ? Math.min(retail, banded) : banded
    },
  },
}
