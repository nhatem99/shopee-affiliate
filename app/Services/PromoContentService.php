<?php

namespace App\Services;

use App\Http\Controllers\ProfileController;
use App\Models\Setting;

/**
 * Kho mẫu nội dung để admin copy đi đăng (nhóm Facebook, Zalo, TikTok...).
 *
 * Vì sao mẫu nằm ở PHP chứ không gõ thẳng vào .vue: mọi con số trong bài đăng là CAM KẾT với
 * người tiêu dùng, nên chúng phải được bơm từ một nguồn duy nhất ở server thay vì nằm rải rác
 * trong template. Đổi tỉ lệ hoàn tiền ở /admin/settings là mọi mẫu đổi theo ngay, không có
 * chuyện một bài đăng cũ còn mang số cũ.
 *
 * Hai mức phần trăm mã giảm giá cố tình để admin TỰ SỬA (Setting) chứ không gõ cứng: đó là con
 * số của nguồn cấp mã, đổi theo từng đợt, và không có chỗ nào trong hệ thống này chứng minh
 * được nó. Gõ cứng nghĩa là mỗi lần nguồn đổi mức giảm thì phải sửa code và deploy lại.
 */
class PromoContentService
{
    public const TOP_PERCENT_KEY = 'promo_top_percent';

    public const SECOND_PERCENT_KEY = 'promo_second_percent';

    /**
     * Khung giờ nguồn cấp mã nạp lại lượt. PHẢI khớp với hằng GROUPS trong
     * resources/js/Components/RestockSchedule.vue — banner trên trang chủ và bài đăng mà nói hai
     * giờ khác nhau thì khách canh sai giờ, vào thấy hết mã rồi nghĩ mình nói dối.
     */
    private const RESTOCK_FB_IG = '0h - 9h - 15h - 20h';

    private const RESTOCK_YOUTUBE = '0h - 9h - 12h - 18h';

    /**
     * Đơn mẫu dùng để minh hoạ tiền hoàn trong bài đăng.
     *
     * Con số hoa hồng là GIẢ ĐỊNH, không phải số đo được: hai nguồn cấp mã đang chạy không trả
     * về tỉ lệ hoa hồng, và hệ thống chỉ biết hoa hồng thật sau khi admin nhập báo cáo Shopee.
     * Vì vậy câu chữ trong mẫu luôn viết dạng "nếu Shopee trả hoa hồng khoảng X" — nói rõ đó là
     * ví dụ chứ không phải cam kết.
     */
    private const EXAMPLE_ORDER_VALUE = 500000;

    private const EXAMPLE_COMMISSION = 25000;

    public function __construct(private CashbackService $cashback) {}

    /**
     * Giá trị bơm vào các chỗ {{ ... }} trong mẫu.
     *
     * @return array<string, string>
     */
    public function tokens(): array
    {
        return [
            'siteUrl' => rtrim((string) config('app.url'), '/'),
            'cashbackRate' => $this->formatNumber($this->cashback->rate()),
            'topPercent' => $this->formatNumber($this->topPercent()),
            'secondPercent' => $this->formatNumber($this->secondPercent()),
            // Link nhóm cộng đồng — admin chưa đặt thì để rỗng, và dòng chứa nó sẽ bị cắt khỏi
            // bài thay vì đăng ra một dòng link trống trơn (xem render()).
            'communityUrl' => (string) (Setting::get('community_url') ?: ''),
            'minWithdrawal' => $this->vnd(ProfileController::MIN_WITHDRAWAL),
            'restockHoursFbIg' => self::RESTOCK_FB_IG,
            'restockHoursYoutube' => self::RESTOCK_YOUTUBE,
            'exampleOrder' => $this->vnd(self::EXAMPLE_ORDER_VALUE),
            'exampleCommission' => $this->vnd(self::EXAMPLE_COMMISSION),
            'exampleCashback' => $this->vnd((int) round(self::EXAMPLE_COMMISSION * $this->cashback->rate() / 100)),
        ];
    }

    public function topPercent(): float
    {
        return max(0.0, (float) Setting::get(self::TOP_PERCENT_KEY, 25));
    }

