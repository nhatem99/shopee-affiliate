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
// Màu ở đây là màu THƯƠNG HIỆU của hai ví, giữ lại vì nó giúp khách nhận ra ô nào là ô nào
// nhanh hơn chữ. Nhưng phải có bản tối: text-pink-600 trên nền navy chỉ được ~3.2:1. ZaloPay
// dùng thẳng token info (cũng là xanh dương) nên khỏi tự chế thêm một cặp màu nữa.
const providers = [
    { key: 'momo', label: 'Ví MoMo', color: 'text-pink-600 dark:text-pink-400' },
    { key: 'zalopay', label: 'Ví ZaloPay', color: 'text-[var(--color-info)]' },
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
            <div class="card p-6">
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
                        <!-- Hai nút phụ đi màu trung tính: cả trang chỉ có MỘT nút cam là "Lưu
                             thông tin" ở khối dưới. Nền peach cũ làm bốn nút trên trang này trông
                             quan trọng ngang nhau.
                             Hover đổi NỀN chứ không hạ opacity: opacity kéo tụt cả chữ, mà Safari
                             iOS còn giữ :hover sau khi chạm nên nút sẽ đứng mờ luôn. -->
                        <div class="flex flex-wrap gap-2">
                            <button type="button" :disabled="uploading" @click="fileInput?.click()"
                                class="panel focus-ring inline-flex items-center justify-center min-h-[44px] px-4 border border-[var(--color-line)] text-[var(--color-ink)] text-sm font-semibold transition hover:bg-[var(--color-line)] disabled:opacity-60">
                                {{ profile.avatar ? 'Đổi ảnh' : 'Chọn ảnh' }}
                            </button>
                            <button v-if="profile.avatar" type="button" :disabled="uploading" @click="removeAvatar"
                                class="focus-ring inline-flex items-center justify-center min-h-[44px] px-4 rounded-xl border border-[var(--color-line)] text-sm font-semibold text-[var(--color-muted)] hover:text-[var(--color-danger)] hover:border-[var(--color-danger)] transition disabled:opacity-60">
                                Gỡ ảnh
                            </button>
                        </div>
                        <p class="text-xs text-[var(--color-muted)]">JPG, PNG hoặc WebP — tối đa {{ maxMb }}MB. Ảnh sẽ được cắt vuông tự động.</p>
                        <p v-if="avatarError" class="text-xs text-[var(--color-danger)]">{{ avatarError }}</p>
                    </div>
                </div>

                <input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp" class="hidden"
                    @change="onAvatarPicked" />
            </div>

            <!-- Thông tin cá nhân -->
            <div class="card p-6">
                <h2 class="font-bold text-[var(--color-ink)] mb-4">Hồ sơ</h2>
                <form @submit.prevent="saveProfile" class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Họ tên</label>
                        <!-- Bỏ: đổi màu viền là tín hiệu quá mờ để biết con trỏ
                             đang ở ô nào. .focus-ring vẽ vòng cam rõ ràng khi đi bằng bàn phím. -->
                        <input v-model="profileForm.name" type="text" required
                            class="focus-ring w-full min-h-[44px] border border-[var(--color-line)] rounded-xl px-3 text-sm bg-[var(--color-bg)] text-[var(--color-ink)] focus:border-[var(--color-accent)]" />
                        <p v-if="profileForm.errors.name" class="text-xs text-[var(--color-danger)] mt-1">{{ profileForm.errors.name }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Email</label>
                        <input :value="profile.email" type="email" disabled
                            class="panel w-full min-h-[44px] border border-[var(--color-line)] px-3 text-sm text-[var(--color-muted)] cursor-not-allowed" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Số điện thoại</label>
                        <input v-model="profileForm.phone" type="tel"
                            class="focus-ring num w-full min-h-[44px] border border-[var(--color-line)] rounded-xl px-3 text-sm bg-[var(--color-bg)] text-[var(--color-ink)] focus:border-[var(--color-accent)]" />
                        <p v-if="profileForm.errors.phone" class="text-xs text-[var(--color-danger)] mt-1">{{ profileForm.errors.phone }}</p>
                    </div>
                    <button type="submit" :disabled="profileForm.processing"
                        class="btn-fire focus-ring inline-flex items-center justify-center text-sm px-5 rounded-xl">
                        Lưu thông tin
                    </button>
                </form>
            </div>

            <!-- Ví nhận tiền -->
            <div class="card p-6">
                <h2 class="font-bold text-[var(--color-ink)] mb-1">Ví nhận tiền</h2>
                <p class="text-xs text-[var(--color-muted)] mb-4">Thiết lập ví MoMo / ZaloPay để nhận hoa hồng khi rút.</p>
                <div class="grid md:grid-cols-2 gap-4">
                    <form v-for="p in providers" :key="p.key" @submit.prevent="savePayout(p.key)"
                        class="border border-[var(--color-line)] rounded-xl p-4 space-y-3">
                        <p class="font-bold text-sm" :class="p.color">{{ p.label }}</p>
                        <div>
                            <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Số điện thoại ví</label>
                            <input v-model="payoutForms[p.key].account_number" type="tel" placeholder="VD: 0901234567"
                                class="focus-ring num w-full min-h-[44px] border border-[var(--color-line)] rounded-xl px-3 text-sm bg-[var(--color-bg)] text-[var(--color-ink)] focus:border-[var(--color-accent)]" />
                            <p v-if="payoutForms[p.key].errors.account_number" class="text-xs text-[var(--color-danger)] mt-1">{{ payoutForms[p.key].errors.account_number }}</p>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-[var(--color-ink)] mb-1">Tên chủ ví</label>
                            <input v-model="payoutForms[p.key].account_name" type="text" placeholder="VD: NGUYEN VAN A"
                                class="focus-ring w-full min-h-[44px] border border-[var(--color-line)] rounded-xl px-3 text-sm bg-[var(--color-bg)] text-[var(--color-ink)] focus:border-[var(--color-accent)]" />
                            <p v-if="payoutForms[p.key].errors.account_name" class="text-xs text-[var(--color-danger)] mt-1">{{ payoutForms[p.key].errors.account_name }}</p>
                        </div>
                        <button type="submit" :disabled="payoutForms[p.key].processing"
                            class="panel focus-ring w-full inline-flex items-center justify-center min-h-[44px] border border-[var(--color-line)] text-[var(--color-ink)] text-sm font-semibold transition hover:bg-[var(--color-line)] disabled:opacity-60">
                            Lưu {{ p.label }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </AccountLayout>
</template>
