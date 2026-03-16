<template>
  <list-page
    ref="listPage"
    list-api="getSubscriptionEmailFormats"
    delete-api="deleteSubscriptionEmailFormat"
    route-name="subscription-email-formats"
    :name="$t('dataPage.sef')"
    gate="subscription_email_format"
    empty-store-variable="allSubscriptionEmailFormats"
    :order-options="orderOptions"
  >
    <template v-slot:table="{list}">
      <tr class="lite-bold">
        <th>{{ $t('index.title') }}</th>
        <th>{{ $t('util.sub') }}</th>
        <th>{{ $t('category.created') }}</th>
        <th/>
      </tr>

      <tr v-for="(value, index) in list" :key="index">
        <td>{{ value.title }}</td>
        <td>{{ value.subject }}</td>
        <td>{{ value.created }}</td>
        <td>

          <button
            v-if="$can('subscription_email_format', 'edit')"
            @click.prevent="$refs.listPage.editItem(value.id)" class="lite-btn">{{ $t('category.edit') }}</button>
          <button
            v-if="$can('subscription_email_format', 'delete')"
            @click.prevent="$refs.listPage.deleteItem(value.id)" class="delete-btn lite-btn"
          >
            {{ $t('category.delete') }}
          </button>

        </td>
      </tr>
    </template>
  </list-page>
</template>

<script>
  import ListPage from "~/components/partials/ListPage"
  import util from '~/mixin/util'
  import outsideClick from "~/directives/outside-click"

  export default {
    name: "subscription-email-formats",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        orderOptions: {
          title: { title: this.$t('index.title') },
          subject: { title: this.$t('util.sub') },
          created_at: { title: this.$t('category.date') },
        }
      }
    },
    directives: {outsideClick},
    components: {
      ListPage
    },
    mixins: [util],
    computed: {
    },
    methods: {
    },
    async mounted() {
    }
  }
</script>
<style lang="stylus">
</style>

