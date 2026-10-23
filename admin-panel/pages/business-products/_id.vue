<template>
    <data-page
        class="detail-width"
        ref="dataPage"
        set-api="setBusinessProduct"
        get-api="getBusinessProduct"
        route-name="business-products"
        :name="$t('pages.businessProducts.businessProduct')"
        :validation-keys="['product_id', 'pricing']"
        :result="result"
        @result="settingResult"
    >
        <template v-slot:form="{hasError}">
            <!-- PRODUCT SELECT -->
            <div class="card">
            <div class="input-wrapper">
                <label>{{ $t('pages.businessProducts.selectProduct') }}</label>
                <div
                    class="product-select"
                    :class="{open: productListOpen}"
                    v-outside-click="closeProductList"
                >
                    <div
                        class="product-field"
                        @click="focusSearch"
                    >
                        <svg class="field-icon" viewBox="0 0 20 20" aria-hidden="true">
                            <circle cx="9" cy="9" r="5.5" />
                            <line x1="13.2" y1="13.2" x2="17" y2="17" />
                        </svg>

                        <input
                            ref="productSearch"
                            type="text"
                            class="product-input"
                            :placeholder="$t('pages.businessProducts.searchProductsPlaceholder')"
                            autocomplete="off"
                            v-model="productQuery"
                            @focus="openProductList"
                            @keydown.down.prevent="moveHighlight(1)"
                            @keydown.up.prevent="moveHighlight(-1)"
                            @keydown.enter.prevent="selectHighlighted"
                            @keydown.esc="closeProductList"
                        >

                        <button
                            v-if="productQuery"
                            type="button"
                            class="field-btn clear"
                            :title="$t('pages.businessProducts.clear')"
                            @click.stop="clearProduct"
                        >
                            <svg viewBox="0 0 20 20" aria-hidden="true">
                                <line x1="6" y1="6" x2="14" y2="14" />
                                <line x1="14" y1="6" x2="6" y2="14" />
                            </svg>
                        </button>
                        <span
                            v-else
                            class="field-btn caret"
                        >
                            <svg viewBox="0 0 20 20" aria-hidden="true">
                                <polyline points="5.5,8 10,12.5 14.5,8" />
                            </svg>
                        </span>
                    </div>

                    <div
                        v-if="productListOpen"
                        class="product-options"
                    >
                        <div
                            v-if="filteredProducts.length"
                            class="options-head"
                        >
                            {{ $tc('pages.businessProducts.productCount', filteredProducts.length, { count: filteredProducts.length }) }}
                        </div>

                        <ul
                            v-if="filteredProducts.length"
                            ref="productOptions"
                            class="options-list"
                        >
                            <li
                                v-for="(p, i) in filteredProducts"
                                :key="p.id"
                                :class="{selected: p.id == result.product_id, highlighted: i === highlightIndex}"
                                @mousedown.prevent="selectProduct(p)"
                                @mouseenter="highlightIndex = i"
                            >
                                <span class="option-title"><em
                                    v-for="(part, pi) in highlightParts(p.title)"
                                    :key="pi"
                                    :class="{match: part.match}"
                                >{{ part.text }}</em></span>

                                <svg class="option-check" viewBox="0 0 20 20" aria-hidden="true">
                                    <polyline points="4.5,10.5 8.5,14.5 15.5,6" />
                                </svg>
                            </li>
                        </ul>

                        <div
                            v-if="!filteredProducts.length"
                            class="options-empty"
                        >
                            <svg viewBox="0 0 20 20" aria-hidden="true">
                                <circle cx="9" cy="9" r="5.5" />
                                <line x1="13.2" y1="13.2" x2="17" y2="17" />
                            </svg>
                            <p>{{ $t('pages.businessProducts.noProductsMatch', { query: productQuery.trim() }) }}</p>
                        </div>
                    </div>
                </div>
            </div>
            </div>

            <!-- PRICING TABLE -->
            <div v-if="result.product_id" class="card">

            <div class="pricing-table">

                <!-- HEADER -->
                <div class="pricing-row header">
                <div>{{ $t('pages.businessProducts.quantityRange') }}</div>
                <div>{{ $t('pages.businessProducts.offeredPrice') }}</div>
                <div>{{ $t('pages.businessProducts.discountType') }}</div>
                <div>{{ $t('pages.businessProducts.discountValue') }}</div>
                <div>{{ $t('pages.businessProducts.finalPrice') }}</div>
                </div>

                <!-- ROWS -->
                <div
                v-for="(row, index) in result.pricing"
                :key="index"
                class="pricing-row"
                >

                <!--
                    RANGE

                    The maximum is a field or the words "No limit", never a 0.
                    An empty box that happens to mean "and over" is a rule
                    staff have to be told; the tick box says it on screen, and
                    the chip alongside reads the range back as the storefront
                    will show it.
                -->
                <div class="range">
                    <input
                        type="number"
                        min="1"
                        v-model="row.min"
                        :placeholder="$t('pages.businessProducts.min')"
                    />
                    <span class="dash">&ndash;</span>

                    <input
                        v-if="!isOpenEnded(row)"
                        type="number"
                        min="1"
                        v-model="row.max"
                        :placeholder="$t('pages.businessProducts.max')"
                    />
                    <span v-else class="no-limit">{{ $t('pages.businessProducts.noLimit') }}</span>

                    <label
                        class="limit-toggle"
                        :title="$t('pages.businessProducts.noLimitTitle')"
                    >
                        <input
                            type="checkbox"
                            :checked="isOpenEnded(row)"
                            @change="setOpenEnded(row, $event.target.checked)"
                        />
                        <span>{{ $t('pages.businessProducts.noLimit') }}</span>
                    </label>

                    <span class="range-label">{{ rangeLabel(row) }}</span>
                </div>

                <!-- WHOLESALE -->
                <input
                    type="number"
                    step="0.01"
                    v-model="row.wholesale_price"
                    disabled
                />

                <!-- TYPE -->
                <div class="select-wrapper">
                    <select v-model="row.discount_type" @change="calculate(row)">
                        <option value="percentage">{{ $t('pages.businessProducts.percentage') }}</option>
                        <option value="fixed">{{ $t('pages.businessProducts.fixed') }}</option>
                    </select>
                    <span class="arrow">⌄</span>
                </div>

                <!-- VALUE -->
                <div class="discount-value">
                    <input
                    type="number"
                    step="0.01"
                    v-model="row.discount_value"
                    @input="handleDecimal(row, 'discount_value')"
                    />
                    <span v-if="row.discount_type === 'percentage'">%</span>
                    <span v-else>€</span>
                </div>

                <!-- FINAL -->
                <div class="final">
                    € {{ row.final_price.toFixed(2) }}
                </div>

                <button class="delete-btn" @click="removeRow(index)">
                    ✕
                </button>
                </div>

            </div>

            <!-- ADD ROW -->
            <button type="button" class="add-row-btn" @click="addRow()">
                {{ $t('pages.businessProducts.addRow') }}
            </button>

            <!--
                The same rules the server enforces, shown while they are still
                being typed. The save is still refused server-side if any of
                these are wrong - this is so the admin is not made to press
                Save to find out.
            -->
            <ul
                v-if="ladderIssues.length"
                class="ladder-issues"
                role="alert"
            >
                <li v-for="(issue, i) in ladderIssues" :key="i">
                    {{ issue }}
                </li>
            </ul>

            <!--
                Ticking No limit on the highest band is what keeps the ladder
                from ending in a cliff: a quantity above the last band matches
                no band at all and is charged the full retail price, so 31
                units came to more than 30. It is stored as max_qty 0, which is
                what BusinessProductPricing::coversQuantity reads as "and over".
            -->
            <p class="range-hint">
                <i18n path="pages.businessProducts.rangeHint" tag="span">
                    <template v-slot:noLimit><strong>{{ $t('pages.businessProducts.noLimit') }}</strong></template>
                </i18n>
            </p>

            </div>
        </template>
    </data-page>
