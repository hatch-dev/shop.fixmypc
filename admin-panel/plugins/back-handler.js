export default ({ app }) => {
  if (process.client) {
    window.addEventListener('popstate', () => {
      console.log('Back button pressed')
    })
  }
}