    public function secondPercent(): float
    {
        return max(0.0, (float) Setting::get(self::SECOND_PERCENT_KEY, 22));
    }

    /**
     * Tỉ lệ hoàn tiền = 0 nghĩa là chương trình đang tắt và hệ thống không trả đồng nào
     * (xem CashbackService::sync). Mẫu nào nhắc tới hoàn tiền phải bị loại khỏi danh sách,
     * không phải chỉ ẩn ở giao diện — admin không được có cơ hội copy nhầm một lời hứa suông.
     */
    public function cashbackOn(): bool
    {
        return $this->cashback->rate() > 0;
    }

    /**
     * Danh sách mẫu đã bơm số, sẵn sàng để copy.
     *
     * @return list<array{id: string, channel: string, name: string, when_to_use: string, body: string}>
     */
    public function templates(): array
    {
        $cashbackOn = $this->cashbackOn();
        // Mức giảm chưa đặt (hoặc đặt về 0) thì bài quảng cáo mức giảm không còn gì để nói —
        // ẩn hẳn thay vì đẩy ra một bài ghi "Mã giảm Shopee tới 0%".
        $hasPercents = $this->topPercent() > 0 && $this->secondPercent() > 0;

        return collect($this->rawTemplates())
            ->reject(fn (array $t) => ($t['needs_cashback'] ?? false) && ! $cashbackOn)
            ->reject(fn (array $t) => ($t['needs_percents'] ?? false) && ! $hasPercents)
            ->map(fn (array $t) => [
                'id' => $t['id'],
                'channel' => $t['channel'],
                'name' => $t['name'],
                'when_to_use' => $t['when_to_use'],
                'body' => $this->render($t['body']),
            ])
            ->values()
            ->all();
    }

    private function render(string $body): string
    {
        $tokens = $this->tokens();

        // Token rỗng (hiện chỉ có communityUrl) thì CẮT CẢ DÒNG chứa nó. Thay bằng chuỗi rỗng sẽ
        // để lại một dòng cụt kiểu "👥 Nhóm săn sale của tụi mình:" — admin copy nguyên thế rồi
        // đăng lên là bài trông cẩu thả ngay ở chỗ đang xin người ta tin mình.
        $body = implode("\n", array_filter(
            explode("\n", $body),
            function (string $line) use ($tokens): bool {
                foreach ($tokens as $token => $value) {
                    if ($value === '' && str_contains($line, '{{ '.$token.' }}')) {
                        return false;
                    }
                }

                return true;
            },
        ));

        $replacements = [];

        foreach ($tokens as $token => $value) {
            $replacements['{{ '.$token.' }}'] = $value;
        }

        return strtr($body, $replacements);
    }

    private function vnd(int $amount): string
    {
        return number_format($amount, 0, ',', '.').'₫';
    }