</template>
<script>
import DataPage from '~/components/partials/DataPage'
import outsideClick from '~/directives/outside-click'
import Dropdown from '~/components/Dropdown'
import util from '~/mixin/util'
import { mapActions } from 'vuex'

export default {
    name: "business-products",
    middleware: ['common-middleware','auth'],
    mixins: [util],
    components: {
        DataPage,
        Dropdown
    },
    directives: { outsideClick },
  data(){
    return {
        products: [],
        productQuery: '',
        productListOpen: false,
        highlightIndex: -1,
        result: {
            id: '',
            product_id : '',
            pricing : []
        }
    }
  },
  computed: {
    selectedProduct() {
      return this.products.find(p => p.id == this.result.product_id) || null
    },
    searchTerms() {
      const query = this.productQuery.trim().toLowerCase()
      // A query still mirroring the selected title counts as 'not searching',
      // so reopening the list behaves like the old select did.
      if (!query || query === (this.selectedProduct?.title || '').toLowerCase()) return []
      return [...new Set(query.split(/\s+/).filter(Boolean))]
    },
    filteredProducts() {
      const words = this.searchTerms
      if (!words.length) return this.products
      return this.products.filter(p => {
        const title = (p.title || '').toLowerCase()
        return words.every(w => title.includes(w))
      })
    },
    /*
     * The ladder's structural faults, in the wording the server uses.
     *
     * BusinessProductsController::bandError is the authority and refuses the
     * save regardless of what this says; these exist so the admin sees the
     * problem while the row is still under the cursor. They are deliberately
     * the same four rules in the same order, so the two never disagree about
     * which fault a ladder has.
     */
    ladderIssues() {
      const bands = []

      for (let i = 0; i < this.result.pricing.length; i++) {
        const row = this.result.pricing[i]
        const line = i + 1
        const min = parseInt(row.min, 10)

        // A half-typed row is not yet a fault; it is a row being typed.
        if (row.min === '' || row.min === null) continue

        if (!Number.isFinite(min) || min < 1) {
          return [this.$t('pages.businessProducts.issueMinAtLeastOne', { line })]
        }

        const open = this.isOpenEnded(row)
        const max = parseInt(row.max, 10)

        if (!open && max < min) {
          return [this.$t('pages.businessProducts.issueMaxBelowMin', { line })]
        }

        bands.push({line, min, max: open ? 0 : max, open})
      }

      const openBands = bands.filter(band => band.open)

      if (openBands.length > 1) {
        return [
          this.$t('pages.businessProducts.issueTwoOpen', { first: openBands[0].line, second: openBands[1].line })
        ]
      }

      if (openBands.length === 1) {
        const top = openBands[0]
        const above = bands.find(band => band.min > top.min)

        if (above) {
          return [
            this.$t('pages.businessProducts.issueOpenNotLast', { line: top.line, min: top.min, above: above.line })
          ]
        }
      }

      for (let i = 0; i < bands.length; i++) {
        for (let j = 0; j < i; j++) {
          const band = bands[i]
          const other = bands[j]
          const otherMax = other.open ? Infinity : other.max
          const thisMax = band.open ? Infinity : band.max

          if (band.min <= otherMax && other.min <= thisMax) {
            return [this.$t('pages.businessProducts.issueOverlap', { line: band.line, other: other.line })]
          }
        }
      }

      const ordered = bands.slice().sort((a, b) => a.min - b.min)

      for (let i = 1; i < ordered.length; i++) {
        const previous = ordered[i - 1]
        const current = ordered[i]
        const expected = previous.max + 1

        if (current.min > expected) {
          const missingTo = current.min - 1
          const missing = expected === missingTo
            ? this.$t(expected === 1 ? 'pages.businessProducts.unitOne' : 'pages.businessProducts.unitMany', { count: expected })
            : this.$t('pages.businessProducts.unitRange', { from: expected, to: missingTo })

          return [
            this.$t('pages.businessProducts.issueGap', { line: current.line, min: current.min, previous: previous.line, max: previous.max, missing })
          ]
        }
      }

      // Not a refusal - the server stores a ladder that stops - but it is the
      // cliff this screen exists to keep out, so it is said out loud.
      if (bands.length && !openBands.length) {
        const top = bands.reduce((a, b) => (b.min > a.min ? b : a))

        return [
          this.$t('pages.businessProducts.issueNoOpenTop', { max: top.max, next: top.max + 1, line: top.line })
        ]
      }

      return []
    }
  },
  watch: {
    'result.product_id'() {
      this.productQuery = this.selectedProduct?.title || ''
    }
  },
  methods: {
    focusSearch() {
      if (this.$refs.productSearch) this.$refs.productSearch.focus()
    },
    // Splits a title into plain/matched runs so the typed words can be emphasised.
    highlightParts(title) {
      const text = title || ''
      const words = this.searchTerms
      if (!words.length) return [{text, match: false}]

      const lower = text.toLowerCase()
      const hits = []
      words.forEach(word => {
        let at = lower.indexOf(word)
        while (at !== -1) {
          hits.push([at, at + word.length])
          at = lower.indexOf(word, at + word.length)
        }
      })
      if (!hits.length) return [{text, match: false}]

      hits.sort((a, b) => a[0] - b[0])
      const merged = []
      hits.forEach(hit => {
        const last = merged[merged.length - 1]
        if (last && hit[0] <= last[1]) last[1] = Math.max(last[1], hit[1])
        else merged.push(hit.slice())
      })

      const parts = []
      let cursor = 0
      merged.forEach(([from, to]) => {
        if (from > cursor) parts.push({text: text.slice(cursor, from), match: false})
        parts.push({text: text.slice(from, to), match: true})
        cursor = to
      })
      if (cursor < text.length) parts.push({text: text.slice(cursor), match: false})
      return parts
    },
    openProductList() {
      this.productListOpen = true
      this.highlightIndex = this.filteredProducts.findIndex(p => p.id == this.result.product_id)
      this.$nextTick(() => this.scrollToHighlight())
    },
    closeProductList() {
      this.productListOpen = false
      this.highlightIndex = -1
      // Drop a half-typed query so the field always shows the real selection.
      this.productQuery = this.selectedProduct?.title || ''
    },
    selectProduct(product) {
      this.result.product_id = product.id
      this.productQuery = product.title || ''
      this.productListOpen = false
      this.highlightIndex = -1
      this.onProductChange()
    },
    selectHighlighted() {
      if (!this.productListOpen) return this.openProductList()
      const product = this.filteredProducts[this.highlightIndex]
      if (product) this.selectProduct(product)
    },
    moveHighlight(step) {
      if (!this.productListOpen) return this.openProductList()
      const total = this.filteredProducts.length
      if (!total) return
      this.highlightIndex = (this.highlightIndex + step + total) % total
      this.scrollToHighlight()
    },
    scrollToHighlight() {
      const list = this.$refs.productOptions
      const item = list && list.children[this.highlightIndex]
      if (item && item.scrollIntoView) item.scrollIntoView({block: 'nearest'})
    },
    clearProduct() {
      this.result.product_id = ''
      this.productQuery = ''
      this.highlightIndex = -1
      this.productListOpen = true
      if (this.$refs.productSearch) this.$refs.productSearch.focus()
    },
    onProductChange() {
      const product = this.products.find(p => p.id == this.result.product_id);
      if (!product) return;
      const basePrice = Number(product.offered ?? 0);
      if (this.result.pricing.length === 0) {
          this.addRow(basePrice);
      }
      this.result.pricing.forEach(row => {
        row.wholesale_price = basePrice;
        this.calculate(row);
      });
    },
    settingResult(evt) {
        this.result = {
            id: evt.product_id,
            product_id: evt.product_id || '',
            pricing: evt.pricing || []
        };

        this.result.pricing.forEach(row => {
            row.wholesale_price = Number(row.wholesale_price ?? 0);
            row.discount_value = Number(row.discount_value ?? 0);
            row.final_price = Number(row.final_price ?? 0);
            // An open-ended band is stored as max_qty 0. Showing that as a
            // literal 0 reads as "a maximum of nothing" and invites staff to
            // replace it with a real number, which is what put the cliff
            // above 30 there in the first place.
            row.max = this.isOpenEnded(row) ? '' : row.max;

            this.calculate(row); // recalc safely
        });
    },
    handleDecimal(row, field) {
        let value = row[field];
        if (value === '' || value === null) return;
        value = parseFloat(value);
        if (!isNaN(value)) {
            row[field] = Math.round(value * 100) / 100;
        }
        this.calculate(row);
    },
    removeRow(index) {
        this.result.pricing.splice(index, 1);
    },
    /*
     * A band is open-ended when no maximum was given: blank, or the 0 the
     * store uses for it.
     *
     * Deliberately stricter than BusinessProductPricing::coversQuantity, which
     * also reads a maximum below the minimum as "and over" so a legacy row
     * cannot price nothing. On an editing screen that tolerance is a trap: it
     * turns "21 - 5", which is a typo, into an open-ended band the admin never
     * asked for and cannot see they have written. Here it is reported as the
     * mistake it is, and the server refuses it for the same reason.
     */
    isOpenEnded(row) {
        const max = parseInt(row && row.max, 10);
        return !Number.isFinite(max) || max <= 0;
    },
    // Echoes the range back in the form the storefront shows it, so a band
    // meant to read "31+" cannot be saved looking like "31 - 0".
    rangeLabel(row) {
        const min = parseInt(row && row.min, 10);
        if (!Number.isFinite(min)) return '';
        return this.isOpenEnded(row) ? `${min}+` : `${min} - ${parseInt(row.max, 10)}`;
    },
    /*
     * Ticking No limit clears the maximum; clearing it has to put a usable
     * number back, or the row stays open-ended in everything but the tick box
     * and the save is refused for a reason the screen is not showing.
     */
    setOpenEnded(row, open) {
        if (open) {
            row.max = '';
            return;
        }

        const min = parseInt(row.min, 10);

        row.max = Number.isFinite(min) && min > 0 ? min : 1;
    },
    async fetchProducts() {
        const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/';
        const response = await this.$axios.get(`${baseUrl}api/admin/product/with-categories`);
        this.products = response.data.products;
        this.productQuery = (this.selectedProduct && this.selectedProduct.title) || '';
    },
    /*
     * A new band goes on the end of the ladder, so there has to be an end to
     * put it on. An open-ended band already covers every quantity above its
     * minimum; a row after it could never apply, and saving the pair is
     * refused. Saying so here is the difference between a rule the admin can
     * follow and an error they meet after typing four figures.
     */
    addRow(price = null) {
      const openIndex = this.result.pricing.findIndex(row => this.isOpenEnded(row));

      if (openIndex !== -1) {
          const label = this.rangeLabel(this.result.pricing[openIndex]) || this.$t('pages.businessProducts.theOpenEndedBand');

          this.$store.dispatch(
              'ui/setToastError',
              this.$t('pages.businessProducts.openTierNotLast', { label })
          );

          return;
      }

      if (price === null) {
          const product = this.products.find(p => p.id == this.result.product_id);
          price = Number(product?.offered ?? 0);
      }

      const safePrice = Number(price);
      const row = {
          // Start where the ladder left off. Bands have to run on from one
          // another without a gap, and the commonest way to make one is to
          // type the next minimum from memory.
          min: this.nextMinimum(),
          max: '',
          wholesale_price: isNaN(safePrice) ? 0 : safePrice,
          discount_type: 'fixed',
          discount_value: 0,
          final_price: isNaN(safePrice) ? 0 : safePrice
      };

      this.calculate(row);

      this.result.pricing.push(row);
    },
    /* One above the highest maximum on the ladder, or blank for the first row. */
    nextMinimum() {
      let highest = null;

      this.result.pricing.forEach(row => {
          const max = parseInt(row.max, 10);

          if (Number.isFinite(max) && max > 0 && (highest === null || max > highest)) {
              highest = max;
          }
      });

      return highest === null ? '' : highest + 1;
    },
    calculate(row) {
        let price = Number(row.wholesale_price) || 0;
        let discount = Number(row.discount_value) || 0;

        if (row.discount_type === 'percentage') {
          row.final_price = price - (price * discount / 100);
        } else {
          row.final_price = price - discount;
        }

        if (row.final_price < 0) {
          row.final_price = 0;
        }
        row.final_price = Number(row.final_price);
    }
  },
  mounted(){
    this.fetchProducts();
  },
}
</script>
<style scoped>

