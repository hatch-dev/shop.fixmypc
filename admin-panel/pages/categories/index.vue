<template>
  <div>
    <!-- Top Bar -->
    <div class="top-bar">
        <div class="page-header">
          <div class="breadcrumb">
              <span>Store</span>
              <i class="fa fa-chevron-right"></i>
              Categories
          </div>
          <div class="page-title">
              Category Management
          </div>
        </div>
        <div class="page-actions">
           <!-- Filter -->
          <div v-if="showFilter" class="filter-bar">
            <div class="filter-item">
              <label>Sort By</label>
              <select v-model="orderby" @change="fetchCategories()">
                <option value="created_at">Date</option>
                <option value="title">Title</option>
              </select>
            </div>
            <div class="filter-item">
              <label>Order</label>
              <select v-model="ordertype" @change="fetchCategories()">
                <option value="desc">Descending</option>
                <option value="asc">Ascending</option>
              </select>
            </div>
            <button class="reset-btn" @click="resetFilter">
              <i class="fa-solid fa-rotate-right"></i>
              Reset
            </button>
          </div>
          <div class="search-box">
            <input type="text" placeholder="Search Categories" v-model="search" @input="searchCategory">
          </div>
          <button class="btn btn-filter" @click="showFilter = !showFilter">
            <i class="fa fa-filter"></i> Filter
          </button>
          <button class="btn black" @click="addCategory">
            <i class="fa fa-plus"></i> Add Category
          </button>
        </div>
    </div>

    <!-- Cards -->
    <div class="cards">
      <div class="container">
        <div class="card">
            <div class="card-inr blue-col">
              <div class="icon-box blue"><i class="fa fa-layer-group"></i></div>
              <div class="card-prcn">
                +12%
              </div>
            </div>
            <div class="cart-cnt">
              <h4>Total Categories</h4>
              <h2>{{ stats.total_categories }}</h2>
            </div>
        </div>
        <div class="card">
            <div class="card-inr purple-col">
              <div class="icon-box purple"><i class="fa fa-sitemap"></i></div>
              <div class="card-prcn">
                +8%
              </div>
            </div>
            <div class="cart-cnt">
              <h4>Sub-Categories</h4>
              <h2>{{ stats.total_subcategories }}</h2>
            </div>
        </div>
        <div class="card">
            <div class="card-inr green-col">
              <div class="icon-box green"><i class="fa-solid fa-circle-check"></i></div>
              <div class="card-prcn">
                  Active
              </div>
            </div>
            <div class="cart-cnt">
              <h4> Published</h4>
              <h2>{{ stats.active_categories }}</h2>
            </div>
        </div>
        <div class="card">
            <div class="card-inr orange-col">
              <div class="icon-box orange"><i class="fa fa-clock"></i></div>
              <div class="card-prcn">
                  Pending
              </div>
            </div>
            <div class="cart-cnt">
              <h4>Draft</h4>
              <h2>{{ stats.draft_categories }}</h2>
            </div>
        </div>
      </div>
    </div>

    <!-- Table -->
    <div class="table-box">
        <div class="container">
          <div class="table-header">
              <div>
                <h3>All Categories</h3>
                <p>Manage your product categories and sub-categories</p>
              </div>
              <div class="table-actions">
                <div class="limit-box">
                  <label>Show</label>
                  <select v-model="limit" @change="fetchCategories()">
                    <option value="5">5</option>
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                    <option value="all">All</option>
                  </select>
                  <span>entries</span>
                </div>
                <button class="btn"><i class="fa fa-download"></i> Export</button>
                <button class="btn"><i class="fa fa-rotate" @click="fetchCategories()"></i> Refresh</button>
              </div>
          </div>
          <table>
              <thead>
                <tr>
                    <th class="col-checkbox"><input type="checkbox"></th>
                    <th @click="sortBy('title')" class="sortable col-category">
                      Category
                      <i v-if="orderby === 'title'" :class="ordertype === 'asc' ? 'fa fa-arrow-up' : 'fa fa-arrow-down'"></i>
                    </th>
                    <th @click="sortBy('slug')" class="sortable col-slug">
                      Slug
                      <i v-if="orderby === 'slug'" :class="ordertype === 'asc' ? 'fa fa-arrow-up' : 'fa fa-arrow-down'"></i>
                    </th>
                    <th @click="sortBy('parent_id')" class="sortable col-parent">
                      Parent
                      <i v-if="orderby === 'parent_id'" :class="ordertype === 'asc' ? 'fa fa-arrow-up' : 'fa fa-arrow-down'"></i>
                    </th>
                    <th @click="sortBy('products_count')" class="sortable col-products">
                      Products
                      <i v-if="orderby === 'products_count'" :class="ordertype === 'asc' ? 'fa fa-arrow-up' : 'fa fa-arrow-down'"></i>
                    </th>
                    <th @click="sortBy('status')" class="sortable col-status">
                      Status
                      <i v-if="orderby === 'status'" :class="ordertype === 'asc' ? 'fa fa-arrow-up' : 'fa fa-arrow-down'"></i>
                    </th>
                    <th @click="sortBy('created_at')" class="sortable col-created">
                      Created
                      <i v-if="orderby === 'created_at'" :class="ordertype === 'asc' ? 'fa fa-arrow-up' : 'fa fa-arrow-down'"></i>
                    </th>
                    <th class="col-actions">Actions</th>
                </tr>
              </thead>
              <tbody>
                <template v-for="cat in itemList">

                  <!-- PARENT CATEGORY -->
                  <tr :key="cat.id" class="clickable-row" @click="editNode(cat)">
                    <td class="expand-checkbox">
                      <span
                        v-if="cat.child && cat.child.length"
                        class="expand-icon"
                        @click.stop="toggle(cat)"
                      >
                        <i :class="cat.open ? 'fa fa-chevron-down' : 'fa fa-chevron-right'"></i>
                      </span>
                      <span v-else class="expand-placeholder"></span>
                      <input type="checkbox" :value="cat.id" v-model="cbList" @click.stop>
                    </td>
                    <td>
                      <div class="category-cell parent-cat">
                        <img :src="getImage(cat.image)" class="category-thumb">
                        <div class="cel-inr">
                          {{ cat.title }}
                          <span class="category-type primary">Primary</span>
                        </div>
                      </div>
                    </td>
                    <td>
                      <div class="cel-inr">
                        <span>
                          {{ cat.slug }}
                        </span>
                      </div>
                    </td>
                    <td>
                      <div class="cel-inr">
                        <span>
                          -
                        </span>
                      </div>
                    </td>
                    <td>{{ cat.products_count ?? 0 }}</td>
                    <td>
                      <span class="badge" :class="cat.status === 1 ? 'green' : 'orange'">
                        <i class="fa-solid fa-circle-check"></i>
                        {{ cat.status === 1 ? 'Published' : 'Draft' }}
                      </span>
                    </td>
                    <td>{{ formatDate(cat.created_at) }}</td>
                    <td class="actions">
                      <i class="fa fa-pen" @click.stop="editNode(cat)"></i>
                      <i v-if="!cat.child || cat.child.length === 0" class="fa fa-trash" @click.stop="deleteNode(cat)"></i>
                      <i class="fa-solid fa-code" @click.stop="openEmbedModal(cat)"></i>
                    </td>
                  </tr>
                  <!-- SUB CATEGORIES -->
                  <tr
                    v-for="child in cat.child"
                    :key="child.id"
                    v-if="cat.open"
                    class="sub-row clickable-row"
                    @click="editNode(child)"
                    >
                    <td>
                      <input type="checkbox" :value="child.id" v-model="cbList" @click.stop>
                    </td>
                    <td>
                      <div class="category-cell sb-cat">
                        <img :src="getImage(child.image)" class="category-thumb">
                        <div class="cel-inr">
                          {{ child.title }}
                          <span class="category-type secondary">
                            Subcategory
                          </span>
                        </div>
                      </div>
                    </td>
                    <td>
                      <div class="cel-inr category-cell sb-cat">
                        <span>
                          {{ child.slug }}
                        </span>
                      </div>
                    </td>
                    <td @click.stop>
                      <select
                        v-model="child.parent"
                        class="parent-select"
                        @change="updateParent(child)"
                      >
                        <option :value="null">Primary</option>
                        <option
                          v-for="p in parentCategories"
                          :key="p.id"
                          :value="p.id"
                          v-if="p.id !== child.id"
                        >
                          {{ p.title }}
                        </option>

                      </select>
                    </td>
                    <td>-</td>
                    <td>
                      <div class="cel-inr category-cell sb-cat">
                        <span class="badge" :class="child.status === 1 ? 'green' : 'orange'">
                          <i class="fa-solid fa-circle-check"></i>
                          {{ child.status === 1 ? 'Published' : 'Draft' }}
                        </span>
                      </div>
                    </td>
                    <td>
                        {{ formatDate(child.created_at) }}
                    </td>
                    <td class="actions">
                        <i class="fa fa-pen" @click.stop="editNode(child)"></i>
                        <i class="fa fa-trash"  @click.stop="deleteNode(child)"></i>
                    </td>
                  </tr>
                </template>
              </tbody>
          </table>
          <div class="pagination" v-if="pagination.total">
            <p class="shw-cat">
              Showing
              <span>{{ pagination.from }}</span>
              -
              <span>{{ pagination.to }}</span>
              of
              <span>{{ pagination.total }}</span>
              results
            </p>
            <div class="page-buttons">
              <span
                :disabled="pagination.current_page === 1"
                @click="changePage(pagination.current_page - 1)"
              >
                <i class="fa fa-chevron-left"></i>
              </span>
              <span
                v-for="page in pagination.last_page"
                :key="page"
                :class="{ active: page === pagination.current_page }"
                @click="changePage(page)"
              >
                {{ page }}
              </span>
              <span
                :disabled="pagination.current_page === pagination.last_page"
                @click="changePage(pagination.current_page + 1)"
              >
                <i class="fa fa-chevron-right"></i>
              </span>
            </div>
          </div>
        </div>
    </div>

    <!-- EMBED MODAL -->
    <div v-if="showEmbed" class="category-embed-modal">
      <div class="embed-box">

        <div class="embed-header">
          <h3>Product Preview</h3>
          <span class="close" @click="showEmbed = false">✕</span>
        </div>

        <div class="embed-body">

          <!-- PREVIEW -->
          <div class="embed-preview">
            <iframe
              :src="embedUrl"
              width="100%"
              height="300px"
              :style="previewStyle"
            ></iframe>
          </div>

          <!-- LEFT PANEL -->
          <div class="embed-left">

            <label>Layout</label>
            <select v-model="embed.layout">
              <option value="horizontal">Horizontal</option>
              <option value="vertical">Vertical</option>
            </select>

            <label>No. Of Products</label>
            <input type="number" v-model="embed.limit" />

            <div class="radio-group">
              <label class="radio-row">
                <input type="radio" value="normal" v-model="embed.mode">
                Normal
              </label>
              <label class="radio-row">
                <input type="radio" value="all" v-model="embed.mode">
                Show All
              </label>

              <label class="radio-row">
                <input type="radio" value="offers" v-model="embed.mode">
                Show Offers Only
              </label>
            </div>
          </div>

          <!-- RIGHT PANEL -->
          <div class="embed-right">

            <div class="config-box">
              <h4>Configuration</h4>

              <div class="radio-group">
                <label class="radio-row">
                  <input
                    type="radio"
                    value="none"
                    v-model="embed.border"
                  >
                  No Border
                </label>

                <label class="radio-row">
                  <input
                    type="radio"
                    value="product"
                    v-model="embed.border"
                  >
                  Enable Product Border
                </label>
              </div>

              <div class="size-inputs">
                <div>
                  <label>Height</label>
                  <input type="text" v-model="embed.height" />
                </div>

                <div>
                  <label>Width</label>
                  <input type="text" v-model="embed.width" />
                </div>
              </div>

              <div class="fluid-container mt-10">
                <label>
                  <input type="checkbox" v-model="embed.fluid">
                  Allow Fluid 100%
                </label>
              </div>
              

            </div>

            <!-- CODE -->
            <div class="code-box">
              <textarea readonly :value="generatedCode"></textarea>
              <button class="copy-btn icon-btn" @click="copyCode">
                <i class="fas fa-copy"></i>
              </button>
            </div>

          </div>

        </div>

      </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div v-if="showDeleteConfirm" class="modal-overlay">
      <div class="confirm-modal-box">

        <div class="confirm-icon">
          <i class="fa-solid fa-triangle-exclamation"></i>
        </div>

        <h3>Confirm Delete</h3>

        <p v-if="deleteType === 'single'">
          You are about to delete category
          <strong>{{ deleteTarget?.title }}</strong>.
        </p>

        <p v-if="deleteType === 'bulk'">
          You are about to delete
          <strong>{{ deleteTarget.length }}</strong> categories.
        </p>

        <p class="confirm-warning">
          This action cannot be undone.
          Are you sure you want to continue?
        </p>

        <div class="modal-actions">
          <button
            class="btn-cancel"
            @click="showDeleteConfirm = false"
          >
            Cancel
          </button>

          <button
            class="button primary-btn"
            @click="executeDelete"
          >
            Yes, Delete
          </button>
        </div>

      </div>
    </div>

  </div>
