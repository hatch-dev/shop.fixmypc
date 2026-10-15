<template>
  <client-only>
  <account-layout
    active-route="savers-club"
    class="mb-5"
  >
    <template v-slot:rightArea>

      <!--
        This page had no loading state at all: the wallet, points, transaction
        and qualifying-product panels all rendered at zero while four requests
        ran one after another behind them.
      -->
      <account-skeleton
        v-if="loading"
        :cards="3"
        :rows="4"
        heading
        label="Loading your Savers Club"
      />

      <template v-else>

      <div class="card-box-club p-4 mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h5 class="mb-1 order-hrading">Savers Club</h5>
            <small class="text-muted">
                Your wallet, rewards, and exclusive membership benefits
            </small>
        </div>
        <div class="d-flex gap-2">
            <!-- downloads the whole wallet history as a CSV (it used to only scroll to the table) -->
            <button type="button" class="btn-statement" :disabled="downloadingStatement" @click="downloadStatement">
                <i class="fa-solid fa-download me-1" style="color: rgb(82, 90, 107);"></i> {{ downloadingStatement ? 'Preparing…' : 'Statement' }}
            </button>
            <button type="button" @click="scrollToWalletTopup" class="btn-add-money">
                <i class="fa-solid fa-plus me-1" style="color: #fff;"></i> Add Money
            </button>
        </div>
      </div>
      <div class="wallet-card p-4 mb-4">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">

            <!-- Left -->
            <div>
                <p class="wallet-balance">Wallet Balance</p>
                <h2 class="my-2 wallet-amount">{{ currencyIcon }}{{ money(wallet?.balance) }}</h2>
                <p class="wallet-balance">Available to spend</p>
                <!-- the wallet API has always sent this; the page never showed it -->
                <p v-if="Number(wallet?.pending) > 0" class="wallet-pending">
                  {{ currencyIcon }}{{ money(wallet.pending) }} top-up pending
                </p>
            </div>

            <!-- Right -->
            <div class="text-end">
                <!--
                  Was the literal "Free Member" for everyone, naming a membership
                  plan that does not exist yet. It is the account the server
                  reports (profile.account), worded as the dashboard words it.
                  When paid membership arrives, its plan name belongs here.
                -->
                <div v-if="isMember" class="permium-member-btn mb-2">
                  <i class="fa-solid fa-crown me-1" aria-hidden="true"></i>
                  {{ membership.plan ? membership.plan.name : '' }} member
                </div>
                <div v-else-if="account" class="permium-member-btn mb-2">
                  <i class="fa-solid fa-user-check me-1" aria-hidden="true"></i>
                  {{ accountTypeLabel }} account
                </div>
                <p v-if="accountStateLabel" class="member-state">{{ accountStateLabel }}</p>
                <!-- was the literal "Joined since May 2026" for every customer -->
                <p v-if="memberSince" class="member-since">Member since {{ memberSince }}</p>
            </div>

        </div>
        <div class="wallet-divider"></div>
        <div class="wallet-stats mt-3">
          <div>
              <small>Cherry Points</small>
              <h6 class="mb-1 cherry-points-total">{{ points?.points || 0 }} pts</h6>
              <small class="opacity-75">≈ {{ currencyIcon }}{{ money(points?.value) }} value</small>
          </div>
          <div>
              <small>Total Saved</small>
              <!-- was transactions?.totalSaved: a field an array never has, so always 0 -->
              <h6 class="mb-1 cherry-points-total">{{ lifetimeSaved === null ? '—' : currencyIcon + lifetimeSaved }}</h6>
              <small class="opacity-75">Lifetime savings</small>
          </div>
          <div>
              <small>Free Shipping</small>
              <h6 class="mb-1 cherry-points-total">{{ hasFreeShipping ? 'Included' : '—' }}</h6>
              <small class="opacity-75">{{ freeShippingNote }}</small>
          </div>
        </div>
      </div>
      <div class="row g-4">
          <div class="col-md-6">
              <div ref="walletTopupSection" class="card-box-club p-4 h-100" :class="{ 'topup-highlight': topupHighlight }">
                  <div class="d-flex justify-content-between mb-3">
                      <div>
                          <h6 class="mb-1 wallet-money">Add Money to Wallet</h6>
                          <small class="wallet-topup">Top up your wallet balance instantly</small>
                      </div>
                      <span class="icon-box"><i class="fa-regular fa-credit-card"
                              style="color: rgb(82, 90, 107);"></i></span>
                  </div>
                  <div class="d-flex gap-2 mb-3">
                      <button
                        v-for="amt in presetAmounts"
                        :key="amt"
                        @click="selectAmount(amt)"
                        :class="['amount-btn', { active: selectedAmount === amt }]"
                      >
                        {{ currencyIcon }}{{ amt }}
                      </button>
                  </div>
                  <label class="wallet-topup mb-1">Custom Amount</label>
                  <input
                    type="number"
                    v-model="customAmount"
                    @focus="selectCustom"
                    :min="topupMin"
                    :max="topupMax"
                    step="0.01"
                    inputmode="decimal"
                    class="form-control custom-input-wallet"
                    :class="{ 'is-invalid': amountError }"
                    :placeholder="currencyIcon + ' 0.00'"
                  />
                  <!-- the same limits create-sumup-checkout enforces for a top-up -->
                  <small class="topup-hint" :class="{ 'text-danger': amountError }">
                    {{ amountError || topupRangeText }}
                  </small>
                  <button
                    class="btn-add-wallet w-100 mt-3"
                    :disabled="!finalAmount || !!amountError || processingPayment"
                    @click="submitTopup"
                  >
                    <span v-if="processingPayment">Processing...</span>
                    <span v-else>Add {{ currencyIcon }}{{ money(finalAmount) }}</span>
                  </button>
              </div>
          </div>
          <div class="col-md-6">
              <div class="card-box-club p-4 h-100">
                  <div class="d-flex justify-content-between mb-3">
                      <div>
                          <h6 class="mb-1 wallet-money">Cherry Points</h6>
                          <small class="wallet-topup">Earn & redeem reward points</small>
                      </div>
                      <span class="icon-box"><i class="fa-solid fa-coins"
                              style="color: rgb(82, 90, 107);"></i></span>
                  </div>
                  <div class="points-box mb-3">
                      <div class="d-flex justify-content-between mb-1">
                          <small>Current Points</small>
                          <strong>{{ pointsBalance.toLocaleString('en-IE') }}</strong>
                      </div>
                      <!--
                        Was "20000 points to next reward tier": there are no
                        tiers, and the figure never moved. Every 100 points is
                        1 off at checkout, so the next whole euro is the goal.
                      -->
                      <div class="progress-track">
                          <div class="progress-fill green" :style="{ width: nextEuroProgress + '%' }"></div>
                      </div>
                      <small class="text-muted">{{ nextEuroText }}</small>
                      <!-- orders placed but not delivered yet: why a new customer sees 0 -->
                      <div v-if="pendingPoints > 0" class="points-pending">
                          <i class="fa-regular fa-clock me-1" aria-hidden="true"></i>
                          +{{ pendingPoints.toLocaleString('en-IE') }} pts on the way from
                          {{ pendingOrders }} {{ pendingOrders === 1 ? 'order' : 'orders' }} awaiting delivery
                      </div>
                  </div>
                  <div class="d-flex justify-content-between small mb-1">
                      <span class="conversion-rate">Earn Rate</span>
                      <span class="pts">1 pt per {{ currencyIcon }}1 paid</span>
                  </div>
                  <div class="d-flex justify-content-between small mb-1">
                      <span class="conversion-rate">Conversion Rate</span>
                      <span class="pts">100 pts = {{ currencyIcon }}1 off</span>
                  </div>
                  <div class="d-flex justify-content-between small mb-3">
                      <span class="conversion-rate">Points Value</span>
                      <span class="pts">{{ currencyIcon }}{{ money(points?.value) }}</span>
                  </div>
                  <!-- points are spent as a discount at checkout; nothing converts them to wallet money -->
                  <button class="btn-redeem-point w-100" :disabled="!(points?.points > 0)" @click="goToCart">
                    {{ points?.points > 0 ? 'Use points at checkout' : 'No points to use yet' }}
                  </button>
                  <button type="button" class="points-activity-link" @click="scrollToPointsActivity">
                    View points activity <i class="fa-solid fa-arrow-down ms-1" aria-hidden="true"></i>
                  </button>
              </div>
          </div>
      </div>
      <!--
        Paid membership (2026-10-15). The plans, prices and benefits come from
        the admin panel (Membership Plans) through GET user/membership; joining
        and the card are on Stripe. Hidden until a plan is on sale, or for a
        customer who never had one.
      -->
      <div v-if="showMembershipBox" ref="membershipSection" class="card-box-club p-0 mb-4 mt-3">
        <div class="section-header p-4">
            <h6 class="mb-1 wallet-money">{{ shownPlan ? shownPlan.name + ' Membership' : 'Membership' }}</h6>
            <small class="wallet-topup">
                {{ shownPlan && shownPlan.tagline ? shownPlan.tagline : 'Unlock exclusive benefits and special discounts' }}
            </small>
        </div>
        <div class="p-4 premium-member-box">
            <div v-if="tiles.length" class="feature-grid text-center mb-4" :style="{gridTemplateColumns: `repeat(${Math.min(tiles.length, 3)}, minmax(0, 1fr))`}">
                <div v-for="(tile, i) in tiles" :key="i">
                    <div class="feature-icon">
                      <i v-if="isFaIcon(tile.icon)" :class="faClass(tile.icon)" style="color: rgb(19, 14, 43);" aria-hidden="true"></i>
                      <span v-else>{{ tile.icon || '★' }}</span>
                    </div>
                    <h6 class="mt-2">{{ tile.title }}</h6>
                    <small class="text-muted">{{ tile.subtitle }}</small>
                </div>
            </div>

            <!-- a member -->
            <div v-if="showMemberView" class="plan-box">
                <div class="d-flex justify-content-between align-items-center flex-wrap mb-2">
                    <div>
                        <strong>Current Plan: {{ membership.plan ? membership.plan.name : '' }}</strong>
                        <span class="badge-active ms-2" :class="badgeTone">{{ membership.status_label.toUpperCase() }}</span><br>
                        <small class="text-muted">{{ membershipDateLine }}</small>
                        <small v-if="['past_due', 'unpaid'].includes(membership.status)" class="plan-alert">
                          Your last payment did not go through. Update your card under Manage Subscription to keep your membership.
                        </small>
                    </div>
                    <div class="text-end">
                        <h5 class="mb-0">{{ membership.source === 'admin' ? 'Free' : currencyIcon + money(membership.price) }}</h5>
                        <small class="text-muted">{{ membership.source === 'admin' ? 'complimentary' : membership.interval_label }}</small>
                    </div>
                </div>

                <div v-if="confirmingCancel" class="plan-confirm mt-3">
                    <p class="mb-2">
                      Cancel your membership? Your benefits continue until
                      {{ formatDate(membership.current_period_end) }} and you will not be charged again.
                    </p>
                    <div class="plan-actions">
                        <button type="button" class="btn-plan-cancel" :disabled="membershipBusy" @click="confirmingCancel = false">Keep membership</button>
                        <button type="button" class="btn-plan-danger" :disabled="membershipBusy" @click="cancelMembership">
                          {{ membershipBusy ? 'Cancelling…' : 'Yes, cancel' }}
                        </button>
                    </div>
                </div>
                <div v-else-if="membership.can_manage_billing || membership.can_resume || membership.can_cancel" class="plan-actions mt-3">
                    <button v-if="membership.can_resume" type="button" class="btn-Manage-Subscription" :disabled="membershipBusy" @click="resumeMembership">
                        Keep my membership
                    </button>
                    <button v-else-if="membership.can_manage_billing" type="button" class="btn-Manage-Subscription" :disabled="membershipBusy" @click="toggleManage">
                        {{ managing ? 'Close' : 'Manage Subscription' }}
                    </button>
                    <button v-if="membership.can_cancel" type="button" class="btn-plan-cancel" :disabled="membershipBusy" @click="confirmingCancel = true; managing = false">
                        Cancel membership
                    </button>
                </div>

                <!-- Manage: the card on file and past payments, all on this page -->
                <div v-if="managing && !confirmingCancel" class="manage-panel mt-3">
                    <div class="manage-row">
                        <div>
                            <strong>Payment card</strong>
                            <small class="d-block text-muted">
                                <template v-if="billingLoading && !billingInfo">Loading…</template>
                                <template v-else-if="billingInfo && billingInfo.card">{{ cardLabel(billingInfo.card) }}</template>
                                <template v-else>No card on file</template>
                            </small>
                        </div>
                        <button v-if="!changingCard" type="button" class="btn-plan-cancel" @click="changingCard = true">Change card</button>
                    </div>
                    <div v-if="changingCard" class="manage-card-form mt-3">
                        <stripe-payment
                            ref="cardForm"
                            inline
                            elements-mode="setup"
                            :stripe-key="membershipData.stripe_key"
                            :amount="0"
                            :submit-payment-method="submitCard"
                            @success="onCardSaved"
                        />
                        <div class="plan-actions mt-3">
                            <button type="button" class="btn-Manage-Subscription" :disabled="cardSaving" @click="saveCard">
                                {{ cardSaving ? 'Saving…' : 'Save card' }}
                            </button>
                            <button type="button" class="btn-plan-cancel" :disabled="cardSaving" @click="changingCard = false">Cancel</button>
                        </div>
                    </div>
                    <div class="manage-history">
                        <div class="history-head-row">
                            <strong>Payment history</strong>
                            <small v-if="billingInfo && billingInfo.pagination && billingInfo.pagination.total" class="text-muted">
                                {{ billingInfo.pagination.from }}–{{ billingInfo.pagination.to }} of {{ billingInfo.pagination.total }}
                            </small>
                        </div>
                        <p v-if="billingInfo && !billingInfo.invoices.length" class="text-muted small mb-0 mt-1">No payments yet.</p>
                        <!-- scrolls inside the panel; the header row stays put -->
                        <div v-else-if="billingInfo" ref="historyScroll" class="history-scroll" :class="{ 'is-loading': historyLoading }">
                            <table class="history-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th class="col-period">Period</th>
                                        <th class="text-end">Amount</th>
                                        <th class="text-end">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(inv, i) in billingInfo.invoices" :key="i">
                                        <td>{{ formatDate(inv.paid_at || inv.period_start) }}</td>
                                        <td class="text-muted col-period">{{ formatDate(inv.period_start) }} – {{ formatDate(inv.period_end) }}</td>
                                        <td class="text-end">{{ currencyIcon }}{{ money(inv.amount) }}</td>
                                        <td class="text-end"><span class="inv-status" :class="inv.status">{{ invoiceLabel(inv.status) }}</span></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-if="billingInfo && billingInfo.pagination && billingInfo.pagination.last_page > 1" class="history-pager">
                            <button type="button" class="pager-btn" :disabled="historyLoading || billingInfo.pagination.current_page <= 1"
                                    @click="loadHistoryPage(billingInfo.pagination.current_page - 1)">
                                <i class="fa-solid fa-chevron-left" aria-hidden="true"></i> Previous
                            </button>
                            <span class="pager-text">Page {{ billingInfo.pagination.current_page }} of {{ billingInfo.pagination.last_page }}</span>
                            <button type="button" class="pager-btn" :disabled="historyLoading || billingInfo.pagination.current_page >= billingInfo.pagination.last_page"
                                    @click="loadHistoryPage(billingInfo.pagination.current_page + 1)">
                                Next <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- early access: the daily email of new products -->
                <div v-if="membershipData.digest && membershipData.digest.available && !confirmingCancel" class="digest-row mt-3">
                    <div>
                        <strong>New product emails</strong>
                        <small class="d-block text-muted">An email whenever new products arrive, so you hear first.</small>
                    </div>
                    <button type="button" class="digest-switch" role="switch"
                            :aria-checked="membershipData.digest.enabled ? 'true' : 'false'"
                            aria-label="New product emails" :disabled="membershipBusy" @click="toggleDigest">
                        <span></span>
                    </button>
                </div>

                <!-- the same plan's other billing period -->
                <div v-if="membership.switch_to && !confirmingCancel" class="plan-change mt-3">
                    <button type="button" class="plan-switch" :disabled="membershipBusy" @click="changeBilling">
                        Switch to {{ membership.switch_to.interval === 'year' ? 'yearly' : 'monthly' }}
                        · {{ currencyIcon }}{{ money(membership.switch_to.price) }} {{ membership.switch_to.interval_label }}
                        <span v-if="membership.switch_to.saving" class="billing-save">Save {{ membership.switch_to.saving }}%</span>
                    </button>
                </div>
                <div v-if="membership.can_change_plan && otherPlans.length && !confirmingCancel" class="plan-change mt-3">
                    <small class="text-muted">Switch plan:</small>
                    <button v-for="plan in otherPlans" :key="plan.id" type="button" class="plan-switch" :disabled="membershipBusy" @click="changePlan(plan)">
                        {{ plan.name }} · {{ currencyIcon }}{{ money(plan.price) }} {{ plan.interval_label }}
                    </button>
                </div>
            </div>

            <!-- not a member: the plans on sale -->
            <template v-else>
                <p v-if="membershipEndedLine" class="text-muted small mb-3">{{ membershipEndedLine }}</p>
                <div v-for="plan in plans" :key="plan.id" class="plan-box mb-3">
                    <!-- monthly or yearly, when the plan offers both -->
                    <div v-if="plan.prices && plan.prices.length > 1" class="billing-toggle mb-3" role="radiogroup" aria-label="Billing">
                        <button v-for="p in plan.prices" :key="p.interval" type="button" role="radio"
                                :aria-checked="priceOption(plan).interval === p.interval ? 'true' : 'false'"
                                @click="billing = p.interval">
                            {{ p.interval === 'year' ? 'Yearly' : 'Monthly' }}
                            <span v-if="p.interval === 'year' && plan.yearly_saving" class="billing-save">Save {{ plan.yearly_saving }}%</span>
                        </button>
                    </div>
                    <div class="d-flex justify-content-between align-items-center flex-wrap mb-2">
                        <div>
                            <strong>{{ plan.name }}</strong><br>
                            <small class="text-muted">{{ billedText(plan, priceOption(plan)) }}</small>
                        </div>
                        <div class="text-end">
                            <h5 class="mb-0">{{ currencyIcon }}{{ money(priceOption(plan).price) }}</h5>
                            <small class="text-muted">{{ priceOption(plan).interval_label }}</small>
                            <small v-if="priceOption(plan).interval === 'year'" class="d-block text-muted per-month">
                              {{ currencyIcon }}{{ money(priceOption(plan).per_month) }} a month
                            </small>
                        </div>
                    </div>
                    <div v-if="joiningPlanId !== plan.id" class="plan-actions mt-3">
                        <button type="button" class="btn-Manage-Subscription" :disabled="membershipBusy || !membershipData.available" @click="startJoin(plan)">
                            {{ plan.trial_days > 0 ? 'Start free trial' : 'Join ' + plan.name + (priceOption(plan).interval === 'year' ? ' yearly' : '') }}
                        </button>
                    </div>
                    <!-- joining here, on Stripe's card form: the page never changes -->
                    <div v-else class="join-form mt-3">
                        <p class="join-summary">
                            <strong>{{ currencyIcon }}{{ money(priceOption(plan).price) }} today</strong>,
                            then every {{ priceOption(plan).interval === 'year' ? 'year' : 'month' }} until you cancel.
                        </p>
                        <stripe-payment
                            ref="joinCard"
                            inline
                            elements-mode="subscription"
                            :stripe-key="membershipData.stripe_key"
                            :amount="Number(priceOption(plan).price)"
                            :submit-payment-method="submitJoin"
                            @success="onJoined"
                        />
                        <div class="plan-actions mt-3">
                            <button type="button" class="btn-Manage-Subscription" :disabled="joinBusy" @click="payJoin">
                                {{ joinBusy ? 'Processing…' : 'Pay ' + currencyIcon + money(priceOption(plan).price) + ' & join' }}
                            </button>
                            <button type="button" class="btn-plan-cancel" :disabled="joinBusy" @click="joiningPlanId = null">Cancel</button>
                        </div>
                    </div>
                </div>
                <p v-if="!membershipData.available" class="text-muted small mb-0">Membership sign-up is not available right now.</p>
            </template>
        </div>
      </div>
      <div ref="transactionsSection" class="card-box-club p-0  mt-4 ">
        <div class=" d-flex justify-content-between align-items-center gap-2 p-4 txn-head">
            <div>
                <h6 class="mb-1 wallet-money">Recent Transactions</h6>
                <!-- only wallet movements: a purchase paid by card never touches the wallet -->
                <small class="text-muted">Money added to and spent from your wallet</small>
            </div>
            <!-- was a "Last 30 days" button that filtered nothing, then a fixed "Newest first" label -->
            <div class="list-controls">
              <select v-model="txnType" class="list-select" aria-label="Show transactions" @change="reloadTransactions">
                <option value="">All</option>
                <option value="credit">Money in</option>
                <option value="debit">Money out</option>
              </select>
              <select v-model="txnOrder" class="list-select" aria-label="Sort transactions" @change="reloadTransactions">
                <option value="desc">Newest first</option>
                <option value="asc">Oldest first</option>
              </select>
            </div>
        </div>
        <div class="table-responsive">
          <table class="table custom-table mb-0">
            <thead>
                <tr class="recent-table">
                    <th>DATE</th>
                    <th>DESCRIPTION</th>
                    <th>TYPE</th>
                    <th>AMOUNT</th>
                    <th>BALANCE</th>
                </tr>
            </thead>
            <tbody :class="{ 'txn-loading': transactionsLoading }">
              <tr v-if="!transactions.length" class="txn-empty">
                <td colspan="5">
                  <template v-if="txnType === 'credit'">No money added to your wallet yet.</template>
                  <template v-else-if="txnType === 'debit'">Nothing paid from your wallet yet.</template>
                  <template v-else>No transactions yet. Top-ups, wallet payments and refunds will show here.</template>
                </td>
              </tr>
              <tr v-for="item in transactions" :key="item.id">
                <td data-label="Date">{{ formatDateTime(item.created_at) }}</td>
                <td data-label="Description" class="txn-desc">
                  <nuxt-link v-if="item.order_id" :to="`/user/order/${item.order_id}`" class="txn-order-link">
                    {{ item.description }}
                  </nuxt-link>
                  <template v-else>{{ item.description || item.source }}</template>
                </td>
                <td data-label="Type">
                  <span :class="item.type === 'credit' ? 'badge credit' : 'badge debit'">
                    {{ item.type.toUpperCase() }}
                  </span>
                </td>
                <td data-label="Amount" class="txn-amount" :class="item.type === 'credit' ? 'text-success' : 'text-danger'">
                  {{ item.type === 'credit' ? '+' : '-' }}{{ currencyIcon }}{{ money(item.amount) }}
                </td>
                <td data-label="Balance">{{ currencyIcon }}{{ money(item.balance_after) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="d-flex justify-content-between align-items-center p-3 flex-wrap gap-2">
          <small class="text-muted">
            <!-- was "Showing 10 of 23" on every page, page 3 included -->
            {{ transactionRangeText }}
          </small>
          <div class="d-flex align-items-center gap-2">
            <button
              class="page-btn"
              :disabled="!pagination.prev_page_url || transactionsLoading"
              @click="goToTransactionsPage(pagination.current_page - 1)"
            >
              Prev
            </button>
            <!-- first, last and the pages either side of this one, not every page -->
            <template v-for="(page, i) in pageWindow">
              <span v-if="page === '…'" :key="'gap' + i" class="page-gap">…</span>
              <button
                v-else
                :key="page"
                :disabled="transactionsLoading"
                @click="goToTransactionsPage(page)"
                :class="['page-number', { active: page === pagination.current_page }]"
              >
                {{ page }}
              </button>
            </template>
            <button
              class="page-btn"
              :disabled="!pagination.next_page_url || transactionsLoading"
              @click="goToTransactionsPage(pagination.current_page + 1)"
            >
              Next
            </button>
          </div>
        </div>
      </div>
      <!-- points earned and used; nothing on the page showed this before -->
      <div ref="pointsActivitySection" class="card-box-club p-0 mt-4">
        <div class="d-flex justify-content-between align-items-center gap-2 p-4 txn-head">
            <div>
                <h6 class="mb-1 wallet-money">Cherry Points Activity</h6>
                <small class="text-muted">Points earned on delivered orders and used at checkout</small>
            </div>
            <div class="list-controls">
              <select v-model="pointsType" class="list-select" aria-label="Show points activity" @change="reloadPointsHistory">
                <option value="">All</option>
                <option value="credit">Earned</option>
                <option value="debit">Used</option>
              </select>
              <select v-model="pointsOrder" class="list-select" aria-label="Sort points activity" @change="reloadPointsHistory">
                <option value="desc">Newest first</option>
                <option value="asc">Oldest first</option>
              </select>
            </div>
        </div>
        <ul class="points-list" :class="{ 'txn-loading': pointsLoading }">
          <li v-if="!pointsHistory.length" class="points-empty">
            <template v-if="pointsType === 'credit'">No points earned yet.</template>
            <template v-else-if="pointsType === 'debit'">No points used yet.</template>
            <template v-else>
              No points yet. You earn 1 point for every {{ currencyIcon }}1 you pay, once the order is delivered.
            </template>
          </li>
          <li v-for="row in pointsHistory" :key="row.id" class="points-row">
            <div class="points-row-main">
              <span class="points-row-desc">{{ row.description }}</span>
              <small class="text-muted">{{ formatDate(row.created_at) }}</small>
            </div>
            <span class="points-row-pts" :class="row.type === 'credit' ? 'text-success' : 'text-danger'">
              {{ row.type === 'credit' ? '+' : '-' }}{{ Number(row.points).toLocaleString('en-IE') }} pts
            </span>
          </li>
        </ul>
        <div v-if="pointsPagination.last_page > 1" class="d-flex justify-content-between align-items-center p-3 gap-2">
          <small class="text-muted">
            Page {{ pointsPagination.current_page }} of {{ pointsPagination.last_page }}
          </small>
          <div class="d-flex align-items-center gap-2">
            <button
              class="page-btn"
              :disabled="pointsPagination.current_page <= 1 || pointsLoading"
              @click="goToPointsPage(pointsPagination.current_page - 1)"
            >
              Prev
            </button>
            <button
              class="page-btn"
              :disabled="pointsPagination.current_page >= pointsPagination.last_page || pointsLoading"
              @click="goToPointsPage(pointsPagination.current_page + 1)"
            >
              Next
            </button>
          </div>
        </div>
      </div>

      <!--
        Was "Qualifying Products", where a product "unlocked" at 20 pts per 1 of
        its price - and unlocking did nothing. Points work on any order (100 pts
        = 1 off at checkout), so each card now says what that many points
        actually take off it: 20% of the price.
      -->
      <div class="card-box-club p-4 mt-4 mb-4">
        <div class="qp-head">
          <div>
            <h6 class="mb-1 wallet-money">Use Your Points</h6>
            <small class="text-muted">
              Cherry Points work on anything in the shop: every 100 pts take {{ currencyIcon }}1 off at checkout.
              Here is what yours can do, lowest price first.
            </small>
          </div>
          <span class="qp-balance">
            <i class="fa-solid fa-coins me-1" aria-hidden="true"></i>{{ Number(userPoints).toLocaleString('en-IE') }} pts
          </span>
        </div>

        <div v-if="!productLoading && !qualifyingProducts.length" class="qp-empty">
          Nothing to show right now.
        </div>

        <div class="row g-3">
          <div
            class="col-6 col-lg-4 col-xl-3 qp-col"
            v-for="product in qualifyingProducts"
            :key="product.id"
          >
            <div class="qp-card" :class="{ 'is-ready': product.is_unlocked }">
              <nuxt-link :to="productLink(product)" class="qp-media" :aria-label="product.title" tabindex="-1">
                <lazy-image
                  :data-src="getImageURL(product.image)"
                  :title="product.title"
                  :alt="product.title"
                />
                <span class="qp-badge">
                  {{ Number(product.required_points).toLocaleString('en-IE') }} pts = {{ currencyIcon }}{{ product.points_value }} off
                </span>
              </nuxt-link>

              <div class="qp-body">
                <nuxt-link :to="productLink(product)" class="qp-title">{{ product.title }}</nuxt-link>

                <!-- the shop's own price (sales included), labelled for VAT like every other tile -->
                <div class="qp-price">
                  <strong>{{ currencyIcon }}{{ product.price }}</strong>
                  <small v-if="product.vat_side === 'inc'">Inc-VAT</small>
                  <small v-else-if="product.vat_side === 'ex'">
                    Ex-VAT · {{ currencyIcon }}{{ product.price_inc_vat }} Inc-VAT
                  </small>
                </div>

                <div class="qp-progress" role="progressbar"
                     :aria-valuenow="Math.min(Number(userPoints), Number(product.required_points))"
                     aria-valuemin="0" :aria-valuemax="product.required_points">
                  <div class="qp-progress-fill" :style="{ width: qpProgress(product) + '%' }"></div>
                </div>
                <p class="qp-status">
                  <template v-if="product.is_unlocked">
                    <i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i>
                    Ready: {{ currencyIcon }}{{ product.points_value }} off with your points
                  </template>
                  <template v-else>
                    {{ Number(product.points_needed).toLocaleString('en-IE') }} more pts for {{ currencyIcon }}{{ product.points_value }} off
                  </template>
                </p>

                <nuxt-link :to="productLink(product)" class="qp-view">
                  View product <i class="fa-solid fa-arrow-right ms-1" aria-hidden="true"></i>
                </nuxt-link>
              </div>
            </div>
          </div>

          <!-- placeholders while the next page loads, so the grid does not jump -->
          <template v-if="productLoading">
            <div v-for="n in 4" :key="'qp-sk' + n" class="col-6 col-lg-4 col-xl-3 qp-col">
              <div class="qp-card qp-skeleton" aria-hidden="true">
                <div class="qp-media"></div>
                <div class="qp-body">
                  <span class="sk-line"></span><span class="sk-line short"></span><span class="sk-line"></span>
                </div>
              </div>
            </div>
          </template>
        </div>

        <div class="text-center mt-4" v-if="hasMoreProducts && qualifyingProducts.length">
          <button type="button" class="qp-more" @click="loadMoreProducts" :disabled="productLoading">
            {{ productLoading ? 'Loading…' : 'Show more products' }}
          </button>
        </div>
      </div>
      <div class="row g-4">
        <div class="col-md-6">
          <div class="card-box-club p-4 h-100">
            <h6 class="mb-4 wallet-money">How Cherry Points Work</h6>
            <div class="step-item d-flex mb-3">
                <div class="step-circle">1</div>
                <div>
                    <strong>Earn Points</strong><br>
                    <small class="text-muted">Get 1 point for every {{ currencyIcon }}1 you pay, added once your order is delivered</small>
                </div>
            </div>
            <div class="step-item d-flex mb-3">
                <div class="step-circle">2</div>
                <div>
                    <strong>Accumulate</strong><br>
                    <small class="text-muted">Points never expire. A cancelled or refunded order takes its points back</small>
                </div>
            </div>
            <div class="step-item d-flex mb-3">
                <div class="step-circle">3</div>
                <div>
                    <strong>Redeem</strong><br>
                    <small class="text-muted">Every 100 points take {{ currencyIcon }}1 off an order at checkout</small>
                </div>
            </div>
            <div class="step-item d-flex">
                <div class="step-circle">4</div>
                <div>
                    <strong>Shop</strong><br>
                    <small class="text-muted">Tick "Use Cherry Points" on the payment step, and pay the rest by card or wallet</small>
                </div>
            </div>
          </div>
        </div>
        <div class="col-md-6">
          <div class="card-box-club p-4 h-100">
              <!-- the plan's own benefits (admin: Membership Plans); this list used to be fixed copy -->
              <h6 class="mb-4 wallet-money">{{ shownPlan ? shownPlan.name + ' Benefits' : 'Membership Benefits' }}</h6>
              <div v-for="(b, i) in benefitList" :key="i" class="benefit-item">
                  <span class="check-icon"><i class="fa-solid fa-check" style="color: #Fff;"></i></span>
                  <span>{{ b.title }}<small v-if="b.subtitle" class="d-block text-muted">{{ b.subtitle }}</small></span>
              </div>
              <p v-if="!benefitList.length" class="text-muted small mb-0">Membership benefits will be listed here when a plan is available.</p>
          </div>
        </div>
      </div>
      <div class="card-box-club p-4 mb-4 mt-4">
        <h6 class="mb-4 wallet-money">Frequently Asked Questions</h6>
        <div class="faq-item">
            <strong>How do I add money to my wallet?</strong>
            <p>
                You can add money using the quick amount buttons or enter a custom amount. Payment is processed instantly through secure payment gateway.
            </p>
        </div>
        <div class="faq-item">
            <strong>Can I withdraw money from my wallet?</strong>
            <p>
                Wallet balance can only be used for purchases on our platform. However, you can request a refund to your original payment method for returned orders.
            </p>
        </div>
        <div class="faq-item">
            <strong>Do Cherry Points expire?</strong>
            <p>
                No. Cherry Points do not expire. Points earned on an order that is later cancelled or refunded are taken back.
            </p>
        </div>
        <div v-if="showMembershipBox" class="faq-item border-0">
            <strong>Can I cancel my membership anytime?</strong>
            <p>
                Yes, you can cancel your membership at any time. You’ll continue to have access until the end of your current billing period.
            </p>
        </div>
      </div>

      </template>

    </template>
  </account-layout>
  </client-only>
</template>

<script>

  import {mapGetters, mapActions} from 'vuex'
  import util from '~/mixin/util'
  import LazyImage from '~/components/LazyImage'
  import RatePopup from '~/components/RatePopup'
  import AccountLayout from '~/components/AccountLayout'
  import Pagination from '~/components/Pagination'
  import AccountSkeleton from '~/components/AccountSkeleton'
  import StripePayment from '~/components/StripePayment'
  import global from '~/mixin/global'

  export default {
    middleware: ['common-middleware', 'auth'],
    head() {
      return {
        title: 'Savers Club',
        meta: []
      }
    },
    data() {
      return {
        productsPage: 1,
        hasMoreProducts: true,
        qualifyingProducts: [],
        userPoints: 0,
        productLoading: false,
        wallet: null,
        points: null,
        transactions: [],
        // Lifetime voucher savings, the same figure the Vouchers page shows
        totalSaved: null,
        // True until mounted() has settled every request.
        loading: true,
        pagination: {
          current_page: 1,
          last_page: 1,
          next_page_url: null,
          prev_page_url: null,
          total: 0
        },
        selectedAmount: null,
        customAmount: null,
        isCustom: false,
        processingPayment: false,
        presetAmounts: [25, 50, 100],
        // the limits create-sumup-checkout enforces for a top-up
        topupMin: 1,
        topupMax: 1000,
        transactionsLoading: false,
        pointsHistory: [],
        pointsPagination: { current_page: 1, last_page: 1 },
        pointsLoading: false,
        // list controls: '' = all, 'credit' / 'debit'; 'desc' = newest first
        txnType: '',
        txnOrder: 'desc',
        pointsType: '',
        pointsOrder: 'desc',
        downloadingStatement: false,
        // brief ring round the top-up card after Add Money
        topupHighlight: false,
        topupHighlightTimer: null,
        // GET user/membership: {available, plans, membership, saved}
        membershipData: null,
        membershipBusy: false,
        // the plan whose card form is open (joining on this page)
        joiningPlanId: null,
        joinBusy: false,
        // the Manage panel: card on file and payments
        managing: false,
        billingInfo: null,
        billingLoading: false,
        historyLoading: false,
        changingCard: false,
        cardSaving: false,
        confirmingCancel: false,
        // the billing period a non-member is looking at: 'month' or 'year'
        billing: 'month'
      }
    },
    watch: {
      '$route.query.checkoutId'(val) {
        if (val) {
          this.fetchWallet()
          this.fetchTransactions()
        }
      }
    },
    components: {
      LazyImage,
      RatePopup,
      AccountLayout,
      AccountSkeleton,
      Pagination,
      StripePayment
    },
    mixins: [util, global],
    computed: {
      finalAmount() {
        if (this.isCustom) {
          return parseFloat(this.customAmount || 0)
        }
        return this.selectedAmount
      },
      transactionRangeText() {
        const {from, to, total} = this.pagination
        if (!total) return 'No transactions'
        if (total === 1) return 'Showing 1 transaction'
        return from && to
          ? `Showing ${from}–${to} of ${total} transactions`
          : `Showing ${this.transactions.length} of ${total} transactions`
      },
      pointsBalance() {
        return Math.max(0, parseInt(this.points?.points, 10) || 0)
      },
      pendingPoints() {
        return Math.max(0, parseInt(this.points?.pending_points, 10) || 0)
      },
      pendingOrders() {
        return Math.max(0, parseInt(this.points?.pending_orders, 10) || 0)
      },
      // toward the next 100 points, i.e. the next 1 off at checkout
      nextEuroProgress() {
        return this.pointsBalance % 100
      },
      nextEuroText() {
        const need = 100 - (this.pointsBalance % 100)
        const first = this.pointsBalance < 100
        return `${need} pts until your ${first ? 'first' : 'next'} ${this.currencyIcon}1 off`
      },
      topupRangeText() {
        return `Between ${this.currencyIcon}${this.topupMin} and ${this.currencyIcon}${this.topupMax.toLocaleString('en-IE')}`
      },
      // Empty when the custom amount is fine (or not being used).
      amountError() {
        if (!this.isCustom || this.customAmount === null || this.customAmount === '') return ''
        const n = Number(this.customAmount)
        if (!isFinite(n) || n < this.topupMin || n > this.topupMax) {
          return `Enter an amount ${this.topupRangeText.toLowerCase()}`
        }
        if (Math.abs(n * 100 - Math.round(n * 100)) > 1e-6) return 'Use at most two decimal places'
        return ''
      },
      // Page buttons to show: 1, the last, and two either side of this one.
      pageWindow() {
        const last = this.pagination.last_page || 1
        const cur = this.pagination.current_page || 1
        if (last <= 7) return Array.from({length: last}, (_, i) => i + 1)
        const keep = [1, last, cur - 2, cur - 1, cur, cur + 1, cur + 2]
          .filter(p => p >= 1 && p <= last)
        const pages = [...new Set(keep)].sort((a, b) => a - b)
        const out = []
        pages.forEach((p, i) => {
          if (i && p - pages[i - 1] > 1) out.push('…')
          out.push(p)
        })
        return out
      },
      ...mapGetters('common', ['currencyIcon', 'setting']),
      membership() {
        return this.membershipData?.membership || null
      },
      plans() {
        return this.membershipData?.plans || []
      },
      // benefits apply now (a past_due card is still being retried by Stripe)
      isMember() {
        return !!(this.membership && this.membership.entitled)
      },
      // a membership to manage, including one stopped by a failed payment
      showMemberView() {
        return !!this.membership && (this.membership.entitled || ['unpaid', 'paused'].includes(this.membership.status))
      },
      showMembershipBox() {
        return !!this.membershipData && (this.plans.length > 0 || this.showMemberView)
      },
      shownPlan() {
        return this.showMemberView ? this.membership.plan : (this.plans[0] || this.membership?.plan || null)
      },
      tiles() {
        return (this.shownPlan?.benefits || []).filter(b => b.highlight).slice(0, 6)
      },
      benefitList() {
        return this.shownPlan?.benefits || []
      },
      otherPlans() {
        return this.plans.filter(p => p.id !== this.membership?.plan?.id)
      },
      hasFreeShipping() {
        return this.isMember && (this.membership.plan?.benefits || []).some(b => b.type === 'free_shipping')
      },
      freeShippingNote() {
        if (this.hasFreeShipping) {
          const rule = this.membership.plan.benefits.find(b => b.type === 'free_shipping')
          return Number(rule?.min_order) > 0
            ? `On deliveries over ${this.currencyIcon}${this.money(rule.min_order)}`
            : 'On every delivery'
        }
        return this.plans.some(p => (p.benefits || []).some(b => b.type === 'free_shipping'))
          ? 'With membership'
          : 'Not included'
      },
      badgeTone() {
        const m = this.membership
        if (!m) return ''
        if (['past_due', 'unpaid'].includes(m.status)) return 'badge-alert'
        if (m.cancel_at_period_end) return 'badge-ending'
        return ''
      },
      membershipDateLine() {
        const m = this.membership
        if (!m) return ''
        const end = this.formatDate(m.current_period_end)
        if (m.source === 'admin') {
          return m.current_period_end ? `Your membership runs until ${end}` : 'Your membership has no end date'
        }
        if (m.status === 'trialing' && !m.cancel_at_period_end) {
          return `Free trial until ${this.formatDate(m.trial_ends_at || m.current_period_end)}, then ${this.currencyIcon}${this.money(m.price)} ${m.interval_label}`
        }
        if (m.cancel_at_period_end) return `Your membership ends on ${end}`
        if (m.status === 'unpaid') return 'Your membership is on hold until the payment is made'
        return `Your membership renews on ${end}`
      },
      membershipEndedLine() {
        const m = this.membership
        if (!m || this.showMemberView || !m.plan) return ''
        return m.ended_at ? `Your ${m.plan.name} membership ended on ${this.formatDate(m.ended_at)}.` : ''
      },
      // voucher savings (user/savings) plus member discounts on orders that stand
      lifetimeSaved() {
        const voucher = this.totalSaved === null || this.totalSaved === undefined ? null : Number(this.totalSaved)
        const member = Number(this.membershipData?.saved || 0)
        if (voucher === null && !member) return null
        return ((voucher || 0) + member).toFixed(2)
      },
      ...mapGetters('user', ['profile']),
      account() {
        return this.profile?.account || null
      },
      accountTypeLabel() {
        const labels = {personal: 'Personal', business: 'Business', trade: 'Trade'}
        return labels[this.account?.account_type] || 'Personal'
      },
      /*
       * A second line only when there is something to say: a personal account
       * in good standing has none. Account status outranks trade status, as on
       * the dashboard.
       */
      accountStateLabel() {
        const a = this.account
        if (!a) return ''
        if (a.status === 'suspended') return 'Account suspended'
        if (a.status === 'rejected') return 'Account not accepted'
        if (a.status === 'pending') return 'Awaiting review'
        if (a.account_type === 'trade') {
          return {
            approved: 'Trade pricing active',
            rejected: 'Trade application not approved',
            suspended: 'Trade pricing suspended',
            pending: 'Trade application under review'
          }[a.trade_status] || 'Trade application under review'
        }
        if (a.account_type === 'business' && a.has_business_pricing) return 'Business pricing active'
        return ''
      },
      // From the account itself, e.g. "Sept 2026". Hidden when unknown.
      memberSince() {
        // "2026-09-10 07:02:04" (no T) is an Invalid Date in Safari; ISO works everywhere
        const raw = String(this.profile?.created_at || '').replace(' ', 'T')
        const d = raw ? new Date(raw) : null
        return d && !isNaN(d.getTime())
          ? d.toLocaleDateString('en-IE', {month: 'short', year: 'numeric'})
          : null
      }
    },
    methods: {
      ...mapActions('common', ['setToastMessage', 'setToastError']),
      money(v) {
        const n = Number(v)
        return (isFinite(n) ? n : 0).toFixed(2)
      },
      authHeaders() {
        return { Authorization: `Bearer ${this.$auth?.strategy?.token?.get()}` }
      },
      async downloadStatement() {
        if (this.downloadingStatement) return
        this.downloadingStatement = true
        try {
          const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'
          const { data } = await this.$axios.get(`${baseUrl}api/v1/user/wallet/statement`, {
            headers: this.authHeaders(),
            responseType: 'blob'
          })
          const url = URL.createObjectURL(new Blob([data], { type: 'text/csv' }))
          const a = document.createElement('a')
          a.href = url
          a.download = `wallet-statement-${new Date().toISOString().slice(0, 10)}.csv`
          document.body.appendChild(a)
          a.click()
          a.remove()
          setTimeout(() => URL.revokeObjectURL(url), 1000)
        } catch (e) {
          console.error(e)
          this.setToastError('We could not prepare your statement. Please try again.')
        } finally {
          this.downloadingStatement = false
        }
      },
      // Later pages had no loading state and no error: a failure left the old rows up.
      async goToTransactionsPage(page) {
        const last = this.pagination.last_page || 1
        if (page < 1 || page > last || page === this.pagination.current_page || this.transactionsLoading) return
        this.transactionsLoading = true
        try {
          await this.fetchTransactions(page)
        } catch (e) {
          console.error(e)
          this.setToastError('We could not load those transactions. Please try again.')
        } finally {
          this.transactionsLoading = false
        }
      },
      // Scrolls el to just below the sticky site header (200px on desktop).
      scrollBelowHeader(el) {
        const header = document.querySelector('header')
        const pos = header && getComputedStyle(header).position
        const offset = pos === 'sticky' || pos === 'fixed' ? header.getBoundingClientRect().height : 0
        window.scrollTo({top: el.getBoundingClientRect().top + window.pageYOffset - offset - 16, behavior: 'smooth'})
      },
      /*
       * Add Money. It used to scrollIntoView({block: 'center'}) and focus the
       * input on the next tick: the focus jumped the page to the input and
       * cut the smooth scroll short, leaving the card half off the bottom of
       * the screen. Now the whole card is brought in under the header, the
       * input is focused once the scroll has settled (without scrolling
       * again), and the card is briefly highlighted.
       */
      scrollToPointsActivity() {
        const el = this.$refs.pointsActivitySection
        if (el) this.scrollBelowHeader(el)
      },
      scrollToWalletTopup() {
        const card = this.$refs.walletTopupSection
        if (!card) return
        this.scrollBelowHeader(card)

        this.topupHighlight = false
        clearTimeout(this.topupHighlightTimer)
        this.$nextTick(() => {
          this.topupHighlight = true
          this.topupHighlightTimer = setTimeout(() => { this.topupHighlight = false }, 1600)
        })

        // no keyboard popping up on phones; there the preset buttons are right in view
        if (window.matchMedia && !window.matchMedia('(pointer: fine)').matches) return
        const input = card.querySelector('.custom-input-wallet')
        let last = -1
        let waited = 0
        const settle = () => {
          const y = window.pageYOffset
          if ((y === last && waited >= 100) || waited > 1200) {
            input?.focus({preventScroll: true})
            return
          }
          last = y
          waited += 50
          setTimeout(settle, 50)
        }
        setTimeout(settle, 50)
      },
      goToCart() {
        this.$router.push('/cart')
      },
      // how far the customer's points go towards this product's 20%
      qpProgress(product) {
        const need = Number(product.required_points) || 0
        if (!need) return 0
        return Math.min(100, Math.round((Number(this.userPoints) || 0) / need * 100))
      },
      loadMoreProducts() {
        if (!this.hasMoreProducts) return

        this.fetchQualifyingProducts(this.productsPage + 1, true)
      },
      async fetchQualifyingProducts(page = 1, append = false) {
        try {
          this.productLoading = true
          const token = this.$auth?.strategy?.token?.get()
          const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'

          const { data } = await this.$axios.get(
            `${baseUrl}api/v1/user/product/qualifying?page=${page}`,
            {
              headers: { Authorization: `Bearer ${token}` }
            }
          )
          this.userPoints = data.points || 0
          if (append) {
            this.qualifyingProducts = [
              ...this.qualifyingProducts,
              ...(data.data || [])
            ]
          } else {
            this.qualifyingProducts = data.data || []
          }
          this.hasMoreProducts = data.current_page < data.last_page
          this.productsPage = data.current_page
        } catch (e) {
          console.error(e)
        } finally {
          this.productLoading = false
        }
      },
      selectAmount(amount) {
        this.selectedAmount = amount
        this.customAmount = null
        this.isCustom = false
      },

      selectCustom() {
        this.selectedAmount = null
        this.isCustom = true
      },

      async submitTopup() {
        try {
          if (!this.finalAmount || this.finalAmount <= 0 || this.amountError) {
            this.setToastError(this.amountError || 'Choose or enter an amount to add')
            return
          }

          this.processingPayment = true

          const token = this.$auth?.strategy?.token?.get()
          const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'

          const { data } = await this.$axios.post(
            `${baseUrl}api/create-sumup-checkout`,
            {
              amount: this.finalAmount,
              currency: 'EUR',
              is_topup: true
            },
            {
              headers: {
                Authorization: `Bearer ${token}`
              }
            }
          )

          // SumUp's refusal comes back as a 200 with no id; it used to do nothing.
          if (!data?.id) {
            this.setToastError('We could not start the payment. Please try again.')
            return
          }

          this.$router.push({
            path: '/user/topup-checkout',
            query: {
              checkoutId: data.id,
              checkoutReference: data.checkout_reference,
              amount: Number(this.finalAmount).toFixed(2),
              // brings the customer back here, not to the dashboard
              from: 'savers-club'
            }
          })

        } catch (e) {
          console.error(e)
          this.setToastError(e?.response?.data?.message || 'We could not start the payment. Please try again.')
        } finally {
          this.processingPayment = false
        }
      },

      // "24 Sept 2026", as the other account pages write it
      formatDate(date) {
        if (!date) return '-'

        return new Date(date).toLocaleDateString('en-IE', {
          day: 'numeric',
          month: 'short',
          year: 'numeric'
        })
      },
      // "24 Sept 2026, 11:14": several movements can share a day
      formatDateTime(date) {
        if (!date) return '-'
        const d = new Date(date)
        if (isNaN(d.getTime())) return '-'
        return `${this.formatDate(date)}, ${d.toLocaleTimeString('en-IE', {hour: '2-digit', minute: '2-digit'})}`
      },
      async fetchWallet() {
        const token = this.$auth?.strategy?.token?.get()
        const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'

        const { data } = await this.$axios.get(
          `${baseUrl}api/v1/user/wallet`,
          { headers: { Authorization: `Bearer ${token}` } }
        )

        this.wallet = data
      },

      async fetchPoints() {
        const token = this.$auth?.strategy?.token?.get()
        const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'

        const { data } = await this.$axios.get(
          `${baseUrl}api/v1/user/points`,
          { headers: { Authorization: `Bearer ${token}` } }
        )

        this.points = data
      },

      async fetchPointsHistory(page = 1) {
        const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'
        const { data } = await this.$axios.get(
          `${baseUrl}api/v1/user/points/history?page=${page}&order=${this.pointsOrder}&type=${this.pointsType}`,
          { headers: this.authHeaders() }
        )
        this.pointsHistory = data.data || []
        this.pointsPagination = { current_page: data.current_page || 1, last_page: data.last_page || 1 }
      },
      // a new sort or filter starts again from page 1
      async reloadTransactions() {
        this.transactionsLoading = true
        try {
          await this.fetchTransactions(1)
        } catch (e) {
          console.error(e)
          this.setToastError('We could not load your transactions. Please try again.')
        } finally {
          this.transactionsLoading = false
        }
      },
      async reloadPointsHistory() {
        this.pointsLoading = true
        try {
          await this.fetchPointsHistory(1)
        } catch (e) {
          console.error(e)
          this.setToastError('We could not load your points activity. Please try again.')
        } finally {
          this.pointsLoading = false
        }
      },
      async goToPointsPage(page) {
        if (page < 1 || page > this.pointsPagination.last_page || this.pointsLoading) return
        this.pointsLoading = true
        try {
          await this.fetchPointsHistory(page)
        } catch (e) {
          console.error(e)
          this.setToastError('We could not load your points activity. Please try again.')
        } finally {
          this.pointsLoading = false
        }
      },
      // ---- membership: GET/POST api/v1/user/membership (MembershipController)
      membershipUrl(path = '') {
        // as mixin/util.js resolves it: API_BASE when set, else this origin
        const configured = (process.env.apiBase || '').trim()
        const base = configured || (typeof window !== 'undefined' ? window.location.origin + '/' : '/')
        return `${base.replace(/\/?$/, '/')}api/v1/user/membership${path ? '/' + path : ''}`
      },
      async fetchMembership() {
        try {
          const { data } = await this.$axios.get(this.membershipUrl(), { headers: this.authHeaders() })
          this.membershipData = data?.status === 200 ? data.data : null
        } catch (e) {
          this.membershipData = null
        }
      },
      async membershipPost(path, body = {}) {
        const { data } = await this.$axios.post(this.membershipUrl(path), body, { headers: this.authHeaders() })
        if (data?.status !== 200) {
          throw new Error(data?.message || 'Something went wrong. Please try again.')
        }
        return data.data
      },
      async membershipGet(path) {
        const { data } = await this.$axios.get(this.membershipUrl(path), { headers: this.authHeaders() })
        if (data?.status !== 200) {
          throw new Error(data?.message || 'Something went wrong. Please try again.')
        }
        return data.data
      },
      // ---- joining on this page: Stripe's card form, no redirect
      startJoin(plan) {
        if (!this.membershipData?.stripe_key) {
          this.setToastError('Card payments are not available right now. Please try again later.')
          return
        }
        this.joiningPlanId = plan.id
      },
      joinForm() {
        const ref = this.$refs.joinCard
        return Array.isArray(ref) ? ref[0] : ref
      },
      async payJoin() {
        if (this.joinBusy || !this.joinForm()) return
        this.joinBusy = true
        try {
          await this.joinForm().pay()
        } finally {
          this.joinBusy = false
        }
      },
      // the card form hands over the card; the bank's challenge, if any, shows over the page
      async submitJoin(paymentMethod, runAction) {
        const plan = this.plans.find(p => p.id === this.joiningPlanId)
        if (!plan) return { ok: false, message: 'This plan is not available.' }
        try {
          let result = await this.membershipPost('subscribe', {
            plan_id: plan.id,
            interval: this.priceOption(plan).interval,
            payment_method: paymentMethod
          })
          if (result.requires_action) {
            await runAction(result.client_secret)
            result = await this.membershipPost('subscribe/finish', { subscription: result.subscription })
          }
          return { ok: true, payload: result }
        } catch (e) {
          return { ok: false, message: e.message }
        }
      },
      onJoined(result) {
        const payload = result?.payload
        if (payload) this.membershipData = payload
        this.joiningPlanId = null
        if (payload?.processing) {
          this.setToastMessage('Payment received. Your membership will show here in a moment.')
          setTimeout(() => this.fetchMembership(), 4000)
        } else {
          this.setToastMessage(`Welcome to ${this.membership?.plan?.name || 'your membership'}!`)
        }
      },
      // ---- managing on this page: the card and the payments
      toggleManage() {
        this.managing = !this.managing
        this.changingCard = false
        if (this.managing) this.loadBilling()
      },
      async loadBilling() {
        this.billingLoading = true
        try {
          this.billingInfo = await this.membershipGet('billing')
        } catch (e) {
          this.billingInfo = { card: null, invoices: [] }
          this.setToastError(e.message)
        } finally {
          this.billingLoading = false
        }
      },
      // another page of payments; the card above stays as it is
      async loadHistoryPage(page) {
        if (this.historyLoading) return
        this.historyLoading = true
        try {
          const data = await this.membershipGet(`billing?page=${page}`)
          this.billingInfo = { ...this.billingInfo, invoices: data.invoices, pagination: data.pagination }
          this.$nextTick(() => { if (this.$refs.historyScroll) this.$refs.historyScroll.scrollTop = 0 })
        } catch (e) {
          this.setToastError(e.message)
        } finally {
          this.historyLoading = false
        }
      },
      async saveCard() {
        const ref = this.$refs.cardForm
        if (this.cardSaving || !ref) return
        this.cardSaving = true
        try {
          await ref.pay()
        } finally {
          this.cardSaving = false
        }
      },
      async submitCard(paymentMethod, runAction) {
        try {
          let result = await this.membershipPost('card', { payment_method: paymentMethod })
          if (result.requires_action) {
            await runAction(result.client_secret)
            result = await this.membershipPost('card/finish', { setup_intent: result.setup_intent })
          }
          return { ok: true, payload: result }
        } catch (e) {
          return { ok: false, message: e.message }
        }
      },
      onCardSaved(result) {
        if (result?.payload) this.membershipData = result.payload
        this.changingCard = false
        this.setToastMessage('Your new card will be used from your next payment.')
        this.loadBilling()
      },
      cardLabel(card) {
        const brand = card.brand ? card.brand.charAt(0).toUpperCase() + card.brand.slice(1) : 'Card'
        return `${brand} •••• ${card.last4}, expires ${String(card.exp_month).padStart(2, '0')}/${String(card.exp_year).slice(-2)}`
      },
      invoiceLabel(status) {
        return { paid: 'Paid', open: 'Due', uncollectible: 'Failed' }[status] || status
      },
      async membershipAction(path, body, success) {
        if (this.membershipBusy) return
        this.membershipBusy = true
        try {
          this.membershipData = await this.membershipPost(path, body)
          this.confirmingCancel = false
          this.setToastMessage(typeof success === 'function' ? success() : success)
        } catch (e) {
          this.setToastError(e.message)
        } finally {
          this.membershipBusy = false
        }
      },
      cancelMembership() {
        return this.membershipAction('cancel', {}, () => `Cancelled. Your benefits continue until ${this.formatDate(this.membership?.current_period_end)}.`)
      },
      resumeMembership() {
        return this.membershipAction('resume', {}, 'Your membership will continue.')
      },
      changePlan(plan) {
        const ok = window.confirm(`Switch to ${plan.name} at ${this.currencyIcon}${this.money(plan.price)} ${plan.interval_label}? ` +
          'The change is immediate; unused time on your current plan is credited on your next bill.')
        if (!ok) return
        return this.membershipAction('change', { plan_id: plan.id }, `You are now on ${plan.name}.`)
      },
      // the price shown for a plan: the chosen period when offered, else its first
      priceOption(plan) {
        const prices = plan.prices || []
        return prices.find(p => p.interval === this.billing) || prices[0] ||
          { interval: plan.interval, price: plan.price, interval_label: plan.interval_label, per_month: plan.price }
      },
      toggleDigest() {
        const enabled = !this.membershipData?.digest?.enabled
        return this.membershipAction('digest', { enabled },
          enabled ? 'We will email you when new products arrive.' : 'New product emails turned off.')
      },
      changeBilling() {
        const to = this.membership?.switch_to
        if (!to) return
        const price = `${this.currencyIcon}${this.money(to.price)} ${to.interval_label}`
        const ok = window.confirm(to.interval === 'year'
          ? `Switch to yearly billing at ${price}? You are charged today, less the unused part of this month.`
          : `Switch to monthly billing at ${price}? The unused part of your year is credited against your bills.`)
        if (!ok) return
        return this.membershipAction('change', { interval: to.interval },
          `You are now billed ${to.interval === 'year' ? 'yearly' : 'monthly'}.`)
      },
      billedText(plan, option = null) {
        const every = (option ? option.interval_label : plan.interval_label).replace(/^per /, 'every ')
        const trial = Number(plan.trial_days) > 0 ? `${plan.trial_days}-day free trial, then billed ${every}` : `Billed ${every}`
        return `${trial}. Cancel anytime.`
      },
      isFaIcon(icon) {
        return /^fa[-\s]/.test(String(icon || '').trim())
      },
      faClass(icon) {
        const value = String(icon).trim()
        return /\bfa-(solid|regular|brands)\b/.test(value) ? value : `fa-solid ${value}`
      },
      async fetchSavings() {
        const token = this.$auth?.strategy?.token?.get()
        const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'

        try {
          const { data } = await this.$axios.get(
            `${baseUrl}api/v1/user/savings`,
            { headers: { Authorization: `Bearer ${token}` } }
          )
          this.totalSaved = data?.total_saved ?? null
        } catch (e) {
          this.totalSaved = null
        }
      },

      async fetchTransactions(page = 1) {
        const token = this.$auth?.strategy?.token?.get()
        const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'

        const { data } = await this.$axios.get(
          `${baseUrl}api/v1/user/wallet/transactions?page=${page}&order=${this.txnOrder}&type=${this.txnType}`,
          { headers: { Authorization: `Bearer ${token}` } }
        )

        this.transactions = data.data
        this.pagination = {
          current_page: data.current_page,
          last_page: data.last_page,
          next_page_url: data.next_page_url,
          prev_page_url: data.prev_page_url,
          total: data.total,
          from: data.from,
          to: data.to
        }
      },
    },
    async mounted() {
      /*
       * Four independent requests, run together rather than awaited one after
       * another -- the page was costing the customer the sum of four round
       * trips. allSettled so one failing panel does not hold the other three.
       */
      try {
        await Promise.allSettled([
          this.fetchWallet(),
          this.fetchPoints(),
          this.fetchTransactions(1),
          this.fetchQualifyingProducts(1),
          this.fetchSavings(),
          this.fetchMembership(),
          this.fetchPointsHistory(1)
        ])
      } catch (e) {
        console.error('Savers Club load failed:', e)
      } finally {
        this.loading = false
      }
    }
  }
</script>

<style scoped>
.card-box-club {
    background: #fff;
    border-radius: 15px;
    border: 1px solid #e6e8f0;
}

button.btn-statement {
    background-color: #F7F7FA;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    padding: 9px 16px;
    height: auto;
    line-height: 1.2;
    color: #525A6B;
}

button.btn-add-money {
    background-color: #33319A;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    padding: 9px 16px;
    height: auto;
    line-height: 1.2;
    color: #fff;
}

button.btn-add-money:hover {
    background-color: #05B942;
}

.section-header {
    background: #F3F3FA;
    border-top-left-radius: 15px;
    border-top-right-radius: 15px;
}

/* Feature icon */
.feature-icon {
    width: 60px;
    height: 60px;
    background: #f1f2f6;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto;
    font-size: 20px;
}

/* Plan box */
.plan-box {
    background: #f7f8fc;
    border-radius: 12px;
    padding: 20px;
}

/* Active badge */
.badge-active {
    background: #4b49ac;
    color: #fff;
    font-size: 11px;
    padding: 3px 8px;
    border-radius: 6px;
}

/* Buttons */
.btn-success {
    background: #28a745;
    border: none;
    border-radius: 8px;
}

.btn-light {
    background: #eef0f6;
    border: none;
    width: 10%;
}

/* Header strip */
.section-header {
    background: #f1f2f6;
}

/* Table */
.custom-table thead {
    background: #f7f8fc;
}

.custom-table th {
    font-size: 12px;
    color: #130E2B;
    font-weight: 600;
}

.custom-table td {
    vertical-align: middle;
    padding: 10px 20px;
    font-size: 14px;
    font-weight: 400;
    color: #130E2B;
}
/* Badges */
.badge {
    font-size: 11px;
    padding: 3px 8px;
    border-radius: 6px;
}

.badge.credit {
    background: #e6f7ee;
    color: #28a745;
}

.badge.debit {
    background: #fdecea;
    color: #dc3545;
}

/* Pagination */
.page-number {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: #f1f2f6;
    display: flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    color: #333;
}

.page-number.active {
    background: #333199;
    color: #fff;
}

.page-btn {
    color: #666;
    text-decoration: none;
    font-size: 14px;
}
.product-card {
    border: 1px solid #e6e8f0;
    border-radius: 12px;
    padding: 15px;
    position: relative;
    transition: 0.2s;
}

.product-card:hover {
    border-color: #4b49ac;
    transform: translateY(-2px);
}

/* Points badge */
.points-badge {
    position: absolute;
    top: 10px;
    right: 10px;
    background: #130E2B;
    color: #fff;
    font-size: 11px;
    padding: 3px 8px;
    border-radius: 6px;
}

/* Image */
.product-img {
    height: 120px;
    background: #f1f2f6;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 30px;
    margin-bottom: 10px;
}

/* Name */
.product-name {
    font-size: 14px;
    margin-bottom: 8px;
}

/* Button */
.btn-outline-primary {
    border-color: #4b49ac;
    color: #4b49ac;
}

.btn-outline-primary:hover {
    background: #4b49ac;
    color: #fff;
}

/* Primary button */
.btn-primary {
    background: #4b49ac;
    border: none;
}
.step-item {
    gap: 12px;
    align-items: flex-start;
}

.step-circle {
    width: 32px;
    height: 32px;
    flex: 0 0 32px;
    align-self: flex-start;
    margin-top: 2px;
    background: #4b49ac;
    color: #fff;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
}

/* Benefits */
.benefit-item {
    background: #f7f8fc;
    border-radius: 10px;
    padding: 10px 12px;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 10px;
}

/* Check icon */
.check-icon {
    background: #28a745;
    color: #fff;
    font-size: 12px;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}
button.btn-Manage-Subscription {
    background-color: #05B942;
    border: none;
    color: #fff;
    font-weight: 500;
    font-size: 14px;
    width: 89%;
    border-radius: 8px;
    padding: 11px 16px;
    height: auto;
    line-height: 1.2;
}
button.btn-Manage-Subscription:hover {
    background-color: #33319A;
}
.faq-item {
    padding: 12px 0;
    border-bottom: 1px solid #e6e8f0;
}

.faq-item strong {
    font-size: 14px;
    display: block;
    margin-bottom: 5px;
    font-weight: 400;
}
tr.recent-table th {
    background-color: aliceblue;
    border: 1px solid #E3E3EF;
    padding: 12px 20px;
}

.faq-item p {
    font-size: 13px;
    color: #666;
    margin: 0;
}
button.Qualifying.Products-btn {
    background-color: #33319A;
    color: #fff;
    font-weight: 400;
    border-radius: 8px;
    font-size: 14px;
    padding: 9px 0;
}
button.Qualifying.Products-btn:hover {
    background-color: #05B942;
}
button.product-view-btn {
    border: 1px solid #333199;
    border-radius: 80px;
    color: #333199;
    font-size: 12px;
    font-weight: 500;
    padding: 3px 20px;
}
button.product-view-btn:hover {
    color: #fff;
    background-color: #333199;
}
.wallet-card {
    border-radius: 15px;
    color: #fff;
    background: linear-gradient(135deg, #9E1C82, #33319A);
}
 
/* Divider */
.wallet-card {
    border-radius: 15px;
    color: #fff;
    background: linear-gradient(135deg, #9E1C82, #33319A);
}
h6.wallet-money {
    font-size: 18px;
    font-weight: 400;
    color: #130E2B;
}
small.wallet-topup {
    font-size: 14px;
    font-weight: 400;
    color: #6B7280;
}
 
/* Amount buttons */
.amount-btn {
    flex: 1;
    border-radius: 8px;
    border: 2px solid #E3E3EF;
    background: #fff;
    transition: 0.2s;
    font-size: 18px;
    font-weight: 400;
    color: #130E2B;
}
 
.amount-btn:hover,
.amount-btn.active {
    border-color: #4b49ac;
    color: #4b49ac;
}
 
/* Input */
.custom-input-wallet {
    background: #F7F7FA;
    border-radius: 10px;
    border: 1px solid #e6e8f0;
    font-size: 14px !important;
    padding: 15px 12px !important;
}
button.btn-add-wallet {
    background-color: #33319A;
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    padding: 12px 16px;
    height: auto;
    line-height: 1.2;
}
button.btn-add-wallet:hover {
    background-color: #05B942;
}
 
/* Points box */
.points-box {
    border: 1px solid #E3E3EF;
    background-color: #F7F7FA;
    border-radius: 14px;
    padding: 10px 15px;
}
span.conversion-rate {
    font-size: 14px;
    font-weight: 400;
    color: #6B7280;
}
span.pts {
    font-size: 14px;
    color: #130E2B;
    font-weight: 400;
}
/* Progress */
.progress-track {
    height: 6px;
    background: #e6e8f0;
    border-radius: 10px;
    margin: 6px 0;
}
 
.progress-fill {
    height: 100%;
    border-radius: 10px;
}
 
.progress-fill.green {
    background:#24C15A;
}
button.btn-redeem-point {
    background-color: #E4E5ED;
    color: #130E2B;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    padding: 12px 16px;
    height: auto;
    line-height: 1.2;
}
button.btn-redeem-point:hover {
    background-color: #33319A;
    color: #fff;
}
 
/* Divider */
.wallet-divider {
    height: 1px;
    background: rgba(255,255,255,0.2);
}
.permium-member-btn {
    display: inline-flex;
    align-items: center;
    background-color: #FFFFFF1A;
    border: 1px solid rgba(255, 255, 255, .22);
    border-radius: 999px;
    font-size: 14px;
    color: #FFFFFF;
    font-weight: 500;
    padding: 7px 14px;
    white-space: nowrap;
}
p.member-state {
    font-size: 12px;
    font-weight: 500;
    color: #FFFFFF;
    opacity: .9;
    margin-bottom: 2px;
}
p.member-since {
    font-size: 12px;
    font-weight: 400;
    color: #FFFFFF;
}
p.wallet-balance {
    font-size: 14px;
    font-weight: 400;
    color: #FFFFFF;
    margin-bottom: 0px;
}
.cherry-points-total {
    font-size: 24px;
    font-weight: 400;
    color: #FFFFFF;
    margin: 7px 0;
}
 
.wallet-amount {
  color: #fff;
  font-weight: 600;
}

.locked-info {
  background: #f1f2f6;
  padding: 4px 8px;
  border-radius: 6px;
  font-size: 11px;
  font-weight: 500;
}

/* ══════════ responsive refresh ══════════
 * Buttons set their own height/line-height and hover backgrounds are
 * !important: base.styl's global button rules otherwise win.
 */

/* base.styl puts letter-spacing: 1px on every h6 */
h6,
.wallet-money,
.premium-member-box h6 {
  letter-spacing: normal;
}

h5.order-hrading { font-size: clamp(22px, 1.1rem + 1.2vw, 30px); }
.wallet-money { font-size: clamp(16px, .9rem + .3vw, 18px); font-weight: 700; color: #130E2B; }

/*
 * cart-flow.css has a global .check-icon (position: absolute; top/right
 * -10px) for the address cards. It pulled every benefit tick out of its row
 * and stacked them off the right edge of the page (5px of sideways scroll).
 */
.benefit-item .check-icon {
  position: static;
  display: flex;
  flex: 0 0 20px;
  width: 20px;
  height: 20px;
  font-size: 11px;
  background: #28a745;
}

/* wallet banner stats: always three across */
.wallet-stats {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
}

.wallet-stats > div { min-width: 0; }
.wallet-amount { font-size: clamp(28px, 1.4rem + 1.6vw, 40px); }

/* membership features */
.feature-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
}

.feature-grid h6 { font-size: 14px; font-weight: 700; margin-bottom: 2px; }

/* plan actions */
.plan-actions {
  display: flex;
  gap: 10px;
}

.plan-actions .btn-Manage-Subscription { flex: 1 1 auto; }

.btn-plan-cancel {
  height: 42px;
  line-height: 1;
  padding: 0 18px;
  border: 1px solid #E3E3EF;
  border-radius: 10px;
  background: #fff;
  color: #130E2B;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
}

.btn-plan-cancel:hover { background: #F5F5FF !important; border-color: #33319A; }

/* transactions */
.txn-order {
  flex-shrink: 0;
  font-size: 12.5px;
  font-weight: 600;
  color: #6B7280;
  background: #F3F4F6;
  padding: 5px 12px;
  border-radius: 999px;
  white-space: nowrap;
}

.txn-empty td {
  text-align: center;
  color: #6B7280;
  padding: 28px 16px !important;
}

/* buttons: own metrics */
.btn-statement,
.btn-add-money,
.btn-add-wallet,
.btn-redeem-point,
.btn-Manage-Subscription,
.amount-btn {
  line-height: 1;
}

.btn-statement,
.btn-add-money { height: 40px; }

.btn-add-wallet,
.btn-redeem-point,
.btn-Manage-Subscription,
.amount-btn { height: 46px; }

.btn-add-money:hover { background: #28267d !important; }
.btn-statement:hover { background: #E9EAF2 !important; }

/* ---------- tablet ---------- */

@media (max-width: 991.98px) {
  .row.g-4 > .col-md-6 { flex: 0 0 100%; max-width: 100%; }
}

/* ---------- phone ---------- */

@media (max-width: 767.98px) {

  .card-box-club.d-flex > .d-flex { width: 100%; }
  .btn-statement,
  .btn-add-money { flex: 1 1 0; justify-content: center; }

  .wallet-card { padding: 18px !important; }
  .wallet-stats { gap: 8px; }
  .wallet-stats small { font-size: 11px; display: block; line-height: 1.3; }
  .wallet-stats .cherry-points-total { font-size: 18px; margin: 4px 0 2px; }

  .feature-grid { gap: 8px; }
  .feature-grid h6 { font-size: 12.5px; }
  .feature-grid small { font-size: 11px; line-height: 1.35; display: block; }
  .feature-icon { width: 44px; height: 44px; margin: 0 auto; }

  .plan-box > .d-flex { flex-wrap: nowrap !important; gap: 12px; align-items: flex-start !important; }
  .plan-box .text-end { flex-shrink: 0; }
  .plan-actions { flex-direction: column; }
  .plan-actions > * { width: 100% !important; max-width: none !important; }

  .txn-head { padding: 18px !important; }

  /* each transaction becomes a two-line row: description + amount, then date + type */
  .custom-table thead { display: none; }
  .custom-table,
  .custom-table tbody { display: block; width: 100%; }

  .custom-table tr:not(.txn-empty) {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    grid-template-areas:
      "desc   amount"
      "date   type"
      ".      balance";
    gap: 2px 12px;
    padding: 12px 18px;
    border-bottom: 1px solid #F0F0F6;
  }

  .custom-table tr:not(.txn-empty) td {
    display: block;
    padding: 0 !important;
    border: 0 !important;
    font-size: 13px;
  }

  .custom-table td[data-label="Description"] { grid-area: desc; font-weight: 600; color: #130E2B; }
  .custom-table td[data-label="Amount"] { grid-area: amount; justify-self: end; font-weight: 700; }
  .custom-table td[data-label="Date"] { grid-area: date; color: #6B7280; }
  .custom-table td[data-label="Type"] { grid-area: type; justify-self: end; }
  .custom-table td[data-label="Balance"] { grid-area: balance; justify-self: end; color: #6B7280; }
  .custom-table td[data-label="Balance"]::before { content: 'Balance '; }

  .txn-empty,
  .txn-empty td { display: block; width: 100%; }

  /* qualifying products: two across, smaller */
  .product-card { padding: 10px; }
  .product-img { height: 110px; }
  .product-name { font-size: 13px; }
}

.wallet-pending {
  display: inline-block;
  margin: 8px 0 0;
  padding: 3px 10px;
  border-radius: 999px;
  background: rgba(255, 255, 255, .16);
  color: #fff;
  font-size: 12.5px;
}

.card-box-club { transition: box-shadow .25s ease, border-color .25s ease; }
.card-box-club.topup-highlight {
  border-color: #33319A;
  box-shadow: 0 0 0 4px rgba(51, 49, 154, .18);
}

.topup-hint { display: block; margin-top: 6px; font-size: 12.5px; color: #6B7280; }
.custom-input-wallet.is-invalid { border-color: #dc3545; }

.btn-redeem-point:disabled,
.btn-add-wallet:disabled,
.btn-statement:disabled { opacity: .6; cursor: not-allowed; }

.list-controls { display: flex; gap: 8px; flex-shrink: 0; }
select.list-select {
  height: 32px;
  padding: 0 28px 0 12px;
  border: 1px solid #E3E3EF;
  border-radius: 999px;
  background: #F3F4F6 url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath fill='%236B7280' d='M0 0h10L5 6z'/%3E%3C/svg%3E") no-repeat right 11px center;
  -webkit-appearance: none;
  appearance: none;
  color: #374151;
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
}
select.list-select:focus { outline: 2px solid rgba(51, 49, 154, .35); outline-offset: 1px; }
@media (max-width: 767.98px) {
  .txn-head { flex-wrap: wrap; }
  .list-controls { width: 100%; }
  .list-controls .list-select { flex: 1 1 0; }
}

.txn-order-link { color: #33319A; text-decoration: none; }
.txn-order-link:hover { text-decoration: underline; }

.page-gap { color: #6B7280; padding: 0 2px; }
.page-number:disabled { cursor: wait; }
tbody.txn-loading { opacity: .45; transition: opacity .15s; }

/* ---------- Use Your Points ---------- */
.qp-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
  margin-bottom: 20px;
}
.qp-head small { display: block; max-width: 620px; line-height: 1.45; }
.qp-balance {
  flex-shrink: 0;
  padding: 6px 12px;
  border-radius: 999px;
  background: #F3F3FA;
  color: #33319A;
  font-size: 13px;
  font-weight: 600;
  white-space: nowrap;
}
.qp-empty { padding: 28px 0; text-align: center; color: #6B7280; font-size: 14px; }
.qp-col { display: flex; }
.qp-card {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
  border: 1px solid #E6E8F0;
  border-radius: 12px;
  background: #fff;
  overflow: hidden;
  transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease;
}
.qp-card:hover { border-color: #C9C8E8; box-shadow: 0 6px 18px rgba(19, 14, 43, .08); transform: translateY(-2px); }
.qp-media {
  position: relative;
  display: block;
  aspect-ratio: 4 / 3;
  background: #F7F7FA;
  border-bottom: 1px solid #EEF0F5;
}
/* the image fills the frame; multiply blends white photo backgrounds into it */
.qp-media ::v-deep img {
  position: absolute;
  inset: 12px;
  width: calc(100% - 24px) !important;
  height: calc(100% - 24px) !important;
  object-fit: contain;
  mix-blend-mode: multiply;
}
.qp-badge {
  position: absolute;
  top: 10px;
  left: 10px;
  max-width: calc(100% - 20px);
  padding: 4px 9px;
  border-radius: 999px;
  background: #130E2B;
  color: #fff;
  font-size: 11px;
  font-weight: 600;
  line-height: 1.3;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.qp-card.is-ready .qp-badge { background: #15803D; }
.qp-body {
  flex: 1;
  display: flex;
  flex-direction: column;
  padding: 14px 14px 12px;
}
.qp-title {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  min-height: 2.7em;
  color: #130E2B;
  font-size: 14px;
  font-weight: 500;
  line-height: 1.35;
  text-decoration: none;
}
.qp-title:hover { color: #33319A; }
.qp-price { display: flex; align-items: baseline; flex-wrap: wrap; gap: 6px; margin: 8px 0 10px; }
.qp-price strong { font-size: 17px; font-weight: 700; color: #130E2B; }
.qp-price small { font-size: 11px; color: #6B7280; }
.qp-progress { height: 6px; border-radius: 999px; background: #ECEDF3; overflow: hidden; }
.qp-progress-fill { height: 100%; border-radius: 999px; background: #24C15A; transition: width .3s ease; }
.qp-status { margin: 6px 0 12px; font-size: 12px; line-height: 1.35; color: #6B7280; }
.qp-card.is-ready .qp-status { color: #15803D; font-weight: 600; }
.qp-view {
  margin-top: auto;
  display: flex;
  align-items: center;
  justify-content: center;
  height: 36px;
  border: 1px solid #D9D8F0;
  border-radius: 8px;
  color: #33319A;
  font-size: 13px;
  font-weight: 600;
  text-decoration: none;
  transition: background .15s ease, color .15s ease, border-color .15s ease;
}
.qp-view:hover { background: #33319A; border-color: #33319A; color: #fff; }
button.qp-more {
  height: 42px;
  line-height: 1;
  padding: 0 22px;
  border: 1px solid #33319A;
  border-radius: 8px;
  background: #fff;
  color: #33319A;
  font-size: 14px;
  font-weight: 600;
}
button.qp-more:hover:not(:disabled) { background: #33319A !important; color: #fff !important; }
button.qp-more:disabled { opacity: .6; cursor: wait; }
.qp-skeleton .qp-media { background: #F1F2F6; }
.qp-skeleton .sk-line { display: block; height: 10px; margin-bottom: 10px; border-radius: 4px; background: #ECEDF3; }
.qp-skeleton .sk-line.short { width: 55%; }
@media (max-width: 767.98px) {
  .qp-head { flex-direction: column; gap: 10px; }
  .qp-body { padding: 10px 10px 10px; }
  .qp-title { font-size: 13px; }
  .qp-price strong { font-size: 15px; }
  .qp-badge { font-size: 10px; padding: 3px 7px; }
}

.points-pending {
  margin-top: 8px;
  padding: 6px 10px;
  border-radius: 8px;
  background: #EEF9F1;
  color: #157A3A;
  font-size: 12.5px;
  line-height: 1.35;
}
button.points-activity-link {
  display: block;
  width: 100%;
  margin-top: 10px;
  height: auto;
  line-height: 1.3;
  padding: 4px 0;
  border: 0;
  background: none !important;
  color: #33319A;
  font-size: 13px;
  font-weight: 500;
  text-align: center;
}
button.points-activity-link:hover { text-decoration: underline; color: #33319A !important; }

/* a global list rule shrank this to its content (576px of a 1074px card) */
.points-list { list-style: none; margin: 0; padding: 0; display: block; width: 100%; }
.points-list > li { width: 100%; box-sizing: border-box; }
.points-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  padding: 12px 24px;
  border-top: 1px solid #F0F0F6;
}
.points-row-main { display: flex; flex-direction: column; min-width: 0; }
.points-row-desc { font-size: 14px; color: #130E2B; }
.points-row-pts { flex-shrink: 0; font-size: 14px; font-weight: 600; }
.points-empty { padding: 24px; text-align: center; color: #6B7280; border-top: 1px solid #F0F0F6; font-size: 14px; }
@media (max-width: 767.98px) {
  .points-row { padding: 12px 18px; }
  .points-empty { padding: 20px 18px; }
}

.qp-needed { display: block; font-size: 12px; color: #6B7280; margin-bottom: 6px; }
.qp-price { display: flex; flex-direction: column; min-width: 0; line-height: 1.2; }
.qp-vat { font-size: 11px; color: #6B7280; }

@media (max-width: 359.98px) {
  .wallet-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

/* membership box states (2026-10-15) */
.plan-alert {
  display: block;
  margin-top: 6px;
  font-size: 12.5px;
  color: #B42318;
}
.badge-active.badge-alert { background: #B42318; }
.badge-active.badge-ending { background: #B54708; }
.plan-confirm {
  background: #fff;
  border: 1px solid #E3E3EF;
  border-radius: 10px;
  padding: 14px 16px;
  font-size: 14px;
}
.btn-plan-danger {
  height: 42px;
  line-height: 1;
  padding: 0 18px;
  border: 0;
  border-radius: 10px;
  background: #B42318;
  color: #fff;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
}
.btn-plan-danger:hover { background: #912018 !important; }
.plan-change {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}
.plan-switch {
  height: 34px;
  line-height: 1;
  padding: 0 14px;
  border: 1px solid #E3E3EF;
  border-radius: 999px;
  background: #fff;
  color: #33319A;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}
.plan-switch:hover { background: #F5F5FF !important; }
.btn-Manage-Subscription:disabled,
.btn-plan-cancel:disabled,
.plan-switch:disabled { opacity: .6; cursor: default; }
.benefit-item small { font-size: 12px; }

/* joining on the page */
.join-form {
  padding: 16px;
  border: 1px solid #E3E3EF;
  border-radius: 12px;
  background: #fff;
}
.join-summary { margin: 0 0 12px; font-size: 14px; color: #475467; }
.join-summary strong { color: #130E2B; }

/* manage panel: card and payments */
.manage-panel {
  padding: 16px;
  border: 1px solid #E3E3EF;
  border-radius: 12px;
  background: #fff;
  font-size: 14px;
}
.manage-row { display: flex; justify-content: space-between; align-items: center; gap: 16px; }
.manage-card-form { padding-top: 4px; }
.manage-history { margin-top: 16px; padding-top: 14px; border-top: 1px solid #F0F0F5; }
.history-head-row { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; }
.history-scroll {
  max-height: 232px;
  overflow-y: auto;
  margin-top: 8px;
  border: 1px solid #F0F0F5;
  border-radius: 10px;
  transition: opacity .15s ease;
}
.history-scroll.is-loading { opacity: .5; }
.history-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.history-table th {
  position: sticky;
  top: 0;
  z-index: 1;
  padding: 8px 12px;
  background: #F7F8FC;
  color: #6B7280;
  font-size: 11.5px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .03em;
  text-align: left;
}
.history-table th.text-end { text-align: right; }
.history-table td { padding: 9px 12px; border-bottom: 1px solid #F3F3F8; }
.history-table tbody tr:last-child td { border-bottom: 0; }
.history-pager { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-top: 10px; }
.pager-text { font-size: 12.5px; color: #6B7280; }
.pager-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 32px;
  line-height: 1;
  padding: 0 12px;
  border: 1px solid #E3E3EF;
  border-radius: 8px;
  background: #fff !important;
  color: #33319A;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}
.pager-btn:hover:enabled { background: #F5F5FF !important; }
.pager-btn:disabled { opacity: .45; cursor: default; }
.plan-actions .btn-plan-cancel { white-space: nowrap; flex-shrink: 0; }
.inv-status { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11.5px; font-weight: 600; background: #F2F4F7; color: #475467; }
.inv-status.paid { background: #ECFDF3; color: #027A48; }
.inv-status.open, .inv-status.uncollectible { background: #FEF3F2; color: #B42318; }
@media (max-width: 575.98px) {
  .history-table .col-period { display: none; }
  .manage-row { flex-wrap: wrap; }
}

/* new product emails */
.digest-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
  padding: 12px 14px;
  border: 1px solid #E3E3EF;
  border-radius: 10px;
  background: #fff;
  font-size: 14px;
}
.digest-switch {
  position: relative;
  flex: 0 0 44px;
  width: 44px;
  height: 24px;
  line-height: 1;
  padding: 0;
  border: 0;
  border-radius: 999px;
  background: #D1D5DB !important;
  cursor: pointer;
}
.digest-switch[aria-checked='true'] { background: #05B942 !important; }
.digest-switch span {
  position: absolute;
  top: 3px;
  left: 3px;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: #fff;
  box-shadow: 0 1px 3px rgba(0, 0, 0, .2);
  transition: transform .2s ease;
}
.digest-switch[aria-checked='true'] span { transform: translateX(20px); }

/* monthly / yearly choice */
.billing-toggle {
  display: inline-flex;
  padding: 3px;
  border-radius: 10px;
  background: #EEF0F6;
}
.billing-toggle button {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 34px;
  line-height: 1;
  padding: 0 16px;
  border: 0;
  border-radius: 8px;
  background: transparent;
  color: #6B7280;
  font-size: 13.5px;
  font-weight: 600;
  cursor: pointer;
}
.billing-toggle button[aria-checked='true'] {
  background: #fff !important;
  color: #130E2B;
  box-shadow: 0 1px 2px rgba(16, 24, 40, .12);
}
.billing-save {
  padding: 2px 7px;
  border-radius: 999px;
  background: #ECFDF3;
  color: #027A48;
  font-size: 11px;
  font-weight: 700;
}
.per-month { font-size: 12px; }
</style>
