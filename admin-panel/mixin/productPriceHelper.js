/*
 * The "-N%" badge, from a before and after price.
 *
 * Rounded, not truncated. This read
 *
 *     100 - parseInt(((now / previous) * 100).toString())
 *
 * in five separate places, and truncating the RATIO rounds the DISCOUNT up:
 * the badge showed ceil() of the real figure every time the division was not
 * whole. A 15% clearance on a EUR 589.99 product advertised "-16% off", and
 * it erred that way consistently -- never in the shop's favour, always
 * overstating the saving.
 *
 * Zero rather than a badge when there is nothing to show: no previous price,
 * no current one, or a price that went up. NaN reached the template before,
 * and rendered as "-NaN%".
 *
 * A plain function rather than a method, so a computed can use it without a
 * component around it -- tests/*.check.js call these computeds with a bare
 * object for `this`, and a computed that reached for this.discountPercent
 * broke every one of them.
 */
function discountPercent(previous, now) {
  previous = parseFloat(previous)
  now = parseFloat(now)

  if (!(previous > 0) || !(now > 0) || now >= previous) {
    return 0
  }

  return Math.min(100, Math.max(0, Math.round(100 - (now / previous) * 100)))
}

export default {
  data() {
    return {
    }
  },
  computed: {
    productPrice() {
      if (this.productInventory?.inventory_attributes?.length > 0 && this.productInventory?.price > 0) {
        return this.productInventory?.price
      }
      return this.product.price > 0
        ? this.product.price : this.product.offered > 0
          ? this.product.offered : this.product.selling
    },
    reducedPercent() {
      return discountPercent(this.prevPrice, this.currentPricing)
    },
    flashPrice(){
      return this.product?.price !== null ? parseFloat(this.product?.price) : null
    },
    sellPrice(){
      return parseFloat(this.product?.selling || 0)
    },
    offeredPrice(){
      return parseFloat(this.product?.offered || 0)
    },
    prevPrice(){
      return parseFloat(this.offeredPrice > 0 ? this.sellPrice : this.flashPrice ? this.sellPrice : 0)
    },
    currentPricing(){
      return parseFloat(this.flashPrice !== null ? this.flashPrice
        : this.offeredPrice > 0 ? this.offeredPrice
          : this.sellPrice)
    },
    inventoryPrice(){
      //return parseFloat(this.inventory?.price) || 0

      if(this.inventory?.inventory_attributes.length > 0) {
        return parseFloat(this.inventory?.price) || 0
      }
      return 0
    },
    currentInventoryPrice(){
      return parseFloat(this.inventoryPrice > 0 ? this.inventoryPrice : this.currentPricing)
    },
  },
  methods:{
    /* Exposed for the components that call it from a template. */
    discountPercent,
    flashPriceCalc(product){
      return product?.end_time ? product?.price ?? null : null
    },
    sellPriceCalc(product){
      return parseFloat(product?.selling || 0)
    },
    offeredPriceCalc(product){
      return parseFloat(product?.offered || 0)
    },
    currentPricingCalc(product){
      const flashPrice = this.flashPriceCalc(product)

      return flashPrice !== null
        ? flashPrice : this.offeredPriceCalc(product) > 0
          ? this.offeredPriceCalc(product) : parseFloat(product?.selling || 0)
    },
    prevPriceCalc(product){
      return this.sellPriceCalc(product)
    },
    currentInventoryPriceCalc(inventory, product){
      if(inventory?.inventory_attributes?.length > 0) {
        return parseFloat(inventory?.price || 0) > 0 ? (inventory?.price || 0) : this.currentPricingCalc(product)
      }

      return this.currentPricingCalc(product)
    },
  }
}
