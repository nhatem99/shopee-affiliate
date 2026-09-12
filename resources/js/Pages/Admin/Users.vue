<script setup>
import { ref, watch } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useToast } from '@/composables/useToast'

const props = defineProps({
    users: Object,
    filters: Object,
    stats: Object,
    currentUserId: Number,
})

const toast = useToast()

function vnd(n) {
    return '₫' + Number(n || 0).toLocaleString('vi-VN')
}

// ── Bộ lọc ────────────────────────────────────────────────────────────────────

const search = ref(props.filters?.q || '')

watch(() => props.filters, (f) => { search.value = f?.q || '' })

function applyFilters(patch = {}) {
    router.get('/admin/users', {
        q: search.value || undefined,
        role: props.filters?.role || undefined,
        status: props.filters?.status || undefined,
        ...patch,
    }, { preserveState: true, replace: true })
}

function setRole(role) {
    applyFilters({ role: role || undefined })
}

function setStatus(status) {
    applyFilters({ status: status || undefined })
}

function resetFilters() {
    search.value = ''
    router.get('/admin/users', {}, { preserveState: true, replace: true })
}

function goPage(url) {
    if (url) router.get(url, {}, { preserveState: true })
}

// ── Modal sửa thông tin ───────────────────────────────────────────────────────

const editing = ref(null)
const editForm = useForm({ name: '', email: '', phone: '' })

function openEdit(u) {
    editing.value = u
    editForm.clearErrors()
    editForm.name = u.name || ''
    editForm.email = u.email || ''
    editForm.phone = u.phone || ''
}

function submitEdit() {
    editForm.patch(`/admin/users/${editing.value.id}`, {
        preserveScroll: true,
        onSuccess: () => { editing.value = null; toast.success('Đã cập nhật thông tin') },
    })
}

// ── Modal đặt lại mật khẩu ────────────────────────────────────────────────────

const resetting = ref(null)
const passwordForm = useForm({ password: '' })

function openReset(u) {
    resetting.value = u
    passwordForm.clearErrors()
    passwordForm.password = ''
}

function submitReset() {
    passwordForm.post(`/admin/users/${resetting.value.id}/password`, {
        preserveScroll: true,
        onSuccess: () => { resetting.value = null; passwordForm.reset(); toast.success('Đã đặt lại mật khẩu') },
    })
}

// ── Modal khoá tài khoản ──────────────────────────────────────────────────────

const banning = ref(null)
const banForm = useForm({ banned_reason: '' })

function openBan(u) {
    banning.value = u
    banForm.clearErrors()
    banForm.banned_reason = ''
}

function submitBan() {
    banForm.post(`/admin/users/${banning.value.id}/ban`, {
        preserveScroll: true,
        onSuccess: () => { banning.value = null; banForm.reset(); toast.success('Đã khoá tài khoản') },
        onError: (errors) => toast.error(errors.banned_reason || 'Không khoá được tài khoản'),
    })
}

function unban(u) {
    router.delete(`/admin/users/${u.id}/ban`, {
        preserveScroll: true,
        onSuccess: () => toast.success('Đã mở khoá tài khoản'),
        onError: () => toast.error('Không mở khoá được tài khoản'),
    })
}

// ── Đổi quyền ─────────────────────────────────────────────────────────────────

const roleTarget = ref(null)

function confirmRole() {
    const u = roleTarget.value
    router.patch(`/admin/users/${u.id}/role`, { role: u.role === 'admin' ? 'user' : 'admin' }, {
        preserveScroll: true,
        onSuccess: () => { roleTarget.value = null; toast.success('Đã đổi quyền tài khoản') },
        onError: (errors) => toast.error(errors.role || 'Không đổi được quyền'),
    })
}
</script>

