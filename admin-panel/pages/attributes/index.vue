<template>
  <list-page
    ref="listPage"
    list-api="getAttributes"
    delete-api="deleteAttribute"
    route-name="attributes"
    empty-store-variable="allAttributes"
    :name="$t('brand.attr')"
    :order-options="orderOptions"
    gate="attribute"
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
        <th>{{ $t('brand.aVal') }}</th>
        <th>{{ $t('category.created') }}</th>
        <th>&nbsp;</th>
      </tr>

      <tr v-for="(value, index) in list" :key="index">
        <td class="w-50x mx-w-50x">
          <input type="checkbox" :value="value.id" v-model="cbList">
        </td>
        <td class="w-50x mx-w-50x">{{ from + index }}</td>
        <td class="">
          <nuxt-link
            :to="`/attributes/${value.id}`"
            class="link"
          >
            <h5 class="mx-w-300x">{{ value.title }}</h5>
          </nuxt-link>
        </td>
        <td>
          <div class="attr-values" v-if="value.values && value.values.length">
            <button
              v-for="(a, i) in value.values"
              :key="i"
              class="attr-value-chip"
              @click.prevent="$router.push(`/attributes/${value.id}`)"
            >
              <span
                v-if="a.color"
                class="attr-chip-dot"
                :style="{ background: a.color }"
              ></span>
              {{ a.title }}
            </button>
          </div>
          <span v-else class="attr-values-empty">—</span>
        </td>
        <td>{{ value.created }}</td>
        <td>
          <button
            v-if="$can('attribute', 'edit')"
            @click.prevent="$refs.listPage.editItem(value.id)" class="lite-btn">{{ $t('category.edit') }}</button>
          <button
            v-if="$can('attribute', 'delete')"
            @click.prevent="$refs.listPage.deleteItem(value.id)" class="delete-btn lite-btn">{{ $t('category.delete') }}</button>
        </td>
      </tr>
    </template>
  </list-page>
</template>

<script>
  import ListPage from "~/components/partials/ListPage";
  import bulkDelete from "~/mixin/bulkDelete";
  export default {
    name: "attributes",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        orderOptions: {
          title: { title: this.$t('index.title') },
          created_at: { title: this.$t('category.date') }
        }
      }
    },
    components: {
      ListPage
    },
    mixins: [bulkDelete],
    computed: {},
    methods: {},
    mounted() {
    }
  }
</script>

<style scoped>
.attr-values {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  align-items: center;
  justify-content: flex-start;
  max-width: 460px;
}

.attr-value-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  width: auto;
  height: auto;
  padding: 3px 12px;
  line-height: 20px;
  font-size: 12px;
  font-weight: 600;
  color: var(--primary-color);
  background: var(--primary-soft);
  border: 1px solid transparent;
  border-radius: 999px;
  cursor: pointer;
  transition: background 0.12s ease, color 0.12s ease, border-color 0.12s ease;
}

.attr-chip-dot {
  width: 12px;
  height: 12px;
  border-radius: 50%;
  border: 1px solid rgba(0, 0, 0, 0.15);
  flex-shrink: 0;
}

.attr-value-chip:hover {
  background: var(--primary-color) !important;
  color: var(--primary-foreground) !important;
  border-color: var(--primary-color) !important;
}

.attr-values-empty {
  color: var(--text-soft);
}
</style>
