<template>
  <div>
    <div class="banner-header">
      <button
        class="button primary-btn"
        @click="goToOnboarding"
      >
        {{ $t('pages.banners.onboarding') }}
      </button>
    </div>
    <list-page
      ref="listPage"
      list-api="getBanners"
      delete-api="deleteBanner"
      route-name="banners"
      :name="$t('admin.banner')"
      gate="banner"
      :add-button="false"
      class="banner-table"
    >
      <template v-slot:table="{list, from}">
        <tr class="lite-bold">
          <th class="w-50x mx-w-50x">#</th>
          <th>{{ $t('admin.img') }}</th>
          <th>{{ $t('admin.type') }}</th>
          <th>{{ $t('category.slug') }}</th>
          <th>{{ $t('admin.closable') }}</th>
          <th>{{ $t('category.status') }}</th>
          <th>{{ $t('admin.sType') }}</th>
          <th/>
        </tr>

        <tr v-for="(value, index) in list" :key="index">
          <td class="w-50x mx-w-50x">{{ from + index }}</td>
          <td>

            <nuxt-link
              :to="`/banners/${value.id}`"
            >
              <lazy-image
                :data-src="getThumbImageURL(value.image)"
                alt=""
              />
            </nuxt-link>


          </td>
          <td>{{ bannerUsed[value.type] }}</td>
          <td>{{ value.slug }}</td>
          <td>{{ featuredObj[value.closable].title }}</td>
          <td
            class="status"
            :class="{active: value.status == 1 }"
          >
            <span>{{ getStatus(value.status) }}</span>
          </td>
          <td>{{ productSourceObj[value.source_type].title }}</td>
          <td>
            <button
              v-if="$can('banner', 'edit')"
              @click.prevent="$refs.listPage.editItem(value.id)" class="lite-btn"> {{ $t('category.edit') }}</button>
          </td>
        </tr>
      </template>
    </list-page>
  </div>
</template>

<script>
  import ListPage from "~/components/partials/ListPage"
  import util from '~/mixin/util'
  import outsideClick from "~/directives/outside-click"
  import LazyImage from "../../components/LazyImage";

  export default {
    name: "banners",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        listApi: 'getBanners',
        deleteApi: 'deleteBanner',
        routeName: 'banners',
        loading: false,
        result:[],
      }
    },
    directives: {outsideClick},
    components: {
      LazyImage,
      ListPage
    },
    mixins: [util],
    computed: {
    },
    methods: {
      goToOnboarding() {
        this.$router.push('/onboarding')
      }
    },
    async mounted() {
    }
  }
</script>
<style lang="stylus">
.banner-table
  img
    width 100px
    height auto

.banner-header
  display flex
  justify-content end
  align-items center
  margin-bottom 20px
</style>

