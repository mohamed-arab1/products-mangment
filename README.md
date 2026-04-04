# mabe3aty_backend

نظام إدارة المبيعات اليومية — Backend (Laravel + MySQL)

## المتطلبات
- PHP 8.2+
- Composer
- MySQL 8 أو MariaDB

## التثبيت

### 1. إنشاء قاعدة البيانات
```bash
mysql -u root -p -e "CREATE DATABASE daily_sales CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### 2. إعداد البيئة
```bash
cp .env.example .env
php artisan key:generate
```

عدّل ملف `.env` وضبط إعدادات MySQL:
```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=daily_sales
DB_USERNAME=root
DB_PASSWORD=كلمة_مرور_قاعدة_البيانات
```

### 3. تشغيل الهجرات والبذور
```bash
php artisan migrate
php artisan db:seed
```

سيتم إنشاء مستخدمين تجريبيين:
- **مدير:** admin@example.com / password
- **بائع:** seller@example.com / password

### 4. تشغيل السيرفر
```bash
php artisan serve
```
الواجهة البرمجية ستكون على: `http://localhost:8000`

---

## واجهة API (جميع المسارات تحت البادئة `/api`)

### المصادقة
| Method | Endpoint | الوصف |
|--------|----------|--------|
| POST | `/api/register` | تسجيل مستخدم جديد (body: name, email, password, password_confirmation, role?) |
| POST | `/api/login` | تسجيل الدخول (body: email, password) |
| POST | `/api/logout` | تسجيل الخروج (يتطلب: Authorization: Bearer {token}) |
| POST | `/api/forgot-password` | طلب رابط استعادة كلمة المرور (body: email) |
| POST | `/api/reset-password` | استعادة كلمة المرور (body: email, token, password, password_confirmation) |

### الفواتير والمبيعات
| Method | Endpoint | الوصف |
|--------|----------|--------|
| GET | `/api/invoices` | قائمة الفواتير (مع فلترة from, to للتاريخ) |
| POST | `/api/invoices` | إنشاء فاتورة (body: sale_date, notes?, items: [{description, quantity, unit_price}]) |
| GET | `/api/invoices/{id}` | تفاصيل فاتورة |
| PUT/PATCH | `/api/invoices/{id}` | تحديث فاتورة |
| DELETE | `/api/invoices/{id}` | حذف فاتورة |
| POST | `/api/invoices/{id}/items` | إضافة بند لفاتورة |
| DELETE | `/api/invoices/{id}/items/{itemId}` | حذف بند من فاتورة |

### الأهداف
| Method | Endpoint | الوصف |
|--------|----------|--------|
| GET | `/api/targets` | قائمة الأهداف |
| POST | `/api/targets` | إنشاء هدف (body: target_amount, period_type: daily|monthly, period_start, user_id?) |
| PUT/PATCH | `/api/targets/{id}` | تحديث هدف |
| DELETE | `/api/targets/{id}` | حذف هدف |

### الإشعارات
| Method | Endpoint | الوصف |
|--------|----------|--------|
| GET | `/api/notifications` | قائمة إشعارات المستخدم |
| POST | `/api/notifications/{id}/read` | تعليم إشعار كمقروء |

### لوحة الإدارة (مدير فقط)
| Method | Endpoint | الوصف |
|--------|----------|--------|
| GET | `/api/admin/dashboard` | إحصائيات يومية وشهرية |
| GET | `/api/admin/sellers` | قائمة البائعين |
| POST | `/api/admin/sellers` | إضافة بائع (body: name, email, password, password_confirmation) |
| GET | `/api/admin/sales-report` | تقرير مبيعات (query: from, to) |

---

## استعادة كلمة المرور (Forgot Password)

1. المستخدم يرسل بريده إلى `POST /api/forgot-password` مع `{"email": "user@example.com"}`.
2. Laravel يرسل بريداً يحتوي رابط استعادة (يتضمن توكن صالح لمدة 60 دقيقة حسب الإعداد الافتراضي).
3. الواجهة الأمامية توجّه المستخدم لصفحة إدخال كلمة المرور الجديدة مع إرسال التوكن في الطلب.
4. الطلب `POST /api/reset-password` مع:
   - `email`
   - `token` (من الرابط)
   - `password`
   - `password_confirmation`

لإرسال البريد فعلياً، عدّل `.env` بإعدادات SMTP (مثل MAIL_MAILER=smtp, MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD). في التطوير يمكن استخدام `MAIL_MAILER=log` وعرض الرابط من ملف الـ log.
# mabe3aty_backend
