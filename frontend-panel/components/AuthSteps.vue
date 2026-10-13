<template>

  <div class="auth-steps">

    <template v-for="(step, index) in steps">

      <span
        :key="step.key"
        class="auth-step"
        :class="{
          on: index === currentIndex,
          done: index < currentIndex
        }"
      >
        <i>{{ index < currentIndex ? '✓' : index + 1 }}</i>
        {{ step.label }}
      </span>

      <span
        v-if="index < steps.length - 1"
        :key="`line-${step.key}`"
        class="auth-step-line"
      />

    </template>

  </div>

</template>

<script>

export default {

  name: "AuthSteps",

  props: {

    steps: {
      type: Array,
      required: true
    },

    current: {
      type: String,
      required: true
    }
  },

  computed: {

    currentIndex() {
      return Math.max(this.steps.findIndex(i => i.key === this.current), 0)
    }
  }
}
</script>

<style scoped>

.auth-steps {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 30px;
}

.auth-step {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12.5px;
    font-weight: 600;
    color: var(--auth-muted, #6B7191);
    white-space: nowrap;
}

.auth-step i {
    width: 22px;
    height: 22px;
    flex: 0 0 auto;
    border-radius: 50%;
    background: var(--auth-line, #E4E5F3);
    color: var(--auth-muted, #6B7191);
    display: grid;
    place-items: center;
    font-style: normal;
    font-size: 11.5px;
    font-weight: 800;
}

.auth-step.on {
    color: var(--auth-brand, #2D2DA0);
}

.auth-step.on i {
    background: var(--auth-brand, #2D2DA0);
    color: #fff;
}

.auth-step.done i {
    background: var(--auth-go, #00A24A);
    color: #fff;
}

.auth-step-line {
    flex: 1;
    height: 1px;
    background: var(--auth-line, #E4E5F3);
    min-width: 8px;
}

/* Scoped, so the layout's compact rule can't reach this margin. */
@media (min-width: 1025px) and (max-height: 1100px) {

    .auth-steps {
        margin-bottom: 26px;
    }
}

@media (max-width: 560px) {

    .auth-step {
        font-size: 0;
        gap: 0;
    }

    .auth-step i {
        font-size: 11.5px;
    }
}

</style>
