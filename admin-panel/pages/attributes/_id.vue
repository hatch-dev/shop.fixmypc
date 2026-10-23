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

      <div class="input-wrapper mb-5">
        <label>{{ $t('brand.aVal') }}</label>

        <div class="attribute-value-wrapper">
          <template v-for="(a, i) in result.values">
            <div
              v-if="!a._deleted"
              :key="i"
              class="attr-value-item"
            >
              <input
                v-if="isColorAttribute"
                type="color"
                class="attr-color-input"
                :value="a.color || '#000000'"
                @input="setColor(i, $event.target.value)"
                :title="a.color || $t('pages.attributes.pickColour')"
              >
              <input
                type="text"
                :placeholder="$t('index.name')"
                v-model="a.title"
                class="attr-value-input"
              >
              <button
                type="button"
                class="attr-value-remove"
                :title="$t('category.delete')"
                @click.prevent="removeAttributeValue(i)"
              >
                <span aria-hidden="true">&times;</span>
              </button>
            </div>
          </template>
        </div>

        <button
          type="button"
          class="lite-btn mt-10"
          @click.prevent="addAttributeValue"
        >
          {{ $t('brand.addAttr') }}
        </button>
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
    computed: {
      isColorAttribute() {
        return /colou?r/i.test(this.result.title || '')
      }
    },
    methods: {
      addAttributeValue() {
        this.result.values.push({title: '', color: ''})
      },
      setColor(i, value) {
        this.$set(this.result.values[i], 'color', value)
      },
      removeAttributeValue(i) {
        const value = this.result.values[i]
        if (value && value.id) {
          // Existing value: clear the title and flag it so the backend
          // (which deletes values submitted with an empty title) removes it.
          this.$set(this.result.values[i], 'title', '')
          this.$set(this.result.values[i], '_deleted', true)
        } else {
          // Unsaved value: just drop it from the list.
          this.result.values.splice(i, 1)
        }
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
    gap 10px

    .attr-value-item
      display flex
      align-items center
      gap 6px
      padding 3px 3px 3px 6px
      border 1px solid var(--border-strong)
      border-radius var(--radius-md)
      background var(--surface-color)
      transition border-color .12s ease, box-shadow .12s ease

      &:focus-within
        border-color var(--primary-color)
        box-shadow 0 0 0 3px var(--primary-soft)

    .attr-color-input
      width 30px !important
      height 30px !important
      min-width 30px
      padding 0 !important
      border 1px solid var(--border-color) !important
      border-radius 6px !important
      background none !important
      box-shadow none !important
      cursor pointer
      flex-shrink 0

    .attr-color-input::-webkit-color-swatch-wrapper
      padding 2px

    .attr-color-input::-webkit-color-swatch
      border none
      border-radius 4px

    .attr-value-input
      width 120px
      min-width 90px
      height 30px !important
      padding 0 6px !important
      border none !important
      background transparent
      box-shadow none !important

      &:focus
        border none !important
        box-shadow none !important

    .attr-value-remove
      display flex
      align-items center
      justify-content center
      width 26px
      height 26px
      min-width 26px
      padding 0
      border none
      border-radius 6px
      background var(--danger-bg)
      color var(--danger-text)
      font-size 18px
      line-height 1
      cursor pointer
      flex-shrink 0
      transition background .12s ease, color .12s ease

      &:hover
        background var(--danger-text)
        color #fff
</style>
