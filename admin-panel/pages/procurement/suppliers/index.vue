<template>
  <list-page
    ref="listPage"
    list-api="getSuppliers"
    delete-api="deleteSupplier"
    route-name="procurement/suppliers"
    :name="$t('procurement.suppliers')"
    :order-options="orderOptions"
    @delete-bulk="deleteBulk"
    @list="itemList = $event"
  >
    <template v-slot:table="{ list, from }">

      <!-- Table Header -->
      <tr class="lite-bold">
        <th>
          <input type="checkbox" @change="checkAll">
        </th>
        <th class="w-50x mx-w-50x">#</th>
        <th>{{ $t('pages.procurementSuppliers.name') }}</th>
        <th>{{ $t('pages.procurementSuppliers.email') }}</th>
        <th>{{ $t('pages.procurementSuppliers.phone') }}</th>
        <th>{{ $t('pages.procurementSuppliers.address') }}</th>
        <th>{{ $t('pages.procurementSuppliers.category') }}</th>
        <th>&nbsp;</th>
      </tr>

      <!-- Table Body -->
      <tr v-for="(value, index) in list" :key="value.id">
        <td>
          <input
            type="checkbox"
            :value="value.id"
            v-model="cbList"
          >
        </td>

        <td class="w-50x mx-w-50x">{{ from + index }}</td>

        <td>
          <nuxt-link
            class="link"
            :to="`/procurement/suppliers/${value.id}`"
          >
            {{ value.name }}
          </nuxt-link>
        </td>

        <td>{{ value.email }}</td>

        <td>
          {{ value.country_code }} {{ value.phone }}
        </td>

        <td>{{ value.address }}</td>

        <td>
          <span v-if="value.categories && value.categories.length">
            {{ value.categories.map(c => c.title).join(', ') }}
          </span>
        </td>

        <td>
          <button
            @click.prevent="$refs.listPage.editItem(value.id)"
            class="lite-btn"
          >
            {{ $t('pages.procurementSuppliers.edit') }}
          </button>

          <button
            @click.prevent="$refs.listPage.deleteItem(value.id)"
            class="delete-btn lite-btn"
          >
            {{ $t('pages.procurementSuppliers.delete') }}
          </button>
        </td>
      </tr>

    </template>
  </list-page>
</template>

<script>
import ListPage from "~/components/partials/ListPage";
import util from '~/mixin/util'
import bulkDelete from "~/mixin/bulkDelete";

export default {
  name: "suppliers",
  middleware: ['common-middleware', 'auth'],
  components: {
    ListPage
  },
  mixins: [util, bulkDelete],

  data() {
    return {
      itemList: [],
      orderOptions: {
        name: { title: this.$t('pages.procurementSuppliers.name') },
        created_at: { title: this.$t('pages.procurementSuppliers.createdDate') }
      }
    }
  },

  methods: {
    checkAll(event) {
      this.cbList = event.target.checked
        ? this.itemList.map(p => p.id)
        : []
    }
  }
}
</script>

<style scoped>
.link {
  color: #2563eb;
  font-weight: 500;
}
</style>