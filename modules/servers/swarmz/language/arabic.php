<?php
/**
 * Swarmz WHMCS Module — client-area strings (Arabic / العربية).
 *
 * Overlay on english.php (the fallback base) — see english.php for the
 * contract. Keep keys in sync across ALL language files (AGENTS.md rule).
 * The panel renders right-to-left for this locale (Helpers::clientDir).
 *
 * @copyright Swarmz Labs Ltd.
 * @license MIT
 */

if (!defined('WHMCS')) {
    die('You cannot access this file directly.');
}

return [
    'workspace_title' => 'مساحة عملك',
    'workspace_ready' => 'جاهزة — عُد إلى المحرّر في أي وقت.',
    'workspace_preparing' => 'قيد التحضير…',
    'provisioning_notice' => 'يجري تجهيز مساحة عملك. إذا استمرّت هذه الرسالة أكثر من بضع دقائق، يُرجى التواصل مع الدعم.',
    'section_your' => '%s الخاصة بك',
    'plan_section' => 'الباقة',
    'free_label' => '%s المجانية',
    'monthly_label' => '%s الشهرية',
    'cloud_label' => '%s السحابية',
    'ai_label' => '%s الذكاء الاصطناعي',
    'extra_label' => '%s الإضافية',
    'cadence_daily' => 'يوميًا',
    'cadence_monthly' => 'شهريًا',
    'cadence_one_time' => 'مرة واحدة',
    'cadence_cycle' => 'لكل دورة',
    'cadence_topup' => 'شحن',
    'not_included' => 'غير مُضمَّنة في هذه الباقة',
    'unlimited' => 'غير محدود',
    'one_time_note' => 'رصيد لمرة واحدة — لا يتجدّد',
    'monthly_note' => 'يتجدّد شهريًا',
    'per_day' => 'يوميًا',
    'resets_midnight' => 'يُعاد الضبط عند 00:00 بتوقيت UTC',
    'up_to_month' => 'حتى %s شهريًا',
    'renews_cycle' => 'يتجدّد مع دورة فوترتك',
    'rolled_over' => '+ %s مُرحَّلة',
    'carry_over' => 'تُرحَّل المبالغ غير المستخدمة لمدة %s شهر.',
    'topup_note' => 'عمليات الشحن المُشتراة — صالحة لمدة 12 شهرًا',
    'topup_used' => 'تم استخدام %s حتى الآن',
    'published_projects' => 'المشاريع المنشورة',
    'of' => 'من',
    'live_now' => 'مباشر الآن',
    'allowed_at_once' => 'المسموح في آنٍ واحد',
    'custom_domains' => 'النطاقات المخصّصة',
    'not_available' => 'غير متاح في هذه الباقة',
    'connected' => 'متّصل',
    'allowed' => 'مسموح',
    'buy_prompt' => 'رصيدك على وشك النفاد؟ اشترِ شحنة في أي وقت — تصل إلى مساحة عملك بمجرّد إتمام الدفع.',
    'buy_button' => 'اشترِ المزيد',
    'modal_title' => 'باقات الشحن',
    'modal_sub' => 'اختر باقة — تُضاف إلى مساحة عملك تلقائيًا بعد الدفع.',
    'order_now' => 'اطلب',
    'price_free' => 'مجاني',
    'chip_onetime' => 'مرة واحدة',
    'chip_monthly' => 'شهري',
    'no_packs' => 'لا توجد باقات متاحة حاليًا.',
    'close' => 'إغلاق',
    'usage_error' => 'تعذّر تحديث الاستخدام الآن:',
    'need_help' => 'تحتاج مساعدة؟',
    'contact_support' => 'تواصل مع الدعم',
    'no_pools' => 'لا تتضمّن هذه الباقة أي رصيد.',
    'updates_fast' => 'يُحدَّث الرصيد خلال ثوانٍ من الشراء.',
    'pack_added' => 'تمت إضافة الشحنة — يجري تطبيق الرصيد على مساحة عملك الآن.',
];
