# Shopee Affiliate Platform

Nền tảng tạo affiliate link Shopee, xây dựng bằng **Laravel 13 + Vue 3 (Inertia.js)**.

---

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | Laravel 13, PHP 8.3+, SQLite (dev) |
| Frontend | Vue 3, Inertia.js, Pinia 3, Tailwind CSS 4 |
| Build | Vite 8 |
| Auth | Laravel Sanctum + Spatie Permission (RBAC) |
| Testing | PHPUnit 12.5 |

---

## Commands

### Development

```bash
composer dev            # Start Laravel + Queue + Vite concurrently (recommended)
php artisan serve       # Only HTTP server (port 8000)
npm run dev             # Only Vite HMR dev server
composer setup          # First-time: install deps, key:generate, migrate, build
```

### Database

```bash
php artisan migrate
php artisan migrate:fresh --seed
php artisan db:seed --class=AdminUserSeeder
php artisan db:seed --class=ApiConfigSeeder
php artisan tinker
```

### Testing

```bash
composer test                           # All tests
php artisan test                        # Verbose
php artisan test --filter=ClassName
php artisan test --coverage
```

### Code Quality

```bash
./vendor/bin/pint                       # Format all PHP files
./vendor/bin/pint --test                # Dry-run check only
./vendor/bin/pint app/Http/Controllers  # Format specific path
```

### Build

```bash
npm run build           # Production Vite build → public/build/
```

---

## Architecture

### Backend Layout

```
app/
├── Http/
│   ├── Controllers/
│   │   ├── AffiliateController.php       ← scan URL + history
│   │   ├── Admin/
│   │   │   ├── DashboardController.php   ← stats aggregation
│   │   │   ├── OrderController.php       ← commission CRUD
│   │   │   └── ApiConfigController.php   ← API credentials
│   │   └── Auth/                         ← Login, Register, OTP
│   └── Middleware/
│       └── AdminMiddleware.php
├── Models/
│   ├── User.php            HasRoles (Spatie), wallet_balance
│   ├── AffiliateLink.php   original_url → affiliate_url, platform, product_*
│   ├── Commission.php      amount, status (pending/approved/paid)
│   ├── Voucher.php         code, discount_type, discount_value
│   └── ApiConfig.php       provider, credentials (JSON), is_active
└── Services/               ← all business logic lives here
    ├── ShopeeApiService.php          (155 lines) HMAC-SHA256 auth
    ├── AffiliateScanOrchestrator.php (119 lines) full scan workflow
    ├── UrlValidationService.php      (62 lines)  platform detection
    └── AccessTradeService.php        (59 lines)  link generation
```

### Frontend Layout

```
resources/js/
├── app.js                  ← Vue + Pinia + Inertia bootstrap
├── Pages/
│   ├── Home.vue            ← URL scanner input (public)
│   ├── History.vue         ← scan history (auth)
│   ├── Result.vue          ← scan result display
│   ├── Auth/               ← Login.vue, Register.vue
│   └── Admin/              ← Dashboard.vue, Orders.vue, ApiConfig.vue
├── Components/
│   ├── BottomNav.vue
│   ├── CouponTicket.vue
│   ├── LoadingSkeleton.vue
│   └── SavingsSummary.vue
├── Layouts/
│   ├── AppLayout.vue       ← main user layout
│   └── AdminLayout.vue     ← admin panel layout
└── Stores/
    ├── useAffiliateStore.js
    ├── useAuthStore.js
    └── useAdminStore.js
```

### Routes

| Path | Middleware | Handler |
|------|-----------|---------|
| `GET /` | — | Home scanner |
| `POST /affiliate/scan` | throttle:affiliate-scan | Scan URL → affiliate link |
| `GET /history` | auth | User scan history |
| `GET /admin/dashboard` | auth, admin | Admin stats |
| `GET/PATCH /admin/orders` | auth, admin | Commission management |
| `GET/POST /admin/api-config` | auth, admin | API credentials |
| `/login`, `/register` | guest | Auth pages |
| `/auth/otp/*` | guest | OTP verification |

---

## Database Schema

| Table | Key Columns |
|-------|------------|
| `users` | name, email, phone, otp_code, otp_expires_at, wallet_balance |
| `affiliate_links` | user_id, original_url, affiliate_url, platform, product_name, product_image, product_price |
| `vouchers` | affiliate_link_id, code, discount_type, discount_value, expires_at |
| `commissions` | user_id, affiliate_link_id, amount, status (pending/approved/paid) |
| `api_configs` | provider (shopee/accesstrade), credentials (JSON), is_active |

---

## Key Conventions

- **Services handle all logic** — controllers only call services, no business logic in controllers
- **Inertia for all pages** — no separate JSON API; use `Inertia::render()` and `router.post()` on frontend
- **Pinia stores** — one store per domain (affiliate, auth, admin); no Vuex
- **Tailwind utility-first** — no custom CSS except `resources/css/app.css` root entry
- **PHP PSR-12** via Laravel Pint — run pint before committing PHP files
- **Migrations reversible** — always implement `down()` method
- **Sanctum + Spatie** — user roles: `user` (default), `admin`

---

## Environment Variables

```env
# Shopee API (stored in api_configs table, NOT .env in production)
SHOPEE_APP_ID=
SHOPEE_APP_SECRET=
SHOPEE_PARTNER_ID=

# AccessTrade
ACCESS_TRADE_API_KEY=

# Database (local = sqlite, prod = mysql)
DB_CONNECTION=sqlite

# App
APP_URL=http://localhost:8000
APP_ENV=local
APP_DEBUG=true
```

Admin credentials: seeded via `database/seeders/AdminUserSeeder.php`.

---

## External APIs

### Shopee API
- Auth: HMAC-SHA256 signature with timestamp + partner_id + path
- Credentials managed via `ApiConfig` model (admin can update via `/admin/api-config`)
- Service: `app/Services/ShopeeApiService.php`

### AccessTrade API
- Generates affiliate commission links
- Service: `app/Services/AccessTradeService.php`

### Supported Platforms (UrlValidationService)
- Shopee, Lazada, Tiki, TikTok Shop
