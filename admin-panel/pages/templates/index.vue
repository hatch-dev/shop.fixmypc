<template>
  <list-page
    ref="listPage"
    list-api="getTemplates"
    delete-api="deleteTemplate"
    route-name="templates"
    :name="$t('index.templates')"
    :order-options="orderOptions"
    @delete-bulk="deleteBulk"
    @list="itemList = $event"
  >

  <template v-slot:table>
    <div>
      <!-- ================= SYSTEM TEMPLATES ================= -->
      <div class="section">
        <h3 class="section-title">System Templates</h3>
        <table class="custom-table">
            <tr class="lite-bold">
              <th><input type="checkbox" @change="checkAll(systemTemplates)"></th>
              <th>{{ $t('index.title') }}</th>
              <th></th>
            </tr>
            <tr v-for="item in systemTemplates" :key="item.id">
              <td>
                <input type="checkbox" :value="item.id" v-model="cbList">
              </td>

              <td>
                <nuxt-link class="link" :to="`/templates/${item.id}`">
                  <h5 class="mx-w-300x">{{ item.title }}</h5>
                </nuxt-link>
              </td>

              <td>
                <button
                  @click.prevent="$refs.listPage.editItem(item.id)"
                  class="edit-btn"
                >
                {{ $t('category.edit') }}
                </button>
              </td>
            </tr>
        </table>
      </div>
      <!-- ================= CUSTOM TEMPLATES ================= -->
      <div class="section">
        <h3 class="section-title">Custom Templates</h3>

        <table class="custom-table">
          <tr class="lite-bold">
            <th><input type="checkbox" @change="checkAll(customTemplates)"></th>
            <th>{{ $t('index.title') }}</th>
            <th></th>
          </tr>

          <tr v-for="item in customTemplates" :key="item.id">
            <td>
              <input type="checkbox" :value="item.id" v-model="cbList">
            </td>

            <td>
              <nuxt-link class="link" :to="`/templates/${item.id}`">
                <h5 class="mx-w-300x">{{ item.title }}</h5>
              </nuxt-link>
            </td>

            <td>
              <button
                @click.prevent="$refs.listPage.editItem(item.id)"
                class="edit-btn"
              >
                {{ $t('category.edit') }}
              </button>

              <button
                @click.prevent="$refs.listPage.deleteItem(item.id)"
                class="delete-btn"
              >
                {{ $t('category.delete') }}
              </button>
            </td>
          </tr>
        </table>
      </div>
    </div>
  </template>
  </list-page>
</template>
<script>
  import ListPage from "~/components/partials/ListPage";
  import util from '~/mixin/util'
  import {mapGetters} from 'vuex'
  import bulkDelete from "~/mixin/bulkDelete";

  export default {
    name: "templates",
    middleware: ['common-middleware', 'auth'],
    data(){
      return {
        itemList: [],
        orderOptions:{
          title: { title: this.$t('index.title') },
          created_at: { title: this.$t('category.created') }
        }
      }
    },
    mixins: [util, bulkDelete],
    components: {
      ListPage
    },
    computed: {
      systemTemplates() {
        return this.itemList?.filter(item => item.type === 'system') || []
      },
      customTemplates() {
        return this.itemList?.filter(item => item.type === 'custom') || []
      }
    },
    methods:{
      checkAll(list) {
        this.cbList = list.map(item => item.id)
      }
    },
    mounted() {
    }
  }
</script>
<style scoped>
.section {
  margin-bottom: 35px;
  background: #ffffff;
  border-radius: 12px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
  padding: 20px;
}

/* Section title */
.section-title {
  font-size: 20px;
  font-weight: 600;
  margin-bottom: 18px;
  color: #1f2937;
}

/* Table */
.custom-table {
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
}

/* Header row */
.custom-table th {
  background: #eef2f7;
  font-weight: 600;
  font-size: 14px;
  text-align: left;
  padding: 14px;
  color: #374151;
  border-bottom: 1px solid #e5e7eb;
}

/* Body cells */
.custom-table td {
  padding: 14px;
  border-bottom: 1px solid #f1f1f1;
  font-size: 14px;
  color: #374151;
}

/* Remove last row border */
.custom-table tr:last-child td {
  border-bottom: none;
}

/* Row hover effect */
.custom-table tbody tr:hover {
  background: #f9fafb;
  transition: 0.2s ease;
}

/* Checkbox alignment */
.custom-table th:first-child,
.custom-table td:first-child {
  width: 50px;
  text-align: center;
}


</style>