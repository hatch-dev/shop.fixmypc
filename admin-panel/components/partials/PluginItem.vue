<template>
  <div
    class="file-wrapper"
  >
    <div
      :class="`file-input has-file`"
    >

      <div class="plus-wrap tick-wrap">
        <i
          class="icon tick-icon no-click"
        />
      </div>

      <h6>{{ plugin.name }}</h6>
    </div>


    <template
      v-if="!plugin.active"
    >
      <input type="text" v-model="purchaseCode" :placeholder="$t('dataPage.pkey')">
      <ajax-button
        class="outline-btn"
        type="button"
        :disabled="activated"
        color="primary"
        :text="$t('dataPage.act')"
        :fetching-data="activating"
        @clicked="activatePlugin"
      />
    </template>


    <ajax-button
      class="outline-btn"
      type="button"
      color="primary"
      :text="$t('category.delete')"
      :fetching-data="deleting"
      @clicked="deletePlugin"
    />
  </div>
</template>

<script>
  import {mapActions} from 'vuex'
  import AjaxButton from "../AjaxButton";


  export default {
    name: "PluginItem",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        purchaseCode: null,
        deleting: false,
        activated: false,
        activating: false,
      }
    },
    components: {
      AjaxButton

    },
    props: {
      plugin: {
        type: Object
      },
    },
    watch: {
    },
    mixins: [],
    computed: {

    },
    methods: {
      async deletePlugin(){
        this.deleting = true
        await this.deleteData({params: this.plugin.id, api: 'deletePlugin' })
        this.deleting = false

        this.$emit('fetching-data')
      },

      async activatePlugin(){
        this.activating = true

        const fd = new FormData()
        fd.append('name', this.plugin?.name)
        fd.append('code', this.purchaseCode)

        const res = await this.setRequest({
          params: fd,
          api: 'activatePlugin'
        })
        this.activating = false


        if(!(res?.status && res?.status === 201)){
          this.$emit('fetching-data', res)
        }
      },
      ...mapActions('common', ['setRequest', 'deleteData'])
    },
    mounted() {
    }
  }
</script>

<style scoped>

</style>
