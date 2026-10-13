<template>
  <account-layout
    active-route="wishlists"
    @clicked-wishlists="loadData"
    class="flow-hidden"
  >
    <template v-slot:rightArea>

      <!--
        The spinner used to sit in its own block with the page rendered
        underneath it, so while the list loaded the customer saw the heading,
        the panel and "No wishlist yet" all at once -- an empty state for data
        that had not arrived. The skeleton now replaces the content instead of
        sitting above it.
      -->
      <account-skeleton
        v-if="fetchingWishlistData"
        :tiles="6"
        heading
        label="Loading your wishlist"
      />

      <div v-else>

      <div class="card-box p-4 mb-4 d-flex justify-content-between align-items-center flex-wrap my-orders-box">
          <div>
              <h5 class="mb-1">My Wishlist</h5>
              <small class="text-muted">View and manage your wishlist items.</small>
          </div>
          <!-- Was href="#" with nothing behind it. -->
          <button
            type="button"
            class="share-link d-flex align-items-center share-whishlist"
            :aria-expanded="shareOpen ? 'true' : 'false'"
            aria-controls="wishlist-share-panel"
            @click="toggleShare"
          >
              <i class="fa-solid fa-share-nodes" aria-hidden="true"></i><span class="ms-2">Share Wishlist</span>
          </button>
      </div>

      <!--
        The share panel. One link per account, made on request and revocable:
        "Stop sharing" kills it, and sharing again issues a new one. The link
        shows the products and the first name only - see
        WishlistShareController.
      -->
      <section
        v-if="shareOpen"
        id="wishlist-share-panel"
        class="share-panel mb-4"
        aria-label="Share your wishlist"
      >
        <p v-if="shareLoading" class="share-help mb-0">Loading…</p>

        <template v-else-if="!shareToken">
          <h6 class="share-title">Share your wishlist</h6>
          <p class="share-help">
            Anyone with the link can see the items on your wishlist and your first name.
            They cannot see your email, address or orders, and you can stop sharing at any time.
          </p>
          <p v-if="!wishlistCount" class="share-help share-warn">
            Your wishlist is empty. Add something first, then share it.
          </p>
          <button
            type="button"
            class="share-btn share-btn-primary"
            :disabled="shareBusy || !wishlistCount"
            @click="enableShare"
          >
            {{ shareBusy ? 'Creating link…' : 'Create share link' }}
          </button>
        </template>

        <template v-else>
          <h6 class="share-title">Your wishlist link</h6>

          <div class="share-row">
            <label for="wishlist-share-url" class="sr-only">Wishlist link</label>
            <input
              id="wishlist-share-url"
              ref="shareInput"
              class="share-url"
              type="text"
              readonly
              :value="shareUrl"
              @focus="$event.target.select()"
            >
            <button type="button" class="share-btn share-btn-primary" @click="copyShare">
              {{ copied ? 'Copied' : 'Copy link' }}
            </button>
          </div>

          <div class="share-row share-targets">
            <button
              v-if="canNativeShare"
              type="button"
              class="share-btn"
              @click="nativeShare"
            >
              <i class="fa-solid fa-share-nodes" aria-hidden="true"></i> Share…
            </button>
            <a
              class="share-btn"
              :href="`https://wa.me/?text=${encodeURIComponent(shareText + ' ' + shareUrl)}`"
              target="_blank"
              rel="noopener"
            >
              <i class="fa-brands fa-whatsapp" aria-hidden="true"></i> WhatsApp
            </a>
            <a
              class="share-btn"
              :href="`mailto:?subject=${encodeURIComponent('My FixMyPC wishlist')}&body=${encodeURIComponent(shareText + '\n\n' + shareUrl)}`"
            >
              <i class="fa-solid fa-envelope" aria-hidden="true"></i> Email
            </a>
            <a class="share-btn" :href="shareUrl" target="_blank" rel="noopener">
              Preview
            </a>

            <button
              type="button"
              class="share-stop"
              :disabled="shareBusy"
              @click="disableShare"
            >
              {{ shareBusy ? 'Stopping…' : 'Stop sharing' }}
            </button>
          </div>
        </template>
      </section>
      <div class="Frequently-card-box p-0 mb-4">
        <div class="section-header d-flex justify-content-between align-items-center">
            <span>Saved items</span>
            <span v-if="wishlistCount" class="wl-count">{{ wishlistCount }} {{ wishlistCount === 1 ? 'item' : 'items' }}</span>
        </div>
        <div class="p-3">
            <div v-if="currentWishLists && !currentWishLists.length" class="info-msg">
              {{ $t('wishlist.noWishlist') }}
            </div>
            <div v-else>
              <product-view-list
                v-for="(value, index) in currentWishLists"
                  :key="index"
                  :product="value.product"
                  :index="index"
                  type="wishlist"
                  @removed="fetchingData"
                />
            </div>
        </div>
      </div>

      

      

      <!-- <div
        v-else
        class="area"
      >
        <div class="area-content">
          <div class="tile-container">
            <div class="shimmer-wrapper pt-15" v-if="fetchingWishlistData">
              <tile-shimmer
                v-for="index in 8"
                :key="index"
              />
            </div>
            <template v-else>
              <product-tile
                v-for="(value, index) in currentWishLists"
                :key="index"
                :product="value.product"
              />
            </template>
          </div>
        </div>
      </div> -->

      <pagination
        class="mt-20 mt-sm-15"
        ref="wishlistPagination"
        :total-page="totalPage"
        @fetching-data="fetchingData"
      />

      </div>

    </template>
  </account-layout>
