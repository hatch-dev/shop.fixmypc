<template>
  <div class="accordion" id="customAccordion">
    <!-- Reviews -->
    <div class="accordion-item mb-3">
      <h2 class="accordion-header">
        <button
          class="accordion-button"
          :class="{ collapsed: activeAccordion !== 'review' }"
          @click="toggleAccordion('review')"
        >
          {{ $t('ui.productAccordionsUi.reviews') }}
        </button>
      </h2>
      <div
        class="accordion-collapse collapse"
        :class="{ show: activeAccordion === 'review' }"
      >
        <div class="accordion-body">
          <product-review
            ref="productReview"
            :id="product.id"
            @has-review="$emit('has-review', $event)"
          />
        </div>
      </div>
    </div>

    <!-- Static Payment Gateway Accordion -->
    <div class="accordion-item mb-3">
      <h2 class="accordion-header">
        <button
          class="accordion-button"
          :class="{ collapsed: activeAccordion !== 'payment' }"
          @click="toggleAccordion('payment')"
        >
          {{ $t('ui.productAccordionsUi.paymentOptions') }}
        </button>
      </h2>

      <div
        class="accordion-collapse collapse"
        :class="{ show: activeAccordion === 'payment' }"
      >
        <div class="accordion-body">
          <client-only>
            <!--
              A list of what we accept, not a picker. Nothing on this page
              reads the choice, and the panel's default method is Cash: as
              a picker it "chose" Cash for the shopper, then withdrew it
              the moment Add to basket made this a delivery order, with a
              "Cash is only available on Collection Store orders" notice
              over a question nobody had been asked.
            -->
            <payment-gateways
              ref="paymentGateways"
              :total-price="Number(totalPrice || 0)"
              :voucher="selectedVoucher"
              :hide-confirm-btn="true"
              :readonly="true"
            />
          </client-only>
        </div>
      </div>
    </div>

    <div
      v-if="product.customizations && product.customizations.length"
      v-for="(customization, index) in product.customizations"
      :key="customization.id"
      class="accordion-item mb-3"
    >
      <h2 class="accordion-header">
        <button
          class="accordion-button"
          :class="{ collapsed: activeAccordion !== index }"
          @click="toggleAccordion(index)"
        >
          {{ customization.template?.title || $t('ui.productAccordionsUi.templateNumber', { id: customization.template_id }) }}
        </button>
      </h2>

      <div
        class="accordion-collapse collapse"
        :class="{ show: activeAccordion === index }"
      >
        <div
          class="accordion-body"
          v-dompurify-html="customization.custom_content"
        ></div>
      </div>
    </div>
  </div>
</template>

<script>
  /**
   * The product detail accordions - reviews, payment options and any
   * customisation templates.
   *
   * They live in their own component because the two layouts want them in
   * different places: under the gallery on the desktop reference, and after
   * the specifications table on the phone. One instance renders at a time,
   * chosen by the page, so the markup is not duplicated.
   */
  import ProductReview from '~/components/ProductReview'
  import PaymentGateways from '~/components/PaymentGateways'

  export default {
    name: 'ProductAccordions',
    components: {
      ProductReview,
      PaymentGateways
    },
    props: {
      product: {
        type: Object,
        required: true
      },
      totalPrice: {
        type: [String, Number],
        default: 0
      },
      selectedVoucher: {
        type: Object,
        default: null
      }
    },
    data() {
      return {
        activeAccordion: 'review'
      }
    },
    methods: {
      toggleAccordion(key) {
        this.activeAccordion =
          this.activeAccordion === key ? null : key
      }
    }
  }
</script>
