<template>
  <div
    class="tab-sidebar pos-rel"
  >
    <h5 class="title bold">
      {{ $t('list.set') }}
    </h5>

    <div class="dply-felx">
      <ul class="left-area">
        <li
          v-for="(value, index) in tabs" :key="index"
          :class="{active : value.tabId === activeRoute}"
        >
          <nuxt-link
            :to="`/setting/${value.tabId}`"
          >
            {{ value.title }}
          </nuxt-link>

        </li>
      </ul>

      <div class="right-area">
        <error-formatter />

        <slot
          name="rightArea"
        />

      </div>
    </div>
  </div>

</template>

<script>

  import ErrorFormatter from "~/components/ErrorFormatter";


  export default {
    name: "SettingLayout",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        tabs: [
          {
            title: this.$t('setting.cur'),
            tabId: 'currency',
          },
          {
            title: this.$t('list.addr'),
            tabId: 'address',
          },
          {
            title: this.$t('list.lang'),
            tabId: 'languages',
          },
          {
            title: this.$t('list.sl'),
            tabId: 'social-login',
          },
          {
            title:  this.$t('list.smtp'),
            tabId: 'smtp',
          },
          {
            title: this.$t('title.ana'),
            tabId: 'analytics',
          },
          {
            title:  this.$t('list.payment'),
            tabId: 'payment',
          },
          {
            title: this.$t('list.ms'),
            tabId: 'media-storage',
          },
          {
            title: this.$t('dataPage.mis'),
            tabId: 'miscellaneous',
          },
          {
            title: this.$t('ship.pl'),
            tabId: 'plugins',
          },
          {
            title: this.$t('setting.cClear'),
            tabId: 'clear-cache',
          }
        ]
      }
    },
    props: {
      activeRoute:{
        type: String
      }
    },
    mixins: [],
    components: {
      ErrorFormatter
    },
    computed: {
    },
    methods: {
    },
    created() {
    },
    async mounted() {
    }
  }
</script>


