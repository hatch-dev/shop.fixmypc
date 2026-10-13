<template>
  <li>
    <nuxt-link
      :to="categoryLink(node)"
      :title="node.title"
      :class="[depth ? 'kid' : 'tree-row', { on: isActive, 'is-open': expanded, 'in-path': !isActive && containsActive }]"
    >
      <template v-if="!depth">
        <span
          class="tw"
          :role="children.length ? 'button' : null"
          @click.prevent.stop="children.length ? toggle() : null"
        >
          <svg
            v-if="children.length"
            width="14"
            height="14"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2.4"
            stroke-linecap="round"
            stroke-linejoin="round"
          >
            <path d="m9 6 6 6-6 6" />
          </svg>
        </span>
        <span class="ci">
          <lazy-image
            v-if="node.image"
            :data-src="getImageURL(node.image)"
            :title="node.title"
            :alt="node.title"
          />
        </span>
      </template>
      <span class="nm">{{ node.title }}</span>
      <span class="n" v-if="hasCount">{{ node.products_count }}</span>
    </nuxt-link>

    <ul class="kids" :class="{ on: expanded }" v-if="children.length">
      <tree-node
        v-for="childNode in children"
        :key="childNode.id"
        :node="childNode"
        :depth="depth + 1"
      />
    </ul>
  </li>
</template>

<script>
  import util from '~/mixin/util'
  import LazyImage from '~/components/LazyImage'

  export default {
    name: 'TreeNode',
    components: { LazyImage },
    props: {
      node: {
        type: Object,
        required: true,
      },
      depth: {
        type: Number,
        default: 0
      }
    },
    data() {
      return {
        // null = follow the route, true/false = the visitor toggled it by hand
        manualExpand: null,
      };
    },
    mixins: [util],
    methods: {
      toggle() {
        this.manualExpand = !this.expanded
      },
    },
    computed: {
      children() {
        return this.node?.child || []
      },
      // Only the branch being browsed is open, as in the reference tree.
      expanded() {
        if (this.manualExpand !== null) {
          return this.manualExpand
        }
        return this.isActive || this.containsActive
      },
      containsActive() {
        const slug = this.$route.params?.category
        return !!slug && this.children.some(child => child?.slug === slug)
      },
      hasCount() {
        return this.node?.products_count !== undefined && this.node?.products_count !== null
      },
      isActive() {
        return this.$route.params?.category === this.node?.slug
      },
      nodeData(){
        return this.node[this.keyName]
      },
    }

  }
</script>
