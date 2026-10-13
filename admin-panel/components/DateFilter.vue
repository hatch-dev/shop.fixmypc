<template>
  <div class="date-filter">
    <div class="date-filter__field">
      <label>{{ $t('prod.sTime') }}</label>
      <datetime
        format="YYYY-MM-DD"
        width="170px"
        v-model="filter.start_time"
        readonly
      />
    </div>

    <div class="date-filter__field">
      <label>{{ $t('prod.eTime') }}</label>
      <datetime
        format="YYYY-MM-DD"
        width="170px"
        v-model="filter.end_time"
        readonly
      />
    </div>

    <div class="date-filter__actions">
      <ajax-button
        name="save-edit"
        class="primary-btn"
        :text="$t('ship.fil')"
        type="button"
        @clicked="filterChanged"
      />

      <button type="button" class="outline-btn" @click="clearTime">
        {{ $t('ship.cl') }}
      </button>
    </div>
  </div>
</template>

<script>
  import datetime from 'vuejs-datetimepicker'
  export default {
    name: 'DateFilter',
    components: {
      datetime
    },
    directives: {},
    props: {

    },
    computed: {

    },
    data() {
      return {
        filter: {
          start_time: '',
          end_time: ''
        },
      }
    },
    methods: {
      filterChanged(){
        this.$emit('date-changed', this.filter)
      },
      clearTime(){
        this.filter.start_time = ''
        this.filter.end_time = ''
        this.filterChanged()
      },
    },
    mounted() {
      this.filter.start_time = this.$route?.query?.start_time
      this.filter.end_time = this.$route?.query?.end_time
    },
  }
</script>

<style lang="stylus" scoped>
  .date-filter
    display flex
    align-items center
    flex-wrap wrap
    gap 12px

  .date-filter__field
    display flex
    align-items center
    gap 8px

    label
      margin 0
      font-size 13px
      font-weight 600
      color var(--text-muted)
      white-space nowrap

  .date-filter__actions
    display flex
    align-items center
    gap 8px

    .primary-btn
    .outline-btn
      height 42px !important
      line-height 40px !important
      border-radius var(--radius-md)
</style>
