<template>
  <div
    class="pop-over"
    :class="{'has-layer': hasLayer}"
  >
    <div
      class="pop-layer"
    />
    <div
      class="pop-over-inner"
      :id="elemId"
      v-outside-click="outsideClickFn"
    >
      <div
        v-if="!noHeader"
        class="pop-heading flex sided plr-15 plr-xs-10 ptb-10 b-b pos-rel"
      >
        <slot
          name="heading">
          <h4>
            {{ title }}
          </h4>
        </slot>

        <button
          class="right-btn close-btn pos-static no-shadow"
          @click.prevent="closePopOver"
        >
          <i
            class="icon close-icon"
          />
        </button>
      </div>

      <div class="pop-over-content p-15 p-xs-10 sb">
        <slot
          name="content"
        />
      </div>
      <div
        v-if="hasFooterSlot"
        class="pop-footer b-t plr-15 plr-xs-10 ptb-10"
      >
        <slot
          name="pop-footer"
        />
      </div>
    </div>
  </div>
</template>

<script>
  import outsideClick from '~/directives/outside-click.js'

  export default {
    name: 'PopOver',
    components: {},
    directives: {outsideClick},
    props: {
      title: {
        type: String,
        default: '',
      },
      elemId: {
        type: String,
        default: '',
      },
      layer: {
        type: Boolean,
        default: false,
      },
      noHeader: {
        type: Boolean,
        default: false,
      },
      outsideClickOn: {
        type: Boolean,
        default: true,
      }
    },
    computed: {
      isSmallerDevice(){
        return window.innerWidth < 992
      },
      hasFooterSlot() {
        return !!this.$slots['pop-footer']
      }
    },
    data() {
      return {
        hasLayer: this.layer,
      }
    },
    methods: {
      outsideClickFn(){
        if(this.outsideClickOn){
          this.closePopOver()
        }
      },
      closePopOver() {
        this.$emit('close')
      }
    },
    mounted() {
      if(this.isSmallerDevice){
        this.hasLayer = true
      }
      if (this.hasLayer) {
        document.body.classList.add('no-scroll')
      }
    },
    destroyed() {
      document.body.classList.remove('no-scroll')
    }
  }
</script>

<style lang="stylus">
  @import "~/assets/styles/pop-over.styl"
</style>
