<script setup>
import { ref, computed, nextTick, onMounted, onUnmounted, watch } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import { useLocalStorage } from '@vueuse/core'
import AppLayout from '@/Layouts/AppLayout.vue'
import CouponTicket from '@/Components/CouponTicket.vue'
import RestockSchedule from '@/Components/RestockSchedule.vue'
import CashbackExplainer from '@/Components/CashbackExplainer.vue'
import CashbackLeaderboard from '@/Components/CashbackLeaderboard.vue'
import HowItWorksSteps from '@/Components/HowItWorksSteps.vue'
import MembershipTiers from '@/Components/MembershipTiers.vue'
import DailyCheckIn from '@/Components/DailyCheckIn.vue'
import { useToast } from '@/composables/useToast'
import { useCashback } from '@/composables/useCashback'
import { useFestive } from '@/composables/useFestive'
import { useAuthStore } from '@/Stores/useAuthStore'

const props = defineProps({
    vouchers: { type: Array, default: () => [] },
    voucherResult: { type: Object, default: null },
    canUseVoucherTool: { type: Boolean, default: true },
    // Khi admin đã bật chuyển hướng qua comment Facebook kèm chế độ tự chuyển hướng: ẩn luôn
    // nút "Mua ngay", đưa khách thẳng tới comment ngay sau khi dán link. Mỗi sản phẩm chỉ sinh
    // 1 comment thay vì mỗi lượt bấm lại thêm 1 cái.
    autoRedirect: { type: Boolean, default: false },
    // Cú bấm "Mua ngay" sẽ mở COMMENT TRÊN FACEBOOK chứ không phải thẳng Shopee — khách phải
    // bấm tiếp link trong comment đó mới về Shopee. Bắt buộc nói trước, nếu không khách bấm
    // xong thấy Facebook hiện ra sẽ tưởng bị lỗi hoặc bị lừa rồi thoát.
    viaFacebookComment: { type: Boolean, default: false },
    // 'reel' = link nằm trong PHẦN MÔ TẢ của reel; 'comment' = link nằm trong BÌNH LUẬN dưới
    // bài viết. Chỉ đúng một chỗ có link, chỉ sai chỗ là khách loay hoay không thấy rồi thoát.
    facebookMode: { type: String, default: 'comment' },
    // Bảng vàng hoàn tiền tháng này — server chỉ gửi khi chương trình đang bật (null khi tắt).
    leaderboard: { type: Object, default: null },
    // Bảng hạng thành viên + tiến độ của chính khách này — null khi admin tắt chương trình hạng
    // hoặc khi tỉ lệ hoàn tiền đang là 0 (xem MembershipTierService::enabled).
    membershipTiers: { type: Object, default: null },
    // Thẻ Điểm danh nhận quà — null khi admin tắt chương trình (xem DailyCheckInService).
    // Khách vãng lai vẫn có dữ liệu này: thẻ hiện đủ kho quà và dẫn họ đi đăng ký.
    dailyCheckIn: { type: Object, default: null },
})

// Nơi khách phải bấm sau khi Facebook mở ra — dùng lại ở nhiều câu hướng dẫn nên gom một chỗ.
const linkLocation = computed(() => props.facebookMode === 'reel'
    ? 'link trong phần mô tả của reel'
    : 'link trong bình luận')

const toast = useToast()
const auth = useAuthStore()
const { cashbackRate, cashbackOn, joinHref, joinLabel } = useCashback()
const { celebrate } = useFestive()

// Nhắc hoàn tiền cho khách VÃNG LAI. Chỉ hiện khi chương trình đang bật và khách chưa đăng nhập:
// đúng lúc đó, mỗi cú bấm mua là một đơn vĩnh viễn không quy về ai được (ShortLinkController chỉ
// gắn sub_id khi có user), và không có đường bù lại sau.
const showGuestCashbackNudge = computed(() => cashbackOn.value && !auth.isLoggedIn)

/**
 * Khách ĐÃ đăng nhập có được nói "đơn này sẽ được ghi nhận" hay không.
 *
 * Ở chế độ đi vòng qua Facebook thì KHÔNG được nói: comment/reel được dùng lại theo SẢN PHẨM
 * trong 20 phút chứ không theo từng khách (xem ShortLinkController::facebookCommentRedirectUrl),
 * nên khách thứ hai mua cùng sản phẩm trong cửa sổ đó sẽ đi qua short-link mang sub_id của khách
 * thứ nhất. Hứa chắc ở đây là hứa sai với đúng những người tin mình nhất.
 */
const showLoggedInCashbackBadge = computed(
    () => cashbackOn.value && auth.isLoggedIn && !props.viaFacebookComment,
)

// --- tietkiemvi.com: công cụ lấy link voucher công khai, không cần đăng nhập ---
// Nguồn cấp mã (kieushopee) trả về ĐÚNG MỘT link đã áp sẵn mã cho mỗi sản phẩm, nên ở đây
// chỉ có một nút "Mua ngay" — không còn danh sách mã theo từng nền tảng để khách phải chọn.
// Server chỉ trả về `voucher_ref` (token mờ); URL affiliate thật được giải mã lại ở
// /voucher/shorten khi khách thực sự bấm — xem ShopeeVoucherController::maskVoucherLink().

const voucherUrl = ref('')
const voucherUrlInput = ref(null)
const voucherResultEl = ref(null)
const resultCtaEl = ref(null)
const resolving = ref(false)
const voucherError = ref(null)
const history = useLocalStorage('sv_history', [])

// Công tắc "Mua lại từ lịch sử" ở Admin > Cài đặt (Setting 'history_rebuy_enabled'). TẮT (mặc
// định) thì mọi mục lịch sử chỉ còn là sổ ghi những sản phẩm đã tra: khách muốn mua phải dán lại
// link để quét mới, vì mã gắn trong ref cũ có thể đã hết lượt/hết hạn từ lúc quét.
const page = usePage()
const historyRebuy = computed(() => page.props.settings?.historyRebuyEnabled ?? false)

// Dọn lịch sử ngay khi mở trang, trước khi khách kịp bấm phải mục chết:
//  - ref chỉ sống 7 ngày (VoucherRefService::TTL_DAYS) — mục cũ hơn bấm chắc chắn lỗi.
//  - HISTORY_VALID_FROM: mốc chuyển ref từ cache sang bảng DB. Ref phát trước mốc này nằm trong
//    cache và đã bị deploy (optimize:clear) xoá sạch, nên mục nào cũ hơn cũng bỏ luôn.
//  - Mục không có created_at là định dạng cũ (trước khi lưu ref) — không bấm được, bỏ.
const HISTORY_VALID_FROM = Date.parse('2026-09-14T00:00:00+07:00')
const HISTORY_TTL_MS = 7 * 24 * 60 * 60 * 1000
history.value = history.value.filter((h) => {
    const t = Date.parse(h?.created_at)
    return !Number.isNaN(t) && t >= HISTORY_VALID_FROM && Date.now() - t < HISTORY_TTL_MS
})

// Khối tab ngay dưới ô dán link: "Lịch sử" | "Bảng xếp hạng". Bảng xếp hạng từng nằm tận cuối
// trang, sau khối giải thích hoàn tiền — trên điện thoại là 3-4 màn hình, không ai kéo tới.
// Đưa lên đây, cạnh lịch sử, để khách vừa dán link xong là thấy ngay có người đang nhận tiền.
// Chưa có lịch sử thì mở sẵn tab bảng xếp hạng (tab lịch sử trống chẳng có gì để nhìn);
// đã có lịch sử thì ưu tiên lịch sử — đó là thứ khách quay lại trang để dùng.
const hasLeaderboard = computed(() => cashbackOn.value && !!props.leaderboard)
const activeTab = ref(history.value.length ? 'history' : 'leaderboard')
const showTabs = computed(() => hasLeaderboard.value && history.value.length > 0)

// Link đã quét xong gần nhất. So với nội dung đang có trong ô để biết có gì mới cần quét hay
// không — dùng chính chuỗi khách nhập chứ không phải canonical_url của server, vì server đã bung
// link ngắn thành link đầy đủ nên hai bên không bao giờ khớp.
const resolvedUrl = ref(null)

const alreadyResolved = computed(
    () => resolvedUrl.value !== null && voucherUrl.value.trim() === resolvedUrl.value,
)

// Moi link ra khỏi đoạn text vừa dán. App Shopee (và Zalo/Messenger khi chuyển tiếp) hay copy
// kèm lời dẫn kiểu "Xem sản phẩm ... tại Shopee! https://vn.shp.ee/..." — gửi nguyên cả câu lên
// server là rớt validate `url`, khách chỉ thấy "Có lỗi xảy ra". Không có link thì trả nguyên text
// để server báo đúng lý do.
function extractUrl(text) {
    return String(text || '').match(/https?:\/\/\S+/i)?.[0] ?? String(text || '').trim()
}

async function pasteVoucherUrl() {
    try {
        let text = (await navigator.clipboard.readText()).trim()
        // Clipboard chỉ có kiểu URL (một số app iOS copy link bằng UIPasteboard.url, không kèm
        // plain text): readText() trả rỗng dù dán tay vẫn ra chữ. Đọc thêm text/uri-list.
        if (!text && navigator.clipboard.read) {
            for (const item of await navigator.clipboard.read()) {
                if (item.types.includes('text/uri-list')) {
                    text = (await (await item.getType('text/uri-list')).text()).trim()
                    break
                }
            }
        }
        if (text) {
            voucherUrl.value = extractUrl(text)
            resolveVoucher()
        }
    } catch (e) {
        toast.error('Không thể đọc clipboard. Hãy dán thủ công (Ctrl+V).')
    }
}

// Dán link vào ô là tự động quét luôn, không cần bấm nút — giống các trang tương tự.
function onVoucherUrlPaste(e) {
    const data = e.clipboardData || window.clipboardData
    // Cùng lý do với pasteVoucherUrl: clipboard chỉ mang kiểu URL thì getData('text') rỗng.
    const text = (data?.getData('text') || data?.getData('text/uri-list') || data?.getData('url') || '').trim()
    if (!text) return
    e.preventDefault()
    voucherUrl.value = extractUrl(text)
    resolveVoucher()
}

// Giá trị của ô NGAY TRƯỚC lần đổi gần nhất — để onVoucherUrlInput biết đoạn nào vừa được chèn.
// Bắt bằng watcher sync (chạy ngay trong lúc gán, trước handler @input) chứ không ghi trong
// handler: gán bằng code (nút Dán, sự kiện paste) không sinh sự kiện input, ghi trong handler
// thì mốc so sánh bị cũ và lần chèn sau tính nhầm cả link cũ vào đoạn "vừa chèn".
let voucherUrlBefore = ''
watch(voucherUrl, (_, oldValue) => {
    voucherUrlBefore = oldValue
}, { flush: 'sync' })

/**
 * Lưới hứng cho những đường dán KHÔNG đi qua sự kiện `paste`, mà v-model vẫn nhận được chữ:
 *  • gợi ý clipboard trên thanh QuickType của bàn phím iPhone (ô type=url hiện sẵn link vừa
 *    copy ở app khác, chạm là chèn) — WebKit coi đó là gõ chữ (insertText), không phải paste;
 *  • kéo-thả, tự điền, hoặc handler paste ở trên bỏ qua vì không đọc được text.
 * Không có lưới này thì link hiện trong ô mà không quét gì cả — khách tưởng trang chết, vì họ
 * đã quen "dán là chạy". Ca thật trên iPhone/Safari: dán link thứ hai xong ô hiện link mà không quét.
 *
 * Chỉ nhận ĐOẠN VỪA CHÈN (so với giá trị trước đó) chứ không nhận cả ô: chèn vào ô đang có link
 * cũ thì ô thành "link1link2", lấy link cuối trong đoạn chèn mới đúng là link khách muốn. Gõ tay
 * từng ký tự thì đoạn chèn không bao giờ thành link → không quét, nút "Tìm mã ngay" vẫn còn đó.
 */
