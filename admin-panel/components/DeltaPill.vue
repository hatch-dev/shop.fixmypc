<template>
  <span
    v-if="isValid"
    class="delta-pill"
    :class="[direction, { 'is-small': small, 'is-muted': muted }]"
    :title="title"
  >
    <i v-if="comparable" class="fa" :class="num >= 0 ? 'fa-arrow-up' : 'fa-arrow-down'"></i>
    {{ label }}
    <span v-if="!small && comparable" class="delta-pill__suffix">{{ $t('index.vsPrevious') }}</span>
  </span>
</template>

<script>
  /**
   * Period-over-period change, e.g. "↑ 18.4% vs previous period".
   *
   * The figure is always passed in from the API, which derives it from two real
   * aggregate queries over the selected window and the equally long window
   * before it. Nothing here invents or rounds a trend.
   *
   * `muted` says the previous period had no orders at all. The arithmetic still
   * yields +100%, but a percentage against a baseline of zero states a precision
   * that does not exist - "up 100% on nothing" is not a fact about the business.
   * Such a period reads "New" instead, and a muted non-gain (nothing then,
   * nothing now) is not drawn at all: there is no change to report.
   */
  export default {
    name: 'DeltaPill',
    props: {
      value: {
        type: [Number, String],
        default: null
      },
      small: {
        type: Boolean,
        default: false
      },
      muted: {
        type: Boolean,
        default: false
      }
    },
    computed: {
      num() {
        return Number(this.value)
      },
      /**
       * `muted` is the API's baseline_empty and is authoritative on its own:
       * when the previous period held no orders the API sends a null change,
       * because no percentage exists against a baseline of zero. That is "new",
       * not "missing" - so the pill still draws, neutrally, and says so.
       */
      isValid() {
        if (this.muted) {
          /*
           * The previous period held no orders. The API sends null when this
           * one did (something appeared from nothing - worth saying) and 0.0
           * when it did not either, which is not news about the business and
           * draws nothing at all.
           */
          return this.value === null || this.num > 0
        }
        // A null change still says something - "no comparable period" - so it
        // draws a dash. Only an absent prop renders nothing.
        return this.value !== undefined
      },
      comparable() {
        return !this.muted && this.value !== null && this.value !== undefined && !isNaN(this.num)
      },
      label() {
        if (this.muted) return this.$t('index.fromZero')
        if (!this.comparable) return '—'
        return `${this.num >= 0 ? '+' : ''}${this.num}%`
      },
      direction() {
        if (this.muted || !this.comparable) return 'is-neutral'
        if (this.num > 0) return 'is-up'
        if (this.num < 0) return 'is-down'
        return 'is-flat'
      },
      title() {
        return this.muted
          ? this.$t('index.noBaseline')
          : this.$t('index.vsPrevious')
      }
    }
  }
</script>

<style lang="stylus">
  @import "~/assets/styles/variables.styl"

  .delta-pill
    display inline-flex
    align-items center
    gap 4px
    padding 2px 8px
    border-radius 999px
    font-size 12px
    font-weight 700
    line-height 1.5
    white-space nowrap

    i
      font-size 9px

    &.is-up
      background success-bg-color
      color success-foreground

    &.is-down
      background danger-bg-color
      color danger-foreground

    &.is-flat,
    &.is-neutral
      background var(--hover-color)
      color var(--text-muted)

    &.is-small
      padding 1px 6px
      font-size 11px
      font-weight 600

    .delta-pill__suffix
      font-weight 500
      opacity .85
</style>
