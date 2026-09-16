<script setup>
import { Link, router, usePage } from '@inertiajs/vue3'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import axios from 'axios'
import { useAuthStore } from '@/Stores/useAuthStore'
import ToastContainer from '@/Components/ToastContainer.vue'
import ThemeToggle from '@/Components/ThemeToggle.vue'

const page = usePage()
const auth = useAuthStore()
const current = computed(() => page.url)

// Con số lấy về từ nhịp poll nhẹ bên dưới; chưa poll lần nào thì dùng số server gửi kèm trang.
const polledUnread = ref(null)
const chatUnread = computed(() => polledUnread.value ?? page.props.chat?.unread ?? 0)

// Mỗi lần chuyển trang (và mỗi nhịp poll của /admin/chats) server đều gửi lại con số mới nhất —
// tin nó hơn số đã poll, nếu không thì vừa trả lời xong badge vẫn treo tới nhịp poll kế tiếp.
watch(() => page.props.chat?.unread, (value) => {
    if (typeof value === 'number') polledUnread.value = value
})

/*
 * Khách nhắn tin trong lúc admin đang làm việc khác — đây là chỗ báo cho admin biết.
 *
 * Admin KHÔNG có chuông thông báo như khách (xem HandleInertiaRequests::notifications), nên nếu
 * không có đoạn này thì cách duy nhất biết có người nhắn là tự bấm vào mục Hỗ trợ mà xem. Ba
 * mức, tăng dần theo mức độ admin đã rời đi: badge ở sidebar, số trên tiêu đề tab, và thông báo
 * của hệ điều hành (chỉ khi admin đã bấm nút xin quyền ở /admin/chats).
 *
 * Cố ý VẪN poll khi tab bị ẩn, ngược với hai trang chat: tab ẩn chính là lúc cần báo nhất.
 */
const POLL_MS = 30000
let timer = null
let offNavigate = null
let lastSeenUnread = null

function pollBadge() {
    // Trang /admin/chats tự poll và đã xin luôn prop 'chat' — đừng hỏi chồng lên.
    if (current.value.startsWith('/admin/chats')) return

    // Endpoint JSON riêng, KHÔNG phải router.reload: partial reload vẫn chạy lại controller của
    // trang đang mở — đứng ở /admin/dashboard thì cứ 30 giây chạy lại toàn bộ thống kê.
    axios.get('/admin/chats/unread')
        .then(({ data }) => {
            if (typeof data?.unread === 'number') polledUnread.value = data.unread
        })
        // Mạng chập chờn hay phiên hết hạn thì im lặng bỏ qua: đây là một con số trang trí, không
        // đáng để ném lỗi vào mặt admin đang làm việc khác.
        .catch(() => {})
}

// Inertia đặt lại document.title sau mỗi lần điều hướng (thẻ <Head> của từng trang), nên số đếm
// phải được gắn lại sau đó — setTimeout để chạy sau lượt cập nhật title của Inertia.
function applyTitleBadge() {
    const base = document.title.replace(/^\(\d+\+?\)\s+/, '')
    document.title = chatUnread.value ? `(${chatUnread.value}) ${base}` : base
}

function notifyDesktop(count) {
    if (typeof Notification === 'undefined' || Notification.permission !== 'granted') return

    const notification = new Notification('Có khách đang chờ trả lời', {
        body: count > 1 ? `${count} hội thoại chưa trả lời` : 'Một khách vừa nhắn tin',
        tag: 'chat-unread', // thay thế thông báo cũ thay vì xếp chồng một dãy
    })

    notification.onclick = () => {
        window.focus()
        router.visit('/admin/chats')
        notification.close()
    }
}

watch(chatUnread, (count) => {
    applyTitleBadge()

    // Lần đầu chỉ ghi nhận mốc: mở trang quản trị mà có sẵn 3 khách đang chờ từ hôm qua thì đó
    // không phải "vừa có người nhắn".
    if (lastSeenUnread !== null && count > lastSeenUnread) notifyDesktop(count)

    lastSeenUnread = count
})

onMounted(() => {
    lastSeenUnread = chatUnread.value
    applyTitleBadge()
    timer = setInterval(pollBadge, POLL_MS)
    offNavigate = router.on('navigate', () => setTimeout(applyTitleBadge, 0))
})

onBeforeUnmount(() => {
    clearInterval(timer)
    if (offNavigate) offNavigate()
})

const mobileOpen = ref(false)
// Đóng sidebar mobile mỗi khi chuyển trang, tránh bị kẹt mở đè lên nội dung mới.
watch(current, () => { mobileOpen.value = false })