function onVoucherUrlInput(e) {
    const value = e.target.value
    const before = voucherUrlBefore
    let head = 0
    while (head < before.length && head < value.length && before[head] === value[head]) head++
    let tail = 0
    while (tail < before.length - head && tail < value.length - head
        && before[before.length - 1 - tail] === value[value.length - 1 - tail]) tail++
    const inserted = value.slice(head, value.length - tail)
    const url = inserted.match(/https?:\/\/\S+/i)?.[0]
    if (!url) return
    voucherUrl.value = url
    resolveVoucher()
}

/**
 * Cuộn cho khối kết quả hiện ra ngay dưới vùng dính (header + khung dán link + dải nhắc).
 * Không dùng scrollIntoView + scroll-mt cố định: vùng dính cao ~300px trên điện thoại và
 * đổi chiều cao khi thu gọn, con số cứng luôn lệch. Ưu tiên nút mua/Mở Facebook: nếu đầu
 * kết quả vừa khít mà nút vẫn tụt dưới mép màn hình (hoặc dưới BottomNav) thì cuộn thêm.
 *
 * Ép khung thu gọn (stuck) TRƯỚC, đợi transition 200ms chạy xong rồi mới đo và cuộn. Nếu
 * cuộn ngay thì khung thu gọn giữa chừng lúc trang đang trượt: nội dung bên dưới trồi lên
 * trong khi màn hình đi xuống — nhìn giật, và mọi vị trí đo lúc đầu đều sai một khoảng bằng
 * phần vừa co lại. Đằng nào cuộn tới kết quả cũng qua ngưỡng dính, nên ép sớm không đổi gì.
 */
const BOTTOM_NAV_HEIGHT = 80
const STICKY_TRANSITION_MS = 200

function scrollToResult() {
    stuck.value = true
    setTimeout(() => {
        const result = voucherResultEl.value
        if (!result) return
        const topGap = HEADER_HEIGHT + (stickyEl.value?.offsetHeight ?? 0) + 12
        let top = result.getBoundingClientRect().top + window.scrollY - topGap

        const cta = resultCtaEl.value
        if (cta) {
            const ctaBottom = cta.getBoundingClientRect().bottom + window.scrollY + 16
            const visibleHeight = window.innerHeight - BOTTOM_NAV_HEIGHT
            if (ctaBottom - top > visibleHeight) top = ctaBottom - visibleHeight
        }

        window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' })
    }, STICKY_TRANSITION_MS + 50)
}

function focusVoucherTool() {
    voucherUrlInput.value?.scrollIntoView({ behavior: 'smooth', block: 'center' })
    voucherUrlInput.value?.focus()
}

// Link đang được tìm (null khi rảnh) và số thứ tự lượt tìm mới nhất. Mỗi lượt giữ số của mình
// và chỉ đụng vào trạng thái chung khi vẫn là lượt mới nhất — lượt cũ bị Inertia huỷ (khách dán
// link khác đè lên) vẫn được gọi onFinish, không có số thì nó tắt cờ `resolving` của lượt mới.
let resolvingUrl = null
let resolveSeq = 0

function resolveVoucher() {
    const url = voucherUrl.value.trim()
    if (!url) return
    // Chặn gửi trùng ở ngay đây, không chỉ dựa vào :disabled của nút: Enter và sự kiện dán đều
    // gọi thẳng hàm này mà không đi qua nút. Trong log hoạt động đã thấy khách dán/bấm lặp lại
    // cách nhau 2-3 giây, và mỗi lượt trượt cache của nguồn ganma là một job ~20 giây.
    // Chỉ chặn khi CÙNG link: dán link KHÁC trong lúc đang chờ là ý khách đã đổi, cho đi luôn
    // (router.post tự huỷ lượt cũ) — khoá cứng là khách đứng nhìn ô im lặng suốt 20-45 giây.
    // Nói cho khách biết vì sao không có gì xảy ra: nút "Đang tìm mã..." bị ẩn khi khung đã dính.
    if (resolving.value && url === resolvingUrl) {
        toast.info('Đang tìm mã cho link này rồi, đợi thêm chút nhé...')

        return
    }
    const seq = ++resolveSeq
    resolvingUrl = url
    resolving.value = true
    voucherError.value = null
    // Xoá link đã lấy của lần quét trước: nút kết quả dùng chung key 'result', còn các dòng
    // lịch sử đánh key theo chỉ số nên bị dịch đi khi có mục mới chèn lên đầu — không xoá là
    // khách bấm phải comment của sản phẩm khác.
    readyLinks.value = {}

    router.post('/voucher/resolve', { url }, {
        preserveScroll: true,
        onSuccess: () => {
            if (seq !== resolveSeq) return
            resolving.value = false
            // Ghi lại link vừa quét xong để khoá nút. Chỉ đặt ở onSuccess: quét lỗi thì phải cho
            // khách bấm thử lại, không được khoá.
            resolvedUrl.value = voucherUrl.value.trim()
            const result = props.voucherResult
            if (result?.voucher_ref) {
                history.value = [
                    {
                        product_name: result.product?.product_name || null,
                        product_image: result.product?.product_image || null,
                        created_at: new Date().toISOString(),
                        // Lưu token mờ để "mua lại" sau này chỉ cần bấm nút, không cần hiện đường
                        // dẫn thô cho khách. ref do server phát ra (xem VoucherRefService) và sống
                        // 7 ngày — hết hạn thì /voucher/shorten trả 422 và báo lỗi.
                        // Vẫn lưu cả khi công tắc historyRebuy đang tắt: admin bật lại giữa chừng
                        // thì những mục ghi trong lúc tắt phải bấm được ngay, không thì khách thấy
                        // nửa danh sách có nút nửa không mà chẳng hiểu vì sao.
                        ref: result.voucher_ref,
                        // Chế độ mã YTB: mua lại từ lịch sử cũng phải qua bước 1 như lần đầu.
                        ytb_activate_url: result.ytb_activate_url || null,
                    },
                    ...history.value,
                ].slice(0, 5)
            }
            // Cuộn tới kết quả ngay khi có — khách không cần tự kéo xuống. Phải gọi TRƯỚC nhánh
            // autoRedirect: nhánh đó return sớm, trước đây vì thế mà chế độ Facebook (đi qua
            // goStraightToVoucher) không bao giờ cuộn, khách tìm xong vẫn đứng ở đầu trang.
            scrollToResult()
            fetchCashbackEstimate(result)
            // Tìm ra mã thì lớp trang trí (nếu đang bật) cho trẻ con rước đèn đi ngang màn hình —
            // chỉ khi có mã thật, không ăn mừng lúc trả về "chưa lấy được mã".
            if (result?.voucher_ref) celebrate()
            // Có bước 1 (kích hoạt YouTube) mà không đi qua Facebook thì KHÔNG được tự chuyển
            // thẳng sang Shopee — khách chưa kích hoạt, sang tới nơi là không có mã YTB. Ở chế
            // độ Facebook thì vẫn lấy sẵn link reel, chỉ khoá nút "Mở Facebook" cho tới bước 1.
            if (props.autoRedirect && result?.voucher_ref && (props.viaFacebookComment || !result.ytb_activate_url)) {
                goStraightToVoucher()
            }
        },
        onError: (errors) => {
            if (seq !== resolveSeq) return
            // `url` là lỗi validate của Laravel (chuỗi dán vào không phải link), `voucher_url`
            // là lỗi nghiệp vụ controller trả về — cả hai đều đã viết cho khách đọc.
            voucherError.value = errors.voucher_url || errors.url || 'Có lỗi xảy ra, vui lòng thử lại.'
            toast.error(voucherError.value)
        },
        // onFinish chạy trong MỌI trường hợp, kể cả request đứt giữa đường (mạng yếu, khách
        // đang trong webview Facebook) — nơi onSuccess/onError đều không được gọi. Bỏ nút đi
        // rồi thì `resolving` còn khoá cả ô nhập, nên kẹt cờ này nghĩa là khách không sửa
        // được link mà cũng không thử lại được, phải tải lại trang.
        onFinish: () => {
            if (seq !== resolveSeq) return
            resolving.value = false
            resolvingUrl = null
        },
    })
}

// --- Hoàn tiền dự kiến của sản phẩm vừa quét ---
// Số tiền, không phải tỉ lệ: khách không quy đổi được "14% hoa hồng rồi chia lại 50%" thành tiền
// trong đầu lúc đang phân vân bấm mua. Hỏi SAU khi kết quả đã hiện (xem
// ShopeeVoucherController::commission) — nguồn hoa hồng là proxy bên thứ ba chậm và hay hỏng,
// nhét vào lượt quét là bắt mọi khách chờ thêm để đổi lấy một con số ước tính.
const cashbackEstimate = ref(null)

async function fetchCashbackEstimate(result) {
    cashbackEstimate.value = null

    if (!cashbackOn.value || !result?.item_id) return

    try {
        const { data } = await axios.get(`/voucher/hoa-hong/${result.item_id}`)
        // Server trả hoa hồng bằng tiền; phần của khách là cashbackRate% của khoản đó — đúng
        // công thức CashbackService::award() dùng khi ghi tiền thật, để con số hứa ở đây và con
        // số vào ví sau này không nói khác nhau.
        if (data?.commission > 0) {
            cashbackEstimate.value = Math.round(data.commission * cashbackRate.value / 100)
        }
    } catch (e) {
        // Im lặng: đây là thông tin thêm, không phải điều kiện để mua hàng.
    }
}

const shorteningKey = ref(null)
const autoRedirecting = ref(false)

/**
 * Trang được lấy lại từ bộ nhớ đệm quay-lui (bfcache) — chuyện thường ngày trên iPhone: khách
 * bấm bước 1 sang app Shopee, quay lại bằng nút Back là Safari trả nguyên trang cũ, không tải
 * lại. Request nào đang chạy lúc rời trang đã bị trình duyệt cắt mà không gọi callback nào,
 * nên cờ nào còn bật là ô nhập/nút mua bị khoá vĩnh viễn cho tới khi tải lại trang.
 */
function onPageShow(e) {
    if (!e.persisted) return
    resolveSeq++
    resolving.value = false
    resolvingUrl = null
    shorteningKey.value = null
    autoRedirecting.value = false
}

// Nhãn nút phải nói đúng nơi nó dẫn tới. "Mua ngay" mà mở ra Facebook là khách tưởng lỗi.
const ctaLabel = computed(() => {
    if (shorteningKey.value === 'result') return props.viaFacebookComment ? 'Đang lấy mã...' : 'Đang mở...'
    return props.viaFacebookComment ? 'Lấy mã qua Facebook' : 'Mua ngay (đã áp mã)'
})

// Link comment Facebook đã lấy xong, theo từng nút (key -> URL). Khi đã có, nút bấm được thay
// bằng THẺ <a> THẬT — đây là điểm mấu chốt để mở được app Facebook:
//  • iOS chỉ kích hoạt universal link khi khách CHẠM TRỰC TIẾP vào anchor. Điều hướng bằng JS
//    (window.open rồi gán location.href) luôn bị Safari giữ lại trong trình duyệt.
//  • Android cũng ưu tiên anchor thật, và anchor cho phép bọc intent:// (xem facebookAppLink).
// Đổi lại khách phải chạm 2 nhịp: chạm để lấy mã → chạm để sang Facebook. Không gộp được vì
// giữa hai nhịp có request đăng comment, xong request là mất "user gesture".
const readyLinks = ref({})

// --- Chế độ mã YTB: bước 1 kích hoạt trên máy khách ---
// Server trả `ytb_activate_url` (/ytb/{ref}) khi mã cần kích hoạt YouTube trước. Khách phải TỰ
// bấm mở link đó (Shopee ghi nhận mã trên chính máy khách — server mở hộ hay xâu vào trước link
// đích đều đã thử và không ra mã), quay lại rồi mới bấm Facebook/Mua ngay. Nhớ ref đã kích hoạt
// trong sessionStorage: bấm bước 1 là rời trang sang Shopee, quay lại có thể là tải lại trang.
const YTB_STORAGE_KEY = 'sv_ytb_activated'
const ytbActivatedRefs = ref(readYtbActivated())

