<?php

namespace App\Services\Finance;

use App\Models\Organization;

/**
 * Manual customer payment-reminder TEMPLATES (French and Arabic). Pure text: nothing is ever sent by the system - an operator
 * previews the rendered message, copies it, or records a follow-up (existing follow-up module) carrying it. There is no automatic
 * external sending and no setting that enables it.
 *
 * Built-in defaults exist for every organization; an organization may override the wording per template key
 * (organizations.settings.customer_reminders.templates.{key}.{fr|ar}). Only the placeholders below are substituted
 * (no code, no HTML); unknown placeholders are left as typed so a typo is visible, never silently dropped.
 */
final class CustomerReminderTemplates
{
    public const KEYS = ['courtesy', 'overdue', 'final'];

    public const PLACEHOLDERS = ['customer', 'organization', 'amount', 'currency', 'due_date', 'oldest_days'];

    private const DEFAULTS = [
        'courtesy' => [
            'label' => ['fr' => 'Rappel courtois', 'ar' => 'تذكير ودّي'],
            'fr' => "Bonjour {customer},\nNous vous rappelons aimablement que le solde de {amount} {currency} reste à régler auprès de {organization}. Si le règlement a déjà été effectué, merci de ne pas tenir compte de ce message.",
            'ar' => "مرحباً {customer}،\nنذكّركم بلطف بأن الرصيد المستحق وقدره {amount} {currency} لا يزال بانتظار السداد لدى {organization}. إذا تم الدفع فيرجى تجاهل هذه الرسالة.",
        ],
        'overdue' => [
            'label' => ['fr' => 'Retard de paiement', 'ar' => 'تأخر في السداد'],
            'fr' => "Bonjour {customer},\nSauf erreur de notre part, {amount} {currency} sont échus depuis {oldest_days} jours (échéance du {due_date}). Merci de régulariser ce règlement ou de nous indiquer la date de paiement prévue.",
            'ar' => "مرحباً {customer}،\nإن لم يكن هناك خطأ من جانبنا، فإن مبلغ {amount} {currency} متأخر منذ {oldest_days} يوماً (تاريخ الاستحقاق {due_date}). نرجو تسوية المبلغ أو إفادتنا بتاريخ السداد المتوقع.",
        ],
        'final' => [
            'label' => ['fr' => 'Dernier rappel', 'ar' => 'تذكير أخير'],
            'fr' => "Bonjour {customer},\nMalgré nos précédents rappels, {amount} {currency} restent impayés (échéance du {due_date}). Sans règlement ou accord de votre part sous peu, nous serons contraints de suspendre les nouvelles livraisons.",
            'ar' => "مرحباً {customer}،\nرغم تذكيراتنا السابقة ما زال مبلغ {amount} {currency} غير مسدد (تاريخ الاستحقاق {due_date}). وفي غياب السداد أو الاتفاق معنا قريباً سنضطر إلى إيقاف التسليمات الجديدة.",
        ],
    ];

    /** @return array<string, array{label: array{fr:string,ar:string}, fr:string, ar:string, customized: bool}> */
    public function forOrganization(Organization $organization): array
    {
        $overrides = (array) data_get($organization->settings, 'customer_reminders.templates', []);
        $out = [];
        foreach (self::KEYS as $key) {
            $default = self::DEFAULTS[$key];
            $custom = (array) ($overrides[$key] ?? []);
            $out[$key] = [
                'label' => $default['label'],
                'fr' => is_string($custom['fr'] ?? null) && trim($custom['fr']) !== '' ? $custom['fr'] : $default['fr'],
                'ar' => is_string($custom['ar'] ?? null) && trim($custom['ar']) !== '' ? $custom['ar'] : $default['ar'],
                'customized' => isset($custom['fr']) || isset($custom['ar']),
            ];
        }

        return $out;
    }

    /** @param array<string, scalar|null> $vars */
    public function render(Organization $organization, string $key, string $language, array $vars): string
    {
        $language = $language === 'ar' ? 'ar' : 'fr';
        $template = $this->forOrganization($organization)[$key] ?? null;
        abort_if($template === null, 404);
        $replace = [];
        foreach (self::PLACEHOLDERS as $name) {
            $replace['{'.$name.'}'] = (string) ($vars[$name] ?? '—');
        }

        return strtr($template[$language], $replace);
    }

    /** Persist the wording of one template (both languages); the caller authorizes and audits. @return array<string,mixed> the stored overrides */
    public function save(Organization $organization, string $key, string $fr, string $ar): array
    {
        abort_unless(in_array($key, self::KEYS, true), 404);
        $settings = (array) $organization->settings;
        $settings['customer_reminders']['templates'][$key] = ['fr' => trim($fr), 'ar' => trim($ar)];
        $organization->settings = $settings;
        $organization->save();

        return $settings['customer_reminders']['templates'];
    }
}
