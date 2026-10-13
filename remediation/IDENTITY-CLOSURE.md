# IDENTITY-C01 — auth/2FA/session veri yaşam döngüsü

Başlangıç: `29b71c0da1358689f596d175f06e8a48b50c0d97`. Orijinal P03.1/P03.2'nin mevcut yollarındaki D02 veri kalıcılığı onarımı. Sekiz yerel ölçüt:

1. Auth/2FA kurulu şemada DDL çalıştırmaz; required columns, canonical PK/unique identity ve InnoDB doğrulanır; lifecycle upsert tablolarındaki beklenmeyen unique index, missing/partial/lost caller yazımdan önce durur.
2. Password reset ve email verification token replacement atomiktir; tüketim current user-before-token locks altında tek başarı verir; expiry eşitliği reddedilir, bilinmeyen hesap orphan reset token üretmez.
3. Password change + token invalidation + mevcut trusted-device invalidation + session revocation aynı transaction/savepoint içinde tamamlanır; raw/managed caller ve late child failure rollback korunur.
4. Current authentication eski snapshot/password rehash ile peer reset'i geri yazamaz; inactive flag/status korunur.
5. Active 2FA setup yeniden başlatılarak kapatılamaz; confirm yalnız pending state'i bir kez etkinleştirir; sekiz recovery kaydıyla atomiktir, second-child failure partial enable bırakmaz.
6. Recovery hash yalnız bir kez tüketilir; login TOTP counter monotonic olarak saklanır; dört concurrent caller'da tek başarı. Trusted device için enabled 2FA ve strict expiry gerekir.
7. Known revoked session IDs durable ledger'da tutulur; cooperating read/write/destroy/destroyForUser/GC aynı session mutex'ini kullanır; old writer ve GC revoked ID'yi yeniden etkinleştiremez.
8. Migration26 existing sessions/ledger/counters'ı korur; replay no destructive backfill; partial upgrade preflight ve unsafe down refusal. Nihai full main/native discovery tam eşleşmesi ve dosya hash kanıtları sağlanır.

`IDENTITY-C01-SONUC.md` kapsam, koşular ve kalan kabul işlerini açıklar. Bu kontrol listesi P03/D02 bağımsız faz kabulü değildir. D02 IN_PROGRESS, independent_acceptance=PENDING, production_ready=false.

Sekiz yerel ölçüt nihai tam ana/native sonuçlarıyla tamamlandı; bağımsız kabul bekliyor.