</template>

<script>

  import ListPage from "~/components/partials/ListPage"
  import util from '~/mixin/util'
  import LazyImage from "~/components/LazyImage"
  import TreeNode from "~/components/TreeNode"

  export default {
    name: "categories",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        showFilter: false,
        parentEditId: null,
        parentCategories: [],
        showDeleteConfirm: false,
        deleteTarget: null,
        deleteType: null,
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
        limit: 5,
        search: '',
        itemList:[],
        pagination:{},
        cbList:[],
        orderby: 'created_at',
        ordertype: 'desc',
        stats: {
          total_categories: 0,
          total_subcategories: 0,
          active_categories: 0,
          draft_categories: 0
        },
        showEmbed: false,
        selectedCategory: null,
        embed: {
          layout: 'horizontal',
          mode: 'normal',
          limit: 3,
          border: 'none',
          fluid: false,
          height: '400px',
          width: '100%'
        },
        orderOptions: {
          created_at: { title: this.$t('category.date') },
          title: { title: this.$t('index.title') },
          status: { title: this.$t('category.status') }
        }
      }
    },
    components: {
      TreeNode,
      LazyImage,
      ListPage
    },
    mixins: [util],
    computed: {
      previewStyle() {
        return {
          border: 'none'
        }
      },
      embedUrl() {
        if (!this.selectedCategory) return ''

        const baseUrl = `https://shop.fixmypc.ie/embed/category/${this.selectedCategory.id}`

        const params = new URLSearchParams({
          layout: this.embed.layout,
          limit: this.embed.mode === 'all' ? 'all' : this.embed.limit,
          offers: this.embed.mode === 'offers' ? 1 : 0,
          product_border: this.embed.border === 'product' ? 1 : 0
        })

        return `${baseUrl}?${params.toString()}`
      },
      generatedCode() {
        if (!this.selectedCategory) return ''

        return `<iframe src="${this.embedUrl}" width="${this.embed.fluid ? '100%' : this.embed.width}" height="${this.embed.height}"></iframe>`
      }
    },
    methods: {
      async fetchParentCategories() {
        try {
          const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'
          const res = await this.$axios.get(
            `${baseUrl}api/admin/category/all`,
            {
              params:{
                limit: 'all'
              }
            }
          )
          this.parentCategories = res.data.data.data
        } catch(e){
          console.error(e)
        }
      },
      async updateParent(node) {
        try {
          const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'
          await this.$axios.post(
            `${baseUrl}api/admin/category/update-parent`,
            {
              id: node.id,
              parent_id: node.parent
            }
          )
          this.parentEditId = null
          this.fetchCategories()
        } catch (e) {
          console.error(e)
        }
      },
      sortBy(field){
        if(this.orderby === field){
          this.ordertype = this.ordertype === 'asc' ? 'desc' : 'asc'
        }else{
          this.orderby = field
          this.ordertype = 'asc'
        }

        this.fetchCategories()
      },
      toggle(cat){
        cat.open = !cat.open
      },
      resetFilter(){
        this.orderby = 'created_at'
        this.ordertype = 'desc'
        this.search = ''
        this.showFilter = false
        this.fetchCategories()
      },
      changePage(page){
        if(page === this.pagination.current_page) return
        this.fetchCategories(page)
      },
      addCategory(){
        this.$router.push('/categories/add')
      },
      searchCategory() {
        this.fetchCategories()
      },
      formatDate(date) {
        if (!date) return ''

        return new Date(date).toLocaleDateString('en-US', {
          year: 'numeric',
          month: 'short',
          day: '2-digit'
        })
      },
      getImage(image) {
        const base = process.env.IMAGE_URL || 'https://shop.fixmypc.ie/uploads/'
        return image ? base + image : 'default-image.webp'
      },
      async fetchCategories(page = 1) {
        try {
          const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'
          const res = await this.$axios.get(
            `${baseUrl}api/admin/category/all`,
            {
              params:{
                page: page,
                limit: this.limit,
                type: this.ordertype,
                orderby: this.orderby,
                q: this.search,
                time_zone: this.timezone
              },
              withCredentials: true
            }
          )

          const data = res.data.data
          this.itemList = data.data.map(cat => ({
            ...cat,
            open: true
          }))

          this.pagination = data

          this.stats.total_categories = data.total_categories || 0
          this.stats.total_subcategories = data.total_subcategories || 0
          this.stats.active_categories = data.active_categories || 0
          this.stats.draft_categories = data.draft_categories || 0
        } catch(e){
          console.error(e)
        }
      },
      async executeDelete(){
        try{

          const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'

          if(this.deleteType === 'single'){
            await this.$axios.delete(
              `${baseUrl}api/admin/category/delete/${this.deleteTarget.id}`
            )
          }

          if(this.deleteType === 'bulk'){
            const ids = this.deleteTarget.join(',')
            await this.$axios.delete(
              `${baseUrl}api/admin/category/delete/${ids}`
            )
            this.cbList = []
          }

          this.showDeleteConfirm = false
          this.fetchCategories()

        }catch(e){
          console.error(e)
        }
      },
      async deleteBulk(){
        if(!this.cbList.length) return
        this.deleteTarget = [...this.cbList]
        this.deleteType = 'bulk'
        this.showDeleteConfirm = true
      },

      editNode(node){
        this.$router.push(`/categories/${node.id}`)
      },
      async deleteNode(node){
        this.deleteTarget = node
        this.deleteType = 'single'
        this.showDeleteConfirm = true
      },
      openEmbedModal(node) {
        this.selectedCategory = node
        this.showEmbed = true
      },

      copyCode() {
        navigator.clipboard.writeText(this.generatedCode)
      }
    },
    mounted() {
      this.fetchCategories()
      this.fetchParentCategories()
    }
  }
