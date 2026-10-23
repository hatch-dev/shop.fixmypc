<template>
  <div>
  <list-page
    ref="listPage"
    list-api="getMembershipPlans"
    delete-api="deleteMembershipPlan"
    route-name="membership-plans"
    :name="$t('pages.membershipPlans.title')"
    gate="membership"
    :add-button="canAdd"
    :order-options="orderOptions"
    @delete-bulk="deleteBulk"
    @list="onList"
  >
    <template v-slot:table="{list, from}">

      <tr class="lite-bold">
        <th>
          <input type="checkbox" @change="checkAll">
        </th>
        <th class="w-50x mx-w-50x">#</th>
        <th>{{ $t('pages.membershipPlans.plan') }}</th>
        <th>{{ $t('pages.membershipPlans.price') }}</th>
        <th>{{ $t('pages.membershipPlans.benefits') }}</th>
        <th>{{ $t('pages.membershipPlans.members') }}</th>
        <th>{{ $t('category.status') }}</th>
        <th>&nbsp;</th>
      </tr>

      <tr v-for="(value, index) in list" :key="value.id">
        <td>
          <input type="checkbox" :value="value.id" v-model="cbList">
        </td>

        <td class="w-50x mx-w-50x">{{ from + index }}</td>

        <td>
          <nuxt-link class="link" :to="`/membership-plans/${value.id}`">
            <h5 class="mx-w-300x">{{ value.name }}</h5>
          </nuxt-link>
          <span v-if="value.tagline" class="sub-note">{{ value.tagline }}</span>
        </td>

        <td>
          <span v-if="Number(value.price) > 0" class="plan-price">{{ currencyIcon }}{{ money(value.price) }} <small>{{ $t('pages.membershipPlans.perMonth') }}</small></span>
          <span v-if="Number(value.yearly_price) > 0" class="plan-price">{{ currencyIcon }}{{ money(value.yearly_price) }} <small>{{ $t('pages.membershipPlans.perYear') }}</small></span>
          <span v-if="value.trial_days > 0" class="sub-note">{{ $t('pages.membershipPlans.trialDays', { days: value.trial_days }) }}</span>
        </td>

        <td>{{ value.benefits_count || 0 }}</td>

        <td>
          <nuxt-link class="link" :to="`/memberships?plan_id=${value.id}`">
            {{ value.members_count || 0 }}
          </nuxt-link>
        </td>

        <!--
          On sale means two things: switched on here, and known to Stripe.
          A plan Stripe has not accepted cannot be bought, so it says so.
        -->
        <td>
          <span class="pill" :class="statusClass(value)">{{ statusLabel(value) }}</span>
        </td>

        <td>
          <button @click.prevent="$refs.listPage.editItem(value.id)" class="lite-btn">{{ $t('category.edit') }}</button>
          <button @click.prevent="$refs.listPage.deleteItem(value.id)" class="delete-btn lite-btn">{{ $t('category.delete') }}</button>
        </td>
      </tr>

    </template>
  </list-page>

  <!-- one plan for now: MembershipPlanController::MAX_PLANS refuses a second -->
  <div v-if="planCount !== null && !canAdd" class="mp-one-plan">
    <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
    <div>
      <strong>{{ $t('pages.membershipPlans.onePlanTitle') }}</strong>
      <span>{{ $t('pages.membershipPlans.onePlanBody') }}</span>
    </div>
  </div>
  </div>
</template>

<script>
import ListPage from "~/components/partials/ListPage"
import util from "~/mixin/util"
import bulkDelete from "~/mixin/bulkDelete"
import { mapGetters } from 'vuex'

export default {
  name: "membership-plans",
  middleware: ['common-middleware', 'auth'],
  components: { ListPage },
  mixins: [util, bulkDelete],

  data() {
    return {
      // from the list response: how many plans exist and how many may
      planCount: null,
      maxPlans: 1,
      orderOptions: {
        sort_order: { title: this.$t('pages.membershipPlans.displayOrder') },
        created_at: { title: this.$t('category.date') },
        name: { title: this.$t('pages.membershipPlans.name') },
        price: { title: this.$t('pages.membershipPlans.price') },
        status: { title: this.$t('category.status') }
      }
    }
  },

  computed: {
    // hidden until the list has said how many plans there are, so it never flashes
    canAdd() {
      return this.planCount !== null && this.planCount < this.maxPlans
    },
    currencyIcon() {
      return this.setting?.currency_icon || '€'
    },
    ...mapGetters('setting', ['setting'])
  },

  methods: {
    onList(list) {
      this.itemList = list
      const result = this.$refs.listPage?.result || {}
      this.planCount = Number(result.plan_count ?? list.length)
      this.maxPlans = Number(result.max_plans || 1)
    },
    money(v) {
      return Number(v || 0).toFixed(2)
    },
    // every period on offer has its Stripe price
    synced(plan) {
      return (!(Number(plan.price) > 0) || !!plan.stripe_price_id)
        && (!(Number(plan.yearly_price) > 0) || !!plan.stripe_yearly_price_id)
    },
    statusLabel(plan) {
      if (!plan.status) return this.$t('pages.membershipPlans.statusOff')
      return this.synced(plan) ? this.$t('pages.membershipPlans.statusOnSale') : this.$t('pages.membershipPlans.statusNotSynced')
    },
    statusClass(plan) {
      if (!plan.status) return 'pill-off'
      return this.synced(plan) ? 'pill-on' : 'pill-wait'
    }
  }
}
</script>

<style scoped>
.mp-one-plan {
  display: flex;
  gap: 12px;
  align-items: flex-start;
  margin: 0 20px 20px;
  padding: 14px 16px;
  border: 1px solid #D5D9F5;
  border-radius: 10px;
  background: #F5F6FF;
  color: #33319A;
  font-size: 13px;
}
.mp-one-plan i { margin-top: 2px; }
.mp-one-plan strong { display: block; color: #1F1D6B; font-size: 13.5px; margin-bottom: 2px; }
.mp-one-plan span { color: #475467; }
.plan-price { display: block; font-weight: 600; white-space: nowrap; }
.plan-price small { font-weight: 400; color: #6b7280; }
.sub-note { display: block; font-size: 11px; color: #6b7280; }
.pill { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; }
.pill-on { background: #ecfdf3; color: #027a48; }
.pill-wait { background: #fffaeb; color: #b54708; }
.pill-off { background: #f2f4f7; color: #475467; }
</style>