    /**
     * Bỏ đuôi ".0" cho số tròn: bài đăng ghi "hoàn 30%" chứ không ai viết "hoàn 30.0%".
     * Số lẻ vẫn giữ nguyên phần thập phân và dùng dấu phẩy theo cách viết của người Việt.
     */
    private function formatNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, ',', '.'), '0'), ',');
    }

    /**
     * @return list<array{id: string, channel: string, name: string, when_to_use: string, needs_cashback?: bool, needs_percents?: bool, body: string}>
     */
    private function rawTemplates(): array
    {
        return [
            [
                'id' => 'fb-group-codes',
                'channel' => 'group-fb',
                'name' => 'Nhóm Facebook — bài ngắn, nhấn mức giảm',
                'when_to_use' => 'Bài đăng hàng ngày trong nhóm săn sale. Ngắn để không bị cắt ở chỗ "Xem thêm". Dùng đúng hai mức giảm bạn đặt ở trên — nguồn đổi mức thì sửa lại rồi copy bài mới.',
                'needs_percents' => true,
                'body' => <<<'TXT'
🔥 Mã giảm Shopee tới {{ topPercent }}% — loại mã nội bộ, tự mở app tìm không thấy

Mình gom mã ở đây: {{ siteUrl }}
Dán link sản phẩm Shopee vào ô đầu trang, nó trả về link đã gắn sẵn mã — bấm vào là mua với giá đã giảm, khỏi nhập mã tay.

Mức đang chạy: {{ topPercent }}% và {{ secondPercent }}%, tuỳ sản phẩm và tuỳ còn lượt hay không.

Nói trước mấy cái kẻo dùng rồi tưởng lỗi:
▪️ Mở bằng ĐIỆN THOẠI, máy tính không chạy được
▪️ Chỉ nhận link Shopee
▪️ Bấm xong nó mở Facebook một nhịp, bấm tiếp link hiện ở đó mới sang Shopee kèm mã. Đừng tắt giữa chừng, tắt là mất mã.
▪️ Không phải sản phẩm nào cũng có mã, và mã có giới hạn lượt. Hết thì canh khung {{ restockHoursFbIg }} (giờ VN) quay lại.

Miễn phí, không thu gì cả.
👉 {{ siteUrl }}
TXT,
            ],
            [
                'id' => 'fb-group-cashback',
                'channel' => 'group-fb',
                'name' => 'Nhóm Facebook — bài dài giải thích hoàn tiền (bài ghim)',
                'when_to_use' => 'Dùng cho nhóm mình lập, nhóm đã quen mặt, hoặc làm BÀI GHIM cho người mới. Cũng dùng để trả lời tập trung khi có người hỏi "hoàn tiền kiểu gì, bao lâu có tiền".',
                'needs_cashback' => true,
                'body' => <<<'TXT'
💸 Mua Shopee xong được chia lại tiền — mình nói thẳng con số thật, kẻo bạn hiểu nhầm

Là {{ cashbackRate }}% của khoản HOA HỒNG mà Shopee trả cho mình vì đơn của bạn — KHÔNG phải {{ cashbackRate }}% giá trị đơn hàng.
Ví dụ cho dễ hình dung: đơn {{ exampleOrder }}, nếu Shopee trả hoa hồng khoảng {{ exampleCommission }} thì bạn nhận lại khoảng {{ exampleCashback }}.

Tiền ở đâu ra mà cho không? Shopee trả hoa hồng tiếp thị cho mình khi bạn mua qua link của mình. Mình chia lại {{ cashbackRate }}% khoản đó cho chính bạn — cộng THÊM vào phần mã giảm giá bạn đã được chứ không thay thế nó. Hoa hồng mỗi ngành hàng mỗi khác nên số tiền mỗi đơn cũng khác, mình không báo trước con số chính xác được.

✅ ĐƯỢC HOÀN khi:
▪️ Bạn ĐĂNG NHẬP TRƯỚC rồi mới bấm nút mua — cái này quan trọng nhất
▪️ Bạn đi thẳng từ link của mình sang Shopee và đặt hàng luôn ở đó
▪️ Đơn chuyển sang trạng thái "Hoàn thành" bên Shopee
▪️ Đơn đó thực sự có hoa hồng — vài ngành hàng hoa hồng bằng 0 thì không có gì để chia

❌ KHÔNG ĐƯỢC HOÀN khi:
▪️ Bấm mua lúc chưa đăng nhập — đơn đó không quy về ai được, và sau này không cứu lại được
▪️ Tự mở app Shopee tìm lại sản phẩm rồi đặt (kiểu này mất luôn cả mã giảm giá)
▪️ Đơn huỷ hoặc trả hàng — khoản đã ghi sẽ bị trừ lại

⏳ Bao lâu có tiền? Không nhanh, và mình không hứa 24h. Đơn phải Hoàn thành bên Shopee (tức qua hạn đổi trả), rồi mình đối soát theo báo cáo hoa hồng của Shopee mới ghi tiền vào ví — thường mất vài tuần. Chỗ nào hứa tiền về sau 24h thì bạn nên nghi ngờ.

🏦 Rút tiền: đủ {{ minWithdrawal }} là gửi được yêu cầu rút về MoMo/ZaloPay đã khai trong trang Tài khoản. Mình duyệt rồi chuyển tay, không tự động.

📌 Lưu ý khi dùng: chỉ nhận link SHOPEE, chỉ chạy trên ĐIỆN THOẠI, và sau khi dán link web sẽ mở Facebook — phải bấm đúng link hiện ở đó thì mã mới được áp.
Mức hoàn {{ cashbackRate }}% hiện tại có thể được điều chỉnh; khi đổi thì các khoản chưa chi trả sẽ được tính lại theo mức mới, mình luôn cập nhật ngay trên trang chủ.

👉 Đăng nhập trước, rồi mới bấm mua: {{ siteUrl }}
👥 Nhóm săn sale của tụi mình: {{ communityUrl }}

Mua rồi mà ví vẫn 0đ thì nhắn mình kèm mã đơn Shopee + ngày đặt để đối chiếu nhé. Trang Tài khoản chỉ hiện số dư đã đối soát xong, đơn đang chờ thì chưa hiện đâu.
TXT,
            ],
            [
                'id' => 'zalo-first',
                'channel' => 'zalo',
                'name' => 'Zalo — nhắn lần đầu, người quen xã giao',
                'when_to_use' => 'Gửi cho người quen không thân lắm: đồng nghiệp cũ, hàng xóm, khách từng mua hàng, họ hàng lớn tuổi. Cố tình ngắn và có câu "không hợp thì bỏ qua" để họ không thấy bị dí. KHÔNG dùng cho bạn thân — nghe khách sáo.',
                'needs_cashback' => true,
                'body' => <<<'TXT'
Anh/chị ơi, em có cái này hay dùng nên nhắn thử, không hợp thì anh/chị bỏ qua giúp em nha.

Em làm một trang lấy mã giảm giá Shopee: {{ siteUrl }}
Copy link sản phẩm bên Shopee, dán vào ô đầu trang, nó trả về link đã gắn sẵn mã — bấm vào là mua với giá đã giảm, không phải nhập mã tay.

Có 3 cái em phải nói trước kẻo anh/chị dùng rồi tưởng lỗi:
- Mở bằng điện thoại nhé, trên máy tính không chạy được.
- Chỉ nhận link Shopee thôi, Lazada/Tiki/TikTok chưa có.
- Bấm xong nó sẽ mở Facebook ra, anh/chị bấm tiếp cái link hiện ở đó thì mới sang Shopee kèm mã. Nghe vòng vo nhưng đúng là phải vậy, vì đó là loại mã dành riêng cho người mua đến từ Facebook. Tắt giữa chừng là mất mã.

Một lưu ý nữa: nếu anh/chị đăng nhập vào trang TRƯỚC rồi mới bấm nút mua thì sau khi đơn hoàn thành, em chia lại {{ cashbackRate }}% khoản hoa hồng Shopee trả cho em vì đơn đó, vào ví của anh/chị trên web. Bấm mua lúc chưa đăng nhập thì đơn không ghi nhận về ai được, cái đó em cũng chịu, không cứu lại được. Đăng nhập một lần thôi ạ.

Miễn phí hết, em không thu gì đâu.
TXT,
            ],
            [
                'id' => 'zalo-close',
                'channel' => 'zalo',
                'name' => 'Zalo — bạn thân / người hay mua Shopee (nói hết cả cái dở)',
                'when_to_use' => 'Gửi cho bạn thân, anh chị em, người mua Shopee suốt tuần — tức người sẽ dùng nhiều và hỏi kỹ. Nên gửi tách làm 2-3 tin thay vì một khối. KHÔNG gửi cho người mới quen, dài quá thành áp đảo.',
                'needs_cashback' => true,
                'body' => <<<'TXT'
Ê, cái web lấy mã Shopee mình làm xong rồi nè: {{ siteUrl }}
Bạn mua Shopee suốt nên dùng thử giùm mình, dở chỗ nào cứ chửi thẳng cho mình sửa.

Dùng thế này:
1. Mở bằng điện thoại. Trên máy tính không chạy được đâu, đừng mất công.
2. Đăng nhập vào trang trước đã — bước này quan trọng nhất, nói ở dưới.
3. Copy link sản phẩm Shopee, dán vào ô đầu trang.
4. Nó trả về link đã gắn mã. Bấm vào thì Facebook sẽ mở ra, bạn bấm tiếp cái link hiện ở đó mới sang Shopee với mã đã áp sẵn. Vòng vo vậy vì đó là mã nội bộ dành cho người mua đến từ Facebook, loại mà tự mở app Shopee tìm thì không thấy.

Vụ hoàn tiền, nói rõ kẻo hiểu nhầm:
Shopee trả hoa hồng tiếp thị cho mình vì đơn của bạn. Mình chia lại {{ cashbackRate }}% CỦA KHOẢN HOA HỒNG ĐÓ — không phải {{ cashbackRate }}% giá trị đơn hàng nha. Nên một đơn vài trăm nghìn thì phần hoàn thường rơi vào tầm vài nghìn tới vài chục nghìn, tuỳ ngành hàng, chứ không phải vài trăm nghìn. Mình nói trước cho bạn khỏi hụt hẫng.

Muốn có tiền thì cần đủ mấy cái này:
- Đăng nhập TRƯỚC rồi mới bấm nút mua. Bấm lúc chưa đăng nhập là đơn đó không gắn về tài khoản bạn được, sau này mình cũng không cứu lại được. Chỉ cần đăng nhập một lần.
- Đi thẳng từ link của mình sang Shopee rồi đặt luôn ở đó. Tự mở app Shopee tìm lại món đó rồi đặt là mất cả mã lẫn tiền hoàn.
- Đơn phải sang trạng thái Hoàn thành bên Shopee. Huỷ hoặc trả hàng thì khoản đã ghi bị trừ lại.
- Vài ngành hàng Shopee trả hoa hồng bằng 0 thì không có gì để chia.

Tiền về chậm, nói luôn: đơn phải qua hết hạn đổi trả, rồi mình tải báo cáo của Shopee về đối soát tay chứ không có tự động gì cả, thường mất vài tuần. Mình không hứa 24h như mấy chỗ khác. Đủ {{ minWithdrawal }} trong ví thì gửi yêu cầu rút về MoMo/ZaloPay, mình duyệt rồi chuyển tay.

Hai chuyện nữa: không phải món nào cũng có mã, vài shop bên nguồn không hỗ trợ, gặp vậy là bình thường chứ không phải web hỏng. Với mã có giới hạn lượt và hết nhanh lắm — khung giờ nạp lại lượt là {{ restockHoursFbIg }} giờ Việt Nam, hết thì canh đúng giờ đó quay lại.

Mức {{ cashbackRate }}% này về sau mình có thể chỉnh, lúc nào đổi mình báo bạn ngay.
TXT,
            ],
            [
                'id' => 'zalo-doubt',
                'channel' => 'zalo',
                'name' => 'Zalo — trả lời khi họ nghi "có lừa không"',
                'when_to_use' => 'Dùng khi người ta đã đọc tin đầu và nhắn lại kiểu "cái này có thật không", "tiền ở đâu ra mà cho". Đây là tin XỬ LÝ NGHI NGỜ, không phải tin mời — đừng gửi khi chưa ai hỏi, tự nhiên đi thanh minh là tự tố.',
                'needs_cashback' => true,
                'body' => <<<'TXT'
Không có gì mờ ám đâu, mình nói thẳng cơ chế cho bạn yên tâm:

Shopee có chương trình tiếp thị liên kết — ai giới thiệu ra đơn hàng thì Shopee trả hoa hồng cho người đó. Mình làm cái trang {{ siteUrl }} để làm việc đó, rồi chia lại {{ cashbackRate }}% khoản hoa hồng mình nhận được cho chính người mua. Tiền đó ra từ Shopee, bạn không phải trả thêm cho mình đồng nào.

Nói luôn mấy cái mình KHÔNG làm được, để bạn khỏi kỳ vọng sai rồi thất vọng:
- Không phải hoàn {{ cashbackRate }}% giá đơn. Là {{ cashbackRate }}% phần hoa hồng của đơn đó thôi, số tiền nhỏ hơn nhiều — thường vài nghìn tới vài chục nghìn một đơn.
- Không nhanh. Đơn phải Hoàn thành bên Shopee, rồi mình đối soát theo báo cáo Shopee tải về thủ công, thường vài tuần mới vào ví.
- Không phải sản phẩm nào cũng có mã, và mã thì có giới hạn lượt.
- Không chạy trên máy tính, chỉ điện thoại. Và hiện chỉ Shopee thôi.

Còn cái bắt buộc phải nhớ: đăng nhập vào trang trước rồi mới bấm nút mua. Bấm lúc chưa đăng nhập thì đơn đó không quy về tài khoản bạn được, mình cũng không cứu lại được — không phải mình ăn chặn, mà là hệ thống Shopee chỉ ghi nhận ngay tại lúc bấm.

Bạn cứ thử một đơn nhỏ cho biết, thấy không ổn thì thôi, mình không nhắc lần nữa đâu.
TXT,
            ],
            [
                'id' => 'comment-nolink',
                'channel' => 'comment',
                'name' => 'Bình luận — không kèm link (nhóm chặt)',
                'when_to_use' => 'Nhóm cấm link/cấm quảng cáo, hoặc bài của người lạ. Mẫu an toàn nhất: không có URL nào để bị bắt, vẫn trả lời đúng câu hỏi của người ta.',
                'body' => <<<'TXT'
Món này bạn cứ săn mã trước khi bấm đặt nhé, Shopee có loại mã nội bộ dành riêng cho người mua đến từ Facebook/Instagram, tự mở app tìm thì không thấy đâu.
Mình đang dùng một trang miễn phí để dán link sản phẩm ra link đã gắn sẵn mã (chỉ chạy trên điện thoại), bạn cần thì cmt phát mình inbox cho, dán link ở đây loãng bài của chủ thớt.
TXT,
            ],
            [
                'id' => 'comment-link',
                'channel' => 'comment',
                'name' => 'Bình luận — có kèm link, trả lời "mua ở đâu rẻ"',
                'when_to_use' => 'Mẫu dùng nhiều nhất: bài hỏi giá/hỏi mua ở đâu trong nhóm cho phép link. Dán MỘT lần duy nhất dưới bài, trả lời thẳng vào người hỏi.',
                'body' => <<<'TXT'
Giá trên Shopee thì shop nào cũng gần như nhau, ăn thua ở cái mã lúc thanh toán thôi bạn.
Mình hay mở {{ siteUrl }} bằng điện thoại rồi dán link sản phẩm vào, nó trả về link đã gắn sẵn mã nội bộ cho người đến từ Facebook/Instagram — bấm xong nó mở Facebook một nhịp rồi mới sang Shopee, bạn đừng tắt giữa chừng; không phải shop nào cũng có mã và mã có lượt giới hạn, hết thì canh khung {{ restockHoursFbIg }} giờ VN nhé.
TXT,
            ],
            [
                'id' => 'comment-cashback',
                'channel' => 'comment',
                'name' => 'Bình luận — trả lời người hỏi kỹ về hoàn tiền',
                'when_to_use' => 'Khi có người rep lại hỏi "hoàn tiền kiểu gì", "web đó thật không", "bao lâu thì có tiền". Đừng dùng để mở màn — chỉ dùng để trả lời.',
                'needs_cashback' => true,
                'body' => <<<'TXT'
Nói thật cho bạn khỏi kỳ vọng sai: hoàn {{ cashbackRate }}% của khoản hoa hồng tiếp thị mà Shopee trả cho bên mình vì đơn đó, không phải {{ cashbackRate }}% giá trị đơn — ví dụ đơn {{ exampleOrder }}, nếu hoa hồng về khoảng {{ exampleCommission }} thì bạn nhận khoảng {{ exampleCashback }} (mức hoàn có thể được điều chỉnh, số đang áp dụng luôn hiện trên web).
Tiền chỉ vào ví khi đơn đã Hoàn thành bên Shopee và bên mình đối soát theo báo cáo hoa hồng, thường mất vài tuần chứ không có vụ về ngay trong 24h; đủ {{ minWithdrawal }} thì gửi lệnh rút về MoMo/ZaloPay.
Điều kiện bắt buộc: đăng nhập ở {{ siteUrl }} trước rồi mới bấm nút mua — bấm lúc chưa đăng nhập thì đơn không quy về tài khoản nào, sau này không cứu lại được.
TXT,
            ],
        ];
    }
}
