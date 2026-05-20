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
        <form
          @submit.prevent="saveInventory"
        >
          <div
            class="mb-20 mb-sm-15 atr-wrapper"
            v-if="attributes && attributes.length"
          >
            <div
              v-for="(attr, index) in attributes"
              :key="index"
              class="single-atr"
            >
              <template
                v-if="!!attr.values.length"
              >
                <label
                  class="cb atr-title f-1-2 bold"
                >
                  <input
                    v-model="selectedAttributes"
                    type="checkbox"
                    :value="attr.id"
                    @change="attributeChanged(attr, $event)"
                  >
                  <span>{{attr.title}}</span>
                </label>

                <label
                  v-for="(val, i) in attr.values"
                  :key="i"
                  class="cb"
                >
                  <input
                    v-model="selectedAttributeValues"
                    type="checkbox"
                    :value="val.id"
                    @change="attributeValueChanged(val, $event)"
                  >
                  <span>{{ val.title }}</span>
                </label>
              </template>
            </div>
          </div>
          <div
            class="inventory-wrap mb-20 mb-sm-15"
          >
            <div class="dply-felx f-column gap-15 ">
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
                    {{ $t('list.aq') }}
                  </h5>
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

                <input type="text" v-model="item.sku" placeholder="SKU">

                <input
                  :disabled="!(item.values && item.values.length)"
                  type="number"
                  step="any"
                  v-model="item.price"
                >

                <input
                  type="number"
                  step="any"
                  v-model="item.quantity"
                >

                <input type="text" v-model="item.imei" placeholder="IMEI">

                <input type="text" v-model="item.barcode" placeholder="Barcode">

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
        inventoryOpen: false,
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
      }
    },
    methods: {
      async validateInventoryBeforeSubmit() {

        this.inventoryValidationError = ''

        // Require at least one attribute
        if (
          !this.selectedAttributeValues ||
          !this.selectedAttributeValues.length
        ) {

          this.inventoryValidationError =
            'Please select inventory attributes'

          this.$toast.error(this.inventoryValidationError)

          this.$emit('has-error')

          return false
        }

        // Require combinations
        if (!this.combinations || !this.combinations.length) {

          this.inventoryValidationError =
            'Please create inventory combinations'

          this.$toast.error(this.inventoryValidationError)

          this.$emit('has-error')

          return false
        }

        // Require valid SKU
        const invalidCombination = this.combinations.find(combo =>
          !combo.sku ||
          !combo.sku.trim() ||
          !combo.values ||
          !combo.values.length
        )

        // Validate each combination
        for (const combo of this.combinations) {
          if (!combo.values || !combo.values.length) {
            this.inventoryValidationError = 'Please select attribute values for all combinations'
            this.$toast.error(this.inventoryValidationError)
            this.$emit('has-error')
            return false
          }

          // SKU missing
          if (!combo.sku || !combo.sku.trim()) {
            this.inventoryValidationError = 'SKU is required for all inventory combinations'
            this.$toast.error(this.inventoryValidationError)
            this.$emit('has-error')
            return false
          }

          // Quantity validation
          if (
            combo.quantity === '' ||
            combo.quantity === null
          ) {

            this.inventoryValidationError = 'Valid quantity is required for all inventory combinations'
            this.$toast.error(this.inventoryValidationError)
            this.$emit('has-error')
            return false
          }

          // Price validation
          if (
            combo.price === '' ||
            combo.price === null ||
            Number(combo.price) < 0
          ) {

            this.inventoryValidationError =
              'Valid price is required for all inventory combinations'

            this.$toast.error(this.inventoryValidationError)

            this.$emit('has-error')

            return false
          }
        }

        // Duplicate SKU validation
        const skuList = this.combinations.map(
          combo => combo.sku.trim()
        )

        const duplicateSku = skuList.find(
          (sku, index) => skuList.indexOf(sku) !== index
        )

        if (duplicateSku) {
          this.inventoryValidationError =
            'Duplicate SKU found'
          this.$toast.error(this.inventoryValidationError)
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
        } else {
          // Default combination
          this.combinations = []
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
      }
    }
  }
</script>
<style lang="stylus">
  .atr-wrapper
    border-radius 5px
    border 1px solid #ddd
    background #f7f7f5

    .single-atr
      display flex
      flex-wrap wrap
      align-items center
      border-bottom 1px solid #ddd

      label
        padding 15px

      .atr-title
        width 120px
        max-width 120px

      &:last-child
        border-bottom none

.inventory-row
  display grid
  grid-template-columns 30px 200px 120px 120px 120px 120px 120px 120px
  gap 12px
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
  font-size 13px
  color #374151
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