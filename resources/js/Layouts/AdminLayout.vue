<script setup>
import { Link, router, usePage } from '@inertiajs/vue3'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import axios from 'axios'
import { useAuthStore } from '@/Stores/useAuthStore'
import ToastContainer from '@/Components/ToastContainer.vue'
import ThemeToggle from '@/Components/ThemeToggle.vue'
import AdminAlertBell from '@/Components/AdminAlertBell.vue'

const page = usePage()
const auth = useAuthStore()
const current = computed(() => page.url)

/*
 * Việc khách đang chờ admin: hội thoại chưa trả lời, lệnh rút chưa duyệt (AdminAlertService).
 *
 * Admin KHÔNG có chuông thông báo như khách (xem HandleInertiaRequests::notifications), nên nếu
 * không có đoạn này thì cách duy nhất biết có việc là tự bấm vào từng trang mà xem. Bốn mức, tăng
 * dần theo mức độ admin đã rời đi: badge ở sidebar, chuông ở header, số trên tiêu đề tab, và
 * thông báo của hệ điều hành (chỉ khi admin đã bấm cho phép ở chuông hoặc ở /admin/chats).
 */
const ALERTS = [
    {
        key: 'chats',
        href: '/admin/chats',
        icon: '💬',
        label: 'Khách đang chờ trả lời',
        // Đếm SỐ KHÁCH, không phải số tin — xem ChatService::unreadForAdmin.
        notify: (n) => ['Có khách đang chờ trả lời', n > 1 ? `${n} hội thoại chưa trả lời` : 'Một khách vừa nhắn tin'],
    },
    {
        key: 'withdrawals',
        href: '/admin/withdrawals?status=pending',
        icon: '💸',
        label: 'Yêu cầu rút tiền chờ duyệt',
        notify: (n) => ['Có yêu cầu rút tiền mới', n > 1 ? `${n} yêu cầu đang chờ duyệt` : 'Một khách vừa yêu cầu rút tiền'],
    },
]

// Con số lấy về từ nhịp poll nhẹ bên dưới; chưa poll lần nào thì dùng số server gửi kèm trang.
const polled = ref(null)
const counts = computed(() => polled.value ?? page.props.adminAlerts ?? {})
const alertItems = computed(() => ALERTS.map((a) => ({ ...a, count: counts.value[a.key] ?? 0 })))
const alertTotal = computed(() => alertItems.value.reduce((sum, a) => sum + a.count, 0))

// Mỗi lần chuyển trang (và mỗi nhịp poll của /admin/chats) server đều gửi lại con số mới nhất —
// tin nó hơn số đã poll, nếu không thì vừa trả lời/duyệt xong badge vẫn treo tới nhịp poll kế.
watch(() => page.props.adminAlerts, (value) => {
    if (value) polled.value = value
})

// Cố ý VẪN poll khi tab bị ẩn, ngược với hai trang chat: tab ẩn chính là lúc cần báo nhất.
const POLL_MS = 30000
let timer = null
let titleObserver = null
let lastSeen = null

function pollAlerts() {
    // Trang /admin/chats tự poll và đã xin luôn prop 'adminAlerts' — đừng hỏi chồng lên.
    if (current.value.startsWith('/admin/chats')) return

    // Endpoint JSON riêng, KHÔNG phải router.reload: partial reload vẫn chạy lại controller của
    // trang đang mở — đứng ở /admin/dashboard thì cứ 30 giây chạy lại toàn bộ thống kê.
    axios.get('/admin/alerts')
        .then(({ data }) => {
            if (data && typeof data === 'object') polled.value = data
        })
        // Mạng chập chờn hay phiên hết hạn thì im lặng bỏ qua: đây là mấy con số báo hiệu, không
        // đáng để ném lỗi vào mặt admin đang làm việc khác.
        .catch(() => {})
}

// Inertia tự dựng lại thẻ <title> mỗi lần <Head> của trang render — thay hẳn phần tử, trễ 1ms qua
// debounce — nên số gắn vào lúc nào cũng có thể bị xoá ngay sau đó (setTimeout 0 sau 'navigate'
// từng thua cuộc đua này: tab chưa bao giờ hiện số). Canh luôn <head>: tiêu đề đổi là gắn lại.
// So trước khi gán, không thì chính lần gán này kích hoạt observer thành vòng lặp.
function applyTitleBadge() {
    const base = document.title.replace(/^\(\d+\+?\)\s+/, '')
    const next = alertTotal.value ? `(${alertTotal.value}) ${base}` : base
    if (document.title !== next) document.title = next
}