function readYtbActivated() {
    try {
        return JSON.parse(sessionStorage.getItem(YTB_STORAGE_KEY) || '[]')
    } catch (e) {
        return []
    }
}

function isYtbActivated(voucherRef) {
    return ytbActivatedRefs.value.includes(voucherRef)
}

function markYtbActivated(voucherRef) {
    if (!voucherRef || isYtbActivated(voucherRef)) return
    ytbActivatedRefs.value = [...ytbActivatedRefs.value, voucherRef].slice(-20)
    try {
        sessionStorage.setItem(YTB_STORAGE_KEY, JSON.stringify(ytbActivatedRefs.value))
    } catch (e) {
        // Không lưu được thì chỉ mất trạng thái khi tải lại trang — khách bấm lại bước 1 là xong.
    }
}

// Bước 2 (nút kết quả) bị khoá cho tới khi khách bấm bước 1 — nếu không khách bấm thẳng bước 2
// như thói quen, sang Shopee không thấy mã YTB rồi tưởng trang hỏng.
const ytbBlocked = computed(() => !!props.voucherResult?.ytb_activate_url
    && !isYtbActivated(props.voucherResult?.voucher_ref))

function historyYtbBlocked(h) {
    return !!h.ytb_activate_url && !isYtbActivated(h.ref)
}

// Bấm bước 1 xong là khách rời sang Shopee — và đo trên production (14 ngày tới 16-09-2026)
// thì chỉ 13/25 lượt quay lại làm bước 2. Số còn lại nhiều khả năng mua luôn tại trang vừa
// mở: đơn đó KHÔNG có mã, mà link bước 1 là link kích hoạt chứ không phải link mua.
// Lời nhắc đặt ở hai chỗ: trước khi bấm (lúc còn đọc được) và ngay khi khách quay lại tab.
const ytbStep2Done = ref(false)
const ytbReturnNudge = ref(false)

function onTabVisible() {
    if (document.visibilityState !== 'visible') return
    // Chưa bấm bước 1, hoặc đã làm xong bước 2 rồi thì không nhắc — nhắc thừa chỉ làm phiền.
    if (!props.voucherResult?.ytb_activate_url || ytbBlocked.value || ytbStep2Done.value) return

    ytbReturnNudge.value = true
    scrollToResult()
}

onMounted(() => document.addEventListener('visibilitychange', onTabVisible))
onUnmounted(() => document.removeEventListener('visibilitychange', onTabVisible))

const ua = typeof navigator !== 'undefined' ? navigator.userAgent : ''
const isAndroid = /Android/i.test(ua)
// Đang ở trong webview của Facebook/Instagram/Zalo thì intent:// không mở được gì cả — giữ
// nguyên URL https để webview tự xử lý.
const isInAppBrowser = /FBAN|FBAV|FB_IAB|Instagram|Zalo|Line\//i.test(ua)

// Đăng comment có thể thất bại (token lỗi, Facebook sập...) — khi đó server trả thẳng
// /go/{code} về Shopee thay vì link comment. Nhãn nút phải đổi theo, nếu không khách bấm
// "Mở Facebook ngay" mà lại ra Shopee thì tưởng hỏng.
function isFacebookLink(url) {
    return typeof url === 'string' && url.startsWith('https://www.facebook.com/')
}

/**
 * Android: bọc URL Facebook thành intent:// trỏ thẳng vào package com.facebook.katana. Cần
 * bước này vì Chrome chỉ tự mở app với App Link đã verify qua assetlinks.json, mà facebook.com
 * không nằm trong nhóm đó — link https thường vì vậy luôn ở lại trình duyệt.
 * browser_fallback_url giữ nguyên đường web cho máy chưa cài app.
 *
 * Khác hẳn vụ intent:// gây 502 trước đây (xem ShortLinkController::redirect): lần đó nó nằm ở
 * Location header phía server nên bị proxy/CDN xử lý, còn ở đây nó chỉ là href trong trình
 * duyệt của khách, không đi qua hạ tầng nào.
 *
 * iOS không có cơ chế tương đương — fb:// không trỏ được tới đúng comment (đã test, xem
 * ShortLinkController::commentUrl) nên để nguyên https và trông cậy vào universal link.
 */
/**
 * Ghi nhận cú bấm "Mở Facebook ngay" — đây là điểm chuyển đổi thật của luồng lấy mã qua FB
 * (khách đã đi được tới bước cuối). Trang sẽ rời đi ngay sau cú bấm nên dùng fetch keepalive
 * để request vẫn hoàn tất; không await, không chặn điều hướng.
 */
function trackFacebookOpen(url, productName) {
    ytbStep2Done.value = true

    if (!isFacebookLink(url)) return

    try {
        fetch('/track/event', {
            method: 'POST',
            keepalive: true,
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({
                event_type: 'facebook_open',
                product_name: productName || null,
                platform: 'shopee',
                source: props.facebookMode === 'reel' ? 'fb_reel' : 'fb_comment',
                url,
            }),
        }).catch(() => {})
    } catch (e) {
        // Không có fetch/keepalive thì bỏ qua — thống kê không được phép làm hỏng nút.
    }
}

function facebookAppLink(url) {
    if (!url || !isAndroid || isInAppBrowser) return url
    if (!isFacebookLink(url)) return url

    return `intent://${url.slice('https://'.length)}#Intent;scheme=https;package=com.facebook.katana;S.browser_fallback_url=${encodeURIComponent(url)};end`
}

// Đổi voucher_ref (token mờ) lấy short-link thật. Dùng chung cho mọi đường: bấm mở, tự chuyển
// hướng, và copy link.
async function fetchVoucherUrl(entry, productName = null, productImage = null) {
    const { data } = await axios.post('/voucher/shorten', {
        ref: entry.ref,
        product_name: productName ?? props.voucherResult?.product?.product_name ?? null,
        product_image: productImage ?? props.voucherResult?.product?.product_image ?? null,
    })

    return data.short_url
}

/**
 * Chế độ tự chuyển hướng: khách dán link xong là đi thẳng tới đích, không bấm nút nào nữa.
 * Điều hướng ngay trên tab hiện tại thay vì mở tab mới — hàm này chạy sau khi request resolve
 * trả về nên đã mất "user gesture", window.open() lúc này chắc chắn bị trình duyệt chặn.
 */
async function goStraightToVoucher() {
    autoRedirecting.value = true

    try {
        const url = await fetchVoucherUrl({ ref: props.voucherResult.voucher_ref })

        // Chế độ Facebook: dừng ở đây và hiện anchor thay vì tự điều hướng — window.location
        // sang facebook.com chỉ mở trình duyệt, không bật được app (xem readyLinks).
        if (props.viaFacebookComment) {
            readyLinks.value.result = url
            autoRedirecting.value = false
            // Ô "đang mở" vừa được thay bằng hướng dẫn 2 bước + nút, cao hơn hẳn — cuộn lại
            // để nút Mở Facebook chắc chắn nằm trong màn hình.
            scrollToResult()

            return
        }

        window.location.href = url
    } catch (e) {
        autoRedirecting.value = false
        toast.error('Không thể tạo link, vui lòng thử lại.')
    }
}

// key chỉ để biết nút nào đang quay (nút kết quả hay một dòng trong lịch sử) — mỗi lần
// chỉ cho bấm một nút, vì cả hai đều dẫn tới cùng một hành động điều hướng.
async function openVoucherLink(entry, productName = null, productImage = null) {
    if (!entry?.ref || shorteningKey.value) return

    ytbStep2Done.value = true
    shorteningKey.value = entry.key

    // Chế độ Facebook: chỉ lấy link rồi hiện anchor, tuyệt đối không window.open/location.href
    // — điều hướng bằng JS là lý do app Facebook không bao giờ được bật lên (xem readyLinks).
    if (props.viaFacebookComment) {
        try {
            readyLinks.value[entry.key] = await fetchVoucherUrl(entry, productName, productImage)
        } catch (e) {
            toast.error('Không thể tạo link, vui lòng thử lại.')
        } finally {
            shorteningKey.value = null
        }

        return
    }

    // Mở tab trắng NGAY trong lúc click (đồng bộ) để trình duyệt không chặn popup —
    // nếu đợi axios xong mới gọi window.open() thì đã mất "user gesture", dễ bị chặn.
    const newTab = window.open('', '_blank')
    // Ghi tạm 1 trang loading vào tab đó — bước tạo link có thể mất vài giây (theo dõi
    // redirect chuỗi + có thể đăng comment Facebook), tab trắng trơn trong lúc chờ dễ khiến
    // khách tưởng bị treo rồi đóng tab/bấm lại.
    newTab?.document.write('<!DOCTYPE html><html lang="vi"><head><meta charset="utf-8"><title>Đang tạo liên kết...</title><style>body{display:flex;align-items:center;justify-content:center;height:100vh;margin:0;font-family:system-ui,sans-serif;color:#666;background:#fafafa}</style></head><body>Đang tạo liên kết, vui lòng đợi giây lát...</body></html>')

    try {
        const url = await fetchVoucherUrl(entry, productName, productImage)
        if (newTab) {
            newTab.location.href = url
        } else {
            // Popup bị chặn — điều hướng ngay tab hiện tại thay vì bỏ cuộc.
            window.location.href = url
        }
    } catch (e) {
        newTab?.close()
        toast.error('Không thể tạo link, vui lòng thử lại.')
    } finally {
        shorteningKey.value = null
    }
}

const copyingKey = ref(null)

// Lấy short-link /go/{code} (cùng link được dùng khi bấm mở) rồi copy vào clipboard —
// để người dùng dán thẳng lên Facebook/Zalo mà không cần tự mở link ra rồi copy từ URL bar.
async function copyVoucherLink(entry) {
    if (!entry?.ref || copyingKey.value) return

    copyingKey.value = entry.key
    try {
        await navigator.clipboard.writeText(await fetchVoucherUrl(entry))
        toast.success('Đã sao chép link!')
    } catch (e) {
        toast.error('Không thể sao chép link, vui lòng thử lại.')
    } finally {
        copyingKey.value = null
    }
}

function vnd(n) {
    return '₫' + Number(n || 0).toLocaleString('vi-VN')
}

// Phần trăm giảm của sản phẩm vừa quét — null khi KHÔNG đủ dữ liệu để nói chắc.
// Lấy THẲNG ô `discount_percent` mà server đã trả, KHÔNG tự lấy giá trừ lại ở đây: cả ba
// nguồn sản phẩm đều trả sẵn ô này cùng một khuôn (ShopeeLinkResolverService dùng chính
// `raw_discount` của Shopee, KieuShopeeService tự tính khi nguồn không có, GanmaService trả 0
// vì không đọc được giá gốc). Tự trừ lại ở frontend là mở đường cho một con số KHÁC con số
// khách nhìn thấy trên chính trang Shopee — với người vốn đã đa nghi về "web hoàn tiền" thì
// một cái badge vênh 3% là đủ để họ thoát. 0 nghĩa là không hiện badge: thà không nói còn hơn
// dán nhãn đỏ "-0%" lên thẻ đang mời khách bấm mua.
const discountPercent = computed(() => {
    const percent = Math.round(Number(props.voucherResult?.product?.discount_percent || 0))

    return percent >= 1 ? percent : null
})

// --- Mã gợi ý ---
const platformTabs = [
    { key: 'all', label: 'Tất cả' },
    { key: 'shopee', label: 'Shopee' },
    { key: 'lazada', label: 'Lazada' },
    { key: 'tiki', label: 'Tiki' },
    { key: 'tiktok', label: 'TikTok' },
]
const activePlatform = ref('all')

const filteredVouchers = computed(() => {
    if (activePlatform.value === 'all') return props.vouchers
    return props.vouchers.filter(v => v.platform === activePlatform.value || v.platform === 'all')
})