</template>

<script>

  import {mapGetters, mapActions} from 'vuex'
  import util from '~/mixin/util'
  import LazyImage from '~/components/LazyImage'
  import RatePopup from '~/components/RatePopup'
  import AccountLayout from '~/components/AccountLayout'
  import ProductTile from '~/components/ProductTile'
  import routeParamHelper from '~/mixin/routeParamHelper'
  import Pagination from '~/components/Pagination'
  import productHelper from '~/mixin/productHelper'
  import Spinner from "~/components/Spinner";
  import AccountSkeleton from '~/components/AccountSkeleton'
  import global from '~/mixin/global'
import ProductViewList from '../../components/ProductViewList.vue'

  export default {

    // No 'auth': a guest keeps a wishlist against its own token, the same way
    // it keeps a basket, so there is nothing to sign in for here.
    middleware: ['common-middleware'],
    head() {
      return {
        title: 'Wishlists',
        meta: []
      }
    },
    data() {
      return {
        allWishlist: null,
        // Share Wishlist
        shareOpen: false,
        shareLoading: false,
        shareBusy: false,
        shareToken: null,
        copied: false,
        // True from the start: the first render happens before mounted()
        // fetches, and false here is what flashed the empty state.
        fetchingWishlistData: true
      }
    },
    watch: {},
    components: {
      Spinner,
      AccountSkeleton,
      LazyImage,
      RatePopup,
      AccountLayout,
      Pagination,
      ProductTile
    },
    mixins: [util, routeParamHelper, productHelper, global],
    computed: {
      totalPage() {
        return this.allWishlist?.last_page
      },
      currentWishLists() {
        return this.allWishlist?.data
      },
      wishlistCount() {
        return this.allWishlist?.total || (this.currentWishLists || []).length
      },
      shareUrl() {
        if (!this.shareToken || !process.client) return ''
        return `${window.location.origin}/wishlist/shared/${this.shareToken}`
      },
      shareText() {
        return 'Have a look at my wishlist on FixMyPC:'
      },
      canNativeShare() {
        return process.client && typeof navigator !== 'undefined' && !!navigator.share
      },
      ...mapGetters('language', ['langCode']),
    },
    methods: {
      async loadData() {
        this.$refs.wishlistPagination.routeParam()
      },
      async fetchingData() {
        this.fetchingWishlistData = true
        setTimeout(async () => {

          try {
            this.settingRouteParam()


            // A guest's list is owned by its token; ask for one rather than
            // relying on whatever an earlier page happened to establish.
            const userToken = this.$auth.loggedIn ? null : await this.getUserToken()

            const data = await this.getRequest({
              params: {
                time_zone: this.timeZone,
                order_by: this.orderBy,
                type: this.orderByType,
                page: this.page,
                q: this.search,
                user_token: userToken
              },
              api: 'userWishlistAll',
              requiredToken: true,
              lang: this.langCode,
            })

            if (data?.status !== 200) {
              this.hasError(data)
            } else if (data?.status === 200) {

              this.allWishlist = data.data

            }

          } catch (e) {
            return this.$nuxt.error(e)
          }
          this.fetchingWishlistData = false
        }, 100)

      },
      shareApi(method) {
        const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'
        return this.$axios({
          method,
          url: `${baseUrl}api/v1/user/wishlist/share`,
          headers: {Authorization: this.$auth?.strategy?.token?.get(), Accept: 'application/json'}
        }).then(r => r.data)
      },
      /*
       * A share link belongs to an account; a guest's wishlist lives on a
       * browser token that would stop working the moment it is cleared.
       */
      async toggleShare() {
        if (!this.$auth?.loggedIn) {
          this.setToastError('Sign in to share your wishlist.')
          this.$router.push('/login')
          return
        }

        this.shareOpen = !this.shareOpen
        if (!this.shareOpen) return

        this.shareLoading = true
        try {
          const data = await this.shareApi('get')
          this.shareToken = data?.status === 200 ? data.data?.token : null
        } catch (e) {
          this.setToastError('We could not load your share settings. Please try again.')
          this.shareOpen = false
        } finally {
          this.shareLoading = false
        }
      },
      async enableShare() {
        this.shareBusy = true
        try {
          const data = await this.shareApi('post')
          if (data?.status !== 200 || !data.data?.token) throw new Error('refused')
          this.shareToken = data.data.token
          this.$nextTick(() => this.$refs.shareInput?.select())
        } catch (e) {
          this.setToastError('We could not create a share link. Please try again.')
        } finally {
          this.shareBusy = false
        }
      },
      async disableShare() {
        this.shareBusy = true
        try {
          const data = await this.shareApi('delete')
          if (data?.status !== 200) throw new Error('refused')
          this.shareToken = null
          this.setToastMessage('Your wishlist link has been switched off.')
        } catch (e) {
          this.setToastError('We could not stop sharing. Please try again.')
        } finally {
          this.shareBusy = false
        }
      },
      async copyShare() {
        try {
          await navigator.clipboard.writeText(this.shareUrl)
        } catch (e) {
          // Older browsers and non-https origins: fall back to the selection.
          this.$refs.shareInput?.select()
          document.execCommand('copy')
        }
        this.copied = true
        clearTimeout(this.copiedTimer)
        this.copiedTimer = setTimeout(() => { this.copied = false }, 2000)
      },
      async nativeShare() {
        try {
          await navigator.share({title: 'My FixMyPC wishlist', text: this.shareText, url: this.shareUrl})
        } catch (e) {
          // Cancelled by the customer: nothing to report.
        }
      },
      ...mapActions('common', ['getRequest', 'setToastError', 'setToastMessage']),
      ...mapActions('user', ['getUserToken']),
    },
    beforeDestroy() {
      clearTimeout(this.copiedTimer)
    },

    async mounted() {
      await this.fetchingData()
    },
  }
