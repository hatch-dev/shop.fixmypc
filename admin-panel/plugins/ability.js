import { c } from '@/static/permissions';
import { AbilityBuilder, defineAbility } from '@casl/ability';
export const ability = defineAbility(can => c(can));

export default function({ store }) {
  const defineRulesFor = (admin) => {
    const permissions = admin.permissions
    const { can, rules } = new AbilityBuilder()

    // This logic depends on how the
    // server sends you the permissions array
    if (permissions) {
      permissions.forEach((p) => {
        let per = p.split(".")
        can(per[0], per[1])
      })
    }
    return rules
  }

  return store.subscribe((mutation) => {
    switch (mutation.type) {
      case 'admin/SET_PERMISSIONS':
        ability.update(defineRulesFor(store.state.admin))
        break
    }
  })
}
