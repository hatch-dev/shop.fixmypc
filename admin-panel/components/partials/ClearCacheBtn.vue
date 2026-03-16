<template>
  <ajax-button
    type="button"
    class="clear-cache"
    loading-text=""
    :color="color"
    :text="$t('setting.cClear')"
    :fetching-data="clearingCache"
    @clicked="clearCache"
  />

</template>

<script>

  import { mapActions} from 'vuex'
  import AjaxButton from "~/components/AjaxButton";
  export default {
    name: 'ClearCacheBtn',
    data() {
      return {
        clearingCache: false,
      }
    },
    props: {
      color: {
        type: String,
        default: 'white',
      },
    },
    mixins: [],
    components: {AjaxButton},
    computed: {

    },
    methods: {
      async clearCache() {
        this.clearingCache = true
        await this.setRequest({
          params: {},
          api: 'clearCache'
        })
        this.clearingCache = false
      },
      ...mapActions('common', ['setRequest']),
    },
    mounted() {
    }
  }
</script>

