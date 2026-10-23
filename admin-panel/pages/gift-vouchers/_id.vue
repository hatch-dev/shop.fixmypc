<template>
  <data-page
    ref="dataPage"
    set-api="setGiftVoucher"
    get-api="getGiftVoucher"
    route-name="gift-vouchers"
    :name="$t('pages.giftVouchers.listName')"
    :validation-keys="['title', 'min_quantity', 'max_quantity']"
    :result="result"
    gate="gift_voucher"
    @result="result = $event"
  >

    <!-- ══════════ main column ══════════ -->
    <template v-slot:form="{hasError, fieldErrors}">

      <!-- Details -->
      <section class="gv-section">
        <!-- a div: the admin styles every <header> as the fixed top bar -->
        <div class="gv-section-head">
          <span class="gv-step">1</span>
          <div>
            <h3 class="gv-section-title">{{ $t('pages.giftVouchers.detailsTitle') }}</h3>
            <p class="gv-section-sub">{{ $t('pages.giftVouchers.detailsSub') }}</p>
          </div>
        </div>

        <div class="input-wrapper" data-field="title">
          <label for="gv-title">{{ $t('pages.giftVouchers.titleLabel') }}</label>
          <input
            id="gv-title"
            v-model="result.title"
            type="text"
            maxlength="255"
            :placeholder="$t('pages.giftVouchers.titlePlaceholder')"
            :class="{invalid: !result.title && hasError}"
          >
          <div class="gv-field-foot">
            <span v-if="!result.title && hasError" class="error">{{ $t('pages.giftVouchers.titleRequired') }}</span>
            <field-error v-else :messages="fieldErrors && fieldErrors.title" />
            <span class="gv-count">{{ (result.title || '').length }}/255</span>
          </div>
        </div>

        <div class="input-wrapper" data-field="description">
          <label for="gv-desc">{{ $t('pages.giftVouchers.descriptionLabel') }} <span class="gv-optional">{{ $t('pages.giftVouchers.optional') }}</span></label>
          <textarea
            id="gv-desc"
            v-model="result.description"
            rows="3"
            maxlength="500"
            :placeholder="$t('pages.giftVouchers.descriptionPlaceholder')"
          ></textarea>
          <div class="gv-field-foot">
            <field-error :messages="fieldErrors && fieldErrors.description" />
            <span class="gv-count">{{ (result.description || '').length }}/500</span>
          </div>
        </div>
      </section>

      <!-- Values -->
      <section class="gv-section" data-field="amounts">
        <!-- a div: the admin styles every <header> as the fixed top bar -->
        <div class="gv-section-head">
          <span class="gv-step">2</span>
          <div>
            <h3 class="gv-section-title">{{ $t('pages.giftVouchers.valuesTitle') }}</h3>
            <p class="gv-section-sub">{{ $t('pages.giftVouchers.valuesSub') }}</p>
          </div>
        </div>

        <ul v-if="sortedAmounts.length" class="gv-chips" :aria-label="$t('pages.giftVouchers.valuesAria')">
          <li v-for="amt in sortedAmounts" :key="amt" class="gv-chip">
            <span>{{ currencyIcon }}{{ formatAmount(amt) }}</span>
            <button
              type="button"
              class="gv-chip-x"
              :aria-label="$t('pages.giftVouchers.removeAmountAria', { amount: currencyIcon + formatAmount(amt) })"
              :disabled="sortedAmounts.length === 1"
              :title="sortedAmounts.length === 1 ? $t('pages.giftVouchers.needOneValue') : $t('pages.giftVouchers.remove')"
              @click="removeAmount(amt)"
            >
              <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
          </li>
        </ul>
        <p v-else class="gv-empty">{{ $t('pages.giftVouchers.noValues') }}</p>

        <div class="gv-add-row">
          <div class="gv-money">
            <span class="gv-money-sym">{{ currencyIcon }}</span>
            <input
              v-model="newAmount"
              type="number"
              min="1"
              max="5000"
              step="0.01"
              inputmode="decimal"
              :placeholder="$t('pages.giftVouchers.addValuePlaceholder')"
              :aria-label="$t('pages.giftVouchers.newValueAria')"
              @keydown.enter.prevent="addAmount()"
            >
          </div>
          <button type="button" class="gv-btn gv-btn-primary" :disabled="!canAdd" @click="addAmount()">
            <i class="fa-solid fa-plus" aria-hidden="true"></i> {{ $t('pages.giftVouchers.add') }}
          </button>
        </div>
        <p v-if="amountHint" class="gv-hint gv-hint-warn">{{ amountHint }}</p>

        <div v-if="presets.length" class="gv-presets">
          <span class="gv-presets-label">{{ $t('pages.giftVouchers.quickAdd') }}</span>
          <button
            v-for="p in presets"
            :key="p"
            type="button"
            class="gv-preset"
            @click="addAmount(p)"
          >
            + {{ currencyIcon }}{{ p }}
          </button>
        </div>

        <field-error :messages="fieldErrors && fieldErrors.amounts" />
      </section>

      <!-- Quantity -->
      <section class="gv-section">
        <!-- a div: the admin styles every <header> as the fixed top bar -->
        <div class="gv-section-head">
          <span class="gv-step">3</span>
          <div>
            <h3 class="gv-section-title">{{ $t('pages.giftVouchers.qtyTitle') }}</h3>
            <p class="gv-section-sub">{{ $t('pages.giftVouchers.qtySub') }}</p>
          </div>
        </div>

        <div class="gv-qty-grid">
          <div class="input-wrapper" data-field="min_quantity">
            <label>{{ $t('pages.giftVouchers.minimum') }}</label>
            <div class="gv-stepper" :class="{invalid: minInvalid && hasError}">
              <button type="button" :aria-label="$t('pages.giftVouchers.decreaseMin')" :disabled="result.min_quantity <= 1" @click="step('min_quantity', -1)">−</button>
              <input v-model.number="result.min_quantity" type="number" min="1" max="100" :aria-label="$t('pages.giftVouchers.minimumCards')">
              <button type="button" :aria-label="$t('pages.giftVouchers.increaseMin')" :disabled="result.min_quantity >= result.max_quantity" @click="step('min_quantity', 1)">+</button>
            </div>
          </div>

          <div class="input-wrapper" data-field="max_quantity">
            <label>{{ $t('pages.giftVouchers.maximum') }}</label>
            <div class="gv-stepper" :class="{invalid: isMaxInvalid && hasError}">
              <button type="button" :aria-label="$t('pages.giftVouchers.decreaseMax')" :disabled="result.max_quantity <= result.min_quantity" @click="step('max_quantity', -1)">−</button>
              <input v-model.number="result.max_quantity" type="number" min="1" max="100" :aria-label="$t('pages.giftVouchers.maximumCards')">
              <button type="button" :aria-label="$t('pages.giftVouchers.increaseMax')" :disabled="result.max_quantity >= 100" @click="step('max_quantity', 1)">+</button>
            </div>
          </div>
        </div>

        <p class="gv-hint" :class="{'gv-hint-warn': minInvalid || isMaxInvalid}">
          <template v-if="minInvalid">{{ $t('pages.giftVouchers.minAtLeastOne') }}</template>
          <template v-else-if="isMaxInvalid">{{ $t('pages.giftVouchers.maxBelowMin') }}</template>
          <template v-else-if="result.min_quantity === result.max_quantity">
            {{ $tc('pages.giftVouchers.buyExactly', result.min_quantity, { count: result.min_quantity }) }}
          </template>
          <template v-else>
            {{ $t('pages.giftVouchers.buyRange', { min: result.min_quantity, max: result.max_quantity }) }}
          </template>
        </p>
      </section>
    </template>

    <!-- ══════════ sidebar ══════════ -->
    <template v-slot:sidebar>

      <!-- Availability -->
      <div class="gv-side-card">
        <div class="gv-side-row">
          <div>
            <h4 class="gv-side-title">{{ $t('pages.giftVouchers.onSale') }}</h4>
            <p class="gv-side-sub">
              {{ result.is_active ? $t('pages.giftVouchers.onSaleYes') : $t('pages.giftVouchers.onSaleNo') }}
            </p>
          </div>
          <button
            type="button"
            class="gv-switch"
            role="switch"
            :aria-checked="result.is_active ? 'true' : 'false'"
            :aria-label="$t('pages.giftVouchers.onSale')"
            @click="result.is_active = !result.is_active"
          >
            <span class="gv-switch-knob"></span>
          </button>
        </div>
      </div>

      <!-- Image -->
      <div class="gv-side-card">
        <h4 class="gv-side-title">{{ $t('pages.giftVouchers.cardImage') }}</h4>

        <label
          class="gv-drop"
          :class="{dragging, 'has-image': hasImage}"
          @dragover.prevent="dragging = true"
          @dragleave.prevent="dragging = false"
          @drop.prevent="onDrop"
        >
          <input type="file" accept="image/png,image/jpeg,image/webp,image/gif" class="gv-file" @change="uploadImage">

          <!-- shown only once it has actually loaded: a pending or failed request is a 0px image -->
          <template v-if="hasImage && !imageBroken">
            <img
              v-show="imageLoaded"
              :src="imagePreview"
              :alt="$t('pages.giftVouchers.cardImage')"
              class="gv-drop-img"
              @load="imageLoaded = true"
              @error="imageBroken = true"
            >
            <template v-if="!imageLoaded">
              <span class="gv-drop-icon gv-pulse"><i class="fa-regular fa-image" aria-hidden="true"></i></span>
              <span class="gv-drop-text">{{ $t('pages.giftVouchers.loadingImage') }}</span>
              <span class="gv-drop-sub">{{ result.image.startsWith('data:') ? $t('pages.giftVouchers.newUpload') : result.image }}</span>
            </template>
          </template>
          <template v-else-if="hasImage && imageBroken">
            <span class="gv-drop-icon"><i class="fa-regular fa-image" aria-hidden="true"></i></span>
            <span class="gv-drop-text">{{ $t('pages.giftVouchers.imageNotFound') }}</span>
            <span class="gv-drop-sub">{{ result.image }}</span>
          </template>
          <template v-else>
            <span class="gv-drop-icon"><i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i></span>
            <span class="gv-drop-text">{{ $t('pages.giftVouchers.dropImageOr') }} <u>{{ $t('pages.giftVouchers.browse') }}</u></span>
            <span class="gv-drop-sub">{{ $t('pages.giftVouchers.imageFormats') }}</span>
          </template>
        </label>

        <div v-if="hasImage" class="gv-image-actions">
          <label class="gv-btn gv-btn-ghost">
            <input type="file" accept="image/png,image/jpeg,image/webp,image/gif" class="gv-file" @change="uploadImage">
            {{ $t('pages.giftVouchers.replace') }}
          </label>
          <button type="button" class="gv-btn gv-btn-danger" @click="removeImage">{{ $t('pages.giftVouchers.remove') }}</button>
        </div>
      </div>

      <!-- Live preview -->
      <div class="gv-side-card gv-preview-card">
        <h4 class="gv-side-title">{{ $t('pages.giftVouchers.shopPreview') }}</h4>

        <div class="gv-preview" :class="{withdrawn: !result.is_active}">
          <div class="gv-preview-img">
            <img v-if="hasImage && !imageBroken && imageLoaded" :src="imagePreview" alt="">
            <div v-else class="gv-preview-fallback">
              <i class="fa-solid fa-gift" aria-hidden="true"></i>
            </div>
            <span v-if="!result.is_active" class="gv-preview-badge">{{ $t('pages.giftVouchers.withdrawn') }}</span>
          </div>
          <div class="gv-preview-body">
            <strong class="gv-preview-title">{{ result.title || $t('pages.giftVouchers.previewTitle') }}</strong>
            <span class="gv-preview-desc">{{ result.description || $t('pages.giftVouchers.previewDescription') }}</span>
            <div class="gv-preview-chips">
              <span v-for="amt in sortedAmounts.slice(0, 6)" :key="amt">{{ currencyIcon }}{{ formatAmount(amt) }}</span>
              <span v-if="sortedAmounts.length > 6" class="more">+{{ sortedAmounts.length - 6 }}</span>
            </div>
            <span class="gv-preview-qty">
              {{ $t('pages.giftVouchers.previewQty', { qty: result.min_quantity === result.max_quantity ? result.min_quantity : `${result.min_quantity}–${result.max_quantity}` }) }}
            </span>
          </div>
        </div>
      </div>
    </template>
  </data-page>
