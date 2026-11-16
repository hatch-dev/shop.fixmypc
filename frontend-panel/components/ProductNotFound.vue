<template>
  <section
    class="pnf"
    aria-labelledby="pnf-title"
  >
    <div class="pnf-card">
      <div
        class="pnf-art"
        aria-hidden="true"
      >
        <span class="pnf-code">4</span>
        <svg class="pnf-lens" width="92" height="92" viewBox="0 0 92 92" fill="none" xmlns="http://www.w3.org/2000/svg">
          <circle cx="40" cy="40" r="28" stroke="currentColor" stroke-width="9"/>
          <path d="M61 61L83 83" stroke="currentColor" stroke-width="11" stroke-linecap="round"/>
          <path d="M31 31l18 18M49 31L31 49" stroke="#05B942" stroke-width="6" stroke-linecap="round"/>
        </svg>
        <span class="pnf-code">4</span>
      </div>

      <p class="pnf-status">{{ $t('productNotFound.status') }}</p>
      <h1
        id="pnf-title"
        class="pnf-title"
      >
        {{ $t('productNotFound.title') }}
      </h1>
      <p class="pnf-message">{{ $t('productNotFound.message') }}</p>

      <form
        class="pnf-search"
        role="search"
        @submit.prevent="search"
      >
        <label
          class="pnf-sr"
          for="pnf-q"
        >{{ $t('productNotFound.searchLabel') }}</label>
        <input
          id="pnf-q"
          ref="q"
          v-model="q"
          type="search"
          maxlength="100"
          autocomplete="off"
          :placeholder="$t('productNotFound.searchPlaceholder')"
        >
        <button
          type="submit"
          class="pnf-search-btn"
        >
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true">
            <circle cx="11" cy="11" r="7"/>
            <path d="M20 20l-3.5-3.5"/>
          </svg>
          <span>{{ $t('productNotFound.search') }}</span>
        </button>
      </form>

      <div class="pnf-actions">
        <nuxt-link
          class="pnf-btn pnf-btn-primary"
          :to="browseLink"
        >
          {{ $t('productNotFound.browse') }}
        </nuxt-link>
        <nuxt-link
          class="pnf-btn pnf-btn-outline"
          to="/"
        >
          {{ $t('productNotFound.home') }}
        </nuxt-link>
      </div>
    </div>
  </section>
</template>

<script>
  export default {
    name: 'ProductNotFound',
    data() {
      return {
        q: '',
        // The catalogue listing the header's Hot Deals link also opens.
        browseLink: '/all-products/products'
      }
    },
    methods: {
      search() {
        const q = this.q.trim()
        if (!q) {
          this.$refs.q && this.$refs.q.focus()
          return
        }
        this.$router.push({path: '/search', query: {q}})
      }
    }
  }
</script>

<style scoped>
.pnf {
  display: flex;
  justify-content: center;
  padding: 48px 16px 64px;
}

.pnf-card {
  width: 100%;
  max-width: 620px;
  text-align: center;
}

.pnf-art {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  color: var(--primary-color);
  line-height: 1;
}

.pnf-code {
  font-size: 112px;
  font-weight: 800;
  letter-spacing: -2px;
}

.pnf-lens {
  width: 92px;
  height: 92px;
  margin-top: 8px;
}

.pnf-status {
  margin-top: 18px;
  font-size: 13px;
  font-weight: 700;
  letter-spacing: .12em;
  text-transform: uppercase;
  color: #05B942;
}

.pnf-title {
  margin-top: 6px;
  font-size: 32px;
  font-weight: 800;
  line-height: 1.2;
  color: #130E2B;
}

.pnf-message {
  max-width: 460px;
  margin: 12px auto 0;
  font-size: 16px;
  line-height: 1.55;
  color: #3A3C6E;
}

.pnf-search {
  display: flex;
  max-width: 480px;
  margin: 28px auto 0;
  border: 1px solid #CFD1E8;
  border-radius: 999px;
  background: #fff;
  overflow: hidden;
  transition: border-color .15s, box-shadow .15s;
}

.pnf-search:focus-within {
  border-color: var(--primary-color);
  box-shadow: 0 0 0 3px rgba(46, 46, 143, .12);
}

.pnf-search input {
  flex: 1;
  min-width: 0;
  height: 48px;
  line-height: normal;
  padding: 0 18px;
  border: 0;
  border-radius: 0;
  font-size: 15px;
  background: transparent;
}

.pnf-search input:focus,
.pnf-search input:active {
  box-shadow: none;
  background: transparent;
}

.pnf-search-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  height: 48px;
  line-height: normal;
  padding: 0 22px;
  border-radius: 0;
  font-size: 15px;
  font-weight: 600;
  color: #fff;
  background: var(--primary-color);
}

.pnf-search-btn:hover,
.pnf-search-btn:focus-visible {
  background: var(--primary-hover-color, var(--primary-color));
}

.pnf-actions {
  display: flex;
  justify-content: center;
  flex-wrap: wrap;
  gap: 12px;
  margin-top: 22px;
}

.pnf-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 190px;
  height: 48px;
  padding: 0 26px;
  border-radius: 999px;
  font-size: 15px;
  font-weight: 700;
  transition: background .15s, color .15s, border-color .15s;
}

.pnf-btn-primary {
  color: #fff;
  background: #05B942;
  box-shadow: 0 6px 16px rgba(5, 185, 66, .22);
}

.pnf-btn-primary:hover,
.pnf-btn-primary:focus-visible {
  color: #fff;
  background: #049937;
}

.pnf-btn-outline {
  color: var(--primary-color);
  border: 1.5px solid var(--primary-color);
  background: #fff;
}

.pnf-btn-outline:hover,
.pnf-btn-outline:focus-visible {
  color: #fff;
  background: var(--primary-color);
}

.pnf-sr {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
  white-space: nowrap;
}

@media only screen and (max-width: 576px) {
  .pnf {
    padding: 32px 16px 48px;
  }

  .pnf-code {
    font-size: 80px;
  }

  .pnf-lens {
    width: 66px;
    height: 66px;
  }

  .pnf-title {
    font-size: 26px;
  }

  .pnf-message {
    font-size: 15px;
  }

  .pnf-search-btn span {
    display: none;
  }

  .pnf-search-btn {
    padding: 0 18px;
  }

  .pnf-actions {
    flex-direction: column;
  }

  .pnf-btn {
    width: 100%;
  }
}
</style>
