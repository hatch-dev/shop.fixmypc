export default function ({ app, store,  $auth  }) {
  app.i18n.locale = store?.state?.language?.currentLanguage?.code

  const rememberExpires = $auth.$storage.getCookie('remember_expires');
  if (rememberExpires) {
    $auth.options.cookie.options.expires = rememberExpires
  } else {
    $auth.options.cookie.options.expires = null
  }
}
