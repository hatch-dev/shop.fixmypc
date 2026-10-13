<template>
  <div
    v-if="password"
    class="pw-strength"
  >

    <div class="pw-strength-track">
      <span
        v-for="segment in 3"
        :key="segment"
        class="pw-strength-segment"
        :class="segment <= level ? strength : ''"
      />
    </div>

    <div class="pw-strength-meta">
      <span>Password strength</span>
      <strong :class="strength">
        {{ label }}
      </strong>
    </div>

    <ul
      v-if="strength !== 'strong'"
      class="pw-strength-rules"
    >
      <li :class="{ passed: checks.length }">
        At least {{ minPasswordLength }} characters
      </li>
      <li :class="{ passed: checks.letterCase }">
        Upper &amp; lowercase letters
      </li>
      <li :class="{ passed: checks.number }">
        At least one number
      </li>
      <li :class="{ passed: checks.symbol }">
        At least one symbol
      </li>
    </ul>

  </div>
</template>

<script>
  import passwordStrength from '~/mixin/passwordStrength'

  export default {
    name: 'PasswordStrength',
    mixins: [passwordStrength],
    props: {
      password: {
        type: String,
        default: ''
      }
    },
    computed: {
      checks() {
        return this.passwordChecks(this.password)
      },
      strength() {
        return this.passwordStrength(this.password)
      },
      level() {
        return {weak: 1, medium: 2, strong: 3}[this.strength] || 0
      },
      label() {
        return {
          weak: 'Weak',
          medium: 'Medium',
          strong: 'Strong'
        }[this.strength] || ''
      }
    }
  }
</script>

<style scoped>
.pw-strength {
  margin-top: 12px;
}

.pw-strength-track {
  display: flex;
  gap: 6px;
}

.pw-strength-segment {
  flex: 1;
  height: 6px;
  border-radius: 6px;
  background: #E4E4F3;
  transition: background-color 0.25s ease;
}

.pw-strength-segment.weak {
  background: #ff4d4f;
}

.pw-strength-segment.medium {
  background: #f5a623;
}

.pw-strength-segment.strong {
  background: #00c543;
}

.pw-strength-meta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 8px;
  font-size: 13px;
  font-weight: 500;
  color: #69679B;
}

.pw-strength-meta strong.weak {
  color: #ff4d4f;
}

.pw-strength-meta strong.medium {
  color: #f5a623;
}

.pw-strength-meta strong.strong {
  color: #00c543;
}

.pw-strength-rules {
  list-style: none;
  padding: 0;
  margin: 10px 0 0;
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 4px 16px;
}

.pw-strength-rules li {
  position: relative;
  padding-left: 20px;
  font-size: 12px;
  font-weight: 500;
  color: #9694B8;
}

.pw-strength-rules li::before {
  content: '';
  position: absolute;
  left: 0;
  top: 50%;
  width: 12px;
  height: 12px;
  margin-top: -6px;
  border: 1px solid #D8D8EA;
  border-radius: 50%;
  box-sizing: border-box;
}

.pw-strength-rules li.passed {
  color: #232159;
}

.pw-strength-rules li.passed::before {
  background: #00c543;
  border-color: #00c543;
}

.pw-strength-rules li.passed::after {
  content: '';
  position: absolute;
  left: 4px;
  top: 50%;
  width: 3px;
  height: 6px;
  margin-top: -4px;
  border: solid #fff;
  border-width: 0 2px 2px 0;
  transform: rotate(45deg);
}

@media (max-width: 480px) {
  .pw-strength-rules {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
