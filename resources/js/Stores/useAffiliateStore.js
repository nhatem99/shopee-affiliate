import { defineStore } from 'pinia'
import { router } from '@inertiajs/vue3'

export const useAffiliateStore = defineStore('affiliate', {
    state: () => ({
        url: '',
        scanning: false,
        error: null,
    }),
    actions: {
        scan() {
            if (!this.url) return
            this.scanning = true
            this.error = null

            router.post('/affiliate/scan', { url: this.url }, {
                onSuccess: () => {
                    this.scanning = false
                },
                onError: (errors) => {
                    this.error = errors.url || 'Có lỗi xảy ra, vui lòng thử lại.'
                    this.scanning = false
                },
            })
        },
    },
})