.detail-width {
  max-width: 900px;
  margin: auto;
}

/* CARD */
.card {
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  padding: 20px;
  background: #fff;
  margin-bottom: 16px;
}

/* INPUT */
.input-wrapper {
  margin-bottom: 10px;
}

.input-wrapper label {
  display: block;
  margin-bottom: 6px;
  font-weight: 500;
}

.input {
  width: 100%;
  height: 36px;
  border: 1px solid #d1d5db;
  border-radius: 6px;
  padding: 0 10px;
}

/* PRODUCT SEARCH */
.product-select {
  position: relative;
}

.product-field {
  display: flex;
  align-items: center;
  gap: 8px;
  height: 42px;
  padding: 0 6px 0 12px;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  background: #fff;
  cursor: text;
  transition: border-color .15s ease, box-shadow .15s ease;
}

.product-field:hover {
  border-color: #b6c0ff;
}

.product-select.open .product-field,
.product-field:focus-within {
  border-color: #4f6ef7;
  box-shadow: 0 0 0 3px rgba(79,110,247,0.12);
}

.field-icon {
  flex: 0 0 18px;
  width: 18px;
  height: 18px;
  fill: none;
  stroke: #9ca3af;
  stroke-width: 1.6;
  stroke-linecap: round;
}

.product-select.open .field-icon {
  stroke: #4f6ef7;
}

