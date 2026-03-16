<template>
  <data-page
    class="detail-width"
    ref="dataPage"
    set-api="setLoyaltyGroup"
    get-api="getLoyaltyGroup"
    route-name="loyalty-groups"
    name="Loyalty Group"
    :validation-keys="['title','discount_value']"
    :result="result"
    @result="settingResult"
  >

    <template v-slot:form="{hasError}">

      <div class="card">

        <!-- TITLE -->
        <div class="input-wrapper">
          <label>Title</label>
          <input
            type="text"
            placeholder="Group title"
            v-model="result.title"
            :class="{invalid: !!!result.title && hasError}"
          >
          <span
            class="error"
            v-if="!!!result.title && hasError"
          >
            Title is required
          </span>
        </div>

        <!-- Discount -->
        <div class="row-fields">
          <div class="input-wrapper half">
            <label>Discount Value *</label>
            <input
              type="number"
              step="0.01"
              placeholder="Enter discount value"
              v-model="result.discount_value"
              :class="{invalid: !!!result.discount_value && hasError}"
            >
          </div>
          <div class="input-wrapper half">
            <label>Discount Type</label>
            <dropdown
              :selectedKey="result.discount_type"
              :options="discountTypes"
              @clicked="discountTypeSelected"
            />
          </div>
        </div>

        <!-- VALIDITY PERIOD -->
        <div class="input-wrapper">
          <label>Validity Period</label>

          <div class="validity-options">

            <!-- One Time -->
            <div
              class="validity-card"
              :class="{active: result.validity === 'one_time'}"
              @click="selectValidity('one_time')"
            >
              <input type="radio" value="one_time" v-model="result.validity">
              <div>
                <strong>One-time purchase</strong>
                <p>Valid for next order only</p>
              </div>
            </div>

            <!-- Set Period -->
            <div
              class="validity-card"
              :class="{active: result.validity === 'period'}"
              @click="selectValidity('period')"
            >
              <input type="radio" value="period" v-model="result.validity">
              <div>
                <strong>Set period</strong>
                <p>e.g. 30 days from joining</p>
              </div>

              <!-- Set Period Popup -->
                <div
                  v-if="showPeriodPopup"
                  class="mini-popup"
                  @click.stop
                >
                <div class="mini-popup-title">Set Validity Period</div>
                  <input
                    type="number"
                    v-model="periodDays"
                    placeholder="Enter days"
                  />
                  <div class="mini-popup-actions">
                    <button class="cancel-btn" @click.stop="closePeriodPopup">Cancel</button>
                    <button class="apply-btn" @click.stop="confirmPeriod">Apply</button>
                  </div>
                </div>
            </div>

            <!-- Date Range -->
            <div
              class="validity-card"
              :class="{active: result.validity === 'date_range'}"
              @click="selectValidity('date_range')"
            >
              <input type="radio" value="date_range" v-model="result.validity">
              <div>
                <strong>Date Range</strong>
                <p>Specific start/end dates</p>
              </div>

              <!-- Date Popup -->
              <div
                v-if="showDatePopup"
                class="mini-popup"
                @click.stop
              >
                <div class="mini-popup-title">Select Date Range</div>
                <input type="date" v-model="startDate">
                <input type="date" v-model="endDate">
                <div class="mini-popup-actions">
                  <button class="cancel-btn" @click.stop="closeDatePopup">Cancel</button>
                  <button class="apply-btn" @click.stop="confirmDateRange">Apply</button>
                </div>
              </div>
            </div>

          </div>
        </div>

        <!-- SELECT USERS -->
        <div class="input-wrapper">
          <div class="users-header">

            <div class="users-header-left">
              <div class="users-title">Select Users</div>
              <div class="users-subtitle">
                Filter and add users to this loyalty group.
              </div>
            </div>

            <div class="users-header-actions">
              <button type="button" @click="selectAllUsers">
                Select All
              </button>

              <button type="button" @click="clearUsers">
                Clear
              </button>
            </div>

          </div>
          <div class="filter-box">

            <div class="filter-header">
              <span class="arrow"><i class="fa fa-filter"></i></span>
              <span class="title">Advanced Filters</span>
            </div>

            <div class="filter-grid">

              <div class="filter-field">
                <label>Total Spend ($)</label>
                <div class="min-max">
                  <input type="number" placeholder="Min" v-model="filters.spend_min">
                  <span class="seperate">-</span>
                  <input type="number" placeholder="Max" v-model="filters.spend_max">
                </div>
              </div>

              <div class="filter-field">
                <label>Registration</label>
                <input type="date" placeholder="Any time" v-model="filters.registration">
              </div>

              <div class="filter-field">
                <label>Orders Last 30d</label>
                <input type="number" placeholder="Any amount" v-model="filters.orders">
              </div>

              <div class="filter-field">
                <label>Loyalty Points</label>
                <input type="number" placeholder="Min pts" v-model="filters.points">
              </div>

              <div class="filter-action">
                <button type="button" class="apply-filter btn" @click="applyFilters">Apply</button>
              </div>

            </div>

          </div>

          <!-- USER TABLE -->

          <div class="users-table">

            <!-- HEADER -->
            <div class="user-row header">
              <div class="checkbox-col">
                <input
                  type="checkbox"
                  :checked="allUsersSelected"
                  @change="toggleAllUsers"
                >
              </div>
              <div class="user-col">User</div>
              <div class="spend-col">Total Spend</div>
              <div class="points-col">Points</div>
            </div>

            <!-- USERS -->
            <div
              v-for="user in users"
              :key="user.id"
              class="user-row"
            >
              <div class="checkbox-col">
                <input
                  type="checkbox"
                  :value="user.id"
                  v-model="result.users"
                >
              </div>

              <div class="user-col user-info">
                <div class="avatar">
                  {{ user.name?.charAt(0) }}
                </div>

                <div class="user-name">
                  {{ user.name }}
                </div>
              </div>

              <div class="spend-col">
                ${{ user.total_spend || 0 }}
              </div>

              <div class="points-col">
                {{ user.total_points || 0 }}
              </div>

            </div>

          </div>
        </div>
      </div>

      <!-- Period Popup -->
      <div v-if="showPeriodPopup" class="mini-popup">
        <div class="mini-popup-title">
          Set Validity Period
        </div>
        <input
          type="number"
          v-model="periodDays"
          placeholder="Enter days"
        />
        <div class="mini-popup-actions">
          <button class="cancel-btn" @click="closePeriodPopup">
            Cancel
          </button>

          <button class="apply-btn" @click="confirmPeriod">
            Apply
          </button>
        </div>
      </div>

      <!-- Date Range Popup -->
      <div v-if="showDatePopup" class="mini-popup">
        <div class="mini-popup-title">
          Select Date Range
        </div>
        <input type="date" v-model="startDate">
        <input type="date" v-model="endDate">
        <div class="mini-popup-actions">
          <button class="cancel-btn" @click="closeDatePopup">
            Cancel
          </button>

          <button class="apply-btn" @click="confirmDateRange">
            Apply
          </button>
        </div>
      </div>
    </template>
  </data-page>
