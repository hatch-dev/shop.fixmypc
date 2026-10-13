export default async function ({$auth, redirect, route}) {

  if (!$auth.user) {
    return
  }

  /*
   * The signup wizard signs the user in the moment their email is confirmed,
   * then carries on into the optional two-factor step. Those last screens have
   * to stay reachable, or verifying would bounce them straight to the shop.
   */
  const name = route.name?.split('___')[0]
  const step = route.query?.step

  if (name === 'register' && ['security', 'done'].includes(step)) {
    return
  }

  redirect('/')
}
