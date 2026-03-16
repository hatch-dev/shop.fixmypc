export default {
  data() {
    return {
      itemList: [],
      cbList: [],
    }
  },
  methods: {
    checkAll(evt){
      if(evt.target.checked){
        this.cbList = this.itemList.map(i=>{
          return i.id
        })
      }else {
        this.cbList = []
      }
    },
    deleteBulk(){
      if(this.cbList?.length){
        this.$refs.listPage.deleteItem(this.cbList.join(','))
      }
    },
  }
}
