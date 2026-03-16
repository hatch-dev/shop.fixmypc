<template>
    <ul class="table-heading">
      <li class="lite-bold mr-0 hide-xxs">{{ $t('list.ob') }}</li>
      <li class="dply-felx gap-0">
        <div>
          <dropdown
            :selectedKey="sortBy"
            :options="orderByOptions"
            @clicked="dropdownSelected(false, $event)"
          />
        </div>
        <dropdown
          :selectedKey="sortByType"
          :options="orderTypeObj"
          @clicked="dropdownSelected(true, $event)"
        />
      </li>
    </ul>
</template>

<script>
  import tableHelper from '~/mixin/tableHelper'
  import Dropdown from "~/components/Dropdown"

  export default {
    name: 'TableTop',
    data() {
      return {
        orderTypeObj: {
          asc: {title: this.$t('dataPage.asc') },
          desc: {title: this.$t('dataPage.desc') }
        },
      }
    },
    props: {
      orderByOptions: {
        type: Object,
        default() {
          return null
        }
      }
    },
    mixins: [tableHelper],
    components: {
      Dropdown,
    },
    computed: {},
    methods: {},
    beforeCreate() {
    },
    destroyed() {
    },
    created() {
    }
  }
</script>

