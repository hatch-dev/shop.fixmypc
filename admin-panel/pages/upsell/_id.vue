<template>
  <data-page
    class="detail-width"
    ref="dataPage"
    set-api="setUpdatedUpsell"
    get-api="getUpdatedUpsell"
    route-name="upsell"
    :name="$t('index.upsell')"
    :validation-keys="['title']"
    :result="result"
    @result="settingResult"
    :emit-before-submit="true"
    @before-submit="handleSubmit"
  >
    <template v-slot:form="{hasError}">

      <div class="input-wrapper">
        <label>{{ $t('index.title') }}</label>
        <input
          type="text"
          :placeholder="$t('index.title')"
          v-model="result.title"
          :class="{invalid: !!!result.title && hasError}"
        >
        <span
          class="error"
          v-if="!!!result.title && hasError"
        >
          {{ $t('category.req', { type: $t('index.title')}) }}
        </span>
      </div>
        <div class="status-row">
          <div
            class="input-wrapper mlr-7-5"
          >
              <label class="block">
                {{ $t('category.status') }}
              </label>

              <dropdown
                :selectedKey="`${result.status}`"
                :options="statusObj"
                @clicked="dropdownSelected"
              />
          </div>
          <button
            type="button"
            class="ajax-btn primary-btn"
            @click="toggleUpsellBox"
          >
            Add
          </button>
        </div>
        <span class="error" v-if="upsellError">
          {{ upsellError }}
        </span>
        <div
          v-for="(upsell, index) in result.upsells"
          :key="index"
          class="upsell-box"
        >
        <div class="upsell-header" @click="toggleExpand(index)">
          <h4 class="upsell-title">
            {{ upsell.item_title || (upsell.type === 'service' ? 'Service' : 'Product') }}
          </h4>
          <div class="header-actions">
            <span
              class="expand-icon"
              :class="{ open: upsell.expanded }"
            >
              ▼
            </span>
            <button
              type="button"
              class="delete-btn"
              @click.stop="removeUpsell(index)"
            >
              Delete
            </button>
          </div>
        </div>
         <div v-show="upsell.expanded" class="upsell-body">
          <div class="radio-group">
            <label>
              <input type="radio" value="service" v-model="upsell.type">
              Service
            </label>
            <label>
              <input type="radio" value="product" v-model="upsell.type">
              Product
            </label>
          </div>
          <div class="input-wrapper">
            <label>Item Title</label>
            <input
              type="text"
              placeholder="Title"
              v-model="upsell.item_title"
            >
            <span class="error" v-if="upsell.errors?.item_title">
              {{ upsell.errors.item_title }}
            </span>
          </div>
          <div class="image-upload-wrapper">
            <div class="image-preview" @click="$refs['fileInput_' + index][0].click()">
              <img
                v-if="upsell.image"
                :src="upsell.image"
                alt="preview"
              />
              <span v-else>Upload Image</span>
            </div>

            <input
              type="file"
              :ref="'fileInput_' + index"
              @change="onImageChange($event, index)"
              class="d-none"
            />
            <span class="error" v-if="upsell.errors?.image">
              {{ upsell.errors.image }}
            </span>
          </div>
          <div v-if="upsell.type === 'service'">
            <div class="input-wrapper">
              <label>Description</label>
              <textarea
                rows="3"
                v-model="upsell.description"
                placeholder="Add description..."
              ></textarea>
              <span class="error" v-if="upsell.errors?.description">
                {{ upsell.errors.description }}
              </span>
            </div>
            <div class="input-wrapper price-field">
              <label>Price</label>
              <div class="price-input">
                <span>{{ currencyIcon }}</span>
                <input
                  type="number"
                  v-model="upsell.price"
                  min="0"
                  step="0.01"
                >
                
              </div>
              <span class="error" v-if="upsell.errors?.price">
                  {{ upsell.errors.price }}
                </span>
            </div>
          </div>
          <div v-if="upsell.type === 'product'" class="ram-section">
            <div class="ram-add-row">
              <select v-model="upsell.selected_ram">
                <option disabled value="">Select RAM</option>
                <option value="4GB">4GB</option>
                <option value="8GB">8GB</option>
                <option value="16GB">16GB</option>
                <option value="32GB">32GB</option>
              </select>

              <button
                type="button"
                class="ajax-btn primary-btn"
                @click="addRam(index)"
              >
                Add
              </button>
            </div>
            <span class="error" v-if="upsell.errors?.ram">
              {{ upsell.errors.ram }}
            </span>
            <div
              v-for="(ram, rIndex) in upsell.ram_options"
              :key="rIndex"
              class="ram-item"
            >
              <span class="ram-name">{{ ram.name }}</span>

              <div class="price-input">
                <span>{{ currencyIcon }}</span>
                <input
                  type="number"
                  v-model="ram.price"
                  min="0"
                  step="0.01"
                >
              </div>

              <button
                type="button"
                class="remove-btn"
                @click="removeRam(index, rIndex)"
              >
                ✕
              </button>
              <span class="error" v-if="upsell.errors?.[`ram_${rIndex}`]">
                {{ upsell.errors[`ram_${rIndex}`] }}
              </span>
            </div>
          </div>
         </div>
        </div>
      </div><!--dply-felx inputs-->
    </template>
  </data-page>
