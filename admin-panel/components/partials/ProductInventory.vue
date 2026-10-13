<template>
  <div>
    <div>

      <div class="dply-felx gap-15 title ptb-5 b-0">
        <h4 class="">{{ $t('prod.pInv') }}</h4>

        <button class="btn-clear dply-felx" @click.prevent="inventoryOpen = !inventoryOpen">
          <i
            class="icon black ignore-click"
            :class="[{'arrow-up': inventoryOpen}, {'arrow-down': !inventoryOpen}]"
          />
        </button>
      </div>

      <div v-if="inventoryOpen" class="form-wrapper attr-inventory b-t">
        <error-formatter
          type="inventory"
        />
        <div v-if="sectionError" class="inventory-section-error">
          <i class="fas fa-exclamation-circle mr-5" />
          {{ sectionError }}
        </div>
        <form
          @submit.prevent="saveInventory"
        >
          <div class="inv-mode-hint" :class="isVariable ? 'is-variable' : 'is-simple'">
            <template v-if="isVariable">
              <i class="fas fa-sitemap mr-5" />
              <span><strong>Variable product</strong> — tick attributes below to generate variant rows.</span>
            </template>
            <template v-else>
              <i class="fas fa-box mr-5" />
              <span><strong>Simple product</strong> — a single stock entry, no variants. Switch to <strong>Variable</strong> in Basic Info to add options.</span>
            </template>
          </div>

          <div
            class="variant-attrs mb-20 mb-sm-15"
            v-if="isVariable && attributes && attributes.length"
          >
            <template v-for="(attr, index) in attributes">
              <div
                v-if="attr.values && attr.values.length"
                :key="index"
                class="attr-block"
              >
                <label class="attr-head">
                  <input
                    v-model="selectedAttributes"
                    type="checkbox"
                    :value="attr.id"
                    @change="attributeChanged(attr, $event)"
                  >
                  <span class="attr-name">{{ attr.title }}</span>
                </label>

                <div class="attr-values">
                  <label
                    v-for="(val, i) in attr.values"
                    v-if="val.title != null && String(val.title).trim().length"
                    :key="i"
                    class="chip"
                    :class="{ active: selectedAttributeValues.includes(val.id) }"
                  >
                    <input
                      v-model="selectedAttributeValues"
                      type="checkbox"
                      :value="val.id"
                      @change="attributeValueChanged(val, $event)"
                    >
                    <span>{{ val.title }}</span>
                  </label>
                </div>
              </div>
            </template>
          </div>

          <div
            v-if="isVariable && !combinations.length"
            class="variant-empty"
          >
            <i class="fas fa-layer-group mr-5" />
            Tick attribute values above to generate variant rows.
          </div>
          <div
            v-if="combinations.length"
            class="inventory-wrap mb-20 mb-sm-15"
          >
            <div class="inventory-toolbar">
              <span class="inventory-count">
                <i class="fas fa-boxes mr-5" />
                {{ combinations.length }} {{ isVariable ? 'variant' : 'stock' }} row{{ combinations.length > 1 ? 's' : '' }}
              </span>
            </div>
            <div class="inventory-scroll">
            <div class="dply-felx f-column gap-15 inventory-table">
              <div class="inventory-row header">
                  <div>Back Order</div>
                  <div class="col-attr">{{ $t('list.attr') }}</div>
                  <div>Images</div>
                  <div>{{ $t('title.sku') }}</div>
                  <div>{{ $t('brand.price') }}({{currencyIcon}})</div>
                  <div>{{ $t('fSale.qty') }}</div>
                  <div>IMEI / S.No</div>
                  <div>Barcode</div>
              </div>
              <div
                v-for="(item, index) in combinations"
                :key="index"
                class="inventory-row"
              >

                <label class="serial-checkbox">
                  <input
                    type="checkbox"
                    v-model="item.is_active"
                    true-value="1"
                    false-value="0"
                  >
                </label>

                <div class="col-attr">
                  <h5 v-if="item.values && item.values.length">
                    {{ renderLabel(item.values || []) }}
                  </h5>
                  <h5 v-else>
                    {{ isVariable ? $t('list.aq') : 'Default' }}
                  </h5>
                  <span v-if="rowError(index, 'values')" class="field-error">
                    {{ rowError(index, 'values') }}
                  </span>
                </div>

                <div
                  class="inventory-image"
                  @click="openVariantImageManager(index)"
                >
                  <img
                    v-if="item.images && item.images.length"
                    :src="item.images[0].blob || getThumbImageURL(item.images[0].image)"
                    class="variant-thumb"
                  >

                  <div v-else class="variant-thumb placeholder">
                    <i class="fas fa-image variant-icon"></i>
                  </div>
                </div>

                <div class="inv-field">
                  <input
                    type="text"
                    v-model="item.sku"
                    placeholder="SKU"
                    :class="{ 'has-error': rowError(index, 'sku') }"
                    @input="clearRowError(index, 'sku')"
                  >
                  <span v-if="rowError(index, 'sku')" class="field-error">
                    {{ rowError(index, 'sku') }}
                  </span>
                </div>

                <div class="inv-field">
                  <input
                    :disabled="isVariable && !(item.values && item.values.length)"
                    type="number"
                    step="0.01"
                    min="0"
                    v-model="item.price"
                    :class="{ 'has-error': rowError(index, 'price') }"
                    @input="clearRowError(index, 'price')"
                  >
                  <span v-if="rowError(index, 'price')" class="field-error">
                    {{ rowError(index, 'price') }}
                  </span>
                </div>

                <div class="inv-field">
                  <input
                    type="number"
                    step="1"
                    min="0"
                    v-model="item.quantity"
                    :class="{ 'has-error': rowError(index, 'quantity') }"
                    @input="clearRowError(index, 'quantity')"
                  >
                  <span v-if="rowError(index, 'quantity')" class="field-error">
                    {{ rowError(index, 'quantity') }}
                  </span>
                </div>

                <input type="text" v-model="item.imei" placeholder="IMEI">

                <input type="text" v-model="item.barcode" placeholder="Barcode">

              </div>
            </div>
            </div>
          </div>

          <!-- <div
            v-if="$can('product', 'edit') || $can('product', 'create')"
            class="dply-felx j-right"
          >
            <ajax-button
              name="save-edit"
              class="primary-btn mr-10"
              :text="$t('list.svn')"
              :fetching-data="formSubmitting  && !redirect"
            />
            <ajax-button
              name="save"
              class="primary-btn"
              :text="$t('setting.sv')"
              :fetching-data="formSubmitting && redirect"
            />
          </div> -->
        </form>
      </div>
    </div>
    
    <!-- Images Modal -->

    <div v-if="showVariantImageModal" class="modal-overlay">
      <div class="image-manager">

        <div class="image-manager-body">
          <h2 class="image-manager-title">Variant Images</h2>

          <div class="image-grid">

            <div
              v-for="img in variantImageGallery"
              :key="img.id"
              class="image-card"
              :class="{ active: selectedVariantImageIds.includes(img.id) }"
              @click="toggleVariantImage(img.id)"
            >
              <img :src="getThumbImageURL(img.image)">

              <div
                v-if="selectedVariantImageIds.includes(img.id)"
                class="image-check"
              >
                ✓
              </div>
            </div>

          </div>
        </div>

        <div class="image-manager-footer">

          <label class="add-image-btn">
            + Add Variant Image
            <input
              type="file"
              accept="image/*"
              multiple
              @change="uploadNewImages"
              hidden
            >
          </label>

          <div class="footer-actions">
              <button class="btn-cancel" @click="showVariantImageModal = false">
                Cancel
              </button>

              <button class="button primary-btn" @click="applyVariantImage">
                Save Selection
              </button>
          </div>
        </div>

      </div>
    </div>
  </div>
