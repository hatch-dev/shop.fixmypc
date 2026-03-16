<template>
  <div>
    <error-formatter/>
    <p class="info-msg mb-15 mb-sm-15">
      {{ $t('setting.linkMsg') }}
    </p>
    <div class="dply-felx align-start f-wrap">
      <div>
        <h4 class="mb-10">
          {{ $t('setting.servLink') }}
        </h4>
        <button
          v-if="$can('footer_link', 'create')"
          class="lite-btn mb-15"
          @click.prevent="addItem('service_links')"
        >
          {{ $t('setting.addLink') }}
        </button>

        <div
          class="input-wrapper dply-felx start mb-15 gap-15"
          v-for="(item, index) in result.service_links"
          :key="index"
        >
          <dropdown
            :disabled="item.deleted"
            :options="allPages"
            :selected-key="item.page_id"
            @clicked="updateItem('service_links', {index, value: $event})"
          />

          <button
            v-if="$can('footer_link', 'delete')"
            class="delete-btn dply-felx"
            @click.prevent="removeItem('service_links', {index})"
          >
            <i
              class="icon"
              :class="item.deleted ? 'undo-icon' : 'delete-icon'"
            />
          </button>
        </div>
      </div>
      <div>
        <h4 class="mb-10">
          {{ $t('setting.al') }}
        </h4>
        <button
          v-if="$can('footer_link', 'create')"
          class="lite-btn mb-15"
          @click.prevent="addItem('about_links')"
        >
          {{ $t('setting.addLink') }}
        </button>

        <div
          class="input-wrapper dply-felx start mb-15"
          v-for="(item, index) in result.about_links"
          :key="index"
        >
          <dropdown
            class="mr-10"
            :disabled="item.deleted"
            :options="allPages"
            :selected-key="item.page_id"
            @clicked="updateItem('about_links', {index, value: $event})"
          />

          <button
            v-if="$can('footer_link', 'delete')"
            class="delete-btn dply-felx"
            @click.prevent="removeItem('about_links', {index})"
          >
            <i
              class="icon"
              :class="item.deleted ? 'undo-icon' : 'delete-icon'"
            />
          </button>
        </div>
      </div>
    </div>
    <div class="dply-felx j-right">
      <ajax-button
        v-if="$can('footer_link', 'edit')"
        type="button"
        name="save"
        class="primary-btn"
        :text="$t('setting.sv')"
        :fetching-data="formSubmitting"
        @clicked="saveFooterLinks"
      />
    </div>
  </div>

</template>

<script>
  import {mapGetters, mapActions} from 'vuex'
  import AjaxButton from "~/components/AjaxButton"
  import Dropdown from "~/components/Dropdown"
  import ErrorFormatter from "~/components/ErrorFormatter"

  export default {
    name: 'FooterLink',
    data() {
      return {
        formSubmitting: false
      }
    },
    props: {
      result: {
        type: Object,
        default() {
          return {
            about_links: [],
            service_links: []
          }
        }
      }
    },
    mixins: [],
    components: {ErrorFormatter, Dropdown, AjaxButton},
    computed: {
      ...mapGetters('common', ['allPages'])
    },
    methods: {
      async saveFooterLinks() {
        this.formSubmitting = true
        try {
          const data = await this.setRequest({params: this.result, api: 'setFooterLink'})
          if (data) {
            this.$emit('result', data)
          }
        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.formSubmitting = false
      },
      removeItem(source, {index}) {
        let deleted = true
        if (this.result[source][index]?.deleted) {
          deleted = false
        }
        this.$emit('remove-item', {source, index, deleted: deleted})
      },
      updateItem(source, {index, value}) {
        this.$emit('update-item', {source, index, value})
      },
      addItem(source) {
        this.$emit('add-item', {source})
      },
      ...mapActions('common', ['setRequest'])
    },
    mounted() {
    }
  }
</script>