.product-input {
  flex: 1 1 auto;
  min-width: 0;
  width: 100%;
  height: 100%;
  /* base.styl gives every input its own padding/height/border - undo it here */
  padding: 0;
  line-height: 1.2;
  border: 0;
  border-radius: 0;
  outline: none;
  background: transparent;
  font-size: 14px;
  color: #111827;
}

.product-input::placeholder {
  color: #9ca3af;
}

.field-btn {
  flex: 0 0 26px;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 26px;
  height: 26px;
  padding: 0;
  border: 0;
  background: transparent;
  border-radius: 6px;
}

.field-btn svg {
  width: 16px;
  height: 16px;
  fill: none;
  stroke: #9ca3af;
  stroke-width: 1.7;
  stroke-linecap: round;
  stroke-linejoin: round;
}

.field-btn.clear {
  cursor: pointer;
}

.field-btn.clear:hover {
  background: #f3f4f6;
}

.field-btn.clear:hover svg {
  stroke: #ef4444;
}

.field-btn.caret {
  pointer-events: none;
}

.product-select.open .field-btn.caret svg {
  transform: rotate(180deg);
}

/* OPTIONS */
.product-options {
  position: absolute;
  z-index: 20;
  top: calc(100% + 6px);
  left: 0;
  right: 0;
  overflow: hidden;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  box-shadow: 0 12px 28px rgba(17,24,39,0.12);
}

