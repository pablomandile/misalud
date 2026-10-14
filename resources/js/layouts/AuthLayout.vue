<script setup lang="ts">
import AvisoCookies from '@/components/AvisoCookies.vue';
import AuthLayout from '@/layouts/auth/AuthSimpleLayout.vue';

const { title = '', description = '' } = defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <AuthLayout :title="title" :description="description">
        <slot />
    </AuthLayout>
    <AvisoCookies />
</template>