</template>

<script>
  import AjaxButton from '~/components/AjaxButton'
  import ErrorFormatter from '~/components/ErrorFormatter'
  import {mapGetters, mapActions} from 'vuex'
  import util from "~/mixin/util"
  import validation from "~/mixin/validation"

  export default {
    name: 'ProductInventory',
    data() {
      return {
        variantImageGallery: [],
        selectedVariantImageIds: [],
        editingVariantIndex: null,
        showVariantImageModal: false,
        inventoryOpen: true,
        result: [],
        selectedAttributes: [],
        selectedAttributeValues: [],
        selected: {},
        combinations: [],
        formSubmitting: false,
        redirect: false,
        existing: {},
        showInventoryImageModal: false,
        editingInventoryIndex: null,
        rowErrors: [],
        sectionError: '',
      }
    },
    props: {
      attributes: {
        type: Array,
        default: []
      },
      productPrice: {
        type: Number,
        default: 0
      },
      productId: {
        type: [String, Number],
        default: null
      },
      isVariable: {
        type: Boolean,
        default: true
      },
    },
    mixins: [util, validation],
    components: {
      AjaxButton,
      ErrorFormatter
    },
    computed: {
      currentInventoryImages() {
        if (this.editingInventoryIndex === null) return []
        return this.combinations[this.editingInventoryIndex]?.images || []
      },
      currencyIcon() {
        return this.setting?.currency_icon || '$'
      },
      ...mapGetters('setting', ['setting'])
    },
    watch: {
      async productId(newVal, oldVal) {
        if (!newVal) return
        await this.fetchingData()
        await this.mappingData()
        await this.fetchVariantImages(newVal)
      },
      isVariable(newVal) {
        if (!newVal) {
          // Simple: collapse to a single value-less stock row
          this.ensureSimpleRow()
        } else {
          // Variable: if only a simple (value-less) row exists, clear it so
          // the user can pick attributes. Never wipe real variant rows.
          const onlySimple = this.combinations.length &&
            this.combinations.every(c => !(c.values && c.values.length))
          if (onlySimple) {
            this.combinations = []
            this.selected = {}
            this.selectedAttributes = []
            this.selectedAttributeValues = []
          }
        }
      }
    },
    methods: {
      ensureSimpleRow() {
        const existing = (this.combinations || []).find(
          c => !(c.values && c.values.length)
        ) || {}

        this.selected = {}
        this.selectedAttributes = []
        this.selectedAttributeValues = []

        this.combinations = [{
          id: existing.id || '',
          sku: existing.sku || '',
          price: (existing.price !== undefined && existing.price !== '' && existing.price !== null)
            ? existing.price
            : (this.productPrice || 0),
          quantity: existing.quantity !== undefined && existing.quantity !== null
            ? existing.quantity
            : 0,
          imei: existing.imei || '',
          barcode: existing.barcode || '',
          images: existing.images || [],
          values: [],
          is_active: existing.is_active || 0
        }]
      },
      resetInventoryErrors() {
        this.sectionError = ''
        this.rowErrors = (this.combinations || []).map(() => ({}))
      },
      rowError(index, field) {
        return (this.rowErrors[index] && this.rowErrors[index][field]) || ''
      },
      clearRowError(index, field) {
        if (this.rowErrors[index] && this.rowErrors[index][field]) {
          this.$set(this.rowErrors[index], field, '')
        }
      },
      async validateInventoryBeforeSubmit() {

        this.resetInventoryErrors()

        // ---- Simple product: validate the single stock row only ----
        if (!this.isVariable) {
          this.ensureSimpleRow()
          this.rowErrors = this.combinations.map(() => ({}))
          const row = this.combinations[0]
          const errors = {}

          if (!row.sku || !String(row.sku).trim()) {
            errors.sku = 'SKU is required'
          }

          if (row.quantity === '' || row.quantity === null || row.quantity === undefined ||
            !Number.isInteger(Number(row.quantity)) || Number(row.quantity) < 0) {
            errors.quantity = 'Quantity must be a whole number, 0 or more'
          }

          if (row.price === '' || row.price === null || isNaN(Number(row.price)) ||
            Number(row.price) < 0) {
            errors.price = 'Price must be 0 or more'
          }

          if (Object.keys(errors).length) {
            this.$set(this.rowErrors, 0, errors)
            this.$emit('has-error')
            return false
          }

          return true
        }

        // Require at least one attribute
        if (
          !this.selectedAttributeValues ||
          !this.selectedAttributeValues.length
        ) {
          this.sectionError = 'Please select inventory attributes'
          this.$emit('has-error')
          return false
        }

        // Require combinations
        if (!this.combinations || !this.combinations.length) {
          this.sectionError = 'Please create inventory combinations'
          this.$emit('has-error')
          return false
        }

        // Validate each combination, collecting every field error at once
        let hasError = false
        const rowErrors = this.combinations.map(combo => {
          const errors = {}

          if (!combo.values || !combo.values.length) {
            errors.values = 'Select attribute values'
          }

          if (!combo.sku || !combo.sku.trim()) {
            errors.sku = 'SKU is required'
          }

          if (combo.quantity === '' || combo.quantity === null ||
            !Number.isInteger(Number(combo.quantity)) || Number(combo.quantity) < 0) {
            errors.quantity = 'Quantity must be a whole number, 0 or more'
          }

          if (combo.price === '' || combo.price === null || isNaN(Number(combo.price)) ||
            Number(combo.price) < 0) {
            errors.price = 'Price must be 0 or more'
          }

          if (Object.keys(errors).length) hasError = true
          return errors
        })

        // Duplicate SKU validation — flag every row sharing a SKU
        const skuList = this.combinations.map(
          combo => (combo.sku || '').trim()
        )
        skuList.forEach((sku, index) => {
          if (sku && skuList.indexOf(sku) !== index) {
            rowErrors[index].sku = 'Duplicate SKU'
            const first = skuList.indexOf(sku)
            rowErrors[first].sku = rowErrors[first].sku || 'Duplicate SKU'
            hasError = true
          }
        })

        this.rowErrors = rowErrors

        if (hasError) {
          this.$emit('has-error')
          return false
        }

        return true
      },
      async uploadNewImages(e) {
        const files = Array.from(e.target.files)
        if (!files.length) return

        const formData = new FormData()

        files.forEach((file, index) => {
          formData.append("images[]", file)
        })

        try {
          const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'

          await this.$axios.post(
            `${baseUrl}api/admin/product/upload-images/${this.productId}`,
            formData,
            { headers: { 'Content-Type': 'multipart/form-data' } }
          )

          await this.fetchVariantImages(this.productId)

          this.$toast.success("Images uploaded successfully")

        } catch (e) {
          console.error(e)
          this.$toast.error("Upload failed")
        }

        e.target.value = ''
      },
      toggleVariantImage(id) {
        const exists = this.selectedVariantImageIds.includes(id)

        if (exists) {
          this.selectedVariantImageIds =
            this.selectedVariantImageIds.filter(i => i !== id)
        } else {
          this.selectedVariantImageIds.push(id)
        }
      },
      async openVariantImageManager(index) {

        this.editingVariantIndex = index
        this.showVariantImageModal = true
        const combo = this.combinations[index]
        this.selectedVariantImageIds = combo.images
          ? combo.images.map(i => i.id)
          : []

        if (!this.variantImageGallery.length) {
          await this.fetchVariantImages(this.productId)
        }
      },
      async fetchVariantImages(productId) {
        try {
          const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'

          const res = await this.$axios.get(
            `${baseUrl}api/admin/product/all-images/${productId}`
          )

          this.variantImageGallery = res.data.data

        } catch (e) {
          console.error(e)
        }
      },
      applyVariantImage() {

        const combo = this.combinations[this.editingVariantIndex]

        const selectedImages = this.variantImageGallery.filter(img =>
          this.selectedVariantImageIds.includes(img.id)
        )

        combo.images = selectedImages.map(img => ({
          id: img.id,
          image: img.image,
          blob: this.getThumbImageURL(img.image)
        }))

        this.$set(this.combinations, this.editingVariantIndex, combo)

        this.showVariantImageModal = false

      },
      openInventoryImageManager(index) {
        this.editingInventoryIndex = index
        this.showInventoryImageModal = true
      },

      closeInventoryImageManager() {
        this.showInventoryImageModal = false
        this.editingInventoryIndex = null
      },

      uploadInventoryImages(e) {

        const files = Array.from(e.target.files)

        const combo = this.combinations[this.editingInventoryIndex]

        files.forEach(file => {

          combo.images.push({
            file,
            blob: URL.createObjectURL(file)
          })

        })

        this.$set(this.combinations, this.editingInventoryIndex, combo)

        e.target.value = ''
      },

      removeInventoryImage(index) {

        const combo = this.combinations[this.editingInventoryIndex]

        combo.images.splice(index,1)

        this.$set(this.combinations, this.editingInventoryIndex, combo)

      },
      async imageDelete(mainIndex, imgIndex) {
        let comb = this.combinations[mainIndex]
        comb.images.splice(imgIndex, 1);

        const temp = this.combinations

        temp[mainIndex] = {...temp[mainIndex], ...comb}

        this.combinations = []

        setTimeout(() => {

          this.combinations = temp

        }, 10)
      },
      fileClicked(refStr) {
        if (this.$refs[refStr] && this.$refs[refStr]?.length) {
          this.$refs[refStr][0]?.click()
        }
      },
      fileChanged(index, evt) {
        let comb = this.combinations[index]

        Object.values(evt?.target?.files)?.forEach((i, key) => {

          if(!this.isValidImage(i)){

            if (!comb?.images) {
              comb = {...comb, ...{images: []}}
            }

            comb.images.push({
              blob: URL.createObjectURL(i),
              file: evt?.target?.files[key]
            })

          }

        })

        const temp = this.combinations

        temp[index] = {...temp[index], ...comb}

        this.combinations = []

        setTimeout(() => {

          this.combinations = temp

        }, 20)
      },
      redirectingEnable(buttonType) {
        this.redirect = buttonType === 'save'
      },
      async fetchingData() {
        try {
          this.loading = true
          this.result = await this.getById({id: this.productId, params: {}, api: 'getInventory'})

          this.loading = false
        } catch (e) {
          return this.$nuxt.error(e)
        }
      },
      async submitInventory() {
        if (!this.productId) {
          this.$toast.error('Please save product details first')
          return false
        }

        if (!this.isVariable) {
          // Simple product: guarantee a single value-less stock row, skip
          // the attribute/combination guards below.
          this.ensureSimpleRow()
        } else {
          if (
            this.selectedAttributes.length &&
            !this.selectedAttributeValues.length
          ) {
            this.$toast.error('Please select inventory attributes')
            this.$emit('has-error')
            return
          }
          const invalidCombination = this.combinations.find(combo =>
            !(combo.values && combo.values.length)
          )
          if (invalidCombination) {
            this.$toast.error('Inventory attribute combination is required')
            this.$emit('has-error')
            return
          }
        }

        this.formSubmitting = true
        try {
          let formData = new FormData();

          this.combinations.forEach((combo, index) => {

            formData.append(`inventories[${index}][id]`, combo.id || "")
            formData.append(`inventories[${index}][sku]`, combo.sku || "")
            formData.append(`inventories[${index}][price]`, combo.price || this.productPrice)
            formData.append(`inventories[${index}][quantity]`, combo.quantity || 0)
            formData.append(`inventories[${index}][imei]`, combo.imei || "")
            formData.append(`inventories[${index}][barcode]`, combo.barcode || "")
            formData.append(`inventories[${index}][is_active]`, combo.is_active || 0)
            if (combo.values && combo.values.length) {
              combo.values.forEach((val, vIndex) => {
                formData.append(
                  `inventories[${index}][values][${vIndex}][id]`,
                  val.id
                )
              })
            }

            if (combo.images && combo.images.length) {

              combo.images.forEach((img) => {

                if (img.id) {
                  formData.append(
                    `inventories[${index}][image_ids][]`,
                    img.id
                  )
                }

                // newly uploaded images
                if (img.file) {
                  formData.append(
                    `inventories[${index}][images][]`,
                    img.file
                  )
                }
              })
            }
          });
          

          const data = await this.setById({
            id: this.productId,
            params: formData,
            api: 'setInventory'
          })

          if (data) {
            this.result = data
            await this.mappingData()
            return true
          } else {
            this.$emit('has-error');
            return false
          }
        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.formSubmitting = false
      },
      renderLabel(attribute) {
        if (!attribute || !attribute.length) return ''

        return attribute.map(i => i.title).join(' + ')
      },
      combos(list, n = 0, result = [], current = []) {
        if (!list || !list.length) {
          return []
        }
        if (n === list.length) {
          result.push({
            id: "",
            sku: "",
            price: this.productPrice || 0,
            quantity: 0,
            imei: "",
            barcode: "",
            images: [],
            values: [...current],
            is_active: 0
          });
        } else {
          (list[n] || []).forEach(item => {
            this.combos(list, n + 1, result, [...current, item]);
          });
        }
        return result;
      },
      attributeValueChanged(attributeValue, event) {
        if (!attributeValue || !event || !event.target) return;
        
        const currentCombos = (this.combinations || []).map(combo => ({
          ...combo,
          comboKey: (combo.values || []).map(v => v?.id || '').sort().join('-')
        }));

        if (event.target.checked) {
          this.selectedAttributes = this.selectedAttributes || [];
          this.selected = this.selected || {};
          
          if (!this.selectedAttributes.includes(attributeValue.attribute_id)) {
            this.selectedAttributes.push(attributeValue.attribute_id);
            this.selected[attributeValue.attribute_id] = this.selected[attributeValue.attribute_id] || [];
          }
          this.selected[attributeValue.attribute_id].push(attributeValue);
        } else {
          this.selected[attributeValue.attribute_id] = (this.selected[attributeValue.attribute_id] || []).filter(i => i?.id !== attributeValue?.id);
          if (!this.selected[attributeValue.attribute_id]?.length) {
            delete this.selected[attributeValue.attribute_id];
            if (!Object.keys(this.selected).length) {
              this.combinations = []
              return
            }
            this.selectedAttributes = (this.selectedAttributes || []).filter(id => id !== attributeValue?.attribute_id);
          }
        }

        const newCombos = this.combos(Object.values(this.selected || {}));
        
        this.combinations = newCombos.map(newCombo => {
          const newKey = (newCombo.values || []).map(v => v?.id || '').sort().join('-');
          const existing = currentCombos.find(c => c.comboKey === newKey);
          return existing ? { 
            ...newCombo, 
            sku: existing.sku || "",
            price: existing.price || this.productPrice || 0,
            quantity: existing.quantity || 0,
            images: existing.images || [] 
          } : newCombo;
        });
      },
      attributeChanged(attribute, event) {
        if (!attribute || !event || !event.target) return;
        
        const currentCombos = (this.combinations || []).map(combo => ({
          ...combo,
          comboKey: (combo.values || []).map(v => v?.id || '').sort().join('-')
        }));

        if (event.target.checked) {
          const attr = (this.attributes || []).find(i => i?.id === attribute?.id);
          const values = attr?.values || [];
          this.selected = this.selected || {};
          this.selected[attribute.id] = values;
          
          this.selectedAttributeValues = this.selectedAttributeValues || [];
          const valueIds = values.map(i => i?.id).filter(Boolean);
          this.selectedAttributeValues = [...new Set([...(this.selectedAttributeValues || []), ...valueIds])];
        } else {
          const valueIds = (this.selected[attribute.id] || []).map(i => i?.id).filter(Boolean);
          this.selectedAttributeValues =
            (this.selectedAttributeValues || [])
              .filter(id => !valueIds.includes(id))
          delete this.selected[attribute.id];
          this.selectedAttributes =
          (this.selectedAttributes || [])
            .filter(id => id !== attribute.id)
          if (!Object.keys(this.selected).length) {
            this.combinations = []
            return
          }
          
          this.selectedAttributeValues = (this.selectedAttributeValues || []).filter(id => !valueIds.includes(id));
        }

        const newCombos = this.combos(Object.values(this.selected || {}));
        
        this.combinations = newCombos.map(newCombo => {
          const newKey = (newCombo.values || []).map(v => v?.id || '').sort().join('-');
          const existing = currentCombos.find(c => c.comboKey === newKey);
          return existing ? { 
            ...newCombo, 
            sku: existing.sku || "",
            price: existing.price || this.productPrice || 0,
            quantity: existing.quantity || 0,
            images: existing.images || [] 
          } : newCombo;
        });
      },
      async mappingData() {
        if (this.result && this.result?.length) {
          this.selected = {}
          this.combinations = []
          const selectedAttr = []
          const selectedAttrValues = []
          
          await Object.values(this.result)?.forEach((i) => {
            // Setting the selected attribute and values
            const attributes = []
            i.inventory_attributes.forEach((j) => {
              selectedAttr.push(j.attribute_value.attribute_id)
              selectedAttrValues.push(j.attribute_value.id)
              attributes.push(j.attribute_value)
              
              // Preparing the selected object
              if (this.selected[j.attribute_value.attribute_id] === undefined) {
                this.selected = {...this.selected, ...{[j.attribute_value.attribute_id]: []}}
              }

              if (this.selected[j.attribute_value.attribute_id].findIndex(i => {
                return j.attribute_value.id === i.id
              }) === -1) {
                this.selected[j.attribute_value.attribute_id].push(j.attribute_value)
              }
            })
            
            // Making an object with existing values to save the inventory values
            this.existing[attributes.map(k => {
              return k.id
            }).join('-')] = {
              id: i.id,
              price: parseFloat(i.price) > 0 ? i.price : this.productPrice,
              sku: i.sku,
              quantity: i.quantity,
              imei: i.imei,
              barcode: i.barcode,
              is_active: i.is_active || 0
            }
            
            // Making combination
            this.combinations.push({
              id: i.id,
              sku: i.sku,
              price: parseFloat(i.price) > 0 ? i.price : this.productPrice,
              quantity: i.quantity,
              imei: i.imei,
              barcode: i.barcode,
              is_active: i.is_active || 0,
              values: attributes,
              images: (i.images || []).map(img => ({
                id: img.product_image_id,
                image: img.image.image,
                blob: this.getThumbImageURL(img.image.image)
              }))
            })
          })
          
          this.selectedAttributes = [...new Set(selectedAttr)]
          this.selectedAttributeValues = [...new Set(selectedAttrValues)]

          // Tell the parent which variant type this saved product is
          const hasValues = this.combinations.some(c => c.values && c.values.length)
          this.$emit('variant-detected', hasValues)
          if (!hasValues) {
            this.ensureSimpleRow()
          }
        } else {
          // No inventory yet
          this.combinations = []
          if (!this.isVariable) {
            this.ensureSimpleRow()
          }
        }
      },
      ...mapActions('common', ['getById', 'setById'])
    },
    beforeCreate() {
    },
    destroyed() {
    },
    created() {
    },
    async mounted() {
      if (this.productId) {
        await this.fetchingData()
        await this.mappingData()
        await this.fetchVariantImages(this.productId)
      } else if (!this.isVariable) {
        // New simple product: seed a single stock row
        this.ensureSimpleRow()
      }
    }
  }
</script>
<style lang="stylus">
  .inv-mode-hint
    display flex
    align-items center
    gap 6px
    padding 10px 14px
    border-radius 8px
    font-size 13px
    margin-bottom 16px

    &.is-variable
      background rgba(51,49,154,.06)
      color #33319a

    &.is-simple
      background #f1f5f9
      color #475569

  .variant-attrs
    border 1px solid #e5e7eb
    border-radius 12px
    overflow hidden
    background #fff

    .attr-block
      display flex
      align-items flex-start
      gap 16px
      padding 14px 16px
      border-bottom 1px solid #eef1f4

      &:last-child
        border-bottom none

    .attr-head
      display flex
      align-items center
      gap 8px
      min-width 120px
      max-width 120px
      padding 6px 0
      cursor pointer
      user-select none

      input
        width 16px
        height 16px
        accent-color #33319a
        margin 0

      .attr-name
        font-weight 700
        font-size 13px
        color #0f172a

    .attr-values
      display flex
      flex-wrap wrap
      gap 8px
      flex 1

    .chip
      display inline-flex
      align-items center
      gap 6px
      padding 6px 12px
      border 1px solid #e2e8f0
      border-radius 8px
      background #fff
      cursor pointer
      font-size 12.5px
      color #475569
      transition all .15s
      user-select none

      input
        width 14px
        height 14px
        accent-color #33319a
        margin 0

      &:hover
        border-color #c7cdd6

      &.active
        border-color #33319a
        background rgba(51,49,154,.06)
        color #33319a
        font-weight 600

  .variant-empty
    display flex
    align-items center
    justify-content center
    gap 6px
    padding 26px 16px
    margin-bottom 20px
    border 1px dashed #cbd5e1
    border-radius 12px
    background #f8fafc
    color #64748b
    font-size 13px

  .inventory-toolbar
    display flex
    align-items center
    justify-content space-between
    padding 10px 14px
    border-bottom 1px solid #e5e7eb
    background #f9fafb

  .inventory-count
    font-size 12.5px
    font-weight 600
    color #475569

  .inventory-scroll
    overflow-x auto

  .inventory-table
    width 100%
    align-items stretch

.inventory-row
  display grid
  width 100%
  grid-template-columns 46px minmax(80px,1.3fr) 52px minmax(70px,1fr) minmax(62px,0.9fr) minmax(56px,0.9fr) minmax(70px,1fr) minmax(70px,1fr)
  gap 8px
  align-items center
  justify-items center
  text-align center
  padding 10px 12px
  border-bottom 1px solid #f1f1f1
  transition background .15s

  &.header
    font-weight 600
    border-bottom 1px solid #ddd
    padding-bottom 8px
    margin-bottom 12px
  
  &:hover
    background #fafafa

  &:last-child
    border-bottom none

  input
    width 100%
    padding 6px 8px
    border 1px solid #ddd
    border-radius 4px

.col-attr
  font-weight 500
  color #374151
  text-align center

  h5
    font-size 13px
    margin 0

.image-remove
  position absolute
  top 5px
  right 5px
  background #000
  color #fff
  border none
  width 22px
  height 22px
  border-radius 50%
  cursor pointer

.inventory-wrap
  border 1px solid #e5e7eb
  border-radius 8px
  overflow hidden
  background #fff

.inventory-row.header
  background #f9fafb
  font-weight 600
  font-size 11px
  letter-spacing .02em
  color #64748b
  text-transform uppercase
  padding 10px 12px
  border-bottom 1px solid #e5e7eb

.inventory-row input
  width 100%
  height 34px
  padding 6px 10px
  border 1px solid #d1d5db
  border-radius 6px
  font-size 13px
  background #fff
  text-align center
  transition border .2s, box-shadow .2s

  &:focus
    outline none
    border-color #6366f1
    box-shadow 0 0 0 2px rgba(99,102,241,.15)

.inv-field
  display flex
  flex-direction column
  align-items stretch
  width 100%
  gap 3px

.inventory-row input.has-error
  border-color #ef4444
  background #fef2f2

  &:focus
    border-color #ef4444
    box-shadow 0 0 0 2px rgba(239,68,68,.15)

.field-error
  display block
  font-size 11px
  line-height 1.3
  color #ef4444
  text-align left
  margin-top 2px

.inventory-section-error
  display flex
  align-items center
  gap 6px
  margin 10px 0
  padding 8px 12px
  font-size 13px
  color #b91c1c
  background #fef2f2
  border 1px solid #fecaca
  border-radius 6px

.inventory-image
  display flex
  align-items center
  justify-content center

.inventory-image-thumb
  width 42px
  height 42px
  border 1px solid #e5e7eb
  border-radius 8px
  overflow hidden
  cursor pointer
  transition transform .15s

  &:hover
    transform scale(1.05)

  img
    width 100%
    height 100%
    object-fit cover

.btn-image
  border 1px dashed #cbd5e1
  padding 0px 10px
  font-size 12px
  border-radius 6px
  background #f9fafb
  cursor pointer
  transition all .2s

  &:hover
    background #f1f5f9
    border-color #94a3b8

.inventory-row.header div
  text-align center
  width 100%
  white-space normal
  word-break break-word
  line-height 1.25

.thumb-stack 
  display flex
  gap 4px

.thumb-stack img 
  width 28px
  height 28px
  border-radius 4px
  object-fit cover

.modal-overlay 
  position fixed
  inset 0
  background rgba(0,0,0,0.45)
  display flex
  align-items center
  justify-content center
  z-index 9999

.image-manager 
  width 900px
  max-width 95%
  background #fff
  border-radius 14px
  overflow hidden
  box-shadow 0 25px 60px rgba(0,0,0,0.2)

.image-grid 
  display grid
  gap 20px
  grid-template-columns repeat(3, 1fr)

.serial-checkbox
  display flex
  justify-content center
  align-items center

.serial-checkbox input
  width 20px
  height 20px

.variant-thumb
  width 30px
  height 30px
  border-radius 4px
  object-fit cover

.variant-thumb.placeholder
  font-size 18px
  color #9ca3af

.variant-icon
  font-size 16px
  color #9ca3af
</style>