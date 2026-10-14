# Ara ana paket düzeltmesi

Son başarılı main koşusundan önce bir ara tam koşu1529 test/11027 assertion ile1 error verdi. ConfigurationSingletonTest.testUnexpectedlyEndedManagedCallerCannotRunConfigurationCallback, işlem kaybından sonra şema kontrolü/teşhis okumasını yapamıyordu; executeStatement içindeki genel RuntimeException önceki LogicException guard'ından önce geliyordu. Ortak Connection select API'sinde inceleme okuması korundu; insert/update/delete/statement/transaction/beginTransaction kaybolmuş managed işlemde yazımdan önce reddedilmeye devam eder. Test beklentisi değiştirilmedi. Son tam main1529/11030 başarılıdır.

Bu kayıt araç çıktısındaki ara sonucun açıklamasıdır; ham başarısız main XML/TXT ayrıca saklanmadığı için yeniden üretilmiş ham koşu diye sunulmaz. İlk before10 test2 error/6 failure ile ham olarak korunur; native c başarısız gelişim koşusu ayrı XML/TXT olarak korunur.
