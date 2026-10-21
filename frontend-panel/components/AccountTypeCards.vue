<template>

  <div class="atc">

    <div class="atc-viewport">

      <button
        v-show="showNav && page > 0"
        type="button"
        class="atc-arrow atc-arrow-prev"
        :aria-label="$t('ui.accountTypeCards.previous')"
        @click="go(page - 1)"
      >
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
      </button>

      <div
        ref="track"
        class="atc-track"
        @scroll.passive="onScroll"
      >

        <button
          v-for="type in types"
          :key="type.key"
          type="button"
          class="atc-card"
          :class="{ 'is-current': type.key === selected }"
          :aria-pressed="type.key === selected ? 'true' : 'false'"
          @click="$emit('select', type.key)"
        >

          <span class="tc-top">
            <span
              class="tc-ico"
              v-html="type.icon"
            />
            <!--
              Coming back to this step to switch type gave no clue which one
              was already chosen, so the card you were on looked like the two
              you were not.
            -->
            <span
              v-if="type.key === selected"
              class="tc-current"
            >
              {{ $t('ui.accountTypeCards.currentChoice') }}
            </span>
            <span
              v-else-if="type.badge"
              class="tc-badge"
            >
              {{ type.badge }}
            </span>
          </span>

          <span class="tc-name">{{ type.name }}</span>

          <span class="tc-desc">{{ type.desc }}</span>

          <span class="tc-list">
            <span
              v-for="(item, index) in type.points"
              :key="index"
              class="tc-li"
            >
              {{ item }}
            </span>
          </span>

          <span class="tc-foot">
            {{ type.foot }}
            <span
              class="tc-go"
              aria-hidden="true"
            >
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            </span>
          </span>

        </button>

      </div>

      <button
        v-show="showNav && page < lastPage"
        type="button"
        class="atc-arrow atc-arrow-next"
        :aria-label="$t('ui.accountTypeCards.next')"
        @click="go(page + 1)"
      >
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>
      </button>

    </div>

    <div
      v-show="showNav"
      class="atc-dots"
    >
      <button
        v-for="(type, index) in types"
        :key="`dot-${type.key}`"
        type="button"
        class="atc-dot"
        :class="{ on: index === page }"
        :aria-label="$t('ui.accountTypeCards.show', { name: type.name })"
        @click="go(index)"
      />
    </div>

  </div>

</template>

<script>

export default {

  name: "AccountTypeCards",

  props: {
    /** The type already chosen, when the shopper has come back to change it. */
    selected: {
      type: String,
      default: ''
    }
  },

  data() {
    return {
      page: 0,
      types: [
        {
          key: 'personal',
          name: this.$t('ui.accountTypeCards.personal.name'),
          desc: this.$t('ui.accountTypeCards.personal.desc'),
          points: [
            this.$t('ui.accountTypeCards.personal.point1'),
            this.$t('ui.accountTypeCards.personal.point2'),
            this.$t('ui.accountTypeCards.personal.point3')
          ],
          foot: this.$t('ui.accountTypeCards.personal.foot'),
          icon: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="3.6"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/></svg>'
        },
        {
          key: 'business',
          name: this.$t('ui.accountTypeCards.business.name'),
          desc: this.$t('ui.accountTypeCards.business.desc'),
          points: [
            this.$t('ui.accountTypeCards.business.point1'),
            this.$t('ui.accountTypeCards.business.point2'),
            this.$t('ui.accountTypeCards.business.point3')
          ],
          foot: this.$t('ui.accountTypeCards.business.foot'),
          icon: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M5 21V8l7-4 7 4v13"/><path d="M10 21v-5h4v5"/></svg>'
        },
        {
          key: 'trade',
          name: this.$t('ui.accountTypeCards.trade.name'),
          badge: this.$t('ui.accountTypeCards.trade.badge'),
          desc: this.$t('ui.accountTypeCards.trade.desc'),
          points: [
            this.$t('ui.accountTypeCards.trade.point1'),
            this.$t('ui.accountTypeCards.trade.point2'),
            this.$t('ui.accountTypeCards.trade.point3')
          ],
          foot: this.$t('ui.accountTypeCards.trade.foot'),
          icon: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76Z"/></svg>'
        }
      ]
    }
  },

  computed: {

    lastPage() {
      return this.types.length - 1
    },

    /* One card is shown at a time, so the controls are always in play. */
    showNav() {
      return this.types.length > 1
    }
  },

  methods: {

    go(index) {

      const track = this.$refs.track
      const card = track && track.children[index]

      if (!card) return

      this.page = index
      track.scrollTo({left: card.offsetLeft - track.offsetLeft, behavior: 'smooth'})
    },

    onScroll() {

      const track = this.$refs.track

      if (!track) return

      // Whichever card's left edge is nearest the viewport's is the active one.
      const left = track.scrollLeft
      let nearest = 0
      let best = Infinity

      Array.from(track.children).forEach((card, index) => {
        const distance = Math.abs((card.offsetLeft - track.offsetLeft) - left)
        if (distance < best) {
          best = distance
          nearest = index
        }
      })

      this.page = nearest
    }
  },

  mounted() {
    // Keep the active dot honest if the track is restored mid-scroll.
    this.$nextTick(this.onScroll)
  }
}
</script>

<style scoped>

.atc {
    margin-bottom: 28px;
}