<template>
    <Head title="Admin — Tài khoản" />
    <AdminLayout>
        <template #title>Quản lý tài khoản</template>

        <!-- Thống kê nhanh -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
            <div class="bg-[var(--color-surface)] border border-[var(--color-line)] rounded-2xl p-4">
                <p class="text-xs text-[var(--color-muted)] mb-1">Tổng tài khoản</p>
                <p class="text-2xl font-extrabold text-[var(--color-ink)] tabular-nums">{{ stats?.total ?? 0 }}</p>
            </div>
            <div class="bg-[var(--color-surface)] border border-[var(--color-line)] rounded-2xl p-4">
                <p class="text-xs text-[var(--color-muted)] mb-1">Mới trong 30 ngày</p>
                <p class="text-2xl font-extrabold text-[var(--color-brand-green)] tabular-nums">{{ stats?.new_30d ?? 0 }}</p>
            </div>
            <div class="bg-[var(--color-surface)] border border-[var(--color-line)] rounded-2xl p-4">
                <p class="text-xs text-[var(--color-muted)] mb-1">Quản trị viên</p>
                <p class="text-2xl font-extrabold text-[var(--color-ink)] tabular-nums">{{ stats?.admins ?? 0 }}</p>
            </div>
            <div class="bg-[var(--color-surface)] border border-[var(--color-line)] rounded-2xl p-4">
                <p class="text-xs text-[var(--color-muted)] mb-1">Đang bị khoá</p>
                <p class="text-2xl font-extrabold text-red-500 tabular-nums">{{ stats?.banned ?? 0 }}</p>
            </div>
        </div>

        <!-- Tìm kiếm + lọc -->
        <div class="bg-[var(--color-surface)] border border-[var(--color-line)] rounded-2xl p-4 mb-4 space-y-3">
            <form @submit.prevent="applyFilters()" class="flex gap-2">
                <input v-model="search" type="search" placeholder="Tìm theo tên, email, số điện thoại hoặc mã sub_id"
                    class="flex-1 min-w-0 border border-[var(--color-line)] rounded-xl px-3 py-2.5 text-sm bg-[var(--color-bg)] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-accent)]" />
                <button type="submit"
                    class="flex-none px-5 bg-[var(--color-accent)] hover:bg-[var(--color-accent-deep)] text-white font-semibold rounded-xl text-sm transition">
                    Tìm
                </button>
            </form>

            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs text-[var(--color-muted)]">Quyền:</span>
                <button v-for="r in [{ v: '', l: 'Tất cả' }, { v: 'user', l: 'Khách' }, { v: 'admin', l: 'Quản trị' }]" :key="'r' + r.v"
                    @click="setRole(r.v)"
                    :class="(filters?.role || '') === r.v ? 'bg-[var(--color-accent)] text-white' : 'bg-[var(--color-bg)] text-[var(--color-ink)] border border-[var(--color-line)] hover:border-[var(--color-accent)]'"
                    class="px-3 py-1.5 rounded-lg text-xs font-semibold transition">{{ r.l }}</button>

                <span class="text-xs text-[var(--color-muted)] ml-2">Trạng thái:</span>
                <button v-for="s in [{ v: '', l: 'Tất cả' }, { v: 'active', l: 'Hoạt động' }, { v: 'banned', l: 'Bị khoá' }]" :key="'s' + s.v"
                    @click="setStatus(s.v)"
                    :class="(filters?.status || '') === s.v ? 'bg-[var(--color-accent)] text-white' : 'bg-[var(--color-bg)] text-[var(--color-ink)] border border-[var(--color-line)] hover:border-[var(--color-accent)]'"
                    class="px-3 py-1.5 rounded-lg text-xs font-semibold transition">{{ s.l }}</button>

                <button @click="resetFilters" class="ml-auto text-xs text-[var(--color-muted)] hover:text-[var(--color-accent)] transition">Xoá lọc</button>
            </div>
        </div>

        <!-- Bảng tài khoản -->
        <div class="bg-[var(--color-surface)] rounded-2xl border border-[var(--color-line)] overflow-hidden overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-[var(--color-peach-soft)]">
                    <tr class="text-left text-xs text-[var(--color-muted)]">
                        <th class="px-4 py-3 font-semibold">Tài khoản</th>
                        <th class="px-4 py-3 font-semibold">Mã sub_id</th>
                        <th class="px-4 py-3 font-semibold text-right">Link</th>
                        <th class="px-4 py-3 font-semibold text-right">Hoa hồng duyệt</th>
                        <th class="px-4 py-3 font-semibold text-right">Số dư khả dụng</th>
                        <th class="px-4 py-3 font-semibold">Trạng thái</th>
                        <th class="px-4 py-3 font-semibold">Thao tác</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-line)]">
                    <tr v-for="u in users?.data" :key="u.id">
                        <td class="px-4 py-3">
                            <p class="font-semibold text-[var(--color-ink)]">
                                {{ u.name }}
                                <span v-if="u.id === currentUserId" class="ml-1 text-[10px] font-bold text-[var(--color-accent)]">(bạn)</span>
                            </p>
                            <p class="text-xs text-[var(--color-muted)]">{{ u.email }}</p>
                            <p class="text-xs text-[var(--color-muted)]">{{ u.phone || '—' }} · tham gia {{ u.created_at }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <code class="text-xs text-[var(--color-ink)]/70">{{ u.sub_id || '—' }}</code>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums text-[var(--color-ink)]/70">{{ u.links_count }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-[var(--color-ink)]/70">{{ vnd(u.approved_commission) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums font-semibold text-[var(--color-brand-green)]">{{ vnd(u.available_balance) }}</td>
                        <td class="px-4 py-3">
                            <span v-if="u.banned_at" class="px-2 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-600">Bị khoá</span>
                            <span v-else-if="u.role === 'admin'" class="px-2 py-1 rounded-full text-xs font-semibold bg-purple-100 text-purple-700">Quản trị</span>
                            <span v-else class="px-2 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-700">Hoạt động</span>
                            <p v-if="u.banned_at && u.banned_reason" class="text-xs text-red-500 mt-1">{{ u.banned_reason }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <button @click="openEdit(u)" class="text-xs font-semibold text-[var(--color-accent)] hover:underline transition">Sửa</button>
                                <button @click="openReset(u)" class="text-xs font-semibold text-blue-600 hover:underline transition">Mật khẩu</button>
                                <button v-if="u.id !== currentUserId" @click="roleTarget = u" class="text-xs font-semibold text-purple-600 hover:underline transition">
                                    {{ u.role === 'admin' ? 'Hạ quyền' : 'Cấp quyền' }}
                                </button>
                                <button v-if="u.banned_at" @click="unban(u)" class="text-xs font-semibold text-[var(--color-brand-green)] hover:underline transition">Mở khoá</button>
                                <button v-else-if="u.id !== currentUserId && u.role !== 'admin'" @click="openBan(u)" class="text-xs font-semibold text-red-500 hover:underline transition">Khoá</button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!users?.data?.length">
                        <td colspan="7" class="px-4 py-10 text-center text-[var(--color-muted)]">Không tìm thấy tài khoản nào.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="flex items-center justify-center gap-3 mt-4" v-if="users?.prev_page_url || users?.next_page_url">
            <button @click="goPage(users.prev_page_url)" :disabled="!users.prev_page_url"
                class="flex-1 md:flex-none px-4 py-2.5 md:py-2 rounded-xl text-sm font-semibold bg-[var(--color-surface)] border border-[var(--color-line)] disabled:opacity-40 disabled:cursor-not-allowed">
                ← Trước
            </button>
            <span class="flex-none text-xs text-[var(--color-muted)] tabular-nums">
                {{ users?.current_page }} / {{ users?.last_page }}
            </span>
            <button @click="goPage(users.next_page_url)" :disabled="!users.next_page_url"
                class="flex-1 md:flex-none px-4 py-2.5 md:py-2 rounded-xl text-sm font-semibold bg-[var(--color-surface)] border border-[var(--color-line)] disabled:opacity-40 disabled:cursor-not-allowed">
                Sau →
            </button>
        </div>

        <!-- Modal sửa thông tin -->
        <div v-if="editing" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
            <div class="bg-[var(--color-surface)] rounded-2xl p-6 w-full max-w-md">
                <h2 class="font-extrabold text-[var(--color-ink)] mb-1">Sửa thông tin tài khoản</h2>
                <p class="text-xs text-[var(--color-muted)] mb-5">#{{ editing.id }} · {{ editing.email }}</p>
                <form @submit.prevent="submitEdit" class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Họ tên *</label>
                        <input v-model="editForm.name" type="text" required
                            class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2.5 text-sm bg-[var(--color-bg)] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-accent)]" />
                        <p v-if="editForm.errors.name" class="text-red-500 text-xs mt-1">{{ editForm.errors.name }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Email *</label>
                        <input v-model="editForm.email" type="email" required
                            class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2.5 text-sm bg-[var(--color-bg)] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-accent)]" />
                        <p v-if="editForm.errors.email" class="text-red-500 text-xs mt-1">{{ editForm.errors.email }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Số điện thoại</label>
                        <input v-model="editForm.phone" type="text"
                            class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2.5 text-sm bg-[var(--color-bg)] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-accent)]" />
                        <p v-if="editForm.errors.phone" class="text-red-500 text-xs mt-1">{{ editForm.errors.phone }}</p>
                    </div>
                    <div class="flex gap-3 pt-2">
                        <button type="submit" :disabled="editForm.processing"
                            class="flex-1 bg-[var(--color-accent)] hover:bg-[var(--color-accent-deep)] text-white font-semibold py-2.5 rounded-xl text-sm transition disabled:opacity-60">
                            Lưu
                        </button>
                        <button type="button" @click="editing = null"
                            class="px-6 bg-[var(--color-peach-soft)] text-[var(--color-ink)] font-semibold py-2.5 rounded-xl text-sm hover:bg-[var(--color-peach)] transition">
                            Hủy
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal đặt lại mật khẩu -->
        <div v-if="resetting" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
            <div class="bg-[var(--color-surface)] rounded-2xl p-6 w-full max-w-md">
                <h2 class="font-extrabold text-[var(--color-ink)] mb-1">Đặt lại mật khẩu</h2>
                <p class="text-xs text-[var(--color-muted)] mb-5">{{ resetting.name }} · {{ resetting.email }}</p>
                <form @submit.prevent="submitReset" class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Mật khẩu mới *</label>
                        <input v-model="passwordForm.password" type="text" required autocomplete="off"
                            class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2.5 text-sm bg-[var(--color-bg)] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-accent)]" />
                        <p class="text-xs text-[var(--color-muted)] mt-1">Tối thiểu 8 ký tự, có chữ hoa, chữ thường và số. Nhớ gửi lại mật khẩu này cho người dùng.</p>
                        <p v-if="passwordForm.errors.password" class="text-red-500 text-xs mt-1">{{ passwordForm.errors.password }}</p>
                    </div>
                    <div class="flex gap-3 pt-2">
                        <button type="submit" :disabled="passwordForm.processing"
                            class="flex-1 bg-[var(--color-accent)] hover:bg-[var(--color-accent-deep)] text-white font-semibold py-2.5 rounded-xl text-sm transition disabled:opacity-60">
                            Đặt lại
                        </button>
                        <button type="button" @click="resetting = null"
                            class="px-6 bg-[var(--color-peach-soft)] text-[var(--color-ink)] font-semibold py-2.5 rounded-xl text-sm hover:bg-[var(--color-peach)] transition">
                            Hủy
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal khoá tài khoản -->
        <div v-if="banning" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
            <div class="bg-[var(--color-surface)] rounded-2xl p-6 w-full max-w-md">
                <h2 class="font-extrabold text-[var(--color-ink)] mb-1">Khoá tài khoản</h2>
                <p class="text-xs text-[var(--color-muted)] mb-5">
                    {{ banning.name }} · {{ banning.email }} — phiên đăng nhập hiện tại sẽ bị ngắt ngay.
                    Lịch sử hoa hồng vẫn được giữ nguyên.
                </p>
                <form @submit.prevent="submitBan" class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Lý do khoá</label>
                        <input v-model="banForm.banned_reason" type="text" placeholder="VD: Gian lận đơn hoàn tiền"
                            class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2.5 text-sm bg-[var(--color-bg)] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-accent)]" />
                        <p v-if="banForm.errors.banned_reason" class="text-red-500 text-xs mt-1">{{ banForm.errors.banned_reason }}</p>
                    </div>
                    <div class="flex gap-3 pt-2">
                        <button type="submit" :disabled="banForm.processing"
                            class="flex-1 bg-red-500 hover:bg-red-600 text-white font-semibold py-2.5 rounded-xl text-sm transition disabled:opacity-60">
                            Khoá tài khoản
                        </button>
                        <button type="button" @click="banning = null"
                            class="px-6 bg-[var(--color-peach-soft)] text-[var(--color-ink)] font-semibold py-2.5 rounded-xl text-sm hover:bg-[var(--color-peach)] transition">
                            Hủy
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Xác nhận đổi quyền -->
        <div v-if="roleTarget" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
            <div class="bg-[var(--color-surface)] rounded-2xl p-6 w-full max-w-md">
                <h2 class="font-extrabold text-[var(--color-ink)] mb-1">
                    {{ roleTarget.role === 'admin' ? 'Hạ quyền quản trị' : 'Cấp quyền quản trị' }}
                </h2>
                <p class="text-xs text-[var(--color-muted)] mb-5">
                    {{ roleTarget.name }} · {{ roleTarget.email }}
                    <template v-if="roleTarget.role !== 'admin'">
                        — tài khoản quản trị đăng nhập tại <b>/admin/login</b> và thấy được toàn bộ dữ liệu hệ thống.
                    </template>
                </p>
                <div class="flex gap-3">
                    <button @click="confirmRole"
                        class="flex-1 bg-[var(--color-accent)] hover:bg-[var(--color-accent-deep)] text-white font-semibold py-2.5 rounded-xl text-sm transition">
                        Xác nhận
                    </button>
                    <button @click="roleTarget = null"
                        class="px-6 bg-[var(--color-peach-soft)] text-[var(--color-ink)] font-semibold py-2.5 rounded-xl text-sm hover:bg-[var(--color-peach)] transition">
                        Hủy
                    </button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
