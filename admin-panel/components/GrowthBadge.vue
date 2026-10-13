<template>
  <span
    class="growth-badge"
    :class="state"
    :title="title"
  >
    <template v-if="comparable">
      <i class="fa" :class="num >= 0 ? 'fa-arrow-up' : 'fa-arrow-down'" aria-hidden="true"></i>
      {{ num >= 0 ? '' : '-' }}{{ magnitude }}%
    </template>
    <template v-else>—</template>
  </span>
</template>

<script>
  /**
   * Month-to-date change against the same stretch of the previous month.
   *
   * The figure always comes from the API, which derives it from two aggregate
   * queries over equal-length windows. Nothing here invents a trend.
   *
   * A null value means the previous window held nothing, so no percentage
   * exists: this renders an em dash rather than a number. It used to arrive as
   * a literal 100 from the server, which put a confident green "+100%" on a
   * shop's first ever sale - the one case where the change is unmeasurable.
   */
  export default {
    name: 'GrowthBadge',
    props: {
      value: {
        type: [Number, String],
        default: null
      }
    },
    computed: {
      num() {
        return Number(this.value)
      },
      /** null, undefined and '' all mean "no previous period to compare". */
      comparable() {
        return this.value !== null && this.value !== undefined && this.value !== '' && !isNaN(this.num)
      },
      magnitude() {
        // One decimal, but only when it says something: 18.4% not 18.0%.
        const abs = Math.abs(this.num)
        return Number.isInteger(abs) ? abs : abs.toFixed(1)
      },
      state() {
        if (!this.comparable) return 'is-unknown'
        if (this.num > 0) return 'up'
        if (this.num < 0) return 'down'
        return 'is-flat'
      },
      title() {
        if (!this.comparable) return this.$t('index.noPrevPeriod')
        return this.$t('index.vsPrevMonth')
      }
    }
  }
</script>

<style lang="stylus">
  @import "~/assets/styles/variables.styl"

  .growth-badge
    display inline-flex
    align-items center
    gap 3px
    font-size 12px
    font-weight 600
    line-height 1
    white-space nowrap

    i
      font-size 9px

    &.up
      color success-foreground

    &.down
      color danger-foreground

    // Flat, and "there was nothing to compare against", are both neutral -
    // neither is good news or bad news.
    &.is-flat,
    &.is-unknown
      color var(--text-soft)
</style>
