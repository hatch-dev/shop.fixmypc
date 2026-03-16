export default {
  data() {
    return {
      listParams: {
        type: 'desc',
        orderby: 'created_at',
        page: 1,
        q: null
      }
    }
  },
  methods: {
    settingRouteParam() {
      this.listParams = {
        ...this.listParams,
        ...{
          type: this.$route.query.orderbyType || 'desc',
          orderby: this.$route.query.orderby || 'created_at',
          page: Number(this.$route.query.page) || 1,
          q: this.$route.query.q || null
        }
      }
    }
  }
}
