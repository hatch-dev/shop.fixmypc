import Vue from 'vue'
import DialogHost from '~/components/DialogHost.vue'

/*
 * this.$dialog: the shop's confirmations, notices and questions, in place of
 * the browser's own confirm(), alert() and prompt().
 *
 * Those stopped the page, showed the site's address as their heading and
 * looked like the browser rather than the shop. Each method returns a
 * promise, so a call site reads as it did:
 *
 *   if (!(await this.$dialog.confirm(message))) return
 *   const value = await this.$dialog.prompt(question)   // null when cancelled
 *   await this.$dialog.alert(message)
 *
 * Options: title, confirmLabel, cancelLabel, tone ('danger' | 'primary'),
 * icon; prompt also takes value, placeholder, required, inputType.
 * confirm() is styled as a destructive act unless tone: 'primary' is passed.
 */
export default function ({app}, inject) {
  const state = Vue.observable({queue: []})

  const ask = (kind, message, options = {}) => new Promise((resolve) => {
    state.queue.push({kind, message: message == null ? '' : String(message), options, resolve})
  })

  const host = new Vue({
    i18n: app.i18n,
    render: h => h(DialogHost, {props: {state}})
  }).$mount()
  document.body.appendChild(host.$el)

  inject('dialog', {
    confirm: (message, options) => ask('confirm', message, options),
    alert: (message, options) => ask('alert', message, options),
    prompt: (message, options) => ask('prompt', message, options)
  })
}
