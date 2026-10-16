import { ref } from 'vue'
import { useToast } from '@/composables/useToast'

// Quyền hiện thông báo của hệ điều hành cho trang quản trị (AdminLayout bắn khi có khách nhắn
// hay rút tiền). Ref đặt ngoài hàm để chuông ở header và băng nhắc ở /admin/chats cùng thấy một
// trạng thái: bấm bật ở chỗ này thì nút ở chỗ kia cũng tự ẩn.
const permission = ref(typeof Notification === 'undefined' ? 'unsupported' : Notification.permission)

export function useDesktopNotify() {
    const toast = useToast()

    // Trình duyệt chỉ cho xin quyền từ một cú bấm thật, nên chỉ gọi hàm này trong @click.
    function ask() {
        Notification.requestPermission().then((result) => {
            permission.value = result
            if (result === 'granted') toast.success('Đã bật — có khách nhắn hay rút tiền là hiện thông báo, miễn là còn mở một tab quản trị.')
            else toast.info('Bạn đã từ chối. Bật lại trong phần quyền của trình duyệt cho trang này.')
        })
    }

    return { permission, ask }
}
