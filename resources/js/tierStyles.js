// Màu của từng hạng thành viên — dùng chung giữa bảng hạng ở trang khách
// (Components/MembershipTiers.vue) và thẻ hạng trong Tài khoản (Components/MembershipTierProgress.vue).
//
// Vì sao là chuỗi class viết sẵn chứ không ghép động (`from-${color}-400`): Tailwind quét mã
// nguồn theo văn bản, class ghép lúc chạy không có trong bản build và ra trang trắng trơn không
// màu — lỗi chỉ lộ ra ở production build, còn `npm run dev` vẫn đẹp như thường.
//
// Danh sách KHOÁ hạng là của server (MembershipTierService::TIERS). Ở đây chỉ có màu; thêm hạng
// mới mà quên thêm màu thì rơi về `fallback`, xấu nhưng không vỡ.
const styles = {
    tan_binh: {
        card: 'bg-emerald-50 border-emerald-200 dark:bg-emerald-950/30 dark:border-emerald-900',
        icon: 'bg-gradient-to-br from-emerald-400 to-emerald-600 text-white',
        text: 'text-emerald-700 dark:text-emerald-300',
        bar: 'bg-emerald-500',
    },
    dong: {
        card: 'bg-orange-50 border-orange-200 dark:bg-orange-950/30 dark:border-orange-900',
        icon: 'bg-gradient-to-br from-orange-400 to-orange-600 text-white',
        text: 'text-orange-700 dark:text-orange-300',
        bar: 'bg-orange-500',
    },
    bac: {
        card: 'bg-slate-50 border-slate-200 dark:bg-slate-900/60 dark:border-slate-700',
        icon: 'bg-gradient-to-br from-slate-400 to-slate-600 text-white',
        text: 'text-slate-700 dark:text-slate-200',
        bar: 'bg-slate-500',
    },
    vang: {
        card: 'bg-amber-50 border-amber-200 dark:bg-amber-950/30 dark:border-amber-900',
        icon: 'bg-gradient-to-br from-amber-400 to-amber-500 text-white',
        text: 'text-amber-700 dark:text-amber-300',
        bar: 'bg-amber-500',
    },
    bach_kim: {
        card: 'bg-sky-50 border-sky-200 dark:bg-sky-950/30 dark:border-sky-900',
        icon: 'bg-gradient-to-br from-sky-400 to-cyan-500 text-white',
        text: 'text-sky-700 dark:text-sky-300',
        bar: 'bg-sky-500',
    },
    kim_cuong: {
        card: 'bg-purple-50 border-purple-200 dark:bg-purple-950/30 dark:border-purple-900',
        icon: 'bg-gradient-to-br from-purple-400 to-fuchsia-600 text-white',
        text: 'text-purple-700 dark:text-purple-300',
        bar: 'bg-purple-500',
    },
}

const fallback = {
    card: 'bg-[var(--color-surface)] border-[var(--color-line)]',
    icon: 'bg-[var(--color-peach-soft)] text-[var(--color-ink)]',
    text: 'text-[var(--color-ink)]',
    bar: 'bg-[var(--color-accent)]',
}

export function tierStyle(key) {
    return styles[key] || fallback
}
