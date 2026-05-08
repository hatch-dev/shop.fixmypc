<template>
  <data-page
    ref="dataPage"
    set-api="setCategory"
    get-api="getCategory"
    route-name="categories"
    :name="$t('category.catUp')"
    :validation-keys="['title', 'slug']"
    :result="result"
    @result="resultData"
  >
    <template v-slot:form="{hasError}">
      <div class="dashboard">
         <div class="container_dash">
            <div class="left">
               <!-- Basic Information -->
               <div class="card">
                  <div class="card-header">
                     <div class="icon">
                        <i class="fa-solid fa-circle-info"></i>
                     </div>
                     <div>
                        <h3>Basic Information</h3>
                        <p>Essential details about your category</p>
                     </div>
                  </div>
                  <!-- Category Name -->
                  <label>Category Name <span>*</span></label>
                  <div class="input-wrap">
                     <input type="text" v-model="result.title" @input="titleChanged"  maxlength="60" :class="{ invalid: !result.title && hasError }" placeholder="Electronics">
                  </div>
                  <div class="field-row">
                     <p>This will be displayed to customers on your store</p>
                     <span :class="{ 'text-danger': titleCount > 60 }">
                        {{ titleCount }}/60
                     </span>
                  </div>
                  <!-- Slug -->
                  <label>Category Slug <span>*</span></label>
                  <div class="input-wrap">
                     <input type="text" v-model="result.slug" :class="{ invalid: !result.slug && hasError }" placeholder="electronics">
                  </div>
                  <p class="field-success">
                     <i class="fa-solid fa-circle-check"></i>
                     Slug is available
                  </p>
                  <!-- Parent -->
                  <label>Parent Category</label>
                  <dropdown
                    v-if="allCategories"
                    :default-null="true"
                    :selectedKey="`${result.parent}`"
                    :options="allCategories"
                    @clicked="categorySelected"
                  />
                  <p class="field-row">
                     Leave as "None" to create a main category, or select a parent to create a sub-category
                  </p>
                  <!-- Short Description -->
                  <label>Short Description</label>
                  <div class="input-wrap">
                     <textarea class="shrt-des" v-model="result.short_description"  maxlength="60" placeholder="Discover the latest in consumer electronics, from smartphones to smart home devices."></textarea>
                  </div>
                  <div class="field-row">
                     <p>This will be displayed to customers on your store</p>
                    <span :class="{ 'text-danger': shortDescCount > 60 }">
                        {{ shortDescCount }}/60
                    </span>
                  </div>
                  <!-- Full Description -->
                  <label>Full Description</label>
                  <div class="input-wrap">
                     <textarea class="shrt-des" v-model="result.description"  maxlength="500" placeholder="Discover the latest in consumer electronics, from smartphones to smart home devices."></textarea>
                  </div>
                  <div class="field-row">
                     <p>This will be displayed to customers on your store</p>
                    <span :class="{ 'text-danger': descCount > 500 }">
                        {{ descCount }}/500
                    </span>
                  </div>
                  <p class="field-row">
                     Detailed information displayed on the category page
                  </p>
               </div>
               <!-- Media -->
               <div class="card mda">
                  <div class="card-header">
                     <div class="icon">
                        <i class="fa-solid fa-image"></i>
                     </div>
                     <div>
                        <h3>Media & Images</h3>
                        <p>Upload images that represent your category</p>
                     </div>
                  </div>
                  <!-- CATEGORY ICON -->
                  <label>Category Icon/Thumbnail</label>
                  <div class="icon-row">
                     <div class="upload-box" @click="$refs.iconInput.click()">
                        <input type="file" ref="iconInput" @change="handleIconUpload" accept="image/*" hidden>
                        <i class="fa-solid fa-cloud-arrow-up"></i>
                        <p>Upload Icon</p>
                        <span>PNG, JPG (Max 2MB)</span>
                        <span class="size-spn"> Recommended: 256x256px</span>
                     </div>
                     <div class="current-icon">
                        <p class="current-title"> <span> Current Icon</span>
                           <i class="fa-solid fa-trash delete-icon" @click="removeIcon"></i>
                        </p>
                        <img v-if="iconPreview" :src="iconPreview" />
                        <span v-else>No icon uploaded</span>
                     </div>
                  </div>
                  <!-- CATEGORY BANNER -->
                  <label>Category Banner Image</label>
                  <div class="banner-upload"  @click="$refs.bannerInput.click()">
                     <input type="file" ref="bannerInput" @change="handleBannerUpload" accept="image/*" hidden>
                     <div v-if="!bannerPreview" class="banner-placeholder">
                        <i class="fa-solid fa-cloud-arrow-up"></i>
                        <p>Upload Banner</p>
                        <span>JPG, PNG (max. 5MB)</span>
                        <span class="sze-upl">Recommended size 1200x400</span>
                     </div>
                     <img v-else :src="bannerPreview" />
                  </div>
                  <!-- ADDITIONAL IMAGES -->
                  <label>Additional Images</label>
                  <div class="additional-upload">
                     <input type="file" ref="galleryInput" multiple accept="image/*" @change="handleGalleryUpload($event)" hidden>
                     <div class="gallery-upload" @click="$refs.galleryInput.click()">
                        <i class="fa-solid fa-plus"></i>
                        <p>Add Images</p>
                     </div>
                     <div class="gallery-grid">
                        <div v-for="(img, index) in gallery" :key="index" class="gallery-item">
                           <img :src="img.preview" />
                           <div class="remove-img" @click="removeGalleryImage(index)">×</div>
                        </div>
                     </div>
                  </div>
               </div>
               <!-- SEO -->
               <div class="card">
                  <div class="card-header">
                     <div class="icon green">
                        <i class="fa-solid fa-magnifying-glass"></i>
                     </div>
                     <div>
                        <h3>SEO & Meta Tags</h3>
                        <p>Optimize your category for search engines</p>
                     </div>
                  </div>
                  <!-- META TITLE -->
                  <label>Meta Title</label>
                  <input type="text" id="metaTitle" v-model="result.meta_title"  maxlength="60" placeholder="Electronics - Latest Tech & Gadgets | YourStore">
                  <div class="field-row">
                    <p>Recommended: 50–60 characters for best display</p>
                    <span :class="{ 'text-danger': metaTitleCount > 60 }">
                        {{ metaTitleCount }}/60
                    </span>
                  </div>
                  <!-- META DESCRIPTION -->
                  <label>Meta Description</label>
                  <textarea rows="3" id="metaDesc" v-model="result.meta_description" maxlength="500" placeholder="Shop the latest electronics including smartphones, laptops, audio devices, and smart home technology. Free shipping on orders over $50."></textarea>
                  <div class="field-row">
                     <p>Recommended: 300-500 characters</p>
                     <span :class="{ 'text-danger': metaDescCount > 500 }">
                        {{ metaDescCount }}/500
                    </span>
                  </div>
                  <!-- FOCUS KEYWORDS -->
                  <label>Focus Keywords</label>
                  <div class="keyword-box">
                    <div 
                        v-for="(tag, index) in keywords" 
                        :key="index" 
                        class="keyword-tag"
                    >
                        {{ tag }}
                        <i @click="removeKeyword(index)">×</i>
                    </div>
                    <input 
                        type="text"
                        v-model="keywordInput"
                        @keydown.enter.prevent="addKeyword"
                        placeholder="Type and press Enter"
                    >
                  </div>
                  <p class="field-row">
                     Add relevant keywords, press Enter after each
                  </p>
                  <!-- CANONICAL URL -->
                  <label>Canonical URL</label>
                  <div class="canonical-field">
                     <input type="url" v-model="result.canonical_url"
                        placeholder="https://yourstore.com/category/electronics">
                     <i class="fa-regular fa-copy copy-btn" @click="copyCanonical"></i>
                  </div>
                  <p class="field-row">
                     Preferred URL for search engines (auto-generated)
                  </p>
                  <div class="seo-score-box">
                     <div class="seo-score-header">
                        <div class="seo-score-icon">
                           <i class="fa-solid fa-chart-line"></i>
                        </div>
                        <div class="seo-score-title">
                           <h4>SEO Score: 85/100</h4>
                           <div class="seo-score-checklist">
                              <div class="seo-item good">
                                 <i class="fa-solid fa-circle-check"></i>
                                 Meta title is optimized
                              </div>
                              <div class="seo-item good">
                                 <i class="fa-solid fa-circle-check"></i>
                                 Meta description is good length
                              </div>
                              <div class="seo-item warning">
                                 <i class="fa-solid fa-circle-exclamation"></i>
                                 Add more focus keywords for better ranking
                              </div>
                           </div>
                        </div>
                     </div>
                  </div>
               </div>
               <div class="card advanced-settings">
                  <div class="card-header">
                     <div class="icon orange">
                        <i class="fa-solid fa-sliders"></i>
                     </div>
                     <div>
                        <h3>Advanced Settings</h3>
                        <p>Additional configuration options</p>
                     </div>
                  </div>
                  <!-- Display Order + Products per Page -->
                  <div class="advanced-grid">
                     <div class="field-group">
                        <label>Display Order</label>
                        <input type="number" v-model="result.display_order">
                        <p class="field-row">Lower numbers appear first</p>
                     </div>
                     <div class="field-group">
                        <label>Products per Page</label>
                        <select v-model="result.products_per_page">
                            <option 
                            v-for="item in productOptions" 
                            :key="item" 
                            :value="item"
                            >
                            {{ item }} products
                            </option>
                        </select>
                    </div>
                  </div>
                  <!-- Category Features -->
                  <label class="feature-label">Category Features</label>
                  <div class="feature-box" @click="toggleFeature('show_in_nav')">
                     <div class="feature-left">
                        <i class="fa-regular fa-eye"></i>
                        <div>
                           <h4>Show in Main Navigation</h4>
                           <p>Display this category in main navigation</p>
                        </div>
                     </div>
                     <input 
                        type="checkbox"
                        class="toggle"
                        v-model="result.show_in_nav"
                        @click.stop
                    >
                  </div>
                  <div class="feature-box" @click="toggleFeature('in_footer')">
                     <div class="feature-left">
                        <i class="fa-regular fa-eye"></i>
                        <div>
                           <h4>Show in Website Footer</h4>
                           <p>Display this category in footer</p>
                        </div>
                     </div>
                     <input 
                        type="checkbox"
                        class="toggle"
                        v-model="result.in_footer"
                        @click.stop
                     >
                  </div>
                  <div class="feature-box" @click="toggleFeature('show_homepage')">
                     <div class="feature-left">
                        <i class="fa-solid fa-house"></i>
                        <div>
                           <h4>Show on Homepage</h4>
                           <p>Feature this category on the homepage</p>
                        </div>
                     </div>
                     <input 
                        type="checkbox"
                        class="toggle"
                        v-model="result.show_homepage"
                        @click.stop
                    >
                  </div>
                  <div class="feature-box" @click="toggleFeature('enable_filters')">
                     <div class="feature-left">
                        <i class="fa-solid fa-filter"></i>
                        <div>
                           <h4>Enable Filters</h4>
                           <p>Allow product filtering on category page</p>
                        </div>
                     </div>
                     <input 
                        type="checkbox"
                        class="toggle"
                        v-model="result.enable_filters"
                        @click.stop
                    >
                  </div>
                  <div class="feature-box" @click="toggleFeature('allow_promotions')">
                     <div class="feature-left">
                        <i class="fa-solid fa-tag"></i>
                        <div>
                           <h4>Allow Promotions</h4>
                           <p>Enable promotional campaigns for this category</p>
                        </div>
                     </div>
                     <input 
                        type="checkbox"
                        class="toggle"
                        v-model="result.allow_promotions"
                        @click.stop
                    >
                  </div>
               </div>
            </div>
            <div class="right">
               <!-- Status & Visibility -->
               <div class="card">
                  <div class="card-header stts-vis">
                     <div class="icon">
                        <i class="fa-solid fa-eye"></i>
                     </div>
                     <div>
                        <h3>Status & Visibility</h3>
                     </div>
                  </div>
                  <label>Publication Status</label>
                  <label class="radio-row">
                  <input type="radio" :value="1" v-model="result.status">
                  <span class="status-category-dot green"></span>
                  Published
                  </label>
                  <label class="radio-row">
                  <input type="radio" :value="3" v-model="result.status">
                  <span class="status-category-dot orange"></span>
                  Draft
                  </label>
                  <label class="radio-row">
                  <input type="radio" :value="2" v-model="result.status">
                  <span class="status-category-dot red"></span>
                  Inactive
                  </label>
                  <div class="line-col visiblity-cl">
                     <label>Visibility</label>
                     <label class="check-row">
                     <input 
                        type="checkbox"
                        :true-value="1"
                        :false-value="2"
                        v-model="result.in_frontend"
                        @click.stop
                        > Visible to public
                     </label>
                     <label class="check-row"  @click="toggleFeature('searchable')">
                     <input 
                        type="checkbox"
                        v-model="result.searchable"
                        @click.stop
                        > Searchable
                     </label>
                     <label class="check-row">
                     <input 
                        type="checkbox"
                        :true-value="1"
                        :false-value="2"
                        v-model="result.featured"
                        @click.stop
                        > Featured category
                     </label>
                  </div>
                  <div class="line-col sch-cl">
                     <label>Schedule Publishing</label>
                     <input 
                            type="datetime-local"
                            v-model="result.publish_at"
                        >
                     <p class="field-row">Leave empty to publish immediately</p>
                  </div>
               </div>
               <!-- Quick Stats -->
               <div class="stats-card">
                  <h3><i class="fa-solid fa-chart-simple"></i> Quick Stats</h3>
                  <div class="stat">
                     <span>Total Products</span>
                     <b>{{ result.total_products || 0 }}</b>
                  </div>
                  <div class="stat">
                     <span>Sub-Categories</span>
                     <b>{{ result.sub_categories || 0 }}</b>
                  </div>
                  <div class="stat">
                     <span>Views (30d)</span>
                     <b>{{ result.views || '-' }}</b>
                  </div>
               </div>
               <!-- Pro Tips -->
               <div class="pro-tips-card">
                  <h3><i class="fa-solid fa-lightbulb"></i> Pro Tips</h3>
                  <ul>
                     <li> <i class="fa-solid fa-check"></i> Use descriptive names that customers will search for</li>
                     <li> <i class="fa-solid fa-check"></i> Add high-quality images to improve engagement</li>
                     <li> <i class="fa-solid fa-check"></i> Optimize meta tags for better SEO ranking</li>
                     <li> <i class="fa-solid fa-check"></i> Organize with sub-categories for easier navigation</li>
                  </ul>
               </div>
               <!-- Category Insights -->
               <div class="card insights-card">
                  <div class="card-header">
                     <i class="fa-solid fa-chart-simple"></i>
                     <h3>Category Insights</h3>
                  </div>
                  <div class="stat">
                     <span>Total Views</span>
                     <b>45,892</b>
                  </div>
                  <div class="stat">
                     <span>Avg. Time on Page</span>
                     <b>2m 34s</b>
                  </div>
                  <div class="stat">
                     <span>Conversion Rate</span>
                     <b class="green">4.2%</b>
                  </div>
                  <div class="stat">
                     <span>Revenue (30d)</span>
                     <b>$128,450</b>
                  </div>
                  <button type="button" class="btn light full">View Full Analytics</button>
               </div>
               <!-- Activity Log -->
               <div class="card activity-card">
                  <div class="card-header">
                     <i class="fa-solid fa-clock-rotate-left"></i>
                     <h3>Activity Log</h3>
                  </div>
                  <div class="activity-item">
                     <span class="activity-icon blue"> <i class="fa-solid fa-pencil"></i> </span>
                     <div>
                        <b>Category updated</b>
                        <p>2 hours ago by Adriano Darwin</p>
                     </div>
                  </div>
                  <div class="activity-item">
                     <span class="activity-icon green"> <i class="fa-solid fa-plus"></i> </span>
                     <div>
                        <b>Sub-category added</b>
                        <p>Yesterday by Sarah Johnson</p>
                     </div>
                  </div>
                  <div class="activity-item">
                     <span class="activity-icon purple"><i class="fa-solid fa-image"></i></span>
                     <div>
                        <b>Icon updated</b>
                        <p>3 days ago by Adriano Darwin</p>
                     </div>
                  </div>
                  <button type="button" class="btn light full">View All Activity</button>
               </div>
               <!-- Danger Zone -->
               <div class="danger-card">
                  <h3><i class="fa-solid fa-triangle-exclamation"></i> Danger Zone</h3>
                  <p>
                     Deleting this category will remove all sub-categories and unassign 2,450 products. This action cannot be undone.
                  </p>
                  <button type="button" class="delete-btn" @click="executeDelete">
                  <i class="fa-solid fa-trash"></i> Delete Category
                  </button>
               </div>
            </div>
         </div>
      </div>
    </template>
  </data-page>
