<template>
  <div class="input-group custom-input">
    <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
    <input
      :id="inputId"
      :type="passwordFieldType"
      class="form-control pe-5 position-relative"
      :placeholder="$t('contact.your', { type: $t('login.password') })"
      v-model.trim="password"
      :class="{ invalid: !!!password || isInvalid }"
      @change="$emit('change', password)"
    />

    <i
      class="icon position-absolute password-check"
      :class="!isPasswordTypePassword ? 'eye-icon' : 'eye-close-icon'"
      @click="passwordFieldToggle"
    />
  </div>

</template>

<script>
  export default {
    name: 'PasswordField',
    data() {
      return {
        password: '',
        passwordFieldType: 'password'
      }
    },
    props: {
      isInvalid: {
        type: Boolean,
        default: false
      },
      value: {
        type: String
      },
      /*
       * Put on the input rather than the wrapper, so a <label for> points at
       * something focusable. Without it the id fell through to the outer div
       * and the label did nothing — clicking it moved no cursor, and a screen
       * reader announced the field unlabelled.
       */
      inputId: {
        type: String,
        default: null
      }
    },
    mixins: [],
    components: {},
    computed: {
      isPasswordTypePassword(){
        return this.passwordFieldType === 'password'
      },
    },
    methods: {
      passwordFieldToggle(){
        if(this.isPasswordTypePassword){
          this.passwordFieldType = 'text'
        } else {
          this.passwordFieldType = 'password'
        }
      },
    },
    mounted() {
      this.password = this.value
    }
  }
</script>
<style scoped>

 span.input-group-text {
      background-color: transparent;
      padding: 10px 16px;
    }

    .password-check {
    right: 10px;
    margin-top: 10px;
}
</style>
