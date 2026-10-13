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
export function discountPercent(previous, now) {
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
    /*
     * QA audit PRC-01.
     *
     * The ladder is: an active flash sale price, then the variant's own price,
     * then the offer price, then the selling price. This has to match
     * App\Models\Helper\Pricing::unitPrice() exactly — when it did not, a
     * variant price silently cancelled flash sales and the customer was shown
     * one number and charged another.
     */
    productPrice() {
      if (this.flashPrice !== null) {
        return this.flashPrice
      }
      if (this.productInventory?.inventory_attributes?.length > 0 && this.productInventory?.price > 0) {
        return this.productInventory?.price
      }
      return this.product.offered > 0 ? this.product.offered : this.product.selling
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
    /*
     * When the listing API supplies a display price, it wins.
     *
     * The server derives it from the cheapest variant a customer can actually
     * buy (App\Models\Helper\Pricing::displayRange), which is the price
     * checkout will charge. Reading products.offered here instead advertised a
     * product-level figure nobody could pay — a laptop whose cheapest in-stock
     * variant cost €500 showed €450 on the tile.
     *
     * Endpoints that do not send display_price fall through to the old logic.
     */
    hasServerPrice(){
      return this.product && this.product.display_price !== undefined && this.product.display_price !== null
    },
    prevPrice(){
      if (this.hasServerPrice) {
        return parseFloat(this.product.display_compare_price || 0)
      }
      return parseFloat(this.offeredPrice > 0 ? this.sellPrice : this.flashPrice ? this.sellPrice : 0)
    },
    currentPricing(){
      if (this.hasServerPrice) {
        return parseFloat(this.product.display_price)
      }
      return parseFloat(this.flashPrice !== null ? this.flashPrice
        : this.offeredPrice > 0 ? this.offeredPrice
          : this.sellPrice)
    },
    /*
     * Which side of VAT the advertised price sits on.
     *
     * This catalogue runs two conventions: most products are quoted inc-VAT,
     * and the B2B-facing ones are quoted ex-VAT with the tax added at
     * checkout. An unlabelled "€699" therefore means two different things
     * depending on the product, which is exactly the ambiguity a shopper
     * cannot resolve for themselves.
     *
     * The split is decided server-side (Pricing::vatBreakdown, fed through
     * ListingPrices) so a tile, the product page, the basket and the order all
     * quote the same figures.
     */
    hasVatSplit(){
      return !!(this.product
        && this.product.vat_rate !== undefined
        && this.product.vat_rate !== null
        && parseFloat(this.product.vat_rate) > 0)
    },
    /* "Inc-VAT" or "Ex-VAT", for the label beside the headline price. */
    vatSide(){
      if (!this.hasVatSplit) {
        return ''
      }

      return this.product.vat_inclusive ? 'Inc-VAT' : 'Ex-VAT'
    },
    /*
     * The gross figure, shown only when the headline is quoted ex-VAT.
     *
     * The two conventions are not symmetrical, so this is deliberately
     * one-directional. An ex-VAT headline does not tell a customer what they
     * will be charged, so the inc-VAT figure has to appear beside it. The
     * reverse is not true: an inc-VAT headline already *is* the amount that
     * gets charged, and printing its net equivalent underneath adds a second
     * price nobody is being asked to pay — which read as an "Ex-VAT" offer on
     * the 111 products whose prices already contain their VAT.
     *
     * Null on a zero-rated or margin-scheme line too: ex and inc are the same
     * number there, so a counterpart would just restate the headline.
     */
    vatCounterpart(){
      if (!this.hasVatSplit || this.product.vat_inclusive) {
        return null
      }

      return {
        label: 'Inc-VAT',
        amount: parseFloat(this.product.display_price_inc_vat),
        /* The tax inside that figure, and the rate it was charged at, so the
           line can read "Inc-VAT €859.77 (VAT 23% €160.77)". Both are the
           server's; the rate is never a constant here. */
        vat: parseFloat(this.product.display_price_vat),
        rate: parseFloat(this.product.vat_rate),
      }
    },
    vatAmount(){
      return this.hasVatSplit ? parseFloat(this.product.display_price_vat) : 0
    },
    /*
     * The same label for a basket or checkout line, which carries its own
     * treatment rather than the product's.
     *
     * The server prices each line and records which convention it used
     * (CartsController::repriceForCustomer), so a basket holding one ex-VAT
     * and one inc-VAT product labels each correctly instead of picking one
     * convention for the whole page.
     *
     * Only ever the label. The price beside it is the one being charged, so
     * its counterpart would be a second number nobody is paying — the same
     * rule vatCounterpart applies to a tile. Empty when no rate applies, where
     * naming a side would assert a treatment the line does not carry, and on
     * any component with no cart line at all.
     */
    cartVatDetail(){
      const rate = parseFloat(this.cart?.vat_rate || 0)

      /*
       * Nothing on a zero-rated or margin-scheme line: there is no tax to
       * describe, and naming one would assert a treatment the line does not
       * carry.
       */
      if (!(rate > 0)) {
        return null
      }

      const inc = parseFloat(this.cart.unit_inc_vat)
      const ex = parseFloat(this.cart.unit_ex_vat)
      const vat = parseFloat(this.cart.unit_vat)

      if (!Number.isFinite(inc)) {
        return null
      }

      /*
       * `inclusive` decides the wording, not the arithmetic — the split is the
       * server's either way. A gross price has its VAT taken out of it
       * ("VAT included"); a net one has it added on top ("VAT 23%"). Saying
       * the wrong one of those about a line is a 23%-of-the-VAT error that
       * looks perfectly plausible on screen.
       */
      return {
        amount: inc,
        ex: Number.isFinite(ex) ? ex : null,
        vat: Number.isFinite(vat) ? vat : null,
        rate,
        inclusive: !!this.cart.vat_inclusive,
      }
    },
    /*
     * Which convention this basket line is priced on, for a badge.
     *
     * Two words, because the badge is read at a glance and then never again:
     * it used to say "Ex-VAT product", and the extra word bought nothing that
     * the card around it does not already make obvious. The rest of the story
     * is in vatExplainer, behind the icon.
     *
     * Null when no rate applies — a zero-rated or margin-scheme line has no
     * side to name, and labelling one would assert a treatment it does not
     * carry.
     */
    cartVatBasis(){
      const rate = parseFloat(this.cart?.vat_rate || 0)

      if (!(rate > 0)) {
        return null
      }

      const inclusive = !!this.cart.vat_inclusive

      return {
        inclusive,
        short: inclusive ? 'Inc-VAT' : 'Ex-VAT',
      }
    },
    /*
     * What the badge's info icon opens: this line's own VAT split, in the
     * order the customer reads it for the convention it is priced on.
     *
     * It lives here rather than on the basket card because the checkout list
     * shows the same lines and has to say the same thing about them. It used
     * to be printed inline under both — "Inc-VAT €829.02 (VAT 23% €155.02)" —
     * which is the single biggest source of the repetition on the basket page:
     * the same tax stated on the card, again beside the line total, and again
     * in a tinted strip below it.
     *
     * Every figure is the server's: unit_ex_vat, unit_vat and unit_inc_vat off
     * the cart line (cartVatDetail). Nothing here applies a rate to anything,
     * and the fallback for a payload missing its net figure is the difference
     * between the two gross ones, not a division.
     *
     * "Price shown" is deliberately first in both: it is the figure on the
     * card, and naming it is what lets the rest be read as working rather than
     * as a second, competing set of prices.
     */
    vatExplainer(){
      const detail = this.cartVatDetail

      if (!detail || detail.vat === null) return null

      const rate = this.formatVatRate(detail.rate)
      const exVat = detail.ex !== null ? detail.ex : detail.amount - detail.vat

      if (detail.inclusive) {
        return {
          title: 'Inc-VAT pricing',
          label: 'About this product’s Inc-VAT price',
          lede: 'The price shown already includes VAT.',
          rows: [
            { label: 'Current price', value: this.money(detail.amount) },
            { label: 'VAT included', value: this.money(detail.vat) },
            { label: 'Ex-VAT value', value: this.money(exVat) },
          ],
          note: '',
        }
      }

      return {
        title: 'Ex-VAT pricing',
        label: 'About this product’s Ex-VAT price',
        lede: 'The price shown excludes VAT.',
        rows: [
          { label: 'Current price', value: this.money(exVat) },
          { label: 'VAT rate', value: rate },
          { label: 'Inc-VAT equivalent', value: this.money(detail.amount) },
        ],
        note: 'VAT is added at checkout using the tax rule that applies to this product.',
      }
    },
    /*
     * ---- the price for the quantity actually selected ------------------
     *
     * A listing price is decided at quantity 1: ListingPrices::decorate asks
     * Pricing::displayRange for the price of buying one, because a tile has no
     * quantity to speak of. That stops being true the moment a tile grows a
     * quantity stepper.
     *
     * Once the product is in the basket the server has priced that line
     * against the customer's entitlement AND its current quantity
     * (CartsController::action -> Pricing::unitPrice), and answered with what
     * it settled on. These prefer that answer and fall back to the listing
     * figures when there is no line — so a business account stepping 10 -> 11
     * sees the band it just crossed into, and a logged-out visitor sees
     * exactly what they saw before.
     *
     * No band logic here: the frontend never decides which tier applies, it
     * only displays the number the server returned. Components that do not
     * track a basket line (`cartEntry` undefined) are unaffected.
     */
    cartLine(){
      return this.cartEntry || null
    },
    cartLineUnitPrice(){
      if (!this.cartLine) {
        return null
      }

      const raw = this.cartLine.unit_price ?? this.cartLine.upsell_price
      const price = parseFloat(raw)

      return Number.isFinite(price) && price > 0 ? price : null
    },
    /* The headline figure: the line's unit price, else the listing price. */
    livePrice(){
      const priced = this.cartLineUnitPrice

      return priced !== null ? priced : this.currentPricing
    },
    /*
     * The struck-through "before" figure. On a priced line this is the retail
     * price the band undercuts (Pricing::listPrice), and it is only shown when
     * it is genuinely higher — a band that saves nothing must not render as a
     * discount.
     */
    livePrevPrice(){
      if (this.cartLineUnitPrice === null) {
        return this.prevPrice
      }

      const previous = parseFloat(this.cartLine.original_price)

      return Number.isFinite(previous) && previous > this.cartLineUnitPrice
        ? previous
        : 0
    },
    /* The badge, recomputed from whichever pair of figures is on screen. */
    liveReducedPercent(){
      return discountPercent(this.livePrevPrice, this.livePrice)
    },
    /*
     * The gross figure beside a net headline, on the same terms as
     * vatCounterpart — shown only when the price is quoted ex-VAT.
     *
     * On a priced line it has to come from that line's own split, because the
     * product-level one describes quantity 1. If the line has no split yet
     * (an older response, or a store row written before this shipped) it shows
     * nothing rather than pairing this quantity's net price with the previous
     * quantity's gross one.
     */
    liveVatCounterpart(){
      if (this.cartLineUnitPrice === null) {
        return this.vatCounterpart
      }

      const rate = parseFloat(this.cartLine.vat_rate || 0)

      if (!(rate > 0) || this.cartLine.vat_inclusive) {
        return null
      }

      const inc = parseFloat(this.cartLine.unit_inc_vat)
      const vat = parseFloat(this.cartLine.unit_vat)

      return Number.isFinite(inc)
        ? {
            label: 'Inc-VAT',
            amount: inc,
            vat: Number.isFinite(vat) ? vat : null,
            rate,
          }
        : null
    },
    /* "23%", from the product's own rule; never a constant. */
    vatRateLabel(){
      if (!this.hasVatSplit) {
        return null
      }

      return String(parseFloat(this.product.vat_rate).toFixed(2)).replace(/\.?0+$/, '') + '%'
    },
    // True when variants disagree on price, so the tile shows "From €x".
    isVariablePrice(){
      return !!(this.product && this.product.is_variable)
    },
    inventoryPrice(){
      //return parseFloat(this.inventory?.price) || 0

      if(this.inventory?.inventory_attributes.length > 0) {
        return parseFloat(this.inventory?.price) || 0
      }
      return 0
    },
    currentInventoryPrice(){
      // Flash first, matching Pricing::unitPrice() (QA audit PRC-01).
      if (this.flashPrice !== null) {
        return this.flashPrice
      }
      return parseFloat(this.inventoryPrice > 0 ? this.inventoryPrice : this.currentPricing)
    },
  },
  methods:{
    /* Exposed for the components that call it from a template. */
    discountPercent,
    /*
     * The same string <price-format :decimals="2"> renders, as plain text.
     *
     * A popover puts its figures in a <dd> rather than a component, so it
     * needs the formatted value — currency symbol, position and separators all
     * still come from the store's settings. priceFormat is util's; every
     * component that uses this mixin uses that one too.
     */
    money(value){
      return this.priceFormat(this.currencyPosition, this.currencyIcon, value, this.setting, 2)
    },
    /*
     * A rate as a percentage label: 23 -> "23%", 13.5 -> "13.5%".
     *
     * Always from a figure the server sent with the line. The catalogue runs
     * more than one rate and a zero-rated scheme besides, so a constant here
     * would be wrong for some products and invisible until an invoice was
     * checked.
     */
    formatVatRate(rate){
      const value = parseFloat(rate)

      if (!(value > 0)) {
        return ''
      }

      return String(value.toFixed(2)).replace(/\.?0+$/, '') + '%'
    },
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
      return parseFloat(this.offeredPriceCalc(product) > 0 ? this.sellPriceCalc(product)
        : this.flashPriceCalc(product)? this.sellPriceCalc(product) : 0)
    },
    currentInventoryPriceCalc(inventory, product){
      // Flash first, matching Pricing::unitPrice() (QA audit PRC-01).
      const flashPrice = this.flashPriceCalc(product)

      if (flashPrice !== null) {
        return parseFloat(flashPrice)
      }

      if(inventory?.inventory_attributes?.length > 0) {
        return parseFloat(inventory?.price || 0) > 0 ? (inventory?.price || 0) : this.currentPricingCalc(product)
      }

      return this.currentPricingCalc(product)
    },
  }
}
