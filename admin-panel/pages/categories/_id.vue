<template>
  <div>
  <data-page
    ref="dataPage"
    :structured="false"
    top-actions
    set-api="setCategory"
    get-api="getCategory"
    route-name="categories"
    :name="$t('category.catUp')"
    :validation-keys="['title', 'slug', 'meta_title', 'meta_description']"
    :validation-labels="{
      title: $t('pages.categories.valCategoryName'),
      slug: $t('pages.categories.valCategorySlug'),
      meta_title: $t('pages.categories.valMetaTitle'),
      meta_description: $t('pages.categories.valMetaDescription')
    }"
    :result="result"
    @result="resultData"
  >
    <template v-slot:top-title>
      <div class="cat-head">
        <nuxt-link to="/categories" class="cat-head__crumb">
          <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
          {{ $t('pages.categories.categories') }}
        </nuxt-link>
        <h4 class="cat-head__title">
          {{ isNew ? $t('list.add', {title: $t('category.catUp')}) : $t('category.edit') + ' ' + $t('category.catUp') }}
        </h4>
        <p class="cat-head__sub">
          {{ isNew
            ? $t('pages.categories.subNew')
            : $t('pages.categories.subEdit') }}
        </p>
      </div>
    </template>

    <template v-slot:form="{hasError, fieldErrors}">
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
                        <h3>{{ $t('pages.categories.basicInfo') }}</h3>
                        <p>{{ $t('pages.categories.basicInfoSub') }}</p>
                     </div>
                  </div>
                  <!-- Category Name -->
                  <label for="category-title">{{ $t('pages.categories.categoryName') }} <span>*</span></label>
                  <div class="input-wrap" data-field="title">
                     <input id="category-title" type="text" v-model="result.title" @input="titleChanged"  maxlength="60" :class="{ invalid: fieldErrors.title || (!result.title && hasError) }" :placeholder="$t('pages.categories.namePlaceholder')">
                  </div>
                  <field-error :messages="fieldErrors.title" />
                  <div class="field-row">
                     <p>{{ $t('pages.categories.displayedToCustomers') }}</p>
                     <span :class="{ 'text-danger': titleCount > 60 }">
                        {{ titleCount }}/60
                     </span>
                  </div>
                  <!-- Slug -->
                  <label for="category-slug">{{ $t('pages.categories.categorySlug') }} <span>*</span></label>
                  <div class="input-wrap" data-field="slug">
                     <input
                        id="category-slug"
                        type="text"
                        v-model="result.slug"
                        :class="{ invalid: fieldErrors.slug || ((!result.slug || slugState === 'taken') && hasError) }"
                        :placeholder="$t('pages.categories.slugPlaceholder')"
                        aria-describedby="slug-status"
                        @input="slugEdited"
                     >
                  </div>
                  <!--
                     Not when the availability line below is already making the
                     same point: it says it with the suggested alternative
                     attached, which is more use than a second copy.
                  -->
                  <field-error :messages="slugState === 'taken' ? null : fieldErrors.slug" />
                  <p id="slug-status" class="field-status" :class="'is-' + slugState" aria-live="polite">
                     <template v-if="slugState === 'checking'">
                        <i class="fa fa-spinner fa-spin"></i> {{ $t('pages.categories.checkingAvailability') }}
                     </template>
                     <template v-else-if="slugState === 'available'">
                        <i class="fa-solid fa-circle-check"></i> {{ $t('pages.categories.slugAvailable') }}
                     </template>
                     <template v-else-if="slugState === 'taken'">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        {{ $t('pages.categories.slugTaken') }}
                        <button type="button" class="link-btn" @click="useSuggestedSlug">
                           {{ $t('pages.categories.useSuggestion', { slug: slugSuggestion }) }}
                        </button>
                     </template>
                     <template v-else-if="slugState === 'invalid'">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        {{ $t('pages.categories.slugInvalid') }}
                     </template>
                     <template v-else>
                        {{ $t('pages.categories.slugHelp') }}
                     </template>
                  </p>
                  <!-- Parent -->
                  <label id="parent-label">{{ $t('pages.categories.parentCategory') }}</label>
                  <div v-if="parentsLoading" class="field-skeleton" aria-live="polite">
                     <span class="sk-field"></span>
                  </div>
                  <div v-else-if="parentOptions" data-field="parent">
                    <dropdown
                      :default-null="true"
                      :selectedKey="`${result.parent || 0}`"
                      :options="parentOptions"
                      @clicked="categorySelected"
                    />
                  </div>
                  <field-error :messages="fieldErrors.parent" />
                  <p class="field-row">
                     {{ $t('pages.categories.parentHelp') }}
                  </p>
                  <!-- Short Description -->
                  <label for="category-short-description">{{ $t('pages.categories.shortDescription') }}</label>
                  <div class="input-wrap" data-field="short_description">
                     <textarea id="category-short-description" class="shrt-des" v-model="result.short_description"  maxlength="60" :placeholder="$t('pages.categories.descPlaceholder')"></textarea>
                  </div>
                  <field-error :messages="fieldErrors.short_description" />
                  <div class="field-row">
                     <p>{{ $t('pages.categories.displayedToCustomers') }}</p>
                    <span :class="{ 'text-danger': shortDescCount > 60 }">
                        {{ shortDescCount }}/60
                    </span>
                  </div>
                  <!-- Full Description -->
                  <label for="category-description">{{ $t('pages.categories.fullDescription') }}</label>
                  <div class="input-wrap" data-field="description">
                     <textarea id="category-description" class="shrt-des" v-model="result.description"  maxlength="500" :placeholder="$t('pages.categories.descPlaceholder')"></textarea>
                  </div>
                  <field-error :messages="fieldErrors.description" />
                  <div class="field-row">
                     <p>{{ $t('pages.categories.displayedToCustomers') }}</p>
                    <span :class="{ 'text-danger': descCount > 500 }">
                        {{ descCount }}/500
                    </span>
                  </div>
                  <p class="field-row">
                     {{ $t('pages.categories.fullDescriptionHelp') }}
                  </p>
               </div>
               <!-- Media -->
               <div class="card mda">
                  <div class="card-header">
                     <div class="icon">
                        <i class="fa-solid fa-image"></i>
                     </div>
                     <div>
                        <h3>{{ $t('pages.categories.mediaImages') }}</h3>
                        <p>{{ $t('pages.categories.mediaImagesSub') }}</p>
                     </div>
                  </div>
                  <!-- CATEGORY ICON -->
                  <label>{{ $t('pages.categories.iconThumbnail') }}</label>
                  <field-error :messages="fieldErrors.icon" />
                  <div class="icon-row" data-field="icon">
                     <div class="upload-box" @click="$refs.iconInput.click()">
                        <input type="file" ref="iconInput" @change="handleIconUpload" accept="image/*" hidden>
                        <i class="fa-solid fa-cloud-arrow-up"></i>
                        <p>{{ $t('pages.categories.uploadIcon') }}</p>
                        <span>{{ $t('pages.categories.iconFormats') }}</span>
                        <span class="size-spn"> {{ $t('pages.categories.iconRecommended') }}</span>
                     </div>
                     <div class="current-icon">
                        <p class="current-title">
                           <span>{{ $t('pages.categories.currentIcon') }}</span>
                           <i
                              v-if="iconPreview"
                              class="fa-solid fa-trash delete-icon"
                              role="button"
                              tabindex="0"
                              :title="$t('pages.categories.removeIcon')"
                              :aria-label="$t('pages.categories.removeIcon')"
                              @click="removeIcon"
                              @keydown.enter="removeIcon"
                           ></i>
                        </p>
                        <img
                           v-if="iconPreview"
                           :src="iconPreview"
                           loading="lazy"
                           :alt="result.title ? $t('pages.categories.iconAlt', { name: result.title }) : $t('pages.categories.categoryIcon')"
                        />
                        <span v-else>{{ $t('pages.categories.noIcon') }}</span>
                     </div>
                  </div>
                  <!-- CATEGORY BANNER -->
                  <label>{{ $t('pages.categories.bannerImage') }}</label>
                  <field-error :messages="fieldErrors.banner" />
                  <div class="banner-upload" data-field="banner" @click="$refs.bannerInput.click()">
                     <input type="file" ref="bannerInput" @change="handleBannerUpload" accept="image/*" hidden>
                     <div v-if="!bannerPreview" class="banner-placeholder">
                        <i class="fa-solid fa-cloud-arrow-up"></i>
                        <p>{{ $t('pages.categories.uploadBanner') }}</p>
                        <span>{{ $t('pages.categories.bannerFormats') }}</span>
                        <span class="sze-upl">{{ $t('pages.categories.bannerRecommended') }}</span>
                     </div>
                     <img v-else :src="bannerPreview" :alt="result.title ? $t('pages.categories.bannerAlt', { name: result.title }) : $t('pages.categories.categoryBanner')" />
                     <!--
                        On the image, the way a gallery tile removes itself.
                        It used to be a text link on a row of its own below the
                        banner, which is a third pattern for the one action -
                        the icon has a trash button, the gallery has this.
                     -->
                     <button
                        v-if="bannerPreview"
                        type="button"
                        class="remove-img banner-remove"
                        :title="$t('pages.categories.removeBanner')"
                        :aria-label="$t('pages.categories.removeBanner')"
                        @click.stop="removeBanner"
                     >&times;</button>
                  </div>
                  <!-- ADDITIONAL IMAGES -->
                  <label>{{ $t('pages.categories.additionalImages') }}</label>
                  <!--
                     One grid, with the add tile as its first cell. The tile
                     was a sibling of the grid, so it sat on a row of its own
                     and the thumbnails began underneath it rather than
                     alongside - two rows where the layout wants one.
                  -->
                  <div class="additional-upload">
                     <input type="file" ref="galleryInput" multiple accept="image/*" @change="handleGalleryUpload($event)" hidden>
                     <div class="gallery-grid">
                        <div class="gallery-upload" @click="$refs.galleryInput.click()">
                           <i class="fa-solid fa-plus"></i>
                           <p>{{ $t('pages.categories.addImages') }}</p>
                        </div>
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
                        <h3>{{ $t('pages.categories.seoMeta') }}</h3>
                        <p>{{ $t('pages.categories.seoMetaSub') }}</p>
                     </div>
                  </div>
                  <!-- META TITLE -->
                  <label for="metaTitle">{{ $t('pages.categories.metaTitle') }} <span>*</span></label>
                  <input
                     type="text"
                     id="metaTitle"
                     data-field="meta_title"
                     v-model="result.meta_title"
                     maxlength="60"
                     :class="{ invalid: fieldErrors.meta_title }"
                     :placeholder="$t('pages.categories.metaTitlePlaceholder')"
                  >
                  <field-error :messages="fieldErrors.meta_title" />
                  <div class="field-row">
                    <p>{{ $t('pages.categories.metaTitleHelp') }}</p>
                    <span :class="{ 'text-danger': metaTitleCount > 60 }">
                        {{ metaTitleCount }}/60
                    </span>
                  </div>
                  <!-- META DESCRIPTION -->
                  <label for="metaDesc">{{ $t('pages.categories.metaDescription') }} <span>*</span></label>
                  <textarea
                     rows="3"
                     id="metaDesc"
                     data-field="meta_description"
                     v-model="result.meta_description"
                     maxlength="500"
                     :class="{ invalid: fieldErrors.meta_description }"
                     :placeholder="$t('pages.categories.metaDescPlaceholder')"
                  ></textarea>
                  <field-error :messages="fieldErrors.meta_description" />
                  <div class="field-row">
                     <p>{{ $t('pages.categories.metaDescHelp') }}</p>
                     <span :class="{ 'text-danger': metaDescCount > 500 }">
                        {{ metaDescCount }}/500
                    </span>
                  </div>
                  <!-- FOCUS KEYWORDS -->
                  <label>{{ $t('pages.categories.focusKeywords') }}</label>
                  <field-error :messages="fieldErrors.meta_keywords" />
                  <div class="keyword-box" data-field="meta_keywords" :class="{ 'is-invalid': fieldErrors.meta_keywords }">
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
                        :placeholder="$t('pages.categories.keywordPlaceholder')"
                    >
                  </div>
                  <p class="field-row">
                     {{ $t('pages.categories.keywordHelp') }}
                  </p>
                  <!-- CANONICAL URL -->
                  <label for="category-canonical">{{ $t('pages.categories.canonicalUrl') }}</label>
                  <div class="canonical-field" data-field="canonical_url">
                     <input id="category-canonical" type="url" v-model="result.canonical_url"
                        :class="{ invalid: fieldErrors.canonical_url }"
                        :placeholder="canonicalUrl">
                     <i class="fa-regular fa-copy copy-btn" @click="copyCanonical"></i>
                  </div>
                  <field-error :messages="fieldErrors.canonical_url" />
                  <p v-if="canonicalCopied" class="copy-done">
                     <i class="fa-solid fa-circle-check"></i> {{ $t('pages.categories.copiedToClipboard') }}
                  </p>
                  <p v-else class="field-row">
                     {{ $t('pages.categories.canonicalHelp') }}
                  </p>
                  <div class="seo-score-box">
                     <div class="seo-score-header">
                        <div class="seo-score-icon">
                           <i class="fa-solid fa-chart-line"></i>
                        </div>
                        <div class="seo-score-title">
                           <h4>{{ $t('pages.categories.seoScore', { score: seoScore.score }) }}</h4>
                           <div class="seo-score-checklist">
                              <div
                                 v-for="check in seoScore.checks"
                                 :key="check.label"
                                 class="seo-item"
                                 :class="check.ok ? 'good' : 'warning'"
                              >
                                 <i :class="check.ok ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-exclamation'"></i>
                                 {{ check.label }}
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
                        <h3>{{ $t('pages.categories.advancedSettings') }}</h3>
                        <p>{{ $t('pages.categories.advancedSettingsSub') }}</p>
                     </div>
                  </div>
                  <!-- Display Order + Products per Page -->
                  <div class="advanced-grid">
                     <div class="field-group">
                        <label for="category-display-order">{{ $t('pages.categories.displayOrder') }}</label>
                        <input
                           id="category-display-order"
                           type="number"
                           data-field="display_order"
                           v-model="result.display_order"
                           :class="{ invalid: fieldErrors.display_order }"
                        >
                        <field-error :messages="fieldErrors.display_order" />
                        <p class="field-row">{{ $t('pages.categories.displayOrderHelp') }}</p>
                     </div>
                     <div class="field-group">
                        <label for="category-per-page">{{ $t('pages.categories.productsPerPage') }}</label>
                        <select id="category-per-page" data-field="products_per_page" v-model="result.products_per_page">
                            <option 
                            v-for="item in productOptions" 
                            :key="item" 
                            :value="item"
                            >
                            {{ $t('pages.categories.productsPerPageOption', { count: item }) }}
                            </option>
                        </select>
                        <field-error :messages="fieldErrors.products_per_page" />
                    </div>
                  </div>
                  <!-- Category Features -->
                  <label class="feature-label">{{ $t('pages.categories.categoryFeatures') }}</label>
                  <div class="feature-box" data-field="show_in_nav" @click="toggleFeature('show_in_nav')">
                     <div class="feature-left">
                        <i class="fa-regular fa-eye"></i>
                        <div>
                           <h4>{{ $t('pages.categories.showInNav') }}</h4>
                           <p>{{ $t('pages.categories.showInNavHelp') }}</p>
                        </div>
                     </div>
                     <input 
                        type="checkbox"
                        class="toggle"
                        v-model="result.show_in_nav"
                        @click.stop
                    >
                  </div>
                  <field-error :messages="fieldErrors.show_in_nav" />
                  <!--
                     `in_footer` is a 1/2 column, not a flag. Bound plainly it
                     sent true/false, which the API refused twice over ("must
                     be an integer", "the selected in footer is invalid") on
                     every single save - two errors about a control the admin
                     had never touched, and no way to get rid of them.
                  -->
                  <div class="feature-box" data-field="in_footer" :class="{ 'is-invalid': fieldErrors.in_footer }" @click="toggleFeature('in_footer')">
                     <div class="feature-left">
                        <i class="fa-regular fa-eye"></i>
                        <div>
                           <h4>{{ $t('pages.categories.showInFooter') }}</h4>
                           <p>{{ $t('pages.categories.showInFooterHelp') }}</p>
                        </div>
                     </div>
                     <input 
                        type="checkbox"
                        class="toggle"
                        :true-value="1"
                        :false-value="2"
                        v-model="result.in_footer"
                        @click.stop
                     >
                  </div>
                  <field-error :messages="fieldErrors.in_footer" />
                  <div class="feature-box" data-field="show_homepage" @click="toggleFeature('show_homepage')">
                     <div class="feature-left">
                        <i class="fa-solid fa-house"></i>
                        <div>
                           <h4>{{ $t('pages.categories.showHomepage') }}</h4>
                           <p>{{ $t('pages.categories.showHomepageHelp') }}</p>
                        </div>
                     </div>
                     <input 
                        type="checkbox"
                        class="toggle"
                        v-model="result.show_homepage"
                        @click.stop
                    >
                  </div>
                  <field-error :messages="fieldErrors.show_homepage" />
                  <div class="feature-box" data-field="enable_filters" @click="toggleFeature('enable_filters')">
                     <div class="feature-left">
                        <i class="fa-solid fa-filter"></i>
                        <div>
                           <h4>{{ $t('pages.categories.enableFilters') }}</h4>
                           <p>{{ $t('pages.categories.enableFiltersHelp') }}</p>
                        </div>
                     </div>
                     <input 
                        type="checkbox"
                        class="toggle"
                        v-model="result.enable_filters"
                        @click.stop
                    >
                  </div>
                  <field-error :messages="fieldErrors.enable_filters" />
                  <div class="feature-box" data-field="allow_promotions" @click="toggleFeature('allow_promotions')">
                     <div class="feature-left">
                        <i class="fa-solid fa-tag"></i>
                        <div>
                           <h4>{{ $t('pages.categories.allowPromotions') }}</h4>
                           <p>{{ $t('pages.categories.allowPromotionsHelp') }}</p>
                        </div>
                     </div>
                     <input 
                        type="checkbox"
                        class="toggle"
                        v-model="result.allow_promotions"
                        @click.stop
                    >
                  </div>
                  <field-error :messages="fieldErrors.allow_promotions" />
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
                        <h3>{{ $t('pages.categories.statusVisibility') }}</h3>
                     </div>
                  </div>
                  <label>{{ $t('pages.categories.publicationStatus') }}</label>
                  <!--
                     Two states, which is all a category has ever had: the list
                     page, the bulk action and the API all read 1 as published
                     and 2 as draft. A third "Draft" radio posting 3 sat here,
                     and every save that used it was refused with "the selected
                     status is invalid".
                  -->
                  <div data-field="status">
                    <label class="radio-row">
                    <input type="radio" :value="1" v-model="result.status">
                    <span class="status-category-dot green"></span>
                    {{ $t('pages.categories.published') }}
                    </label>
                    <label class="radio-row">
                    <input type="radio" :value="2" v-model="result.status">
                    <span class="status-category-dot orange"></span>
                    {{ $t('pages.categories.draft') }}
                    </label>
                  </div>
                  <field-error :messages="fieldErrors.status" />
                  <div class="line-col visiblity-cl">
                     <label>{{ $t('pages.categories.visibility') }}</label>
                     <label class="check-row" data-field="featured">
                     <input 
                        type="checkbox"
                        :true-value="1"
                        :false-value="2"
                        v-model="result.featured"
                        @click.stop
                        > {{ $t('pages.categories.featuredCategory') }}
                     </label>
                     <field-error :messages="fieldErrors.featured" />
                  </div>
                  <div class="line-col sch-cl">
                     <label for="category-publish-at">{{ $t('pages.categories.schedulePublishing') }}</label>
                     <input 
                            id="category-publish-at"
                            type="datetime-local"
                            data-field="publish_at"
                            v-model="publishAt"
                        >
                     <field-error :messages="fieldErrors.publish_at" />
                     <!--
                        What the schedule will actually do, in the admin's own
                        words. The field used to say only "leave empty to
                        publish immediately" and give no sign of whether the
                        date it held meant anything - which it did not, since
                        nothing in the application read the column.
                     -->
                     <p v-if="scheduleNote" class="field-row schedule-note" :class="scheduleNote.tone">
                        <i :class="scheduleNote.icon" aria-hidden="true"></i>
                        {{ scheduleNote.text }}
                     </p>
                     <p v-else class="field-row">{{ $t('pages.categories.scheduleHelp') }}</p>
                  </div>
               </div>
               <!-- Quick Stats -->
               <div class="stats-card">
                  <h3><i class="fa-solid fa-chart-simple"></i> {{ $t('pages.categories.quickStats') }}</h3>
                  <template v-if="isNew">
                     <p class="stat-empty">{{ $t('pages.categories.statsEmpty') }}</p>
                  </template>
                  <template v-else>
                     <div class="stat">
                        <span>{{ $t('pages.categories.statProducts') }}</span>
                        <b>{{ result.products_count || 0 }}</b>
                     </div>
                     <div class="stat">
                        <span>{{ $t('pages.categories.statDirectSubs') }}</span>
                        <b>{{ result.children_count || 0 }}</b>
                     </div>
                     <div class="stat">
                        <span>{{ $t('pages.categories.statAllSubs') }}</span>
                        <b>{{ result.descendants_count || 0 }}</b>
                     </div>
                     <div class="stat">
                        <span>{{ $t('pages.categories.statBranchProducts') }}</span>
                        <b>{{ result.products_in_branch || 0 }}</b>
                     </div>
                  </template>
               </div>
               <!-- Pro Tips -->
               <div class="pro-tips-card">
                  <h3><i class="fa-solid fa-lightbulb"></i> {{ $t('pages.categories.proTips') }}</h3>
                  <ul>
                     <li> <i class="fa-solid fa-check"></i> {{ $t('pages.categories.tipNames') }}</li>
                     <li> <i class="fa-solid fa-check"></i> {{ $t('pages.categories.tipImages') }}</li>
                     <li> <i class="fa-solid fa-check"></i> {{ $t('pages.categories.tipMeta') }}</li>
                     <li> <i class="fa-solid fa-check"></i> {{ $t('pages.categories.tipSubcategories') }}</li>
                  </ul>
               </div>
               <!-- Record -->
               <div v-if="!isNew" class="card activity-card">
                  <div class="card-header">
                     <i class="fa-solid fa-clock-rotate-left"></i>
                     <h3>{{ $t('pages.categories.record') }}</h3>
                  </div>
                  <div class="activity-item">
                     <span class="activity-icon green"><i class="fa-solid fa-plus"></i></span>
                     <div>
                        <b>{{ $t('pages.categories.created') }}</b>
                        <p>{{ formatStamp(result.created_at) }}</p>
                     </div>
                  </div>
                  <div class="activity-item">
                     <span class="activity-icon blue"><i class="fa-solid fa-pencil"></i></span>
                     <div>
                        <b>{{ $t('pages.categories.lastUpdated') }}</b>
                        <p>{{ formatStamp(result.updated_at) }}</p>
                     </div>
                  </div>
                  <div class="activity-item">
                     <span class="activity-icon purple"><i class="fa-solid fa-hashtag"></i></span>
                     <div>
                        <b>{{ $t('pages.categories.categoryId') }}</b>
                        <p>{{ result.id }}</p>
                     </div>
                  </div>
               </div>
               <!-- Danger Zone -->
               <div class="danger-card">
                  <h3><i class="fa-solid fa-triangle-exclamation"></i> {{ $t('pages.categories.dangerZone') }}</h3>
                  <p v-if="isNew">
                     {{ $t('pages.categories.dangerUnsaved') }}
                  </p>
                  <p v-else>
                     <i18n path="pages.categories.dangerDeleting" tag="span">
                        <template v-slot:name><strong>{{ result.title }}</strong></template>
                     </i18n>
                     <template v-if="result.descendants_count">
                        <i18n :path="result.descendants_count === 1 ? 'pages.categories.dangerHasSubOne' : 'pages.categories.dangerHasSubMany'" tag="span">
                           <template v-slot:count><strong>{{ result.descendants_count }}</strong></template>
                        </i18n>
                     </template>
                     <template v-if="result.products_in_branch">
                        <i18n :path="result.products_in_branch === 1 ? 'pages.categories.dangerProductsOne' : 'pages.categories.dangerProductsMany'" tag="span">
                           <template v-slot:count><strong>{{ result.products_in_branch }}</strong></template>
                        </i18n>
                     </template>
                     <template v-if="!result.descendants_count && !result.products_in_branch">
                        {{ $t('pages.categories.dangerNothingLinked') }}
                     </template>
                  </p>
                  <button
                     type="button"
                     class="delete-btn"
                     :disabled="isNew"
                     @click="confirmDelete"
                  >
                  <i class="fa-solid fa-trash"></i> {{ $t('pages.categories.deleteCategoryBtn') }}
                  </button>
               </div>
            </div>
         </div>
      </div>
    </template>
  </data-page>

    <!-- DELETE CONFIRMATION -->
      <confirm-dialog
        :open="showDeleteConfirm"
        :title="$t('pages.categories.deleteCategoryTitle')"
        :warning="$t('pages.categories.cannotBeUndone')"
        :confirm-label="$t('pages.categories.deleteCategory')"
        confirm-icon="fa-solid fa-trash"
        :busy-label="$t('pages.categories.deleting')"
        :busy="deleting"
        :confirm-disabled="impactLoading"
        @cancel="closeDelete"
        @confirm="executeDelete"
      >
        <template v-slot:subject>
          <i18n path="pages.categories.deleteSubjectOne" tag="span">
            <template v-slot:name><strong>{{ result.title }}</strong></template>
          </i18n>
        </template>

          <div v-if="impactLoading" class="impact-box" aria-busy="true">
            <span class="sk-field"></span>
          </div>

          <div v-else-if="impact" class="impact-box">
            <!--
               Each sentence is one flex item, not several. The line is a
               flex row so the icon can hang beside wrapped text, and a bare
               <strong> in among the words became an item of its own - so the
               row's 9px gap opened up either side of every number, and the
               sentences read "Contains  3  subcategories."
            -->
            <p v-if="impact.subcategories" class="impact-line warn">
              <i class="fa fa-sitemap" aria-hidden="true"></i>
              <span>
                <i18n :path="impact.subcategories === 1 ? 'pages.categories.impactSubsOne' : 'pages.categories.impactSubsMany'" tag="span">
                  <template v-slot:count><strong>{{ impact.subcategories }}</strong></template>
                </i18n>
              </span>
            </p>
            <p v-if="impact.products_including_subcategories" class="impact-line warn">
              <i class="fa fa-box" aria-hidden="true"></i>
              <span>
                <i18n :path="impact.products_including_subcategories === 1 ? 'pages.categories.impactProductsOne' : 'pages.categories.impactProductsMany'" tag="span">
                  <template v-slot:count><strong>{{ impact.products_including_subcategories }}</strong></template>
                </i18n>
              </span>
            </p>
            <p v-if="!impact.subcategories && !impact.products_including_subcategories" class="impact-line ok">
              <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
              <span>{{ $t('pages.categories.nothingLinkedOne') }}</span>
            </p>
          </div>

          <fieldset v-if="impact && impact.subcategories" class="children-choice">
            <legend>{{ $t('pages.categories.childrenQuestion') }}</legend>
            <label>
              <input type="radio" value="reparent" v-model="childrenMode">
              <span>
                <strong>{{ $t('pages.categories.moveThemUp') }}</strong>
                <em>{{ $t('pages.categories.moveThemUpHelp') }}</em>
              </span>
            </label>
            <label>
              <input type="radio" value="cascade" v-model="childrenMode">
              <span>
                <strong>{{ $t('pages.categories.deleteThemToo') }}</strong>
                <em>{{ $t('pages.categories.deleteThemTooHelp', { count: impact.subcategories }) }}</em>
              </span>
            </label>
          </fieldset>

      </confirm-dialog>
  </div>
</template>

<script>

  import DataPage from "~/components/partials/DataPage";
  import util from "~/mixin/util"
  import Dropdown from '~/components/Dropdown'
  import FieldError from '~/components/FieldError'
  import ConfirmDialog from '~/components/ConfirmDialog'
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
        // Parent picker, fetched here rather than taken from the shared store:
        // the store's copy is root categories only and has no notion of which
        // options would create a loop for the category being edited.
        parentOptions: null,
        parentsLoading: true,
        // '' | 'checking' | 'available' | 'taken' | 'invalid'
        slugState: '',
        slugSuggestion: '',
        slugTouched: false,
        slugTimer: null,
        imageError: '',
        // Confirmation for the canonical-URL copy button, which used to
        // write to the clipboard with nothing shown to say that it had.
        canonicalCopied: false,

        canonicalCopiedTimer: null,
        // Delete confirmation, replacing window.confirm().
        showDeleteConfirm: false,
        deleting: false,
        impact: null,
        impactLoading: false,
        childrenMode: 'reparent',
        result: {
          id: '',
          title: '',
          status: 2,
          featured: 2,
          parent: '',
          slug: '',
          meta_description: '',
          meta_keywords: '',
          // 1/2 columns, like status - not flags. See the footer toggle.
          in_footer: 2,
          meta_title: '',
          image: '',
          canonical_url : '',
          description: '',
          short_description: '',
          /*
           * `in_frontend` and `searchable` are deliberately absent. The form
           * has no control for either any more, and writableAttributes() only
           * writes what the request actually carries - so an edit leaves the
           * stored value alone, and a new category takes the column defaults
           * (both 1: visible, searchable). Seeding them here would post a
           * value nobody chose, and the old seed was in_frontend: 2, which
           * would have made every new category invisible on the storefront.
           */
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
      Dropdown,
      FieldError,
      ConfirmDialog
    },
    computed: {
        /**
         * The address this category would have on the storefront.
         *
         * Was hard coded to yourstore.com, which is what the field offered as
         * its placeholder — so every category the admin did not fill in by
         * hand advertised a domain nobody owns.
         */
        canonicalUrl() {
            if (this.result.canonical_url) return this.result.canonical_url

            const base = (process.env.CLIENT_BASE_URL || process.env.apiBase || '')
                .replace(/\/$/, '')

            return `${base}/category/${this.result.slug || ''}`
        },
        /**
         * A score over the fields actually filled in, rather than the fixed
         * "85/100" and three fixed checklist lines this used to render whether
         * the SEO section was complete or empty.
         */
        seoScore() {
            const title = (this.result.meta_title || '').trim()
            const description = (this.result.meta_description || '').trim()

            const checks = [
                {
                    label: title.length >= 30 && title.length <= 60
                        ? this.$t('pages.categories.seoTitleGood')
                        : (title ? this.$t('pages.categories.seoTitleLength') : this.$t('pages.categories.seoTitleMissing')),
                    ok: title.length >= 30 && title.length <= 60
                },
                {
                    label: description.length >= 120 && description.length <= 500
                        ? this.$t('pages.categories.seoDescGood')
                        : (description ? this.$t('pages.categories.seoDescLength') : this.$t('pages.categories.seoDescMissing')),
                    ok: description.length >= 120 && description.length <= 500
                },
                {
                    label: this.keywords.length >= 3
                        ? this.$t('pages.categories.seoKeywordsGood')
                        : this.$t('pages.categories.seoKeywordsMissing'),
                    ok: this.keywords.length >= 3
                },
                {
                    label: this.result.slug ? this.$t('pages.categories.seoSlugGood') : this.$t('pages.categories.seoSlugMissing'),
                    ok: !!this.result.slug
                },
                {
                    label: (this.result.short_description || '').trim()
                        ? this.$t('pages.categories.seoShortDescGood')
                        : this.$t('pages.categories.seoShortDescMissing'),
                    ok: !!(this.result.short_description || '').trim()
                }
            ]

            const passed = checks.filter(check => check.ok).length

            return { checks, score: Math.round(passed / checks.length * 100) }
        },
        /**
         * The schedule field, in the admin's own clock.
         *
         * `publish_at` is stored and compared in the application's timezone
         * (UTC), and a <input type="datetime-local"> has no timezone at all -
         * it reads and writes the wall clock of whoever is sitting in front
         * of it. Binding the column straight to the control would have shown
         * a Dublin admin a UTC time and sent back a UTC-labelled value that
         * was really local, which for a scheduling field is the whole thing.
         */
        publishAt: {
            get() {
                return this.toInputValue(this.result.publish_at)
            },
            set(value) {
                const parsed = value ? new Date(value) : null

                this.result.publish_at = (parsed && !isNaN(parsed.getTime()))
                    ? parsed.toISOString()
                    : ''
            }
        },
        /** What the schedule will do, said plainly under the field. */
        scheduleNote() {
            const when = this.asDate(this.result.publish_at)

            if (!when) return null

            if (Number(this.result.status) === 1) {
                return {
                    tone: 'warn',
                    icon: 'fa-solid fa-circle-exclamation',
                    text: this.$t('pages.categories.scheduleAlreadyPublished')
                }
            }

            if (when.getTime() <= Date.now()) {
                return {
                    tone: 'warn',
                    icon: 'fa-solid fa-circle-exclamation',
                    text: this.$t('pages.categories.schedulePassed')
                }
            }

            return {
                tone: 'ok',
                icon: 'fa-solid fa-clock',
                text: this.$t('pages.categories.scheduleUntil', { when: this.formatStamp(when) })
            }
        },
        isNew() {
            return this.$route?.params?.id === 'add' || !this.result.id
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
        /**
         * Open the confirmation and ask the server what the delete would take
         * with it. Was a window.confirm() over a fixed sentence, followed by a
         * raw axios call to a hard-coded production host whose failure was
         * swallowed into console.error — so a refused delete looked exactly
         * like a successful one.
         */
        async confirmDelete() {
            this.showDeleteConfirm = true
            this.childrenMode = 'reparent'
            this.impact = null
            this.impactLoading = true

            try {
                this.impact = await this.getById({
                    id: this.result.id, params: {}, api: 'getCategoryDeleteImpact'
                })
            } catch (e) {
                this.impact = null
            } finally {
                this.impactLoading = false
            }
        },
        closeDelete() {
            if (this.deleting) return
            this.showDeleteConfirm = false
            this.impact = null
        },
        async executeDelete(){
            if (this.deleting) return

            this.deleting = true

            try {
                await this.deleteData({
                    params: `${this.result.id}?children=${this.childrenMode}`,
                    api: 'deleteCategory'
                })

                this.emptyAllList('allCategories')
                this.showDeleteConfirm = false
                this.$router.push('/categories')

            } catch (e) {
                // The dialog stays open and the category is still there.
                this.$store.dispatch('ui/setToastError',
                    e?.message || this.$t('error.err') || this.$t('pages.categories.couldNotDelete'))
            } finally {
                this.deleting = false
            }
        },
        /**
         * Flip a toggle from a click anywhere on its row.
         *
         * Three of these columns store 1 or 2 rather than a boolean, and the
         * API refuses anything else. Writing `!value` to one of them sent
         * `true`, so the save came back with two complaints about a control
         * the admin had not touched.
         */
        toggleFeature(key) {
            if (['in_footer', 'featured'].indexOf(key) > -1) {
                this.result[key] = Number(this.result[key]) === 1 ? 2 : 1
                return
            }

            this.result[key] = !this.result[key]
        },
        copyCanonical() {
            if (!this.canonicalUrl) return
            navigator.clipboard.writeText(this.canonicalUrl)

            this.canonicalCopied = true
            clearTimeout(this.canonicalCopiedTimer)
            this.canonicalCopiedTimer = setTimeout(() => {
                this.canonicalCopied = false
            }, 2000)
        },
        handleGalleryUpload(e) {
            const files = Array.from(e.target.files).filter(file => this.validateImage(file, 5))

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
        /**
         * Removal is an explicit null on save, which the API now reads as
         * "fall back to the default image". The form used to set the same
         * field to null and the API dropped it along with every other falsy
         * value, so the picture never actually went away.
         */
        removeIcon() {
            this.iconPreview = null
            this.result.icon = null
            this.result.image = null
        },
        removeBanner() {
            this.bannerPreview = null
            this.result.banner = null
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
        // The media base comes from the server's own configuration (it is put
        // in the store at boot), not from a hard-coded production host.
        let url = this.$store.state.imgSrcUrl || '';

        if(this.$route?.params?.id === 'add'){
          this.emptyAllList('allCategories')
        }

        this.result = {
            ...this.result,
            ...evt 
        }

        /*
         * 1/2 columns, and seven existing categories hold a 0 in in_footer
         * from before the column meant anything. The checkbox can only ever
         * produce 1 or 2, so a 0 loaded here would be posted back untouched
         * and refused ("the selected in footer is invalid") on a save the
         * admin made for some entirely unrelated reason, with nothing on the
         * form they could change to get rid of it.
         */
        ;['in_footer', 'featured'].forEach(key => {
            this.result[key] = Number(this.result[key]) === 1 ? 1 : 2
        })

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
      /**
       * Derive the slug from the name only while the admin has not written one
       * themselves. This used to overwrite the slug on every keystroke in the
       * name field, so a hand-picked slug was destroyed by any later edit to
       * the title.
       */
      titleChanged(){
        if (this.slugTouched) return

        this.result.slug = this.convertToSlug(this.result.title)
        this.checkSlug()
      },
      formatStamp(value){
        if (!value) return '—'
        const parsed = value instanceof Date ? value : this.asDate(value)
        if (!parsed) return '—'
        return parsed.toLocaleString(this.$i18n?.locale || 'en', {
          year: 'numeric', month: 'short', day: '2-digit',
          hour: '2-digit', minute: '2-digit'
        })
      },
      /**
       * A Date from whatever the API or the form is holding.
       *
       * The API sends "2026-09-24 08:00:00" - no timezone, and the column is
       * in the application's, which is UTC. Left to `new Date()` that string
       * is read as *local* time, so every stamp on this page was out by the
       * admin's offset. The form's own values are already full ISO.
       */
      asDate(value){
        if (!value) return null

        const naive = /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/.test(value)
        const parsed = new Date(naive ? value.replace(' ', 'T') + 'Z' : value)

        return isNaN(parsed.getTime()) ? null : parsed
      },
      /** A Date as the wall clock a datetime-local control wants. */
      toInputValue(value){
        const parsed = this.asDate(value)

        if (!parsed) return ''

        const pad = n => String(n).padStart(2, '0')

        return `${parsed.getFullYear()}-${pad(parsed.getMonth() + 1)}-${pad(parsed.getDate())}`
          + `T${pad(parsed.getHours())}:${pad(parsed.getMinutes())}`
      },
      slugEdited(){
        this.slugTouched = true
        this.checkSlug()
      },
      useSuggestedSlug(){
        this.result.slug = this.slugSuggestion
        this.slugState = 'available'
      },
      /** Debounced, against the server — the field used to render a fixed
       *  "Slug is available" that checked nothing at all. */
      checkSlug(){
        clearTimeout(this.slugTimer)

        const slug = (this.result.slug || '').trim()

        if (!slug) {
          this.slugState = ''
          return
        }

        if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug)) {
          this.slugState = 'invalid'
          return
        }

        this.slugState = 'checking'

        this.slugTimer = setTimeout(async () => {
          try {
            const params = { slug }
            if (this.result.id) params.id = this.result.id

            const data = await this.getRequest({ params, api: 'getCategorySlugAvailable' })

            // A later keystroke may have moved on while this was in flight.
            if ((this.result.slug || '').trim() !== slug) return

            this.slugSuggestion = data.suggestion
            this.slugState = data.available ? 'available' : 'taken'

          } catch (e) {
            // Unknown rather than wrongly green; the save still validates.
            this.slugState = ''
          }
        }, 400)
      },
      /** The options the parent picker may offer, as a tree, minus this
       *  category and everything under it. */
      async loadParentOptions(){
        this.parentsLoading = true

        try {
          const params = { scope: 'all' }

          if (this.result.id) params.exclude = this.result.id

          const rows = await this.getRequest({ params, api: 'getAllCategories' })
          const list = Array.isArray(rows) ? rows : (rows?.data || [])

          const byParent = {}
          list.forEach(row => {
            const key = (row.parent === null || row.parent === undefined) ? 'root' : String(row.parent)
            if (!byParent[key]) byParent[key] = []
            byParent[key].push(row)
          })

          const options = {}
          const walk = (key, depth) => {
            ;(byParent[key] || []).forEach(row => {
              options[row.id] = { title: (depth ? '— '.repeat(depth) : '') + row.title }
              walk(String(row.id), depth + 1)
            })
          }
          walk('root', 0)

          this.parentOptions = options

        } catch (e) {
          this.parentOptions = {}
          this.$store.dispatch('ui/setToastError',
            e?.message || this.$t('pages.categories.couldNotLoadParents'))
        } finally {
          this.parentsLoading = false
        }
      },
      dropdownSelected(data) {
        this.result.status = data.key
      },
    /**
     * Accepted types and the size cap, matched to what the API enforces.
     *
     * Neither was checked here: any file the picker would hand over was read
     * as base64 and posted, so a 40MB TIFF was only refused after the whole
     * thing had been uploaded — if at all.
     */
    validateImage(file, maxMb) {
        const allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml']

        if (!allowed.includes(file.type)) {
            this.imageError = this.$t('pages.categories.imageType')
            this.$store.dispatch('ui/setToastError', this.imageError)
            return false
        }

        if (file.size > maxMb * 1024 * 1024) {
            this.imageError = this.$t('pages.categories.imageTooLarge', { size: (file.size / 1024 / 1024).toFixed(1), max: maxMb })
            this.$store.dispatch('ui/setToastError', this.imageError)
            return false
        }

        this.imageError = ''
        return true
    },

    handleIconUpload(e) {
        const file = e.target.files[0]
        e.target.value = ''

        if (!file || !this.validateImage(file, 2)) return

        const reader = new FileReader()

        reader.onload = (event) => {
            this.iconPreview = event.target.result
            this.result.icon = event.target.result
        }

        reader.readAsDataURL(file)
    },

    handleBannerUpload(e) {
        const file = e.target.files[0]
        e.target.value = ''

        if (!file || !this.validateImage(file, 5)) return

        const reader = new FileReader()

        reader.onload = (event) => {
            this.bannerPreview = event.target.result
            this.result.banner = event.target.result
        }

        reader.readAsDataURL(file)
    },
    ...mapActions('common', ['getAllList', 'emptyAllList', 'getRequest', 'getById', 'deleteData'])
    },
    async mounted() {
      /*
       * "Add subcategory" in the list arrives here with ?parent=<id>, so the
       * picker opens on the right parent instead of the admin having to find
       * it again.
       */
      const presetParent = this.$route?.query?.parent

      if (presetParent && this.$route?.params?.id === 'add') {
        this.result.parent = presetParent
      }

      await this.loadParentOptions()
    },
    beforeDestroy() {
      clearTimeout(this.slugTimer)
      clearTimeout(this.canonicalCopiedTimer)

      // Navigating away with the dialog open would otherwise leave the whole
      // admin panel unable to scroll.
      if (typeof document !== 'undefined') {
        document.body.style.overflow = ''
      }
    }
  }
</script>

<style scoped>
/* =====================================================================
   Category add / edit
   One set of tokens for the whole form: spacing, radii, borders and
   type sizes are picked from these rather than restated per block, so
   the sections line up with each other and with the category list page.
   ===================================================================== */

.dashboard,
.cat-head {
  --surface: #FFFFFF;
  --surface-sunken: #F9FAFB;
  --line: #E5E7EB;
  --line-soft: #F1F3F5;
  --ink: #111827;
  --ink-body: #374151;
  --ink-muted: #6B7280;
  --ink-faint: #9CA3AF;
  --accent: #33319a;
  --accent-soft: rgba(51, 49, 154, .12);
  --danger: #DC2626;
  --ok: #16A34A;
  --warn: #D97706;
  --r-card: 16px;
  --r-control: 10px;
  --card-pad: 24px;
  --shadow-card: 0 1px 2px rgba(16, 24, 40, .04), 0 0 0 1px rgba(16, 24, 40, .04);
}

/* ---- page header (rendered into DataPage's sticky top bar) ---------- */

.cat-head {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.cat-head__crumb {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  font-weight: 600;
  color: var(--ink-muted);
  text-decoration: none;
  width: fit-content;
}

.cat-head__crumb:hover { color: var(--accent); }

.cat-head__crumb i { font-size: 10px; }

.cat-head__title {
  margin: 0;
  font-size: 18px;
  font-weight: 700;
  line-height: 1.25;
  color: var(--ink);
}

.cat-head__sub {
  margin: 0;
  font-size: 13px;
  line-height: 1.45;
  color: var(--ink-muted);
}

/* ---- layout --------------------------------------------------------- */

.dashboard { padding: 4px 0 0; }

.dashboard .container_dash {
  display: grid;
  grid-template-columns: minmax(0, 2fr) minmax(300px, 1fr);
  align-items: start;
  gap: 20px;
  margin: auto;
}

.dashboard .left,
.dashboard .right {
  display: flex;
  flex-direction: column;
  gap: 20px;
  min-width: 0;
}

/* ---- cards ---------------------------------------------------------- */

.card,
.stats-card,
.pro-tips-card,
.danger-card {
  background: var(--surface);
  border-radius: var(--r-card);
  padding: var(--card-pad);
  box-shadow: var(--shadow-card);
}

.card { margin-bottom: 0; }
.card:last-child { margin-bottom: 0; }

.card-header {
  display: flex;
  gap: 12px;
  align-items: center;
  padding-bottom: 16px;
  margin-bottom: 4px;
  border-bottom: 1px solid var(--line-soft);
}

.card-header h3 {
  font-size: 16px;
  font-weight: 700;
  color: var(--ink);
  line-height: 1.3;
  margin: 0 0 2px;
}

.card-header p {
  font-size: 13px;
  color: var(--ink-muted);
  font-weight: 400;
  line-height: 1.4;
  margin: 0;
}

.card-header .icon {
  width: 36px;
  height: 36px;
  flex-shrink: 0;
  border-radius: 10px;
  background: #EEF2FF;
  display: flex;
  align-items: center;
  justify-content: center;
}

.card-header .icon i {
  color: #4F46E5;
  font-size: 14px;
}

.card.mda .card-header .icon { background: #F5F3FF; }
.card.mda .card-header .icon i { color: #7C3AED; }
.icon.green { background: #F0FDF4; }
.icon.green i { color: var(--ok); }
.icon.orange { background: #FFF7ED; }
.icon.orange i { color: #EA580C; }
.icon.purple { background: #F5F3FF; }
.card-header.stts-vis .icon { background: #F3F4F6; }
.card-header.stts-vis .icon i { color: #4B5563; }

/* ---- fields --------------------------------------------------------- */

.dashboard .card label {
  display: block;
  font-size: 13px;
  font-weight: 600;
  color: var(--ink-body);
  margin: 20px 0 6px;
  line-height: 1.4;
}

.dashboard .card label span { color: var(--danger); margin-left: 2px; }

.dashboard .card input:not([type="checkbox"]):not([type="radio"]),
.dashboard .card textarea,
.dashboard .card select {
  width: 100%;
  margin: 0;
  padding: 10px 14px;
  min-height: 42px;
  border-radius: var(--r-control);
  border: 1px solid var(--line);
  background: var(--surface);
  font-family: inherit;
  font-size: 14px;
  font-weight: 400;
  line-height: 1.5;
  color: var(--ink);
  transition: border-color .15s ease, box-shadow .15s ease;
  outline: 0 !important;
}

.dashboard .card select {
  appearance: none;
  background-image: url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%236B7280' d='M1.4.6 6 5.2 10.6.6 12 2 6 8 0 2z'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 14px center;
  padding-right: 38px;
}

.dashboard .card input:not([type="checkbox"]):not([type="radio"])::placeholder,
.dashboard .card textarea::placeholder { color: var(--ink-faint); }

.dashboard .card input:not([type="checkbox"]):not([type="radio"]):focus,
.dashboard .card textarea:focus,
.dashboard .card select:focus {
  border-color: var(--accent);
  box-shadow: 0 0 0 3px var(--accent-soft);
  background: var(--surface);
}

.dashboard .card input.invalid,
.dashboard .card select.invalid,
.dashboard .card textarea.invalid {
  border-color: var(--danger);
  box-shadow: 0 0 0 3px rgba(220, 38, 38, .1);
}

.dashboard .card textarea {
  min-height: 96px;
  resize: vertical;
}

.dashboard .card textarea.shrt-des { min-height: 84px; }
.dashboard .card textarea#metaDesc { min-height: 110px; }

.input-wrap { display: block; }

/* ---- the refusal for one field, under that field -------------------
   Every message the save came back with used to be stacked in one box
   at the top of the page, so "The meta title field is required" meant
   scrolling the form looking for the meta title. These sit against the
   control, and DataPage keeps them out of the box at the top. */

.field-error {
  display: flex;
  align-items: flex-start;
  gap: 6px;
  margin: 6px 0 0;
  font-size: 12px;
  line-height: 1.5;
  font-weight: 500;
  color: var(--danger);
}

.field-error i {
  font-size: 11px;
  line-height: 18px;
  flex: 0 0 auto;
}

/* The controls that are not a plain input still have to look wrong. */

.dashboard .card .feature-box.is-invalid,
.dashboard .card .keyword-box.is-invalid {
  border-color: var(--danger);
}

/* help line under a field, with the character counter on the right */

.field-row {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
  font-size: 12px;
  line-height: 1.5;
  color: var(--ink-muted);
  margin-top: 6px;
  font-weight: 400;
}

.field-row p { margin: 0; }

.field-row span {
  color: var(--ink-faint);
  font-weight: 500;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.field-row span.text-danger { color: var(--danger) !important; }

/* slug availability */

.field-status {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
  font-size: 12px;
  line-height: 1.5;
  color: var(--ink-muted);
  margin-top: 6px;
  min-height: 18px;
}

.field-status.is-available { color: var(--ok); }
.field-status.is-taken,
.field-status.is-invalid { color: var(--danger); }
.field-status.is-checking { color: var(--ink-muted); }

.link-btn {
  border: 0;
  background: transparent;
  padding: 0;
  font: inherit;
  color: var(--accent);
  text-decoration: underline;
  cursor: pointer;
}

.link-btn:hover { color: #201f6b; }

.link-btn:focus-visible {
  outline: 2px solid var(--accent);
  outline-offset: 2px;
  border-radius: 4px;
}

/* parent picker skeleton */

.field-skeleton { display: block; }

@keyframes form-shimmer {
  0% { background-position: 100% 50%; }
  100% { background-position: 0 50%; }
}

.sk-field {
  display: block;
  height: 42px;
  width: 100%;
  border-radius: var(--r-control);
  background: linear-gradient(90deg, #F3F4F6 25%, #E5E7EB 37%, #F3F4F6 63%);
  background-size: 400% 100%;
  animation: form-shimmer 1.4s ease infinite;
}

/* ---- media ---------------------------------------------------------- */

.icon-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
}

.upload-box {
  width: auto;
  height: 190px;
  border: 1.5px dashed #D1D5DB;
  border-radius: 12px;
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  cursor: pointer;
  text-align: center;
  padding: 16px;
  background: var(--surface-sunken);
  transition: border-color .15s ease, background .15s ease;
}

.upload-box:hover {
  border-color: var(--accent);
  background: #FAFAFF;
}

.upload-box i {
  color: var(--ink-faint);
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: 50%;
  width: 48px;
  height: 48px;
  font-size: 18px;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 12px;
}

.upload-box p {
  font-weight: 600;
  font-size: 13px;
  color: var(--ink-body);
  margin: 0 0 4px;
}

.upload-box span {
  font-size: 12px;
  color: var(--ink-muted);
  font-weight: 400;
}

.upload-box span.size-spn {
  color: var(--ink-faint);
  margin-top: 2px;
}

.current-icon {
  display: flex;
  flex-direction: column;
  border: 1px solid var(--line);
  border-radius: 12px;
  height: 190px;
  font-size: 13px;
  color: var(--ink-muted);
  background: var(--surface-sunken);
  padding: 14px;
}

.current-icon p.current-title {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  width: 100%;
  margin: 0;
}

.current-icon p.current-title span {
  color: var(--ink-body);
  font-weight: 600;
  font-size: 12px;
}

.current-icon p i.delete-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 26px;
  height: 26px;
  border-radius: 50%;
  background: var(--surface);
  border: 1px solid var(--line);
  color: var(--ink-faint);
  font-size: 11px;
  cursor: pointer;
  transition: all .15s ease;
}

.current-icon p i.delete-icon:hover {
  background: #FEF2F2;
  border-color: #FECACA;
  color: var(--danger);
}

/* A square tile for a square asset. The preview filled the panel's whole
   width, so a 256x256 icon sat marooned in the middle of a 350x130
   rectangle, reading as a broken image rather than a thumbnail. */

.current-icon img {
  flex: 1;
  min-height: 0;
  aspect-ratio: 1;
  width: auto;
  max-width: 100%;
  object-fit: contain;
  background: var(--surface);
  border: 1px solid var(--line-soft);
  border-radius: 10px;
  padding: 12px;
  margin: 10px auto 0;
}

.current-icon > span {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  color: var(--ink-faint);
}

/* banner */

/* The shape the field asks for. It advertises 1200x400 and then rendered the
   upload in a fixed 200px-tall box - roughly 3.9:1 at this column width - so
   `cover` cropped the top and bottom off every banner the admin had sized to
   the stated recommendation, and the card never showed what the storefront
   would get. */

.banner-upload {
  margin-top: 0;
  border: 1.5px dashed #D1D5DB;
  border-radius: 12px;
  aspect-ratio: 3 / 1;
  height: auto;
  /* `width: 100%` is load-bearing. Left auto, the box takes its width from
     the ratio once `min-height` wins - 170px x 3 = 510px - so on a phone the
     banner grew wider than the card it sits in. */
  width: 100%;
  min-height: 170px;
  display: flex;
  justify-content: center;
  align-items: center;
  cursor: pointer;
  position: relative;
  overflow: hidden;
  background: var(--surface-sunken);
  transition: border-color .15s ease, background .15s ease;
}

.banner-upload:hover {
  border-color: var(--accent);
  background: #FAFAFF;
}

.banner-placeholder { padding: 16px; }

.banner-placeholder i {
  color: var(--ink-faint);
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: 50%;
  width: 48px;
  height: 48px;
  font-size: 18px;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 12px auto;
}

.banner-placeholder p {
  font-weight: 600;
  font-size: 13px;
  color: var(--ink-body);
  margin: 0 0 4px;
  text-align: center;
}

.banner-placeholder span {
  width: 100%;
  display: block;
  text-align: center;
  font-size: 12px;
  color: var(--ink-muted);
  font-weight: 400;
}

.banner-placeholder span.sze-upl {
  color: var(--ink-faint);
  margin-top: 2px;
}

.banner-upload img {
  width: 100%;
  height: 100%;
  max-width: none;
  max-height: none;
  object-fit: cover;
  border-radius: 0;
}

/* Sits on the banner itself, sharing .remove-img with the gallery tiles. */

.banner-remove {
  top: 10px;
  right: 10px;
  width: 28px;
  height: 28px;
  border: 0;
  font-size: 16px;
  line-height: 1;
  padding: 0;
}

/* additional images */

.additional-upload { margin-top: 0; }

.gallery-upload {
  width: 116px;
  height: 116px;
  max-width: none;
  max-height: none;
  border: 1.5px dashed #D1D5DB;
  border-radius: 12px;
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  cursor: pointer;
  background: var(--surface-sunken);
  transition: border-color .15s ease, background .15s ease;
}

.gallery-upload:hover {
  border-color: var(--accent);
  background: #FAFAFF;
}

.gallery-upload p {
  font-weight: 500;
  color: var(--ink-muted);
  font-size: 12px;
  margin: 0;
}

.gallery-upload i {
  font-size: 14px;
  margin-bottom: 6px;
  color: var(--ink-faint);
}

.gallery-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, 116px);
  gap: 12px;
  margin-top: 0;
}

.gallery-item {
  position: relative;
  width: 116px;
  height: 116px;
  border: 1px solid var(--line);
  border-radius: 12px;
  overflow: hidden;
  background: var(--surface-sunken);
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
  background: rgba(17, 24, 39, .72);
  color: #fff;
  border-radius: 50%;
  width: 22px;
  height: 22px;
  font-size: 14px;
  cursor: pointer;
  box-shadow: none;
  display: flex;
  align-items: center;
  justify-content: center;
  line-height: 1;
  transition: background .15s ease;
}

.remove-img:hover { background: var(--danger); }

/* ---- SEO ------------------------------------------------------------ */

.keyword-box {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  border: 1px solid var(--line);
  border-radius: var(--r-control);
  padding: 7px 8px;
  background: var(--surface);
  transition: border-color .15s ease, box-shadow .15s ease;
}

.keyword-box:focus-within {
  border-color: var(--accent);
  box-shadow: 0 0 0 3px var(--accent-soft);
}

.card .keyword-box input {
  border: none;
  flex: 1;
  min-width: 140px;
  font-size: 14px;
  padding: 4px 6px;
  min-height: 28px;
  outline: none;
  margin: 0;
  box-shadow: none;
}

.keyword-tag {
  background: #F3F4F6;
  color: var(--ink-body);
  padding: 5px 10px;
  border-radius: 999px;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-size: 12px;
  font-weight: 500;
  line-height: 1.4;
}

.keyword-tag i {
  cursor: pointer;
  font-size: 13px;
  font-style: normal;
  color: var(--ink-faint);
  line-height: 1;
}

.keyword-tag i:hover { color: var(--danger); }

/* canonical URL */

.canonical-field {
  display: flex;
  align-items: center;
  border: 1px solid var(--line);
  border-radius: var(--r-control);
  overflow: hidden;
  background: var(--surface);
  transition: border-color .15s ease, box-shadow .15s ease;
}

.canonical-field:focus-within {
  border-color: var(--accent);
  box-shadow: 0 0 0 3px var(--accent-soft);
}

.card .canonical-field input {
  border: none;
  flex: 1;
  padding: 10px 14px;
  font-size: 14px;
  margin: 0;
  box-shadow: none;
  border-radius: 0;
}

.copy-btn {
  padding: 0 14px;
  align-self: stretch;
  display: flex;
  align-items: center;
  cursor: pointer;
  color: var(--ink-muted);
  border-left: 1px solid var(--line);
  transition: color .15s ease, background .15s ease;
}

.copy-btn:hover { color: var(--ink); background: var(--surface-sunken); }

.copy-done {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: var(--ok);
  margin-top: 6px;
}

/* SEO score */

.seo-score-box {
  margin-top: 22px;
  padding: 18px;
  border-radius: 12px;
  background: var(--surface-sunken);
  border: 1px solid var(--line);
}

.seo-score-header {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  justify-content: flex-start;
}

.seo-score-icon {
  width: 32px;
  height: 32px;
  flex-shrink: 0;
  border-radius: 8px;
  background: var(--surface);
  border: 1px solid var(--line);
  display: flex;
  align-items: center;
  justify-content: center;
}

.seo-score-icon i {
  color: var(--ink-body);
  font-size: 12px;
}

.seo-score-title { flex: 1; margin-left: 0; min-width: 0; }

.seo-score-title h4 {
  font-size: 13px;
  font-weight: 700;
  color: var(--ink);
  margin: 0 0 10px;
}

.seo-score-checklist {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
  gap: 6px 18px;
  margin-top: 0;
}

.seo-item {
  font-size: 12px;
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--ink-body);
  font-weight: 400;
  line-height: 1.5;
}

.seo-item.good i { color: var(--ok); }
.seo-item.warning i { color: var(--warn); }

/* ---- advanced settings ---------------------------------------------- */

.advanced-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px 20px;
  margin-bottom: 4px;
}

.field-group label { margin-top: 20px; }

.feature-label { margin-top: 22px; margin-bottom: 4px; display: block; }

.feature-box {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
  border: 1px solid var(--line);
  border-radius: 12px;
  padding: 14px 16px;
  margin-top: 10px;
  background: var(--surface);
  cursor: pointer;
  transition: border-color .15s ease, background .15s ease;
}

.feature-box:hover {
  border-color: #D1D5DB;
  background: var(--surface-sunken);
}

.feature-left {
  display: flex;
  gap: 12px;
  align-items: center;
  min-width: 0;
}

.feature-left i {
  font-size: 15px;
  color: var(--ink-faint);
  width: 18px;
  text-align: center;
}

.feature-left h4 {
  font-size: 14px;
  font-weight: 600;
  margin: 0 0 2px;
  color: var(--ink);
}

.feature-left p {
  font-size: 12px;
  color: var(--ink-muted);
  font-weight: 400;
  margin: 0;
  line-height: 1.45;
}

/* ---- switches and choice controls ----------------------------------- */

.dashboard input[type="checkbox"].toggle {
  appearance: none;
  -webkit-appearance: none;
  position: relative;
  flex-shrink: 0;
  width: 40px !important;
  height: 22px !important;
  min-height: 0 !important;
  margin: 0 !important;
  padding: 0 !important;
  border: 0;
  border-radius: 999px;
  background: #D1D5DB;
  opacity: 1;
  cursor: pointer;
  transition: background .18s ease;
}

.dashboard input[type="checkbox"].toggle:before {
  content: '';
  position: absolute;
  top: 3px;
  left: 3px;
  right: auto;
  width: 16px;
  height: 16px;
  margin: 0;
  border: 0;
  border-radius: 50%;
  background: #fff;
  box-shadow: 0 1px 2px rgba(16, 24, 40, .2);
  transform: none;
  transition: transform .18s ease;
}

.dashboard input[type="checkbox"].toggle:checked {
  background: var(--accent);
}

.dashboard input[type="checkbox"].toggle:checked:before {
  transform: translateX(18px);
}

.dashboard input[type="checkbox"].toggle:focus-visible {
  outline: 2px solid var(--accent);
  outline-offset: 2px;
}

/* status radios */

.radio-row {
  display: flex !important;
  align-items: center !important;
  gap: 10px;
  margin: 0 0 8px !important;
  border: 1px solid var(--line);
  border-radius: var(--r-control);
  padding: 11px 14px;
  font-size: 14px;
  font-weight: 500;
  color: var(--ink-body);
  cursor: pointer;
  transition: border-color .15s ease, background .15s ease;
}

.radio-row:hover { border-color: #D1D5DB; background: var(--surface-sunken); }

.radio-row:last-of-type { margin-bottom: 0 !important; }

.dashboard .radio-row input[type="radio"] {
  appearance: none;
  -webkit-appearance: none;
  position: relative;
  width: 16px;
  height: 16px;
  min-height: 0;
  margin: 0 !important;
  padding: 0 !important;
  border: 1.5px solid #D1D5DB;
  border-radius: 50%;
  background: #fff;
  cursor: pointer;
  flex-shrink: 0;
  transition: border-color .15s ease;
}

.dashboard .radio-row input[type="radio"]:checked {
  border-color: var(--accent);
  border-width: 5px;
}

.status-category-dot {
  width: 8px;
  height: 8px;
  max-width: 8px;
  border-radius: 50%;
  display: inline-block;
  flex-shrink: 0;
}

.status-category-dot.green { background: #22C55E; }
.status-category-dot.orange { background: #F59E0B; }
.status-category-dot.red { background: #EF4444; }

/* visibility checkboxes */

.line-col {
  padding-top: 18px;
  border-top: 1px solid var(--line-soft);
  margin-top: 18px;
}

.dashboard .line-col label { margin-top: 0; }

.check-row {
  display: flex !important;
  align-items: center !important;
  gap: 10px !important;
  margin: 0 0 10px !important;
  font-size: 14px;
  cursor: pointer;
}

.dashboard .line-col.visiblity-cl label.check-row {
  font-weight: 400;
  color: var(--ink-body);
  margin-bottom: 10px !important;
}

.dashboard .line-col.visiblity-cl label.check-row:last-child { margin-bottom: 0 !important; }

.dashboard .line-col input[type="checkbox"] {
  appearance: none;
  -webkit-appearance: none;
  position: relative;
  width: 17px;
  height: 17px;
  min-height: 0;
  margin: 0 !important;
  padding: 0 !important;
  border: 1.5px solid #D1D5DB;
  border-radius: 5px;
  background: #fff;
  opacity: 1;
  cursor: pointer;
  flex-shrink: 0;
  transition: background .15s ease, border-color .15s ease;
}

.dashboard .line-col input[type="checkbox"]:checked {
  background: var(--accent);
  border-color: var(--accent);
}

.dashboard .line-col input[type="checkbox"]:before {
  content: '';
  position: absolute;
  left: 5px;
  top: 1.5px;
  right: auto;
  width: 4px;
  height: 9px;
  margin: 0;
  border: solid #fff;
  border-width: 0 2px 2px 0;
  transform: rotate(45deg);
  opacity: 0;
  transition: opacity .15s ease;
}

.dashboard .line-col input[type="checkbox"]:checked:before { opacity: 1; }

.line-col.sch-cl label { margin-bottom: 8px; }

/* what the schedule will do, under the field */

.schedule-note {
  display: flex;
  align-items: flex-start;
  gap: 6px;
  line-height: 1.5;
  /* .field-row is a label/counter pair and spreads its two children to the
     edges. This is one sentence with an icon in front of it, so the leftover
     space must go after the text, not between the icon and the first word -
     which pushed the message a ragged distance right, by a different amount
     for every message. */
  justify-content: flex-start;
}

.schedule-note i { line-height: 18px; flex: 0 0 auto; }

.schedule-note.ok { color: var(--ok); }
.schedule-note.warn { color: var(--warn); }

.dashboard .line-col.sch-cl input {
  width: 100%;
  height: 42px !important;
  min-height: 42px;
  color: var(--ink);
  padding: 0 14px;
}

/* ---- sidebar: quick stats ------------------------------------------- */

.stats-card h3,
.pro-tips-card h3,
.danger-card h3 {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 15px;
  font-weight: 700;
  color: var(--ink);
  margin: 0 0 14px;
}

.stats-card i {
  width: 32px;
  height: 32px;
  flex-shrink: 0;
  border-radius: 9px;
  background: #F3F4F6;
  color: var(--ink-body);
  font-size: 13px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.stat {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  padding: 9px 0;
  border-top: 1px solid var(--line-soft);
  margin-top: 0;
}

.stat:first-of-type { border-top: 0; padding-top: 0; }

.stat span {
  color: var(--ink-muted);
  font-weight: 400;
  font-size: 13px;
  line-height: 1.4;
}

.stat b {
  color: var(--ink);
  font-size: 15px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}

.stat-empty {
  font-size: 13px;
  color: var(--ink-muted);
  line-height: 1.5;
  margin: 0;
}

/* ---- sidebar: pro tips ---------------------------------------------- */

.pro-tips-card {
  background: var(--surface);
}

.pro-tips-card h3 i {
  width: 32px;
  height: 32px;
  flex-shrink: 0;
  border-radius: 9px;
  background: #FFFBEB;
  color: var(--warn);
  font-size: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.pro-tips-card ul {
  padding-left: 0;
  margin: 0;
  list-style-type: none;
}

.pro-tips-card li {
  margin-bottom: 9px;
  font-weight: 400;
  font-size: 12.5px;
  line-height: 1.5;
  color: var(--ink-muted);
  display: flex;
  align-items: flex-start;
  gap: 8px;
}

.pro-tips-card li:last-child { margin-bottom: 0; }

.pro-tips-card li i {
  position: relative;
  top: 3px;
  font-size: 10px;
  color: var(--ok);
  flex-shrink: 0;
}

/* ---- sidebar: record ------------------------------------------------ */

.card.activity-card .card-header {
  gap: 10px;
  align-items: center;
}

.card.activity-card .card-header i {
  color: var(--ink-muted);
  font-size: 14px;
}

.card.activity-card .card-header h3 { margin: 0; }

.activity-item {
  display: flex;
  gap: 12px;
  margin-top: 14px;
  align-items: flex-start;
}

.activity-item .activity-icon i {
  background: #F3F4F6;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
  color: var(--ink-body);
}

.activity-item .activity-icon.green i { background: #F0FDF4; color: var(--ok); }
.activity-item .activity-icon.blue i { background: #EEF2FF; color: #4F46E5; }
.activity-item .activity-icon.purple i { background: #F5F3FF; color: #7C3AED; }

.activity-item b {
  font-weight: 600;
  font-size: 13px;
  line-height: 1.4;
  color: var(--ink);
  display: block;
}

.activity-item p {
  font-size: 12px;
  color: var(--ink-muted);
  line-height: 1.5;
  margin: 2px 0 0;
}

/* ---- sidebar: danger zone ------------------------------------------- */

.danger-card {
  background: var(--surface);
  border: 1px solid #FECACA;
  box-shadow: none;
}

.danger-card h3 { color: #991B1B; }

.danger-card h3 i {
  width: 32px;
  height: 32px;
  flex-shrink: 0;
  border-radius: 9px;
  background: #FEF2F2;
  color: var(--danger);
  font-size: 13px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.danger-card p {
  color: var(--ink-muted);
  font-size: 13px;
  line-height: 1.55;
  margin: 0;
}

.danger-card strong { color: var(--ink-body); font-weight: 600; }

.delete-btn {
  width: 100%;
  background: var(--danger);
  border: none;
  border-radius: 999px;
  margin-top: 16px;
  cursor: pointer;
  height: 42px;
  color: #fff;
  font-size: 14px;
  font-weight: 600;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  transition: background .15s ease;
}

.delete-btn:hover:not(:disabled) { background: #B91C1C; }

.delete-btn:disabled {
  background: #FCA5A5;
  opacity: .7;
  cursor: not-allowed;
}

/* ---- parent picker (Dropdown component) ------------------------------ */

.dashboard .card .custom-dropdown {
  display: block;
  max-width: none;
  width: 100%;
  line-height: normal;
}

.dashboard .card .custom-dropdown >>> span {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  width: 100%;
  height: 42px;
  line-height: 40px;
  padding: 0 14px;
  border: 1px solid var(--line);
  border-radius: var(--r-control);
  font-size: 14px;
  color: var(--ink);
  background: var(--surface);
  min-width: 0;
}

.dashboard .card .custom-dropdown >>> .dropdown-inner {
  border-radius: var(--r-control);
}

/* ---- responsive ------------------------------------------------------ */

@media (max-width: 1280px) {
  .dashboard .container_dash { grid-template-columns: minmax(0, 1.7fr) minmax(280px, 1fr); }
}

@media (max-width: 1100px) {
  .dashboard .container_dash { grid-template-columns: minmax(0, 1fr); }
  .dashboard .right { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; align-items: start; }
}

@media (max-width: 760px) {
  .dashboard { padding: 0; }
  .dashboard .container_dash,
  .dashboard .left,
  .dashboard .right { gap: 16px; }
  .dashboard .right { grid-template-columns: minmax(0, 1fr); }
  .card,
  .stats-card,
  .pro-tips-card,
  .danger-card { padding: 18px; border-radius: 14px; }
  .card-header { gap: 10px; }
  .card-header h3 { font-size: 15px; }
  .icon-row { grid-template-columns: 1fr; }
  .upload-box,
  .current-icon { height: 170px; }
  .advanced-grid { grid-template-columns: 1fr; }
  .feature-box { padding: 13px 14px; }
  .feature-left { gap: 10px; }
  .cat-head__title { font-size: 16px; }
  .cat-head__sub { display: none; }
}

@media (max-width: 480px) {
  .gallery-upload,
  .gallery-item { width: 96px; height: 96px; }
  .gallery-grid { grid-template-columns: repeat(auto-fill, 96px); }
}

@media (prefers-reduced-motion: reduce) {
  .sk-field { animation: none; }
  .dashboard input[type="checkbox"].toggle,
  .dashboard input[type="checkbox"].toggle:before { transition: none; }
}
/* What the delete would affect. Passed into ConfirmDialog's slot, so it is
   styled here: Vue scopes slot content to the page, not to the component. */
.impact-box {
  background: #F9FAFB;
  border: 1px solid #F1F3F5;
  border-radius: 12px;
  padding: 14px 16px;
  margin: 0 0 14px;
  text-align: left;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.impact-line {
  display: flex;
  align-items: flex-start;
  gap: 9px;
  font-size: 13px;
  line-height: 1.5;
  color: #374151;
}

.impact-line i { margin-top: 3px; font-size: 12px; flex: 0 0 auto; }
.impact-line strong { font-weight: 600; color: #111827; }
.impact-line.warn i { color: #EA580C; }
.impact-line.ok i { color: #16A34A; }

.children-choice {
  border: 1px solid #E5E7EB;
  border-radius: 12px;
  padding: 12px 14px;
  margin: 0 0 14px;
  text-align: left;
}

.children-choice legend {
  font-size: 12px;
  font-weight: 700;
  color: #6B7280;
  text-transform: uppercase;
  letter-spacing: .04em;
  padding: 0 6px;
}

.children-choice label {
  display: flex;
  gap: 10px;
  align-items: flex-start;
  padding: 8px 4px;
  cursor: pointer;
}

.children-choice label span { display: flex; flex-direction: column; gap: 2px; }
.children-choice input[type="radio"] { accent-color: #33319a; margin-top: 2px; }
.children-choice strong { font-size: 14px; color: #111827; font-weight: 600; }
.children-choice em { font-style: normal; font-size: 12px; color: #6B7280; }

</style>