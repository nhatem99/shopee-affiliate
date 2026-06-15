import { defineStore } from 'pinia'

const STORAGE_KEY = 'theme'

function resolveInitial() {
    if (typeof window === 'undefined') return 'light'
    const saved = localStorage.getItem(STORAGE_KEY)
    if (saved === 'dark' || saved === 'light') return saved
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
}

export const useThemeStore = defineStore('theme', {
    state: () => ({
        theme: 'light',
        initialized: false,
    }),

    getters: {
        isDark: (state) => state.theme === 'dark',
    },

    actions: {
        // Đồng bộ state với class .dark mà inline script đã đặt sẵn (chống nhấp nháy)
        init() {
            if (this.initialized) return
            this.theme = resolveInitial()
            this.apply()
            this.initialized = true
        },

        apply() {
            if (typeof document === 'undefined') return
            document.documentElement.classList.toggle('dark', this.theme === 'dark')
        },

        toggle() {
            this.theme = this.theme === 'dark' ? 'light' : 'dark'
            if (typeof window !== 'undefined') {
                localStorage.setItem(STORAGE_KEY, this.theme)
            }
            this.apply()
        },
    },
})
