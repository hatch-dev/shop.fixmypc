<template>
  <div
    class="main-slider"
    :class="{'mn-h-500x': loading}"
  >
    <div
      v-if="loading"
      class="spinner-wrapper"
    >
      <spinner
        :radius="60"
        color="primary"
        class="mr-15"
      />
    </div>

    <transition
      name="fade"
      mode="out-in"
    >
    <div
      v-if="!loading"
    >
      <div class="left">
        <image-slider
          v-if="getDataFromObject(result, 'main.length')"
          :image-list="result.main"
          @delete="deleteItem($event)"
        />
        <div
          v-else
          class="btn-wrapper"
        >
          <nuxt-link
            v-if="$can('home_slider', 'create')"
            to="/home-slider/add?type=1"
            class="primary-btn button"
          >
           {{ $t('admin.addImg') }}
          </nuxt-link>
        </div>
      </div>
      <!--left-->
      <div
        class="right"
      >
        <div
          class="img-wrap"
        >
          <template
            v-if="result.right_top"
          >
            <img
              :src="getImageURL(result.right_top.image)"
              alt=""
            >
            <div class="btn-wrapper">
              <nuxt-link
                v-if="$can('home_slider', 'edit')"
                class="primary-btn button"
                :to="`/home-slider/${result.right_top.id}`"
              >
                {{ $t('category.edit') }}
              </nuxt-link>
              <button
                v-if="$can('home_slider', 'delete')"
                class="primary-btn"
                @click.prevent="deleteItem(result.right_top.id)"
              >
                {{ $t('category.delete') }}
              </button>
            </div>
          </template>

          <div
            v-else class="btn-wrapper"
          >
            <nuxt-link
              v-if="$can('home_slider', 'create')"
              to="/home-slider/add?type=2"
              class="primary-btn button"
            >
              {{ $t('admin.addImg') }}
            </nuxt-link>
          </div>
        </div>

        <div class="img-wrap">
          <template
            v-if="result.right_bottom"
          >
            <img
              :src="getImageURL(result.right_bottom.image)"
              alt=""
            >
            <div
              class="btn-wrapper"
            >
              <nuxt-link
                v-if="$can('home_slider', 'edit')"
                class="primary-btn button"
                :to="`/home-slider/${result.right_bottom.id}`"
              >
                {{ $t('category.edit') }}
              </nuxt-link>
              <button
                v-if="$can('home_slider', 'delete')"
                class="primary-btn"
                @click.prevent="deleteItem(result.right_bottom.id)"
              >
                {{ $t('category.delete') }}
              </button>
            </div>
          </template>
          <div v-else class="btn-wrapper">
            <nuxt-link
              v-if="$can('home_slider', 'create')"
              to="/home-slider/add?type=3"
              class="primary-btn button"
            >
              {{ $t('admin.addImg') }}
            </nuxt-link>
          </div>
        </div>
      </div>
      <!--right-->
    </div>
    </transition>
  </div>

  <!--main-slider-->
</template>

<script>
  import ListPage from "~/components/partials/ListPage"
  import util from '~/mixin/util'
  import ImageSlider from "~/components/partials/ImageSlider"
  import outsideClick from "~/directives/outside-click"
  import {mapActions} from 'vuex'
  import Spinner from "~/components/Spinner";

  export default {
    name: "home-sliders",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        listApi: 'getSliderImages',
        deleteApi: 'deleteSliderImage',
        routeName: 'home-slider',
        loading: false,
        result:{
          main: null,
          right_top: null,
          right_bottom: null
        }
      }
    },
    directives: {outsideClick},
    components: {
      Spinner,
      ImageSlider,
      ListPage
    },
    mixins: [util],
    computed: {
    },
    methods: {
      async fetchingData() {
        try {
          this.loading = true
          this.result = await this.getRequest({params: { }, api: this.listApi })
          this.loading = false
        } catch (e) {
          return this.$nuxt.error(e)
        }
      },
      editItem(id) {
        return this.$router.push(`/${this.routeName}/${id}`)
      },
      async deleteItem(id) {
        if (confirm(this.$t('admin.dltMsg'))) {
          try {
            this.loading = true
            await this.deleteData({params: id, api: this.deleteApi })
            this.loading = false
            await this.fetchingData()
          }catch (e) {
            return this.$nuxt.error(e)
          }
        }
      },
      ...mapActions('common', ['deleteData', 'getRequest'])
    },
    async mounted() {
      this.loading = true
      try {
        await this.fetchingData()
      } catch (e) {
        return this.$nuxt.error(e)
      }
      this.loading = false
    }
  }
</script>

<style lang="stylus">
  @import "~/assets/styles/slider.styl"
</style>
