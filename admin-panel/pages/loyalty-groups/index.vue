<template>
  <list-page
    ref="listPage"
    list-api="getLoyaltyGroups"
    delete-api="deleteLoyaltyGroup"
    route-name="loyalty-groups"
    :name="'Loyalty Groups'"
    :order-options="orderOptions"
    @delete-bulk="deleteBulk"
    @list="itemList = $event"
  >
    <template v-slot:table="{list, from}">

      <tr class="lite-bold">
        <th>
          <input type="checkbox" @change="checkAll">
        </th>

        <th class="w-50x mx-w-50x">#</th>
        <th>Title</th>
        <th>Discount</th>
        <th>Validity</th>
        <th>Members</th>
        <th>Status</th>
        <th>&nbsp;</th>
      </tr>

      <tr v-for="(value, index) in list" :key="index">

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
            :to="`/loyalty-groups/${value.id}`"
          >
            <h5 class="mx-w-300x">
              {{ value.title }}
            </h5>
          </nuxt-link>
        </td>

        <!--
          The old list printed the raw type and value in separate columns, so
          "percentage" and "10" sat side by side and a reader had to assemble
          "10%" themselves. The cap and floor were not shown at all, which are
          the two things that decide what the discount is actually worth.
        -->
        <td>
          <span class="discount-value">{{ discountLabel(value) }}</span>
          <span v-if="limitLabel(value)" class="sub-note">
            {{ limitLabel(value) }}
          </span>
        </td>

        <td>
          <span>{{ validityLabel(value) }}</span>
        </td>

        <td>
          {{ value.members_count || 0 }}
          <span v-if="value.redeemed_count > 0" class="sub-note">
            {{ value.redeemed_count }} redeemed
          </span>
        </td>

        <!--
          Two different questions, and the old page asked neither: is the group
          switched on, and is it doing anything today? A date_range group that
          ended last week is switched on and dead.
        -->
        <td>
          <span
            class="pill"
            :class="statusClass(value)"
          >
            {{ statusLabel(value) }}
          </span>
        </td>

        <td>

          <button
            @click.prevent="$refs.listPage.editItem(value.id)"
            class="lite-btn"
          >
            Edit
          </button>

          <button
            @click.prevent="$refs.listPage.deleteItem(value.id)"
            class="delete-btn lite-btn"
          >
            Delete
          </button>

        </td>

      </tr>

    </template>
  </list-page>
</template>

<script>
import ListPage from "~/components/partials/ListPage"
import util from "~/mixin/util"
import bulkDelete from "~/mixin/bulkDelete"
import { mapGetters } from 'vuex'

export default {

  name: "loyalty-groups",

  middleware: ['common-middleware', 'auth'],

  components: {
    ListPage
  },

  mixins: [util, bulkDelete],

  data() {
    return {

      orderOptions: {

        created_at: {
          title: 'Date'
        },

        title: {
          title: 'Title'
        },

        discount_type: {
          title: 'Discount Type'
        },

        discount_value: {
          title: 'Discount Value'
        },

        status: {
          title: 'Status'
        },

        validity: {
          title: 'Validity'
        }

      }

    }
  },

  computed: {
    currencyIcon() {
      return this.setting?.currency_icon || '€'
    },
    ...mapGetters('setting', ['setting'])
  },

  methods: {

    discountLabel(group) {
      const value = Number(group.discount_value || 0)

      return group.discount_type === 'percentage'
        ? `${value}%`
        : `${this.currencyIcon}${value.toFixed(2)}`
    },

    limitLabel(group) {
      const parts = []

      if (Number(group.min_cart_value) > 0) {
        parts.push(`min ${this.currencyIcon}${Number(group.min_cart_value).toFixed(2)}`)
      }

      if (Number(group.max_discount) > 0) {
        parts.push(`cap ${this.currencyIcon}${Number(group.max_discount).toFixed(2)}`)
      }

      return parts.join(' · ')
    },

    validityLabel(group) {
      if (group.validity === 'period') {
        return group.period_days
          ? `${group.period_days} days from joining`
          : 'Set period'
      }

      if (group.validity === 'date_range') {
        const start = this.dateOnly(group.start_date)
        const end = this.dateOnly(group.end_date)

        return start && end ? `${start} → ${end}` : 'Date range'
      }

      return 'One-time purchase'
    },

    dateOnly(value) {
      return value ? String(value).substring(0, 10) : ''
    },

    statusLabel(group) {
      if (!group.status || Number(group.status) === 0) {
        return 'Inactive'
      }

      // Set by the API: active, but its date range has not started or has ended.
      return group.is_running === false ? 'Scheduled / Expired' : 'Running'
    },

    statusClass(group) {
      if (!group.status || Number(group.status) === 0) {
        return 'pill-off'
      }

      return group.is_running === false ? 'pill-wait' : 'pill-on'
    }

  }

}
</script>

<style scoped>
.discount-value{
  font-weight:600;
}

.sub-note{
  display:block;
  font-size:11px;
  color:#6b7280;
}

.pill{
  display:inline-block;
  padding:2px 10px;
  border-radius:999px;
  font-size:11px;
  font-weight:600;
}

.pill-on{
  background:#ecfdf3;
  color:#027a48;
}

.pill-wait{
  background:#fffaeb;
  color:#b54708;
}

.pill-off{
  background:#f2f4f7;
  color:#475467;
}
</style>
