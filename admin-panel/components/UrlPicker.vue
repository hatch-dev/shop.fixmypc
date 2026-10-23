<template>

  <div
    class="up"
    :class="{ open }"
    @keydown.esc.stop="close"
  >

    <div class="up-field">

      <input
        ref="input"
        :value="value"
        type="text"
        :placeholder="placeholder"
        autocomplete="off"
        spellcheck="false"
        @input="onType($event.target.value)"
        @focus="openMenu"
        @blur="onBlur"
        @keydown.down.prevent="step(1)"
        @keydown.up.prevent="step(-1)"
        @keydown.enter.prevent="commit"
      >

      <button
        type="button"
        class="up-caret"
        tabindex="-1"
        :aria-label="open ? $t('cmp.UrlPicker.hidePages') : $t('cmp.UrlPicker.showAllPages')"
        @mousedown.prevent="toggle"
      >
        <svg
          width="12"
          height="8"
          viewBox="0 0 12 8"
          aria-hidden="true"
        >
          <path
            d="M1 1l5 5 5-5"
            fill="none"
            stroke="currentColor"
            stroke-width="1.8"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
        </svg>
      </button>

    </div>

    <div
      v-if="open"
      class="up-menu"
    >

      <!-- Anything typed can be used as-is, so a custom link is never blocked. -->
      <button
        v-if="showCustom"
        type="button"
        class="up-row up-custom"
        :class="{ on: cursor === -1 }"
        @mousedown.prevent="choose(value)"
        @mouseenter="cursor = -1"
      >
        <span class="up-row-label">{{ $t('cmp.UrlPicker.useThisAddress') }}</span>
        <span class="up-row-url">{{ value }}</span>
      </button>

      <p
        v-if="!options.length"
        class="up-none"
      >
        {{ $t('cmp.UrlPicker.listNotLoaded') }}
      </p>

      <p
        v-else-if="!matches.length"
        class="up-none"
      >
        {{ $t('cmp.UrlPicker.noMatch') }}
      </p>

      <template v-for="group in grouped">

        <p
          :key="`g-${group.group}`"
          class="up-group"
        >
          {{ group.group }}
        </p>

        <button
          v-for="item in group.items"
          :key="item.url"
          type="button"
          class="up-row"
          :class="{
            on: matches[cursor] && matches[cursor].url === item.url,
            current: item.url === value
          }"
          @mousedown.prevent="choose(item.url)"
          @mouseenter="cursor = matches.indexOf(item)"
        >
          <span class="up-row-label">{{ item.label }}</span>
          <span class="up-row-url">{{ item.url }}</span>
        </button>

      </template>

    </div>

  </div>

</template>

<script>

  export default {

    name: "UrlPicker",

    props: {

      value: {
        type: String,
        default: ''
      },

      /** [{group, items: [{label, url}]}] straight from the links endpoint. */
      options: {
        type: Array,
        default: () => []
      },

      placeholder: {
        type: String,
        default: ''
      }
    },

    data() {
      return {
        open: false,
        cursor: 0,
        /*
         * Filtering only starts once the admin types. Opening the menu on a
         * banner that already has a link saved should still list every page,
         * not just the ones matching what is already in the box.
         */
        typing: false
      }
    },

    computed: {

      /** Flattened, so arrow keys can walk the list across group headings. */
      matches() {

        const q = this.typing ? (this.value || '').trim().toLowerCase() : ''

        return this.options.reduce((all, group) => {

          const items = (group.items || []).filter(i =>
            !q
            || i.url.toLowerCase().includes(q)
            || String(i.label).toLowerCase().includes(q))

          return all.concat(items.map(i => ({...i, group: group.group})))

        }, [])
      },

      /** The same matches, back in their groups, empty groups dropped. */
      grouped() {

        return this.options
          .map(group => ({
            group: group.group,
            items: this.matches.filter(i => i.group === group.group)
          }))
          .filter(group => group.items.length)
      },

      /** Only offered while typing something that is not already a listed page. */
      showCustom() {

        const typed = (this.value || '').trim()

        return this.typing && !!typed && !this.matches.some(i => i.url === typed)
      }
    },

    methods: {

      openMenu() {
        this.open = true
        this.typing = false
        this.cursor = 0
      },

      toggle() {

        if (this.open) {
          this.close()
          return
        }

        this.openMenu()
        this.$refs.input.focus()
      },

      onType(next) {
        this.$emit('input', next)
        this.open = true
        this.typing = true
        this.cursor = 0
      },

      choose(url) {
        this.$emit('input', url)
        this.close()
      },

      commit() {

        const picked = this.matches[this.cursor]

        this.choose(picked ? picked.url : this.value)
      },

      step(by) {

        if (!this.open) {
          this.openMenu()
          return
        }

        const first = this.showCustom ? -1 : 0
        const last = this.matches.length - 1
        const next = this.cursor + by

        this.cursor = next < first ? last : (next > last ? first : next)
      },

      onBlur() {
        // Let a mousedown on a row land before the menu goes away.
        setTimeout(this.close, 120)
      },

      close() {
        this.open = false
        this.typing = false
        this.cursor = 0
      }
    }
  }
</script>

<style lang="stylus">

$ink = #16174A
$brand = #2D2DA0
$brand-50 = #F1F1FC
$line = #E4E5F3
$line-strong = #CFD1E8
$muted = #6B7191

.up
  position relative

.up-field
  position relative

  input
    padding-right 36px

.up-caret
  position absolute
  top 0
  right 0
  width 34px
  height 100%
  padding 0
  display flex
  align-items center
  justify-content center
  background none
  border 0
  border-radius 0 8px 8px 0
  color $muted
  cursor pointer
  transition transform 0.15s

  &:hover
    color $brand

.up.open .up-caret
  transform rotate(180deg)

.up-menu
  position absolute
  z-index 20
  top calc(100% + 6px)
  left 0
  right 0
  max-height 300px
  overflow-y auto
  background #fff
  border 1px solid $line-strong
  border-radius 10px
  box-shadow 0 12px 34px rgba(22, 23, 74, 0.14)
  padding 6px

.up-group
  font-size 10.5px
  letter-spacing 0.12em
  text-transform uppercase
  color $muted
  font-weight 800
  margin 8px 0 4px
  padding 0 8px

.up-row
  display flex
  align-items baseline
  justify-content space-between
  gap 12px
  width 100%
  height auto
  line-height 1.4
  text-align left
  background none
  border 0
  border-radius 7px
  padding 7px 8px
  cursor pointer
  font-family inherit

  &.on
    background $brand-50

  &.current .up-row-label
    color $brand

.up-row-label
  font-size 13.5px
  color $ink
  font-weight 600
  white-space nowrap
  overflow hidden
  text-overflow ellipsis

.up-row-url
  font-size 12px
  color $muted
  flex-shrink 0
  max-width 55%
  white-space nowrap
  overflow hidden
  text-overflow ellipsis

.up-custom
  border-bottom 1px solid $line
  border-radius 7px 7px 0 0
  margin-bottom 4px

  .up-row-label
    color $brand

.up-none
  font-size 12.5px
  color $muted
  padding 10px 8px
  margin 0

</style>