.atc-viewport {
    position: relative;
}

.atc-track {
    display: grid;
    grid-auto-flow: column;
    /* One card fills the viewport; the rest wait either side. */
    grid-auto-columns: 100%;
    gap: 14px;
    overflow-x: auto;
    scroll-snap-type: x mandatory;
    scrollbar-width: none;
    /* Room for the hover lift and its shadow, which would otherwise clip. */
    padding: 4px 2px 8px;
    margin: -4px -2px -8px;
}

.atc-track::-webkit-scrollbar {
    display: none;
}

.atc-card {
    scroll-snap-align: start;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    text-align: left;
    width: 100%;
    height: auto;
    line-height: 1.45;
    padding: 18px 18px 16px;
    background: #fff;
    cursor: pointer;
    border: 1px solid var(--auth-line-strong, #CFD1E8);
    border-radius: 16px;
    font-family: inherit;
    transition: border-color .15s, box-shadow .15s, transform .15s;
}

.atc-card:hover {
    border-color: var(--auth-brand, #2D2DA0);
    box-shadow: 0 10px 26px rgba(22, 23, 74, .09);
    transform: translateY(-2px);
}

.atc-card:active {
    transform: none;
    background: #fff;
}

.tc-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    width: 100%;
    margin-bottom: 12px;
}

.tc-ico {
    width: 42px;
    height: 42px;
    flex: 0 0 auto;
    border-radius: 13px;
    background: var(--auth-brand-50, #F1F1FC);
    color: var(--auth-brand, #2D2DA0);
    display: grid;
    place-items: center;
}

.tc-badge {
    display: inline-block;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: #B26A00;
    background: #FFF6E6;
    border: 1px solid #F0DCB6;
    padding: 3px 8px;
    border-radius: 999px;
    white-space: nowrap;
}

.tc-current {
    display: inline-block;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: #0B6B3A;
    background: #E8F7EF;
    border: 1px solid #B7E3CB;
    padding: 3px 8px;
    border-radius: 999px;
    white-space: nowrap;
}

.atc-card.is-current {
    border-color: #0B6B3A;
    box-shadow: 0 0 0 1px #0B6B3A inset;
}

.tc-name {
    display: block;
    font-size: 16.5px;
    font-weight: 800;
    letter-spacing: -.01em;
    color: var(--auth-ink, #16174A);
    line-height: 1.2;
    margin-bottom: 5px;
}

.tc-desc {
    display: block;
    font-size: 13px;
    color: var(--auth-muted, #6B7191);
    line-height: 1.4;
}

.tc-list {
    display: grid;
    gap: 3px;
    margin-top: 11px;
    width: 100%;
}

.tc-li {
    position: relative;
    padding-left: 20px;
    font-size: 12.5px;
    color: var(--auth-ink-soft, #3A3C6E);
    line-height: 1.35;
}

.tc-li:before {
    content: "";
    position: absolute;
    left: 3px;
    top: 5px;
    width: 9px;
    height: 5px;
    border-left: 2px solid var(--auth-go, #00A24A);
    border-bottom: 2px solid var(--auth-go, #00A24A);
    transform: rotate(-45deg);
}

.tc-foot {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    width: 100%;
    /* Pushed to the bottom so the footer line aligns across all three cards,
     * whatever the length of the copy above it. */
    margin-top: auto;
    padding-top: 11px;
    border-top: 1px solid var(--auth-line, #E4E5F3);
    font-size: 11.5px;
    font-weight: 700;
    color: var(--auth-brand, #2D2DA0);
    letter-spacing: .01em;
}

.tc-go {
    color: var(--auth-line-strong, #CFD1E8);
    display: grid;
    place-items: center;
    flex: 0 0 auto;
    transition: color .15s, transform .15s;
}

.atc-card:hover .tc-go {
    color: var(--auth-brand, #2D2DA0);
    transform: translateX(3px);
}

/* ── carousel controls ── */
.atc-arrow {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    z-index: 2;
    width: 36px;
    height: 36px;
    padding: 0;
    line-height: 1;
    border-radius: 50%;
    border: 1px solid var(--auth-line-strong, #CFD1E8);
    background: #fff;
    color: var(--auth-ink, #16174A);
    display: grid;
    place-items: center;
    cursor: pointer;
    box-shadow: 0 4px 14px rgba(22, 23, 74, .12);
}

.atc-arrow:hover {
    border-color: var(--auth-brand, #2D2DA0);
    color: var(--auth-brand, #2D2DA0);
}

.atc-arrow-prev {
    left: -14px;
}

.atc-arrow-next {
    right: -14px;
}

.atc-dots {
    display: flex;
    justify-content: center;
    gap: 7px;
    margin-top: 18px;
}

.atc-dot {
    width: 7px;
    height: 7px;
    padding: 0;
    border: 0;
    border-radius: 50%;
    background: var(--auth-line-strong, #CFD1E8);
    cursor: pointer;
    transition: width .2s, background .2s, border-radius .2s;
}

.atc-dot.on {
    width: 22px;
    border-radius: 999px;
    background: var(--auth-brand, #2D2DA0);
}

/* Below the split layout the panel runs full width, so the arrows would sit
 * far from the card — swipe and the dots carry it instead. */
@media (max-width: 1024px) {

    .atc-arrow {
        display: none;
    }
}

</style>
