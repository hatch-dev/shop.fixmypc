<template>
  <list-page
    ref="listPage"
    list-api="getClearances"
    delete-api="deleteClearance"
    route-name="clearances"
    empty-store-variable="allClearances"
    :name="$t('brand.clearance')"
    :order-options="orderOptions"
    gate="clearance"
    @delete-bulk="deleteBulk"
    @list="itemList = $event"
  >
    <template v-slot:table="{list, from}">
      <tr class="lite-bold">
        <th class="w-50x mx-w-50x">
          <input type="checkbox" @change="checkAll">
        </th>
        <th class="w-50x mx-w-50x">#</th>
        <th>{{ $t('fSale.product') }}</th>
        <th>{{ $t('category.status') }}</th>
        <th>{{ $t('fSale.timeStatus') }}</th>
        <th>{{ $t('prod.eTime') }}</th>
        <th>{{ $t('category.created') }}</th>
        <th>&nbsp;</th>
      </tr>

      <tr v-for="(value, index) in list" :key="index">
        <td class="w-50x mx-w-50x">
          <input type="checkbox" :value="value.id" v-model="cbList">
        </td>
        <td class="w-50x mx-w-50x">{{ from + index }}</td>
        <td>
          <nuxt-link
            :to="`/clearances/${value.id}`"
            class="dply-felx j-left link"
          >
            <lazy-image
              v-if="value.product"
              class="mr-15 img-40x"
              :data-src="getThumbImageURL(value.product.image)"
              :alt="value.product.title"
            />
            <h5 class="mx-w-300x">{{ value.title || (value.product && value.product.title) || '-' }}</h5>
          </nuxt-link>
        </td>
        <td
          class="status"
          :class="{active: value.status == 1 }"
        >
          <span>{{ getStatus(value.status) }}</span>
        </td>
        <td
          class="time-status"
          :class="{active: isActive(value), expired: !isActive(value)}"
        >
          <span>{{ isActive(value) ? $t('fSale.active') : $t('fSale.expired') }}</span>
        </td>
        <td>{{ value.end_time ? value.end_time : '—' }}</td>
        <td>{{ value.created }}</td>
        <td>
          <button
            v-if="$can('clearance', 'edit')"
            @click.prevent="$refs.listPage.editItem(value.id)" class="lite-btn">{{ $t('category.edit') }}</button>
          <button
            v-if="$can('clearance', 'delete')"
            @click.prevent="$refs.listPage.deleteItem(value.id)" class="delete-btn lite-btn">{{ $t('category.delete') }}</button>
        </td>
      </tr>
    </template>
  </list-page>
</template>

<script>
  import bulkDelete from "~/mixin/bulkDelete";
  import util from "~/mixin/util";
  import ListPage from "~/components/partials/ListPage";
  import LazyImage from "~/components/LazyImage";

  export default {
    name: "clearances",
    middleware: ['common-middleware', 'auth'],
    data(){
      return {
        orderOptions:{
          created_at: { title: this.$t('category.date') },
          title: { title: this.$t('index.title') },
          status: { title: this.$t('category.status') }
        }
      }
    },
    mixins: [util, bulkDelete],
    components: {
      ListPage,
      LazyImage
    },
    methods: {
      // A clearance is "active" when published and not past its (optional) timer.
      isActive(value) {
        if (value.status != 1) return false
        if (!value.end_time) return true
        return new Date(value.end_time.replace(' ', 'T')) > new Date()
      }
    }
  }
</script>

<style scoped>
.time-status.active {
  color: #4CAF50;
  font-weight: bold;
}
.time-status.active span {
  padding: 5px 10px;
}
.time-status.expired span {
  background: #F44336;
  color: #fff;
  font-weight: bold;
  padding: 5px 10px;
}
</style>
