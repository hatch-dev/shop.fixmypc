<template>
  <div class="order-chart card mtb-20 mtb-sm-15">
    <div class="dply-felx right mb-10 mb-xs-5">
      <dropdown
        v-if="months"
        class="right-dropdown"
        :options="months"
        @clicked="dropdownSelected"
      />
    </div>

    <div
      class="oflow-auto"
      style="height: 360px"
    >
      <div
        v-if="orderDataset"
        class="mn-w-600x h-100"
      >
        <sell-chart
          class=" h-100"
          :chart-month="currentMonth"
          :chart-data="orderDataset"
        />
      </div>
      <div
        v-else
        class="h-100 dply-felx j-center"
      >
        <spinner
          :radius="60"
          color="primary"
          class="mr-15"/>
      </div>
    </div>
  </div>
</template>

<script>
  import Spinner from '~/components/Spinner'
  import Dropdown from '~/components/Dropdown'
  import SellChart from '~/components/partials/SellChart'

  export default {
    name: 'OrderChart',
    data() {
      return {
        months: null,
        selectedMonth: null,
        monthNames: ["January", "February", "March", "April", "May", "June",
          "July", "August", "September", "October", "November", "December"
        ]
      }
    },
    props: {
      chartMonth: {
        type: Object
      },
      monthlyOrder: {
        type: Array
      }
    },
    components: {
      Dropdown,
      SellChart,
      Spinner
    },
    computed: {
      currentMonth() {
        return {...this.chartMonth, ...{name: this.currentMonthName}}
      },
      currentMonthName() {
        return this.monthNames[this.chartMonth?.month - 1] || ''
      },
      orderDataset() {
        if (this.monthlyOrder) {
          let chartDate = []
          let chartValue = []
          let data = {}
          this.monthlyOrder.forEach(obj => {
            data[obj.time] = {value: obj.value, total: obj.total}
          })

          let date = new Date(),
            y = this.chartMonth?.year || date.getFullYear(),
            m = this.chartMonth?.month || date.getMonth() + 1;

          let lastDay = new Date(y, m, 0);

          for (let i = 1; i <= lastDay.getDate(); i++) {
            let d = 0
            let dateStr = i;

            if (i < 10) {
              dateStr = `0${i}`
            }
            if (m < 10) {
              d = `${y}-0${m}-${dateStr}`
            } else {
              d = `${y}-${m}-${dateStr}`
            }

            chartDate.push(dateStr)
            if (data[d] !== undefined) {
              chartValue.push(data[d].value)
            } else {
              chartValue.push(0)
            }
          }
          return {
            labels: chartDate,
            datasets: [
              {
                label: 'Sell',
                backgroundColor: "rgba(255, 108, 110, 0.6)",
                borderColor: "rgba(255, 108, 110, .7)",
                pointBackgroundColor: "rgba(255, 108, 110, 0.5)",
                pointBorderColor: "rgba(255, 108, 110, 1)",
                pointHoverBackgroundColor: '#aaa',
                pointHoverBorderColor: "rgba(255, 108, 110, 0.5)",
                pointBorderWidth: 6,
                data: chartValue
              }
            ]
          }
        }
        return null
      },
    },
    methods: {
      calcMonths() {
        const m = this.chartMonth?.month
        let y = this.chartMonth?.year
        const my = {}

        for (let i = m; i >= 1; i--) {
          my[`${i}-${y}`] = {title: `${this.monthNames[i - 1]}, ${y}`}
        }
        y--
        if (m !== 12) {
          for (let i = 12; i > m; i--) {
            my[`${i}-${y}`] = {title: `${this.monthNames[i - 1]}, ${y}`}
          }
        }

        return my
      },
      dropdownSelected(data) {
        const splited = data.key.split('-')
        this.selectedMonth = {
          month: splited[0],
          year: splited[1]
        }
        this.$emit('month-changed', this.selectedMonth)
      },
    },
    destroyed() {
    },
    mounted() {
      this.months = this.calcMonths()
    }
  }
</script>

<style lang="stylus">
  @import "~/assets/styles/order-chart.styl"
</style>
