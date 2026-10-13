<template>
  <span
    class="skeleton-block"
    :class="{ 'skeleton-block--circle': circle }"
    :style="style"
    aria-hidden="true"
  />
</template>

<script>
  /**
   * One grey shimmering placeholder, sized by the caller.
   *
   * Deliberately separate from the older components/Shimmer.vue, which renders
   * a fixed stack of five 65px table rows and is wired into ListPage and
   * Withdrawal. Dashboard cards need a single primitive they can size to the
   * real element it stands in for, so the layout does not jump when the data
   * lands.
   */
  export default {
    name: 'SkeletonBlock',
    props: {
      width: {
        type: String,
        default: '100%'
      },
      height: {
        type: String,
        default: '14px'
      },
      radius: {
        type: String,
        default: '6px'
      },
      circle: {
        type: Boolean,
        default: false
      }
    },
    computed: {
      style() {
        return {
          width: this.width,
          height: this.height,
          borderRadius: this.circle ? '50%' : this.radius
        }
      }
    }
  }
</script>

<style lang="stylus">
  @import "~/assets/styles/skeleton.styl"
</style>
