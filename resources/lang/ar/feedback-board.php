<?php

return [
    'dir' => 'rtl',
    'launcher' => 'الإبلاغ عن مشكلة',
    'form' => ['title' => 'العنوان', 'description' => 'صف المشكلة', 'submit' => 'إرسال'],
    'type' => ['bug' => 'خلل', 'feature' => 'طلب ميزة', 'question' => 'سؤال', 'other' => 'أخرى'],
    'status' => ['new' => 'جديد', 'in_review' => 'قيد المراجعة', 'in_progress' => 'قيد العمل', 'resolved' => 'تم الحل', 'closed' => 'مغلق', 'declined' => 'مرفوض'],
    'priority' => ['low' => 'منخفضة', 'medium' => 'متوسطة', 'high' => 'عالية', 'urgent' => 'عاجلة'],
    'mail' => [
        'new_subject' => 'ملاحظة جديدة: :title', 'new_line' => 'تم رفع ملاحظة جديدة.',
        'reply_subject' => 'رد جديد على ملاحظتك',
        'resolved_subject' => 'تم الحل: :title', 'resolved_line' => 'تم حل ملاحظتك.',
    ],
    'board' => [
        'my_reports' => 'بلاغاتي', 'reply' => 'رد', 'internal_note' => 'ملاحظة داخلية',
        'title' => 'لوحة الملاحظات', 'thread' => 'النقاش', 'activity' => 'النشاط', 'attachments' => 'المرفقات',
        'assign_me' => 'إسناد لي', 'unassign' => 'إلغاء الإسناد',
    ],
    'destinations' => [
        'delivery_failed' => 'فشل إرسال الملاحظة',
    ],
];
