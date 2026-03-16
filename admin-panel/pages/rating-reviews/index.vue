<template>
  <div class="rating-review-page">
    <!-- Add Review Button -->
    <div class="page-actions">
      <button class="add-review-btn primary-btn" @click="toggleReviewForm">
        {{ showReviewForm ? 'Cancel' : 'Add Review' }}
      </button>
    </div>

    <!-- List Page Component -->
    <list-page
      ref="listPage"
      list-api="getRatingReviews"
      delete-api="deleteRatingReview"
      route-name="rating-reviews"
      :name="$t('fSale.rr')"
      gate="rating_review"
      class="rating-review-wrapper"
      :add-button="false"
      :order-options="ratingReviewObj"
      @delete-bulk="deleteBulk"
      @list="itemList = $event"
    >
      <template v-slot:table="{list}">
        <tr class="lite-bold">
          <th>
            <input type="checkbox" @change="checkAll">
          </th>
          <th>{{ $t('fSale.customer') }}</th>
          <th>{{ $t('fSale.rating') }}</th>
          <th>{{ $t('fSale.review') }}</th>
          <th class="mn-w-130x">{{ $t('Source') }}</th>
          <th>{{ $t('fSale.product') }}</th>
          <th>{{ $t('category.created') }}</th>
          <th>&nbsp;</th>
        </tr>

        <tr v-for="(value, index) in list" :key="index">
          <td>
            <input type="checkbox" :value="value.id" v-model="cbList">
          </td>
          <td><span>{{ customerName(value) }}</span></td>
          <td><span>{{ value.rating }}</span></td>
          <td><span>{{ value.review }}</span></td>
          <td><span>{{ value.source }}</span></td>
          <td>
            <nuxt-link class="ellipsis mx-w-200x link" :to="`products/${value.product.id}`">
              {{ value.product.title }}
            </nuxt-link>
          </td>
          <td>{{ value.created }}</td>
          <td>
            <button
              v-if="$can('rating_review', 'delete')"
              @click.prevent="$refs.listPage.deleteItem(value.id)"
              class="delete-btn lite-btn"
            >
              {{ $t('category.delete') }}
            </button>
            <button
              v-if="$can('rating_review', 'delete')"
              @click.prevent="editReview(value)"
              class="edit_review lite-btn"
            >
              {{ $t('category.edit') }}
            </button>
          </td>
        </tr>
      </template>
    </list-page>

    <!-- Review Form -->
    <div v-if="showReviewForm" class="review-form-container" ref="reviewFormContainer">
      <div class="review-form-inline">
        <h3>{{ reviewForm.id ? 'Edit Review' : 'Add New Review' }}</h3>
        
        <form class="review-form" @submit.prevent="submitReview">
          <input type="hidden" name="review_id" id="review_id" v-model="reviewForm.id">
          
          <!-- Star Rating -->
          <div class="star-rating">
            <span
              v-for="star in 5"
              :key="star"
              :class="{ 
                active: star <= currentRating || (star <= hoverRating && currentRating === 0) 
              }"
              @click="setRating(star)"
              @mouseover="setHoverRating(star)"
              @mouseout="clearHoverRating"
            >
              ★
            </span>
          </div>
          
          <!-- Form Fields -->
          <div class="form-row">
            <div class="form-group">
              <label>Source <span class="required">*</span></label>
              <select v-model="reviewForm.source" @change="clearError('source')">
                <option value="">Select Source</option>
                <option value="google">Google</option>
                <option value="facebook">Facebook</option>
                <option value="shop">Shop</option>
              </select>
              <div v-if="formErrors.source" class="error-message">{{ formErrors.source }}</div>
            </div>
          </div>
          
          <div class="form-row">
            <div class="form-group">
              <label>Customer Name <span class="required">*</span></label>
              <input
                type="text"
                v-model="reviewForm.name"
                @input="clearError('name')"
              >
              <div v-if="formErrors.name" class="error-message">{{ formErrors.name }}</div>
            </div>
          </div>
          
          <div class="form-group">
            <label>Product <span class="required">*</span></label>
            <select v-model="reviewForm.product_id" @change="clearError('product_id')">
              <option value="">Select Product</option>
              <option v-for="product in products" :key="product.id" :value="product.id">
                {{ product.title }}
              </option>
            </select>
            <div v-if="formErrors.product_id" class="error-message">{{ formErrors.product_id }}</div>
          </div>
          
          <div class="form-group">
            <label>Review <span class="required">*</span></label>
            <textarea
              v-model="reviewForm.review"
              rows="4"
              @input="clearError('review')"
            ></textarea>
            <div v-if="formErrors.review" class="error-message">{{ formErrors.review }}</div>
          </div>
          
          <div class="form-row">
            <div class="checkbox-group">
              <input
                type="checkbox"
                id="is_verified"
                v-model="reviewForm.is_verified"
              >
              <label for="is_verified">Verified Purchase</label>
            </div>
          </div>
          
          <div class="form-actions">
            <button type="button" class="cancel-btn primary-btn" @click="toggleReviewForm">
              Cancel
            </button>
            <button type="submit" class="submit-btn primary-btn">
              {{ reviewForm.id ? 'Update Review' : 'Submit Review' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script>
import ListPage from "~/components/partials/ListPage";
import util from '~/mixin/util'
import LazyImage from "~/components/LazyImage";
import bulkDelete from "~/mixin/bulkDelete";

export default {
  name: "rating-reviews",
  middleware: ['common-middleware', 'auth'],
  data() {
    return {
      ratingReviewObj: {
        user_id: { title: this.$t('fSale.user') },
        created_at: { title: this.$t('category.date') },
        rating: { title: this.$t('fSale.rating') },
        product_id: { title: this.$t('fSale.product') },
      },
      showReviewForm: false,
      currentRating: 0,
      hoverRating: 0,
      products: [],
      reviewForm: {
        id: null,
        name: '',
        source: 'shop',
        product_id: '',
        review: '',
        is_verified: false,
        rating: 0
      },
      formErrors: {},
      itemList: [],
      cbList: []
    }
  },
  components: {
    LazyImage,
    ListPage
  },
  mixins: [util, bulkDelete],
  computed: {},
  methods: {
    userName(review) {
      return review?.user?.name || review?.guest_user?.name || "";
    },
    customerName(review) {
      return review?.user?.name || review?.guest_user?.name || "";
    },
    toggleReviewForm() {
      this.showReviewForm = !this.showReviewForm;
      if (this.showReviewForm) {
        this.resetForm();
        this.loadProducts();
        
        // Scroll to form after it's rendered
        this.$nextTick(() => {
          if (this.$refs.reviewFormContainer) {
            this.$refs.reviewFormContainer.scrollIntoView({
              behavior: 'smooth',
              block: 'start'
            });
          }
        });
      }
    },
    resetForm() {
      this.reviewForm = {
        id: null,
        name: '',
        source: 'shop',
        product_id: '',
        review: '',
        is_verified: false,
        rating: 0
      };
      this.currentRating = 0;
      this.formErrors = {};
    },
    async loadProducts() {
      try {
        const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/';
        const response = await this.$axios.get('${baseUrl}api/products/all-simple');
        this.products = response.data.data || [];
      } catch (error) {
        console.error('Error loading products:', error);
      }
    },
    setRating(rating) {
      this.currentRating = rating;
      this.reviewForm.rating = rating;
      this.hoverRating = 0; // Clear hover state after selection
      this.clearError('rating');
    },
    setHoverRating(rating) {
      if (this.currentRating === 0) {
        this.hoverRating = rating;
      }
    },
    clearHoverRating() {
      this.hoverRating = 0;
    },
    validateForm() {
      let isValid = true;
      this.formErrors = {};
      
      if (this.reviewForm.rating <= 0) {
        this.formErrors.rating = 'Please select a rating';
        isValid = false;
      }
      
      if (!this.reviewForm.name.trim()) {
        this.formErrors.name = 'Please enter customer name';
        isValid = false;
      }
      
      if (!this.reviewForm.source) {
        this.formErrors.source = 'Please select a source';
        isValid = false;
      }
      
      if (!this.reviewForm.product_id) {
        this.formErrors.product_id = 'Please select a product';
        isValid = false;
      }
      
      if (!this.reviewForm.review.trim()) {
        this.formErrors.review = 'Please enter your review';
        isValid = false;
      }
      
      return isValid;
    },
    clearError(field) {
      if (this.formErrors[field]) {
        delete this.formErrors[field];
      }
    },
    async submitReview() {
      if (!this.validateForm()) return;
      
      try {
        const formData = new FormData();
        formData.append('name', this.reviewForm.name);
        formData.append('source', this.reviewForm.source);
        formData.append('product_id', this.reviewForm.product_id);
        formData.append('review', this.reviewForm.review);
        formData.append('is_verified', this.reviewForm.is_verified ? 1 : 0);
        formData.append('rating', this.reviewForm.rating);
        
        if (this.reviewForm.id) {
          formData.append('review_id', this.reviewForm.id);
        }
        const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/';
          
        const url = this.reviewForm.id 
          ? '${baseUrl}api/v1/rating-review/update'
          : '${baseUrl}api/v1/rating-review/store';
        
        const response = await this.$axios.post(url, formData, {
          headers: {
            'Content-Type': 'multipart/form-data'
          }
        });
        
        // Show success message and reload
        alert(
          this.reviewForm.id 
            ? 'Review updated successfully!'
            : 'Review added successfully!'
        );
        setTimeout(() => {
          location.reload();
        }, 3000);
        
      } catch (error) {
        console.error('Error submitting review:', error);
      }
    },
    async editReview(review) {
      this.showReviewForm = true;
      this.reviewForm = {
        id: review.id,
        name: review.name,
        source: review.source,
        product_id: review.product_id,
        review: review.review,
        is_verified: review.is_verified,
        rating: review.rating
      };
      this.currentRating = review.rating;
      
      if (this.products.length === 0) {
        await this.loadProducts();
      }

      // Scroll to form after it's rendered
      this.$nextTick(() => {
        if (this.$refs.reviewFormContainer) {
          this.$refs.reviewFormContainer.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
          });
        }
      });
    },
    checkAll(event) {
      if (event.target.checked) {
        this.cbList = this.itemList.map(item => item.id);
      } else {
        this.cbList = [];
      }
    },
    deleteBulk() {
      if (this.cbList.length) {
        this.$refs.listPage.deleteItem(this.cbList.join(","));
      }
    }
  },
  mounted() {
    // Add CSS styles dynamically
    const style = document.createElement('style');
    style.textContent = `
      .page-actions {
        float: right;
        margin-top: 8px;
      }
      .review-form-container {
        margin-bottom: 30px;
        background: #fff;
        padding: 20px;
        border-radius: 8px;
        border: 1px solid #eee;
        margin-top: 15px;
      }
      
      .form-row {
        display: flex;
        gap: 20px;
        margin-bottom: 15px;
      }
      
      .form-row .form-group {
        flex: 1;
      }
      
      .star-rating {
        font-size: 24px;
        margin: 5px 0;
      }
      
      .star-rating span {
        color: #ccc;
        cursor: pointer;
        margin-right: 5px;
      }
      
      .star-rating span.active,
      .star-rating span:hover {
        color: gold;
      }
      
      .form-group {
        margin-bottom: 15px;
      }
      
      .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 500;
      }
      
      .form-group input[type="text"],
      .form-group select,
      .form-group textarea {
        width: 100%;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 14px;
      }
      
      .form-group select {
        height: 40px;
      }
      
      .form-group textarea {
        min-height: 100px;
      }
      
      .checkbox-group {
        display: flex;
        align-items: center;
        padding-top: 25px;
      }
      
      .checkbox-group input {
        margin-right: 10px;
      }
      
      .form-actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        margin-top: 20px;
      }
      
      .submit-btn {
        background: #42b983;
        color: white;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        padding: 8px 15px;
      }
      
      .cancel-btn {
        background: #f5f5f5;
        border: 1px solid #ddd;
        border-radius: 4px;
        cursor: pointer;
        padding: 8px 15px;
      }
      
      .required {
        color: red;
      }
      
      .error-message {
        font-size: 12px;
        margin-top: 5px;
        color: red;
      }
      
      .add-review-btn {
        background: #42b983;
        color: white;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        padding: 8px 15px;
        margin-left: 10px;
      }
    `;
    document.head.appendChild(style);
  }
}
</script>

<style lang="stylus">
.rating-review-wrapper
  table
    min-width 800px
</style>