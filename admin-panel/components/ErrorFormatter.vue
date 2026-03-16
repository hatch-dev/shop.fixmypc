<template>
  <ul class="error-list mb-15" v-if="errorData">
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
      }
    },
    computed: {
      errorData(){
        return this.errors && this.errors[this.type] !== undefined ? this.errors[this.type] : null
      },
      ...mapGetters('ui', ['errors'])
    },
  }
</script>

<style lang="stylus">
</style>
