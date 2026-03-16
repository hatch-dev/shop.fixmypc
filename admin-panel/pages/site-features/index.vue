<template>
  <list-page
    v-if="$can('home_slider', 'view')"
    ref="listPage"
    list-api="getSiteFeatures"
    delete-api="deleteSiteFeature"
    route-name="site-features"
    :name="$t('title.sf')"
    gate="home_slider"
    :order-options="orderOptions"
    @delete-bulk="deleteBulk"
    @list="itemList = $event"
  >
    <template v-slot:table="{list}">
      <tr class="lite-bold">
        <th>
          <input type="checkbox" @change="checkAll">
        </th>
        <th>{{ $t('admin.img') }}</th>
        <th>{{ $t('admin.img') }}</th>
        <th>{{ $t('admin.img') }}</th>
        <th>{{ $t('category.status') }}</th>
        <th/>
      </tr>

      <tr v-for="(value, index) in list" :key="index">
        <td>
          <input type="checkbox" :value="value.id" v-model="cbList">
        </td>

        <td>

          <nuxt-link
            :to="`/site-features/${value.id}`"
          >
            <lazy-image
              class="mx-w-70x"
              :data-src="getThumbImageURL(value.image)"
              alt=""
            />
          </nuxt-link>
        </td>



        <td><div v-html="value.detail"></div></td>
        <td
          class="status"
          :class="{active: value.status == 1 }"
        >
          <span>{{ getStatus(value.status) }}</span>
        </td>
        <td>{{ value.created }}</td>
        <td>
          <button
            v-if="$can('home_slider', 'edit')"
            @click.prevent="$refs.listPage.editItem(value.id)" class="lite-btn"
          >
            {{ $t('category.edit') }}</button>
          <button
            v-if="$can('home_slider', 'delete')"
            @click.prevent="$refs.listPage.deleteItem(value.id)"
            class="delete-btn lite-btn"
          >
            {{ $t('category.delete') }}</button>
        </td>
      </tr>
    </template>
  </list-page>
  <!--main-slider-->
</template>

<script>
  import ListPage from "~/components/partials/ListPage"
  import util from '~/mixin/util'
  import bulkDelete from '~/mixin/bulkDelete'
  import outsideClick from "~/directives/outside-click"
  import LazyImage from "~/components/LazyImage";

  export default {
    name: "banners",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        orderOptions:{
          detail: { title: this.$t('title.det') },
          created_at: { title: this.$t('category.date') }
        },
        listApi: 'getBanners',
        deleteApi: 'deleteBanner',
        routeName: 'banners',
        loading: false,
        result:[]
      }
    },
    directives: {outsideClick},
    components: {
      LazyImage,
      ListPage
    },
    mixins: [util, bulkDelete],
    computed: {
    },
    methods: {
    },
    async mounted() {
    }
  }
</script>
<style lang="stylus">
  .banner-table
    img
      max-width 300px
      max-height 300px
</style>

