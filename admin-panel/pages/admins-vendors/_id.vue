<template>
  <data-page
    v-if="allRoles"
    ref="dataPage"
    set-api="setAdmin"
    get-api="getAdmin"
    route-name="admins-vendors"
    :name="$t('user.admVend')"
    :emit-before-submit="true"
    @before-submit="submitForm"
    :validation-keys="validationKeys"
    :result="result"
    gate="admin"
    @result="setResult"
  >
    <template v-slot:form="{hasError}">
      <div class="input-wrapper">
        <label>
          {{ $t('user.name') }}
        </label>
        <input
          type="text"
          :placeholder="$t('user.name')"
          v-model="result.name"
        >
      </div>

      <div class="input-wrapper">
        <label>
          {{ $t('user.uName') }}
        </label>
        <input
          type="text"
          :placeholder="$t('user.uName')"
          name="username"
          v-model.trim="result.username"
          :class="{invalid: !result.username && hasError}"
        >
        <span
          class="error"
          v-if="!!!result.username && hasError"
        >
          {{ $t('category.req', { type: $t('user.uName')}) }}
        </span>
      </div>


      <div class="input-wrapper">

        <label>
          {{ $t('fSale.email') }}
        </label>

        <div class="icon-input">
          <i
            class="icon email-icon"
          />
          <input
            type="text"
            :placeholder="$t('fSale.email')"
            v-model.trim="result.email"
            :class="{invalid: !!!result.email || isInvalidEmail}"
          >
        </div>

        <span
          class="error"
          v-if="!!!result.email && hasError"
        >
          {{ $t('category.req', { type: $t('fSale.email')}) }}
        </span>
        <span
          class="error"
          v-else-if="isInvalidEmail && hasError"
        >
          {{ $t('user.isValid', { type: $t('fSale.email') }) }}
        </span>
      </div>


      <div
        v-if="!id"
        class="input-wrapper">
        <label>
          {{ $t('user.pass') }}
        </label>
        <password-field
          :value="result.password"
          :is-invalid="!isValidLength(result.password)"
          @change="result.password = $event"
        />
        <span
          class="error"
          v-if="!!!result.password && hasError"
        >
          {{ $t('category.req', { type: $t('user.pass')}) }}
        </span>
        <span
          class="error"
          v-else-if="!isValidLength(result.password) && hasError"
        >
          {{ $t('user.inPass') }}
        </span>
      </div>


      <div
        v-if="!id"
        class="input-wrapper">
        <label>
          {{ $t('user.cPass') }}
        </label>
        <password-field
          :value="result.confirm_password"
          :is-invalid="!isValidLength(result.confirm_password)"
          @change="result.confirm_password = $event"
        />
        <span
          class="error"
          v-if="!!!result.confirm_password && hasError"
        >
          {{ $t('category.req', { type: $t('user.cPass')}) }}
        </span>
        <span
          class="error"
          v-else-if="!isValidLength(result.confirm_password) && hasError"
        >
           {{ $t('user.inPass') }}
        </span>
        <span
          class="error"
          v-else-if="(result.confirm_password !== result.new_password) && hasError"
        >
          {{ $t('user.nMatch') }}
        </span>
      </div>


      <div class="input-wrapper">
        <div class="dply-felx j-left mb-20 mb-sm-15">
          <span class="mr-15">
            {{ $t('user.role') }}
          </span>
          <dropdown
            :selectedKey="getDataFromObject(result, 'roles.0.name', getDataFromObject(result, 'roles.0', null))"
            :options="allRoles"
            @clicked="dropdownSelected"
          />
        </div>
      </div>

      <label class="input-wrapper block">
        <span class="mr-15">{{ $t('title.ac') }}</span>
        <input type="checkbox" v-model="result.active">
      </label>

      <label class="input-wrapper block">
        <span class="mr-15">{{ $t('user.verified') }}</span>
        <input type="checkbox" v-model="result.verified">
      </label>

      <div
        v-if="getDataFromObject(result, 'roles.0.name', getDataFromObject(result, 'roles.0', null)) === 'vendor'"
        class="input-wrapper"
      >
        <label>
          {{ $t('user.com') }}
        </label>
        <input
          type="number"
          step="any"
          :placeholder="$t('user.eg')"
          name="commission"
          v-model.trim="result.commission"
        >
      </div>

    </template>
  </data-page>
</template>
<script>

  import DataPage from '~/components/partials/DataPage'
  import Dropdown from '~/components/Dropdown'
  import {mapGetters, mapActions} from 'vuex'
  import validation from '~/mixin/validation'
  import util from "~/mixin/util"
  import {debounce} from "debounce";
  import PasswordField from "../../components/PasswordField";

  export default {
    name: "admins-vendors",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        validationKeys: ['username', 'email'],
        roles: null,
        result: {
          id: '',
          name: '',
          active: 0,
          verified: 0,
          username: '',
          commission: '',
          password: '',
          confirm_password: '',
          roles: [],
          email: ''
        }
      }
    },

    mixins: [util, validation],
    components: {
      PasswordField,
      DataPage,
      Dropdown
    },
    watch: {
    },
    computed: {
      id(){
        return this.result?.id
      },
      isInvalidEmail() {
        return (this.result.email && !this.isValidEmail(this.result.email))
      },
      ...mapGetters('common', ['allRoles'])
    },
    methods: {
      submitForm() {
        if(!this.id){
          this.validationKeys.push('password')
          this.validationKeys.push('confirm_password')
        }

        this.$refs.dataPage.checkForm()
      },
      setResult(event){
        if(event.roles.length){
          event.roles = event.roles.map(i => {
            return i.name
          })
        } else{
          event.roles = [Object.values(this.allRoles)[0].title]
        }
        this.result = event
      },
      activatedSelected(data){
        this.result.active = data.key
      },
      dropdownSelected(data){
        this.result.roles = [data.key]
      },
      ...mapActions('common', ['getAllList'])
    },
    async mounted() {
      if(!this.allRoles){
        try {
          await this.getAllList({api: 'allRoles', mutation: 'SET_ALL_ROLES'})
        } catch (e) {
          return this.$nuxt.error(e)
        }
      }
    }
  }
</script>

<style scoped>

</style>
