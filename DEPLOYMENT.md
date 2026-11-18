# تجهيز النشر والتشغيل

هذا دليل تجهيز قابل للتطبيق بعد اختيار الاستضافة والدومين. لم يتم نشر المشروع على استضافة فعلية أو إعداد شهادة HTTPS خارجية بعد.

## المتطلبات

- PHP 8.4 متوافق مع ملفات Composer المقفلة، وامتدادات Laravel وMySQL وOpenSSL وIntl وMbstring وXML وFileinfo وCurl وZip.
- MySQL وقاعدة ومستخدم خاصان بالمشروع، وNode.js لبناء الأصول.
- Web server يوجّه الدومين إلى مجلد `public` فقط، مع HTTPS.
- عامل طابور مستمر وScheduler كل دقيقة، ومشروع Firebase بإعداداته وقواعده المنشورة.
- مكان خاص للنسخ، وبرنامج mysqldump وصلاحيات إنشاء نسخة متسقة. يلزم تحديد تخزين نسخة خارج الخادم وسياسة حفظها.

## الإعداد

1. انسخ `.env.example` إلى `.env` وأدخل القيم الحقيقية على الخادم فقط.
2. اضبط `APP_ENV=production` و`APP_DEBUG=false` و`APP_URL=https://your-domain` و`SESSION_SECURE_COOKIE=true`.
3. اضبط MySQL وFirebase وفق [دليل Firebase](FIREBASE_SETUP.md). احفظ حساب الخدمة خارج public بصلاحيات قراءة لحساب التطبيق فقط.
4. أبقِ `QUEUE_CONNECTION=database` و`DB_QUEUE_RETRY_AFTER=180`؛ مهلة العامل 120 ثانية أقل من مهلة إعادة إتاحة الوظيفة.
5. سياسة التذاكر المختارة `QUEUE_RETENTION_DAYS=365`. راجع `php artisan queue:prune --dry-run` قبل أول تنظيف لبيانات مستوردة.

## بناء الإصدار

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan key:generate # للمشروع الجديد فقط؛ احتفظ بالمفتاح الحالي عند التحديث
php artisan migrate --force
php artisan filament:assets
php artisan optimize
php artisan queue:restart
php artisan schedule:interrupt
```

احتفظ بنفس APP_KEY عند تحديث تثبيت قائم. مجلدا `storage` و`bootstrap/cache` يحتاجان صلاحية كتابة لحساب التطبيق. لا تشغّل DemoSeeder في الإنتاج.

المسار `public/firebase-messaging-sw.js` ناتج عن البناء ويجب نشره مع أصول `public/build`. اضبط تقديمه بذاكرة تخزين مؤقت قصيرة أو `Cache-Control: no-cache` حتى يتحدث عامل الإشعارات. لا تكشف `.env` أو `.git` أو storage الخاص.

## مثال عامل مستمر على Linux

إعداد Supervisor نموذجي، مع تغيير مسار المشروع وPHP والمستخدم إلى قيم استضافتك:

```ini
[program:queue-worker]
process_name=%(program_name)s_%(process_num)02d
command=/usr/bin/php8.4 /var/www/queue/current/artisan queue:work --sleep=3 --tries=5 --timeout=120 --max-time=3600
directory=/var/www/queue/current
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/queue/current/storage/logs/worker.log
stopwaitsecs=180
```

جدولة Linux تحت مستخدم التطبيق:

```cron
* * * * * cd /var/www/queue/current && /usr/bin/php8.4 artisan schedule:run >> storage/logs/scheduler.log 2>&1
```

على Windows المحلي استخدم [start-dev.ps1](scripts/start-dev.ps1) و[stop-dev.ps1](scripts/stop-dev.ps1). هذه أدوات تطوير ولا تضبط خدمة إنتاج أو تشغيلًا عند إقلاع الجهاز.

## فحص قبل إتاحة العملاء

- إنشاء مسؤول إنتاج موثوق وفروع وشبابيك وموظفين، وإزالة اعتماد التشغيل على حسابات التجربة.
- تجربة الحجز والنداء والخدمة والغياب والاستراحة وتغيير الوردية من أجهزة الفرع الفعلية.
- تجربة QR بالكاميرا وWeb Push على Android وiPhone مدعوم، بما في ذلك الإذن المرفوض والصفحة في الخلفية.
- التأكد من عزل تذاكر Firebase وقواعد منع الكتابة من المتصفح.
- مراجعة `/admin/system-status` وتحقق نبض العامل والجدولة واستمرار تقدم نشر الأحداث.
- إنشاء نسخة واختبار استعادتها في قاعدة مستقلة، وضبط التخزين الخارجي والوصول وسياسة نسخ الملفات.
- اختبار الحمل المتوقع للفروع ومراقبة قراءات وكتابات Firestore وتكلفته.

## التعامل مع الأعطال

- أرقام أو شاشات قديمة: افحص حالة الاتصال ثم عدد Outbox المعلق ونبض العامل، وأعد `queue:publish-pending` بعد إصلاح سبب الاتصال.
- إشعارات لا تصل: افحص HTTPS وإذن المتصفح ودعم الجهاز وVAPID وService Worker وإعداد FCM. ظهور تحديث الصفحة لا يثبت وصول Push.
- وظائف فشلت: استخدم `queue:failed` وشخّص السبب قبل `queue:retry all`. لا تعرض محتوى payload أو المفاتيح في شاشة عامة.
- تحديث كود أو إعداد Firebase: أعد تشغيل العمال وحدّث config cache. Supervisor يعيد تشغيل العامل بعد خروجه من `queue:restart`.
- التشغيل التلقائي يحتاج Worker وScheduler مستمرين؛ استعادة المهل تعمل كل 5 ثوانٍ، ولذلك قد يبقى `schedule:run` نشطًا حتى نهاية الدقيقة. نفّذ `schedule:interrupt` بعد النشر لتجديد مهام الثواني بالكود الجديد. اختبر مهلة حضور فعلية وإغلاق صفحة الموظف قبل تفعيل التلقائي في الفرع.
- استعادة: أوقف استقبال الحجز والعمال، واحتفظ بنسخة للحالة الحالية، واستعد أولًا إلى قاعدة مستقلة للتحقق. تغيير اتصال الإنتاج واستئناف الخدمة خطوة تشغيل صريحة على الخادم المقصود، ولم تُنفذ هنا.
- احتفظ بإصدار الكود السابق؛ الرجوع للكود لا يعني إسقاط الجداول أو تشغيل migrate:rollback آليًا.

النسخ المحلية واختبارات الاستعادة لا تغني عن إعداد النسخ الخارجية ومراقبة فشلها على الاستضافة الفعلية.