</script>

<style>
.container{
margin:0 auto;
}
/* page header area under top bar */
.breadcrumb {
font-size: 14px;
color: #111827;
display: flex;
align-items: center;
gap: 5px;
line-height: 20px;
font-weight: 600;
margin-bottom: 5px;
}
.breadcrumb span {
color: #9CA3AF;
font-weight: 400;
}
.breadcrumb i {
font-size: 10px;
color: #9CA3AF;
}
.page-title {
font-size: 30px;
font-weight: 800;
color: #111827;
line-height: 1.2em;
}
/* search row */
.page-actions{
display: flex;
gap: 15px;
justify-content: flex-end;
align-items: end;
}
button.btn.btn-filter {
  border: 1px solid #e5e7eb;
}
/* top actions */
.top-bar {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 20px;
    align-items: center;
    padding: 25px;
    top: 0;
    z-index: 9999;
    border-radius: 10px;
}
.top-bar .btn {
border: 0;
background: #fff;
border-radius: 50px;
cursor: pointer;
font-size: 14px;
font-weight: 500;
height: 44px;
transition: all 0.3s ease;
}
.top-bar .btn:hover {
background: #33319a !important;
color: #fff !important;
}
.search-box{
position:relative;
}
.search-box input {
padding: 13px 12px 13px 38px;
border: 0;
border-radius: 50px;
width: 250px;
font-size: 14px;
font-weight: 400;
box-shadow: 0px 4px 20px -2px #0000000D;
}
.search-box i {
position: absolute;
left: 15px;
top: 50%;
transform: translateY(-50%);
color: #9CA3AF;
font-size: 15px;
}
.top-bar .btn.black {
background: #000;
color: #fff;
font-weight: 700 !important;
}
.top-bar .btn i {
margin-right: 1px;
}
/* stat cards */
.cards .container{
display:grid;
grid-template-columns:repeat(4,1fr);
gap:18px;
margin-bottom:25px;
}
.card {
background: #fff;
border-radius: 24px;
padding: 30px;
box-shadow: 0px 2px 8px 0px #0000000A;
box-shadow: 0px 0px 0px 1px #00000008;
}
.card-inr {
display: flex;
align-items: center;
justify-content: space-between;
}
.card-inr .card-prcn {
background: #EFF6FF;
border-radius: 50px;
display: flex;
align-items: center;
justify-content: center;
padding: 5px 9px;
font-weight: 700;
font-size: 12px;
color: #2563EB;
}
.card-inr.purple-col .card-prcn {
background: #FAF5FF;
color: #9333EA;
}
.card-inr.green-col .card-prcn {
background: #F0FDF4;
color: #16A34A;
}
.card-inr.orange-col .card-prcn {
background: #FFF7ED;
color: #EA580C;
}
.cart-cnt {
margin-top: 15px;
}
.icon-box {
width: 48px;
height: 48px;
display: flex;
align-items: center;
justify-content: center;
border-radius: 16px;
}
.blue {
background: #EFF6FF;
color: #2563EB;
}
.purple {
background: #FAF5FF;
color: #9333EA;
}
.green {
background: #F0FDF4;
color: #16A34A;
}
.orange {
background: #FFF7ED;
color: #EA580C;
}
.card .cart-cnt h4 {
font-size: 14px;
font-weight: 500;
color: #6B7280;
margin-bottom: 7px;
}
.card .cart-cnt h2 {
font-size: 30px;
font-weight: 700;
color: #111827;
}

