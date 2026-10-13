<template>
  <p
    v-if="list.length"
    class="field-error"
    role="alert"
  >
    <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
    <span>{{ list.join(' ') }}</span>
  </p>
</template>

<script>
  /**
   * One refusal, under the control that caused it.
   *
   * Validation messages used to be readable only as a list at the top of the
   * form — on a long page that meant reading "The meta title field is
   * required" and then hunting for the meta title field. `messages` is
   * whatever DataPage's `fieldErrors` holds for this field, so a field with
   * nothing wrong with it renders nothing at all.
   */
  export default {
    name: 'FieldError',
    props: {
      messages: {
        type: [Array, String],
        default: null
      }
    },
    computed: {
      list() {
        if (!this.messages) return []
        return Array.isArray(this.messages) ? this.messages : [this.messages]
      }
    }
  }
</script>
