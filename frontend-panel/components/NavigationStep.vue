<template>
  <div class="container py-5 d-flex justify-content-center">
    <div class="steps" :style="progressStyle">

      <!-- Step 1 -->
      <div class="step"
        :class="{
          completed: currentStep > 1,
          active: currentStep === 1
        }">
        <div class="circle">
          {{ currentStep > 1 ? '✓' : '1' }}
        </div>
        <div class="step-label">Cart</div>
      </div>

      <!-- Step 2 -->
      <div class="step"
        :class="{
          completed: currentStep > 2,
          active: currentStep === 2
        }">
        <div class="circle">
          {{ currentStep > 2 ? '✓' : '2' }}
        </div>
        <div class="step-label">Details</div>
      </div>

      <!-- Step 3 -->
      <div class="step"
        :class="{
          completed: currentStep > 3,
          active: currentStep === 3
        }">
        <div class="circle">
          {{ currentStep > 3 ? '✓' : '3' }}
        </div>
        <div class="step-label">Payment</div>
      </div>

      <!-- Step 4 -->
      <div class="step"
        :class="{
          completed: currentStep > 4,
          active: currentStep === 4
        }">
        <div class="circle">
          {{ currentStep > 4 ? '✓' : '4' }}
        </div>
        <div class="step-label">Complete</div>
      </div>

    </div>
  </div>
</template>
<script>
export default {
    name: 'NavigationStep',
    computed:{
        currentStep() {
            const path = this.$route.path

            if (path.includes('cart')) return 1
            if (path.includes('shipping')) return 2
            if (path.includes('checkout') || path.includes('user/order/bank')) return 3
            if (path.includes('complete')) return 4

            return 1
        },
        progressStyle() {
            const stepWidth = 100 / 3 // 3 gaps between 4 steps
            const progress = (this.currentStep - 1) * stepWidth

            return {
            '--progress-width': `${progress}%`
            }
        }
    }
}
</script>
<style scoped>
.steps {
    display: flex;
    align-items: center;
    position: relative;
    gap: 60px;
}

/* Line (full) */
.steps::before {
    content: "";
    position: absolute;
    top: 18px;
    left: 35px;
    right: 35px;
    height: 2px;
    background: #e0e0e0;
    z-index: 0;
}

/* Active line */
.steps::after {
    content: "";
    position: absolute;
    top: 18px;
    left: 35px;
    height: 2px;
    background: #4f46e5;
    z-index: 1;
    transition: width 0.3s ease;
    width: var(--progress-width, 0%);
}

/* Step */
.step {
    text-align: center;
    position: relative;
    z-index: 2;
}

/* Circle */
.circle {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    color: #555;
    margin: auto;
}

/* Active / Completed */
.step.active .circle,
.step.completed .circle {
    background: #33319A;
    color: #fff;
}

/* Label */
.step-label {
    margin-top: 8px;
    font-size: 14px;
    color: #6b7280;
}
</style>