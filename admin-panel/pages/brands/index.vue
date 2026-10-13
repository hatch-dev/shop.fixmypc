<template>
  <list-page
    v-if="$can('brand', 'view')"
    ref="listPage"
    list-api="getBrands"
    delete-api="deleteBrand"
    route-name="brands"
    empty-store-variable="allBrands"
    :name="$t('brand.brand')"
    gate="brand"
    :order-options="orderOptions"
    @delete-bulk="deleteBulk"
    @list="itemList = $event"
  >
    <template v-slot:table="{list, from}">
      <tr class="lite-bold">
        <th class="w-50x mx-w-50x">
          <input type="checkbox" @change="checkAll">
        </th>
        <th class="w-50x mx-w-50x">#</th>
        <th>{{ $t('index.title') }}</th>
        <th>{{ $t('category.slug') }}</th>
        <th>{{ $t('category.featured') }}</th>
        <th>{{ $t('category.status') }}</th>
        <th>{{ $t('category.created') }}</th>
        <th>&nbsp;</th>
      </tr>

      <tr v-for="(value, index) in list" :key="index">
        <td class="w-50x mx-w-50x">
          <input type="checkbox" :value="value.id" v-model="cbList">
        </td>
        <td class="w-50x mx-w-50x">{{ from + index }}</td>
        <td class="brand-cell">
          <nuxt-link
            :to="`/brands/${value.id}`"
            class="brand-link"
          >
            <span class="brand-thumb">
              <lazy-image
                :data-src="getThumbImageURL(value.image)"
                :alt="value.title"
              />
            </span>
            <span class="brand-title">{{ value.title }}</span>
          </nuxt-link>
        </td>

        <td>
          {{ value.slug }}
        </td>

        <td
          class="status"
          :class="{active: value.featured == 1 }"
        >
          <span>{{ getFeatured(value.featured) }}</span>
        </td>
        <td
          class="status"
          :class="{active: value.status == 1 }"
        >
          <span>{{ getStatus(value.status) }}</span>
        </td>
        <td>{{ value.created }}</td>
        <td>
          <button
            v-if="$can('brand', 'edit')"
            @click.prevent="$refs.listPage.editItem(value.id)" class="lite-btn">{{ $t('category.edit') }}</button>
          <button
            v-if="$can('brand', 'delete')"
            @click.prevent="$refs.listPage.deleteItem(value.id)" class="delete-btn lite-btn">{{ $t('category.delete') }}</button>
        </td>
      </tr>
    </template>
  </list-page>
</template>

<script>
  import ListPage from "~/components/partials/ListPage";
  import util from '~/mixin/util'
  import LazyImage from "~/components/LazyImage";
  import bulkDelete from "~/mixin/bulkDelete";

  export default {
    name: "brands",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        orderOptions: {
          title: { title: this.$t('index.title') },
          featured: { title: this.$t('category.featured') },
          created_at: { title: this.$t('category.date') },
          status: { title: this.$t('category.status') }
        }
      }
    },
    components: {
      LazyImage,
      ListPage
    },
    mixins: [util, bulkDelete],
    computed: {},
    methods: {},
    mounted() {
    }
  }
</script>

<style scoped>
.brand-cell {
  min-width: 220px;
}

.brand-link {
  display: grid;
  grid-template-columns: 46px minmax(0, 1fr);
  align-items: center;
  gap: 12px;
  color: var(--text-strong);
  text-decoration: none;
  text-align: left;
}

.brand-thumb {
  width: 46px;
  height: 46px;
  min-width: 46px;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 1px solid var(--border-color);
  border-radius: 8px;
  background: #fff;
  overflow: hidden;
}

.brand-thumb img {
  width: 100%;
  height: 100%;
  max-width: 42px;
  max-height: 42px;
  object-fit: contain;
}

.brand-title {
  font-weight: 650;
  line-height: 18px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.brand-link:hover .brand-title {
  color: var(--primary-color);
  text-decoration: underline;
}
</style>
