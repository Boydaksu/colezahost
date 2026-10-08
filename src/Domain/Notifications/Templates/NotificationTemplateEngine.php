<?php

declare(strict_types=1);

namespace Coleza\Domain\Notifications\Templates;

use InvalidArgumentException;

final class NotificationTemplateEngine
{
    /**
     * @var array<string, array{subject_en: string, subject_tr: string, body_en: string, body_tr: string}>
     */
    private array $templates = [];

    public function __construct()
    {
        $this->registerDefaultTemplates();
    }

    /**
     * @param string $templateKey
     * @param array<string, mixed> $data
     * @param string $locale
     * @return array{subject: string, html: string, text: string}
     */
    public function render(string $templateKey, array $data, string $locale = 'en'): array
    {
        $lang = strtolower($locale) === 'tr' ? 'tr' : 'en';

        if (!isset($this->templates[$templateKey])) {
            throw new InvalidArgumentException("Notification template '{$templateKey}' not found.");
        }

        $def = $this->templates[$templateKey];
        $subjectTemplate = $lang === 'tr' ? $def['subject_tr'] : $def['subject_en'];
        $bodyTemplate = $lang === 'tr' ? $def['body_tr'] : $def['body_en'];

        $subject = $this->interpolateString($subjectTemplate, $data, false);
        $contentHtml = $this->interpolateString($bodyTemplate, $data, true);
        $contentText = $this->interpolateString($bodyTemplate, $data, false);

        $brandName = (string) ($data['brand_name'] ?? 'Coleza Host');
        $actionUrl = isset($data['action_url']) ? (string) $data['action_url'] : null;
        $actionText = isset($data['action_text']) ? (string) $data['action_text'] : ($lang === 'tr' ? 'Detayları Görüntüle' : 'View Details');

        $fullHtml = $this->wrapEmailLayout($contentHtml, $subject, $brandName, $actionUrl, $actionText, $lang);

        return [
            'subject' => $subject,
            'html' => $fullHtml,
            'text' => strip_tags($contentText) . ($actionUrl ? "\n\n{$actionText}: {$actionUrl}" : ''),
        ];
    }