function notifyDesktop(alert, count) {
    if (typeof Notification === 'undefined' || Notification.permission !== 'granted') return

    const [title, body] = alert.notify(count)
    const notification = new Notification(title, {
        body,
        tag: `admin-${alert.key}`, // thay thế thông báo cũ cùng loại thay vì xếp chồng một dãy
    })

    notification.onclick = () => {
        window.focus()
        router.visit(alert.href)
        notification.close()
    }
}

watch(alertItems, (items) => {
    applyTitleBadge()

    // Lần đầu chỉ ghi nhận mốc: mở trang quản trị mà có sẵn 3 khách đang chờ từ hôm qua thì đó
    // không phải "vừa có người nhắn".
    for (const item of items) {
        if (lastSeen && item.count > (lastSeen[item.key] ?? 0)) notifyDesktop(item, item.count)
    }

    lastSeen = Object.fromEntries(items.map((a) => [a.key, a.count]))
})

onMounted(() => {
    lastSeen = Object.fromEntries(alertItems.value.map((a) => [a.key, a.count]))
    applyTitleBadge()
    timer = setInterval(pollAlerts, POLL_MS)
    titleObserver = new MutationObserver(applyTitleBadge)
    titleObserver.observe(document.head, { childList: true, subtree: true, characterData: true })
})

onBeforeUnmount(() => {
    clearInterval(timer)
    titleObserver?.disconnect()
})

const mobileOpen = ref(false)
// Đóng sidebar mobile mỗi khi chuyển trang, tránh bị kẹt mở đè lên nội dung mới.
watch(current, () => { mobileOpen.value = false })

// Tách nhóm theo việc admin làm, không theo thứ tự thêm trang: 20 mục xếp thành một cột
// phẳng là không dò được mục cần bấm. Mỗi kênh (Facebook, Zalo) một nhóm riêng — admin tìm
// theo kênh đang làm, không nhớ trang đó được xếp vào "quảng bá" hay "hệ thống".
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
            // badge: key trong ALERTS — số việc đang chờ ở trang đó, cùng con số với chuông.
            { href: '/admin/withdrawals', icon: '💸', label: 'Rút tiền', badge: 'withdrawals' },
            { href: '/admin/chats', icon: '💬', label: 'Hỗ trợ', badge: 'chats' },
        ],
    },
    {
        label: 'Mã & quảng bá',
        items: [
            { href: '/admin/voucher-buttons', icon: '🔘', label: 'Nút Voucher' },
            { href: '/admin/promo', icon: '📣', label: 'Bài giới thiệu' },
        ],
    },
    {
        label: 'Facebook',
        items: [
            { href: '/admin/fb-posts', icon: '📢', label: 'Đăng bài vào nhóm' },
            { href: '/admin/fb-groups', icon: '🧑‍🤝‍🧑', label: 'Nhóm & bot đăng bài' },
            { href: '/admin/fb-engage', icon: '🗨️', label: 'Bình luận dạo' },
            { href: '/admin/fb-videos', icon: '🎬', label: 'Đăng Reel từ video' },
        ],
    },
    {
        label: 'Zalo',
        items: [
            { href: '/admin/zalo-nick', icon: '📱', label: 'Nick trả lời nhóm' },
            { href: '/admin/zalo-bot', icon: '🤖', label: 'Zalo Bot' },
        ],
    },
    {
        label: 'Hệ thống',
        items: [
            { href: '/admin/settings', icon: '🔧', label: 'Cài đặt chung' },
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
            <Link href="/" class="flex items-center mb-6 px-2">
                <img src="/logo.png" alt="tietkiemvi" class="h-11 w-auto">
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
                                v-if="item.badge && counts[item.badge]"
                                class="flex-none min-w-[20px] h-5 px-1.5 rounded-full bg-[var(--color-accent)] text-white text-[11px] font-extrabold flex items-center justify-center"
                            >{{ counts[item.badge] > 9 ? '9+' : counts[item.badge] }}</span>
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
                    <AdminAlertBell :items="alertItems" :total="alertTotal" />
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
