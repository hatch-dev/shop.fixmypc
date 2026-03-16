<template>
  <div
    class="inline-pop-wrapper"
    v-outside-click="closePop"
  >
    <button
      @click.prevent="togglePop"
    >
      <slot
        name="button"
      />
      <i
        v-if="arrow"
        class="icon black ignore-click"
        :class="[{'arrow-up': showPopover}, {'arrow-down': !showPopover}]"
      />

    </button>
    <div
      v-if="showPopover"
      class="inline-pop-over"
    >
        <h4 v-if="title" class="pop-header ptb-10 plr-15">{{ title }}</h4>
        <div class="pop-content sb sb-2">
          <slot
            name="content"
          />
        </div>
        <div
          v-if="hasFooterSlot"
          class="pop-footer"
        >
          <slot
            name="footer"
          />
        </div>
      </div>
    </div>
</template>

<script>
  import outsideClick from '~/directives/outside-click.js'

  export default {
    name: 'InlinePopOver',
    components: {},
    directives: {outsideClick},
    props: {
      arrow: {
        type: Boolean,
        default: false,
      },
      title: {
        type: String,
        default: '',
      },
    },
    computed: {
      hasFooterSlot() {
        return !!this.$slots['footer']
      }
    },
    data() {
      return {
        showPopover: false
      }
    },
    methods: {
      togglePop(){
        this.showPopover = !this.showPopover
        if(this.showPopover) {
          this.$emit('open')
        }
      },
      closePop(){
        this.showPopover = false
      }
    },
    mounted() {}
  }
</script>

<style lang="stylus">
  @import "~/assets/styles/inline-pop-over.styl"
</style>