</template>

<script>

  import DataPage from "~/components/partials/DataPage";
  import util from "~/mixin/util"
  import Dropdown from '~/components/Dropdown'
  import {mapGetters, mapActions } from 'vuex'

  export default {
    name: "categories",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        productOptions: [12, 24, 36, 48],
        iconPreview: null,
        bannerPreview: null,
        gallery: [],
        keywords: [],
        keywordInput: '',
        result: {
          id: '',
          title: '',
          status: 2,
          featured: 2,
          parent: '',
          slug: '',
          meta_description: '',
          meta_keywords: '',
          in_footer: false,
          in_frontend: 2, // Added this field
          meta_title: '',
          image: '',
          canonical_url : '',
          description: '',
          short_description: '',
          visible: true,
          searchable: true,
          featured_category: false,
          display_order: 1,
          products_per_page: 12,
          show_in_nav: false,
          show_homepage: false,
          enable_filters: false,
          allow_promotions: false,
          publish_at: ''
        }
      }
    },
    mixins: [util],
    components: {
      DataPage,
      Dropdown
    },
    computed: {
        canonicalUrl() {
            return this.result.canonical_url || `https://yourstore.com/category/${this.result.slug}`
        },
        titleCount() {
            return this.result.title?.length || 0
        },
        metaTitleCount() {
            return this.result.meta_title?.length || 0
        },
        metaDescCount() {
            return this.result.meta_description?.length || 0
        },
        shortDescCount() {
            return this.result.short_description?.length || 0
        },
        descCount() {
            return this.result.description?.length || 0
        },
      ...mapGetters('language', ['currentLanguage']),
      ...mapGetters('common', ['allCategories'])
    },
    methods: {
        async executeDelete(){
            const confirmed = window.confirm("Are you sure you want to delete this category?");

            if (!confirmed) return;

            try{
                const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'
                await this.$axios.delete(
                    `${baseUrl}api/admin/category/delete/${this.result.id}`
                )
                this.$router.push('/categories')
            }catch(e){
                console.error(e)
            }
        },
        toggleFeature(key) {
            this.result[key] = !this.result[key]
        },
        copyCanonical() {
            if (!this.canonicalUrl) return
            navigator.clipboard.writeText(this.canonicalUrl)
        },
        handleGalleryUpload(e) {
            const files = Array.from(e.target.files)

            files.forEach(file => {
                const reader = new FileReader()

                reader.onload = (event) => {
                this.gallery.push({
                    preview: event.target.result
                })

                // ✅ store only base64 array
                this.result.gallery = this.gallery.map(i => i.preview)
                }

                reader.readAsDataURL(file)
            })

            e.target.value = ''
        },
        removeGalleryImage(index) {
            this.gallery.splice(index, 1)
            this.result.gallery = this.gallery.map(i => i.preview)
        },
        removeIcon() {
            if (this.iconPreview) {
                URL.revokeObjectURL(this.iconPreview)
            }
            this.iconPreview = null
            this.result.icon = null
        },
        addKeyword() {
            const value = this.keywordInput.trim()
            if (!value) return
            if (this.keywords.includes(value)) {
            this.keywordInput = ''
            return
            }
            this.keywords.push(value)
            this.result.meta_keywords = this.keywords.join(',')
            this.keywordInput = ''
        },
        removeKeyword(index) {
            this.keywords.splice(index, 1)
            this.result.meta_keywords = this.keywords.join(',')
        },
      resultData(evt){
        let url = 'https://shop.fixmypc.ie/uploads/';
        if(this.$route?.params?.id === 'add'){
          this.emptyAllList('allCategories')
        }

        this.result = {
            ...this.result,
            ...evt 
        }

        if (evt.image) {
            this.iconPreview =  ((evt.image).startsWith('http')) ? evt.image : url + evt.image
            this.result.icon = ((evt.image).startsWith('http')) ? evt.image : url + evt.image
        }

        if (evt.banner) {
            this.bannerPreview =  ((evt.banner).startsWith('http')) ? evt.banner : url + evt.banner
            this.result.banner =  ((evt.banner).startsWith('http')) ? evt.banner : url + evt.banner
        }

        if (evt.gallery) {
            const images = evt.gallery
                .split(',')
                .map(i => i.trim())
                .filter(i => i) // remove empty values

            this.gallery = images.map(img => ({
                preview: img.startsWith('http')
                ? img
                : `${url.replace(/\/$/, '')}/${img.replace(/^\//, '')}`
            }))

            this.result.gallery = images
            } else {
            this.gallery = []
            this.result.gallery = []
            }

        if (this.result.meta_keywords) {
            this.keywords = this.result.meta_keywords.split(',').map(k => k.trim())
        }
      },
      inFooterSelected(data) {
        this.result.in_footer = data.key
      },
      featuredSelected(data) {
        this.result.featured = data.key
      },
      categorySelected(data) {
        this.result.parent = data.key
      },
      // Added this method for frontend visibility
      inFrontendSelected(data) {
        this.result.in_frontend = data.key
      },
      titleChanged(){
        this.result.slug = this.convertToSlug(this.result.title)
      },
      dropdownSelected(data) {
        this.result.status = data.key
      },
    handleIconUpload(e) {
        const file = e.target.files[0]
        if (!file) return

        const reader = new FileReader()

        reader.onload = (event) => {
            this.iconPreview = event.target.result
            this.result.icon = event.target.result
        }

        reader.readAsDataURL(file)
    },

    handleBannerUpload(e) {
        const file = e.target.files[0]
        if (!file) return

        const reader = new FileReader()

        reader.onload = (event) => {
            this.bannerPreview = event.target.result

            // ✅ base64
            this.result.banner = event.target.result
        }

        reader.readAsDataURL(file)
    },
    ...mapActions('common', ['getAllList', 'emptyAllList'])
    },
    async mounted() {
      if (!this.allCategories) {
        try {
          await this.getAllList({api: 'getAllCategories', mutation: 'SET_ALL_CATEGORIES'})
        } catch (e) {
          return this.$nuxt.error(e)
        }
      }
    }
  }
