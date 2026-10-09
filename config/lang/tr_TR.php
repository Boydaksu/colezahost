<?php

declare(strict_types=1);

return [
    // Auth & Security
    'auth.login_title' => 'Giriş Yap',
    'auth.register_title' => 'Hesap Oluştur',
    'auth.forgot_password' => 'Şifremi Unuttum',
    'auth.reset_password' => 'Şifreyi Sıfırla',
    'auth.invalid_credentials' => 'Geçersiz kimlik bilgileri.',
    'auth.account_inactive' => 'Hesabınız aktif değildir.',
    'auth.account_locked' => 'Hesabınız geçici olarak kilitlendi. Lütfen daha sonra tekrar deneyin.',
    'auth.password_reset_sent' => 'Şifre sıfırlama bağlantısı :email adresine gönderildi.',
    'auth.password_reset_success' => 'Şifreniz başarıyla sıfırlandı.',
    'auth.welcome_user' => 'Hoş geldiniz, :name!',
    'auth.logout_success' => 'Başarıyla çıkış yaptınız.',
    'auth.session_expired' => 'Oturum süreniz doldu. Lütfen tekrar giriş yapın.',

    // Two-Factor Authentication
    'two_factor.required' => 'Lütfen iki faktörlü doğrulama kodunuzu girin.',
    'two_factor.invalid_code' => 'Girilen 2FA kodu geçersizdir.',
    'two_factor.backup_code_used' => 'Doğrulama için bir kurtarma kodu kullanıldı.',
    'two_factor.enabled' => 'İki faktörlü doğrulama başarıyla etkinleştirildi.',
    'two_factor.disabled' => 'İki faktörlü doğrulama devre dışı bırakıldı.',

    // Organizations
    'org.created' => ':name organizasyonu başarıyla oluşturuldu.',
    'org.switch_success' => 'Organizasyon :slug olarak değiştirildi.',
    'org.member_invited' => 'Davet :email adresine gönderildi.',
    'org.member_removed' => ':name adlı üye organizasyondan çıkarıldı.',

    // Impersonation
    'impersonation.notice' => 'Şu anda :user kullanıcısı adına işlem yapmaktasınız.',
    'impersonation.stopped' => 'Kullanıcı oturumu sonlandırıldı. :admin hesabına dönüldü.',

    // Navigation
    'nav.dashboard' => 'Kontrol Paneli',
    'nav.services' => 'Hizmetler',
    'nav.domains' => 'Alan Adları',
    'nav.billing' => 'Faturalandırma',
    'nav.invoices' => 'Faturalar',
    'nav.support' => 'Destek',
    'nav.tickets' => 'Destek Biletleri',
    'nav.announcements' => 'Duyurular',
    'nav.settings' => 'Ayarlar',
    'nav.users' => 'Kullanıcılar',
    'nav.system_health' => 'Sistem Durumu',

    // Common Actions & Controls
    'common.save' => 'Değişiklikleri Kaydet',
    'common.cancel' => 'İptal',
    'common.delete' => 'Sil',
    'common.edit' => 'Düzenle',
    'common.create' => 'Yeni Oluştur',
    'common.confirm' => 'Onayla',
    'common.back' => 'Geri',
    'common.next' => 'İleri',
    'common.search' => 'Ara...',
    'common.filter' => 'Filtrele',
    'common.export' => 'Dışa Aktar',
    'common.close' => 'Kapat',
    'common.actions' => 'İşlemler',
    'common.status' => 'Durum',
    'common.date' => 'Tarih',
    'common.details' => 'Ayrıntılar',
    'common.loading' => 'Yükleniyor...',
    'common.no_records' => 'Kayıt bulunamadı.',
    'common.success' => 'İşlem başarıyla tamamlandı.',
    'common.error' => 'Beklenmeyen bir hata oluştu.',

    // Billing & Invoices
    'billing.invoices' => 'Faturalar',
    'billing.invoice_number' => 'Fatura No: :number',
    'billing.total' => 'Toplam',
    'billing.subtotal' => 'Ara Toplam',
    'billing.tax' => 'Vergi',
    'billing.due_date' => 'Son Ödeme Tarihi',
    'billing.issue_date' => 'Düzenleme Tarihi',
    'billing.amount_due' => 'Ödenecek Tutar',
    'billing.pay_now' => 'Şimdi Öde',
    'billing.download_pdf' => 'PDF İndir',
    'billing.credit_balance' => 'Mevcut Bakiye: :amount',
    'billing.credit_applied' => ':number numaralı faturaya :amount tutarında kredi uygulandı.',
    'billing.payment_successful' => ':number numaralı fatura ödemesi başarıyla tamamlandı.',
    'billing.payment_failed' => 'Ödeme başarısız: :reason',

    // Services
    'services.my_services' => 'Hizmetlerim',
    'services.service_name' => 'Hizmet',
    'services.domain' => 'Alan Adı',
    'services.billing_cycle' => 'Ödeme Döngüsü',
    'services.next_due' => 'Sonraki Ödeme Tarihi',
    'services.manage' => 'Hizmeti Yönet',
    'services.active_services_count' => ':count adet aktif hizmetiniz bulunmaktadır.',
    'services.renew' => 'Hizmeti Yenile',

    // Domains
    'domains.my_domains' => 'Alan Adlarım',
    'domains.domain_name' => 'Alan Adı',
    'domains.registration_date' => 'Kayıt Tarihi',
    'domains.expiry_date' => 'Bitiş Tarihi',
    'domains.auto_renew' => 'Otomatik Yenileme',
    'domains.nameservers' => 'Alan Adı Sunucuları',
    'domains.manage_dns' => 'DNS Yönetimi',

    // Support Tickets
    'tickets.my_tickets' => 'Destek Biletleri',
    'tickets.open_ticket' => 'Yeni Destek Bileti',
    'tickets.subject' => 'Konu',
    'tickets.department' => 'Departman',
    'tickets.priority' => 'Öncelik',
    'tickets.last_reply' => 'Son Cevap',
    'tickets.ticket_number' => 'Bilet No: :number',
    'tickets.created_success' => ':number numaralı destek bileti başarıyla oluşturuldu.',
    'tickets.reply_submitted' => ':number numaralı bilete cevap gönderildi.',
    'tickets.closed' => ':number numaralı bilet kapatıldı.',

    // Lifecycle Statuses
    'status.active' => 'Aktif',
    'status.pending' => 'Beklemede',
    'status.suspended' => 'Askıya Alındı',
    'status.terminated' => 'Sonlandırıldı',
    'status.cancelled' => 'İptal Edildi',
    'status.paid' => 'Ödendi',
    'status.unpaid' => 'Ödenmedi',
    'status.overdue' => 'Gecikmiş',
    'status.refunded' => 'İade Edildi',
    'status.open' => 'Açık',
    'status.answered' => 'Cevaplandı',
    'status.customer_reply' => 'Müşteri Cevabı',
    'status.closed' => 'Kapalı',

    // Validation Messages
    'validation.required' => ':field alanı zorunludur.',
    'validation.email' => ':field geçerli bir e-posta adresi olmalıdır.',
    'validation.min_length' => ':field en az :min karakter olmalıdır.',
    'validation.max_length' => ':field en fazla :max karakter olabilir.',
    'validation.numeric' => ':field sayısal bir değer olmalıdır.',
];
