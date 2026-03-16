<template>

  <list-page
    v-if="$can('language', 'view')"
    ref="listPage"
    list-api="getLanguages"
    delete-api="deleteLanguage"
    route-name="setting/languages"
    :name="$t('profile.langLower')"
    gate="language"
    :order-options="orderOptions"
  >
    <template v-slot:table="{list}">
      <tr class="lite-bold">
        <th>{{ $t('user.name') }}</th>
        <th>{{ $t('setting.code') }}</th>
        <th>{{ $t('setting.dir') }}</th>
        <th>{{ $t('admin.default') }}</th>
        <th>{{ $t('category.status') }}</th>
        <th>&nbsp;</th>
      </tr>

      <tr v-for="(item, index) in list" :key="index">
        <td>
          <nuxt-link
            class="link"
            :to="`/setting/languages/${item.id}`"
          >
            <h5 class="mx-w-300x">{{ item.name }}</h5>
          </nuxt-link>
        </td>
        <td>{{ item.code }}</td>
        <td>{{ item.direction }}</td>
        <td
          class="status"
          :class="{active: item.default == 1 }"
        >
          <span>{{ getBoolean(item.default) }}</span>
        </td>
        <td
          class="status"
          :class="{active: item.status == 1 }"
        >
          <span>{{ getStatus(item.status) }}</span>
        </td>
        <td>
          <button
            v-if="$can('language', 'edit')"
            @click.prevent="$refs.listPage.editItem(item.id)" class="lite-btn">Edit
          </button>
          <button
            v-if="$can('language', 'delete')"
            @click.prevent="$refs.listPage.deleteItem(item.id)" class="delete-btn lite-btn"
          >
            {{ $t('category.delete') }}
          </button>
        </td>
      </tr>
    </template>
  </list-page>

</template>

<script>
  import util from "~/mixin/util"
  import ListPage from "./ListPage";

  export default {
    name: 'LanguageSetting',
    data() {
      return {
        orderOptions: {
          created_at: { title: this.$t('category.date') },
          name: { title: this.$t('user.name') },
          code: { title: this.$t('setting.code') },
          direction: { title: this.$t('setting.dir') },
          default: { title: this.$t('admin.default') },
          status: { title: this.$t('category.status') }
        }
      }
    },
    props: {},
    mixins: [util],
    components: {
      ListPage
    },
    computed: {},
    methods: {},
    created() {
    },
    async mounted() {
    }
  }
</script>
