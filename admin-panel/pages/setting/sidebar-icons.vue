<template>
  <setting-layout
    active-route="sidebar-icons"
    class="mb-5"
  >
    <template v-slot:rightArea>
      <p class="dashboard-notice info-msg mb-20 mb-sm-15">
        <b>{{ $t('setting.imp') }}</b>: {{ $t('setting.sidebarIconsMsg') }}
      </p>

      <div class="sidebar-icons-list">
        <div
          v-for="item in menuItems"
          :key="item.key"
          class="sidebar-icon-row"
        >
          <span
            v-if="previewFor(item).svg"
            class="sidebar-icon-preview"
            v-html="previewFor(item).svg"
          ></span>
          <i
            v-else
            class="sidebar-icon-preview"
            :class="previewFor(item).class"
          ></i>

          <div class="sidebar-icon-info">
            <span class="sidebar-icon-label">{{ item.title }}</span>
            <span
              class="sidebar-icon-tag"
              :class="{custom: !!icons[item.key]}"
            >
              {{ icons[item.key] ? $t('setting.sidebarIconCustom') : $t('setting.sidebarIconDefault') }}
            </span>
          </div>

          <div class="sidebar-icon-actions">
            <label class="upload-btn" :class="{disabled: uploading === item.key}">
              <input
                type="file"
                accept=".svg,image/svg+xml"
                :disabled="uploading === item.key"
                @change="onFile(item, $event)"
              >
              {{ uploading === item.key ? $t('setting.sidebarIconUploading') : $t('profile.upload') }}
            </label>

            <button
              v-if="icons[item.key]"
              type="button"
              class="reset-btn"
              :disabled="uploading === item.key"
              @click="resetIcon(item)"
            >
              {{ $t('setting.sidebarIconReset') }}
            </button>
          </div>
        </div>
      </div>
    </template>
  </setting-layout>
</template>

<script>

  import SettingLayout from "~/components/partials/SettingLayout";
  import {sidebarMenuItems} from "~/mixin/sidebar-menu";
  import {mapActions} from 'vuex'

  export default {
    name: "sidebar-icons",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        menuItems: sidebarMenuItems(this.$t.bind(this)),
        icons: {},
        uploading: null,
      }
    },
    components: {
      SettingLayout
    },
    methods: {
      // Decide what to render in the preview: a custom uploaded SVG, the built-in
      // inline-SVG default, or a Font Awesome class.
      previewFor(item) {
        const custom = this.icons[item.key]
        if (custom) {
          return {svg: custom}
        }
        if (typeof item.defaultIcon === 'string' && item.defaultIcon.trim().startsWith('<svg')) {
          return {svg: item.defaultIcon}
        }
        return {class: item.defaultIcon}
      },
      applyResult(data) {
        // setRequest returns the icons map on success (200); on a validation
        // error (201) it returns the response object which carries `.status`.
        if (data && data.status === undefined) {
          this.icons = data
          this.$root.$emit('sidebar-icons-updated', data)
        }
      },
      async onFile(item, event) {
        const file = event.target.files && event.target.files[0]
        event.target.value = ''
        if (!file) {
          return
        }

        this.uploading = item.key
        try {
          const fd = new FormData()
          fd.append('menu_key', item.key)
          fd.append('photo', file)

          const data = await this.setRequest({params: fd, api: 'sidebarIconsAction'})
          this.applyResult(data)
        } catch (e) {
          return this.$nuxt.error(e)
        } finally {
          this.uploading = null
        }
      },
      async resetIcon(item) {
        this.uploading = item.key
        try {
          const data = await this.setRequest({params: {menu_key: item.key}, api: 'sidebarIconsDelete'})
          this.applyResult(data)
        } catch (e) {
          return this.$nuxt.error(e)
        } finally {
          this.uploading = null
        }
      },
      async fetchingData() {
        try {
          const data = await this.getRequest({api: 'sidebarIconsFind'})
          this.icons = data || {}
        } catch (e) {
          return this.$nuxt.error(e)
        }
      },
      ...mapActions('common', ['setRequest', 'getRequest'])
    },
    async mounted() {
      await this.fetchingData()
    }
  }
</script>

<style scoped>
.sidebar-icons-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.sidebar-icon-row {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 10px 12px;
  border: 1px solid #EEF2F7;
  border-radius: 10px;
}
.sidebar-icon-preview {
  width: 22px;
  min-width: 22px;
  height: 22px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  color: var(--text-muted);
}
.sidebar-icon-preview >>> svg {
  width: 18px;
  height: 18px;
  max-width: 100%;
  max-height: 100%;
}
.sidebar-icon-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
  flex: 1;
  min-width: 0;
}
.sidebar-icon-label {
  font-weight: 600;
}
.sidebar-icon-tag {
  font-size: 11px;
  color: var(--text-muted);
}
.sidebar-icon-tag.custom {
  color: var(--primary, #333199);
}
.sidebar-icon-actions {
  display: flex;
  align-items: center;
  gap: 8px;
}
.upload-btn {
  position: relative;
  overflow: hidden;
  cursor: pointer;
  padding: 6px 14px;
  border-radius: 8px;
  border: 1px solid var(--primary, #333199);
  color: var(--primary, #333199);
  font-size: 13px;
  white-space: nowrap;
}
.upload-btn.disabled {
  opacity: 0.6;
  pointer-events: none;
}
.upload-btn input[type="file"] {
  position: absolute;
  inset: 0;
  opacity: 0;
  cursor: pointer;
  width: 100%;
  height: 100%;
}
.reset-btn {
  padding: 0px 14px;
  border-radius: 8px;
  border: 1px solid #E2433B;
  background: transparent;
  color: #E2433B;
  font-size: 13px;
  cursor: pointer;
  white-space: nowrap;
}
.reset-btn:disabled {
  opacity: 0.6;
  cursor: default;
}
</style>
