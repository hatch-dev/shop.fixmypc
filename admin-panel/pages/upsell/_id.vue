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
            {{ upsell.item_title || 'Product Upgrade' }}
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
          <div class="upgrade-section">
            <div class="ram-add-row">
              <select v-model.number="upsell.selected_attribute_id" @change="onAttributeChange(index)">
                <option disabled value="">
                  Select Attribute
                </option>
                <option
                  v-for="attribute in availableAttributes(upsell)"
                  :key="attribute.id"
                  :value="attribute.id"
                >
                  {{ attribute.title }}
                </option>
              </select>
              <select v-model.number="upsell.selected_value_id">
                <option disabled value="">
                  Select Value
                </option>
                <option
                  v-for="value in upsell.attribute_values"
                  :key="value.id"
                  :value="value.id"
                >
                  {{ value.title }}
                </option>
              </select>
              <button
                type="button"
                class="ajax-btn primary-btn"
                @click="addUpgradeOption(index)"
              >
                Add
              </button>
            </div>
            <div
              v-for="(group, gIndex) in upsell.upgrade_groups"
              :key="gIndex"
              class="upgrade-group"
            >
              <h4 class="group-title">
                {{ group.title }}
              </h4>
              <div
                v-for="(option, oIndex) in group.options"
                :key="oIndex"
                class="ram-item"
              >
                <span class="ram-name">
                  {{ option.title }}
                </span>
                <div class="price-input">
                  <span>{{ currencyIcon }}</span>

                  <input
                    type="number"
                    v-model="option.price"
                    min="0"
                    step="0.01"
                  >
                </div>
                <button
                  type="button"
                  class="remove-btn"
                  @click="removeOption(index, gIndex, oIndex)"
                >
                  ✕
                </button>
              </div>
            </div>
          </div>
         </div>
        </div>
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
        attributes: [],
        loadingAttributes: false,
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
      filteredAttributes() {
        return this.attributes.filter(attribute => {
          const title = attribute.title.toLowerCase()
          return ['ram', 'storage'].includes(title)
        })
      },
      dateValidation() {
       // return new Date(this.result.end_time) > new Date(this.result.start_time)
      },
      currencyIcon() {
        return this.setting?.currency_icon || '$'
      },
      ...mapGetters('setting', ['setting']),
    },
    methods: {
      availableAttributes(upsell) {

        if (
          upsell.upgrade_groups &&
          upsell.upgrade_groups.length > 0
        ) {

          const firstGroup = upsell.upgrade_groups[0]

          return this.filteredAttributes.map(attribute => ({
            ...attribute,
            disabled:
              Number(attribute.id) !==
              Number(firstGroup.attribute_id)
          }))

        }

        return this.filteredAttributes.map(attribute => ({
          ...attribute,
          disabled: false
        }))
      },
      onAttributeChange(index) {
        const upsell = this.result.upsells[index]
        const attribute = this.attributes.find(
          a => a.id === upsell.selected_attribute_id
        )
        if (!attribute) {
          upsell.attribute_values = []
          return
        }
        upsell.attribute_values = attribute.values || []
        upsell.selected_value_id = ''
      },
      removeOption(upsellIndex, groupIndex, optionIndex) {
        this.result
          .upsells[upsellIndex]
          .upgrade_groups[groupIndex]
          .options
          .splice(optionIndex, 1)
      },
      async fetchAttributes() {
        this.loadingAttributes = true
        try {
          const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/';
          const response = await this.$axios.get(
            `${baseUrl}api/admin/attribute/all`,
            {
              params: {
                type: 'desc',
                orderby: 'created_at',
                page: 1,
                time_zone: this.timeZone
              }
            }
          )
          this.attributes = response.data?.data?.data || []
        } catch (e) {
          console.log(e)
        }
        this.loadingAttributes = false
      },
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

        const cleanedUpsells = this.result.upsells.map(u => ({
          id: u.id || null,
          item_title: u.item_title,
          image: u.image,
          upgrade_groups: u.upgrade_groups
        }))

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


          if (!upsell.upgrade_groups || upsell.upgrade_groups.length === 0) {
            this.$set(upsell.errors, 'ram', 'Add at least one RAM option')
            isValid = false
          }

        })

        return isValid
      },
      addUpgradeOption(index) {
        const upsell = this.result.upsells[index]

        if (
          !upsell.selected_attribute_id ||
          !upsell.selected_value_id
        ) {
          return
        }

        const attribute = this.attributes.find(
          a => a.id === upsell.selected_attribute_id
        )

        if (!attribute) {
          return
        }

        const value = attribute.values.find(
          v => v.id === upsell.selected_value_id
        )

        if (!value) {
          return
        }

        let group = upsell.upgrade_groups.find(
          g => g.attribute_id === attribute.id
        )

        if (!group) {
          group = {
            attribute_id: attribute.id,
            title: attribute.title,
            options: []
          }
          upsell.upgrade_groups.push(group)
        }

        const exists = group.options.find(
          o => o.value_id === value.id
        )

        if (exists) {
          return
        }

        group.options.push({
          value_id: value.id,
          title: value.title,
          price: 0
        })

        upsell.selected_value_id = ''
      },
      removeRam(upsellIndex, ramIndex) {
        this.result.upsells[upsellIndex].upgrade_groups.splice(ramIndex, 1)
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
          item_title: '',
          image: '',
          selected_attribute_id: '',
          selected_value_id: '',
          attribute_values: [],
          upgrade_groups: [],
          errors: {},
          expanded: true
        })
      },
      settingResult(evt) {
        const mappedUpsells = (evt.items || []).map(item => {
          const groupedOptions = [];
          (item.upgrade_options || []).forEach(option => {
            let group = groupedOptions.find(
              g => g.title.toLowerCase() === item.title
                .replace(' Upgrade', '')
                .toLowerCase()
            )

            const attributeTitle = item.title
            .replace(/upgrade/i, '')
            .trim()

            const attribute = this.filteredAttributes.find(
              a =>
                a.title.trim().toLowerCase() ===
                attributeTitle.trim().toLowerCase()
            )
            if (!group) {

              group = {
                attribute_id: attribute ? attribute.id : '',
                title: attributeTitle,
                options: []
              }

              groupedOptions.push(group)

            }

            const matchedValue = (attribute?.values || []).find(
              v =>
                v.title.trim().toLowerCase() ===
                option.name.trim().toLowerCase()
            )

            group.options.push({
              id: option.id,
              value_id: matchedValue?.id || null,
              title: option.name,
              price: Number(option.price || 0)
            })
          });

          const firstGroup = groupedOptions[0] || null
          let attributeValues = []
          if (firstGroup?.attribute_id) {
            const attribute = this.attributes.find(
              a => a.id === firstGroup.attribute_id
            )

            attributeValues = attribute?.values || []
          }
          return {
            id: item.id,
            item_title: item.title,
            image: item.image
              ? '/' + item.image
              : '',
            selected_attribute_id:
              Number(firstGroup?.attribute_id) || '',
            selected_value_id: '',
            attribute_values: attributeValues,
            upgrade_groups: groupedOptions,
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
      await this.fetchAttributes()
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
