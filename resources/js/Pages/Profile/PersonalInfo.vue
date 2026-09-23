<script setup>
import { computed, ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import AccountLayout from '@/Layouts/AccountLayout.vue'
import UserAvatar from '@/Components/UserAvatar.vue'
import { useToast } from '@/composables/useToast'

const toast = useToast()

const props = defineProps({
    profile: Object,
    payoutAccounts: Object,
    // Trần dung lượng ảnh (KB) lấy từ AvatarService để không gõ cứng hai nơi rồi lệch nhau.
    avatarMaxKb: { type: Number, default: 5120 },
})

// --- Ảnh đại diện ---
//
// Chọn xong là tải lên luôn, không có nút "Lưu ảnh" riêng: đây là form một trường, bắt bấm
// thêm một nút nữa chỉ tạo thêm chỗ để quên bấm rồi tưởng đã lưu.
const fileInput = ref(null)
const uploading = ref(false)
const avatarError = ref('')

// Ảnh xem trước dựng từ file ngay trên máy, hiện trong lúc chờ server trả lời — mạng điện
// thoại tải 3MB mất vài giây, không có gì nhúc nhích thì khách bấm chọn lại lần nữa.
const preview = ref(null)
const avatarSrc = computed(() => preview.value || props.profile.avatar)
const maxMb = computed(() => Math.round(props.avatarMaxKb / 1024))

function clearPreview() {
    if (preview.value) {
        URL.revokeObjectURL(preview.value)
        preview.value = null
    }
}

function onAvatarPicked(event) {
    const file = event.target.files?.[0]

    // Xoá giá trị input ngay: không xoá thì chọn LẠI đúng file vừa chọn sẽ không bắn sự kiện
    // change, khách bấm mà tưởng web đơ.
    event.target.value = ''

    if (!file) return

    avatarError.value = ''

    // Chặn ngay trên máy thay vì để khách tải lên hết 10MB rồi mới nhận lỗi từ server.
    if (file.size > props.avatarMaxKb * 1024) {
        avatarError.value = `Ảnh tối đa ${maxMb.value}MB. Ảnh bạn chọn nặng ${(file.size / 1024 / 1024).toFixed(1)}MB.`
        return
    }

    clearPreview()
    preview.value = URL.createObjectURL(file)
    uploading.value = true

    router.post('/profile/avatar', { avatar: file }, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => toast.success('Đã cập nhật ảnh đại diện'),
        onError: (errors) => { avatarError.value = errors.avatar || 'Tải ảnh không thành công, thử lại giúp mình nhé.' },
        onFinish: () => {
            uploading.value = false
            clearPreview()
        },
    })
}

function removeAvatar() {
    avatarError.value = ''

    router.delete('/profile/avatar', {
        preserveScroll: true,
        onSuccess: () => toast.success('Đã gỡ ảnh đại diện'),
    })
}

// --- Thông tin cá nhân ---
const profileForm = useForm({
    name: props.profile.name,
    phone: props.profile.phone || '',
})

function saveProfile() {
    profileForm.patch('/profile', {
        preserveScroll: true,
        onSuccess: () => toast.success('Đã cập nhật thông tin'),
    })
}

// --- Ví nhận tiền ---
const providers = [
    { key: 'momo', label: 'Ví MoMo', color: 'text-pink-600' },
    { key: 'zalopay', label: 'Ví ZaloPay', color: 'text-blue-600' },
]

const payoutForms = {
    momo: useForm({
        provider: 'momo',
        account_number: props.payoutAccounts?.momo?.account_number || '',
        account_name: props.payoutAccounts?.momo?.account_name || '',
    }),
    zalopay: useForm({
        provider: 'zalopay',
        account_number: props.payoutAccounts?.zalopay?.account_number || '',
        account_name: props.payoutAccounts?.zalopay?.account_name || '',
    }),
}

function savePayout(key) {
    payoutForms[key].post('/profile/payout', {
        preserveScroll: true,
        onSuccess: () => toast.success('Đã lưu thông tin ví'),
    })
}
</script>

