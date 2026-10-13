<template>
  <div class="auth-password-control">
    <input
      :type="passwordFieldType"
      class="auth-input pe-5 position-relative"
      :placeholder="$t('contact.your', { type: $t('login.password') })"
      v-model.trim="password"
      :class="{ invalid: isInvalid }"
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
    name: 'AuthPasswordField',
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
    watch: {
      password(value) {
        this.$emit('change', value)
      },
      value(value) {
        if (value !== this.password) {
          this.password = value
        }
      }
    },
    mounted() {
      this.password = this.value
    }
  }
</script>
<style scoped>
/* Mirrors the shared .auth-input in layouts/empty.vue; repeated here because
 * this block is scoped and would otherwise lose to nothing at all. */
.auth-input {
    width: 100%;
    padding: 14px 52px 14px 16px;
    background-color: #FFFFFF;
    border: 1px solid var(--auth-line-strong, #CFD1E8);
    border-radius: var(--auth-radius, 14px);
    font-size: 15px;
    color: var(--auth-ink, #16174A);
    outline: none;
    transition: border-color .15s, box-shadow .15s;
}

.auth-input:focus {
    border-color: var(--auth-brand, #2D2DA0);
    box-shadow: 0 0 0 4px rgba(45, 45, 160, .10);
}

.auth-input::placeholder {
    color: #9AA0BC;
    opacity: 1;
}

.auth-input.invalid {
    border-color: var(--auth-danger, #C8324B);
    box-shadow: 0 0 0 4px rgba(200, 50, 75, .09);
}

.auth-input-container {
    position: relative;
    width: 100%;
}

.auth-label {
  display: block;
  font-size: 13.5px;
  font-weight: 600;
  color: var(--auth-ink, #16174A);
  margin-bottom: 7px;
}
/*
 * The toggle is anchored to this wrapper (not the surrounding form group), so it
 * stays centred on the input no matter what is rendered below it — strength bar,
 * requirement list, error text.
 */
.auth-password-control {
    position: relative;
}

.auth-password-control .password-check {
    position: absolute;
    right: 18px;
    top: 50%;
    transform: translateY(-50%);
    cursor: pointer;
    z-index: 10;
    color: var(--auth-muted, #6B7191);
    font-size: 18px;
}

.auth-password-control .password-check:hover {
    color: var(--auth-brand, #2D2DA0);
}
</style>
