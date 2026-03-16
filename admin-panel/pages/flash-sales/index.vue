<template>
  <list-page
    ref="listPage"
    list-api="getFlashSales"
    delete-api="deleteFlashSale"
    route-name="flash-sales"
    :name="$t('fSale.fSale')"
    :order-options="orderOptions"
    gate="flash_sale"
    @delete-bulk="deleteBulk"
    @list="itemList = $event"
  >
    <template v-slot:table="{list}">
        <tr class="lite-bold">
          <th>
            <input type="checkbox" @change="checkAll">
          </th>
          <th>{{ $t('index.title') }}</th>
          <th>{{ $t('category.status') }}</th>
          <!-- New column header for expired/active status -->
          <th>{{ $t('fSale.timeStatus') }}</th>
          <th>{{ $t('prod.sTime') }}</th>
          <th>{{ $t('prod.eTime') }}</th>
          <th>{{ $t('category.created') }}</th>
          <th>&nbsp;</th>
        </tr>

        <tr v-for="(value, index) in list" :key="index">
          <td>
            <input type="checkbox" :value="value.id" v-model="cbList">
          </td>
          <td>
            <nuxt-link
              class="link"
              :to="`/flash-sales/${value.id}`"
            >
              <h5 class="mx-w-300x">{{ value.title }}</h5>
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
            :class="{active: isFlashSaleActive(value.end_time), expired: !isFlashSaleActive(value.end_time)}"
          >
            <span>{{ getTimeStatus(value.end_time) }}</span>
          </td>
          <td>{{ formatDate(value.start_time) }}</td>
          <td>{{ formatDate(value.end_time) }}</td>
          <td>{{ formatDate(value.created) }}</td>
          <td>
            <button
              v-if="$can('flash_sale', 'edit')"
              @click.prevent="$refs.listPage.editItem(value.id)" class="lite-btn">{{ $t('category.edit') }}</button>
            <button
              v-if="$can('flash_sale', 'delete')"
              @click.prevent="$refs.listPage.deleteItem(value.id)" class="delete-btn lite-btn">{{ $t('category.delete') }}</button>
          </td>
        </tr>
    </template>
  </list-page>
</template>

<script>
  import ListPage from "~/components/partials/ListPage";
  import util from '~/mixin/util'
  import {mapGetters} from 'vuex'
  import bulkDelete from "~/mixin/bulkDelete";

  export default {
    name: "flash-sale",
    middleware: ['common-middleware', 'auth'],
    data(){
      return {
        orderOptions:{
          created_at: { title: this.$t('category.date') },
          title: {title: this.$t('index.title') },
          status: { title: this.$t('category.status') }
        }
      }
    },
    mixins: [util, bulkDelete],
    components: {
      ListPage
    },
    methods: {
      // Check if flash sale is active based on end_time
      isFlashSaleActive(endTime) {
        if (!endTime) return false;
        
        const endDate = new Date(endTime);
        const now = new Date();
        return endDate > now;
      },
      
      // Get the status text based on end_time
      getTimeStatus(endTime) {
        return this.isFlashSaleActive(endTime) ? this.$t('fSale.active') : this.$t('fSale.expired');
      },
      
      // Format date from YYYY-MM-DD to DD-MM-YYYY
      formatDate(dateString) {
        if (!dateString) return '';
        
        // Check if the date string contains time component
        const hasTime = dateString.includes(':');
        
        if (hasTime) {
          // Handle datetime strings like "2023-12-31 23:59:59"
          const [datePart, timePart] = dateString.split(' ');
          const [year, month, day] = datePart.split('-');
          return `${day}-${month}-${year} ${timePart}`;
        } else {
          // Handle date-only strings like "2023-12-31"
          const [year, month, day] = dateString.split('-');
          return `${day}-${month}-${year}`;
        }
      }
    },
    mounted() {
    }
  }
</script>

<style scoped>
  /* Style for the new time status column */
.time-status.active {
    color: #4CAF50;
    font-weight: bold; 
}
.time-status.active  span{
  padding:5px 10px;
}
.time-status.expired span {
    background: #F44336;
    color:#fff;
    font-weight: bold;
    padding:5px 10px;
  }
</style>