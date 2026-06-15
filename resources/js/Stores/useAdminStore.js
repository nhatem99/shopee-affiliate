import { defineStore } from 'pinia'
import { router } from '@inertiajs/vue3'
import axios from 'axios'

export const useAdminStore = defineStore('admin', {
    state: () => ({
        loading: false,
    }),
    actions: {
        approveOrder(commissionId) {
            router.patch(`/admin/orders/${commissionId}`, { status: 'approved' })
        },

        async testApiConfig(configId) {
            const response = await axios.post(`/admin/api-config/${configId}/test`)
            return response.data
        },
    },
})
