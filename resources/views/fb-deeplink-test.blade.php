<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Test deep-link Facebook</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; padding: 16px; background: #f5f5f5; color: #222; }
        h1 { font-size: 18px; }
        p.note { font-size: 13px; color: #666; line-height: 1.5; }
        a.try { display: block; background: #1877f2; color: #fff; text-decoration: none; padding: 14px 16px;
                border-radius: 10px; margin-bottom: 10px; font-weight: 600; }
        a.try small { display: block; font-weight: 400; opacity: .85; font-size: 11px; margin-top: 4px;
                      word-break: break-all; }
        a.web { background: #444; }
    </style>
</head>
<body>
    <h1>Test deep-link tới comment Facebook</h1>
    <p class="note">
        Mở trang này <strong>trên điện thoại</strong>, bấm lần lượt từng nút bên dưới.
        Ghi lại nút nào mở đúng app Facebook <strong>và cuộn tới đúng comment</strong>.
        Sau mỗi lần bấm thì quay lại trang này để thử nút tiếp theo.
    </p>

    @foreach ($candidates as $i => $c)
        <a class="try {{ str_starts_with($c['url'], 'https') ? 'web' : '' }}" href="{{ $c['url'] }}">
            {{ $i + 1 }}. {{ $c['label'] }}
            <small>{{ $c['url'] }}</small>
        </a>
    @endforeach

    <p class="note">Comment đang test: <code>{{ $commentFullId }}</code></p>
</body>
</html>
