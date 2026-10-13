<template>

  <div class="auth-otp">

    <input
      v-for="(digit, index) in digits"
      :key="index"
      :ref="`box-${index}`"
      class="auth-otp-box"
      :class="{ invalid: isInvalid, valid: isValid }"
      type="text"
      inputmode="numeric"
      autocomplete="one-time-code"
      maxlength="1"
      :readonly="isValid"
      :aria-label="`Digit ${index + 1}`"
      :value="digit"
      @input="onInput(index, $event)"
      @keydown.delete="onBackspace(index, $event)"
      @keydown.left.prevent="focusBox(index - 1)"
      @keydown.right.prevent="focusBox(index + 1)"
      @paste.prevent="onPaste"
      @focus="$event.target.select()"
    >

  </div>

</template>

<script>

export default {

  name: "OtpInput",

  props: {

    value: {
      type: String,
      default: ''
    },

    length: {
      type: Number,
      default: 6
    },

    isInvalid: {
      type: Boolean,
      default: false
    },

    /* Accepted — the boxes go green and lock while the redirect is pending. */
    isValid: {
      type: Boolean,
      default: false
    }
  },

  computed: {

    digits() {

      const chars = (this.value || '').split('')

      return Array.from({length: this.length}, (item, index) => chars[index] || '')
    }
  },

  methods: {

    focusBox(index) {

      if (index < 0 || index >= this.length) return

      const box = this.$refs[`box-${index}`]
      const el = Array.isArray(box) ? box[0] : box

      if (el) {
        el.focus()
      }
    },

    emit(chars) {

      const code = chars.join('').slice(0, this.length)

      this.$emit('change', code)

      if (code.length === this.length) {
        this.$emit('complete', code)
      }
    },

    onInput(index, event) {

      // A box can receive several characters at once on mobile keyboards,
      // so spread whatever arrived across the boxes from here on.
      const typed = event.target.value.replace(/\D/g, '')
      const chars = this.digits.slice()

      if (!typed) {
        chars[index] = ''
        event.target.value = ''
        return this.emit(chars)
      }

      typed.split('').forEach((char, offset) => {
        if (index + offset < this.length) {
          chars[index + offset] = char
        }
      })

      event.target.value = chars[index]

      this.emit(chars)
      this.focusBox(Math.min(index + typed.length, this.length - 1))
    },

    onBackspace(index, event) {

      const chars = this.digits.slice()

      // Empty box: clear the one before it and step back.
      if (!chars[index]) {
        event.preventDefault()
        chars[Math.max(index - 1, 0)] = ''
        this.emit(chars)
        return this.focusBox(index - 1)
      }

      event.preventDefault()
      chars[index] = ''
      this.emit(chars)
    },

    onPaste(event) {

      const pasted = (event.clipboardData || window.clipboardData)
        .getData('text')
        .replace(/\D/g, '')
        .slice(0, this.length)

      if (!pasted) return

      const chars = Array.from({length: this.length}, (item, i) => pasted[i] || '')

      this.emit(chars)
      this.focusBox(Math.min(pasted.length, this.length - 1))
    },

    focusFirst() {
      this.$nextTick(() => this.focusBox(0))
    }
  },

  mounted() {
    this.focusFirst()
  }
}
</script>

<style scoped>

.auth-otp {
    display: flex;
    gap: 10px;
    margin: 6px 0 4px;
}

.auth-otp-box {
    flex: 1;
    min-width: 0;
    height: 60px;
    text-align: center;
    font-size: 24px;
    font-weight: 700;
    color: var(--auth-ink, #16174A);
    border: 1px solid var(--auth-line-strong, #CFD1E8);
    border-radius: var(--auth-radius, 14px);
    background: #fff;
    outline: none;
    transition: border-color .15s, box-shadow .15s;
}

.auth-otp-box:focus {
    border-color: var(--auth-brand, #2D2DA0);
    box-shadow: 0 0 0 4px rgba(45, 45, 160, .10);
}

.auth-otp-box.invalid {
    border-color: var(--auth-danger, #C8324B);
    box-shadow: 0 0 0 4px rgba(200, 50, 75, .09);
}

/* Mirrors the invalid state, so a correct code reads as clearly as a wrong one. */
.auth-otp-box.valid {
    border-color: var(--auth-go, #00A24A);
    box-shadow: 0 0 0 4px rgba(0, 162, 74, .12);
    color: #0B6B3A;
    background: #F6FCF8;
    cursor: default;
}

.auth-otp-box.valid:focus {
    border-color: var(--auth-go, #00A24A);
    box-shadow: 0 0 0 4px rgba(0, 162, 74, .12);
}

@media (max-width: 480px) {

    .auth-otp {
        gap: 8px;
    }

    .auth-otp-box {
        height: 54px;
        font-size: 21px;
    }
}

</style>
