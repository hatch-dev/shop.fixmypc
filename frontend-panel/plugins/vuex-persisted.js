import createPersistedState from 'vuex-persistedstate'

export default ({ store }) => {
  createPersistedState({
    key: 'shop',
    paths: ['cart'],
  })(store)
}