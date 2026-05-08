<template>
  <div class="breadcrumb-box">
    <ul class="breadcrumb-list">
      <li v-for="(crumb, index) in breadcrumbs" :key="index">

        <NuxtLink v-if="index !== breadcrumbs.length - 1" :to="crumb.path">
          {{ crumb.name }}
        </NuxtLink>

        <span v-else>
          {{ crumb.name }}
        </span>

        <span v-if="index !== breadcrumbs.length - 1" class="separator">
          >
        </span>
      </li>
    </ul>
  </div>
</template>
<script>
export default {
  computed: {
    breadcrumbs() {
      const pathArray = this.$route.path.split('/').filter(p => p)

      const crumbs = [
        { name: 'Home', path: '/' }
      ]

      pathArray.forEach((segment, index) => {
        const path = '/' + pathArray.slice(0, index + 1).join('/')

        crumbs.push({
          name: this.formatName(segment),
          path
        })
      })

      return crumbs
    }
  },

  methods: {
    formatName(text) {
      return text
        .replace(/-/g, ' ')
        .replace(/\b\w/g, l => l.toUpperCase())
    }
  }
}
</script>
<style scoped>
.breadcrumb-box {
    padding: 12px 16px;
    border-radius: 6px;
    max-width: 1470px;
    width: 100%;
    margin-left: auto !important;
    margin-right: auto !important;
}

.breadcrumb-list {
  display: flex;
  align-items: center;
  gap: 6px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.breadcrumb-list li {
  font-size: 14px;
  color: #6b7280;
}

.breadcrumb-list a {
  text-decoration: none;
  color: #374151;
}

.breadcrumb-list a:hover {
  color: #111827;
}

.separator {
  margin: 0 4px;
  color: #9ca3af;
}
</style>