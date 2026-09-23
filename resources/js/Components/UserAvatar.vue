<script setup>
import { computed, ref, watch } from 'vue'

// Khung tròn ảnh đại diện. Có ảnh thì hiện ảnh, chưa có thì vẫn là chữ cái đầu của tên như
// trước — gom vào một chỗ vì hiện có 5 nơi vẽ đúng khung này (sidebar Tài khoản, menu trượt,
// đầu trang Tổng quan, trang Thông tin cá nhân, bảng vàng hoàn tiền), mỗi nơi tự xử lý là kiểu
// gì cũng có chỗ quên mất phần ảnh.
//
// Kích thước và cỡ chữ do NƠI GỌI truyền qua class (Vue tự gộp với class ở đây) — mỗi chỗ một
// cỡ khác nhau, nhét hết vào prop thì thành một bảng size không ai nhớ nổi.
const props = defineProps({
    src: { type: String, default: null },
    name: { type: String, default: '' },
    // Màu nền khi chưa có ảnh. Mặc định là màu nhấn của web; bảng vàng truyền màu riêng theo
    // tên để mỗi người một màu cố định.
    gradient: { type: String, default: 'from-[var(--color-accent)] to-[var(--color-accent-deep)]' },
})

const initial = computed(() => (props.name || '').trim().charAt(0).toUpperCase() || '?')

// Ảnh 404 (file bị xoá tay trên server, hoặc URL cũ trong cache bảng vàng) thì quay về chữ cái
// đầu thay vì để trình duyệt vẽ icon ảnh vỡ giữa khung tròn.
const broken = ref(false)
watch(() => props.src, () => { broken.value = false })

const showImage = computed(() => !!props.src && !broken.value)
</script>

<template>
    <div
        class="rounded-full overflow-hidden flex items-center justify-center text-white font-extrabold bg-gradient-to-br"
        :class="gradient"
    >
        <img
            v-if="showImage"
            :src="src"
            :alt="name ? `Ảnh đại diện của ${name}` : 'Ảnh đại diện'"
            loading="lazy"
            class="w-full h-full object-cover"
            @error="broken = true"
        />
        <span v-else>{{ initial }}</span>
    </div>
</template>
