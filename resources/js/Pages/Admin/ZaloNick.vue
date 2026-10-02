<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { router, usePage, Head } from '@inertiajs/vue3'
import axios from 'axios'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useToast } from '@/composables/useToast'

const toast = useToast()
const page = usePage()

const props = defineProps({
    status: Object,
    bridgeUrl: String,
    groupIds: Array,
    repliesPer10Minutes: Number,
})

const errors = computed(() => page.props.errors || {})
const state = ref(props.status)

// Đang trong lượt đăng nhập QR thì hỏi lại 2 giây/lần để thay mã mới (Zalo đổi mã khoảng mỗi
// phút) và biết ngay lúc quét xong; còn lại 15 giây/lần, đủ để thấy phiên bị đá.
const QR_PENDING = ['generating', 'waiting_scan', 'scanned', 'expired', 'declined']
const pending = computed(() => state.value?.reachable && !state.value.loggedIn && QR_PENDING.includes(state.value.qr?.status))

let timer = null
async function poll() {
    try {
        const { data } = await axios.get('/admin/zalo-nick/status')
        if (data?.loggedIn && state.value?.reachable && !state.value.loggedIn) {
            toast.success('Đã đăng nhập Zalo — bot nhóm chạy lại.')
        }
        state.value = data
    } catch {
        // Mất mạng/hết phiên admin — giữ nguyên trạng thái cũ, lần sau hỏi lại.
    }
    schedule()
}
function schedule() {
    clearTimeout(timer)
    timer = setTimeout(poll, pending.value ? 2000 : 15000)
}
onMounted(schedule)
onBeforeUnmount(() => clearTimeout(timer))

const starting = ref(false)
function relogin() {
    if (state.value?.loggedIn && !confirm('Nick đang đăng nhập. Lấy mã QR mới sẽ NGẮT bot nhóm cho tới khi quét xong. Tiếp tục?')) return

    starting.value = true
    router.post('/admin/zalo-nick/relogin', {}, {
        preserveScroll: true,
        onSuccess: () => {
            const msg = page.props.flash?.success
            if (msg) toast.success(msg)
            // Cầu nối cần một hai giây để có mã đầu tiên.
            state.value = { ...state.value, loggedIn: false, qr: { status: 'generating', image: null } }
            clearTimeout(timer)
            timer = setTimeout(poll, 1500)
        },
        onFinish: () => { starting.value = false },
    })
}
</script>

<template>
    <Head title="Admin — Zalo nick nhóm" />
    <AdminLayout>
        <template #title>Zalo nick nhóm</template>

        <!-- 1. Trạng thái -->
        <section class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5 mb-5">
            <h2 class="font-extrabold text-[var(--color-ink)] mb-1">1. Trạng thái</h2>
            <p class="text-sm text-[var(--color-muted)] mb-3">
                Nick Zalo cá nhân (nick phụ) tự trả link có mã khi khách dán link Shopee trong nhóm. Nick chạy qua
                cầu nối hermes-zalo-plugin trên server — không phải API chính thức, Zalo có thể khoá nick.
            </p>

            <div v-if="!state?.reachable" class="text-sm">
                <p class="font-semibold text-red-500">Không nối được cầu nối ({{ bridgeUrl }}).</p>
                <p class="text-[var(--color-muted)] mt-1">Cầu nối chưa chạy trên server — cần khởi động nó trước khi quét QR.</p>
                <p v-if="state?.error" class="font-mono text-xs text-[var(--color-muted)] mt-2 break-all">{{ state.error }}</p>
            </div>
            <ul v-else class="text-sm space-y-1.5">
                <li>
                    Đăng nhập:
                    <span v-if="state.loggedIn" class="font-semibold text-green-600">đang đăng nhập</span>
                    <span v-else-if="state.sessionDead" class="font-semibold text-red-500">phiên đã bị đăng xuất{{ state.sessionDeadReason ? ` (${state.sessionDeadReason})` : '' }}</span>
                    <span v-else class="font-semibold text-amber-600">chưa đăng nhập</span>
                    <span v-if="state.ownId" class="font-mono text-xs text-[var(--color-muted)] ml-1">uid {{ state.ownId }}</span>
                </li>
                <li>
                    Lệnh nghe tin <span class="font-mono text-xs">zalo:group-listen</span>:
                    <span v-if="state.listening" class="font-semibold text-green-600">đang chạy</span>
                    <span v-else class="font-semibold text-red-500">chưa chạy — bot nhận tin nhưng không trả lời</span>
                </li>
            </ul>
        </section>

        <!-- 2. Mã QR -->
        <section class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5 mb-5">
            <h2 class="font-extrabold text-[var(--color-ink)] mb-1">2. Đăng nhập bằng mã QR</h2>
            <p class="text-sm text-[var(--color-muted)] mb-4">
                Mở app Zalo của <span class="font-semibold text-[var(--color-ink)]">nick phụ</span> → biểu tượng quét QR → quét mã
                bên dưới → xác nhận trên điện thoại. Mã tự đổi khoảng mỗi phút, trang tự cập nhật.
            </p>

            <div v-if="state?.reachable && !state.loggedIn" class="mb-4">
                <img v-if="state.qr?.image" :src="state.qr.image" alt="Mã QR đăng nhập Zalo"
                    class="w-64 h-64 border border-[var(--color-line)] rounded-xl bg-white p-2" />
                <p v-else-if="state.qr?.status === 'scanned'" class="text-sm font-semibold text-green-600">
                    ✓ {{ state.qr.scannedBy || 'Đã quét' }} — bấm xác nhận đăng nhập trên điện thoại…
                </p>
                <p v-else-if="pending" class="text-sm text-[var(--color-muted)]">Đang tạo mã QR…</p>
                <p v-else class="text-sm text-[var(--color-muted)]">Chưa có mã — bấm "Lấy mã QR".</p>
            </div>

            <p v-if="errors.relogin" class="text-red-500 text-xs mb-3 break-all">{{ errors.relogin }}</p>
            <button @click="relogin" :disabled="!state?.reachable || starting || pending"
                class="bg-[var(--color-accent)] hover:bg-[var(--color-accent-deep)] text-white font-semibold px-5 py-2.5 rounded-xl text-sm transition disabled:opacity-50">
                {{ starting ? 'Đang gửi...' : (state?.loggedIn ? 'Đăng nhập lại / đổi nick' : 'Lấy mã QR') }}
            </button>
        </section>

        <!-- 3. Nhóm -->
        <section class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] p-5">
            <h2 class="font-extrabold text-[var(--color-ink)] mb-1">3. Nhóm được trả lời</h2>
            <p v-if="groupIds?.length" class="text-sm mb-2">
                <span v-for="id in groupIds" :key="id" class="inline-block font-mono text-xs bg-[var(--color-peach-soft)] rounded-lg px-2 py-1 mr-2 mb-1">{{ id }}</span>
            </p>
            <p v-else class="text-sm font-semibold text-amber-600 mb-2">Mọi nhóm nick phụ đang ở.</p>
            <p class="text-xs text-[var(--color-muted)]">
                Tối đa {{ repliesPer10Minutes }} lần trả lời mỗi nhóm trong 10 phút. Đổi danh sách nhóm ở
                <span class="font-mono">ZALO_PERSONAL_GROUP_IDS</span> rồi khởi động lại lệnh nghe tin.
            </p>
        </section>
    </AdminLayout>
</template>
