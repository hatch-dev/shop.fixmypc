<template>
  <div
    class="dply-felx mb-5"
  >
    <div
      class="w-100"
      v-if="options && Object.keys(options).length"
    >
      <div
        class="sided mb-15"
      >
        <span
          class="lite-bold"
        >
          {{ title }}
        </span>
        <button
          class="primary-btn mn-w-200x"
          @click.prevent="toggle(null)"
        >
          {{ $t('list.an') }}
        </button>
      </div>
      <div
        class="dply-felx f-wrap start"
      >
        <div
          v-for="(item, index) in selectedIds"
          :key="index" class="dply-felx mr-15 mb-10"
        >
          <dropdown
            :options="options"
            :selectedKey="item"
            @clicked="selected({evt: $event, index: index})"
          />

          <button
            @click.prevent="toggle(index)"
            class="ml-5 close-btn"
          >
            <i
              class="icon close-icon"
            />
          </button>
        </div>
      </div>
    </div>
    <a v-else
       :href="`/${route.link}`"
       @click.prevent="$router.push({name: route.name})"
       :title="$t('list.add', { title: title })"
       class="link"
    >
      {{ $t('list.add', { title: title }) }}
    </a>
  </div>
</template>

<script>

  import Dropdown from "~/components/Dropdown"

  export default {
    name: 'SliderFormTab',
    data() {
      return {
        selectedIdsData: null
      }
    },
    props: {
      title: {
        type: String,
        default: ''
      },
      selectedIds: {
        type: Object,
        default() {
          return null
        }
      },
      options: {
        type: Object,
        default() {
          return null
        }
      },
      route: {
        type: Object,
        default() {
          return null
        }
      }
    },
    mixins: [],
    components: {
      Dropdown

    },
    computed: {},
    methods: {
      toggle(index = null) {
        if (index) {
          this.selectedIdsData = {
            ...this.selectedIdsData, ...{
              [index]: undefined
            }
          }
          delete this.selectedIdsData[index]
        } else {
          this.selectedIdsData = {
            ...this.selectedIdsData, ...{
              [Object.keys(this.selectedIdsData).length]: Object.keys(this.options)[0]
            }
          }
        }
        this.$emit('selected-ids', this.selectedIdsData)
      },
      selected(data) {
        this.selectedIdsData[data.index] = data.evt.key
        this.$emit('selected-ids', this.selectedIdsData)
      },
    },
    created() {
    },
    mounted() {
      this.selectedIdsData = this.selectedIds
    }
  }
</script>

