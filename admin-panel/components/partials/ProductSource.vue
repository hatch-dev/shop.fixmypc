<template>
  <div
    class="mb-20 mb-sm-15"
  >
    <div class="dply-felx">
      <h5 class="mb-10 capitalize">{{ title }}</h5>
      <button
        class="outline-btn mb-10"
        @click.prevent="addItem"
      >
        {{ $t('list.add', { title: title }) }}
      </button>
    </div>
    <div
      class="dply-felx j-left f-wrap mlr--7-5"
    >
      <div
        v-for="(value, index) in items"
        :key="index"
        class="mlr-7-5 dply-felx mt-10"
      >
        <dropdown
          :disabled="value.deleted"
          :selected-key="value[type].id"
          :options="allData"
          @clicked="itemSelected(index, $event)"
        />

        <button
          class="ml-5 close-btn"
          @click.prevent="removeItem(index)"
        >
          <i
            class="icon"
            :class="value.deleted ? 'undo-icon' : 'close-icon'"
          />
        </button>
      </div>
    </div>
  </div>
</template>

<script>
  import Dropdown from "~/components/Dropdown"

    export default {
        name: "ProductSource",
      data() {
        return {
        }
      },
      props: {
        title: {
          type: String,
          default: ''
        },
        type: {
          type: String,
          default: ''
        },
        allData: {
          type: Object,
          default: null
        },
        items: {
          type: Array,
          default: []
        },
      },
      mixins: [],
      components: {
        Dropdown,
      },
      computed: {
      },
      methods: {
        itemSelected(index, value){
          this.$emit('itemSelected', {index, value: value.key})
        },
        removeItem(index){
          let deleted = true
          if(this.items[index]?.deleted){
            deleted = false
          }
          this.$emit('removeItem', {index, deleted: deleted})
        },
        addItem(){
          this.$emit('addItem')
        }
      },
      async mounted() {
      }
    }
</script>

<style scoped>

</style>
