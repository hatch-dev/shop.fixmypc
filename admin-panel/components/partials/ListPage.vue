<template>
  <div :class="{loading: loading}">

    <div
      v-if="deleting"
      class="spinner-wrapper"
    >
      <spinner
        :radius="60"
        color="primary"
        class="mr-15"
      />
    </div>

    <slot
      name="table-top"
      v-bind:orderOptions="orderOptions"
    >
      <table-top
        :title="name"
        :gate="gate"
        :add-button="addButton"
        :order-by-options="orderOptions"
        @delete-bulk="deleteBulk"
      >
      <template v-slot:extra-filters>
        <slot name="extra-filters" />
      </template>
        <slot
          name="add-button"
        >
        </slot>
      </table-top>
    </slot>

    <transition
      v-if="!gate || $can(gate, 'view')"
      name="fade" mode="out-in"
    >
      <div class="list-page-body p-20" v-if="!loading">
        <div v-if="errorMessage" class="empty-state danger-msg" role="alert">
          <h5>{{ $t('error.err') || 'Unable to load data' }}</h5>
          <p>{{ errorMessage }}</p>
          <button class="outline-btn xs mt-10" @click.prevent="fetchingData">
            {{ $t('util.retry') || 'Retry' }}
          </button>
        </div>

        <template v-else>
          <div class="list-summary sided f-wrap gap-10">
            <h5>{{ resultText }}</h5>
          </div>

          <div v-if="hasRows" class="n-product-table">
          <div class="table-wrapper">
            <table class="mn-w-600x">
              <slot
                name="table"
                v-bind:list="list"
              />
            </table>
          </div>
          </div>

          <div v-else class="empty-state card">
            <h5>{{ $t('list.noData', { data: name}) }}</h5>
            <p>{{ $t('list.sh') }}</p>
          </div>

          <pagination
            v-if="totalPage > 1"
            :total-page="totalPage"
          />
        </template>
        </div>
      <shimmer
        v-else
      />
    </transition>

  </div>
</template>

<script>
  import Shimmer from '~/components/Shimmer'
  import util from '~/mixin/util'
  import {mapGetters, mapActions} from 'vuex'
  import Pagination from "~/components/partials/Pagination"
  import TableTop from "~/components/partials/TableTop"
  import routeParamHelper from "~/mixin/routeParamHelper"
  import Spinner from "~/components/Spinner"

  export default {
    name: "ListPage",
    props: {
      addButton: {
        type: Boolean,
        default: true
      },
      name: {
        type: String,
        default: ''
      },
      gate: {
        type: String,
        default: null
      },
      listApi: {
        type: String,
        default: ''
      },
      deleteApi: {
        type: String,
        default: ''
      },
      routeName: {
        type: String,
        default: ''
      },
      emptyStoreVariable: {
        type: String,
        default: null
      },
      orderOptions: {
        type: Object,
        default() {
          return {
            created_at: { title: this.$t('category.date') },
            title: { title: this.$t('index.title') },
            status: { title: this.$t('category.status') }
          }
        }
      },
    },
    data(){
      return {
        deleting: false,
        loading: true,
        result: null,
        errorMessage: '',
      }
    },
    components: {
      Spinner,
      Pagination,
      TableTop,
      Shimmer
    },
    mixins: [util, routeParamHelper],
    computed: {
      resultText() {
        if (this.result) {
          if(this.result?.total > 0){
            return this.$t('list.show', { from: this.result?.from, to: this.result?.to, total: this.result?.total })
          }
          return this.$t('list.noData', { data: this.name})
        }
        return this.$t('list.loadn') + '...'
      },
      list() {
        return this.result?.data || []
      },
      hasRows() {
        return Array.isArray(this.list) && this.list.length > 0
      },
      totalPage() {
        return this.result?.last_page
      },
      ...mapGetters('language', ['currentLanguage']),
    },
    methods: {
      deleteBulk(){
        this.$emit('delete-bulk')
      },
      async fetchingData() {
        try {
          this.settingRouteParam()
          this.loading = true
          this.errorMessage = ''
          this.result = await this.getRequest({
            params: {
              ...this.$route.query,
              ...this.listParams,
              ...{time_zone: this.timeZone}
            },
            api: this.listApi
          })

          this.$emit('list', this.list)
        } catch (e) {
          this.errorMessage = e?.message || this.$t('error.err') || 'Something went wrong'
          this.$store.dispatch('ui/setToastError', this.errorMessage)
        } finally {
          this.loading = false
        }
      },
      editItem(id) {
        return this.$router.push(`/${this.routeName}/${id}`)
      },
      async deleteItem(id) {
        if (confirm(this.$t('admin.dltMsg'))) {
          try {
            this.deleting = true
            await this.deleteData({params: id, api: this.deleteApi })
            this.emptyAllList(this.emptyStoreVariable)
            this.$emit('deleted')
            await this.fetchingData()
          }catch (e) {
            this.$store.dispatch('ui/setToastError', e?.message || this.$t('error.err') || 'Unable to delete')
          } finally {
            this.deleting = false
          }
        }
      },
      ...mapActions('common', ['deleteData', 'getRequest', 'emptyAllList'] )
    },
    mounted() {
      if(!this.gate || this.$can(this.gate, 'view')){
        this.fetchingData()
      }
    }
  }
</script>

<style scoped>
.list-page-body {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.list-summary {
  min-height: 32px;
}

.empty-state {
  padding: 24px;
  text-align: center;
}

.empty-state h5 {
  font-weight: 700;
  margin-bottom: 6px;
}

.empty-state p {
  color: var(--text-muted);
  line-height: 1.5;
}
</style>