.options-head {
  padding: 8px 14px;
  border-bottom: 1px solid #f1f2f4;
  background: #fafbfc;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: .04em;
  text-transform: uppercase;
  color: #9ca3af;
}

.options-list {
  max-height: 264px;
  overflow-y: auto;
  margin: 0;
  padding: 4px;
  list-style: none;
}

.options-list::-webkit-scrollbar {
  width: 8px;
}

.options-list::-webkit-scrollbar-thumb {
  background: #dfe3e8;
  border: 2px solid #fff;
  border-radius: 8px;
}

.options-list li {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  padding: 9px 12px;
  border-radius: 6px;
  font-size: 14px;
  line-height: 1.35;
  color: #374151;
  cursor: pointer;
  transition: background .12s ease;
}

.options-list li.highlighted {
  background: #f2f5ff;
  color: #1f2937;
}

.options-list li.selected {
  color: #4f6ef7;
  font-weight: 600;
}

.option-title {
  display: block;
  flex: 1 1 auto;
  min-width: 0;
}

.option-title em {
  display: inline;
  font-style: normal;
}

.option-title em.match {
  font-weight: 700;
  color: #4f6ef7;
  background: rgba(79,110,247,0.12);
  border-radius: 3px;
}

.option-check {
  flex: 0 0 16px;
  width: 16px;
  height: 16px;
  fill: none;
  stroke: #4f6ef7;
  stroke-width: 2;
  stroke-linecap: round;
  stroke-linejoin: round;
  opacity: 0;
}

