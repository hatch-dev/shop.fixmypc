import Vue from 'vue'
import DialogHost from '~/components/DialogHost.vue'

/*
 * this.$dialog: the panel's confirmations, notices and questions, in place of
 * the browser's own confirm(), alert() and prompt().
 *
 * Those stopped the whole page, could not be styled or translated beyond
 * their one sentence, and looked like the browser rather than the panel. Each
 * method here returns a promise, so a call site reads as it did:
 *
 *   if (!(await this.$dialog.confirm(this.$t('admin.dltMsg')))) return
 *   const reason = await this.$dialog.prompt(question, {required: true})  // null when cancelled
 *   await this.$dialog.alert(message)
 *
 * Options: title, confirmLabel, cancelLabel, tone ('danger' | 'primary'),
 * warning, icon; prompt also takes value, placeholder, required, inputType.
 * confirm() is styled as a destructive act unless tone: 'primary' is passed.
 *
 * Questions asked while one is showing wait their turn.
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
    prompt: (message, options) => ask('prompt', message, options),
    /** The panel's standard "delete this?" question. */
    confirmDelete: (message, options = {}) => ask('confirm', message || app.i18n.t('admin.dltMsg'), {
      title: app.i18n.t('dialog.deleteTitle'),
      confirmLabel: app.i18n.t('dialog.delete'),
      icon: 'fa-solid fa-trash-can',
      ...options
    })
  })
}
