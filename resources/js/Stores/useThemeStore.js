import { defineStore } from 'pinia'
import { ref, computed } from 'vue'

const STORAGE_KEY = 'theme'

function resolveInitial() {
    if (typeof window === 'undefined') return 'light'
    const saved = localStorage.getItem(STORAGE_KEY)
    if (saved === 'dark' || saved === 'light') return saved
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
}

export const useThemeStore = defineStore('theme', () => {
    const theme = ref('light')
    const initialized = ref(false)
    const isDark = computed(() => theme.value === 'dark')

    function apply() {
        if (typeof document === 'undefined') return
        document.documentElement.classList.toggle('dark', theme.value === 'dark')
    }

    // Đồng bộ state với class .dark mà inline script đã đặt sẵn (chống nhấp nháy)
    function init() {
        if (initialized.value) return
        theme.value = resolveInitial()
        apply()
        initialized.value = true
    }

    function toggle() {
        theme.value = theme.value === 'dark' ? 'light' : 'dark'
        if (typeof window !== 'undefined') {
            localStorage.setItem(STORAGE_KEY, theme.value)
        }
        apply()
    }

    return { theme, isDark, init, toggle }
})