</template>
<script>

import DataPage from '~/components/partials/DataPage'
import Dropdown from '~/components/Dropdown'
import util from '~/mixin/util'
import { mapActions } from 'vuex'

export default {

  name: "loyalty-group",
  middleware: ['common-middleware','auth'],
  mixins: [util],
  components: {
    DataPage,
    Dropdown
  },
  data(){
    return {
      showPeriodPopup: false,
      showDatePopup: false,
      periodDays: '',
      startDate: '',
      endDate: '',
      users: [],
      allUsers: [],
      filters: {
        spend_min:'',
        spend_max:'',
        registration:'',
        orders:'',
        points:''
      },
      discountTypes:{
        fixed:{title:'Fixed Amount ($)'},
        percentage:{title:'Percentage (%)'}
      },
      result: {
        id: '',
        title: '',
        discount_type: 'fixed',
        discount_value: '',
        validity:'one_time',
        period_days: '',
        start_date: '',
        end_date: '',
        users: []
      }
    }
  },
  computed: {
    allUsersSelected(){
      return this.users.length && this.result.users.length === this.users.length
    }
  },
  methods: {
    selectValidity(type){
      // close both popups first
      this.showPeriodPopup = false
      this.showDatePopup = false

      // update validity
      this.result.validity = type

      // open correct popup
      if(type === 'period'){
        this.showPeriodPopup = true
      }

      if(type === 'date_range'){
        this.showDatePopup = true
      }
    },
    closePeriodPopup() {
      this.showPeriodPopup = false
    },
    confirmPeriod() {
      this.result.validity = "period"
      this.result.period_days = this.periodDays

      this.showPeriodPopup = false
      this.showDatePopup = false
    },
    closeDatePopup() {
      this.showDatePopup = false
    },
    confirmDateRange() {
      this.result.validity = "date_range"
      this.result.start_date = this.startDate
      this.result.end_date = this.endDate

      this.showPeriodPopup = false
      this.showDatePopup = false
    },
    toggleAllUsers(e){
      if(e.target.checked){
        this.result.users = this.users.map(user => user.id)
      }else{
        this.result.users = []
      }
    },
    async applyFilters(){
      const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/';
        const response = await this.$axios.get(`${baseUrl}api/admin/user/all`, {
          params: {
            limit: 'all',
            type: 'desc',
            orderby: 'created_at',
            spend_min: this.filters.spend_min,
            spend_max: this.filters.spend_max,
            registration: this.filters.registration,
            orders: this.filters.orders,
            points: this.filters.points,
            page: 1,
            time_zone: Intl.DateTimeFormat().resolvedOptions().timeZone,
          }
        });
      this.users = response?.data?.data?.data || [];

    },
    formatDiscount() {
        if (this.result.discount_value !== '' && this.result.discount_value !== null) {

            const value = Number(this.result.discount_value)

            this.result.discount_value = (Math.round(value * 100) / 100).toFixed(2)

        }
    },
    toggleUser(id) {
        const index = this.result.users.indexOf(id)
        if (index > -1) {
            this.result.users.splice(index, 1)
        } else {
            this.result.users.push(id)
        }
    },
    selectAllUsers() {
        this.result.users = this.users.map(u => u.id)
    },
    clearUsers() {
        this.result.users = []
    },
    discountTypeSelected(data){
      this.result.discount_type = data?.key;
    },
    settingResult(evt){
      this.result = {
        id: evt.id,
        title: evt.title,
        discount_type: evt.discount_type,
        discount_value: evt.discount_value,
        validity: evt.validity || 'one_time',
        period_days: evt.period_days || '',
        start_date: evt.start_date || '',
        end_date: evt.end_date || '',
        users: evt.users ? evt.users.map(u => u.id) : []
      }

      this.periodDays = this.result.period_days
      this.startDate = this.result.start_date
      this.endDate = this.result.end_date
    },
    async fetchUsers() {
        const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/';
        const response = await this.$axios.get(`${baseUrl}api/admin/user/all`, {
          params: {
            limit: 'all',
            type: 'desc',
            orderby: 'created_at',
            page: 1,
            time_zone: Intl.DateTimeFormat().resolvedOptions().timeZone,
          }
        });
        this.users = response?.data?.data?.data || [];
    },
    ...mapActions('common',['getById'])
  },
  mounted(){
    this.fetchUsers()
  }
}
</script>
<style scoped>
.row-fields{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:20px;
  position:relative;

}

