<template>
  <data-page
    v-if="$can('brand', 'create') || $can('brand', 'view')"
    ref="dataPage"
    set-api="setBrand"
    get-api="getBrand"
    empty-store-variable="allBrands"
    set-image-api="setBrandImage"
    route-name="brands"
    :name="$t('prod.brand')"
    gate="brand"
    :validation-keys="['title', 'slug']"
    :file-keys="['id', 'status']"
    :result="result"
    @result="result = $event"
  >

    <template v-slot:form="{hasError}">

      <div class="input-wrapper">
        <label>{{ $t('index.title') }}</label>
        <input
          type="text"
          :placeholder="$t('index.title')"
          name="title"
          v-model="result.title"
          ref="title"
          @change="slugChange"
          :class="{invalid: !!!result.title && hasError}"
        >
        <span
          class="error"
          v-if="!!!result.title && hasError"
        >
          {{ $t('category.req', { type: $t('index.title')}) }}
        </span>
      </div>



      <div class="input-wrapper">
        <label>{{ $t('category.slug') }}</label>
        <input
          type="text"
          :placeholder="$t('category.slug')"
          name="slug"
          v-model="result.slug"
          ref="slug"
          :class="{invalid: !!!result.slug && hasError}"
        >
        <span
          class="error"
          v-if="!!!result.slug && hasError"
        >
          {{ $t('category.req', { type: $t('category.slug')}) }}
        </span>
      </div>

      <div class="input-wrapper">
        <div class="dply-felx j-left mb-20 mb-sm-15">
          <span class="mr-15">
            {{$t('category.featured')}}
          </span>

          <dropdown
            :selectedKey="`${result.featured}`"
            :options="featuredObj"
            @clicked="featuredSelected"
          />
        </div>
      </div>


      <div class="input-wrapper">
        <div class="dply-felx j-left mb-20 mb-sm-15">
          <span class="mr-15">
            {{ $t('category.status') }}
          </span>

          <dropdown
            :selectedKey="`${result.status}`"
            :options="statusObj"
            @clicked="dropdownSelected"
          />
        </div>
      </div>

    </template>
  </data-page>
</template>

<script>

  import Dropdown from "~/components/Dropdown"
  import DataPage from "~/components/partials/DataPage"
  import util from "~/mixin/util"
  import {mapGetters} from 'vuex'

  export default {
    name: "brands",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        result: {
          id: '',
          title: '',
          slug: '',
          featured: 2,
          status: 2,
          image: ''
        }
      }
    },
    mixins: [util],
    components: {
      DataPage,
      Dropdown
    },
    computed: {
      ...mapGetters('language', ['currentLanguage']),
    },
    methods: {
      featuredSelected(data) {
        this.result.featured = data.key
      },
      dropdownSelected(data) {
        this.result.status = data.key
      },

    },
    async mounted() {
    }
  }
</script>

<style scoped>

</style>
