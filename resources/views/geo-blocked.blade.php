<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Chưa phục vụ khu vực của bạn</title>
    <style nonce="{{ request()->attributes->get('csp_nonce') }}">
        body { font-family: system-ui, sans-serif; background: #fff7f2; color: #222; margin: 0; display: grid; place-items: center; min-height: 100vh; }
        main { max-width: 26rem; padding: 2rem 1.5rem; text-align: center; }
        h1 { font-size: 1.25rem; margin: 0 0 .75rem; }
        p { line-height: 1.6; color: #555; }
        small, small a { color: #999; }
    </style>
</head>
<body>
    <main>
        <h1>Trang chỉ phục vụ khách ở Việt Nam và Nhật Bản</h1>
        <p>Nếu bạn đang ở Việt Nam, hãy tắt VPN rồi tải lại trang.</p>
        {{-- Giấy phép CC BY 4.0 của DB-IP Lite bắt ghi nguồn ở trang dùng kết quả tra — xem GeoIpDatabase. --}}
        <small><a href="https://db-ip.com">IP Geolocation by DB-IP</a></small>
    </main>
</body>
</html>
