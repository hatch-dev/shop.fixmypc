<template>
  <div class="side-block">
    <h3>
      {{ $t('productReview.customerReviews') }}
      <button
        type="button"
        :disabled="!rating"
        :aria-label="$t('ui.filterRatingUi.aria.submit')"
        @click.prevent="filterRating(0)"
      >
        {{ $t('listingLayout.clear') }}
      </button>
    </h3>

    <label
      class="f-row"
      v-for="value in ratingSteps"
      :key="value"
    >
      <input
        type="radio"
        name="rate"
        :value="value"
        :checked="parseInt(rating) === value"
        @change="filterRating(value)"
      >
      <span class="stars">{{ filledStars(value) }}<span class="off">{{ emptyStars(value) }}</span></span>
      &amp; {{ $t('listingLayout.up') }}
    </label>
  </div><!--side-block-->
</template>

<script>


    export default {
      name: "FilterRating",
      props: {

      },
      data() {
        return {
          rating: this.$route.query.rating || '',
          ratingSteps: [4, 3, 2, 1],
        }
      },
      components: {
      },
      mixins: [],
      computed: {

      },
      mounted() {


      },
      methods: {
        filledStars(value) {
          return '★'.repeat(value)
        },
        emptyStars(value) {
          return '★'.repeat(5 - value)
        },
        clearRating() {
          this.rating = ''
        },
        filterRating(rating) {
          let filtered = Object.assign({}, this.$route.query)
          this.rating = rating
          if (parseInt(rating) > 0 && parseInt(rating) <= 5) {
            filtered = {...filtered, ...{rating: rating}}
          } else {
            delete filtered.rating
          }

          this.$emit('reset-route', filtered)
        },
      },
    }
</script>

<style scoped>

</style>