// Hướng dẫn chính thức của trang, hiện thành thanh bước ngang ngay dưới ô dán link
// (Components/HowItWorksSteps.vue). Khi hoàn tiền đang bật thì bước ĐĂNG NHẬP phải nằm ngay
// trong đây: sub_id chỉ được gắn tại đúng giây khách bấm nút mua, nên khách làm đủ ba bước cũ
// một cách hoàn hảo vẫn nhận 0đ. Một bản hướng dẫn dẫn tới 0đ là lỗi nặng hơn cả việc không có
// hướng dẫn.
//
// `label` là chữ hiện dưới icon nên phải NGẮN — bốn tới năm bước đứng cùng một hàng trên màn
// hình 375px; `desc` là bản đầy đủ, đi vào thuộc tính title của từng bước.
const steps = computed(() => [
    ...(cashbackOn.value ? [
        { icon: '🔑', label: 'Đăng nhập', desc: 'Bắt buộc nếu bạn muốn được hoàn tiền — đơn chỉ ghi nhận được về tài khoản đang đăng nhập tại lúc bấm mua. Chỉ cần làm một lần.' },
    ] : []),
    { icon: '📎', label: 'Dán link', desc: 'Copy link sản phẩm từ app hoặc web Shopee, dán vào ô ở đầu trang.' },
    { icon: '🔍', label: 'Tự quét mã', desc: 'Không cần bấm nút — hệ thống tự tìm mã Facebook, YouTube, Instagram còn hiệu lực ngay khi bạn dán link.' },
    { icon: '🛍️', label: 'Bấm mua', desc: 'Bấm nút mua ở khối kết quả — link đã áp sẵn mã giảm giá sẽ tự mở ra, không cần nhập mã.' },
])

// Tiêu đề phải nói đúng thứ khách nhận được: chưa bật hoàn tiền thì trang chỉ tìm mã giảm giá,
// hứa "nhận hoàn tiền" lúc đó là hứa suông.
const stepsTitle = computed(() => cashbackOn.value ? 'Các bước nhận hoàn tiền' : 'Các bước lấy mã giảm giá')

// FAQ phải theo trạng thái chương trình hoàn tiền: khi admin chưa bật (tỉ lệ = 0) thì hệ thống
// thật sự không trả đồng nào, nên câu trả lời cũ mới là câu đúng. Bật lên rồi mà vẫn để nguyên
// câu "không tạo hoàn tiền" thì chính trang của mình đang phủ nhận chức năng của mình.
const faqs = computed(() => [
    { q: 'Công cụ này hoạt động như thế nào?', a: 'Bạn dán link sản phẩm Shopee vào ô ở đầu trang — hệ thống tự tìm mã giảm giá đang áp dụng cho sản phẩm đó và trả về một link đã gắn sẵn mã, không phải nhập mã thủ công.' },
    { q: 'Vì sao bấm nút lại mở ra Facebook?', a: 'Vì đây là mã dành riêng cho người mua đến từ Facebook — Shopee chỉ áp mã khi bạn bấm vào link nằm trên Facebook. Quy trình là: bấm "Lấy mã qua Facebook" → bấm tiếp "Mở Facebook ngay" (ứng dụng Facebook sẽ mở ra) → bấm link hiện ở đó → về Shopee với mã đã được áp sẵn. Bỏ qua bước này thì mã sẽ không có hiệu lực.' },
    { q: 'Mấy giờ thì có mã mới (back mã)?', a: 'Mã YouTube được nạp lại lượt vào 0h, 9h, 12h và 18h. Mã IG – FB nạp lại vào 0h, 9h, 15h và 20h (giờ Việt Nam). Mã có số lượng giới hạn nên thường hết rất nhanh — nếu Shopee báo hết lượt, bạn quay lại đúng khung giờ trên để lấy mã mới.' },
    cashbackOn.value
        ? { q: 'Tôi có được hoàn tiền không?', a: `Có. Ngoài mã giảm giá được áp ngay lúc thanh toán, bạn còn được hoàn thêm ${cashbackRate.value}% khoản hoa hồng tiếp thị mà Shopee trả cho tụi mình vì đơn của bạn. Điều kiện bắt buộc: phải đăng nhập TRƯỚC khi bấm nút mua ở đầu trang — bấm lúc chưa đăng nhập thì đơn đó không quy về tài khoản nào được và sau này không cứu lại được. Tiền vào ví sau khi đơn chuyển sang trạng thái Hoàn thành trên Shopee và được đối soát.` }
        : { q: 'Tôi có được hoàn tiền không?', a: 'Công cụ lấy mã giảm giá không tạo hoàn tiền — mục đích là giúp bạn được giảm giá ngay khi thanh toán trên Shopee.' },
    ...(cashbackOn.value ? [
        { q: 'Sao tôi mua rồi mà ví vẫn 0đ?', a: 'Bốn khả năng, xếp theo thứ tự hay gặp nhất. (1) Lúc bấm mua bạn chưa đăng nhập — đơn đó không gắn được mã định danh của bạn, trường hợp này tiếc thật nhưng không cứu được. (2) Đơn chưa "Hoàn thành" trên Shopee — còn đang giao hoặc còn trong hạn đổi trả thì chưa tính. (3) Đơn đã hoàn thành nhưng chưa tới kỳ đối soát — tụi mình nhập báo cáo Shopee theo đợt, không phải tức thì. (4) Hiếm hơn: link lúc đó không gắn được mã định danh của bạn do nguồn cấp mã đổi đường dẫn hoặc chuỗi chuyển hướng bị gãy — nhắn cho tụi mình kèm ngày đặt và mã đơn Shopee để đối chiếu. Lưu ý: trang Tài khoản chỉ hiện số dư đã đối soát xong, chưa hiện đơn đang chờ — nên mua xong vài ngày mà chưa thấy gì ở đó là bình thường, không phải mất.' },
        { q: 'Tiền hoàn tính trên cái gì?', a: `Tính trên hoa hồng tiếp thị Shopee trả cho tụi mình vì đơn của bạn, không phải trên giá trị đơn hàng. Mức hoàn hiện tại là ${cashbackRate.value}% khoản hoa hồng đó. Hoa hồng mỗi ngành hàng mỗi khác nên số tiền hoàn của mỗi đơn cũng khác nhau. Sau khi dán link, tụi mình hiện luôn số tiền hoàn DỰ KIẾN của đúng sản phẩm đó ngay trên thẻ kết quả — đó là ước tính theo tỉ lệ hoa hồng Shopee đang công bố cho sản phẩm, con số cuối cùng chốt theo báo cáo đối soát nên có thể xê dịch. Mức hoàn này có thể được điều chỉnh; khi đổi thì các khoản chưa chi trả sẽ được tính lại theo mức mới.` },
    ] : []),
    { q: 'Có mất phí không?', a: 'Hoàn toàn miễn phí, bạn không mất phí gì khi dùng công cụ lấy mã.' },
    { q: 'Hỗ trợ những sàn nào?', a: 'Ô dán link ở đầu trang hiện chỉ hỗ trợ Shopee. Riêng mục "Mã giảm giá gợi ý" bên dưới có thêm mã cho Lazada, TikTok Shop và Tiki.' },
])
const openFaq = ref(null)

// Khung dán link dính lên đầu trang khi cuộn (sticky) để khách lúc nào cũng dán được link.
// Lúc đã dính thì thu gọn tiêu đề lại, nếu không khung chiếm gần hết màn hình điện thoại.
// Đo bằng vị trí thật của khung chứ không bằng scrollY: chiều cao phần trên khung đổi theo
// trạng thái (banner, dải nhắc, thanh chữ chạy), lấy mốc scrollY cố định sẽ thu gọn sai nhịp
// và làm nội dung giật một cái.
const HEADER_HEIGHT = 64 // AppLayout: header sticky h-16
const stickyEl = ref(null)
const stuck = ref(false)
let scrollTicking = false

function measureStuck() {
    scrollTicking = false
    const top = stickyEl.value?.getBoundingClientRect().top
    stuck.value = top !== undefined && top <= HEADER_HEIGHT + 0.5
}

function onScroll() {
    if (scrollTicking) return
    scrollTicking = true
    requestAnimationFrame(measureStuck)
}

onMounted(() => {
    window.addEventListener('scroll', onScroll, { passive: true })
    window.addEventListener('resize', onScroll, { passive: true })
    window.addEventListener('pageshow', onPageShow)
    measureStuck()
})
onUnmounted(() => {
    window.removeEventListener('scroll', onScroll)
    window.removeEventListener('resize', onScroll)
    window.removeEventListener('pageshow', onPageShow)
})
</script>