</script>

<style scoped>
.dashboard .container_dash {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 20px;
    margin: auto;
}
.dashboard {
    padding: 20px;
}
.card {
    background: #fff;
    padding: 30px;
    border-radius: 32px;
    margin-bottom: 20px;
    box-shadow: 0px 2px 8px 0px #0000000A;
    box-shadow: 0px 0px 0px 1px #00000008;
}
.card:last-child {
    margin-bottom: 0;
}
.card-header {
    display: flex;
    gap: 15px;
    align-items: center;
    margin-bottom: 30px;
}
.card-header h3 {
    font-size: 20px;
    font-weight: 700;
    color: #111827;
    line-height: 1.2em;
    margin-bottom: 3px;
}
.card-header p {
    font-size: 14px;
    color: #6B7280;
    font-weight: 400;
    line-height: 20px;
}
.card-header .icon {
    width: 40px;
    height: 40px;
    border-radius: 16px;
    background: #EFF6FF;
    display: flex;
    align-items: center;
    justify-content: center;
}
.card-header .icon i {
    color: #2563EB;
}
.card.mda .card-header .icon {
    background: #FAF5FF;
}
.card.mda .card-header .icon i {
    color: #9333EA;
}
.icon.purple{
    background:#ede9fe;
}
.icon.green{
    background:#F0FDF4
}
.icon.green i {
    color: #16A34A;
}