</template>

<script>
import DataPage from '~/components/partials/DataPage'
import FieldError from '~/components/FieldError'
import util from '~/mixin/util'
import {mapGetters} from 'vuex'

export default {
  name: 'gift-voucher',
  middleware: ['common-middleware', 'auth'],

  data() {
    return {
      result: {
        id: '',
        title: '',
        image: '',
        description: '',
        amounts: [25, 50, 100],
        min_quantity: 1,
        max_quantity: 10,
        is_active: true
      },
      newAmount: '',
      dragging: false,
      imageBroken: false,
      imageLoaded: false
    }
  },

  mixins: [util],
  components: {DataPage, FieldError},

  computed: {
    ...mapGetters('setting', ['setting']),
    currencyIcon() {
      return this.setting?.currency_icon || '€'
    },
    // Numbers, de-duplicated, smallest first: the order the shop shows them in.
    sortedAmounts() {
      return [...new Set((this.result.amounts || [])
        .map(a => Math.round(parseFloat(a) * 100) / 100)
        .filter(a => a > 0))]
        .sort((a, b) => a - b)
    },
    parsedNew() {
      const v = Math.round(parseFloat(this.newAmount) * 100) / 100
      return isNaN(v) ? null : v
    },
    canAdd() {
      return this.parsedNew !== null && !this.amountHint
    },
    amountHint() {
      if (this.newAmount === '' || this.newAmount === null) return ''
      if (this.parsedNew === null || this.parsedNew < 1) return this.$t('pages.giftVouchers.valueMin')
      if (this.parsedNew > 5000) return this.$t('pages.giftVouchers.valueMax')
      if (this.sortedAmounts.includes(this.parsedNew)) return this.$t('pages.giftVouchers.valueDuplicate')
      if (this.sortedAmounts.length >= 12) return this.$t('pages.giftVouchers.valueLimit')
      return ''
    },
    presets() {
      return [10, 25, 50, 75, 100, 150, 200, 250]
        .filter(p => !this.sortedAmounts.includes(p))
        .slice(0, 5)
    },
    minInvalid() {
      return !this.result.min_quantity || this.result.min_quantity < 1
    },
    isMaxInvalid() {
      return !this.result.max_quantity || this.result.max_quantity < this.result.min_quantity
    },
    hasImage() {
      return !!this.result.image
    },
    imagePreview() {
      if (!this.result.image) return ''
      return String(this.result.image).startsWith('data:')
        ? this.result.image
        : this.getImageURL(this.result.image)
    }
  },

  watch: {
    // a new image gets a fresh chance to load
    'result.image'() {
      this.imageBroken = false
      this.imageLoaded = false
    }
  },

  methods: {
    formatAmount(v) {
      return Number(v) % 1 === 0 ? String(Number(v)) : Number(v).toFixed(2)
    },
    addAmount(value) {
      const v = value !== undefined ? value : this.parsedNew
      if (v === null || v < 1 || v > 5000 || this.sortedAmounts.includes(v) || this.sortedAmounts.length >= 12) return
      this.result.amounts = [...this.sortedAmounts, v].sort((a, b) => a - b)
      if (value === undefined) this.newAmount = ''
    },
    removeAmount(v) {
      if (this.sortedAmounts.length === 1) return
      this.result.amounts = this.sortedAmounts.filter(a => a !== v)
    },
    step(key, delta) {
      const next = (parseInt(this.result[key]) || 0) + delta
      this.result[key] = Math.min(100, Math.max(1, next))
    },
    removeImage() {
      this.result.image = ''
    },
    readFile(file) {
      if (!file || !/^image\/(png|jpe?g|webp|gif)$/.test(file.type)) {
        this.$store.dispatch('ui/setToastError', this.$t('pages.giftVouchers.imageTypeError'))
        return
      }
      if (file.size > 4 * 1024 * 1024) {
        this.$store.dispatch('ui/setToastError', this.$t('pages.giftVouchers.imageSizeError'))
        return
      }
      const reader = new FileReader()
      reader.onload = () => { this.result.image = reader.result }
      reader.readAsDataURL(file)
    },
    uploadImage(e) {
      this.readFile(e.target.files && e.target.files[0])
      e.target.value = ''
    },
    onDrop(e) {
      this.dragging = false
      this.readFile(e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0])
    }
  }
}
</script>