.cel-inr span {
font-weight: 400;
font-size: 12px;
color: #6B7280;
}
.cel-inr {
display: flex;
flex-direction: column;
gap: 1.5px;
font-weight: 600;
font-size: 14px;
line-height: 20px;
color: #111827;
}

/* parent row */
.parent-cat {
  display: flex;
  align-items: center;
  gap: 12px;
}

.expand-icon,
.expand-placeholder{
width:16px;
display:flex;
align-items:center;
justify-content:center;
}

.expand-icon i{
cursor:pointer;
color:#9CA3AF;
transition:0.2s;
}

.expand-icon i:hover{
color:#111827;
}

/* smaller thumbnail */
.sub-row .category-thumb {
  width: 32px;
  height: 32px;
}

 /* table container */
.table-box .container {
background: #fff;
border-radius: 32px;
padding: 30px;
box-shadow: 0px 2px 8px 0px #0000000A;
box-shadow: 0px 0px 0px 1px #00000008;
}
.table-header{
display:flex;
justify-content:space-between;
align-items:center;
margin-bottom:15px;
}
.table-header h3 {
font-size: 18px;
font-weight: 700;
line-height: 1.2em;
color: #111827;
}
.table-header p {
font-size: 14px;
color: #6B7280;
line-height: 20px;
margin-top: 5px;
}
.table-actions{
display:flex;
align-items:center;
gap:12px;
}
.table-actions button {
margin-left: 8px;
color: #4B5563;
font-size: 14px;
font-weight: 500;
border: 1px solid #E5E7EB;
background: transparent;
border-radius: 50px;
transition: all 0.3s ease;
cursor: pointer;
}
.table-actions button:hover {
color: #fff !important;
border-color: #111827 !important;
background: #111827 !important;
}

