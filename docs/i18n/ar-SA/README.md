<!-- Source: README.md; SHA256: 97ba37df4a883426d3984ed7d14547ad7367e973411a8abd930409ce259f545b -->

<!-- translations:start -->
[English](../../../README.md) | [简体中文](../zh-CN/README.md) | [हिन्दी](../hi-IN/README.md) | [Español (España)](../es-ES/README.md) | [Español (Latinoamérica)](../es-419/README.md) | [العربية (السعودية)](README.md) | [Afrikaans](../af-ZA/README.md) | [አማርኛ](../am-ET/README.md) | [العربية (مصر)](../ar-EG/README.md) | [العربية (المغرب)](../ar-MA/README.md) | [বাংলা](../bn-BD/README.md) | [Bosanski](../bs-BA/README.md) | [Deutsch](../de-DE/README.md) | [فارسی](../fa-IR/README.md) | [Filipino](../fil-PH/README.md) | [Français](../fr-FR/README.md) | [Français (Maroc)](../fr-MA/README.md) | [Français (Sénégal)](../fr-SN/README.md) | [Hausa](../ha-NG/README.md) | [עברית](../he-IL/README.md) | [Hrvatski](../hr-HR/README.md) | [Bahasa Indonesia](../id-ID/README.md) | [Igbo](../ig-NG/README.md) | [Italiano](../it-IT/README.md) | [日本語](../ja-JP/README.md) | [한국어](../ko-KR/README.md) | [Malagasy](../mg-MG/README.md) | [मराठी](../mr-IN/README.md) | [Bahasa Melayu](../ms-MY/README.md) | [Nederlands](../nl-NL/README.md) | [Polski](../pl-PL/README.md) | [Português (Brasil)](../pt-BR/README.md) | [Русский](../ru-RU/README.md) | [Kinyarwanda](../rw-RW/README.md) | [Soomaali](../so-SO/README.md) | [Српски](../sr-RS/README.md) | [Svenska](../sv-SE/README.md) | [Kiswahili](../sw-KE/README.md) | [தமிழ்](../ta-IN/README.md) | [తెలుగు](../te-IN/README.md) | [ไทย](../th-TH/README.md) | [Türkçe](../tr-TR/README.md) | [Українська](../uk-UA/README.md) | [اردو](../ur-PK/README.md) | [Tiếng Việt](../vi-VN/README.md) | [isiXhosa](../xh-ZA/README.md) | [Yorùbá](../yo-NG/README.md) | [繁體中文](../zh-TW/README.md) | [isiZulu](../zu-ZA/README.md)
<!-- translations:end -->

<a id="plugin_gpu"></a>
# plugin_gpu

ملحق لـ Cacti 1.2 يجلب استخدام كل GPU وذاكرته وحرارته وقدرته وأخطاء ECC وأسباب خفض الأداء من أدوات NVIDIA وAMD إلى Cacti. يصبح كل GPU (أو مثيل MIG) فهرسًا لاستعلام بيانات على جهاز عقدته، فتظهر رسومه بجوار رسوم العقدة الأخرى ضمن صلاحيات Cacti، ويمكن لـ Thold وMaint إطلاق التنبيهات وكتمها.

