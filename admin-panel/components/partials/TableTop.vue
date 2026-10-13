<template>
  <div :class="{
    'hidden': ($route.path === '/bulk-product-editor' || $route.path === '/bulk-product-editor/')
  }" class="table-top filter-toolbar">
    <div class="filter-toolbar__inner">

      <div class="filter-toolbar__filters">
        <slot name="extra-filters" />

        <table-sort
          class="filter-toolbar__sort"
          :order-by-options="orderByOptions"
        />

        <inline-pop-over
          :arrow="true"
          class="bulk-action"
          ref="bulkDelete"
        >
          <template
            v-slot:button
          >
            {{ $t('title.act') }}
          </template>
          <template
            v-slot:content
          >
            <button @click.prevent="deleteBulk" class="outline-btn">
              {{ $t('category.delete') }}
            </button>
          </template>
        </inline-pop-over>
      </div>

      <div
        v-if="enableSearch"
        class="filter-toolbar__right"
      >
        <form
          class="search-input"
          @submit.prevent="makeSearch"
        >
          <span class="search-input__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="11" cy="11" r="7" />
              <line x1="21" y1="21" x2="16.65" y2="16.65" />
            </svg>
          </span>
          <input
            ref="searchInput"
            type="text"
            :placeholder="$t('list.sh')"
            v-model="search"
            @input="onSearchInput"
          >
        </form>

        <div class="filter-toolbar__add">
          <slot name="add-button" />
          <nuxt-link
            v-if="!hasCustomAdd && title && addButton"
            :to="addRoute"
            class="button primary-btn"
          >
            <span v-html="$t('profile.add', { title: title})"></span>
          </nuxt-link>
        </div>
      </div>

    </div>
  </div>
</template>

<script>
  import tableHelper from '~/mixin/tableHelper'
  import Dropdown from "~/components/Dropdown"
  import TableSort from "~/components/partials/TableSort"
  import InlinePopOver from "../InlinePopOver";

  export default {
    name: 'TableTop',
    data() {
      return {
        searchDebounce: null
      }
    },
    props: {
      addButton: {
        type: Boolean,
        default: true
      },
      hasCustomAdd: {
        type: Boolean,
        default: false
      },
      enableSearch: {
        type: Boolean,
        default: true
      },
      resetRoute: {
        type: Boolean,
        default: true
      },
      title: {
        type: String,
        default: null
      },
      gate: {
        type: String,
        default: null
      },
      orderByOptions: {
        type: Object,
        default() {
          return null
        }
      }
    },
    mixins: [tableHelper],
    components: {
      InlinePopOver,
      Dropdown,
      TableSort
    },
    computed: {
      addRoute(){
        // Removing the trailing slash
        return `${this.$route.path.replace(/\/$/, "")}/add`
      }
    },
    methods: {
      onSearchInput() {
        clearTimeout(this.searchDebounce)
        this.searchDebounce = setTimeout(() => {
          this.makeSearch()
        }, 400)
      },
      deleteBulk(){
        this.$refs.bulkDelete.closePop()
        this.$emit('delete-bulk')
      }
    },
    beforeCreate() {
    },
    destroyed() {
    },
    created() {
    },
    mounted() {
      this.$nextTick(() => {
        if (this.search) {
          this.$refs.searchInput?.focus()
        }
      })
    },
    watch: {
      '$route.query.q'() {
        this.$nextTick(() => {
          if (this.search) {
            this.$refs.searchInput?.focus()
          }
        })
      }
    }
  }
</script>

<style>
.hidden {
  display: none;
}</style>

