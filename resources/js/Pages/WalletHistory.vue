<script setup>
import { Head } from '@inertiajs/vue3'
import AccountLayout from '@/Layouts/AccountLayout.vue'

defineProps({
    available: Number,
    events: Array,
})

function vnd(n) {
    return '₫' + Number(n || 0).toLocaleString('vi-VN')
}

function signed(n) {
    const v = Number(n || 0)
    if (v === 0) return '0'
    return (v > 0 ? '+' : '−') + vnd(Math.abs(v))
}

// Tiền vào = màu tiền, tiền ra = màu trừ. Giữ quy ước sổ sách (ra thì đỏ) vì khách đọc cột này
// bằng mắt chứ không đọc dấu +/−; trước đây dùng green-100/red-100 kèm dark: chép tay, giờ là
// token nên chỉ còn một cặp lớp cho cả hai chế độ.
function deltaClass(v) {
    if (v > 0) return 'bg-[var(--color-money-soft)] text-[var(--color-money)]'
    if (v < 0) return 'bg-[var(--color-danger-soft)] text-[var(--color-danger)]'
    return 'panel text-[var(--color-muted)]'
}

// Vòng tròn icon: tiền VÀO (quà, hoàn tiền) đi màu tiền, tiền RA (rút về ví) đi màu trung tính —
// rút tiền là việc khách chủ động làm, không phải sự cố, nên không tô đỏ ở đây; dấu trừ đỏ nằm
// bên cột biến động là đủ.
const kindStyles = {
    bonus: { ring: 'bg-[var(--color-money-soft)] text-[var(--color-money)]', icon: '🎁' },
    cashback: { ring: 'bg-[var(--color-money-soft)] text-[var(--color-money)]', icon: '↑' },
    withdrawal: { ring: 'bg-[var(--color-info-soft)] text-[var(--color-info)]', icon: '↓' },
}
</script>

<template>
    <Head title="Lịch sử số dư ví" />
    <AccountLayout>
        <div class="space-y-6">
            <div>
                <h1 class="text-2xl font-extrabold text-[var(--color-ink)]">Lịch sử số dư ví</h1>
                <p class="text-sm text-[var(--color-muted)] mt-1">Từng lần tiền vào, tiền ra và số dư trước/sau mỗi lần.</p>
            </div>

            <!-- Số dư: cùng một khuôn với AccountDrawer và trang Tổng quan — cùng nhãn "SỐ DƯ KHẢ
                 DỤNG", cùng màu tiền, cùng .num. Bỏ thẻ gradient cam chữ trắng: cam là của nút bấm,
                 và ba nơi vẽ cùng một con số theo ba kiểu khác nhau khiến khách tưởng là ba con số.
                 Nhãn cũng đổi từ "Số dư hiện tại" sang "Số dư khả dụng" cho khớp — đây đúng là con
                 số availableBalance() mà ProfileController::walletHistory truyền vào. -->
            <div class="card p-6">
                <p class="text-xs font-bold uppercase tracking-wide text-[var(--color-muted)]">Số dư khả dụng</p>
                <p class="text-3xl font-extrabold num text-[var(--color-money)] mt-0.5">{{ vnd(available) }}</p>
            </div>

            <div class="card overflow-hidden">
                <div class="flex items-center justify-between px-6 py-4 border-b border-[var(--color-line)]">
                    <h2 class="font-bold text-[var(--color-ink)]">Biến động</h2>
                    <span class="text-xs text-[var(--color-muted)]">Mới nhất ở trên</span>
                </div>

                <ul v-if="events?.length" class="divide-y divide-[var(--color-line)]">
                    <li v-for="e in events" :key="e.key" class="px-6 py-4 flex flex-wrap md:flex-nowrap items-center gap-4">
                        <span
                            class="flex-none w-10 h-10 rounded-full flex items-center justify-center text-lg font-extrabold"
                            :class="kindStyles[e.kind]?.ring"
                        >{{ kindStyles[e.kind]?.icon }}</span>

                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-sm text-[var(--color-ink)]">{{ e.title }}</p>
                            <p class="text-xs text-[var(--color-muted)]">{{ e.at }}</p>
                            <p v-if="e.note" class="text-xs text-[var(--color-muted)] mt-0.5">{{ e.note }}</p>
                        </div>

                        <div class="flex-none px-3 py-1.5 rounded-xl text-sm font-extrabold num whitespace-nowrap" :class="deltaClass(e.delta)">
                            {{ signed(e.delta) }}
                        </div>

                        <div class="w-full md:w-auto flex gap-2 text-xs num">
                            <div class="panel flex-1 md:flex-none px-3 py-1.5 flex justify-between gap-3">
                                <span class="text-[var(--color-muted)] font-semibold">Trước</span>
                                <span class="text-[var(--color-ink)] font-bold">{{ vnd(e.before) }}</span>
                            </div>
                            <div class="panel flex-1 md:flex-none px-3 py-1.5 flex justify-between gap-3">
                                <span class="text-[var(--color-muted)] font-semibold">Sau</span>
                                <span class="text-[var(--color-ink)] font-bold">{{ vnd(e.after) }}</span>
                            </div>
                        </div>
                    </li>
                </ul>
                <p v-else class="px-6 py-12 text-center text-sm text-[var(--color-muted)]">
                    Ví chưa có biến động nào.
                </p>

                <p v-if="events?.length" class="px-6 py-3 text-center text-xs text-[var(--color-muted)] border-t border-[var(--color-line)]">
                    Đã hiển thị tất cả biến động
                </p>
            </div>
        </div>
    </AccountLayout>
</template>