</script>

<style scoped>
.section-header {
    background: #F3F3FA;
    padding: 12px 20px;
    border-top-left-radius: 15px;
    border-top-right-radius: 15px;
    font-weight: 400;
    font-size: 18px;
    color: #130E2B;
}

.Frequently-card-box {
    background-color: #fff;
    border: 1px solid #E3E3EF;
    border-radius: 16px;
    overflow: hidden;
}

.my-orders-box {
    background-color: #fff;
    border: 1px solid #E3E3EF;
    border-radius: 16px;
}

.my-orders-box h5 {
    font-size: clamp(22px, 1.1rem + 1.2vw, 30px);
    font-weight: 700;
    color: #130E2B;
}

/*
 * A button now, not a link: its own metrics, so base.styl's global button
 * height/line-height (50px / 48px) does not reach it.
 */
.my-orders-box .share-whishlist {
    height: auto;
    line-height: 1.4;
    padding: 8px 12px;
    border: 1px solid transparent;
    border-radius: 8px;
    background: transparent;
    font-size: 14px;
    font-weight: 500;
    color: #6B7280;
    cursor: pointer;
}

.my-orders-box .share-whishlist:hover,
.my-orders-box .share-whishlist[aria-expanded="true"] {
    /* !important: base.styl's global button:hover outranks any scoped rule */
    background: #F5F5FF !important;
    border-color: #E3E3EF;
    color: #33319A;
}

