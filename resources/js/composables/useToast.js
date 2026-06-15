import { ref } from 'vue'

const toasts = ref([])
let nextId = 0

export function useToast() {
    function add(message, type = 'success', duration = 3500) {
        const id = ++nextId
        toasts.value.push({ id, message, type })
        setTimeout(() => remove(id), duration)
    }

    function remove(id) {
        const idx = toasts.value.findIndex(t => t.id === id)
        if (idx !== -1) toasts.value.splice(idx, 1)
    }

    return {
        toasts,
        success: (msg, duration) => add(msg, 'success', duration),
        error: (msg, duration) => add(msg, 'error', duration),
        info: (msg, duration) => add(msg, 'info', duration),
        remove,
    }
}