.table-box th, .table-box td {
text-align:center;
}
.table-box thead tr th {
font-size: 14px;
}
.table-box .pagination {
  border: 0;
  border-top: 1px solid #e3e3e3;
  padding-top: 18px;
  margin-top: 30px;
  border-radius: 0 !important;
}
.category-cell{
display:flex;
align-items:center;
gap:10px;
}
.category-icon {
width: 48px;
height: 48px;
border-radius: 16px;
display: flex;
align-items: center;
justify-content: center;
color: #fff;
box-shadow: 0px 1px 2px 0px #0000000D;
}
.category-icon.blue {
background: linear-gradient(135deg, #60A5FA 0%, #2563EB 100%);
}
.category-icon.white {
border: 2px solid #E5E7EB;
color: #9CA3AF !important;
}
.category-icon.purple {
background: linear-gradient(135deg, #C084FC 0%, #9333EA 100%);
}
.category-icon.orange {
background: linear-gradient(135deg, #FB923C 0%, #EA580C 100%);
}
.badge {
padding: 5px 12px;
border-radius: 50px;
font-size: 12px;
font-weight: 600;
display: flex;
align-items: center;
justify-content: center;
gap: 4px;
width: fit-content;
}
.badge.green {
background: #F0FDF4;
color: #16A34A;
}
.badge.orange {
background: #FFF7ED;
color: #EA580C;
}
.badge i {
font-size: 6px;
}
.actions i {
margin-right: 22px;
cursor: pointer;
color: #9CA3AF;
}
.actions i:last-child {
margin-right: 0px !important;
}
/* pagination */
.pagination{
display:flex;
justify-content:space-between;
align-items:center;
margin-top:15px;
}
.pagination .shw-cat {
font-size: 14px;
font-weight: 400;
color: #6B7280;
}
.pagination .shw-cat span {
color: #111827;
font-weight: 500;
}
.page-buttons{
display:flex;
gap:6px;
}
.page-buttons span {
border: 1px solid #E5E7EB;
padding: 6px 10px;
border-radius: 8px;
cursor: pointer;
width: 36px;
height: 36px;
display: flex;
align-items: center;
justify-content: center;
font-size: 14px;
font-weight: 600;
color: #4B5563;
}
.page-buttons .active{
background:#000;
color:#fff;
border-color:#000;
}
.category-embed-modal {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.55);
  backdrop-filter: blur(6px);
  display: flex;
  justify-content: center;
  align-items: center;
  z-index: 9999;
  padding: 40px;
}

/* MODAL CONTAINER */
.embed-box {
  width: 1050px;
  background: #f8fafc;
  border-radius: 18px;
  box-shadow: 0 25px 60px rgba(0, 0, 0, 0.25);
  overflow: hidden;
  animation: fadeIn .2s ease;
}

/* HEADER */
.embed-header {
  padding: 22px 28px;
  background: #ffffff;
  border-bottom: 1px solid #e2e8f0;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.embed-header h3 {
  font-size: 20px;
  font-weight: 600;
  color: #0f172a;
  margin: 0;
}

.close {
  width: 38px;
  height: 38px;
  border-radius: 50%;
  background: #0f172a;
  color: #fff;
  display: flex;
  justify-content: center;
  align-items: center;
  cursor: pointer;
  font-size: 14px;
  transition: 0.2s;
}

.close:hover {
  background: #1e293b;
}

/* BODY LAYOUT */
.embed-body{
display:flex;
gap:28px;
padding:30px;
}

.embed-preview{
width:55%;
background:#fff;
border-radius:16px;
padding:16px;
box-shadow:0 2px 8px rgba(0,0,0,0.08);
display:flex;
justify-content:center;
align-items:center;
}

.embed-preview iframe{
width:100%;
height:350px;
border:none;
border-radius:10px;
}

/* LEFT PANEL */
.embed-left {
  width: 45%;
  background: #f1f5f9;
  border-radius: 16px;
  padding: 20px;
}

.embed-left label {
  display: block;
  font-size: 14px;
  font-weight: 500;
  margin-top: 15px;
  color: #334155;
}

.embed-left select {
    width: 100%;
}

.embed-left input:not([type="checkbox"]),
.embed-left select {
  margin-top: 6px;
  padding: 8px 10px;
  border-radius: 8px;
  border: 1px solid #cbd5e1;
  font-size: 14px;
  outline: none;
}

.embed-left input[type="checkbox"] {
  width: 16px;
  height: 16px;
  margin: 0;
  cursor: pointer;
}

/* RIGHT PANEL */
.embed-right {
  width: 75%;
}

/* CONFIGURATION BOX */
.config-box {
  background: #eef2f7;
  border-radius: 14px;
  padding: 22px;
  margin-bottom: 22px;
}

.config-box h4 {
  margin: 0 0 15px;
  font-weight: 600;
  color: #0f172a;
}

.config-box label {
  display: block;
  margin-bottom: 8px;
  font-size: 14px;
  color: #334155;
}

.size-inputs {
  display: flex;
  gap: 20px;
  margin-top: 14px;
}

.size-inputs input {
  width: 100%;
  padding: 8px 10px;
  border-radius: 8px;
  border: 1px solid #cbd5e1;
}

/* CODE SECTION */
.code-box {
  background: #0f172a;
  border-radius: 18px;
  padding: 22px;
  position: relative;
}

.code-box textarea {
  width: 100%;
  height: 140px;
  background: #1e293b;
  color: #e2e8f0;
  border: none;
  border-radius: 10px;
  padding: 16px;
  font-size: 13px;
  resize: none;
  outline: none;
}

/* COPY BUTTON */
.code-box button {
  margin-top: 16px;
  width: 46px;
  height: 46px;
  border-radius: 12px;
  border: none;
  background: #ffffff;
  cursor: pointer;
  font-weight: 600;
  transition: 0.2s;
}

.code-box button:hover {
  transform: scale(1.05);
}

.checkbox-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 12px;
}

.checkbox-row input {
  width: 16px;
  height: 16px;
  cursor: pointer;
}

.checkbox-row label {
  margin: 0;
  cursor: pointer;
}

.radio-group {
  margin-top: 10px;
}

.radio-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 10px;
  font-size: 14px;
  cursor: pointer;
}

