import { defineStore } from 'pinia'
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'

export const useAffiliateStore = defineStore('affiliate', () => {
    const url = ref('')
    const scanning = ref(false)
    const error = ref(null)

    function scan() {
        if (!url.value) return
        scanning.value = true
        error.value = null

        router.post('/affiliate/scan', { url: url.value }, {
            onSuccess: () => {
                scanning.value = false
            },
            onError: (errors) => {
                error.value = errors.url || 'Có lỗi xảy ra, vui lòng thử lại.'
                scanning.value = false
            },
        })
    }

    return { url, scanning, error, scan }
})