.options-list li.selected .option-check {
  opacity: 1;
}

/* EMPTY */
.options-empty {
  padding: 26px 16px;
  text-align: center;
  color: #9ca3af;
}

.options-empty svg {
  width: 26px;
  height: 26px;
  margin-bottom: 8px;
  fill: none;
  stroke: #d1d5db;
  stroke-width: 1.4;
  stroke-linecap: round;
}

.options-empty p {
  margin: 0;
  font-size: 13px;
  word-break: break-word;
}

/* TABLE */
.pricing-table {
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  overflow: hidden;
}

.pricing-row {
  /* The range column carries two fields, a tick box and the chip, so it gets
     the room rather than wrapping under the offered price. */
  display: grid;
  grid-template-columns: 2.4fr 1fr 1fr 1fr 1fr 50px;
  gap: 10px;
  padding: 12px;
  align-items: center;
  border-bottom: 1px solid #f1f1f1;
}

.pricing-row.header {
  background: #f9fafb;
  font-weight: 600;
  font-size: 13px;
}

/* RANGE */
.range {
  display: flex;
  align-items: center;
  gap: 6px;
}

.range input {
  width: 75px;
}

.range-label {
  flex: 0 0 auto;
  padding: 2px 7px;
  border-radius: 10px;
  background: #f2f5ff;
  font-size: 12px;
  font-weight: 600;
  color: #4f6ef7;
  white-space: nowrap;
}

