<template>
  <div
    v-if="$can('footer_link', 'view')"
    class="tab-sidebar"
  >
    <h4 class="title">{{ $t('admin.footer') }}</h4>

    <div class="dply-felx">
      <ul class="left-area">
        <li
          v-for="(value, index) in tabs"
          :key="index"
          :class="{active : value.tabId === activeTab}"
          @click.prevent="tabSelect(value)"
        >
          {{ value.title }}
        </li>
      </ul>

      <div
        class="right-area pos-rel"
      >
        <div
          v-if="loading"
          class="spinner-wrapper"
        >
          <spinner
            :radius="60"
            color="primary"
          />
        </div>
        <footer-link
          v-if="tabId[0] === activeTab"
          :result="result"
          @remove-item="removeItem"
          @update-item="updateItem"
          @add-item="addItem"
          @result="result = Object.assign({}, $event)"
        />

        <footer-image-link
          v-if="tabId[1] === activeTab"
          :result="result"
          @delete-item="deleteFooterImageLink"

        />
      </div>

    </div>
  </div>
</template>

<script>
  import {mapGetters, mapActions} from 'vuex'
  import FooterLink from "~/components/partials/FooterLink"
  import Spinner from "~/components/Spinner"
  import FooterImageLink from "~/components/partials/FooterImageLink"

  export default {
    name: "footer-links",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        result: {
          about_links: [],
          service_links: [],
          payment_links: [],
          social_links: []
        },
        tabId: ['service-about', 'payment-social'],
        activeTab: 'service',
        tabs: [
          {
            title:  this.$t('admin.service'),
            tabId: 'service-about',
          },
          {
            title: this.$t('admin.payment'),
            tabId: 'payment-social',
          }
        ],
        loading: false,
      }
    },
    components: {
      FooterImageLink,
      Spinner,
      FooterLink
    },
    mixins: [],
    computed: {
      ...mapGetters('common', ['allPages'])
    },
    methods: {
      removeItem({source, index, deleted}){
        const v =  {
          ...this.result[source][index],
          ...{deleted: deleted}
        }
        this.result[source].splice(index, 1)
        this.result[source].splice(index, 0, v)
      },
      updateItem({source, index, value}){
        if(this.result[source][index]?.id){
          this.result[source][index] = {...this.result[source][index], ...{updated: true}}
        }
        const v = {...this.result[source][index], ...{page_id: value.key}}
        this.result[source].splice(index, 1)
        this.result[source].splice(index, 0, v)
      },
      addItem({source}){
        this.result[source].push({page_id: Object.keys(this.allPages)[0]})
      },
      tabSelect(val) {
        if (val.tabId !== this.$route.hash.replace('#', '')) {
          this.$router.push({
            hash: val.tabId
          })
        }
        this.activeTab = val.tabId
      },
      async fetchingData() {
        this.loading = true
        try {
          this.result = await this.getRequest({params: {}, api: 'getFooterLinks'})
        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.loading = false
      },
      async deleteFooterImageLink(id) {
        if (confirm(this.$t('admin.dltMsg'))) {
          try {
            this.loading = true
            await this.deleteData({params: id, api: 'deleteFooterImageLink' })
            await this.fetchingData()
          } catch (e) {
            return this.$nuxt.error(e)
          }
        }
      },
      ...mapActions('common', ['getAllList', 'getRequest', 'deleteData'])
    },
    async mounted() {
      this.loading = true
      this.activeTab = this.$route.hash ? this.$route.hash.replace('#', '') : this.tabs[0].tabId
      if (!this.allPages) {
        try {
          await this.getAllList({api: 'getAllPages', mutation: 'SET_ALL_PAGES'})
        } catch (e) {
          return this.$nuxt.error(e)
        }
      }
      if(this.$can('footer_link', 'view')) {
        await this.fetchingData()
      } else {
        this.loading = false
      }
    }
  }
</script>
