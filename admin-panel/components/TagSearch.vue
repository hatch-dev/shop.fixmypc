<template>
  <div
    class="tag-wrapper"
    v-outside-click="autoSuggestionClose"
  >
    <ul class="tags">
      <li
        v-for="(value, index) in selectedTags"
        :key="index"
        :class="{warning: deleteWarning && index === selectedTags.length - 1}"
      >
        <span
          class="tag"
        >
          {{value}}
          <span
            class="close-btn"
            @click="deleteTag(value)"
          >
            <i class="icon close-icon"/>
          </span>
        </span>
      </li>
      <li class="tag-input">
        <input
          type="text"
          ref="tagSearchInput"
          v-model="searchedString"
          @focus="openResult"
          @keydown.enter.prevent="clickedItem({title: searchedString})"
          @keydown.delete="deleteKeyPress()"
          placeholder="Write here"
        >
      </li>
    </ul>

    <div
      class="tag-dropdown"
      v-if="autoSuggestionOpen"
    >
      <div
        v-if="loading"
        class="spinner-wrapper"
      >
        <spinner
          :radius="40"
          color="primary"
        />
      </div>
      <ul
        v-else-if="tagList"
      >
        <li
          v-for="(item, index) in tagList"
          :key="index"
        >
          <a
            href="#"
            @click.prevent="clickedItem(item.title)"
          >
            {{ item.title }}
          </a>
        </li>
        <li v-if="searchedString && !tagExists">
          <a
            href="#"
            @click.prevent="clickedItem(null)"
          >
            {{ searchedString }} ({{ $t('error.new') }})
          </a>
        </li>
      </ul>
      <p v-else-if="!searchedString">
        {{ $t('index.nf') }}
      </p>
    </div>
  </div>

</template>

<script>

  import outsideClick from '~/directives/outside-click'
  import {debounce} from 'debounce'
  import Spinner from '~/components/Spinner';
  import {mapActions} from 'vuex'

  export default {
    name: 'TagSearch',
    data() {
      return {
        setApi: 'setTags',
        searchedString: '',
        tagList: null,
        loading: false,
        autoSuggestionOpen: false,
        deleteWarning: false,
      }
    },
    props: {
      tags: {
        type: String,
        default: ''
      }
    },
    directives: {outsideClick},
    components: {
      Spinner
    },
    watch: {
      searchedString: debounce(function () {
        this.fetchingData()
      }, 700)
    },
    computed: {
      tagExists(){
        return this.tagList.findIndex(o=>{
          return o.title === this.searchedString
        }) > -1
      },
      selectedTags() {
        if (this.tags) {
          return this.tags.split(',').filter(o=>{
            return o
          })
        }
        return []
      }
    },
    methods: {
      deleteKeyPress() {
        if (!this.searchedString) {
          if (this.deleteWarning) {
            this.deleteTag(this.selectedTags[this.selectedTags.length - 1])
          }
          this.deleteWarning = !this.deleteWarning
        }
      },
      deleteTag(tag) {
        this.$emit('delete', tag)
      },
      openResult() {
        this.autoSuggestionOpen = !this.autoSuggestionOpen
        this.fetchingData()
      },
      async saveTag() {
        this.loading = true
        try {
          await this.setRequest({params: {title: this.searchedString}, api: 'setTags'})
        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.loading = false
      },
      async fetchingData() {
        this.loading = true
        try {
          const {data} = await this.getRequest({params: {q: this.searchedString}, api: 'getTags'})
          if(data){
            this.tagList = data
          }
        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.loading = false
      },
      autoSuggestionClose() {
        this.autoSuggestionOpen = this.deleteWarning = false
        this.searchedString = ''
      },
      emitAddingTag(tag){
        if(this.selectedTags.findIndex(o => {return o === tag}) === -1){
          this.$emit('add', tag)
        }
      },
      clickedItem(data) {
        if(!data){
          this.saveTag()
          data = this.searchedString
        }
        this.emitAddingTag(data)
        this.autoSuggestionClose()
        this.$refs.tagSearchInput.focus()
      },
      ...mapActions('common', ['setRequest', 'getRequest'])
    },
  }
</script>

<style lang="stylus">
  @import "~/assets/styles/tag.styl"
</style>

