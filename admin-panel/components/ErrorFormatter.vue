<template>
  <ul class="error-list mb-15" v-if="errorData && errorData.length">
    <li class="mb-10">{{ $t('error.feo') }}</li>
    <li
      v-for="(value, index) in errorData"
      :key="index"
    >
      {{ value }}
    </li>
  </ul>
</template>

<script>
  import {mapGetters} from 'vuex'

  export default {
    name: 'ErrorFormatter',
    props: {
      type: {
        type: String,
        default: 'form'
      },
      /*
       * An explicit list to render instead of whatever sits in the store
       * under `type`. A form that puts each message against the control that
       * caused it passes the leftovers here, so the summary box carries only
       * the ones with nowhere else to go instead of repeating the lot.
       * Left null, the component behaves exactly as it always has.
       */
      messages: {
        type: Array,
        default: null
      }
    },
    computed: {
      errorData(){
        if (this.messages) {
          return this.messages
        }

        return this.errors && this.errors[this.type] !== undefined ? this.errors[this.type] : null
      },
      ...mapGetters('ui', ['errors'])
    },
  }
</script>

<style lang="stylus">
</style>
