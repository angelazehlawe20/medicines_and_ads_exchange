<?php

return [
    'unauthorized' => 'غير مصرح لك بالوصول، يرجى تسجيل الدخول أولاً',
    'forbidden' => 'ليس لديك الصلاحيات الكافية للقيام بهذا الإجراء',
    'dashboard' => 'تم جلب إحصائيات لوحة التحكم بنجاح',

    'error_my_status' => 'لا يمكنك تغيير حالة حسابك الشخصي من هنا',
    'status_already_set' => 'هذا الحساب :status بالفعل',
    'status_updated' => 'تم :status حساب المستخدم بنجاح',
    'active' => 'نشط',
    'suspended' => 'معلق',
    'activated' => 'تفعيل',

    'users_fetched' => 'تم جلب كافة المستخدمين من نوع :role بنجاح',
    'no_users_found' => 'لا يوجد مستخدمين حالياً من نوع :role',
    'add_user' => 'تم إنشاء المستخدم بنجاح',
    'creation_failed' => 'فشل إنشاء الحساب: ',
    'delete_own_account' => 'لا يمكنك حذف حسابك الشخصي، يرجى التواصل مع الإدارة العليا',
    'delete_success' => 'تم حذف المستخدم بنجاح',
    'delete_failed' => 'فشل في حذف المستخدم، يرجى المحاولة لاحقاً',

    'get_ads' => 'تم جلب إعلانات التبادل للمراجعة',
    'search_results' => 'تم العثور على :count نتيجة مطابقة',
    'no_results' => 'لا توجد نتائج مطابقة للبحث عن ":query"',

    'roles' => [
        'citizen' => 'مواطن',
        'pharmacy' => 'صيدلية',
        'specialist' => 'مختص',
        'delivery' => 'عامل توصيل',
        'admin' => 'مسؤول',
    ],

    'governorates' => [
        'Damascus' => 'دمشق',
        'Aleppo' => 'حلب',
        'Homs' => 'حمص',
        'Hama' => 'حماة',
        'Lattakia' => 'اللاذقية',
        'Tartous' => 'طرطوس',
        'Daraa' => 'درعا',
        'Deir ez-Zor' => 'دير الزور',
        'Hasakah' => 'الحسكة',
        'Raqqa' => 'الرقة',
        'Suwayda' => 'السويداء',
        'Quneitra' => 'القنيطرة',
        'Rif Dimashq' => 'ريف دمشق',
    ],
];
