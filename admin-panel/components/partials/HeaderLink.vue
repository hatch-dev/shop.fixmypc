<template>
  <div>
    <div class="dply-felx">


      <h4>{{ title }}</h4>

      <button
        @click.prevent="addNew(type)"
        class="lite-btn"
      >
        {{ $t('setting.addLink') }}
      </button>

    </div>


    <div class="table-wrapper mb-20 mb-sm-15">
      <table class="mn-w-600x">
        <tr class="lite-bold">
          <th>{{ $t('index.title') }}</th>
          <th>{{ $t('admin.url') }}</th>
          <th/>
        </tr>

        <tr
          v-for="(item, index) in result[type]"
          :key="index"
          class="deletable"
          :class="{deleted: item.deleted}"
        >
          <td>
            <input
              :disabled="item.deleted"
              type="text"
              :placeholder="$t('index.title')"
              name="title"
              v-model="item.title"
            >
          </td>
          <td>
            <input
              :disabled="item.deleted"
              type="text"
              :placeholder="$t('admin.url')"
              name="title"
              v-model="item.url"
            >

          </td>
          <td
            class="undo-container"
          >
            <button
              v-if="item.deleted"
              @click.prevent="undoDelete(index, type)"
              class="lite-btn "
            >
              {{ $t('fSale.undo') }}
            </button>
            <button
              v-else
              @click.prevent="deleteItem(index, type)"
              class="lite-btn delete-btn"
            >
              {{ $t('category.delete') }}
            </button>
          </td>
        </tr>
      </table>
    </div>
  </div>
</template>

<script>

  import {debounce} from "debounce";

  export default {
    name: 'HeaderLink',
    data() {
      return {
        result: {
          left: [],
          right: [],
        }

      }
    },
    props: {
      resultData: {
        type: Object,
        default () {
          return {
            left: [],
            right: [],
          }
        }
      },
      type: {
        type: String,
        default: 'left'
      },
      title: {
        type: String,
      }
    },
    computed:{

    },
    watch: {
      resultData(){
        this.assignResult()
      }
    },
    methods: {
      addNew(type = 'left') {
        this.result[type].push({
          title: '',
          url: '',
        })
        this.$emit('change', this.result)
      },

      deleteItem(index, type = 'left') {
        this.result[type][index] = {
          ...this.result[type][index],
          ...{deleted: true}
        }
        this.result = {...this.result, ...{[type]: this.result[type]}}
        this.$emit('change', this.result)
      },
      undoDelete(index, type = 'left') {
        this.result[type][index] = {
          ...this.result[type][index],
          ...{deleted: false}
        }
        this.result = {...this.result, ...{[type]: this.result[type]}}
        this.$emit('change', this.result)
      },
      assignResult(){
        this.result = this.resultData
      }
    },
    mounted() {
      this.assignResult()
    }
  }
</script>