/* inputs */

.card label {
    display: block;
    font-size: 14px;
    margin-top: 30px;
    font-weight: 700;
    color: #111827;
}
.card label span {
    color: #EF4444;
}
.card input, .card textarea, .card select {
    width: 100%;
    padding: 16px 18px;
    margin-top: 8px;
    border-radius: 16px;
    border: 2px solid #E5E7EB;
    font-size: 14px;
    font-weight: 500;
    color: #1F2937;
    outline: 0 !important;
}
.card textarea.shrt-des {
    min-height: 92px;
}

/* upload */

.banner-box{
border:2px dashed #d1d5db;
padding:30px;
text-align:center;
border-radius:10px;
margin-top:15px;
}
.stats-card {
    background: linear-gradient(135deg, #4f7cff, #2b5cff);
    color: #fff;
    padding: 30px;
    border-radius: 32px;
    margin-bottom: 20px;
}

.stat{
display:flex;
justify-content:space-between;
margin-top:10px;
}


/* danger */
.danger-card {
    padding: 30px;
    border-radius: 32px;
    background: linear-gradient(135deg, #EF4444 0%, #DC2626 100%);
    color: #fff;
    box-shadow: 0px 2px 8px 0px #0000000A;
    box-shadow: 0px 0px 0px 1px #00000008;
}
.danger-card h3 {
    font-weight: 700;
    font-size: 18px;
    margin-bottom: 14px;
}
.danger-card p {
    color: #FEE2E2;
    font-size: 14px;
    line-height: 20px;
}
.delete-btn {
    width: 100%;
    background: #FFFFFF;
    border: none;
    border-radius: 16px;
    margin-top: 16px;
    cursor: pointer;
    height: 44px;
    color: #DC2626;
    font-weight: 700;
    transition: all 0.3s ease;
}



/* field helper text */

.field-success {
    font-size: 12px;
    color: #16A34A;
    margin-top: 6px;
    display: flex;
    align-items: center;
    gap: 5px;
    font-weight: 500;
}

/* slug field */
.slug-field{
display:flex;
align-items:center;
border:2px solid #E5E7EB;
border-radius:16px;
overflow:hidden;
margin-top:8px;
}
.slug-prefix{
padding:14px 14px;
background:#F3F4F6;
font-size:14px;
color:#6B7280;
}
.slug-field input{
border:none;
flex:1;
padding:14px;
}

/* editor */
.editor {
    border: 2px solid #E5E7EB;
    border-radius: 16px;
    margin-top: 8px;
    overflow: hidden;
}
.editor-toolbar {
    display: flex;
    gap: 6px;
    padding: 12px 18px;
    border-bottom: 1px solid #E5E7EB;
    background: #F9FAFB;
}
.editor-toolbar button {
    width: 26px;
    height: 26px;
    cursor: pointer;
    background: transparent;
    border: none;
    transition: all 0.3s;
    color: #4B5563;
}
.editor-toolbar button:hover {
    color: #000;
}
.editor .editor-content {
    font-weight: 400;
    font-size: 14px;
    line-height: 20px;
    padding: 16px;
    outline: 0 !IMPORTANT;
}
.editor-toolbar .divider {
    width: 1px;
    background: #E5E7EB;
    margin: 0 4px;
}
.field-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 12px;
    color: #6B7280;
    margin-top: 6px;
    font-weight: 400;
}
.field-row span {
    color: #9CA3AF;
    font-weight: 500;
}



/* icon upload */

.icon-row {
    display: flex;
    gap: 15px;
    margin-top: 15px;
}
.upload-box {
    width: 50%;
    height: 330px;
    border: 2px dashed #D1D5DB;
    border-radius: 24px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    cursor: pointer;
    text-align: center;
    background: #F9FAFB;
}
.upload-box i {
    color: #9CA3AF;
    background: #fff;
    box-shadow: 0px 1px 2px 0px #0000000D;
    border: 1px solid #E5E7EB;
    border-radius: 24px;
    width: 80px;
    height: 80px;
    font-size: 35px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 20px;
}
.upload-box p {
    font-weight: 600;
    font-size: 14px;
    margin-bottom: 8px;
}
.upload-box span {
    font-size: 12px;
    color: #6B7280;
    font-weight: 400;
}
.upload-box span.size-spn {
    color: #9CA3AF;
    margin-top: 5px;
}

.current-icon {
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    border: 2px solid #E5E7EB;
    border-radius: 24px;
    width: 50%;
    height: 330px;
    font-size: 13px;
    color: #6B7280;
    background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%);
    padding: 20px;
}
.current-icon p {
    color: #111827;
    font-weight: 700;
    font-size: 14px;
    width: 100%;
    text-align: center;
}
.current-icon p i {
    display: none;
}
.current-icon.has-image i#deleteIcon {
    display: block;
    background: #fff;
    box-shadow: 0px 1px 2px 0px #0000000D;
    height: 28px;
    width: 28px;
    border-radius: 50px;
    font-size: 12px;
    transition: all 0.3s;
    padding: 8px;
    cursor: pointer;
    color: #9CA3AF;
}
.current-icon.has-image i#deleteIcon:hover {
    background: #2563EB;
    border-color: #2563EB;
    color: #fff;
}
.current-icon span#noIconText {
    font-size: 12px;
    color: #6B7280;
    font-weight: 400;
    margin-top: 5px;
}
.current-icon img {
    object-fit: contain;
    width: 100%;
    background: #fff;
    border-radius: 16px;
    padding: 20px;
    margin-top: 15px;
    height: 100%;
}
.current-icon.has-image p.current-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.current-icon.has-image p.current-title span {
    color: #1E3A8A;
    font-weight: 700;
    font-size: 12px;
    box-shadow: 0px 1px 2px 0px #0000000D;
    background: #fff;
    border-radius: 50px;
    height: 28px;
    padding: 7px 10px;
}

