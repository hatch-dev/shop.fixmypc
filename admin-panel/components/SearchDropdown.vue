<template>
  <div class="custom-dropdown">
    <input
      type="text"
      :placeholder="placeholder"
      v-model="search"
      @input="searchData"
      @blur="onBlur"
      :data-ignore="currentId"
      @focus="onFocus"
    >
    <button
      v-if="showClose"
      @click.prevent="clear"
      :data-ignore="currentId"
      class="ml-5 close-btn z-10"
    >
      <i
        class="icon close-icon"
      />
    </button>
    <div
      v-if="open"
      class="dropdown-inner mn-h-200x"
      v-outside-click="outsideClickFn"
      :id="currentId"
    >
      <div
        v-if="loading"
        class="spinner-wrapper"
      >
        <spinner
          :radius="60"
          color="primary"
          class="mr-15"
        />
      </div>
      <ul>
        <li
          v-for="(data, index) in result"
          :key="index"
          @click.prevent="itemClicked(data)"
        >
          {{data[titleKey]}}
        </li>
      </ul>
    </div>

  </div>
</template>

<script>
  import outsideClick from '~/directives/outside-click';
  import Spinner from "~/components/Spinner"
  import Dropdown from "./Dropdown";
  import {mapGetters, mapActions} from 'vuex'
  import {debounce} from "debounce";

  export default {
    name: 'SearchDropdown',
    components: {
      Dropdown,
      Spinner
    },
    data() {
      return {
        selected: '',
        searched: '',
        search: '',
        result: null,
        open: false,
        loading: false,
      }
    },
    props: {
      currentId: {
        type: String,
        default: 'title'
      },
      titleKey: {
        type: String,
        default: 'title'
      },
      placeholder: {
        type: String,
        default: ''
      },
      preItem: {
        type: Object,
        default: null
      },
      listApi: {
        type: String,
        default: ''
      },
      selectedText: {
        type: String,
        default: ''
      },
    },
    directives: {outsideClick},
    computed: {
      showClose(){
        if(this.search) {
          return true
        } else if(this.selected) {
          return true
        }
        return false
      }
    },
    watch: {
      selectedText(value){
        this.search = value
      }
    },
    methods:{
      clearData(){
        this.selected = ''
        this.search = ''
        this.searched = ''
        this.open = false
      },
      clear(){
        this.clearData()
        this.fetchingData(true)
        this.$emit('clicked', null)
      },
      itemClicked(evt){
        this.selected = evt[this.titleKey]
        this.open = false
        this.search = evt[this.titleKey]
        this.$emit('clicked', evt)
      },
      searchData: debounce(function (e) {
        this.searched = this.search
        this.fetchingData(true)
      }, 700),

      onBlur() {
        this.search = this.selected
        if(!this.search){
          this.search = this.selectedText
        }
      },
      outsideClickFn(){
        this.open = false
      },
      onFocus(){
        this.search = this.searched
        this.fetchingData()

      },
      async fetchingData(search = false) {
        this.open = true

        if(!this.result?.length || search) {
          try {
            this.loading = true
            const res = await this.getRequest({
              params: {
                q: this.search,
                per_page: 10
              },
              api: this.listApi
            });

            this.result = res?.data || []
            if(this.preItem){
              this.result.unshift(this.preItem)
            }


            this.loading = false
          } catch (e) {
            return this.$nuxt.error(e)
          }
        }

      },
      ...mapActions('common', ['getRequest'] )
    },
    mounted() {
      this.search = this.selectedText
    }
  }
</script>
