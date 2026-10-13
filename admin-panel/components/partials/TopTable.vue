<template>
  <div class="dash-table-wrap">
    <table class="dash-table" :class="wide ? 'mn-w-600x' : 'mn-w-400x'">
      <tr class="dash-head">
        <th>{{ $t('index.title') }}</th>
        <th>{{ $t('index.sold') }}</th>
        <th>{{ $t('index.amount') }}</th>
      </tr>

      <!-- v-for count rather than v-for + v-if: the two on one element is a
           Vue 2 anti-pattern, v-for wins and v-if then runs per row -->
      <tr v-for="n in (loading ? skeletonRows : 0)" :key="`sk-${n}`">
        <td>
          <div class="dash-cell">
            <skeleton-block width="26px" height="26px" radius="7px" />
            <skeleton-block width="44px" height="44px" radius="8px" />
            <skeleton-block :width="skeletonNameWidths[n - 1]" height="13px" />
          </div>
        </td>
        <td><skeleton-block width="28px" height="13px" /></td>
        <td class="dash-amount"><skeleton-block width="64px" height="13px" style="margin-left:auto" /></td>
      </tr>

      <tr v-if="!loading && !rows.length">
        <td colspan="3" class="dash-empty">{{ $t('index.nf') }}</td>
      </tr>

      <tr
        v-for="(value, index) in (loading ? [] : rows)"
        :key="value.id"
      >
        <td>
          <div class="dash-cell">
            <span class="dash-rank">{{ index + 1 }}</span>
            <nuxt-link
              :to="`${linkPrefix}/${value.id}`"
              class="dash-link"
            >
              <entity-thumb
                :image="value.image"
                :fallback-image="value.fallback_image"
                :title="value.title"
                size="44px"
              />
              <span class="dash-name" :title="value.title">{{ value.title }}</span>
            </nuxt-link>
          </div>
        </td>
        <td class="dash-sold">
          {{ value.total }}
        </td>
        <td class="dash-amount">
          {{ priceFormatting(value.total_price) }}
        </td>
      </tr>
    </table>
  </div>
</template>

<script>
  import { mapGetters } from 'vuex'
  import util from '~/mixin/util'
  import EntityThumb from '~/components/EntityThumb'
  import SkeletonBlock from '~/components/SkeletonBlock'

  /**
   * The top categories / brands / products tables were three near-identical
   * copies of the same markup. One component keeps them in step — notably the
   * thumbnail and the loading placeholder, which previously existed in none of
   * them consistently.
   */
  export default {
    name: 'TopTable',
    mixins: [util],
    components: {
      EntityThumb,
      SkeletonBlock
    },
    props: {
      rows: {
        type: Array,
        default: () => []
      },
      loading: {
        type: Boolean,
        default: false
      },
      linkPrefix: {
        type: String,
        required: true
      },
      wide: {
        type: Boolean,
        default: false
      }
    },
    data() {
      return {
        skeletonRows: 4,
        // Varied widths so the placeholder does not read as a real table
        skeletonNameWidths: ['62%', '45%', '71%', '38%']
      }
    },
    computed: {
      currencyPosition() {
        return this.setting?.currency_position
      },
      currencyIcon() {
        return this.setting?.currency_icon || '$'
      },
      ...mapGetters('setting', ['setting'])
    }
  }
</script>

<style lang="stylus">
  @import "~/assets/styles/top-table.styl"
</style>
