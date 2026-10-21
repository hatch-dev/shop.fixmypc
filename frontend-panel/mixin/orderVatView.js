/*
 * How an order's money is stated to the customer reading it.
 *
 * In the basis the account that placed the order is billed in:
 *
 *   business / trade  net of VAT, the VAT on its own row, Total (Inc-VAT)
 *   personal          the price paid, VAT inside it, one "VAT included" note
 *   no VAT at all     the prices alone - a margin-scheme item is not
 *                     "0 in taxes", and the payload cannot tell it from an
 *                     untaxed one
 *
 * The total is the same either way; only the statement changes. Item rows and
 * the summary always share one basis - printing an ex-VAT product's own net
 * figures above a gross summary showed one discount as -€100 and -€123 on the
 * same screen.
 *
 * Every figure is the server's (OrderSummary::lines / decorate). Nothing here
 * works VAT out. A page supplies the order as a `vatOrder` computed.
 */
export default {
  computed: {
    /*
     * Read off the order, not the signed-in customer: the receipt is about
     * the account that placed it. Orders from before the snapshot have no
     * type and read as personal.
     */
    isBusinessOrder() {
      const type = this.vatOrder?.account_type

      return !!type && type !== 'personal'
    },
    orderCalc() {
      return this.vatOrder?.calculations || {}
    },
    orderHasVat() {
      return Number(this.orderCalc.vat) > 0
    },
    // The net statement applies only when there is VAT to separate out.
    orderIsNet() {
      return this.isBusinessOrder && this.orderHasVat
    },
    // "(Inc-VAT)" names a side, so it is only said when there is VAT.
    orderGrossTag() {
      return this.orderHasVat ? this.$t('ui.vatView.incTag') : ''
    },
    /*
     * One row per rate charged. A zero rate is left out: it is a margin
     * scheme or an untaxed line, and the payload cannot say which.
     */
    orderVatRows() {
      return (this.orderCalc.vat_breakdown || [])
        .filter(row => Number(row.rate) > 0 && Number(row.vat) > 0)
        .map(row => ({
          label: Number(Number(row.rate).toFixed(2)) + '%',
          vat: row.vat
        }))
    },
    // The goods block of the summary: list, discount, net of discount.
    orderGoods() {
      const c = this.orderCalc

      return this.orderIsNet
        ? { list: c.subtotal_ex_vat, discount: c.discount_ex_vat, net: c.ex_vat, tag: this.$t('ui.vatView.exTag') }
        : { list: c.subtotal_inc_vat, discount: c.discount_inc_vat, net: c.inc_vat, tag: this.orderGrossTag }
    },
    /*
     * What the payment badge says. A bank transfer that has not arrived is
     * not "Processing" - nothing has been received to process.
     */
    paymentBadge() {
      const order = this.vatOrder || {}

      if (Number(order.payment_done) === 1) {
        return { label: this.$t('ui.vatView.paid'), color: '#05B942' }
      }

      const cancelled = Number(order.cancelled) === 1 || [6, 8].includes(Number(order.status))

      if (!cancelled && Number(order.order_method) === 7) {
        return order.trans_id
          ? { label: this.$t('ui.vatView.checkingTransfer'), color: '#b45309' }
          : { label: this.$t('ui.vatView.awaitingTransfer'), color: '#b45309' }
      }

      return { label: this.$t('ui.vatView.notPaid'), color: '#dc3545' }
    }
  },
  methods: {
    /*
     * One item row in the order's basis. Falls back to the line's own
     * figures for a line the server could not decorate.
     */
    lineView(item) {
      const net = this.orderIsNet
      const pick = (exKey, incKey, fallback) => {
        const value = item?.[net ? exKey : incKey]

        return value ?? fallback
      }
      const vat = Number(item?.vat ?? item?.tax_total ?? 0)
      // The rate the line was charged at, as the server recorded it.
      const rate = Number(Number(item?.tax_rate || 0).toFixed(2))

      return {
        unit: pick('unit_ex_vat', 'unit_inc_vat', item?.price),
        unitList: pick('unit_list_ex_vat', 'unit_list_inc_vat', item?.actual_price),
        list: pick('list_ex_vat', 'list_inc_vat', Number(item?.actual_price || 0) * Number(item?.quantity || 0)),
        discount: Number(pick('discount_ex_vat', 'discount_inc_vat', item?.line_discount) || 0),
        // Its own row only in the net statement; inside the price otherwise.
        vat: net ? vat : 0,
        vatLabel: rate > 0 ? this.$t('ui.vatView.vatRate', { rate }) : this.$t('ui.vatView.vat'),
        exTag: net && vat > 0,
        total: pick('inc_vat', 'inc_vat', item?.line_total),
        totalTag: net && vat > 0 ? this.$t('ui.vatView.incTag') : ''
      }
    }
  }
}
