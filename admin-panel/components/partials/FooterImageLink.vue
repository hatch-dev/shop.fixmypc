<template>
  <div>
    <div class="dply-felx">
      <h4>{{ $t('setting.pl') }}</h4>
      <nuxt-link
        v-if="$can('footer_link', 'create')"
        to="/footer-links/add?type=1"
        class="lite-btn button"
      >
        {{ $t('setting.addLink') }}
      </nuxt-link>
    </div>
    <div>
      <div class="table-wrapper mt-0 mb-15">
        <table
          v-if="result.payment_links.length"
          class="mtb-15"
        >
          <tr class="lite-bold">
            <th>{{ $t('index.title') }}</th>
            <th>{{ $t('category.status') }}</th>
            <th />
          </tr>

          <tr
            v-for="(value, index) in result.payment_links"
            :key="index"
          >
            <td>
              <nuxt-link
                :to="`/footer-links/${value.id}?type=1/`"
                class="dply-felx j-left link"
              >
                <lazy-image
                  class="mr-20 mn-w-30x w-30x"
                  :data-src="getThumbImageURL(value.image)"
                  :alt="value.title"
                />
                <h5 class="mx-w-300x">{{ value.title }}</h5>
              </nuxt-link>
            </td>
            <td
              class="status"
              :class="{active: value.status == 1 }"
            >
              <span>{{ getStatus(value.status) }}</span>
            </td>
            <td class="right-text">
              <nuxt-link
                v-if="$can('footer_link', 'edit')"
                :to="`/footer-links/${value.id}?type=1`"
                class="edit-btn lite-btn button"
              >
                <i
                  class="edit-icon icon"
                />
              </nuxt-link>
              <button
                v-if="$can('footer_link', 'delete')"
                @click.prevent="$emit('delete-item', value.id)"
                class="delete-btn lite-btn"
              >
                <i
                  class="delete-icon icon"
                />
              </button>
            </td>
          </tr>
        </table><!--table-->
        <p v-else>
          {{ $t('setting.noData') }}
        </p>
      </div>
    </div>


    <div class="dply-felx">
      <h4>{{ $t('setting.sl') }}</h4>
      <nuxt-link
        v-if="$can('footer_link', 'create')"
        to="/footer-links/add?type=2"
        class="lite-btn button"
      >
        {{ $t('setting.addLink') }}
      </nuxt-link>
    </div>

    <div
      class="table-wrapper mt-0"
    >
      <table
        v-if="result.social_links.length"
        class="mtb-15"
      >
        <tr class="lite-bold">
          <th>{{ $t('index.title') }}</th>
          <th>{{ $t('category.status') }}</th>
          <th />
        </tr>

        <tr
          v-for="(value, index) in result.social_links"
          :key="index"
        >
          <td>
            <nuxt-link
              :to="`/footer-links/${value.id}?type=2/`"
              class="dply-felx j-left link"
            >

              <lazy-image
                class="mr-20 mn-w-30x w-30x"
                :data-src="getThumbImageURL(value.image)"
                :alt="value.title"
              />
              <h5 class="mx-w-300x">{{ value.title }}</h5>
            </nuxt-link>
          </td>
          <td
            class="status"
            :class="{active: value.status == 1 }"
          >
            <span>{{ getStatus(value.status) }}</span>
          </td>
          <td class="right-text">
            <nuxt-link
              v-if="$can('footer_link', 'edit')"
              :to="`/footer-links/${value.id}?type=2`"
              class="edit-btn lite-btn button"
            >
              <i
                class="edit-icon icon"
              />
            </nuxt-link>
            <button
              v-if="$can('footer_link', 'delete')"
              @click.prevent="$emit('delete-item', value.id)"
              class="delete-btn lite-btn"
            >
              <i
                class="delete-icon icon"
              />
            </button>
          </td>
        </tr>
      </table><!--table-->
      <p v-else>
        {{ $t('setting.noData') }}
      </p>
    </div>
  </div>
</template>

<script>
  import util from '~/mixin/util'
  import LazyImage from "~/components/LazyImage";

  export default {
    name: 'FooterImageLink',
    data() {
      return {
        deleting: false
      }
    },
    props: {
      result: {
        type: Object,
        default() {
          return {
            payment_links: [],
            social_links: []
          }
        }
      }
    },
    mixins: [util],
    components: {LazyImage},
    computed: {
    },
    methods: {
    },
    mounted() {
    }
  }
</script>