</template>

<script>

  import datetime from 'vuejs-datetimepicker'
  import {mapGetters, mapActions} from 'vuex'
  import DataPage from '~/components/partials/DataPage'
  import Dropdown from '~/components/Dropdown'
  import Spinner from '~/components/Spinner'
  import util from '~/mixin/util'
  import {debounce} from 'debounce'
  import moment from 'moment-timezone'
  import ProductInventory from "../../components/partials/ProductInventory";
  import ProductSearch from "../../components/partials/ProductSearch";
  import LazyImage from "../../components/LazyImage";
  import PriceFormat from "../../components/partials/PriceFormat";

  export default {
    name: "updated-upsell",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        upsellError: '',
        result: {
          upsells: [],
          id: '',
          title: '',
          status: 2,
          time_zone: this.timeZone,
          products: []
        }
      }
    },
    mixins: [util],
    components: {
      PriceFormat,
      LazyImage,
      ProductSearch,
      ProductInventory,
      DataPage,
      Dropdown,
      datetime,
      Spinner
    },
    watch: {
      searchedString: debounce(function () {
        this.fetchingData()
      }, 700)
    },
    computed: {
      dateValidation() {
       // return new Date(this.result.end_time) > new Date(this.result.start_time)
      },
      currencyIcon() {
        return this.setting?.currency_icon || '$'
      },
      ...mapGetters('setting', ['setting']),
    },
    methods: {
      toggleExpand(index) {
        this.result.upsells[index].expanded =
          !this.result.upsells[index].expanded
      },
      removeUpsell(index) {
        const item = this.result.upsells[index]
        console.log(item, "item");

        if (item.id) {
          if (!this.result.deleted_ids) {
            this.$set(this.result, 'deleted_ids', [])
          }
          this.result.deleted_ids.push(item.id)
        }

        this.result.upsells.splice(index, 1)
      },
      preparePayload() {

        const cleanedUpsells = this.result.upsells.map(u => {
          if (u.type === 'service') {
            return {
              id: u.id || null,
              type: u.type,
              item_title: u.item_title,
              description: u.description,
              image: u.image,
              price: u.price
            }
          }

          if (u.type === 'product') {
            return {
              id: u.id || null,
              type: u.type,
              item_title: u.item_title,
              image: u.image,
              ram_options: u.ram_options
            }
          }
        })

        return {
          id: this.result.id,
          title: this.result.title,
          status: this.result.status,
          upsells: cleanedUpsells,
          deleted_ids: this.result.deleted_ids || []
        }
      },
      handleSubmit() {
        if (!this.validateUpsells()) {
          return
        }
        this.$refs.dataPage.checkForm()
      },
      validateUpsells() {
        let isValid = true
        this.upsellError = ''
        console.log("upsells", this.result.upsells);
        if (!this.result.upsells || this.result.upsells.length === 0) {
          this.upsellError = 'Please add at least one service or product'
          return false
        }
        this.result.upsells.forEach((upsell) => {

          this.$set(upsell, 'errors', {})

          if (!upsell.item_title || !upsell.item_title.trim()) {
            this.$set(upsell.errors, 'item_title', 'Item title is required')
            isValid = false
          }

          if (!upsell.image || !upsell.image.trim()) {
            this.$set(upsell.errors, 'image', 'Image is required')
            isValid = false
          }

          if (upsell.type === 'service') {

            if (!upsell.description || !upsell.description.trim()) {
              this.$set(upsell.errors, 'description', 'Description is required')
              isValid = false
            }

            // if (!upsell.price || Number(upsell.price) <= 0) {
            //   this.$set(upsell.errors, 'price', 'Price must be greater than 0')
            //   isValid = false
            // }

          }

          if (upsell.type === 'product') {

            if (!upsell.ram_options || upsell.ram_options.length === 0) {
              this.$set(upsell.errors, 'ram', 'Add at least one RAM option')
              isValid = false
            }

            // upsell.ram_options.forEach((ram, rIndex) => {
            //   if (!ram.price || Number(ram.price) <= 0) {
            //     this.$set(
            //       upsell.errors,
            //       `ram_${rIndex}`,
            //       'RAM price must be greater than 0'
            //     )
            //     isValid = false
            //   }
            // })

          }

        })

        return isValid
      },
      addRam(index) {
        const upsell = this.result.upsells[index]

        if (!upsell.selected_ram) {
          this.$set(upsell, 'errors', { ram: 'Select RAM first' })
          return
        }

        const exists = upsell.ram_options.find(
          r => r.name === upsell.selected_ram
        )
        if (exists) return

        upsell.ram_options.push({
          name: upsell.selected_ram,
          price: 0
        })

        upsell.selected_ram = ''
      },

      removeRam(upsellIndex, ramIndex) {
        this.result.upsells[upsellIndex].ram_options.splice(ramIndex, 1)
      },
      onImageChange(e, index) {
        const file = e.target.files[0]
        if (!file) return

        const reader = new FileReader()
        reader.onload = (event) => {
          this.result.upsells[index].image = event.target.result
        }
        reader.readAsDataURL(file)
      },
      toggleUpsellBox() {
        this.result.upsells.push({
          type: 'service',
          item_title: '',
          description: '',
          image: '',
          price: 0,
          ram_options: [],
          selected_ram: '',
          errors: {},
          expanded: true
        })
      },
      settingResult(evt) {
        const mappedUpsells = (evt.items || []).map(item => {
          return {
            id: item.id,
            type: item.type,
            item_title: item.title,
            description: item.description,
            image: item.image ? '/' + item.image : '',
            price: item.service_price,
            ram_options: item.ram_options || [],
            selected_ram: '',
            errors: {},
            expanded: false
          }
        })

        this.result = {
          id: evt.id,
          title: evt.title,
          status: evt.status,
          upsells: mappedUpsells,
          time_zone: this.timeZone
        }
      },
      dropdownSelected(data) {
        this.result.status = data.key
      },
      ...mapActions('common', ['getById'] )
    },
    async mounted() {
    },
  }