<template>
    <!-- Tiêu đề/mô tả đổi theo trạng thái chương trình hoàn tiền: khi admin chưa bật (tỉ lệ = 0)
         thì hệ thống không trả đồng nào, quảng cáo hoàn tiền trên kết quả tìm kiếm lúc đó là
         kéo khách vào để thất vọng. -->
    <Head>
        <title>{{ cashbackOn ? 'Mã Giảm Giá Shopee + Hoàn Tiền Về Ví | tietkiemvi.com' : 'Tìm Voucher Shopee Facebook YouTube Instagram | tietkiemvi.com' }}</title>
        <meta
            name="description"
            :content="cashbackOn
                ? `Dán link Shopee → nhận mã giảm giá áp sẵn, và được chia lại ${cashbackRate}% hoa hồng của đơn vào số dư trên web, rút về MoMo/ZaloPay khi đủ mức tối thiểu. Đăng nhập trước khi bấm mua thì đơn mới được ghi nhận.`
                : 'Dán link sản phẩm Shopee → nhận link voucher độc quyền Facebook, YouTube, Instagram. Xem giá sau giảm ngay.'"
        />
    </Head>
    <AppLayout>
        <!-- Công cụ chính: hiện ngay khi vào trang, không cần mô tả dài trước đó -->
        <section id="voucher-tool" class="px-4 pt-6 pb-4">
            <div class="max-w-3xl mx-auto">
                <!-- Banner "khung giờ back mã" đã CHUYỂN XUỐNG dưới khối hướng dẫn.
                     Kể cả sau khi chính nó đã được rút gọn thành thẻ thường (xem đầu
                     RestockSchedule.vue), nó vẫn cao ~170px — đặt trên ô dán link là đẩy chính
                     thứ duy nhất khách vào đây để làm ra khỏi màn hình đầu của iPhone SE
                     (375x667). Giờ giấc back mã là câu hỏi THỨ HAI (hỏi khi quét không ra mã),
                     nên nó đứng ở vị trí thứ hai. -->

                <!-- top-16 = chiều cao header sticky của AppLayout (h-16). Nền đặc chỉ bật khi
                     đã dính, để lúc chưa cuộn khung vẫn phẳng với nền trang. Cố tình KHÔNG dùng
                     backdrop-blur ở đây: khung này cao/rộng hơn hẳn header, nên nếu blur thì
                     trình duyệt phải làm mờ lại toàn bộ nội dung phía sau mỗi khung hình lúc
                     cuộn — chính là nguyên nhân bị khựng khi cuộn qua khu vực này trên máy yếu.
                     Nền đặc (không alpha) rẻ hơn nhiều mà vẫn che kín nội dung phía dưới.
                     -mx-4 px-4 kéo nền ra sát mép để nội dung cuộn phía dưới không lòi ra hai bên. -->
                <div
                    ref="stickyEl"
                    class="sticky top-16 z-30 -mx-4 px-4 pt-2 pb-3 transition-shadow duration-200 [contain:layout_paint]"
                    :class="stuck ? 'bg-[var(--color-bg)] shadow-[0_10px_24px_rgba(0,0,0,.12)]' : ''"
                >
                    <!-- rounded-2xl = --radius-card của hệ. rounded-3xl (24px) ở đây là bo góc
                         duy nhất trong trang không khớp với thẻ nào khác, nhìn ra ngay khi nó
                         nằm sát khối kết quả bo 16px. -->
                    <div v-if="canUseVoucherTool" class="rounded-2xl bg-gradient-to-br from-[var(--color-peach)] via-[var(--color-peach-soft)] to-[var(--color-green-soft)] border border-[var(--color-line)] transition-all duration-200" :class="stuck ? 'p-3' : 'p-5 md:p-8'">
                        <!-- Giữ h1 trong DOM (chỉ thu chiều cao) để không mất thẻ h1 của trang.
                             Thu gọn bằng grid-template-rows (1fr -> 0fr) chứ không dùng max-height:
                             max-height phải đoán một giá trị lớn hơn chiều cao thật (vd max-h-40 =
                             160px trong khi nội dung chỉ ~70px), nên phần lớn thời gian transition
                             chiều cao hiển thị không đổi (vẫn bị nội dung ghim ở 70px), rồi mới đột
                             ngột sụp xuống 0 ở cuối — nhìn giống bị khựng/giật thay vì thu gọn mượt.
                             Grid-rows nội suy đúng theo tỉ lệ thật nên mượt bất kể chiều cao nội dung. -->
                        <div
                            class="grid transition-[grid-template-rows,opacity,margin-bottom] duration-200 ease-out"
                            :class="stuck ? 'grid-rows-[0fr] opacity-0 mb-0' : 'grid-rows-[1fr] opacity-100 mb-4'"
                        >
                            <!-- Tiêu đề + mô tả rút ngắn để cả H1 + ô dán + nút nằm gọn trong
                                 màn hình đầu của iPhone SE (375x667). Bản cũ tràn 2 dòng tiêu đề
                                 + 2 dòng mô tả, riêng phần chữ đã ăn ~100px mà không giúp khách
                                 làm được gì thêm — họ đã biết mình vào đây để dán link. -->
                            <div class="overflow-hidden min-h-0">
                                <h1 class="text-xl md:text-2xl font-extrabold text-[var(--color-ink)] mb-1">
                                    Dán link Shopee — lấy mã giảm giá
                                </h1>
                                <p class="text-sm text-[var(--color-muted)]">Miễn phí, không cần nhập mã.</p>
                            </div>
                        </div>

                        <div class="flex flex-col md:flex-row gap-3">
                            <div class="relative flex-1">
                                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-[var(--color-muted)]">🔗</span>
                                <input
                                    ref="voucherUrlInput"
                                    v-model="voucherUrl"
                                    type="url"
                                    enterkeyhint="search"
                                    @keydown.enter="resolveVoucher"
                                    @paste="onVoucherUrlPaste"
                                    @input="onVoucherUrlInput"
                                    placeholder="Dán link Shopee (shopee.vn hoặc s.shopee.vn)..."
                                    class="focus-ring w-full pl-10 pr-24 border border-[var(--color-line)] rounded-xl text-sm bg-[var(--color-surface)] focus:border-[var(--color-accent)] transition-all duration-200"
                                    :class="stuck ? 'min-h-[48px]' : 'min-h-[56px]'"
                                />
                                <!-- Chiều cao đặt bằng min-h chứ không py-*, và nút "Dán" cao 44px
                                     — nó là nút được bấm nhiều nhất trang (khách vừa copy link từ
                                     app Shopee xong) mà trước đây chỉ cao 30px. -->
                                <button
                                    @click="pasteVoucherUrl"
                                    type="button"
                                    class="focus-ring absolute right-1.5 top-1/2 -translate-y-1/2 min-h-[44px] text-sm font-bold text-[var(--color-accent-deep)] bg-[var(--color-peach-soft)] hover:bg-[var(--color-peach)] border border-[var(--color-accent)]/30 rounded-lg px-3 transition"
                                >Dán</button>
                            </div>
                            <!-- Dán link là tự quét luôn, nên nút này gần như không còn việc gì:
                                 khoá lại khi ĐANG quét và cả khi link trong ô ĐÃ quét xong, để khách
                                 không bấm thêm một lượt vô ích (mỗi lượt trượt cache là một vòng gọi
                                 nguồn mã, riêng ganma mất ~20 giây). Sửa lại link thì nút tự mở.
                                 Khi khung đã dính mà link đã tìm xong thì ẩn hẳn: nút disabled
                                 chiếm ~70px của màn hình điện thoại vốn đã chật, che mất tên
                                 sản phẩm phía dưới. Cuộn lên đầu hoặc sửa link là nút hiện lại. -->
                            <button
                                v-show="!(stuck && alreadyResolved)"
                                @click="resolveVoucher"
                                :disabled="resolving || !voucherUrl.trim() || alreadyResolved"
                                class="btn-fire rounded-xl flex items-center justify-center gap-2 whitespace-nowrap transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed"
                                :class="stuck ? 'px-6 min-h-[44px]' : 'px-8 min-h-[52px]'"
                            >
                                <svg v-if="resolving" class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" stroke-dasharray="30 70" />
                                </svg>
                                <span v-else>{{ alreadyResolved ? '✓' : '🔍' }}</span>
                                {{ resolving ? 'Đang tìm mã...' : (alreadyResolved ? 'Đã tìm xong' : 'Tìm mã ngay') }}
                            </button>
                        </div>
                        <!-- text-red-500 không có bản tối đi kèm mà tối là chế độ mặc định —
                             dùng token danger (đã kiểm tương phản ở cả hai chế độ). -->
                        <p v-if="voucherError" class="text-sm mt-2 font-semibold text-[var(--color-danger)]">{{ voucherError }}</p>
                    </div>

                    <!-- Máy tính (không phải admin): ẩn khung tìm mã, chỉ hiện thông báo dùng điện thoại.
                         PHẢI đứng liền ngay sau khối v-if bên trên — chen bất cứ thẻ nào vào giữa
                         là đứt cặp v-if/v-else và thông báo này nhảy ra trên cả điện thoại. -->
                    <div v-else class="rounded-2xl p-5 md:p-8 bg-gradient-to-br from-[var(--color-peach)] via-[var(--color-peach-soft)] to-[var(--color-green-soft)] border border-[var(--color-line)] text-center">
                        <p class="text-2xl mb-2">📱</p>
                        <p class="font-semibold text-[var(--color-ink)]">Chức năng lấy mã chỉ dùng được trên điện thoại.</p>
                        <p class="text-sm text-[var(--color-muted)] mt-1">Shopee chỉ áp mã khi bấm từ điện thoại. Vui lòng mở tietkiemvi.com bằng trình duyệt trên điện thoại để dán link nhé.</p>
                        <div class="flex items-center justify-center gap-3 mt-4">
                            <Link href="/ma-giam-gia" class="focus-ring inline-flex items-center min-h-[44px] px-4 rounded-xl text-sm font-semibold bg-[var(--color-surface)] border border-[var(--color-line)] text-[var(--color-ink)] hover:border-[var(--color-accent)] transition no-underline">Xem mã giảm giá</Link>
                            <Link href="/flashsale" class="focus-ring inline-flex items-center min-h-[44px] px-4 rounded-xl text-sm font-semibold bg-[var(--color-surface)] border border-[var(--color-line)] text-[var(--color-ink)] hover:border-[var(--color-accent)] transition no-underline">Xem Flash Sale</Link>
                        </div>
                    </div>

                    <!-- Dải xác nhận cho khách ĐÃ đăng nhập, dính theo ô dán link. Bắt buộc gói
                         gọn MỘT DÒNG (text-xs + truncate): vùng này đã chiếm chỗ của ô nhập
                         trên màn hình điện thoại, dài thêm một dòng nữa là đẩy chính công cụ ra
                         khỏi tầm nhìn.

                         Khách VÃNG LAI không có dải nào ở đây nữa: dải vàng "Đăng nhập trước, đơn
                         này mới được hoàn tiền" từng nằm ngay dưới ô dán link làm khách tưởng
                         PHẢI đăng nhập mới lấy được mã — trong khi lấy mã là miễn phí, không cần
                         tài khoản. Lời mời đăng nhập giờ chỉ còn ở khối trước nút mua (khi có
                         kết quả) và phần cuối trang. -->
                    <template v-if="canUseVoucherTool">
                        <!-- Câu ĐIỀU KIỆN, không phải cam kết: "mua từ link này thì mới được tính"
                             nói đúng thứ khách cần biết (đi đường khác là mất) mà không hứa thay
                             cho những khâu phía sau vốn có thể hỏng — rewriteToOwnAffiliate() gặp
                             lỗi thì trả nguyên link của nguồn, và mã khách chỉ gắn được khi link
                             đích đã có sẵn tham số utm_content. -->
                        <div
                            v-if="showLoggedInCashbackBadge"
                            title="Đơn được tính hoàn tiền sau khi Shopee chốt ở trạng thái Hoàn thành và tụi mình đối soát báo cáo."
                            class="mt-2 flex items-center gap-2 rounded-xl bg-[var(--color-money-soft)] border border-[var(--color-money)]/25 px-3 py-2"
                        >
                            <!-- Chữ dùng --color-money chứ không --color-brand-green: brand-green
                                 chỉ đạt 3.41:1 ở chế độ sáng, mà đây là chữ nhỏ. Và 11px thì phần
                                 dấu tiếng Việt (ấ, ợ, ề) bị bóp nghẹt — sàn cứng là 12px. -->
                            <span class="text-xs leading-tight truncate text-[var(--color-money)] font-semibold">
                                ✓ Đang đăng nhập — mua từ link này thì đơn mới được tính hoàn tiền
                            </span>
                        </div>
                    </template>
                </div>

                <!-- Kết quả — màn hình đắt nhất của cả sản phẩm. Đọc từ trên xuống theo đúng ba
                     câu hỏi của khách: MUA GÌ (tầng sản phẩm) → ĐƯỢC LỢI BAO NHIÊU (dải tiền) →
                     BẤM VÀO ĐÂU (một nút cam duy nhất). Trước đây cả ba tầng bị nén chung vào
                     một cột chữ nhỏ bên phải cái ảnh 64px, nên câu thứ hai — lý do duy nhất
                     khiến khách ở lại — nằm ở cỡ chữ 11px dưới cùng. -->
                <div v-if="canUseVoucherTool && voucherResult" ref="voucherResultEl" class="mt-4 card p-4">
                    <!-- Tầng 1: MUA GÌ -->
                    <div v-if="voucherResult.product" class="flex gap-3 items-start">
                        <div class="w-16 h-16 rounded-xl bg-[var(--color-peach-soft)] flex-none overflow-hidden">
                            <img v-if="voucherResult.product.product_image" :src="voucherResult.product.product_image" :alt="voucherResult.product.product_name" class="w-full h-full object-cover" />
                            <div v-else class="w-full h-full flex items-center justify-center text-2xl">🛍️</div>
                        </div>
                        <div class="flex-1 min-w-0">
                            <!-- Nhãn sàn bằng chữ thường màu phụ, không còn ô vuông cam #F5511E:
                                 ô đó là một đốm cam nữa ngay trên cái nút cam, và nó viết thẳng mã
                                 màu nên ở chế độ tối vẫn là cam chế độ sáng. -->
                            <p class="text-xs font-semibold text-[var(--color-muted)]">Shopee</p>
                            <p class="font-semibold text-[var(--color-ink)] text-sm line-clamp-2">{{ voucherResult.product.product_name || 'Sản phẩm' }}</p>

                            <!-- Hàng giá: giá phải trả đứng trước và đậm nhất. Badge % CHỈ hiện
                                 khi server nói có giảm (discountPercent) — nguồn ganma không đọc
                                 được giá gốc nên trả 0, dán "-0%" lên đó là một nhãn đỏ nói với
                                 khách rằng họ chẳng được giảm gì.
                                 Giá gốc gạch ngang có điều kiện RIÊNG, không đi chung với badge:
                                 kieushopee có thể trả sẵn raw_discount mà KHÔNG trả giá gốc (khi
                                 đó original_price = discounted_price), in ra là hai con số y hệt
                                 nhau nằm cạnh nhau, một cái bị gạch — nhìn như lỗi hiển thị.
                                 Cả hàng giá cũng ẩn khi không đọc được giá: "₫0" dưới tên sản
                                 phẩm là một con số SAI, tệ hơn hẳn việc không có con số nào. -->
                            <div v-if="Number(voucherResult.product.discounted_price) > 0" class="mt-1 flex items-baseline flex-wrap gap-x-2 gap-y-1">
                                <span class="num text-lg font-extrabold text-[var(--color-ink)]">{{ vnd(voucherResult.product.discounted_price) }}</span>
                                <span
                                    v-if="Number(voucherResult.product.original_price) > Number(voucherResult.product.discounted_price)"
                                    class="num text-xs text-[var(--color-muted)] line-through"
                                >{{ vnd(voucherResult.product.original_price) }}</span>
                                <span v-if="discountPercent" class="num text-xs font-bold px-1.5 py-0.5 rounded-md text-[var(--color-danger)] bg-[var(--color-danger-soft)]">-{{ discountPercent }}%</span>
                            </div>
                        </div>
                    </div>
                    <p v-else class="text-sm text-[var(--color-muted)]">Không lấy được thông tin sản phẩm, nhưng bạn vẫn có thể dùng link bên dưới.</p>

                    <!-- Tầng 2: ĐƯỢC LỢI BAO NHIÊU. Một dải duy nhất, màu tiền, số to — đây là
                         thứ trang này có mà chỗ khác không có, không thể là dòng chữ nhỏ nhất thẻ.
                         Ước tính, KHÔNG phải cam kết: hoa hồng thật của đơn chỉ chốt sau khi Shopee
                         đối soát, và shop có thể đổi tỉ lệ giữa chừng. Chữ ở đây phải nói đúng
                         chừng đó — FAQ bên dưới cũng nói cùng một điều. -->
                    <div v-if="cashbackEstimate" class="panel bg-[var(--color-money-soft)] mt-3 px-3 py-3">
                        <div class="flex items-baseline justify-between gap-3">
                            <span class="text-sm font-semibold text-[var(--color-ink)]">💸 Hoàn tiền dự kiến</span>
                            <span class="num text-lg font-extrabold text-[var(--color-money)] whitespace-nowrap">~{{ vnd(cashbackEstimate) }}</span>
                        </div>
                        <!-- Chữ ink chứ không muted: nền ở đây là --color-money-soft (#E7F5EE ở
                             chế độ sáng) chứ không phải nền thẻ, và muted trên nền đó chỉ còn
                             4.27:1 — trượt AA cho chữ 12px. Vẫn đọc ra là dòng phụ nhờ cỡ chữ và
                             độ đậm, mà đây lại đúng là câu giữ chữ tín (nói trước tiền vào chậm). -->
                        <p class="text-xs text-[var(--color-ink)] mt-0.5">Vào ví sau khi đơn hoàn thành và được đối soát.</p>
                    </div>

                    <!-- Chế độ tự chuyển hướng: khách không bấm gì cả, chỉ báo đang đi. -->
                    <div v-if="autoRedirecting" class="flex items-center gap-3 mb-4 mt-3 px-4 py-3 rounded-xl bg-[var(--color-peach-soft)] text-[var(--color-ink)] text-sm font-semibold">
                        <span class="w-4 h-4 rounded-full border-2 border-[var(--color-accent)] border-t-transparent animate-spin flex-none"></span>
                        <span v-if="viaFacebookComment">Đang mở Facebook... Bấm vào <b>{{ linkLocation }}</b> để nhận mã nhé.</span>
                        <span v-else>Đang chuyển tới mã giảm giá, vui lòng đợi giây lát...</span>
                    </div>

                    <!-- Chế độ mã YTB, bước 1: khách tự mở link YouTube để Shopee ghi nhận mã trên
                         máy mình, rồi quay lại làm bước 2. Thẻ <a> thật, cùng tab, KHÔNG target=_blank
                         — cùng lý do với nút Facebook: tab mới làm hỏng universal link mở app.
                         Nút bước 2 bên dưới khoá cho tới khi bấm ở đây (ytbBlocked). -->
                    <!-- Màu của khối này chuyển từ đỏ YouTube viết thẳng (#FF0000/#c00000) sang
                         token danger: đỏ cứng đó sinh ra cho nền trắng, ở chế độ tối (mặc định
                         của trang) chữ #c00000 gần như chìm vào nền navy. Vai trò ngữ nghĩa vẫn
                         đúng: đây là chỗ khách dễ làm hỏng đơn của chính mình nhất. -->
                    <div
                        v-if="!autoRedirecting && voucherResult.voucher_ref && voucherResult.ytb_activate_url"
                        class="mb-3 mt-3 rounded-xl border border-[rgba(var(--color-danger-rgb),.3)] bg-[rgba(var(--color-danger-rgb),.06)] px-4 py-3"
                    >
                        <p class="text-sm font-bold text-[var(--color-ink)] mb-2">Mã này cần kích hoạt YouTube trước — làm theo thứ tự:</p>
                        <ol class="text-xs text-[var(--color-ink)] leading-relaxed space-y-1 list-decimal list-inside mb-2">
                            <li>Bấm <b>Kích hoạt mã YouTube</b> → Shopee mở ra. <b>Tuyệt đối không đặt hàng ở đó</b>, chỉ cần <b>quay lại đây</b>.</li>
                            <li>Bấm tiếp nút <b>{{ viaFacebookComment ? 'Lấy mã qua Facebook' : 'Mua ngay' }}</b> bên dưới như bình thường.</li>
                        </ol>

                        <!-- Đây là chỗ duy nhất lời nhắc còn kịp: bấm xong là khách sang Shopee,
                             nhìn thấy đúng sản phẩm mình định mua và rất dễ đặt hàng luôn. -->
                        <div class="mb-3 rounded-lg bg-[var(--color-danger-soft)] border border-[rgba(var(--color-danger-rgb),.3)] px-3 py-2.5">
                            <p class="text-sm font-bold text-[var(--color-danger)] leading-relaxed">
                                ⛔ Đừng đặt hàng ngay ở bước 1
                            </p>
                            <p class="text-xs text-[var(--color-ink)] leading-relaxed mt-1">
                                Shopee sẽ mở ra với mã đã nhận, nhưng <b>đừng bấm mua ở trang đó</b> —
                                đặt hàng ngay tại bước này dễ khiến tài khoản bị Shopee đánh dấu <b>F02</b>.
                                Hãy quay lại đây làm tiếp <b>bước 2</b> rồi mới đặt hàng.
                            </p>
                        </div>

                        <!-- Chữ TRẮNG trên nền #FF0000 chỉ được 4.0:1 — trượt AA, mà dòng phụ
                             còn để white/80 nên thực tế còn thấp hơn. Đổi sang nền danger-soft +
                             chữ danger: đạt chuẩn ở cả hai chế độ, và không thành nút đặc thứ hai
                             tranh chỗ với nút cam bên dưới. Giữ viền nhấp nháy để mắt vẫn tìm ra
                             đây là việc phải làm trước. -->
                        <a
                            v-if="ytbBlocked"
                            :href="voucherResult.ytb_activate_url"
                            @click="markYtbActivated(voucherResult.voucher_ref)"
                            class="focus-ring w-full min-h-[52px] px-4 py-2 rounded-xl flex flex-col items-center justify-center bg-[var(--color-danger-soft)] border-2 border-[rgba(var(--color-danger-rgb),.45)] text-[var(--color-danger)] transition no-underline animate-pulse-ring"
                        >
                            <span class="flex items-center gap-2 text-sm font-extrabold">
                                <span>▶️</span>
                                <span>Bước 1: Kích hoạt mã YouTube</span>
                            </span>
                            <span class="text-xs font-semibold">Mở ra rồi quay lại — đừng đặt hàng</span>
                        </a>
                        <div v-else class="rounded-lg bg-[var(--color-money-soft)] border border-[var(--color-money)]/30 px-3 py-2.5">
                            <p class="flex items-center gap-2 text-sm font-bold text-[var(--color-money)]">
                                <span>✓</span>
                                <span>Đã nhận mã xong bước 1</span>
                            </p>
                            <p class="text-xs text-[var(--color-ink)] leading-relaxed mt-1">
                                Giờ bấm nút <b>bước 2</b> bên dưới để đặt hàng — <b>đừng mua thẳng trên Shopee</b>,
                                dễ bị đánh dấu <b>F02</b>.
                            </p>
                        </div>
                        <button
                            v-if="ytbBlocked"
                            type="button"
                            @click="markYtbActivated(voucherResult.voucher_ref)"
                            class="focus-ring mt-1 min-h-[44px] text-xs text-[var(--color-muted)] underline underline-offset-2"
                        >Vừa kích hoạt rồi (Shopee đã mở)? Bỏ qua bước này</button>
                    </div>

                    <!-- Khách vừa từ Shopee quay lại mà chưa làm bước 2. Nhắc thẳng vào mặt vì
                         đây là lúc họ có thể vừa đặt hàng hụt mã xong — biết sớm thì còn huỷ
                         đơn và làm lại được. -->
                    <div
                        v-if="!autoRedirecting && ytbReturnNudge && voucherResult.voucher_ref"
                        class="mb-3 mt-3 rounded-xl border border-[rgba(var(--color-danger-rgb),.4)] bg-[var(--color-danger-soft)] px-4 py-3"
                    >
                        <p class="text-sm font-bold text-[var(--color-danger)] mb-1">⚠️ Bạn chưa làm bước 2 — đừng mua ở bước 1</p>
                        <p class="text-xs text-[var(--color-ink)] leading-relaxed">
                            Nếu bạn vừa đặt hàng thẳng ở trang Shopee lúc nãy thì nên <b>huỷ đơn đó</b> —
                            mua ở bước 1 dễ khiến tài khoản bị đánh dấu <b>F02</b>. Đặt lại bằng nút
                            <b>{{ viaFacebookComment ? 'Lấy mã qua Facebook' : 'Mua ngay' }}</b> bên dưới.
                        </p>
                    </div>

                    <!-- Nói trước khi khách bấm: nút này mở Facebook, không phải mở thẳng Shopee.
                         Dùng token info (thông tin trung tính) thay cho xanh Facebook #1877F2 viết
                         thẳng — cùng là xanh, nhưng token có sẵn bản tối và không nhận vơ thương
                         hiệu Facebook cho một khối hướng dẫn của mình. -->
                    <div v-if="!autoRedirecting && viaFacebookComment && voucherResult.voucher_ref" class="mb-3 mt-3 rounded-xl border border-[rgba(var(--color-info-rgb),.3)] bg-[var(--color-info-soft)] px-4 py-3">
                        <p class="text-sm font-bold text-[var(--color-ink)] mb-2">{{ voucherResult.ytb_activate_url ? 'Bước 2 — nhận mã qua Facebook:' : 'Mã này nhận qua Facebook — làm 2 bước:' }}</p>
                        <ol class="text-xs text-[var(--color-ink)] leading-relaxed space-y-1 list-decimal list-inside">
                            <li v-if="!isFacebookLink(readyLinks.result)">Bấm nút bên dưới → hệ thống lấy mã và hiện nút <b>Mở Facebook ngay</b>.</li>
                            <li v-else-if="facebookMode === 'reel'">Bấm <b>Mở Facebook ngay</b> → <b>ứng dụng Facebook mở ra</b> tại một reel.</li>
                            <li v-else>Bấm <b>Mở Facebook ngay</b> → <b>Facebook sẽ mở ra</b> tại một bình luận.</li>
                            <li>Bấm tiếp vào <b>{{ linkLocation }}</b> → về Shopee, mã đã áp sẵn.</li>
                        </ol>
                        <!-- Cùng lý do với dòng phụ ở dải hoàn tiền: nền đã đổi từ #1877F2/5 (gần
                             như trắng) sang --color-info-soft #E8EEFC, muted trên nền đó tụt còn
                             4.12:1 ở chế độ sáng. Mà đây là câu quyết định việc khách có mã hay
                             không, không phải chú thích cho vui. -->
                        <p class="text-xs text-[var(--color-ink)] mt-2">Phải đi qua Facebook thì mã mới có hiệu lực — đừng đóng giữa chừng nhé.</p>
                    </div>

                    <!-- Trước đây chỗ này có khối mời khách vãng lai đăng nhập để được hoàn tiền.
                         Đã bỏ: nó chắn nguyên một màn hình điện thoại ngay trước nút mua, đúng lúc
                         khách đã muốn bấm — trong khi ở chế độ Facebook (đang chạy) nó vốn đã bị ẩn,
                         và lời mời cũng chưa chắc giữ được lời hứa: link trên caption reel dùng lại
                         theo SẢN PHẨM nên có thể mang sub_id của khách khác. Lời mời hoàn tiền vẫn
                         còn ở cuối trang (showGuestCashbackNudge), chỗ không cản đường mua hàng. -->

                    <!-- Tầng 3: BẤM VÀO ĐÂU. Mã đã được áp sẵn trong link nên khách không phải
                         chọn/nhập gì, chỉ bấm mở — đúng MỘT nút cam, chiếm trọn bề ngang, cao
                         52px. Nút "sao chép" tách xuống hàng dưới thay vì đứng cạnh: nó là việc
                         của người đi chia sẻ link, không phải của người đang muốn mua, mà đứng
                         cạnh thì nó cắt mất 56px bề ngang của chính nút quan trọng nhất trang. -->
                    <div v-if="!autoRedirecting && voucherResult.voucher_ref" class="mt-4 mb-4">
                        <div ref="resultCtaEl">
                            <!-- Đã lấy được link comment: chuyển hẳn sang thẻ <a>. Cú chạm vào anchor
                                 thật là điều kiện bắt buộc để iOS bật app Facebook; không dùng
                                 target="_blank" vì tab mới cũng làm hỏng universal link. -->
                            <!-- Chưa qua bước 1 (kích hoạt YouTube) thì khoá — kể cả khi link reel đã
                                 lấy sẵn (autoRedirect): bấm được là khách bỏ bước 1 rồi mất mã YTB.
                                 KHÔNG mặc .btn-fire lúc bị khoá: cam đặc mờ 50% vẫn nặng mắt hơn
                                 nút "Bước 1" viền mỏng phía trên, tức vật nổi nhất khung nhìn lại
                                 là vật KHÔNG bấm được — khách bấm vào nó, không thấy gì xảy ra, rồi
                                 tự mò sang Shopee mua thẳng và mất cả mã lẫn hoàn tiền. Lúc bị khoá
                                 nó chỉ là một dòng trạng thái xám; cam quay lại ngay khi mở khoá. -->
                            <button
                                v-if="ytbBlocked"
                                type="button"
                                disabled
                                class="w-full min-w-0 px-6 min-h-[52px] rounded-xl flex items-center justify-center gap-2 text-base font-bold border border-[var(--color-line)] bg-[var(--color-surface)] text-[var(--color-muted)] cursor-not-allowed"
                            >
                                <span>🔒</span>
                                <span class="truncate">Bước 2: {{ viaFacebookComment ? 'Lấy mã qua Facebook' : 'Mua ngay' }}</span>
                            </button>
                            <!-- Bỏ animate-pulse-ring ở nút này: vòng sáng vàng nhấp nháy quanh một
                                 nút cam gradient là màu thứ ba chen vào đúng chỗ đã sáng nhất thẻ,
                                 và ở chế độ mã YTB nó chạy cùng lúc với vòng sáng của bước 1. Nút
                                 nằm trọn bề ngang, cao 52px, là vật cam duy nhất trong khung nhìn
                                 — không cần nhấp nháy mới thấy. -->
                            <a
                                v-else-if="readyLinks.result"
                                :href="facebookAppLink(readyLinks.result)"
                                @click="trackFacebookOpen(readyLinks.result, voucherResult.product?.product_name)"
                                class="btn-fire w-full min-w-0 px-6 min-h-[52px] rounded-xl flex items-center justify-center gap-2 text-base no-underline"
                            >
                                <span>{{ isFacebookLink(readyLinks.result) ? '👉' : '🛒' }}</span>
                                <span class="truncate">{{ isFacebookLink(readyLinks.result) ? 'Mở Facebook ngay' : 'Mua ngay (đã áp mã)' }}</span>
                            </a>
                            <button
                                v-else
                                @click="openVoucherLink({ key: 'result', ref: voucherResult.voucher_ref })"
                                :disabled="shorteningKey === 'result'"
                                class="btn-fire w-full min-w-0 px-6 min-h-[52px] rounded-xl flex items-center justify-center gap-2 text-base disabled:opacity-60"
                            >
                                <span>{{ viaFacebookComment ? '👉' : '🛒' }}</span>
                                <span class="truncate">{{ ctaLabel }}</span>
                            </button>
                        </div>
                        <button
                            @click="copyVoucherLink({ key: 'result', ref: voucherResult.voucher_ref })"
                            :disabled="copyingKey === 'result'"
                            title="Sao chép link để dán lên Facebook/Zalo"
                            class="focus-ring mt-2 w-full min-h-[44px] rounded-xl flex items-center justify-center gap-2 text-sm font-semibold transition-all bg-transparent border border-[var(--color-line)] text-[var(--color-muted)] hover:text-[var(--color-ink)] disabled:opacity-60"
                        >
                            <svg v-if="copyingKey !== 'result'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                            <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4 animate-spin"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                            <span>{{ copyingKey === 'result' ? 'Đang tạo link...' : 'Sao chép link để gửi bạn bè' }}</span>
                        </button>
                    </div>
                    <!-- v-if tường minh chứ không v-else: đang tự chuyển hướng thì đã có spinner
                         ở trên rồi, v-else sẽ hiện thêm dòng "chưa lấy được mã" gây hoang mang. -->
                    <p v-if="!autoRedirecting && !voucherResult.voucher_ref" class="text-sm text-[var(--color-muted)] mt-3 mb-4">Chưa lấy được mã cho sản phẩm này — có thể do lỗi kết nối tạm thời, thử dán lại link nhé.</p>

                    <!-- Lời dặn = vai "cần chú ý" (warn), không phải vai "hành động chính": nền
                         cam nhạt + chữ accent-deep ở đây là màu của nút mua đem dùng cho một đoạn
                         chữ không bấm được, làm loãng đúng thứ cần nổi. -->
                    <div class="flex items-start gap-2 bg-[var(--color-warn-soft)] border border-[rgba(var(--color-warn-rgb),.3)] rounded-xl px-3 py-2.5">
                        <span class="text-sm leading-none">⚠️</span>
                        <p v-if="viaFacebookComment" class="text-xs text-[var(--color-ink)] leading-relaxed">Nhớ bấm <b>{{ linkLocation }}</b> thì mã mới được áp — bấm nhầm chỗ khác là mua không có giảm giá. Sang Shopee rồi thì đặt hàng bình thường, không cần nhập mã. Nếu Shopee báo mã hết lượt, thử lại sau ít phút nhé.</p>
                        <p v-else class="text-xs text-[var(--color-ink)] leading-relaxed">Mã đã gắn sẵn trong link — bấm "Mua ngay" rồi đặt hàng như bình thường, không cần nhập mã. Nếu Shopee báo mã hết lượt, thử lại sau ít phút nhé.</p>
                    </div>
                </div>

                <!-- Hướng dẫn 4 bước, đặt NGAY SAU ô dán link (và sau khối kết quả nếu đang có).
                     Đứng sau kết quả chứ không chen vào giữa: lúc vừa quét xong, thứ khách cần
                     thấy ngay dưới ô nhập là sản phẩm + nút mua, không phải bảng hướng dẫn.
                     Không có kết quả thì khối này tự nằm sát ô nhập, đúng chỗ khách đang phân vân. -->
                <div v-if="canUseVoucherTool" class="mt-4">
                    <HowItWorksSteps :title="stepsTitle" :steps="steps" />
                </div>

                <!-- Khung giờ back mã: câu hỏi THỨ HAI của khách ("sao không ra mã / mấy giờ có
                     mã mới"), nên đứng ngay sau bảng hướng dẫn chứ không đứng trên ô dán link.
                     Chỉ cách màn hình đầu đúng một nhịp cuộn, vẫn thấy được trước khi rời trang. -->
                <RestockSchedule class="mt-4" />

                <!-- Điểm danh nhận quà: đứng ngay dưới công cụ chính, trước lịch sử/bảng xếp hạng.
                     Đây là lý do để quay lại vào ngày khách KHÔNG có gì để mua, nên nó phải nằm trong
                     màn hình đầu tiên sau khi cuộn một nhịp — nhét xuống cuối trang thì chỉ người đã quay
                     lại rồi mới thấy, tức đúng nhóm không cần nó nữa. -->
                <DailyCheckIn v-if="dailyCheckIn" :state="dailyCheckIn" class="mt-4" />

                <!-- Lịch sử chuyển đổi (lưu trên trình duyệt) + Bảng xếp hạng hoàn tiền, dạng tab.
                     Chỉ hiện thanh tab khi cả hai cùng có; thiếu một bên thì hiện thẳng bên còn lại
                     với tiêu đề thường, không bắt khách bấm tab để xem thứ duy nhất đang có. -->
                <div v-if="history.length || hasLeaderboard" class="mt-8">
                    <!-- Tab đang chọn dùng .nav-pill--active (viên thuốc cam nhạt của hệ) chứ không
                         còn .btn-fire: btn-fire là nút cam đặc — dùng cho việc CHỌN TAB nghĩa là
                         trên cùng một màn hình có hai vật cam đặc, mà chỉ một trong hai dẫn tới
                         tiền. Bấm tab không phải hành động chính của trang này. -->
                    <div v-if="showTabs" class="flex gap-2 mb-3">
                        <button
                            @click="activeTab = 'history'"
                            class="nav-pill focus-ring flex-1 px-3 min-h-[44px] rounded-xl text-sm font-bold flex items-center justify-center gap-1.5"
                            :class="activeTab === 'history' ? 'nav-pill--active' : 'bg-[var(--color-surface)] border-[var(--color-line)]'"
                        >
                            <span>🕘</span> Lịch sử
                            <!-- Badge đếm để màu trung tính, không cam: một là nó nằm ngay trong
                                 viên thuốc đã cam sẵn khi tab đang chọn (cam chồng cam, không đọc
                                 ra tầng bậc nào), hai là accent-deep trên nền cam 12% chỉ đạt
                                 4.0:1 ở chế độ sáng. Đây là một con số đếm, không phải lời mời bấm. -->
                            <span class="num text-xs font-mono px-1.5 py-0.5 rounded-full bg-[var(--color-line)] text-[var(--color-ink)]">{{ history.length }}</span>
                        </button>
                        <button
                            @click="activeTab = 'leaderboard'"
                            class="nav-pill focus-ring flex-1 px-3 min-h-[44px] rounded-xl text-sm font-bold flex items-center justify-center gap-1.5"
                            :class="activeTab === 'leaderboard' ? 'nav-pill--active' : 'bg-[var(--color-surface)] border-[var(--color-line)]'"
                        >
                            <span>🏆</span> Bảng xếp hạng
                        </button>
                    </div>
                    <h3 v-else-if="history.length" class="text-sm font-bold text-[var(--color-ink)] mb-3">Lịch sử chuyển đổi</h3>
                    <h3 v-else class="text-sm font-bold text-[var(--color-ink)] mb-3">🏆 Bảng vàng hoàn tiền tháng {{ leaderboard.month }}</h3>

                    <div v-if="history.length && (!showTabs || activeTab === 'history')" class="flex flex-col gap-3">
                        <div
                            v-for="(h, hi) in history"
                            :key="hi"
                            class="card p-3"
                        >
                            <div class="flex items-center gap-3 mb-2">
                                <div class="w-10 h-10 rounded-lg bg-[var(--color-peach-soft)] flex-none overflow-hidden">
                                    <img v-if="h.product_image" :src="h.product_image" :alt="h.product_name" class="w-full h-full object-cover" />
                                    <div v-else class="w-full h-full flex items-center justify-center text-lg">🛍️</div>
                                </div>
                                <span class="truncate flex-1 text-sm text-[var(--color-ink)] font-medium">{{ h.product_name || 'Sản phẩm' }}</span>
                                <span class="text-[var(--color-muted)] text-xs whitespace-nowrap">{{ new Date(h.created_at).toLocaleDateString('vi-VN') }}</span>
                            </div>
                            <!-- Công tắc "Mua lại từ lịch sử" (Admin > Cài đặt) đang TẮT: không có
                                 nút mua nào ở đây, khách muốn mua phải dán lại link để quét mới —
                                 mã trong ref cũ có thể đã hết lượt/hết hạn từ lúc quét. -->
                            <button
                                v-if="!historyRebuy"
                                type="button"
                                @click="focusVoucherTool"
                                class="focus-ring px-4 min-h-[44px] rounded-lg text-sm font-bold inline-flex items-center gap-1.5 border border-[var(--color-line)] text-[var(--color-muted)] hover:border-[var(--color-accent)] hover:text-[var(--color-ink)] transition"
                            >
                                <span>📋</span> Dán lại link để lấy mã
                            </button>
                            <!-- Chế độ mã YTB: mua lại từ lịch sử cũng phải kích hoạt YouTube trước
                                 (bước 1) y như lần đầu — trạng thái nhớ theo ref trong sessionStorage. -->
                            <a
                                v-if="historyRebuy && h.ref && historyYtbBlocked(h)"
                                :href="h.ytb_activate_url"
                                @click="markYtbActivated(h.ref)"
                                class="focus-ring mb-2 px-4 min-h-[44px] rounded-lg text-sm inline-flex items-center gap-1.5 font-bold bg-[var(--color-danger-soft)] border border-[rgba(var(--color-danger-rgb),.45)] text-[var(--color-danger)] no-underline"
                            >
                                <span>▶️</span> Bước 1: Kích hoạt mã YouTube
                            </a>
                            <!-- Mục cũ (trước khi chuyển sang một mã duy nhất) không có h.ref nên
                                 không hiện nút — chúng tự trôi khỏi danh sách sau 5 lần quét mới. -->
                            <button
                                v-if="historyRebuy && h.ref && historyYtbBlocked(h)"
                                type="button"
                                disabled
                                class="px-4 min-h-[44px] rounded-lg text-sm font-bold flex items-center gap-1.5 border border-[var(--color-line)] bg-[var(--color-surface)] text-[var(--color-muted)] cursor-not-allowed"
                            >
                                <span>🔒</span> Bước 2: {{ viaFacebookComment ? 'Lấy mã qua Facebook' : 'Mua ngay' }}
                            </button>
                            <a
                                v-else-if="historyRebuy && h.ref && readyLinks[`hist-${hi}`]"
                                :href="facebookAppLink(readyLinks[`hist-${hi}`])"
                                @click="trackFacebookOpen(readyLinks[`hist-${hi}`], h.product_name)"
                                class="btn-fire px-4 min-h-[44px] rounded-lg text-sm inline-flex items-center gap-1.5 no-underline"
                            >
                                <span>{{ isFacebookLink(readyLinks[`hist-${hi}`]) ? '👉' : '🛒' }}</span>
                                {{ isFacebookLink(readyLinks[`hist-${hi}`]) ? 'Mở Facebook ngay' : 'Mua ngay' }}
                            </a>
                            <button
                                v-else-if="historyRebuy && h.ref"
                                @click="openVoucherLink({ key: `hist-${hi}`, ref: h.ref }, h.product_name, h.product_image)"
                                :disabled="shorteningKey === `hist-${hi}`"
                                class="btn-fire px-4 min-h-[44px] rounded-lg text-sm flex items-center gap-1.5 disabled:opacity-60"
                            >
                                <span>{{ viaFacebookComment ? '👉' : '🛒' }}</span>
                                {{ shorteningKey === `hist-${hi}` ? (viaFacebookComment ? 'Đang lấy mã...' : 'Đang mở...') : (viaFacebookComment ? 'Lấy mã qua Facebook' : 'Mua ngay') }}
                            </button>
                        </div>
                    </div>

                    <CashbackLeaderboard
                        v-if="hasLeaderboard && (!showTabs || activeTab === 'leaderboard')"
                        :leaderboard="leaderboard"
                        compact
                    />
                </div>
            </div>
        </section>

        <!-- Hoàn tiền: đặt NGAY SAU công cụ, trước mọi section dài khác. Trên điện thoại, section
             "Mã giảm giá gợi ý" bên dưới là một grid một cột dài hàng chục màn hình — nhét khối
             giải thích xuống sau nó thì coi như không ai đọc.
             Bản RÚT GỌN: chỉ giữ hai cột ✓/✕ rồi dẫn sang /hoan-tien. Bản đầy đủ dài 3-4 màn
             hình điện thoại, đẩy mục mã gợi ý và FAQ xuống quá sâu. -->
        <CashbackExplainer v-if="cashbackOn" compact />

        <!-- Hạng thành viên: đứng ngay dưới khối hoàn tiền rút gọn, vì nó trả lời câu hỏi kế
             tiếp của người vừa đọc xong "có được hoàn không" — hoàn bao nhiêu, và mua nhiều thì
             có hơn gì không. Khách đã đăng nhập còn thấy luôn hạng của mình ở đây. -->
        <MembershipTiers v-if="membershipTiers" v-bind="membershipTiers" />

        <!-- Mã giảm giá gợi ý -->
        <section v-if="vouchers.length" class="py-16 px-4 bg-[var(--color-bg)]">
            <div class="max-w-5xl mx-auto">
                <div class="text-center mb-8">
                    <h2 class="text-2xl md:text-3xl font-extrabold text-[var(--color-ink)] mb-2">🎁 Mã giảm giá gợi ý</h2>
                    <p class="text-[var(--color-muted)] text-sm">Mã từ Facebook & YouTube đang có hiệu lực — copy và dùng ngay khi mua hàng.</p>
                </div>

                <!-- Platform filter — cùng lý do với thanh tab lịch sử: lọc theo sàn là việc phụ,
                     không được mặc áo cam đặc của nút mua. Dùng viên thuốc chọn/không chọn của hệ. -->
                <div class="flex flex-wrap justify-center gap-2 mb-8">
                    <button
                        v-for="tab in platformTabs"
                        :key="tab.key"
                        @click="activePlatform = tab.key"
                        :class="activePlatform === tab.key
                            ? 'nav-pill--active'
                            : 'bg-[var(--color-surface)] border-[var(--color-line)]'"
                        class="nav-pill focus-ring inline-flex items-center px-4 min-h-[44px] rounded-xl text-sm font-semibold"
                    >
                        {{ tab.label }}
                    </button>
                </div>

                <!-- Voucher grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <CouponTicket
                        v-for="v in filteredVouchers"
                        :key="v.id"
                        :code="v.code"
                        :discount-type="v.discount_type"
                        :discount-value="Number(v.discount_value)"
                        :minimum-order="Number(v.minimum_order)"
                        :expires-at="v.expires_at"
                        :is-freeship="v.discount_type === 'freeship'"
                        :source="v.source"
                        :subtitle="v.title"
                    />
                </div>
                <p v-if="!filteredVouchers.length" class="text-center text-[var(--color-muted)] text-sm py-8">
                    Chưa có mã cho sàn này. Thử chọn sàn khác nhé!
                </p>
            </div>
        </section>

        <!-- FAQ -->
        <section class="py-16 px-4 bg-[var(--color-bg)]">
            <div class="max-w-2xl mx-auto">
                <h2 class="text-2xl font-extrabold text-[var(--color-ink)] text-center mb-10">Câu hỏi thường gặp</h2>
                <div class="space-y-3">
                    <div
                        v-for="(faq, i) in faqs"
                        :key="i"
                        class="card overflow-hidden"
                    >
                        <button
                            @click="openFaq = openFaq === i ? null : i"
                            :aria-expanded="openFaq === i"
                            :aria-controls="`faq-panel-${i}`"
                            class="focus-ring w-full px-5  min-h-[44px] text-left flex justify-between items-center gap-4 font-semibold text-[var(--color-ink)] text-sm"
                        >
                            {{ faq.q }}
                            <span class="text-[var(--color-muted)] transition-transform" :class="openFaq === i ? 'rotate-180' : ''">▾</span>
                        </button>
                        <Transition name="fade-up">
                            <div v-if="openFaq === i" :id="`faq-panel-${i}`" class="px-5 pb-4 text-sm text-[var(--color-muted)] leading-relaxed">
                                {{ faq.a }}
                            </div>
                        </Transition>
                    </div>
                </div>
            </div>
        </section>

        <!-- CTA cuối trang.
             Nền cam đặc + chữ trắng đã bỏ vì nó hỏng ở CẢ HAI chế độ: ở sáng, chữ trắng trên
             #F5511E chỉ được 3.4:1 (đoạn văn để white/80 còn 2.7:1 — trượt AA rõ ràng), còn ở
             tối --color-accent là cam nhạt #fb923c nên chữ trắng trên đó còn tệ hơn. Đổi thành
             dải nền peach-soft + chữ ink/muted: đúng tương phản ở cả hai chế độ, và để dành màu
             cam đặc cho ĐÚNG MỘT vật trong khung nhìn — cái nút. -->
        <section class="py-14 px-4 bg-[var(--color-peach-soft)] border-t border-[var(--color-line)]">
            <div class="max-w-xl mx-auto text-center">
                <!-- Khách vãng lai + đang có hoàn tiền: đây là lời mời TẠO TÀI KHOẢN, nên nút phải
                     dẫn thẳng tới /register. Trước đây nút ghi "Tham gia ngay hôm nay" mà bấm vào
                     chỉ cuộn ngược lên ô dán link — hứa một đằng làm một nẻo.
                     Con số "hơn 1.2 triệu mã đã được tạo" đã bỏ: không có nguồn nào trong hệ thống
                     đếm ra con số đó, mà cả trang này đang bán bằng sự minh bạch. -->
                <template v-if="showGuestCashbackNudge">
                    <h2 class="text-2xl md:text-3xl font-extrabold text-[var(--color-ink)] mb-4">Mua thì vẫn phải mua — sao không lấy lại một phần?</h2>
                    <!-- Không dùng chữ "khác mỗi việc đăng nhập": đăng nhập là điều kiện ĐẦU TIÊN
                         chứ không phải điều kiện duy nhất — sau nó còn đơn phải Hoàn thành, phải
                         qua kỳ đối soát, và muốn cầm được tiền thì còn mốc rút tối thiểu. Kể đúng
                         thứ tự các chặng, rồi lấy chính sự thẳng thắn đó làm câu chốt. -->
                    <p class="text-[var(--color-muted)] mb-8">
                        Vẫn dán link, vẫn được mã giảm giá như thường. Đăng nhập trước khi bấm mua thì đơn của bạn
                        còn được ghi nhận: Shopee chốt đơn ở trạng thái Hoàn thành, tụi mình đối soát báo cáo,
                        rồi {{ cashbackRate }}% hoa hồng của đơn đó vào ví bạn. Không nhanh, nhưng có thật —
                        và tụi mình nói trước cả những lúc bạn không được hoàn.
                    </p>
                    <Link :href="joinHref"
                        class="btn-fire inline-flex items-center justify-center px-8 min-h-[52px] rounded-2xl no-underline">
                        {{ joinLabel }}
                    </Link>
                </template>
                <template v-else>
                    <h2 class="text-2xl md:text-3xl font-extrabold text-[var(--color-ink)] mb-4">Sẵn sàng tiết kiệm tiền?</h2>
                    <p class="text-[var(--color-muted)] mb-8">Dán link sản phẩm là có ngay mã giảm giá — miễn phí, không cần nhập tay.</p>
                    <button @click="focusVoucherTool"
                        class="btn-fire inline-flex items-center justify-center px-8 min-h-[52px] rounded-2xl">
                        Lấy link ngay — Miễn phí
                    </button>
                </template>
            </div>
        </section>
    </AppLayout>
</template>
