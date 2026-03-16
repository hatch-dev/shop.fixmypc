<template>
  <button
    @click.passive="btnClicked"
    class="ajax-btn"
    :disabled="disable || disabled || !isActive"
    :type="type"
  >
    <spinner
      v-if="fetchingData"
      :color="color"
    />
    <i
      v-if="onlyIcon && !fetchingData"
      class="icon"
      :class="onlyIcon"
    />
    <span
      v-if="!onlyIcon && loadingText && fetchingData"
    >
       {{ loadingText }}
    </span>
    <span
      v-else-if="!onlyIcon && !fetchingData"
    >
       {{ text }}
    </span>
  </button>
</template>

<script>

  import Spinner from "~/components/Spinner"
  import {mapGetters} from 'vuex'

  export default {
    name: 'AjaxButton',
    components: {
      Spinner
    },
    props: {
      color: {
        type: String,
        default: 'white',
      },
      text: {
        type: String,
        default: function () {
          return this.$t('profile.submit')
        }
      },
      onlyIcon: {
        type: String,
        default: null,
      },
      loadingText: {
        type: String,
        default: function () {
          return this.$t('profile.gr')
        }
      },
      fetchingData: {
        type: Boolean,
        default: false,
      },
      disabled: {
        type: Boolean,
        default: false,
      },
      activateBtn: {
        type: Boolean,
        default: false,
      },
      type: {
        type: String,
        default: 'Submit',
      },
    },
    computed: {
      disable() {
        return this.fetchingData
      },
      isActive() {
        if(this.activateBtn){
          return true
        }
        return this.activated
      },
      ...mapGetters('admin', ['activated'])
    },
    methods:{
      btnClicked(){
        if(this.type !== 'Submit'){
          this.$emit('clicked')
        }
      }
    }
  }
</script>

<style lang="stylus">
  .ajax-btn
    gap 10px
    display flex
    justify-content center
    align-items center

    span
      white-space nowrap
      overflow hidden
      text-overflow ellipsis


  button:disabled,
  button[disabled] {
    opacity .6
    cursor no-drop
  }
</style>
