<script setup lang="ts">
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'

definePage({ meta: { requiresAuth: true } })

// Extensions is now Features: keep old links and bookmarks working.
const router = useRouter()
onMounted(() => {
  void router.replace('/features')
})
</script>

<template>
  <div />
</template>
