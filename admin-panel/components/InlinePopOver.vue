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
      ref="pop"
      class="inline-pop-over"
      :class="alignClass"
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
        showPopover: false,
        // '' (the stylesheet's own side), 'align-left' or 'align-right'
        alignClass: ''
      }
    },
    methods: {
      togglePop(){
        this.showPopover = !this.showPopover
        if(this.showPopover) {
          this.$emit('open')
          this.alignClass = ''
          this.$nextTick(this.fitToView)
        }
      },
      closePop(){
        this.showPopover = false
      },
      /*
       * Keep the panel where it can be seen.
       *
       * The panel opens from the button's right edge (its left edge in RTL).
       * A button near the start of its row - the products table's "Action",
       * for one - then opened its panel past the start of the content area
       * and underneath the fixed sidebar, which covered all of "Delete" but
       * its last letter. When the panel spills out of the area it belongs to
       * (the table toolbar, or failing that the window), it is aligned to the
       * other edge of the button instead; a panel that fits is left alone.
       */
      fitToView(){
        const pop = this.$refs.pop

        if (!pop || typeof window === 'undefined') return

        const area = this.$el.closest('.filter-toolbar, .table-top')
        const box = area ? area.getBoundingClientRect() : { left: 0, right: window.innerWidth }
        const left = Math.max(box.left, 0)
        const right = Math.min(box.right, window.innerWidth)
        const rect = pop.getBoundingClientRect()

        if (rect.left < left) {
          this.alignClass = 'align-left'
        } else if (rect.right > right) {
          this.alignClass = 'align-right'
        }
      }
    },
    mounted() {}
  }
</script>

<style lang="stylus">
  @import "~/assets/styles/inline-pop-over.styl"
</style>
