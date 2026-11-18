# إعداد Firebase للمشروع

التطبيق يستخدم Cloud Firestore للتحديثات اللحظية وFirebase Cloud Messaging لإشعارات الويب. قاعدة التطبيق الأساسية تظل MySQL. يمكن تجربة الواجهات محليًا قبل إعداد Firebase؛ عند تعطيله تتحدث صفحات المتابعة من Laravel كل 10 ثوانٍ.

## 1. إنشاء المشروع وتطبيق الويب

1. افتح [Firebase Console](https://console.firebase.google.com/) وأنشئ مشروعًا جديدًا.
2. من إعدادات المشروع أضف تطبيق Web بعلامة `</>`، وسمّه مثلًا `Queue Web`.
3. لا تحتاج تفعيل Firebase Hosting؛ موقع Laravel يستضاف على خادم PHP.
4. احتفظ ببيانات إعداد تطبيق الويب: `apiKey` و`authDomain` و`projectId` و`messagingSenderId` و`appId`.

بيانات تطبيق الويب تُستخدم في المتصفح بطبيعتها. المفتاح الخاص بحساب الخدمة مختلف تمامًا ولا يوضع في الواجهة أو المحادثة أو Git.

## 2. Firestore وAuthentication

1. فعّل Cloud Firestore وأنشئ قاعدة البيانات الافتراضية `(default)`، واختر منطقة مناسبة قبل بدء التشغيل.
2. اختر وضع قواعد مغلقة، ثم انشر محتوى [firestore.rules](firestore.rules) من تبويب Rules. لا تفتح القراءة والكتابة للجميع.
3. افتح Authentication وابدأ إعداد الخدمة. التطبيق يستخدم Custom Tokens يصدرها Laravel؛ لا يحتاج تفعيل دخول العملاء بكلمة مرور أو دخول مجهول.
4. أضف دومين الموقع إلى Authorized domains عند الحاجة، وأضف `localhost` صراحة إذا كنت تختبر عليه.

العميل يقرأ مستند تذكرته فقط، وشاشة الشباك تقرأ مستند شباكها فقط. الشاشة العامة تقرأ `branch_displays/{branchId}` بصلاحية `branch_display_id` خاصة بالفرع؛ أعد نشر ملف القواعد بعد التحديث. المستند يضم الشبابيك والأرقام والحالات دون أسماء العملاء أو الموبايل. الكتابة من الخادم. صلاحية قراءة Firebase المصدرة تنتهي بعد ساعة ويجددها الموقع أثناء فتح الصفحة. إلغاء رابط شاشة يمنع إصدار صلاحيات جديدة له؛ صلاحية سبق إصدارها قد تستمر حتى انتهائها.

## 3. إعداد FCM

1. من Project settings ثم Cloud Messaging افتح إعدادات Web Push.
2. أنشئ Web Push certificate key pair وانسخ **المفتاح العام VAPID**.
3. تأكد من تفعيل Firebase Cloud Messaging API HTTP v1 للمشروع.
4. الموقع يحتاج HTTPS، باستثناء الاختبارات المحلية التي يسمح بها المتصفح على localhost. عنوان IP عادي على شبكة المكتب ليس بديلًا عن HTTPS عند تجربة الإشعارات من الموبايل.

## 4. حساب الخدمة الخاص بالخادم

1. من Project settings ثم Service accounts أنشئ مفتاح JSON خاصًا بالخادم، أو استخدم حساب خدمة مخصصًا بصلاحيات مناسبة لـFirestore وإرسال FCM.
2. احفظ الملف محليًا بهذا الاسم:

   `storage/app/private/firebase-service-account.json`

3. هذا المسار مستثنى من Git. لا تنقل الملف إلى `public` ولا ترفعه ضمن ملفات الواجهة.
4. في الإنتاج استخدم مسارًا خاصًا خارج مجلد الويب وصلاحيات قراءة لحساب تشغيل الخادم فقط. يجب أن يطابق `project_id` مشروع تطبيق الويب.

المشروع يستخدم Google Auth مع واجهات Firestore REST وFCM HTTP v1، فلا يحتاج إضافة gRPC لتشغيل الربط.

## 5. إعداد ملف .env

أضف القيم الفعلية في `.env` على جهازك فقط:

```dotenv
FIREBASE_ENABLED=true
FIREBASE_PROJECT_ID=your-project-id
FIREBASE_API_KEY=your-web-api-key
FIREBASE_AUTH_DOMAIN=your-project-id.firebaseapp.com
FIREBASE_MESSAGING_SENDER_ID=your-sender-id
FIREBASE_APP_ID=your-web-app-id
FIREBASE_VAPID_KEY=your-public-vapid-key
# اتركه غير معرّف إذا وضعت الملف في المسار الافتراضي المذكور أعلاه.
# FIREBASE_CREDENTIALS="D:/private/firebase-service-account.json"
```

ثم نفذ:

```powershell
php artisan config:clear
npm.cmd run build
php artisan queue:restart
php artisan queue:publish-pending
```

أمر البناء ينشئ Service Worker من المصدر الموجود في `resources/js/firebase-messaging-sw.js`. لا تعدل الملف المولّد في `public` مباشرة.

شغّل عامل الخلفية والجدولة في نافذتين أو كخدمتين دائمتين على الاستضافة:

```powershell
php artisan queue:work --tries=5 --timeout=120
php artisan schedule:work
```

اضبط `retry_after` لاتصال الطابور ليكون أكبر من مهلة الوظيفة؛ المقترح 180 ثانية مقابل `timeout=120`. انشر قواعد Firestore قبل إتاحة الموقع للعملاء.

## 6. اختبار الربط الفعلي

- افتح صفحة الفرع واحجز تذكرة، ثم وافق على تفعيل الإشعارات.
- افتح شاشة الموظف وشاشة الشباك واضغط التالي.
- تحقق أن شاشة العميل تعرض الشباك الصحيح وأن الرقم يظهر على شاشة الشباك.
- جرّب الإشعار والصفحة مفتوحة ثم في الخلفية على جهاز مدعوم.
- على iPhone مدعوم، أضف الموقع للشاشة الرئيسية وافتحه من الأيقونة قبل تفعيل الإشعارات.
- جرّب رفض الإذن: يجب استمرار الحجز والمتابعة.
- جرّب انقطاع الاتصال ثم عودته واسترجاع الحالة الصحيحة.
- تحقق من أن مستخدمًا لا يستطيع قراءة مستند تذكرة غيره أو الكتابة لحالة الطابور.

حتى إتمام هذه التجربة لا يعتبر وصول الإشعارات الفعلية أو نشر قواعد الحساب متحققًا. FCM لا يضمن وصول كل إشعار؛ متابعة الصفحة وشاشات الفرع تظل ضرورية.

## 7. التشغيل والتكلفة

- راقب Firestore reads/writes وحدود الاستخدام واضبط تنبيهات الميزانية من حساب Google Cloud حسب الخطة.
- نشر حالة «أمامك» لكل تذكرة منتظرة يستهلك كتابات إضافية؛ يلزم قياس الاستخدام قبل تشغيل فرع مزدحم.
- يحتفظ Outbox بالأحداث غير المنشورة ويعيد أمر الجدولة إرسالها بعد الفشل.
- تسليم الإشعار قابل لإعادة المحاولة؛ تعطل العملية بعد قبول FCM وقبل تسجيل النجاح قد يكرر الإرسال. يستخدم الإشعار tag للحد من التكرار المرئي، ولا ندعي ضمان التسليم مرة واحدة.
- الناشر الحالي يستخدم قفل الفرع أثناء المزامنة لمنع نشر حالة قديمة؛ مناسب لبداية التشغيل ويحتاج اختبار حمل قبل استخدام واسع.
- أعد تشغيل العمال بعد أي تعديل على الكود أو إعدادات Firebase.

## مراجع

- [بدء FCM للويب](https://firebase.google.com/docs/cloud-messaging/web/get-started)
- [استقبال الرسائل في الويب](https://firebase.google.com/docs/cloud-messaging/web/receive-messages)
- [تحديثات Firestore](https://firebase.google.com/docs/firestore/query-data/listen)
- [إنشاء Custom Tokens](https://firebase.google.com/docs/auth/admin/create-custom-tokens)
- [إعداد الخادم وحساب الخدمة](https://firebase.google.com/docs/admin/setup)