.radio-row input {
  width: 16px;
  height: 16px;
  cursor: pointer;
}

.category-thumb{
width:40px;
height:40px;
object-fit:cover;
border-radius:10px;
border:1px solid #eee;
}

/* Filter */
.filter-bar{
align-items: end;
display: flex;
gap: 15px;
}

.filter-item{
display:flex;
flex-direction:column;
gap:4px;
}

.filter-item label{
font-size:12px;
font-weight:600;
color:#6B7280;
}

.filter-item select{
padding:9px 12px;
border:1px solid #E5E7EB;
border-radius:8px;
font-size:14px;
min-width:140px;
background:#fff;
}

.apply-btn{
background: #000;
color: #fff;
font-weight: 700 !important;
}

.apply-btn:hover{
    background: #33319a !important;
    color: #fff !important;
}

.reset-btn{
display:flex;
align-items:center;
gap:6px;
background:#F3F4F6;
border:none;
padding:9px 16px;
border-radius:8px;
cursor:pointer;
font-size:14px;
font-weight:600;
color:#374151;
transition:all .2s ease;
}

.reset-btn i{
font-size:13px;
}

.reset-btn:hover{
background:#E5E7EB;
}

.limit-box{
display:flex;
align-items:center;
gap:6px;
font-size:14px;
color:#6B7280;
margin-right:10px;
}

