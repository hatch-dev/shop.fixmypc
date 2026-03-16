<template>
  <data-page
    v-if="allPermissions"
    ref="dataPage"
    set-api="setRole"
    get-api="getRole"
    route-name="roles-permissions"
    empty-store-variable="allRoles"
    :name="$t('dataPage.rPer')"
    :validation-keys="['name']"
    :result="result"
    gate="role"
    @result="result = $event"
  >
    <template v-slot:form="{hasError}">
        <div class="input-wrapper">

          <label>{{ $t('user.name') }}</label>
          <input
            type="text"
            :placeholder="$t('user.name')"
            name="title"
            v-model="result.name"
            ref="title"
            :class="{invalid: !!!result.name && hasError}"
          >
          <span
            class="error"
            v-if="!!!result.name && hasError"
          >
            {{ $t('category.req', { type: $t('user.name')}) }}
          </span>
        </div>

        <div class="input-wrapper">
          <label>{{ $t('user.per') }}</label>
          <div class="b-b mb-10 mb-md-15 pb-10">
            <input
              class="styled-checkbox"
              :id="`styled-checkbox-all`"
              type="checkbox"
              v-model="allSelected"
              @change="selectAll"
            >
            <label
              :for="`styled-checkbox-all`"
              class="mtb-5"
            >
              {{ $t('index.all') }}
            </label>
          </div>

          <div
            v-for="(value, key, index) in groupBy(allPermissions, 'group_name')"
            :key="index"
            class="permission-group"
          >
            <div>
              <input
                v-model="groupPermissions"
                class="styled-checkbox"
                :id="`styled-checkbox-${key}`"
                type="checkbox"
                :value="key"
                @change="selectGroup(key, $event)"
              >
              <label
                :for="`styled-checkbox-${key}`"
                class="mtb-5 mt-md-15"
              >
                {{ formatGroupName(key) }}
              </label>
            </div>
            <div
              class="permission-item "
            >
              <span
                v-for="(i, ind) in value"
                :key="`${index}-${ind}`"
                class="mr-15"
              >
                <input
                  v-model="selectedPermissions"
                  class="styled-checkbox"
                  :id="`checkbox-${index}-${ind}`"
                  type="checkbox"
                  :value="i.id"
                  @change="permissionChanged"
                >
                <label
                  :for="`checkbox-${index}-${ind}`"
                  class="mtb-7-5"
                >
                  {{ formatPermissionName(i.name) }}
                </label>
              </span>
            </div>
          </div>
        </div>
    </template>
  </data-page>
</template>
<script>

  import DataPage from '~/components/partials/DataPage'
  import util from '~/mixin/util'
  import Dropdown from '~/components/Dropdown'
  import {mapGetters, mapActions} from 'vuex'


  export default {
    name: "roles-permissions",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        allSelected: false,
        groupPermissions: [],
        selectedPermissions: [],
        result: {
          id: '',
          name: '',
          updated_permissions: [],
          permissions: []
        }
      }
    },
    mixins: [util],
    components: {
      DataPage,
      Dropdown
    },
    watch:{
      resultPermissions(value) {
        this.selectedPermissions = value?.map(i => { return i.id })
      }
    },
    computed: {
      resultPermissions() {
        return this.result?.permissions
      },
      ...mapGetters('common', ['allPermissions'])
    },
    methods: {
      groupBy(arr, group){
        return arr.reduce((acc, obj) => {
          const key = obj[group];
          if (!acc[key]) {
            acc[key] = [];
          }
          // Add object to list for given key's value
          acc[key].push(obj);
          return acc;
        }, {});
      },
      formatPermissionName(name){
        return this.capitalizeFirstLetter(name?.replace('_', ' ' ).split('.')[1])
      },
      formatGroupName(name){
        return this.capitalizeFirstLetter(name?.replace(/\.|_/g, ' ' ))
      },
      capitalizeFirstLetter(string) {
        return string?.charAt(0).toUpperCase() + string?.slice(1);
      },
      selectAll() {
        this.groupPermissions = []
        this.selectedPermissions = []
        if (this.allSelected) {
          for (let i in this.allPermissions) {

            this.selectedPermissions.push(this.allPermissions[i].id)

            this.groupPermissions.push(this.allPermissions[i].group_name)
          }
        }
        this.groupPermissions = [...new Set(this.groupPermissions)]
        this.result.updated_permissions = this.selectedPermissions
      },
      selectGroup(data, event){
        const current = this.allPermissions.filter(i => {
          return i.group_name === data
        }).map(i => {
          return i.id
        })

        if (event.target.checked) {
          this.selectedPermissions = [...new Set([...this.selectedPermissions, ...current])]
        } else {
          this.selectedPermissions = this.selectedPermissions.filter(i => {
            return current.indexOf(i) === -1
          })
        }
        this.result.updated_permissions = this.selectedPermissions
      },
      permissionChanged() {
        this.groupPermissions = []
        this.allSelected = false
        this.result.updated_permissions = this.selectedPermissions
      },
      ...mapActions('common', ['getAllList'])
    },
    async mounted() {
      if(!this.allPermissions){
        try {
          await this.getAllList({api: 'allPermissions', mutation: 'SET_ALL_PERMISSIONS'})
        } catch (e) {
          return this.$nuxt.error(e)
        }
      }
    }
  }
</script>

<style scoped>

</style>