/* banner */

.banner-upload {
    margin-top: 10px;
    border: 2px dashed #D1D5DB;
    border-radius: 24px;
    height: 200px;
    display: flex;
    justify-content: center;
    align-items: center;
    cursor: pointer;
    position: relative;
    overflow: hidden;
    background: #F9FAFB;
    min-height: 300px;
}
.banner-placeholder i {
    color: #9CA3AF;
    background: #fff;
    box-shadow: 0px 1px 2px 0px #0000000D;
    border: 1px solid #E5E7EB;
    border-radius: 24px;
    width: 96px;
    height: 96px;
    font-size: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 15px auto;
}
.banner-placeholder p {
    font-weight: 600;
    font-size: 14px;
    margin-bottom: 8px;
    text-align: center;
}
.banner-placeholder span {
    width: 100%;
    display: block;
    text-align: center;
    font-size: 12px;
    color: #6B7280;
    font-weight: 400;
}
.banner-placeholder span.sze-upl {
    color: #9CA3AF;
    margin-top: 4px;
}
.banner-upload img {
    width: 100%;
    object-fit: cover;
    max-width: 380px;
    max-height: 250px;
    border-radius: 15px;
}

/* additional images */

.additional-upload{
margin-top:10px;
}
.gallery-upload {
    width: 100%;
    height: 154px;
    max-width: 154px;
    max-height: 154px;
    border: 2px dashed #D1D5DB;
    border-radius: 16px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    cursor: pointer;
    background: #F9FAFB;
}
.gallery-upload p {
    font-weight: 500;
    color: #6B7280;
    font-size: 12px;
}
.gallery-upload i {
    font-size: 17px;
    margin-bottom: 6px;
    color: #9CA3AF;
}
.gallery-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, 154px);
    gap: 12px;
    margin-top: 12px;
}
.gallery-item {
    position: relative;
    width: 154px;
    height: 154px;
    border-radius: 12px;
    overflow: hidden;
}
.gallery-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.remove-img {
    position: absolute;
    top: 6px;
    right: 6px;
    background: #fff;
    border-radius: 50%;
    width: 22px;
    height: 22px;
    font-size: 15px;
    cursor: pointer;
    box-shadow: 0 0 10px 0 #000;
    display: flex;
    align-items: center;
    line-height: 0;
    justify-content: center;
    transition: all 0.3s ease;
}


