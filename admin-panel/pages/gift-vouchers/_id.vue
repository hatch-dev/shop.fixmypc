<template>
  <data-page
    ref="dataPage"
    set-api="setGiftVoucher"
    get-api="getGiftVoucher"
    route-name="gift-vouchers"
    :name="'Gift Voucher'"
    :validation-keys="['title', 'description', 'min_quantity', 'max_quantity']"
    :result="result"
    gate="gift_voucher"
    @result="result = $event"
  >

    <template v-slot:form="{hasError}">

      <!-- Title -->
      <div class="input-wrapper">
        <label>Title</label>
        <input
          type="text"
          v-model="result.title"
          :class="{invalid: !!!result.title && hasError}"
        >
        <span class="error" v-if="!!!result.title && hasError">
          Title is required
        </span>
      </div>

      <!-- Image -->
      <div class="input-wrapper">
        <label>Image</label>

        <div class="image-upload">
            <!-- Preview -->
            <div v-if="result.image" class="preview">
                <img :src="imagePreview" alt="preview" />
                <button type="button" @click="removeImage">✕</button>
            </div>

            <!-- Upload -->
            <input
                type="file"
                accept="image/*"
                @change="uploadImage"
            >
        </div>
      </div>

      <!-- Description -->
      <div class="input-wrapper">
        <label>Description</label>
        <textarea v-model="result.description" :class="{invalid: !!!result.description && hasError}"></textarea>
         <span class="error" v-if="!!!result.description && hasError">
          Description is required
        </span>
      </div>

    <!-- Amounts -->
      <div class="input-wrapper">
        <label>Amounts</label>

        <div
          v-for="(amt, index) in result.amounts"
          :key="index"
          class="dply-felx align-center mb-10"
        >
          <input
            type="number"
            v-model.number="result.amounts[index]"
            placeholder="Enter amount"
            :class="{invalid: (!amt || amt <= 0) && hasError}"
          >

          <button
            type="button"
            class="delete-btn ml-10"
            @click="removeAmount(index)"
          >
            ✕
          </button>
        </div>

        <button type="button" class="lite-btn" @click="addAmount">
          + Add Amount
        </button>
      </div>

      

      <!-- Min & Max Quantity -->
      <div class="dply-felx align-start j-left inputs d-block-sm">

        <div class="input-wrapper">
          <div class="flex-v-centered">
            <span class="mr-15">Min Quantity</span>
            <input
              type="number"
              v-model.number="result.min_quantity"
              :class="{invalid: (!result.min_quantity || result.min_quantity < 1) && hasError}"
            >
            <span class="error" v-if="(!result.min_quantity || result.min_quantity < 1) && hasError">
                Min quantity must be at least 1
            </span>
          </div>
        </div>

        <div class="input-wrapper">
          <div class="flex-v-centered">
            <span class="mr-15">Max Quantity</span>
            <input
              type="number"
              v-model.number="result.max_quantity"
              :class="{invalid: (!result.max_quantity || isMaxInvalid) && hasError}"
            >
            <span class="error" v-if="!result.max_quantity && hasError">
                Max quantity is required
            </span>

            <span class="error" v-else-if="isMaxInvalid && hasError">
                Max must be greater than Min
            </span>
          </div>
        </div>

      </div>

    </template>
  </data-page>
</template>

<script>
import DataPage from '~/components/partials/DataPage'
import util from '~/mixin/util'

export default {
  name: "gift-voucher",
  middleware: ['common-middleware', 'auth'],

  data() {
    return {
      result: {
        id: '',
        title: '',
        image: '',
        description: '',
        amounts: [100],
        min_quantity: 1,
        max_quantity: 10
      }
    }
  },

  mixins: [util],
  computed:{
    isMaxInvalid() {
        return this.result.max_quantity &&
            this.result.min_quantity &&
            this.result.max_quantity < this.result.min_quantity
    },
    imagePreview() {
        if (!this.result.image) return ''

        if (this.result.image instanceof File) {
            return URL.createObjectURL(this.result.image)
        }

        return this.result.image.startsWith('data:')
        ? this.result.image
        : this.getImageURL(this.result.image)
    },
  },
  components: {
    DataPage
  },

  methods: {
    addAmount() {
      this.result.amounts.push(0)
    },

    removeAmount(index) {
        if (this.result.amounts.length === 1) return
        this.result.amounts.splice(index, 1)
    },

    removeImage() {
        this.result.image = ''
    },

    async uploadImage(e) {
        const file = e.target.files[0]
        if (!file) return
        const reader = new FileReader()

        reader.onload = () => {
            this.result.image = reader.result
        }
        reader.readAsDataURL(file)
    }
  },
}
</script>

<style scoped>
.delete-btn {
  background: red;
  color: white;
  padding: 0px 10px;
  border-radius: 4px;
}

.image-upload {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.preview {
  position: relative;
  width: 120px;
  height: 120px;
}

.preview img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  border-radius: 6px;
}

.preview button {
  position: absolute;
  top: -5px;
  right: -5px;
  background: red;
  color: white;
  border-radius: 50%;
  padding: 2px 6px;
}
</style>