    public function registerTemplate(
        string $key,
        string $subjectEn,
        string $subjectTr,
        string $bodyEn,
        string $bodyTr
    ): void {
        $this->templates[$key] = [
            'subject_en' => $subjectEn,
            'subject_tr' => $subjectTr,
            'body_en' => $bodyEn,
            'body_tr' => $bodyTr,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function interpolateString(string $template, array $data, bool $escapeHtml): string
    {
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function ($matches) use ($data, $escapeHtml) {
            $key = $matches[1];
            $val = $data[$key] ?? '';
            $str = is_scalar($val) ? (string) $val : '';
            return $escapeHtml ? htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $str;
        }, $template) ?? $template;
    }

    private function wrapEmailLayout(
        string $contentHtml,
        string $title,
        string $brandName,
        ?string $actionUrl,
        string $actionText,
        string $lang
    ): string {
        $footerText = $lang === 'tr'
            ? "Bu e-posta Coleza Host bildirim altyapısı tarafından otomatik olarak gönderilmiştir."
            : "This email was automatically generated and sent by the Coleza Host notification engine.";

        $btnHtml = '';
        if ($actionUrl !== null && $actionUrl !== '') {
            $eUrl = htmlspecialchars($actionUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $eBtn = htmlspecialchars($actionText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $btnHtml = <<<HTML
            <div style="text-align: center; margin: 28px 0;">
                <a href="{$eUrl}" style="background-color: #2563eb; color: #ffffff; text-decoration: none; padding: 12px 24px; border-radius: 6px; font-weight: 600; display: inline-block;">{$eBtn}</a>
            </div>
HTML;
        }

        $eBrand = htmlspecialchars($brandName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $eTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="{$lang}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$eTitle}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #1e293b; line-height: 1.6;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f8fafc; padding: 32px 0;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 8px; border: 1px solid #e2e8f0; overflow: hidden; max-width: 90%;">
                    <!-- Header -->
                    <tr>
                        <td style="background-color: #0f172a; padding: 24px 32px; text-align: left;">
                            <span style="font-size: 20px; font-weight: 700; color: #ffffff; letter-spacing: -0.5px;">{$eBrand}</span>
                        </td>
                    </tr>
                    <!-- Content -->
                    <tr>
                        <td style="padding: 32px;">
                            <div style="font-size: 15px; color: #334155;">
                                {$contentHtml}
                            </div>
                            {$btnHtml}
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f1f5f9; padding: 20px 32px; text-align: center; font-size: 12px; color: #64748b; border-top: 1px solid #e2e8f0;">
                            <div>{$footerText}</div>
                            <div style="margin-top: 4px;">&copy; {$eBrand}. All rights reserved.</div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
    }

    private function registerDefaultTemplates(): void
    {
        $this->templates['order_created'] = [
            'subject_en' => 'Order Confirmation: {{ order_number }}',
            'subject_tr' => 'Sipariş Onayı: {{ order_number }}',
            'body_en' => '<p>Hello {{ customer_name }},</p><p>Thank you for your order! Your order <strong>{{ order_number }}</strong> has been received and is currently being processed.</p><p>Total Amount: <strong>{{ total_formatted }}</strong></p>',
            'body_tr' => '<p>Merhaba {{ customer_name }},</p><p>Siparişiniz için teşekkür ederiz! <strong>{{ order_number }}</strong> numaralı siparişiniz başarıyla alınmış olup işleme konulmuştur.</p><p>Toplam Tutar: <strong>{{ total_formatted }}</strong></p>',
        ];

        $this->templates['invoice_created'] = [
            'subject_en' => 'New Invoice Issued: {{ invoice_number }}',
            'subject_tr' => 'Yeni Fatura Düzenlendi: {{ invoice_number }}',
            'body_en' => '<p>Dear {{ customer_name }},</p><p>A new invoice <strong>{{ invoice_number }}</strong> has been generated for your account.</p><p>Amount Due: <strong>{{ total_formatted }}</strong><br>Due Date: <strong>{{ due_date }}</strong></p>',
            'body_tr' => '<p>Sayın {{ customer_name }},</p><p>Hesabınız için <strong>{{ invoice_number }}</strong> numaralı yeni bir fatura düzenlenmiştir.</p><p>Ödenecek Tutar: <strong>{{ total_formatted }}</strong><br>Son Ödeme Tarihi: <strong>{{ due_date }}</strong></p>',
        ];

        $this->templates['payment_received'] = [
            'subject_en' => 'Payment Receipt: {{ invoice_number }}',
            'subject_tr' => 'Ödeme Alındı Makbuzu: {{ invoice_number }}',
            'body_en' => '<p>Hello {{ customer_name }},</p><p>We have successfully received your payment of <strong>{{ amount_formatted }}</strong> for invoice <strong>{{ invoice_number }}</strong>.</p><p>Thank you for doing business with us!</p>',
            'body_tr' => '<p>Merhaba {{ customer_name }},</p><p><strong>{{ invoice_number }}</strong> numaralı faturanız için <strong>{{ amount_formatted }}</strong> tutarındaki ödemeniz başarıyla tahsil edilmiştir.</p><p>Bizi tercih ettiğiniz için teşekkür ederiz!</p>',
        ];

        $this->templates['service_activated'] = [
            'subject_en' => 'Service Activated: {{ service_name }}',
            'subject_tr' => 'Hizmetiniz Aktifleştirildi: {{ service_name }}',
            'body_en' => '<p>Hello {{ customer_name }},</p><p>Your service <strong>{{ service_name }}</strong> (Domain/Host: {{ domain }}) is now active and ready for use.</p>',
            'body_tr' => '<p>Merhaba {{ customer_name }},</p><p><strong>{{ service_name }}</strong> (Alan Adı/Host: {{ domain }}) hizmetiniz başarıyla aktif edilmiş ve kullanıma açılmıştır.</p>',
        ];

        $this->templates['hosting_account_welcome'] = [
            'subject_en' => 'Your Hosting Account Details for {{ domain }}',
            'subject_tr' => '{{ domain }} için Hosting Hesap Bilgileriniz',
            'body_en' => '<p>Hello {{ customer_name }},</p><p>Your hosting account for <strong>{{ domain }}</strong> is active!</p><p>Package: {{ package }}<br>cPanel Username: {{ username }}<br>Server: {{ server_name }} ({{ server_ip }})<br>Nameservers: {{ nameservers }}</p>',
            'body_tr' => '<p>Merhaba {{ customer_name }},</p><p><strong>{{ domain }}</strong> alan adınız için hosting hesabınız aktif edildi!</p><p>Paket: {{ package }}<br>cPanel Kullanıcı Adı: {{ username }}<br>Sunucu: {{ server_name }} ({{ server_ip }})<br>İsim Sunucuları (NS): {{ nameservers }}</p>',
        ];

        $this->templates['service_suspended'] = [
            'subject_en' => 'Service Suspended: {{ service_name }}',
            'subject_tr' => 'Hizmet Askıya Alındı: {{ service_name }}',
            'body_en' => '<p>Notice: Your service <strong>{{ service_name }}</strong> has been suspended due to: {{ reason }}. Please contact support or settle outstanding invoices.</p>',
            'body_tr' => '<p>Bilgilendirme: <strong>{{ service_name }}</strong> hizmetiniz şu gerekçeyle askıya alınmıştır: {{ reason }}. Lütfen gecikmiş faturalarınızı ödeyiniz veya destek ekibimizle iletişime geçiniz.</p>',
        ];

        $this->templates['password_reset'] = [
            'subject_en' => 'Password Reset Request',
            'subject_tr' => 'Şifre Sıfırlama Talebi',
            'body_en' => '<p>Hello {{ customer_name }},</p><p>We received a request to reset your password. Click the link below to set a new password. This link expires in {{ expires_in }} minutes.</p>',
            'body_tr' => '<p>Merhaba {{ customer_name }},</p><p>Hesabınız için şifre sıfırlama talebinde bulunuldu. Yeni şifre belirlemek için aşağıdaki bağlantıya tıklayınız. Bu bağlantı {{ expires_in }} dakika süreyle geçerlidir.</p>',
        ];
    }
}
