<template>
  <canvas id="myChart"></canvas>
</template>

<script>
  import Chart from "chart.js"
  import {mapGetters} from "vuex";

  export default {
    props: {
      chartData: {
        type: Object
      },
      chartMonth: {
        type: Object
      },
    },
    computed: {
      currencyIcon(){
        return this.setting?.currency_icon || '$'
      },
      ...mapGetters('setting', ['setting'])
    },
    methods: {
      chartConstructor(chartType, chartData, chartOptions) {
        var ctx = document.getElementById('myChart').getContext('2d');
        new Chart(ctx, {
          type: chartType,
          data: chartData,
          options: chartOptions,
        })
      }
    },
    mounted() {
      const self = this
      this.chartConstructor('bar', this.chartData, {
        title: {
          display: false,
          text: this.$t('list.cct')
        },
        legend: {
          display: false
        },
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          y: {
            beginAtZero: true
          },
          yAxes: [
            {
              ticks: {
                callback: function (label) {
                  return `${self.currencyIcon}${label}`
                }
              }
            }
          ],
          xAxes: [
            {
              scaleLabel: {
                display: true,
                labelString: this.$t('list.dom')
              }
            }
          ]
        },
        showTooltips: false,
        tooltips: {
          displayColors: false,
          callbacks: {
            title: function (data) {
              return `${data[0].label} ${self.chartMonth?.name}, ${self.chartMonth?.year}`
            },
            label: function (tooltipItem) {
              return `${self.currencyIcon}${tooltipItem.yLabel}`
            }
          }
        }

      })

    }
  }
</script>
