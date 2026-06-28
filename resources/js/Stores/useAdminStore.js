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

    const loadingWithdrawals = ref([])

    function patchWithdrawal(id, payload, { onSuccess, onError } = {}) {
        if (loadingWithdrawals.value.includes(id)) return

        loadingWithdrawals.value.push(id)

        router.patch(`/admin/withdrawals/${id}`, payload, {
            preserveScroll: true,
            onSuccess: () => {
                loadingWithdrawals.value = loadingWithdrawals.value.filter(x => x !== id)
                onSuccess?.()
            },
            onError: () => {
                loadingWithdrawals.value = loadingWithdrawals.value.filter(x => x !== id)
                onError?.()
            },
        })
    }

    function approveWithdrawal(id, callbacks) {
        patchWithdrawal(id, { status: 'approved' }, callbacks)
    }

    function completeWithdrawal(id, transaction_ref, callbacks) {
        patchWithdrawal(id, { status: 'completed', transaction_ref }, callbacks)
    }

    function rejectWithdrawal(id, admin_note, callbacks) {
        patchWithdrawal(id, { status: 'rejected', admin_note }, callbacks)
    }

    return {
        loadingOrders, approveOrder, testApiConfig,
        loadingWithdrawals, approveWithdrawal, completeWithdrawal, rejectWithdrawal,
    }
})
