<template>
  <div class="side-block category-wrapper" v-if="categories && categories.length">
    <h3>
      {{ $t('searchPopup.categories') }}
      <button
        v-if="backBtn"
        type="button"
        aria-label="Go back"
        @click.prevent="$emit('go-back')"
      >
        {{ $t('date.gb') }}
      </button>
    </h3>

    <ul class="tree">
      <tree-node
        v-for="value in categories"
        :node="value"
        :key="value.id"
      />
    </ul>
  </div>
</template>

<script>

  import util from '~/mixin/util'
  import {mapGetters} from 'vuex'
  import TreeNode from "./TreeNode";

    export default {
      name: "FilterCategory",
      props: {
        categories: {
          type: Array,
          default: []
        },
        backBtn: {
          type: Boolean,
          default: false
        },
      },
      data() {
        return {

        }
      },
      components: {
        TreeNode

      },
      mixins: [util],
      computed: {
        categorySlug(){
          return this.$route.params?.category || null
        },
      },
      mounted() {
      },
      methods: {

      },
    }
</script>

<style scoped>

</style>