/* keyword tags */
.field-row span#metaTitleCount, .field-row span#metaDescCount {
    color: #16A34A;
}
.keyword-box {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    border: 2px solid #E5E7EB;
    border-radius: 16px;
    padding: 10px;
    margin-top: 8px;
}
.keyword-box input {
    border: none;
    flex: 1;
    min-width: 120px;
    font-size: 14px;
    padding: 8px;
    outline: none;
    margin: 0;
}
.keyword-tag {
    background: #111827;
    color: #fff;
    padding: 6px 12px;
    border-radius: 20px;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
}
.keyword-tag i {
    cursor: pointer;
    font-size: 12px;
}


/* canonical */

.canonical-field {
    display: flex;
    align-items: center;
    border: 2px solid #E5E7EB;
    border-radius: 16px;
    overflow: hidden;
    margin-top: 8px;
}
.canonical-field input {
    border: none;
    flex: 1;
    padding: 16px;
    font-size: 14px;
    margin: 0;
}
.copy-btn {
    padding: 0 16px;
    cursor: pointer;
    color: #6B7280;
    transition: all 0.3es ease;
}
.copy-btn:hover{
    color:#111827;
}
.copy-toast {
    position: fixed;
    bottom: 25px;
    right: 25px;
    background: #111827;
    color: #fff;
    padding: 12px 18px;
    border-radius: 10px;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
    opacity: 0;
    transform: translateY(20px);
    transition: all .3s ease;
    z-index: 9999;
    pointer-events: none;
}
.copy-toast.show{
    opacity:1;
    transform:translateY(0);
}