<template>
    <Head title="Thông tin cá nhân" />
    <AccountLayout>
        <div class="space-y-6">
            <h1 class="text-2xl font-extrabold text-[var(--color-ink)]">Thông tin cá nhân</h1>

            <!-- Ảnh đại diện -->
            <div class="card-glass rounded-2xl p-6">
                <h2 class="font-bold text-[var(--color-ink)] mb-1">Ảnh đại diện</h2>
                <p class="text-xs text-[var(--color-muted)] mb-4">
                    Ảnh này hiện ở <b class="text-[var(--color-ink)]">bảng vàng hoàn tiền</b> nếu tháng đó bạn có tên —
                    ai vào trang chủ cũng thấy. Chưa tải ảnh thì chỗ đó vẫn là chữ cái đầu của tên bạn.
                </p>

                <div class="flex items-center gap-5">
                    <div class="relative flex-none">
                        <UserAvatar
                            :src="avatarSrc"
                            :name="profile.name || profile.email"
                            class="w-20 h-20 text-2xl ring-2 ring-[var(--color-line)]"
                            :class="uploading ? 'opacity-60' : ''"
                        />
                        <div v-if="uploading" class="absolute inset-0 flex items-center justify-center">
                            <span class="w-6 h-6 rounded-full border-2 border-white/40 border-t-white animate-spin"></span>
                        </div>
                    </div>

                    <div class="min-w-0 space-y-2">
                        <div class="flex flex-wrap gap-2">
                            <button type="button" :disabled="uploading" @click="fileInput?.click()"
                                class="bg-[var(--color-peach-soft)] hover:bg-[var(--color-peach)] text-[var(--color-ink)] text-sm font-semibold px-4 py-2 rounded-xl transition disabled:opacity-60">
                                {{ profile.avatar ? 'Đổi ảnh' : 'Chọn ảnh' }}
                            </button>
                            <button v-if="profile.avatar" type="button" :disabled="uploading" @click="removeAvatar"
                                class="text-sm font-semibold px-4 py-2 rounded-xl border border-[var(--color-line)] text-[var(--color-muted)] hover:text-red-500 hover:border-red-300 transition disabled:opacity-60">
                                Gỡ ảnh
                            </button>
                        </div>
                        <p class="text-[11px] text-[var(--color-muted)]">JPG, PNG hoặc WebP — tối đa {{ maxMb }}MB. Ảnh sẽ được cắt vuông tự động.</p>
                        <p v-if="avatarError" class="text-xs text-red-500">{{ avatarError }}</p>
                    </div>
                </div>

                <input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp" class="hidden"
                    @change="onAvatarPicked" />
            </div>

            <!-- Thông tin cá nhân -->
            <div class="card-glass rounded-2xl p-6">
                <h2 class="font-bold text-[var(--color-ink)] mb-4">Hồ sơ</h2>
                <form @submit.prevent="saveProfile" class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Họ tên</label>
                        <input v-model="profileForm.name" type="text" required
                            class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2.5 text-sm bg-[var(--color-bg)] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-accent)]" />
                        <p v-if="profileForm.errors.name" class="text-xs text-red-500 mt-1">{{ profileForm.errors.name }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Email</label>
                        <input :value="profile.email" type="email" disabled
                            class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2.5 text-sm bg-[var(--color-peach-soft)] text-[var(--color-muted)] cursor-not-allowed" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Số điện thoại</label>
                        <input v-model="profileForm.phone" type="tel"
                            class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2.5 text-sm bg-[var(--color-bg)] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-accent)]" />
                        <p v-if="profileForm.errors.phone" class="text-xs text-red-500 mt-1">{{ profileForm.errors.phone }}</p>
                    </div>
                    <button type="submit" :disabled="profileForm.processing"
                        class="btn-fire text-sm px-5 py-2.5 rounded-xl">
                        Lưu thông tin
                    </button>
                </form>
            </div>

            <!-- Ví nhận tiền -->
            <div class="card-glass rounded-2xl p-6">
                <h2 class="font-bold text-[var(--color-ink)] mb-1">Ví nhận tiền</h2>
                <p class="text-xs text-[var(--color-muted)] mb-4">Thiết lập ví MoMo / ZaloPay để nhận hoa hồng khi rút.</p>
                <div class="grid md:grid-cols-2 gap-4">
                    <form v-for="p in providers" :key="p.key" @submit.prevent="savePayout(p.key)"
                        class="border border-[var(--color-line)] rounded-xl p-4 space-y-3">
                        <p class="font-bold text-sm" :class="p.color">{{ p.label }}</p>
                        <div>
                            <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Số điện thoại ví</label>
                            <input v-model="payoutForms[p.key].account_number" type="tel" placeholder="VD: 0901234567"
                                class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2 text-sm bg-[var(--color-bg)] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-accent)]" />
                            <p v-if="payoutForms[p.key].errors.account_number" class="text-xs text-red-500 mt-1">{{ payoutForms[p.key].errors.account_number }}</p>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Tên chủ ví</label>
                            <input v-model="payoutForms[p.key].account_name" type="text" placeholder="VD: NGUYEN VAN A"
                                class="w-full border border-[var(--color-line)] rounded-xl px-3 py-2 text-sm bg-[var(--color-bg)] text-[var(--color-ink)] focus:outline-none focus:border-[var(--color-accent)]" />
                            <p v-if="payoutForms[p.key].errors.account_name" class="text-xs text-red-500 mt-1">{{ payoutForms[p.key].errors.account_name }}</p>
                        </div>
                        <button type="submit" :disabled="payoutForms[p.key].processing"
                            class="w-full bg-[var(--color-peach-soft)] hover:bg-[var(--color-peach)] text-[var(--color-ink)] text-sm font-semibold py-2 rounded-xl transition disabled:opacity-60">
                            Lưu {{ p.label }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </AccountLayout>
</template>