.share-panel {
    background: #fff;
    border: 1px solid #E3E3EF;
    border-radius: 16px;
    padding: 18px 20px;
}

.share-title {
    font-size: 16px;
    font-weight: 600;
    color: #130E2B;
    margin: 0 0 6px;
}

.share-help {
    font-size: 13.5px;
    color: #6B7280;
    line-height: 1.55;
    margin: 0 0 14px;
}

.share-warn { color: #B45309; }

.share-row {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}

.share-targets { margin-top: 12px; }

.share-url {
    flex: 1 1 260px;
    min-width: 0;
    height: 42px;
    padding: 0 12px;
    border: 1px solid #E3E3EF;
    border-radius: 8px;
    background: #F8F9FD;
    color: #130E2B;
    font-size: 13.5px;
}

.share-panel .share-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 40px;
    line-height: 1;
    padding: 0 14px;
    border: 1px solid #D7DAE9;
    border-radius: 8px;
    background: #fff;
    color: #130E2B;
    font-size: 13.5px;
    font-weight: 500;
    text-decoration: none;
    white-space: nowrap;
    cursor: pointer;
}

.share-panel .share-btn:hover:enabled,
.share-panel a.share-btn:hover {
    background: #fff !important;
    border-color: #33319A;
    color: #33319A;
}

.share-panel .share-btn-primary {
    background: #33319A;
    border-color: #33319A;
    color: #fff;
}

.share-panel .share-btn-primary:hover:enabled {
    background: #28267d !important;
    color: #fff;
}

.share-panel .share-btn:disabled {
    opacity: .55;
    cursor: not-allowed;
}

.share-panel .share-stop {
    margin-left: auto;
    height: auto;
    line-height: 1.4;
    padding: 6px 4px;
    border: 0;
    background: transparent;
    color: #DC2626;
    font-size: 13.5px;
    font-weight: 500;
    cursor: pointer;
}

.share-panel .share-stop:hover:enabled { text-decoration: underline; background: transparent !important; }

.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
}

/* the item count in the list heading */
.wl-count {
    font-size: 13px;
    font-weight: 600;
    color: #33319A;
    background: #EEEEFA;
    padding: 3px 10px;
    border-radius: 999px;
}

@media (max-width: 767px) {
    /* title and Share on one row; Share used to wrap under the subtitle */
    .my-orders-box { flex-wrap: nowrap !important; align-items: flex-start !important; gap: 12px; }
    .my-orders-box .share-whishlist { flex-shrink: 0; padding: 8px 10px; font-size: 13px; }
    .section-header { font-size: 16px; padding: 12px 16px; }
    .share-panel { padding: 16px; }
    .share-panel .share-stop { margin-left: 0; }
    .my-orders-box { padding: 18px !important; }
    .my-orders-box h5 { font-size: 22px; }
    .section-header { font-size: 15px; padding: 12px 16px; }
}
</style>
