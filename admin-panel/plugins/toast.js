import Vue from 'vue'
import Toast from 'vue-toastification'
import 'vue-toastification/dist/index.css'

Vue.use(Toast, {
  position: 'top-right',
  timeout: 2500,
  closeOnClick: true,
  pauseOnHover: true,
  draggable: true,
  hideProgressBar: false
})