/* The maximum, when there is not one. Sized like the field it stands in for
   so the row does not shift as the tick box is used. */
.no-limit {
  display: flex;
  align-items: center;
  justify-content: center;
  flex: 0 0 75px;
  width: 75px;
  height: 38px;
  border: 1px dashed #c7d0ff;
  border-radius: 8px;
  background: #f7f9ff;
  font-size: 12px;
  font-weight: 600;
  color: #4f6ef7;
  white-space: nowrap;
}

.limit-toggle {
  display: flex;
  align-items: center;
  gap: 5px;
  margin: 0;
  font-size: 12px;
  color: #4b5563;
  white-space: nowrap;
  cursor: pointer;
  user-select: none;
}

.limit-toggle input {
  width: auto;
  height: auto;
  margin: 0;
  cursor: pointer;
}

.dash {
  color: #9ca3af;
}

.ladder-issues {
  margin: 12px 0 0;
  padding: 10px 12px 10px 28px;
  border: 1px solid #fecaca;
  border-radius: 8px;
  background: #fef2f2;
  color: #b91c1c;
  font-size: 12.5px;
  line-height: 1.55;
}

.ladder-issues li + li {
  margin-top: 4px;
}

.range-hint {
  margin: 10px 2px 0;
  font-size: 12px;
  line-height: 1.5;
  color: #6b7280;
}

.range-hint strong {
  color: #374151;
}

/* DISCOUNT */
.discount-value {
  display: flex;
  align-items: center;
  gap: 6px;
}

/* FINAL */
.final {
  font-weight: 600;
}

/* BUTTON */
.add-row-btn {
  margin-top: 12px;
  border: 1px dashed #4f6ef7;
  background: #fff;
  width: 100%;
  cursor: pointer;
  border-radius: 6px;
  color: #4f6ef7;
}

.add-row-btn:hover {
  background: #f5f7ff;
}
.select-wrapper {
  position: relative;
  width: 100%;
}

.select-wrapper select {
  width: 100%;
  height: 38px;
  padding: 0 35px 0 10px;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  background: #fff;
  appearance: none;
  font-size: 14px;
  cursor: pointer;
  transition: all 0.2s ease;
}

/* Hover + Focus */
.select-wrapper select:hover {
  border-color: #4f6ef7;
}

.select-wrapper select:focus {
  outline: none;
  border-color: #4f6ef7;
  box-shadow: 0 0 0 2px rgba(79,110,247,0.1);
}

/* Arrow */
.select-wrapper .arrow {
  position: absolute;
  right: 10px;
  top: 50%;
  transform: translateY(-50%);
  pointer-events: none;
  font-size: 14px;
  color: #6b7280;
}

.delete-btn:hover {
  background: #fecaca;
}
</style>