.input-wrapper{
  margin-bottom:22px;
}

.input-wrapper label{
  display:block;
  margin-bottom:6px;
  font-weight:500;
  font-size:14px;
}

.card{
  border:1px solid #e5e7eb;
  border-radius:10px;
  padding:24px;
  background:#fff;
  margin-bottom: 10px;
}

/* VALIDITY CARDS */

.validity-options{
  display:flex;
  gap:16px;
  margin-top:10px;
}

.validity-card{
  flex:1;
  border:1px solid #d0d5dd;
  border-radius:8px;
  padding:14px;
  display:flex;
  gap:10px;
  cursor:pointer;
  transition:all .2s;
  position: relative;
}

.validity-card input{
  margin-top:3px;
}

.validity-card strong{
  font-size:14px;
}

.validity-card p{
  font-size:12px;
  color:#667085;
  margin:2px 0 0 0;
}

.validity-card.active{
  border-color:#4f6ef7;
  background:#f5f7ff;
}

/* USER ACTIONS */

.user-actions{
  display:flex;
  gap:10px;
  margin-bottom:12px;
}

.user-actions button{
  border:1px solid #d0d5dd;
  background:#fff;
  border-radius:6px;
  cursor:pointer;
  font-size:12px;
}

/* ADVANCED FILTER */
.filter-box{
  background:#f9fafb;
  border:1px solid #e5e7eb;
  border-radius:8px;
  padding:16px 18px;
  margin-top:12px;
}

/* header */