<style scoped>
/*
 * Buttons here set height/line-height and their hover background with
 * !important: base.styl's global button rules (height and a hover that
 * outranks scoped CSS) otherwise win.
 */

/* ---------- sections ---------- */

.gv-section {
  padding: 4px 0 22px;
  margin-bottom: 22px;
  border-bottom: 1px solid var(--border-color, #E5E7EB);
}

.gv-section:last-child { border-bottom: 0; margin-bottom: 0; padding-bottom: 0; }

.gv-section-head {
  display: flex;
  gap: 12px;
  align-items: flex-start;
  margin-bottom: 16px;
}

.gv-step {
  flex: 0 0 28px;
  height: 28px;
  border-radius: 50%;
  background: var(--primary-soft, #EFEFFF);
  color: var(--primary-color, #33319A);
  font-weight: 700;
  font-size: 13px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.gv-section-title {
  margin: 0;
  font-size: 15.5px;
  font-weight: 700;
  color: var(--text-strong, #111827);
}

.gv-section-sub {
  margin: 2px 0 0;
  font-size: 13px;
  color: var(--text-muted, #6B7280);
}

.gv-optional {
  font-weight: 500;
  color: var(--text-soft, #9CA3AF);
  margin-left: 4px;
  text-transform: none;
}

.tab-sidebar .form-wrapper textarea#gv-desc {
  font-family: inherit;   /* was monospace from a global rule */
  font-size: 14px;
  line-height: 1.5;
  padding: 10px 12px;
}

.gv-stepper { width: 100%; }

.gv-field-foot {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  min-height: 18px;
  margin-top: 4px;
}

.gv-count {
  margin-left: auto;
  font-size: 11.5px;
  color: var(--text-soft, #9CA3AF);
  font-variant-numeric: tabular-nums;
}

.gv-hint {
  margin: 10px 0 0;
  font-size: 13px;
  color: var(--text-muted, #6B7280);
}

.gv-hint-warn { color: var(--warning-text, #7A5A00); }

/* ---------- values ---------- */

.gv-chips {
  list-style: none;
  margin: 0 0 14px;
  padding: 0;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.gv-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 36px;
  padding: 0 6px 0 14px;
  border-radius: 999px;
  background: var(--primary-soft, #EFEFFF);
  color: var(--primary-color, #33319A);
  font-weight: 700;
  font-size: 14px;
  font-variant-numeric: tabular-nums;
}

.gv-chip-x {
  width: 26px;
  height: 26px !important;
  line-height: 1 !important;
  padding: 0;
  border: 0;
  border-radius: 50%;
  background: transparent;
  color: inherit;
  font-size: 12px;
  cursor: pointer;
}

.gv-chip-x:hover:enabled { background: rgba(51, 49, 154, .14) !important; }
.gv-chip-x:disabled { opacity: .35; cursor: not-allowed; }

.gv-empty {
  margin: 0 0 14px;
  padding: 12px 14px;
  border-radius: var(--radius-md, 8px);
  background: var(--warning-bg, #FFF7D6);
  color: var(--warning-text, #7A5A00);
  font-size: 13px;
}

.gv-add-row {
  display: flex;
  gap: 10px;
  max-width: 420px;
}

.gv-money {
  position: relative;
  flex: 1 1 auto;
}

.gv-money-sym {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--text-muted, #6B7280);
  font-weight: 600;
  pointer-events: none;
}

.tab-sidebar .form-wrapper .gv-money input {
  width: 100%;
  height: 40px;
  padding-left: 28px;
}

.gv-presets {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  margin-top: 12px;
}

.gv-presets-label {
  font-size: 12px;
  font-weight: 600;
  color: var(--text-soft, #9CA3AF);
  text-transform: uppercase;
  letter-spacing: .04em;
  margin-right: 2px;
}

.gv-preset {
  height: 30px !important;
  line-height: 1 !important;
  padding: 0 12px;
  border: 1px dashed var(--border-strong, #D1D5DB);
  border-radius: 999px;
  background: #fff;
  color: var(--text-color, #1F2937);
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}

.gv-preset:hover {
  border-style: solid;
  border-color: var(--primary-color, #33319A);
  color: var(--primary-color, #33319A);
  background: var(--primary-soft, #EFEFFF) !important;
}

/* ---------- buttons ---------- */

.gv-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  height: 40px !important;
  line-height: 1 !important;
  padding: 0 16px;
  border-radius: var(--radius-md, 8px);
  font-size: 13.5px;
  font-weight: 600;
  white-space: nowrap;
  cursor: pointer;
  border: 1px solid transparent;
}

.gv-btn:disabled { opacity: .5; cursor: not-allowed; }

.gv-btn-primary {
  background: var(--primary-color, #33319A);
  color: #fff;
}

.gv-btn-primary:hover:enabled { background: var(--primary-hover, #29277F) !important; color: #fff; }

.gv-btn-ghost {
  background: #fff;
  border-color: var(--border-strong, #D1D5DB);
  color: var(--text-color, #1F2937);
}

.gv-btn-ghost:hover { background: var(--hover-color, #F3F4F6) !important; }

.gv-btn-danger {
  background: #fff;
  border-color: #F5C2C0;
  color: var(--danger-text, #B42318);
}

.gv-btn-danger:hover { background: var(--danger-bg, #FEE2E2) !important; }

/* ---------- quantity ---------- */

.gv-qty-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 200px));
  gap: 16px;
}

/* a global .input-wrapper rule overrides the grid; set the two fields explicitly */
.gv-qty-grid {
  display: flex !important;
  flex-wrap: wrap;
  gap: 16px;
}

.gv-qty-grid > .input-wrapper {
  flex: 0 0 200px;
  width: 200px !important;
  max-width: 100%;
  margin: 0 !important;
}

.gv-stepper {
  display: flex;
  align-items: stretch;
  height: 42px;
  border: 1px solid var(--border-strong, #D1D5DB);
  border-radius: var(--radius-md, 8px);
  overflow: hidden;
  background: #fff;
}

.gv-stepper.invalid { border-color: var(--danger-text, #B42318); }

.gv-stepper button {
  width: 42px;
  height: auto !important;
  line-height: 1 !important;
  border: 0;
  border-radius: 0;
  background: var(--hover-color, #F3F4F6);
  color: var(--text-strong, #111827);
  font-size: 18px;
  cursor: pointer;
}

.gv-stepper button:hover:enabled { background: var(--primary-soft, #EFEFFF) !important; color: var(--primary-color, #33319A); }
.gv-stepper button:disabled { opacity: .4; cursor: not-allowed; }

.tab-sidebar .form-wrapper .gv-stepper input {
  flex: 1 1 auto;
  width: 0;          /* the global input width pushed the stepper past its column */
  min-width: 0;
  height: 100%;
  border: 0;
  border-radius: 0;
  box-shadow: none;
  text-align: center;
  font-weight: 700;
  font-size: 15px;
  -moz-appearance: textfield;
}

.gv-stepper input::-webkit-outer-spin-button,
.gv-stepper input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }

/* ---------- sidebar ---------- */

.gv-side-card {
  background: #fff;
  border: 1px solid var(--border-color, #E5E7EB);
  border-radius: 12px;
  padding: 16px;
}

.gv-side-title {
  margin: 0 0 10px;
  font-size: 14px;
  font-weight: 700;
  color: var(--text-strong, #111827);
}

.gv-side-row {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
}

.gv-side-row .gv-side-title { margin-bottom: 2px; }

.gv-side-sub {
  margin: 0;
  font-size: 12.5px;
  color: var(--text-muted, #6B7280);
  line-height: 1.45;
}

/* switch */
.gv-switch {
  position: relative;
  flex: 0 0 44px;
  width: 44px;
  height: 24px !important;
  line-height: 1 !important;
  padding: 0;
  border: 0;
  border-radius: 999px;
  background: var(--border-strong, #D1D5DB);
  cursor: pointer;
  transition: background-color .2s ease;
}

.gv-switch[aria-checked='true'] { background: #12B76A !important; }
.gv-switch:hover { background: #BFC3CC !important; }
.gv-switch[aria-checked='true']:hover { background: #0E9F5B !important; }

.gv-switch-knob {
  position: absolute;
  top: 3px;
  left: 3px;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: #fff;
  box-shadow: 0 1px 3px rgba(0, 0, 0, .2);
  transition: transform .2s ease;
}

.gv-switch[aria-checked='true'] .gv-switch-knob { transform: translateX(20px); }

.gv-switch:focus-visible { outline: 2px solid var(--primary-color, #33319A); outline-offset: 2px; }

/* image drop zone */
.gv-file {
  position: absolute;
  width: 1px;
  height: 1px;
  opacity: 0;
  pointer-events: none;
}

.gv-drop {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  min-height: 150px;
  padding: 14px;
  border: 1.5px dashed var(--border-strong, #D1D5DB);
  border-radius: 12px;
  background: #FAFAFC;
  text-align: center;
  cursor: pointer;
  transition: border-color .15s ease, background-color .15s ease;
}

.gv-drop:hover,
.gv-drop.dragging {
  border-color: var(--primary-color, #33319A);
  background: var(--primary-soft, #EFEFFF);
}

.gv-drop.has-image { padding: 8px; }

.gv-drop-img {
  max-width: 100%;
  max-height: 180px;
  object-fit: contain;
  border-radius: 8px;
}

.gv-drop-icon {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  background: #fff;
  border: 1px solid var(--border-color, #E5E7EB);
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--primary-color, #33319A);
  font-size: 18px;
}

.gv-pulse { animation: gv-pulse 1.4s ease-in-out infinite; }

@keyframes gv-pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: .45; }
}

@media (prefers-reduced-motion: reduce) {
  .gv-pulse { animation: none; }
}

.gv-drop-text { font-size: 13.5px; font-weight: 600; color: var(--text-strong, #111827); }
.gv-drop-sub { font-size: 12px; color: var(--text-muted, #6B7280); word-break: break-all; }

.gv-image-actions {
  display: flex;
  gap: 8px;
  margin-top: 10px;
}

.gv-image-actions .gv-btn { flex: 1 1 0; position: relative; }

/* live preview */
.gv-preview {
  border: 1px solid var(--border-color, #E5E7EB);
  border-radius: 12px;
  overflow: hidden;
  background: #fff;
  box-shadow: 0 6px 18px rgba(17, 24, 39, .06);
}

.gv-preview.withdrawn { opacity: .6; }

.gv-preview-img {
  position: relative;
  height: 120px;
  background: #F7F7FC;
  display: flex;
  align-items: center;
  justify-content: center;
}

.gv-preview-img img { max-width: 100%; max-height: 100%; object-fit: contain; }

.gv-preview-fallback {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #33319A, #5653D1);
  color: rgba(255, 255, 255, .9);
  font-size: 34px;
}

.gv-preview-badge {
  position: absolute;
  top: 8px;
  left: 8px;
  padding: 3px 9px;
  border-radius: 999px;
  background: #111827;
  color: #fff;
  font-size: 11px;
  font-weight: 700;
}

.gv-preview-body {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 12px;
}

.gv-preview-title { font-size: 14px; color: var(--text-strong, #111827); overflow-wrap: anywhere; }

.gv-preview-desc {
  font-size: 12px;
  color: var(--text-muted, #6B7280);
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.gv-preview-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
  margin: 6px 0 2px;
}

.gv-preview-chips span {
  padding: 3px 9px;
  border: 1px solid #4B49AC;
  border-radius: 7px;
  color: #4B49AC;
  font-size: 11.5px;
  font-weight: 600;
}

.gv-preview-chips .more { border-style: dashed; }

.gv-preview-qty { font-size: 11.5px; color: var(--text-soft, #9CA3AF); }

@media only screen and (max-width: 992px) {
  .gv-qty-grid > .input-wrapper { flex: 1 1 0; width: auto !important; }
  .gv-add-row { max-width: none; }
}

/*
 * Hover, disabled and pressed states, pinned for every button on this form.
 *
 * base.styl applies to all buttons:
 *   button:hover            { background: hover-color }        (any state, even disabled)
 *   button:hover:enabled    { border-color: primary-color }
 *   button:active           { background: rgba(0,0,0,.02) }
 * so a disabled Add went pale grey behind white text (invisible), and Remove
 * picked up a purple border. Each button states its own background, border
 * and colour for every state here, with !important because those global
 * rules outrank scoped CSS.
 */
.gv-btn-primary,
.gv-btn-primary:hover,
.gv-btn-primary:active,
.gv-btn-primary:disabled:hover {
  background: var(--primary-color, #33319A) !important;
  border-color: var(--primary-color, #33319A) !important;
  color: #fff !important;
}

.gv-btn-primary:hover:enabled {
  background: var(--primary-hover, #29277F) !important;
  border-color: var(--primary-hover, #29277F) !important;
}

.gv-btn-danger,
.gv-btn-danger:disabled:hover {
  background: #fff !important;
  border-color: #F5C2C0 !important;
  color: var(--danger-text, #B42318) !important;
}

.gv-btn-danger:hover:enabled,
.gv-btn-danger:active {
  background: var(--danger-bg, #FEE2E2) !important;
  border-color: #EF9A95 !important;
  color: var(--danger-text, #B42318) !important;
}

.gv-btn-ghost:hover,
.gv-btn-ghost:active {
  background: var(--hover-color, #F3F4F6) !important;
  border-color: var(--border-strong, #D1D5DB) !important;
  color: var(--text-strong, #111827) !important;
}

.gv-chip-x,
.gv-chip-x:disabled:hover {
  background: transparent !important;
  border-color: transparent !important;
}

.gv-chip-x:hover:enabled,
.gv-chip-x:active {
  background: rgba(51, 49, 154, .14) !important;
  border-color: transparent !important;
}

.gv-preset:hover,
.gv-preset:active {
  background: var(--primary-soft, #EFEFFF) !important;
  border-color: var(--primary-color, #33319A) !important;
  color: var(--primary-color, #33319A) !important;
}

.gv-stepper button,
.gv-stepper button:disabled:hover {
  background: var(--hover-color, #F3F4F6) !important;
  border-color: transparent !important;
  color: var(--text-strong, #111827) !important;
}

.gv-stepper button:hover:enabled,
.gv-stepper button:active {
  background: var(--primary-soft, #EFEFFF) !important;
  border-color: transparent !important;
  color: var(--primary-color, #33319A) !important;
}

.gv-switch,
.gv-switch:hover,
.gv-switch:active { border-color: transparent !important; }

</style>