.limit-box select{
border:1px solid #E5E7EB;
border-radius:8px;
padding:6px 10px;
font-size:14px;
background:#fff;
cursor:pointer;
}

/* Sub category row styling */
.sub-row {
  background: #f4f6f9;
}

.sub-row td {
  padding: 8px 10px;
  font-size: 13px;
}

.clickable-row{
cursor:pointer;
transition:0.15s;
}

.clickable-row:hover{
background:#f9fafb;
}

.sortable{
cursor:pointer;
user-select:none;
}

.sortable:hover{
color:#111827;
}

.sortable i{
margin-left:6px;
font-size:11px;
color:#6B7280;
}

.category-type {
    text-align: center;
    padding: 0px 5px;
    margin-top: 4px;
    letter-spacing: 0.2px;
    border-radius: 8px;
}

/* Primary */
.category-type.primary{
background:#EEF2FF;
color:#4338CA;
border:1px solid #E0E7FF;
}

/* Secondary */
.category-type.secondary{
background:#ECFDF5;
color:#047857;
border:1px solid #D1FAE5;
}

.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9999;
  overflow-y: scroll;
}

.confirm-modal-box {
  background: #fff;
  width: 420px;
  max-width: 90%;
  border-radius: 14px;
  padding: 28px;
  text-align: center;
  box-shadow: 0 25px 60px rgba(0,0,0,0.25);
}

