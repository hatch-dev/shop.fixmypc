<template>
  <list-page
    v-if="$can('subcategory', 'view')"
    ref="listPage"
    list-api="getSubCategories"
    delete-api="deleteSubCategory"
    route-name="sub-categories"
    empty-store-variable="allSubCategories"
    :name="$t('category.subCat')"
    gate="subcategory"
    :order-options="orderOptions"
  >
    <template v-slot:table="{list}">
      <tr class="lite-bold">
        <th>{{ $t('index.title') }}</th>
        <th>{{ $t('category.status') }}</th>
        <th>{{ $t('category.catUp') }}</th>
        <th>{{ $t('category.slug') }}</th>
        <th>{{ $t('category.featured') }}</th>
        <th>{{ $t('category.created') }}</th>
        <th>&nbsp;</th>
      </tr>

      <tr v-for="(value, index) in list" :key="index">
        <td class="">
          <nuxt-link
            :to="`/sub-categories/${value.id}`"
            class="dply-felx j-left link"
          >
            <lazy-image
              class="mr-20"
              :data-src="getThumbImageURL(value.image)"
              :alt="value.title"
            />
            <h5 class="mx-w-300x">{{ value.title }}</h5>
          </nuxt-link>
        </td>

        <td
          class="status"
          :class="{active: value.status == 1 }"
        >
          <span>{{ getStatus(value.status) }}</span>
        </td>

        <td>
          <nuxt-link
            :to="`/categories/${value.category.id}`"
            class="link"
          >
            {{ value.category.title }}
          </nuxt-link>
        </td>

        <td>{{ value.slug }}</td>

        <td
          class="status"
          :class="{active: value.featured == 1 }"
        >
          <span>{{ getFeatured(value.featured) }}</span>
        </td>

        <td>{{ value.created }}</td>
        <td>
          <button
            v-if="$can('subcategory', 'edit')"
            @click.prevent="$refs.listPage.editItem(value.id)" class="lite-btn">{{ $t('category.edit') }}</button>
          <button
            v-if="$can('subcategory', 'delete')"
            @click.prevent="$refs.listPage.deleteItem(value.id)" class="delete-btn lite-btn">{{ $t('category.delete') }}</button>
        </td>
      </tr>
    </template>
  </list-page>
</template>

<script>
  import ListPage from "~/components/partials/ListPage";
  import util from '~/mixin/util'
  import LazyImage from "../../components/LazyImage";

  export default {
    name: "categories",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        orderOptions: {
          title: { title: this.$t('index.title') },
          category_id: { title: this.$t('category.catUp') },
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
    mixins: [util],
    computed: {},
    methods: {},
    mounted() {
    }
  }
</script>

<style scoped>

</style>