/* SEO score */
.seo-score-box {
    margin-top: 25px;
    padding: 20px;
    border-radius: 24px;
    background: #EFF6FF;
    border: 1px solid #DBEAFE;
}
.seo-score-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
}
.seo-score-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: #2563EB;
    display: flex;
    align-items: center;
    justify-content: center;
}
.seo-score-icon i {
    color: #ffffff;
    font-size: 13px;
}
.seo-score-title {
    flex: 1;
    margin-left: 12px;
}
.seo-score-title h4 {
    font-size: 14px;
    font-weight: 700;
    color: #1E3A8A;
}

.seo-score-checklist {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-top: 10px;
}
.seo-item {
    font-size: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
    color: #1D4ED8;
    font-weight: 400;
}
.seo-item.good i {
    color: #16A34A;
}
.seo-item.warning i {
    color: #F59E0B;
}


/* Advanced settings */

.advanced-settings .icon.orange {
    background: #FFF7ED;
}
.advanced-settings .icon.orange i {
    color: #EA580C;
}


/* grid for top inputs */

.advanced-grid{
display:grid;
grid-template-columns:1fr 1fr;
gap:20px;
margin-bottom:25px;
}

.field-group label{
margin-top:0;
}


/* category features label */

.feature-label{
margin-top:10px;
margin-bottom:10px;
display:block;
}


/* feature rows */

.feature-box{
display:flex;
justify-content:space-between;
align-items:center;
border:2px solid #E5E7EB;
border-radius:16px;
padding:18px;
margin-top:12px;
background:#fff;
transition:all .2s ease;
}

