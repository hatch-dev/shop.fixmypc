<template>
  <div
    class="dply-felx inputs align-end j-left"
  >
    <div
      class="input-wrapper"
    >
      <label class="mb-10">{{ $t('prod.sTime') }}</label>
      <datetime
        format="YYYY-MM-DD"
        width="180px"
        v-model="filter.start_time"
        readonly
      />
    </div>

    <div
      class="input-wrapper"
    >
      <label class="mb-10">{{ $t('prod.eTime') }}</label>

      <datetime
        format="YYYY-MM-DD"
        width="180px"
        v-model="filter.end_time"
        readonly
      />

    </div>



    <ajax-button
      name="save-edit"
      class="primary-btn mlr-5 mtb-sm-5"
      :text="$t('ship.fil')"
      type="button"
      @clicked="filterChanged"
    />

    <button class="outline-btn" @click="clearTime">
      {{ $t('ship.cl') }}
    </button>
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
