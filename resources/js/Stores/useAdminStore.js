import { defineStore } from 'pinia'
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'

export const useAdminStore = defineStore('admin', () => {
    const loadingOrders = ref([])

    function approveOrder(commissionId, { onSuccess, onError } = {}) {
        if (loadingOrders.value.includes(commissionId)) return

        loadingOrders.value.push(commissionId)

        router.patch(`/admin/orders/${commissionId}`, { status: 'approved' }, {
            onSuccess: () => {
                loadingOrders.value = loadingOrders.value.filter(id => id !== commissionId)
                onSuccess?.()
            },
            onError: () => {
                loadingOrders.value = loadingOrders.value.filter(id => id !== commissionId)
                onError?.()
            },
        })
    }

    async function testApiConfig(configId) {
        const response = await axios.post(`/admin/api-config/${configId}/test`)
        return response.data
    }

    return { loadingOrders, approveOrder, testApiConfig }
})