.feature-box:hover{
border-color:#d1d5db;
}


/* left content */

.feature-left {
    display: flex;
    gap: 14px;
    align-items: center;
}
.feature-left i {
    font-size: 16px;
    color: #9CA3AF;
    width: 18px;
}
.feature-left h4 {
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 3px;
    color: #111827;
}
.feature-left p {
    font-size: 12px;
    color: #6B7280;
    font-weight: 400;
}
.toggle {
    width: 18px !important;
    height: 18px !important;
    accent-color: #2563EB;
    cursor: pointer;
    margin: 0 !important;
}
.card-header.stts-vis .icon {
    background: #F3F4F6;
}
.card-header.stts-vis .icon i {
    color: #4B5563;
}
.radio-row {
    display: flex !important;
    align-items: center !important;
    gap: 6px;
    margin-top: 10px !important;
    border: 2px solid #E5E7EB;
    border-radius: 16px;
    padding: 16px 18px;
}
.status-category-dot {
    width: 12px;
    height: 12px;
    max-width: 12px;
    border-radius: 50%;
    display: inline-block;
    position: none;
}
.radio-row input {
    width: 16px;
    margin-top: 1px;
    margin-right: 5px;
    height: 16px;
}
.status-category-dot.green {
    background: #22C55E;
}
.status-category-dot.orange {
    background: #F97316;
}
.status-category-dot.red {
    background: #EF4444;
}
.line-col {
    padding-top: 25px;
    border-top: 1px solid #F3F4F6;
    margin-top: 25px;
}
.line-col label {
    margin-top: 0;
}
.check-row {
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    margin-top: 10px !important;
}
.line-col input {
    width: 16px;
    margin-top: 1px;
    margin-right: 5px;
    height: 16px;
}
.line-col.visiblity-cl label.check-row {
    font-weight: 400;
    color: #374151;
    margin-bottom: 10px ! IMPORTANT;
}
.line-col.visiblity-cl label.check-row:last-child {
    margin-bottom: 0px ! IMPORTANT;
}
.line-col.sch-cl label {
    margin-bottom: 8px;
}
.line-col.sch-cl input {
    width: 100%;
    height: 46px !important;
    color: #1F2937;
    padding: 5px 16px;
}
.stats-card i {
    width: 40px;
    height: 40px;
    border-radius: 16px;
    background: #ffffff30;
    display: flex;
    align-items: center;
    justify-content: center;
}
.stats-card h3 {
    display: flex;
    gap: 15px;
    align-items: center;
    margin-bottom: 20px;
}
.stat span {
    color: #DBEAFE;
    font-weight: 400;
    font-size: 14px;
}


.pro-tips-card {
    background: #FFFBEB;
    border: 2px solid #FDE68A;
    padding: 30px;
    border-radius: 32px;
    margin-bottom: 20px;
}
.pro-tips-card h3 {
    font-size: 14px;
    margin-bottom: 10px;
    color: #78350F;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 10px;
}
.pro-tips-card h3 i {
    color: #D97706;
    font-size: 22px;
}
.pro-tips-card ul {
    padding-left: 0;
    font-size: 13px;
    color: #92400E;
    list-style-type: none;
}
.pro-tips-card li {
    margin-bottom: 6px;
    font-weight: 400;
    font-size: 12px;
    line-height: 16px;
    display: inline-flex;
    align-items: flex-start;
    gap: 6px;
}
.pro-tips-card li:last-child {
    margin-bottom: 0px;
}
.pro-tips-card li i {
    position: relative;
    top: 3px;
}
.card.insights-card i, .card.activity-card i {
    color: #9CA3AF;
}
.card.insights-card h3, .card.activity-card h3 {
    margin: 0;
}
.insights-card .stat {
    display: flex;
    justify-content: space-between;
    margin-top: 15px;
}
.insights-card .stat span {
    color: #4B5563;
}
.insights-card .stat b {
    color: #111827;
}
.insights-card .stat b.green {
    color: #16A34A;
}
.card.insights-card .card-header, .card.activity-card .card-header {
    margin-bottom: 20px;
}
.card button.btn.light.full {
    background: #F9FAFB;
    border: none;
    text-align: center;
    width: 100%;
    color: #374151;
    font-weight: 500;
    font-size: 14px;
    height: 40px;
    border-radius: 8px;
    transition: all 0.3s ease;
    margin-top: 20px;
    cursor: pointer;
}
.card button.btn.light.full:hover {
    background: #2563EB;
    color: #fff;;
}
.activity-item {
    display: flex;
    gap: 13px;
    margin-top: 15px;
    align-items: flex-start;
}
.activity-item .activity-icon i {
    background: #DBEAFE;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    color: #2563EB;
}
.activity-item .activity-icon.green i {
    background: #DCFCE7;
    color: #16A34A;
}
.activity-item .activity-icon.purple i {
    background: #F3E8FF;
    color: #9333EA;
}
.activity-item b {
    font-weight: 500;
    font-size: 14px;
    line-height: 20px;
}
.activity-item p {
    font-size: 12px;
    color: #6B7280;
    line-height: 16px;
}
</style>