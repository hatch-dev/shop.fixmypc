<template>
  <pop-over
    title="Cancellation reason"
    @close="$emit('close')"
    elem-id="rate-pop-over"
    :layer="true"
    class="rating-popup popup-top-auto"
  >
    <template v-slot:content>
      <div
        class="pos-rel"
        :class="{'mn-h-120x': loading}"
      >
        <div
          v-if="loading"
          class="spinner-wrapper"
        >
          <spinner
            :radius="40"
            color="primary"
          />
        </div>
        <h4 class="mb-10">{{ result.title }}</h4>
        <p>{{ result.message }}</p>
      </div>

    </template>
    <template
      v-slot:pop-footer
    >
      <div class="right-text">
        <button
          class="outline-btn"
          @click.prevent="$emit('close')"
        >
          {{ $t('setting.dismiss')  }}
        </button>
      </div>

    </template>
  </pop-over>

</template>

<script>

  import util from "~/mixin/util";
  import {mapActions} from "vuex";
  import Spinner from "~/components/Spinner";
  import PopOver from "~/components/PopOver";

  export default {
    name: 'Cancellation',
    data() {
      return {
        result: {
          title: '',
          message: '',
        },
        loading: true
      }
    },
    props: {},
    mixins: [util],
    components: {Spinner, PopOver},
    computed: {
      orderId() {
        return this.$route?.params?.id
      }
    },
    methods: {
      async fetchingData() {
        this.loading = true
        try {
          this.result = await this.getById({id: this.orderId, params: {}, api: 'getOderCancellationMessage' })
        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.loading = false
      },
      ...mapActions('common', ['getById'])
    },
    mounted() {
      this.fetchingData()
    }
  }
</script>

