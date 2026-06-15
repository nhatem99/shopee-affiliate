import { defineStore } from 'pinia'
import { usePage, router } from '@inertiajs/vue3'
import { computed } from 'vue'

export const useAuthStore = defineStore('auth', () => {
    const page = usePage()
    const user = computed(() => page.props.auth?.user ?? null)
    const isLoggedIn = computed(() => !!user.value)
    const isAdmin = computed(() => user.value?.role === 'admin')

    function logout() {
        router.post('/logout')
    }

    return { user, isLoggedIn, isAdmin, logout }
})