th.col-checkbox {
    width: 65px;
    padding: 12.5px 10px 12.5px 20px !important;
}
th.sortable.col-products {
    width: 100px;
}
th.sortable.col-status {
    width: 130px;
}
th.sortable.col-created {
    width: 155px;
}
th.col-actions {
    width: 180px;
}
th.sortable.col-parent, th.sortable.col-slug {
    width: 180px;
}
.category-cell.sb-cat {
    margin-left: 40px;
    position: relative;
}
th.sortable.col-category {
    width: 220px;
}

.parent-select{
border:1px solid #E5E7EB;
border-radius:8px;
padding:6px 10px;
font-size:13px;
background:#fff;
cursor:pointer;
min-width:140px;
}

.cards, .table-box, .filter-bar {
  margin-left: auto;
  margin-right: auto;
  border-radius: 10px;
}

.expand-checkbox{
  position: relative;
}

.expand-icon {
  position: absolute;
  left: 0px;
  top: 27px;
}

.table-box table {
    width: 100% !important;
    table-layout: auto;
    border-radius: 16px;
    overflow: hidden;
}

/* ANIMATION */
@keyframes fadeIn {
  from {
    opacity: 0;
    transform: scale(.97);
  }
  to {
    opacity: 1;
    transform: scale(1);
  }
}

@media only screen and (max-width:767px) {
 
.embed-body {
    flex-direction: column !important;
}
.embed-left, .embed-right {
    width: 100% !important;
}
}

@media only screen and (max-width:1200px) {
 
.category-embed-modal {
    overflow: scroll;
}
.embed-box {
    top: 90px;
    position: relative;
}
}
</style>
