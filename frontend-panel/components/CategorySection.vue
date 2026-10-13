<template>
    <div class="category-box p-4 mb-4">

      <!-- Header -->
      <div class="d-flex justify-content-between align-items-center mb-4">
        <h5 class="slider-collection-heading">Featured Categories</h5>
        <nuxt-link :to="`/categories`" class="link">
            Show all
        </nuxt-link>
      </div>

      <!-- Grid -->
      <div class="row g-4 text-center">
        <div
            v-for="cat in featured_categories.slice(0, 12)"
            :key="cat.id"
            class="col-6 col-md-4 col-lg-2"
        >
            <nuxt-link :to="`/all/${cat.slug}`" class="category-item">
                <div class="icon-circle">
                    <img
                        :src="getImage(cat.image)"
                        alt=""
                     />
                </div>
                <p>{{ cat.title }}</p>
            </nuxt-link>
        </div>
      </div>
    </div>
</template>
<script>
  export default {
    name: 'CategorySection',
    props: {
        featured_categories: {
        type: Array,
        default: () => []
        }
    },
    methods: {
        getImage(img) {
            const base = 'https://shop.fixmypc.ie/uploads/';
            return img.startsWith('http') ? img : base + img
        }
    }
  }
</script>

<style scoped>
.category-box {
    background: #F3F5FC;
    border-radius: 30px;
    border: 1px solid #E3E2F1;
}

.category-item {
    text-align: center;
}

.icon-circle {
    align-items: center;
    background: #fff;
    border: 1px solid #e0e3ea;
    border-radius: 50%;
    color: #4b3bbd;
    display: flex;
    font-size: 34px;
    height: 200px;
    justify-content: center;
    margin: auto;
    transition: .3s;
    width: 200px;
}
.icon-circle img {
    width: 100px;
    height: 100px;
}
.category-item p {
    margin-top: 10px;
    font-size: 18px;
    font-weight: 500;
}

.category-item:hover .icon-circle {
    transform: translateY(-5px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.08);
    background: #fff;
}

.show-all {
    font-size: 13px;
    text-decoration: none;
    color: #000;
}

@media (max-width:1035px){
 .icon-circle{
    width: 150px;
    height: 150px;
 }  
 .icon-circle img{
    height: 80px;
    width: 80px;
 }
}
@media (max-width:500px){
 .icon-circle{
    width: 110px;
    height: 110px;
 }  
}
</style>