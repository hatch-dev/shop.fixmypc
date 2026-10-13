<template>
    <section v-if="showBanner" class="pr-banner-top-offer">
        <button
            class="pr-banner-close"
            @click="closeBanner"
        >
            ×
        </button>
        <div class="container-fluid">
            <div class="pr-banner-inner">

                <div class="pr-banner-text">
                    <span>SHOP AND</span>
                    <strong>SAVE BIG</strong>
                    <span>ON LATEST PRODUCT</span>
                </div>
                
                <div class="pr-banner-center-div">
                   <div class="pr-banner-price-box">
                    <span class="pr-banner-label">
                        STARTING FROM
                    </span>

                    <span class="pr-banner-price">
                        €55
                    </span>
                </div>

                <div class="pr-banner-discount-wrap">
                    <span class="pr-banner-up">UP TO</span>

                    <span class="pr-banner-discount">
                        30%
                    </span>

                    <span class="pr-banner-off">
                        OFF
                    </span>
                </div>

                </div>
                  <div class="pr-banner-images">
                        <img src="~/assets/images/toppromobanner/p-i-1.png" alt="">
                        <img src="~/assets/images/toppromobanner/p-i-2.png" alt="">
                </div>
            </div>
        </div>

    </section>
</template>
<script>

export default {
  data() {
    return {
      showBanner: true
    }
  },

  mounted() {
    const hidden =
      localStorage.getItem(
        'topPromoBannerClosed'
      )

    if (hidden === 'true') {
      this.showBanner = false
    }
  },

  methods: {
    closeBanner() {
      this.showBanner = false
      localStorage.setItem(
        'topPromoBannerClosed',
        'true'
      )
    }
  }
}
</script>
<style scoped>
.pr-banner-top-offer {
	width: 100%;
	background: #d4dfff;
	overflow: hidden;
    position: relative;
}

.pr-banner-close {
  position: absolute;
  top: 50%;
  right: 15px;
  transform: translateY(-50%);
  width: 30px;
  height: 30px;
  border-radius: 50%;
  border: none;
  background: rgba(0,0,0,.08);
  color: #130e2b;
  font-size: 20px;
  line-height: 1;
  cursor: pointer;
  z-index: 5;
  transition: all 0.7s ease;
}

.pr-banner-close:hover {
  background: rgba(0,0,0,.15);
}

.pr-banner-inner {
	height: 76px;
	margin: auto;
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 28px;
}

.pr-banner-text {
	display: flex;
	align-items: center;
	gap: 20px;
	font-size: 20px;
	color: #130e2b;
	white-space: nowrap;
}

.pr-banner-text strong {
	font-size: 20px;
	font-weight: 700;
}

.pr-banner-price-box {
	background: #fff;
	border-radius: 50px;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 14px;
	padding: 10px 28px;
}

.pr-banner-label {
	font-size: 14px;
	color: #130e2b;
}

.pr-banner-price {
	font-size: 24px;
	font-weight: 700;
	color: #121212;
}

.pr-banner-discount-wrap {
	display: flex;
	align-items: center;
	gap: 12px;
	color: #252535;
}

.pr-banner-center-div {
	display: flex;
	gap: 20px;
	align-items: center;
}

.pr-banner-up {
	font-size: 12px;
	color: #130e2b;
	line-height: 1;
	width: 18px;
}

.pr-banner-discount {
	font-size: 26px;
	font-weight: 800;
	color: #05b942;
}

.pr-banner-off {
	font-size: 24px;
	font-weight: 400;
}

.pr-banner-images {
	display: flex;
	align-items: flex-end;
	position: relative;
}

.pr-banner-images img {
	display: block;
	height: 80px;
	object-fit: contain;
	margin-bottom: -15px;
}
@media (max-width: 1026px) {
 .pr-banner-inner{
    gap:15px;
 }   
 .pr-banner-text{
    gap:10px;
 }
 .pr-banner-text{
    font-size: 16px;
 }
 .pr-banner-price-box{
        padding: 6px 12px;
 }
}
@media (max-width: 768px) {
	.pr-banner-inner {
		text-align: center;
		gap: 10px;
        padding: 10px 0px;
        height: auto;
	}
  .pr-banner-text {
		flex-wrap: wrap;
		font-size: 16px;
	}
     .pr-banner-center-div{
        gap:10px
     }
     .pr-banner-text strong{
        font-size: 16px;
     }
     .pr-banner-price-box{
      padding: 6px 12px;
     }
     .pr-banner-label{
        font-size: 18px;
     }
    .pr-banner-price {
		font-size: 16px;
	}
    .pr-banner-images{
        display: none;
    }    
}

@media (max-width: 576px) {
	.pr-banner-inner {
		flex-direction: column;
		text-align: center;
		gap: 10px;
        padding: 10px 0px;
	}

	.pr-banner-text {
		flex-wrap: wrap;
		justify-content: center;
		font-size: 12px;
	}
     .pr-banner-center-div{
        gap:10px
     }
     .pr-banner-text strong{
        font-size: 16px;
     }
     .pr-banner-price-box{
      padding: 6px 12px;
     }
     .pr-banner-label{
        font-size: 12px;
     }
    .pr-banner-price {
		font-size: 16px;
	}
  .pr-banner-images {
    display: none;
  }
  .pr-banner-close{
    top: 24%;
    right: 9px;
    transform: translateY(-50%);
    width: 20px;
    height: 20px;
    font-size: 17px;
  }
}
</style>