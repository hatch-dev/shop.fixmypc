<template>
  <div>
    <slot
      :renderArea="renderArea"
    />
  </div>
</template>

<script>

  export default {
    name: 'LazyArea',
    props: {
      rootMargin: {
        type: Number,
        default: () => 0,
      },
    },
    data() {
      return {
        renderArea: false,
      };
    },
    mixins: [],
    computed: {
    },
    mounted() {
      /*
       * No observer, no lazy loading - but there must still be a page.
       *
       * Without this guard the slot stays false for ever on any browser
       * lacking IntersectionObserver, so every section wrapped in a LazyArea
       * is simply absent: not late, gone. Rendering everything at once is the
       * correct degradation, since that is what the page did before.
       */
      if (typeof window === 'undefined' || !('IntersectionObserver' in window)) {
        this.renderArea = true
        return
      }

      const options = {
        rootMargin: `${this.rootMargin}px`,
      };
      const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
          if (entry.intersectionRatio > 0) {
            this.renderArea = true
            observer.disconnect()
          }
        })
      }, options)
      observer.observe(this.$el)

      // A route change before the area is ever reached would otherwise leave
      // the observer holding a detached element.
      this.$once('hook:destroyed', () => {
        observer.disconnect()
      })
    },
    methods: {
    },
  };
</script>
