export const MIN_PASSWORD_LENGTH = 8

export default {
  data() {
    return {
      minPasswordLength: MIN_PASSWORD_LENGTH
    }
  },
  methods: {
    passwordChecks(password) {
      const value = password || ''

      return {
        length: value.length >= this.minPasswordLength,
        letterCase: /[a-z]/.test(value) && /[A-Z]/.test(value),
        number: /\d/.test(value),
        symbol: /[^A-Za-z0-9]/.test(value)
      }
    },
    passwordVariety(password) {
      const value = password || ''

      return [/[a-z]/, /[A-Z]/, /\d/, /[^A-Za-z0-9]/]
        .filter(pattern => pattern.test(value))
        .length
    },
    passwordScore(password) {
      const value = password || ''

      if (!value) {
        return 0
      }

      const checks = this.passwordChecks(value)

      let score = Object.keys(checks).filter(key => checks[key]).length

      if (value.length >= 12 && score >= 3) {
        score += 1
      }

      return score
    },
    passwordStrength(password) {
      const value = password || ''

      if (!value) {
        return null
      }

      if (value.length < this.minPasswordLength) {
        return 'weak'
      }

      if (this.passwordVariety(value) <= 1) {
        return 'weak'
      }

      const score = this.passwordScore(value)

      if (score <= 1) {
        return 'weak'
      }

      return score >= 4 ? 'strong' : 'medium'
    }
  }
}