.filter-header{
  display:flex;
  align-items:center;
  gap:6px;
  margin-bottom:12px;
  font-weight:600;
  font-size:14px;
  color:#374151;
}

.arrow{
  font-size:12px;
}

/* grid layout */

.filter-grid{
  display:grid;
  grid-template-columns:220px 180px 180px 180px auto;
  gap:14px;
  align-items:end;
}

/* fields */

.filter-field{
  display:flex;
  flex-direction:column;
}

.filter-field label{
  font-size:12px;
  color:#6b7280;
  margin-bottom:4px;
}

/* inputs */

.filter-field input{
  height:34px;
  padding:0 10px;
  border:1px solid #d1d5db;
  border-radius:6px;
  font-size:13px;
  background:#fff;
}

/* min max */

.min-max{
  display:flex;
  gap:8px;
}

.min-max input{
  width:100%;
}

/* apply button */

.filter-action{
  display:flex;
  align-items:center;
}

.filter-action span{
  color:#4f46e5;
  font-size:13px;
  cursor:pointer;
  font-weight:500;
}
/* USERS TABLE */

.users-table{
  margin-top:16px;
  border:1px solid #e5e7eb;
  border-radius:8px;
  overflow:hidden;
}

.user-row{
  display:grid;
  grid-template-columns:50px 1fr 140px 100px;
  align-items:center;
  padding:12px 16px;
  border-bottom:1px solid #f1f1f1;
  font-size:13px;
}

.user-row:last-child{
  border-bottom:none;
}

.user-row.header{
  background:#f9fafb;
  font-weight:600;
  font-size:12px;
  color:#6b7280;
  text-transform:uppercase;
  text-align:center;
}

.user-row:hover{
  background:#fafafa;
}

/* columns */

.checkbox-col{
  display:flex;
  justify-content:center;
  align-items:center;
}

.user-col{
  display:flex;
  align-items:center;
  justify-content: center;
  gap:10px;
}

.spend-col{
  display:flex;
  justify-content:center;
  align-items:center;
}

.points-col{
  display:flex;
  justify-content:center;
  align-items:center;
}

/* avatar */

.avatar{
  width:28px;
  height:28px;
  border-radius:50%;
  background:#e5edff;
  display:flex;
  align-items:center;
  justify-content:center;
  font-size:12px;
  font-weight:600;
  color:#4f6ef7;
}

.user-name{
  font-weight:500;
}

.custom-dropdown .dropdown-inner {
  width: 100% !important;
}

form .input-wrapper .custom-dropdown{
  min-width: 100% !important;
}

.users-header{
  display:flex;
  justify-content:space-between;
  align-items:flex-start;
  margin-bottom:14px;
}

.users-title{
  font-size:16px;
  font-weight:600;
  color:#111827;
}

.users-subtitle{
  font-size:13px;
  color:#6b7280;
  margin-top:2px;
}

.users-header-actions{
  display:flex;
  gap:8px;
}

.users-header-actions button{
  border:1px solid #d1d5db;
  background:#fff;
  border-radius:6px;
  font-size:13px;
  cursor:pointer;
}

.users-header-actions button:hover{
  background:#f9fafb;
}

.apply-filter.btn{
  height: 35px;
  background: #486fef;
  color: #fff;
}

.mini-popup{
  position:absolute;
  top:100%;
  left:0;
  margin-top:10px;
  width:260px;
  background:#f5f6f7;
  border-radius:10px;
  padding:14px;
  border:1px solid #d0d5dd;
  box-shadow:0 6px 16px rgba(0,0,0,0.12);
  z-index:20;
}

.mini-popup-title{
  font-weight:600;
  font-size:14px;
  margin-bottom:10px;
}

.mini-popup input{
  width:100%;
  height:36px;
  border:1px solid #d1d5db;
  border-radius:6px;
  padding:0 10px;
  margin-bottom:10px;
}

.mini-popup-actions{
  display:flex;
  justify-content:space-between;
  margin-top:10px;
}

.cancel-btn{
  border:1px solid #d1d5db;
  background:#fff;
  border-radius:6px;
  cursor:pointer;
}

.apply-btn{
  background:#4f6ef7;
  color:#fff;
  border:none;
  border-radius:6px;
  cursor:pointer;
}

.confirm{
  color:green;
  cursor:pointer;
}

.cancel{
  color:red;
  cursor:pointer;
}
</style>