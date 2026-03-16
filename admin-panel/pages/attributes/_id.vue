<template>
  <data-page
    ref="dataPage"
    set-api="setAttribute"
    get-api="getAttribute"
    empty-store-variable="allAttributes"
    route-name="attributes"
    :name="$t('fSale.attr')"
    :validation-keys="['title']"
    :result="result"
    gate="attribute"
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
          :class="{invalid: !!!result.title && hasError}"
        >
        <span
          class="error"
          v-if="!!!result.title && hasError"
        >
          {{ $t('category.req', { type: $t('index.title')}) }}
        </span>
      </div>

      <p class="info-msg mb-20 mb-sm-15">{{ $t('brand.delAttr') }}</p>

      <div class=" input-wrapper mb-5">
        <label>{{ $t('brand.aVal') }}</label>
        <div class="attribute-value-wrapper mlr--7-5">
          <input
            v-for="(a, i) in result.values"
            type="text"
            placeholder="Name"
            v-model="result.values[i].title"
            class="mlr-7-5"
          >
          <button
            class="lite-btn mlr-7-5"
            @click.prevent="addAttributeValue"
          >
            {{ $t('brand.addAttr') }}
          </button>
        </div>

      </div>
    </template>
  </data-page>
</template>

<script>

  import DataPage from "~/components/partials/DataPage";

  export default {
    name: "attributes",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        result: {
          id: '',
          title: '',
          values: []
        }
      }
    },
    mixins: [],
    components: {
      DataPage
    },
    computed: {},
    methods: {
      addAttributeValue() {
        this.result.values.push({title: ''})
      },
    },
    async mounted() {
    }
  }
</script>

<style lang="stylus">
  .attribute-value-wrapper
    display flex
    flex-wrap wrap

    input
      min-width 100px
      flex 1
      width auto
      margin-bottom 15px
</style>