</script>

<style lang="stylus">
.upsell-box
  border 2px solid #ddd
  padding 25px
  margin-top 20px
  border-radius 6px
  background #fafafa
  margin-bottom 10px

.radio-group
  display flex
  gap 20px
  margin-bottom 20px

.image-upload-wrapper
  margin 15px 0

.image-preview
  width 120px
  height 120px
  border 2px dashed #ccc
  display flex
  align-items center
  justify-content center
  cursor pointer
  overflow hidden
  border-radius 6px
  background white

.image-preview img
  width 100%
  height 100%
  object-fit cover

.price-input
  display flex
  align-items center
  border 1px solid #ccc
  padding 5px 10px
  border-radius 4px
  width 150px

.price-input span
  margin-right 5px

.status-row
  display flex
  align-items flex-end
  gap 20px

.status-row .input-wrapper
  margin-bottom 0

.status-row .ajax-btn
  height 38px
  padding 0 22px
  display flex
  align-items center
  justify-content center

.ram-section
  margin-top 15px

.ram-add-row
  display flex
  gap 15px
  align-items center
  margin-bottom 15px

.ram-add-row select
  height 38px
  padding 0 10px
  border 1px solid #ccc
  border-radius 4px

.ram-item
  display flex
  align-items center
  gap 15px
  margin-bottom 10px

.ram-name
  min-width 80px
  font-weight 600

.upsell-header
  display flex
  justify-content space-between
  align-items center
  margin-bottom 15px

.upsell-title
  font-size 16px
  font-weight 600
  color #333

.upsell-header
  display flex
  justify-content space-between
  align-items center
  cursor pointer
  background #f7f8fa
  padding 12px 16px
  border-radius 8px
  transition all .2s ease
  border 1px solid #e5e7eb

.upsell-header:hover
  background #eef2f7

.upsell-title
  font-size 15px
  font-weight 600
  color #2c3e50

.header-actions
  display flex
  align-items center
  gap 12px

.expand-icon
  font-size 14px
  transition transform .25s ease
  color #6b7280

.expand-icon.open
  transform rotate(180deg)
  color #111827

.upsell-body
  margin-top 15px
  padding 10px 5px
  animation fadeIn .2s ease

@keyframes fadeIn
  from
    opacity 0
    transform translateY(-4px)
  to
    opacity 1
    transform translateY(0)
</style>