// Tách nhóm theo việc admin làm, không theo thứ tự thêm trang: 13 mục xếp thành một cột
// phẳng là không dò được mục cần bấm. Nhóm nào cũng ngắn (2-4 mục) để mắt quét một nhịp.
const navGroups = [
    {
        label: 'Tổng quan',
        items: [
            { href: '/admin/dashboard', icon: '📊', label: 'Dashboard' },
            { href: '/admin/activities', icon: '🕵️', label: 'Theo dõi' },
        ],
    },
    {
        label: 'Khách & tiền',
        items: [
            { href: '/admin/users', icon: '👥', label: 'Tài khoản' },
            { href: '/admin/orders', icon: '📦', label: 'Đơn hàng' },
            { href: '/admin/shopee-orders', icon: '🧾', label: 'Báo cáo Shopee' },
            { href: '/admin/withdrawals', icon: '💸', label: 'Rút tiền' },
            // badge: số khách đang chờ trả lời (page.props.chat) — admin không có chuông thông
            // báo như khách, đây là chỗ DUY NHẤT thấy có người nhắn tin.
            { href: '/admin/chats', icon: '💬', label: 'Hỗ trợ', badge: true },
        ],
    },
    {
        label: 'Mã & quảng bá',
        items: [
            { href: '/admin/vouchers', icon: '🎫', label: 'Voucher FB/YT' },
            { href: '/admin/voucher-buttons', icon: '🔘', label: 'Nút Voucher' },
            { href: '/admin/promo', icon: '📣', label: 'Bài giới thiệu' },
        ],
    },
    {
        label: 'Hệ thống',
        items: [
            { href: '/admin/settings', icon: '🔧', label: 'Cài đặt' },
            { href: '/admin/api-config', icon: '⚙️', label: 'Cấu hình API' },
            { href: '/admin/blocked-ips', icon: '🚫', label: 'Chặn IP' },
            { href: '/admin/logs', icon: '🐞', label: 'Nhật ký lỗi' },
            { href: '/admin/scheduler', icon: '⏱️', label: 'Lịch chạy' },
            { href: '/admin/console', icon: '⌨️', label: 'Lệnh artisan' },
        ],
    },
]
</script>

<template>
    <div class="flex min-h-screen bg-[var(--color-bg)]">
        <!-- Lớp phủ tối phía sau sidebar khi mở trên mobile, bấm vào để đóng -->
        <div
            v-if="mobileOpen"
            @click="mobileOpen = false"
            class="fixed inset-0 bg-black/60 z-40 md:hidden"
        ></div>

        <!-- Sidebar: cố định hiện trên desktop, dạng drawer trượt trên mobile -->
        <aside
            class="w-[248px] flex-none bg-[var(--color-side)] text-white flex flex-col py-6 px-4 fixed h-screen z-50 transition-transform duration-200 md:translate-x-0"
            :class="mobileOpen ? 'translate-x-0' : '-translate-x-full'"
        >
            <Link href="/" class="flex items-center gap-2 font-extrabold text-lg text-white mb-6 px-2">
                <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-[var(--color-accent)] to-[var(--color-accent-deep)] flex items-center justify-center text-base font-extrabold">%</span>
                Mã Giảm Giá
            </Link>

            <!-- overflow-y-auto: có tiêu đề nhóm thì cột dài hơn màn hình điện thoại ngang,
                 không cuộn được là mất mấy mục cuối. -->
            <nav class="flex-1 overflow-y-auto -mx-1 px-1 space-y-5">
                <div v-for="group in navGroups" :key="group.label">
                    <p class="px-3 mb-1.5 text-[11px] font-bold uppercase tracking-wider text-white/35">{{ group.label }}</p>
                    <div class="space-y-0.5">
                        <Link
                            v-for="item in group.items"
                            :key="item.href"
                            :href="item.href"
                            class="flex items-center gap-3 px-3 py-2 rounded-xl text-sm font-semibold transition"
                            :class="current.startsWith(item.href) && item.href !== '/'
                                ? 'bg-[var(--color-side-soft)] text-white'
                                : 'text-white/60 hover:text-white hover:bg-[var(--color-side-soft)]'"
                        >
                            <span>{{ item.icon }}</span>
                            <span class="flex-1 min-w-0 truncate">{{ item.label }}</span>
                            <span
                                v-if="item.badge && chatUnread"
                                class="flex-none min-w-[20px] h-5 px-1.5 rounded-full bg-[var(--color-accent)] text-white text-[11px] font-extrabold flex items-center justify-center"
                            >{{ chatUnread > 9 ? '9+' : chatUnread }}</span>
                        </Link>
                    </div>
                </div>
            </nav>

            <div class="border-t border-white/10 pt-4 px-2">
                <p class="text-xs text-white/40 mb-1">Đăng nhập với tư cách</p>
                <p class="text-sm font-semibold text-white">{{ auth.user?.name }}</p>
                <button @click="auth.logout()" class="mt-2 text-xs text-white/50 hover:text-white transition">Đăng xuất</button>
            </div>
        </aside>

        <!-- Main content -->
        <div class="flex-1 md:ml-[248px] flex flex-col min-h-screen min-w-0">
            <header class="sticky top-0 z-30 bg-[var(--color-surface)] border-b border-[var(--color-line)] px-4 md:px-6 h-14 flex items-center justify-between gap-3">
                <div class="flex items-center gap-3 min-w-0">
                    <button
                        @click="mobileOpen = true"
                        class="md:hidden flex-none w-9 h-9 rounded-lg flex items-center justify-center text-[var(--color-ink)] hover:bg-[var(--color-line)] transition"
                        aria-label="Mở menu"
                    >
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
                    </button>
                    <h1 class="font-bold text-[var(--color-ink)] text-base truncate">
                        <slot name="title">Admin</slot>
                    </h1>
                </div>
                <div class="flex items-center gap-3 flex-none">
                    <span class="hidden sm:block text-sm text-[var(--color-muted)]">{{ new Date().toLocaleDateString('vi-VN') }}</span>
                    <ThemeToggle />
                </div>
            </header>
            <main class="flex-1 p-4 md:p-6 min-w-0">
                <slot />
            </main>
        </div>
        <ToastContainer />
    </div>
</template>
