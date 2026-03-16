<template>
  <inline-pop-over
    :title="$t('profile.um')"
    @open="fetchMessages"
    ref="messagePop"
  >
    <template
      v-slot:button
    >
      <i
        class="icon user-messages"
      />
      <span class="hide-sm">
        {{ $t('user.msg') }}
      </span>
      ({{ messageCount }})
    </template>
    <template
      v-slot:content
    >
      <div
        class="user-messages-wrapper"
        :class="{'mn-h-200x': loading}"
      >
        <div
          v-if="loading"
          class="spinner-wrapper"
        >
          <spinner
            :radius="40"
            color="primary"
          />
        </div>
        <div v-else-if="userMessages.length">
          <nuxt-link
            v-for="(item, index) in userMessages"
            :key="index"
            :to="`/user-messages/${item.id}`"
            @click.native="closePop"
            class="b-t block ptb-10 plr-15 item-hover"
            :class="{'new-record': isNewRecord(item.viewed) && !alreadyFetched}"
          >
            <h5>
              {{ item.name }}(<span class="f-8">{{ item.email }}</span>)
            </h5>
            <p>{{ item.subject }}</p>
            <p>{{ item.created }}</p>
          </nuxt-link>
        </div>
        <p
          v-else
          class="p-15"
        >
          {{ $t('list.noData', { data: $t('user.msg') }) }}
        </p>

      </div>

    </template>
    <template
      v-slot:footer
    >
      <h5
        class="centered p-15"
      >
        <nuxt-link
          class="link"
          to="/user-messages"
          @click.native="closePop"
        >
          {{ $t('list.vam') }}
        </nuxt-link>
      </h5>
    </template>
  </inline-pop-over>
</template>

<script>
  import util from '~/mixin/util'
  import {mapGetters, mapActions} from 'vuex'
  import Spinner from "~/components/Spinner";
  import InlinePopOver from "~/components/InlinePopOver";

  export default {
    name: "UserMessages",
    props: {
    },
    data(){
      return {
        alreadyFetched: false,
        loading: false,
      }
    },
    components: {
      Spinner,
      InlinePopOver
    },
    mixins: [util],
    computed: {
      ...mapGetters('user-message', ['userMessages', 'messageCount'] )
    },
    methods:{
      closePop(){
        this.$refs.messagePop.closePop()
      },
      async fetchMessages(){
        if(this.userMessages){
          this.alreadyFetched = true
          return false
        }
        this.loading = true
        try {
          await this.getUserMessages({count: 10, time_zone: this.timeZone})
        } catch (e) {
          this.closePop()
          return this.$nuxt.error(e)
        }
        this.loading = false
      },
      ...mapActions('user-message', ['getUserMessages'] )
    },
    mounted() {
    }
  }
</script>

<style scoped>

</style>