> **الحالة: المراحل 1 إلى 3 منفذة في الشيفرة، ولم تُصدر بعد.** المرحلة 1 (جمع NVIDIA `nvidia-smi` عبر SNMP extend، وتتبع الهوية، والرسوم، وإشارات خفض التردد وECC، وتنبيهات Thold وMaint، وصفحة الصحة)، والمرحلة 2 (محلل/عميل HTTP(S) للقراءة فقط لـ NVIDIA DCGM Exporter ومحلل AMD `amd-smi`)، والمرحلة 3 (تتبع هوية مثيلات MIG، وأعمدة Schema v4، وتنفيذ المجمّع البعيد ضمن نطاق المستطلع، وخطافات `replicate_out` و`remote_agent`)، وأداة سطر أوامر المشغّل وCasC (`cli/gpu.php`)، وخطافات التكامل البيئي (`abilities_register`، `doctor_register_checks`، `casc_register_providers`) منفذة ومغطاة باختبارات الوحدة والأمان والتكامل على Cacti مؤقت. تعتمد التشغيلات الحية حتى الآن على `snmpd` اصطناعي وحمولات اختبارية بدلًا من عتاد GPU فعلي. لا يزال مفتوحًا قبل وسْم الإصدار: تجارب على عتاد حقيقي ([docs/pilot-plan.md](../../pilot-plan.md)، #54)، ومراجعة أمنية خارجية ([docs/security-review.md](../../security-review.md))، وعينات ملتقطة من أدوات حقيقية (#14)، وقياسات الحجم على عتاد بحجم الإنتاج ([docs/performance.md](docs/performance.md)). يظل جمع أخطاء XID مفتوحًا بموجب القرار #8.

<a id="access-roles"></a>
## أدوار الوصول

| المجال | الوصول |
| --- | --- |
| GPU View | صفحات المراقبة الحالية. |
| GPU Operator | المراقبة إضافةً إلى صفحة العمليات وإقرارات الفرز الشخصية. |
| GPU Admin | وصول Operator إضافةً إلى الإعداد والإجراءات الإدارية الحالية. |

بعد نسخ ملفات الترقية، افتح صفحة الملحقات كمسؤول لتسجيل المجال الجديد. عيّن مجال Operator صراحةً ضمن **الإعداد > المستخدمون** أو مجموعات المستخدمين. تحتفظ الترقيات بمعرّفات مجالي View وAdmin ومنحهما الحاليين. يرث Admin وصول Operator، ويرث Operator وصول View في كل طلب؛ ولا يضيف ذلك منحًا مخزنة للمستخدمين أو المجموعات. تتطلب إعدادات الملحق وصول GPU Admin إضافةً إلى صلاحية Settings في Cacti.

تظل صلاحيات أجهزة Cacti تحدّ الصفوف والإجراءات المتاحة لكل مستخدم؛ وتتطلب الكائنات غير المربوطة مسؤولًا. تبقى بيانات الاعتماد والإعداد والربط والحذف والتغييرات الإدارية الأخرى في الصفحات الإدارية. تستمر الإقرارات في جلسة المستخدم الحالي فقط، لمدة ساعة كحد أقصى وطالما بقيت الملاحظة نفسها حديثة. تبطلها الملاحظات الجديدة أو المتغيرة. لا تجعل القياس الفاشل سليمًا ولا تكبت التنبيهات مطلقًا.

<a id="why"></a>
## الدافع

تكون عقد GPU عادةً موجودة بالفعل في Cacti لمراقبة CPU والذاكرة والواجهات. صحة GPU مسألة أخرى لا تجيب عنها بيانات SNMP للعقدة: قد تُخفض سرعة بطاقة بسبب الحرارة أو تتراكم أخطاء ECC أو يُبلّغ عطل XID بينما تبدو العقدة نفسها سليمة. تبلّغ أدوات الشركات ذلك (`nvidia-smi` وDCGM و`amd-smi`)، لكن خرجها خارج رسوم Cacti وصلاحياته وتنبيهاته.

يسد الملحق هذه الفجوة بتحويل خرج أدوات الشركات إلى مصادر بيانات Cacti، بمفاتيح تعتمد هوية كل GPU لا موضعها، كي لا تربط إعادة تشغيل تغيّر ترتيب GPU تاريخ بطاقة بأخرى. يعيد استخدام رسوم Cacti وصلاحياته ومجمّعاته البعيدة وThold وMaint بدل بناء نسخ موازية منها.

<a id="planned-scope"></a>
## النطاق المخطط

| المرحلة | النطاق | الحالة | المعالم |
|---|---|---|---|
| 0، البحث | خرج أدوات الشركات وإشارات XID وخفض التردد وهوية GPU والامتيازات وقرار مسار الجمع | مكتمل | [M1](https://github.com/somethingwithproof/plugin_gpu/milestone/1) |
| 1، nvidia | جمع `nvidia-smi`، وجرد حسب UUID، ورسوم، وإشارات خفض التردد وECC، وThold وMaint، وصفحة الصحة | منفذ (مختبر بالعينات) | [M2](https://github.com/somethingwithproof/plugin_gpu/milestone/2)، [M3](https://github.com/somethingwithproof/plugin_gpu/milestone/3)، [M4](https://github.com/somethingwithproof/plugin_gpu/milestone/4) |
| 2، الواجهات الخلفية | محلل/عميل HTTP(S) للقراءة فقط لـ DCGM Exporter (`lib/DcgmExporterParser.php`، `lib/DcgmExporterClient.php`)، ومحلل AMD `amd-smi` (`lib/AmdSmiParser.php`) | منفذ (مختبر بالوحدة) | [M5](https://github.com/somethingwithproof/plugin_gpu/milestone/5) |
| 3، MIG والبعيد والنظام البيئي | تتبع هوية مثيلات MIG، ومجمّعات البيانات البعيدة (`INFO`، `poller_gpu.php --poller=N`، `replicate_out`، `remote_agent`)، وأداة CasC CLI (`cli/gpu.php`)، وخطافات MCP/Doctor/CasC | منفذ (مختبر بالوحدة وتكامل CI) | [M6](https://github.com/somethingwithproof/plugin_gpu/milestone/6) |

خارج النطاق: أي أمر يغيّر GPU أو العقدة (الساعات وحدود الطاقة وإعادة الضبط وتخطيط MIG)، ومحرك تنبيه خاص بالملحق، وخدمة خلفية أو بروتوكول شبكي مخصص، ومحاسبة المجدول أو المهام. راجع الخطة لقائمة البحث المتبقية.

<a id="what-milestone-4-adds"></a>
## ما يضيفه المعلم 4

- **مجمّع القراءة فقط لـ NVIDIA DCGM Exporter** (`lib/DcgmExporterParser.php`، `lib/DcgmExporterClient.php`). يحلل مقاييس العرض النصي لـ Prometheus (`DCGM_FI_DEV_GPU_UTIL` و`DCGM_FI_DEV_FB_USED` و`DCGM_FI_DEV_FB_FREE` و`DCGM_FI_DEV_POWER_USAGE` و`DCGM_FI_DEV_GPU_TEMP` و`DCGM_FI_DEV_ECC_DBE_VOL_TOTAL` و`DCGM_FI_DEV_CLOCK_THROTTLE_REASONS`، ووسمي MIG `GPU_I_ID` و`GPU_I_PROFILE`). يفرض عميل HTTP(S) قائمة سماح صريحة للمضيف أو نطاق CIDR لنقطة النهاية، ويتحقق من شهادات TLS للنظير افتراضيًا، ويقيد المهلات وحجم الاستجابة، ويعطل إعادة التوجيه، ويرفض المخططات غير HTTP(S).
- **محلل القراءة فقط لـ AMD `amd-smi`** (`lib/AmdSmiParser.php`). يوحّد خرج `amd-smi` بصيغة JSON أو SNMP-extend (الاستخدام وVRAM والقدرة وحرارة الوصلة والحافة وECC وبيانات التقسيم الوصفية ومراكمات خفض التردد المنفصلة عبر `Reading::$vendorExtensions`) في نموذج المقاييس المشترك تحت `Vendor::Amd`.
- **تتبع مثيلات MIG وSchema v4** (`lib/GpuReport.php`، `lib/GpuSnapshot.php`، `lib/GpuRecord.php`، `lib/Schema.php`، `lib/ScriptServer.php`). يتتبع معرّفات UUID لمثيلات MIG (`MIG-<uuid>` و`MIG-GPU-<parent>/<gi>/<ci>`) و`mig_mode` و`mig_instance_id` و`mig_profile` و`parent_uuid` و`partition_info` إلى جانب وحدات GPU الأصلية، مع إبقاء مفاتيح الهوية الثابتة (`vendor` و`uuid` و`parent_uuid`) محمية عند التحديثات ومتحققًا منها قبل استعلامات قاعدة البيانات.
- **مجمّعات البيانات البعيدة** (`INFO`، `poller_gpu.php`، `lib/Poller.php`، `lib/ReplicationRepository.php`، `setup.php`). يعلن `remote_collect:1, remote_poller:1` في `INFO`، ويدعم التنفيذ ضمن نطاق المستطلع عبر `php plugins/gpu/poller_gpu.php --poller=N`، ويتخطى توفير كائنات Cacti على المستطلعات غير الرئيسية، وينسخ الجداول المقيدة بالمستطلع عبر `replicate_out`، ويجيب عن إجراءات القراءة فقط `gpu_health` و`gpu_inventory` عبر `remote_agent`.
- **أداة سطر الأوامر للمشغّل وCasC** (`cli/gpu.php`، `lib/CascProvider.php`). تعمل على PHP 8.2+ (وتُحلل على PHP 8.0) دون أي اعتماديات Composer خارجية: `php plugins/gpu/cli/gpu.php export [--format=yaml|xml]`، و`validate <file> [--format=yaml|xml]`، و`plan <file> [--format=yaml|xml] [--output=text|json]`، و`apply <file> --confirm=<PLAN_HASH> [--format=yaml|xml]`، و`health [--format=text|json]`. تحسب الخطط تجزئة تأكيد SHA-256 حتمية، ويفشل `apply` بأمان عند عدم تطابق التجزئة، ويرفض تحليل XML تصريحات `DOCTYPE` و`ENTITY`.
- **خطافات التكامل البيئي** (`lib/EcosystemIntegration.php`، `setup.php`). يسجّل `abilities_register` (`gpu-list-nodes` و`gpu-list-devices` و`gpu-get-health`؛ للقراءة فقط ومقيدة بنطاق `GPU View` وصلاحيات أجهزة Cacti)، و`doctor_register_checks` (`gpu_schema` و`gpu_poller_freshness` و`gpu_node_health`)، و`casc_register_providers`.
- **أسباب خفض التردد وECC كقيم تنبيه** ([docs/metrics.md](docs/metrics.md)). يفك قناع أحداث الساعة إلى مؤشرات 0/1 ويحسب أخطاء ECC المصحّحة وغير المصحّحة الجديدة لكل فترة كقيم gauge بحيث ينبّه Thold 1.8.2 وMaint 1.3 ويكتمان التنبيهات دون عدادات سالبة أو قفزات إعادة الضبط ([docs/alerting.md](docs/alerting.md)).
- **صحة GPU والصلاحيات والفشل/الاستعادة والحجم.** تعرض صفحة صحة GPU (GPU View) الحداثة المخزنة ونتيجة آخر جمع وحالة Maint والواجهة الخلفية والإصدارات والأقنعة الخام لخفض التردد دون الاتصال بالعقد. وتتحقق الاختبارات الشاملة المؤتمتة من الصلاحيات واستعادة أعطال الوكيل وقاعدة البيانات وRRD ([docs/backup.md](docs/backup.md))، وتشغيل اختبار حجم لـ 100 GPU في CI ([docs/performance.md](docs/performance.md)، [docs/operations.md](docs/operations.md)، [CHANGELOG.md](../../../CHANGELOG.md)).

<a id="what-milestone-3-adds"></a>
## ما يضيفه المعلم 3

- **الجمع عبر SNMP extend** ([ADR 0008](../../decisions/0008-collect-through-snmp-extend.md)). يضيف مسؤول العقدة سطري `extend` ثابتين إلى `snmpd.conf` ([docs/node-setup.md](docs/node-setup.md))؛ ويقرأ الملحق NET-SNMP-EXTEND-MIB عبر دوال SNMP في Cacti ببيانات اعتماد الجهاز نفسه؛ ويوصى بـSNMPv3. لا يشغّل شيئًا على خادم Cacti أو العقدة.
- **تحليل صارم.** تُقرأ الحقول حسب الموضع وتسمّي كل سطر GPU بواسطة UUID؛ وتفشل عملية الجمع كاملةً عند حالة خروج غير صفرية أو انتهاء مهلة أو خرج مبتور أو عدد حقول خاطئ أو نص خطأ مدمج: تصبح القيم `U`، ولا تُعلّم GPU غائبة، وتعرض عقد GPU السبب.
- **مجمّع بعد كل دورة مستطلع.** يقرأ وكيل كل عقدة مفعّلة ويحدّث سجلات الهوية ويخزّن آخر عينة مؤقتًا. يقرأ إدخال خادم السكربت هذه الذاكرة فقط، فلا ينتظر استطلاع Cacti عقدة GPU مطلقًا.
- **كائنات Cacti** ([ADR 0010](../../decisions/0010-attach-to-existing-node-devices.md)). تُرفق القوالب واستعلام *GPU - Devices* كـXML وتُستورد عند التثبيت والترقية. يربط حفظ العقدة الاستعلام بجهازها الحالي بطريقة إعادة فهرسة None؛ ولا تستطيع عقد GPU إنشاء جهاز إلا إذا لم تملك العقدة جهازًا. كل GPU فهرس بمفتاح الشركة وUUID، مع مصدر بيانات واحد تشترك فيه رسوم الاستخدام والذاكرة والحرارة والطاقة ونسبة الطاقة والساعات وخفض التردد وأخطاء ECC وأخطاء ECC الجديدة والصفحات المتقاعدة وعمر العينة. يُرسم كل حقل نموذج في رسم ما، لأن Cacti لا يخزّن إلا حقولًا تستخدمها الرسوم. يعيد الملحق الفهرسة بعد كل تغيير GPU، ويحتفظ بإعادة فهرسة تخطاها Cacti حين كان الجهاز متوقفًا أو معطّلًا وينفذها عندما ينشط، وينشئ رسوم GPU الجديدة.
- **يبقى التاريخ مع البطاقة.** تظل GPU الغائبة فهرسًا، فتظهر فجوة في رسومها وتستأنف في ملف RRD نفسه عند عودتها. تحصل البطاقة البديلة في الفتحة نفسها على مصدرها الخاص. يزيل **إزالة من استعلام البيانات** على صفحة العقدة GPU الغائبة من القائمة؛ تبقى ملفات RRD ورسومها حتى تحذفها.
- **صلاحيات الأجهزة في عقد GPU.** لا يرى GPU Admin ولا يغيّر ولا يصدّر ولا ينقل إلا عقد وGPU أجهزة يجوز له رؤيتها في Cacti؛ ويتطلب نقل GPU كلا العقدتين. العقدة التي حُذف جهازها يمكن تعطيلها أو حذفها.
- **نموذج المقاييس المشترك** ([ADR 0009](../../decisions/0009-common-metric-core-with-vendor-extensions.md)، [docs/metrics.md](docs/metrics.md)). الذاكرة بالبايت، ويُفك قناع أحداث الساعة إلى مؤشرات `thr_*`، و`age` عمر وقت العينة الخاصة بـGPU، أو `U` عند الجهل به.

يجب أن يستطيع مستخدم التثبيت (خادم الويب) الكتابة إلى `<cacti>/resource/script_server/` حيث يضع الملحق XML استعلام البيانات؛ وإلا يتوقف التثبيت والترقية برسالة ويعيدان المحاولة لاحقًا. لا تزيل الإزالة القوالب وXML إلا عند تحديد *إزالة كائنات Cacti الخاصة بالملحق عند الإزالة*، ولا تحذف ملفات RRD أو الأجهزة أو الرسوم أو مصادر البيانات مطلقًا ([docs/operations.md](docs/operations.md#uninstall)). تعمل العقد على المجمّعات البعيدة للبيانات ضمن نطاق معرّف المستطلع `poller_id` المعيّن لها.

<a id="what-milestone-2-ships"></a>
## ما يقدمه المعلم 2

- **تثبيت محمي.** على PHP أقل من 8.2 يُرفض التثبيت والتفعيل برسالة تحدد الإصدار المطلوب ويستمر باقي Cacti بالعمل. يمكن تحليل `setup.php` وسكربتات الصفحات على PHP 8.0.
- **دورة الحياة.** التثبيت والترقية والتعطيل والإزالة عبر صفحة ملحقات Cacti. الترقيات مرتبة وتكتمل بعد الانقطاع ولا تغيّر الإصدار المسجل إلا عند نجاحها. تحتفظ الإزالة بجداول الملحق ما لم تُلغِ *الاحتفاظ بجداول الملحق عند الإزالة*؛ ولا تمس جهاز Cacti أو مصدر بيانات أو رسمًا أو ملف RRD مطلقًا.
- **مجالات منفصلة.** يفتح *GPU Admin* صفحة **عقد GPU** لتسجيل أجهزة Cacti كعقد GPU وتأكيد نقل GPU بين العقد أو رفضه وتصدير كل سجل كـJSON. يفتح *GPU View* **جرد GPU** الذي يسرد GPU على أجهزة يمكن للمشاهد رؤيتها فقط. الصفحتان تحت الإدارة، وكل تغيير طلب POST يفحصه csrf-magic.
- **سجلات هوية GPU.** مفتاح كل GPU هو الشركة وUUID. الفهرس ومعرّف ناقل PCI والاسم وبرنامج التشغيل وأداة الإبلاغ سمات تتغير في موضعها. يُعلّم UUID جديد في فتحة معروفة كبديل محتمل ويبدأ تاريخه الخاص؛ ولا تُنقل GPU مرصودة على عقدة أخرى إلا بتأكيد المشغّل؛ وتُعلّم GPU المفقودة من جمع ناجح غائبة بعد عدد قابل للضبط ولا تُحذف مطلقًا. لا تُقبل حاليًا إلا UUID لوحدات NVIDIA الكاملة؛ تنتظر مثيلات MIG القرار #13، وAMD البحث #19. تُختبر القواعد بتسلسلات عينات، إذ لا يوجد مجمّع يغذيها بعد.
- **الاختبارات وCI.** مجموعات وحدة وقواعد مصدر دون GPU أو قاعدة بيانات، وفحوص دورة الحياة وصلاحيات الصفحات على Cacti 1.2.31 و1.2.x الحالي مع PHP 8.2 إلى 8.5، ومهمة رفض PHP 8.1، وفحص صياغة نقاط الدخول على PHP 8.0، وPHPStan بالمستوى 9 وREUSE. بيئة الاختبار مؤقتة حتى يُحسم #12.

<a id="install-for-evaluation"></a>
### التثبيت للتقييم

تثبّت الخطوات التالية الشيفرة الحالية لتجربتها؛ الدليل الكامل في [docs/operations.md](docs/operations.md). تتطلب Cacti 1.2.31 أو أحدث على PHP 8.2 أو أحدث.

1. انسخ المستودع إلى `plugins/gpu` تحت دليل Cacti. يجب أن يُسمّى الدليل `gpu`.
2. في Cacti، افتح **الإعداد > الملحقات** ثم ثبّت **GPU** وفعّله.
3. امنح المستخدمين نطاق *GPU View* أو *GPU Operator* أو *GPU Admin* ضمن **الإعدادات > المستخدمون** بحسب دورهم. يحصل مستخدم التثبيت على View وAdmin؛ ويشمل Admin وصول Operator.
4. اضبط وكيل SNMP لكل عقدة كما في [docs/node-setup.md](docs/node-setup.md)، ثم أضفها ضمن **الإدارة > عقد GPU**.
5. للتنبيهات، استورد قوالب Thold المرفقة كما في [docs/alerting.md](docs/alerting.md).
6. اختياريًا اضبط **الإعداد > الإعدادات > GPU**: *تعليم الغياب بعد* (افتراضيًا 3 عمليات جمع ناجحة، من 1 إلى 100)، و*التقادم بعد* (عمر العينة قبل عرض القيم `U`)، و*الاحتفاظ بجداول الملحق عند الإزالة* (مفعّل افتراضيًا).

<a id="how-metrics-reach-cacti"></a>
## كيف تصل المقاييس إلى Cacti

اختار القرار [#7](https://github.com/somethingwithproof/plugin_gpu/issues/7) SNMP `extend` ([ADR 0008](../../decisions/0008-collect-through-snmp-extend.md)): يشغّل وكيل العقدة أمر `nvidia-smi` ثابتًا ويقرأ الملحق النتيجة فقط. تقارن الخطة الخيارات تحت [مسارات الجمع](../../PLAN.md#collection-paths).

<a id="compatibility-targets"></a>
## أهداف التوافق

| المكوّن | الهدف |
|---|---|
| Cacti | 1.2.31 أو أحدث، دون ترقيعات للنواة |
| PHP | 8.2 أو أحدث؛ يبقى `setup.php` قابلًا للتحليل على 8.0 كي يُرفض وقت التشغيل غير المدعوم بدل تعطيل واجهة Cacti |
| NVIDIA | خطوط برامج التشغيل والأدوات يحددها البحث [#26](https://github.com/somethingwithproof/plugin_gpu/issues/26)؛ فُحصت معرّفات حقول DCGM عند v3.3.9 وv4.7.0 |
| AMD | `amd-smi` على إصدارات ROCm يحددها البحث [#26](https://github.com/somethingwithproof/plugin_gpu/issues/26) |
| Thold وMaint | Thold 1.8.2 وMaint 1.3 دون تعديل، باختبار شامل ([docs/alerting.md](docs/alerting.md)) |

<a id="design"></a>
## التصميم

- [docs/PLAN.md](../../PLAN.md) الخطة الكاملة: المعمارية والحقائق المعتمدة من المشاريع الأصلية (بمصادرها) ونموذج الهوية وحدود الأمان وحسابات الأداء والاختبار والمخاطر.
- يحوي [docs/decisions/](../../decisions) سجلات القرارات المعمارية المتخذة.
- يربط [docs/REQUIREMENTS.md](../../REQUIREMENTS.md) كل معرّف متطلب بقضيته.

```mermaid
flowchart LR
    NODE["عقدة GPU: snmpd extend / DCGM Exporter / amd-smi"] -->|SNMPv3 للقراءة فقط أو HTTPS مسموح به| C["مجمّع ضمن نطاق المستطلع"]
    C --> CACHE[("ذاكرة الإضافة المؤقتة مفهرسة بالمورّد + GPU/MIG UUID")]
    CACHE --> SS["إدخال استعلام بيانات خادم السكربت"]
    CACHE --> ECO["CasC CLI وقدرات MCP وفحوصات Doctor"]
    SS --> RRD[("ملفات Cacti RRD، مصدر بيانات واحد لكل GPU")]
    SS -->|poller_output| T["Thold وMaint"]
    RRD --> G["رسوم Cacti وصلاحياته"]
```

<a id="how-work-is-tracked"></a>
## كيفية تتبع العمل

- تتبع **المعالم** معالم الخطة. لكل منها قضية ملحمية تسرد قضاياها الفرعية وأدلة الإكمال.
- تُصنّف **القضايا** بالوسوم: `type: requirement` و`type: research` و`type: decision` و`type: epic`. تذكر قضايا المتطلبات متطلبات مرقمة قابلة للاختبار (`REQ-AREA-NNN`) ومعايير قبول.
- تحمل **الوسوم** أيضًا `area:` و`phase:` و`priority:` و`status:`. راجع [CONTRIBUTING.md](../../../CONTRIBUTING.md#labels).
- تعرض [لوحة المشروع](https://github.com/users/somethingwithproof/projects/9) جميع القضايا عبر المعالم.

<a id="contributing"></a>
## المساهمة

اقرأ [CONTRIBUTING.md](../../../CONTRIBUTING.md). تتطلب الالتزامات توقيع DCO. نقاش التصميم في القضايا؛ تحمل القرارات المفتوحة الوسم `type: decision`. الخرج الملتقط من أدوات GPU حقيقية أكثر مساهمة فائدة للمعلم 1؛ أزل أسماء المضيفين والأرقام التسلسلية وUUID أولًا.

<a id="security"></a>
## الأمان

أبلغ عن الثغرات بصورة خاصة؛ راجع [SECURITY.md](../../../SECURITY.md).

<a id="license"></a>
## الترخيص

رخصة GNU العمومية العامة v2.0، وهي رخصة Cacti وملحقاته نفسها. راجع [LICENSE](../../../LICENSE).
