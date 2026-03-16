<template>
  <div class="dply-felx j-right">
    <ul class="pagination" v-if="totalPage > 1">
      <li :class="{disabled: currentPage === 1}" @click.prevent="navigate(-1)">
        <i class="icon arrow-left black"></i>
      </li>

      <!-- <li class="page" :class="{disabled: currentPage === value}"
          v-for="(value, index) in allPages.slice(getActivePages[0], getActivePages[1])"
          :key="index"
          @click.prevent="paginate(value)"><span>{{ value }}</span>
      </li> -->

      <li class="page"
          :class="{ disabled: currentPage === page }"
          v-for="page in visiblePages"
          :key="page"
          @click.prevent="paginate(page)">
        <span>{{ page }}</span>
      </li>
      <li :class="{disabled: currentPage === totalPage}" @click.prevent="navigate(1)">
        <i class="icon arrow-right black">&nbsp;</i>
      </li>
    </ul>
  </div>

</template>

<script>
  export default {
    name: 'Pagination',
    data() {
      return {
        sortByType: this.$route.query.orderByType || 'desc',
        sortBy: this.$route.query.orderBy || 'created_at',
        currentPage: Number(this.$route.query.page) || this.page,
        search: this.$route.query.q || null
      }
    },
    props: {
      changingRoute: {
        type: Boolean,
        default: true
      },
      page: {
        type: Number,
        default: 1
      },
      totalPage: {
        type: Number
      },
      pagePer: {
        type: Number,
        default: 5
      }
    },
    watch:{
    },
    directives: {},
    components: {},
    mixins: [],
    computed: {
      visiblePages() {
        const total = this.totalPage
        const current = this.currentPage
        const per = this.pagePer

        let start = current - Math.floor(per / 2)
        let end = current + Math.floor(per / 2)

        // First page case
        if (start < 1) {
          start = 1
          end = per
        }

        // Last page case
        if (end > total) {
          end = total
          start = Math.max(1, total - per + 1)
        }

        const pages = []
        for (let i = start; i <= end; i++) {
          pages.push(i)
        }

        return pages
      }
    },
    methods: {
      routeParam() {
        if(this.isDefaultPage()){
          this.resettingRoute()
        }else{
          this.clearQuery()
          this.$emit('fetching-data')
        }

        this.scrollToTop()
      },
      resettingRoute(routeParams = {}){
        this.clearQuery()
        this.$router.push({
          query: {
            ...routeParams,
            ...{
              orderBy: this.orderBy,
              orderByType: this.orderByType,
              page: this.currentPage,
              q: this.search,
            }
          }
        })
        this.$emit('fetching-data')
      },
      clearQuery(){
        this.orderByType = 'desc'
        this.orderBy = 'created_at'
        this.currentPage = 1
      },
      isDefaultPage(){
        return (this.orderByType === 'desc' && this.orderBy === 'created_at' && this.currentPage === 1)
      },
      settingRoute() {
        this.$router.push({
          query: {
            ...this.$route.query,
            ...{
              orderBy: this.orderBy,
              orderByType: this.orderByType,
              page: this.currentPage,
              q: this.search,
            }
          }
        })
      },
      dropdownSelected(isOrderType, data) {
        this.currentPage = 1
        if (isOrderType)
          this.orderByType = data.key
        else
          this.orderBy = data.key

        this.getDataWithRoute()
      },
      scrollToTop() {
        window.scrollTo(0,0);
      },
      getDataFromRoute() {
        this.sortByType = this.$route.query.orderByType || 'desc'
        this.sortBy = this.$route.query.orderBy || 'created_at'
        this.currentPage = Number(this.$route.query.page) || 1
        this.search = this.$route.query.q || null

        this.$emit('fetching-data')
      },
      getDataWithRoute() {
        if(this.changingRoute){
          this.settingRoute()
          this.scrollToTop()
        }
        this.$emit('fetching-data', {
          orderBy: this.orderBy,
          orderByType: this.orderByType,
          page: this.currentPage,
          q: this.q
        })
      },
      navigate(param) {
        if ((param > 0 && this.currentPage >= this.totalPage) || (param < 0 && this.currentPage <= 1)){
          return
        }

        this.currentPage += param

        this.getDataWithRoute()
      },
      paginate(page) {
        if (this.currentPage !== page) {
          this.currentPage = page
          this.getDataWithRoute()
        }
      },
      loadData(){
        this.getDataFromRoute()
      },
    },
    destroyed() {
      window.removeEventListener('popstate', this.loadData)
    },
    mounted() {
      window.addEventListener('popstate', this.loadData)
    }
  }
</script>
<style lang="stylus">
  @import "~/assets/styles/pagination.styl"
</style>
