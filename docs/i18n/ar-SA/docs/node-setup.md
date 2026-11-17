<!-- Source: docs/node-setup.md; SHA256: bd138c845671d32102a4985b87e4356d95897b131711cc0e498982e626b81a98 -->

<a id="setting-up-a-gpu-node"></a>
# إعداد عقدة GPU

يقرأ الملحق خرج `nvidia-smi` لكل عقدة GPU عبر وكيل SNMP الخاص بها باستخدام NET-SNMP-EXTEND-MIB ([ADR 0008](../../../decisions/0008-collect-through-snmp-extend.md)). يضيف مسؤول العقدة إدخالي `extend` إلى `snmpd.conf`، وثالثًا فقط لإصدارات `nvidia-smi` الأقدم؛ ولا يرسل الملحق إلى الوكيل سوى طلبات القراءة، مستخدمًا إعدادات SNMP لجهاز العقدة في Cacti. لا يُثبّت على العقدة شيء سوى `snmpd` (net-snmp).

<a id="snmpdconf"></a>
## snmpd.conf

تحتاج كل عقدة إلى السطرين التاليين كما هما تمامًا. يحلل الملحق أسماء الإدخالات وقائمة الحقول وترتيبها؛ ويرفض القائمة المعدّلة بوصفها خرجًا غير صالح، بدلًا من قراءتها قراءة خاطئة.

<!-- extend-lines:start -->
```
extend -cacheTime 30 cacti_gpu /usr/bin/env -i TZ=UTC /usr/bin/timeout -k 2 10 /usr/bin/nvidia-smi --query-gpu=timestamp,uuid,index,pci.bus_id,driver_version,ecc.mode.current,utilization.gpu,utilization.memory,memory.used,memory.total,temperature.gpu,power.draw,power.limit,clocks.sm,clocks.mem,ecc.errors.corrected.volatile.total,ecc.errors.uncorrected.volatile.total,retired_pages.pending,retired_pages.sbe,retired_pages.dbe,clocks_event_reasons.active --format=csv,noheader,nounits
extend -cacheTime 300 cacti_gpu_names /usr/bin/env -i /usr/bin/timeout -k 2 10 /usr/bin/nvidia-smi --query-gpu=uuid,name --format=csv,noheader
```
<!-- extend-lines:end -->

وظيفة كل جزء:

- يشغّل `extend` دون `-execType sh` البرنامج بمتجه معاملات ثابت ودون صدفة أوامر. لا يصل شيء من Cacti أو العقدة أو GPU إلى سطر الأوامر.
- يبدأ `/usr/bin/env -i` الأداة ببيئة فارغة. يضبط إدخال المقاييس `TZ=UTC` فقط، لأن `nvidia-smi` يطبع وقت العينة دون منطقة زمنية ويقرأه الملحق باعتباره UTC.
- يوقف `/usr/bin/timeout -k 2 10` الأداة بعد 10 ثوانٍ وينهيها قسرًا بعد ثانيتين إضافيتين. ينتظر snmpd العملية الابنة دون مهلة خاصة به، لذا قد يعلق الوكيل إذا تعلّق `nvidia-smi` دون هذا القيد.
- يحتفظ `-cacheTime 30` بعينة واحدة لمدة 30 ثانية، والمهلة أقصر من هذه النافذة، لذلك ينتهي التشغيل دائمًا قبل إمكان بدء التالي. ترى جميع عمليات استطلاع Cacti ضمن النافذة العينة نفسها، ويعرض الملحق عمر العينة ذاتها.
- تأتي أسماء GPU من إدخال منفصل لأن اسم المنتج نص حر قد يحتوي فاصل CSV. نادرًا ما تتغير الأسماء، لذا يُخزّن هذا الإدخال مؤقتًا لمدة 5 دقائق.
- يقل طول كل أمر عن حد extend البالغ 510 بايتات للأمر ومعاملاته.
- الحد المدعوم هو 128 وحدة GPU لكل عقدة، كي يبقى خرج المقاييس أقل بكثير من حد snmpd البالغ 100 KiB. بلغ طول سطر المقاييس 194 إلى 197 بايتًا في عينات الاختبار الملتقطة، و370 بايتًا إذا طبع كل مقياس `[Not Supported]`: أي نحو 46 KiB كحد أقصى لـ128 وحدة GPU. بلغ خرج إدخال الأسماء 64 بايتًا لكل GPU في العينات. فوق الحد، أعاد snmpd في الاختبار الشامل حالة الخروج 4 دون خرج، فيعرضها الملحق على أنها *رُفض الإذن*؛ والسبب غير مفهوم، لذا التزم بالحد.

يعدّ الملحق كل حالة مما يلي فشل جمع بقيم مجهولة، لا بيانات: حالة خروج غير صفرية (بما فيها انتهاء المهلة)، أو خرج مبتور عند حد snmpd البالغ 100 KiB أو 1024 سطرًا، أو سطر به حقول أكثر أو أقل من المطلوب، أو أي نص آخر في الخرج، مثل رسالة خطأ دمجها snmpd من stderr.

<a id="optional-an-nvidia-smi-older-than-the-clock-event-reasons-rename"></a>
### اختياري: nvidia-smi أقدم من إعادة تسمية أسباب أحداث الساعة

يطلب إدخال المقاييس أسباب أحداث الساعة بالاسم `clocks_event_reasons.active` (REQ-NVSMI-005). غيّر سجل تغييرات nvidia-smi اسمها من `clocks_throttle_reasons.*` في v530، وأعاد أسماء الاستعلام القديمة في v535 ([البحث](../../../research/clock-event-reasons.md))، لذا قد يعرف إصدار `nvidia-smi` قديم الاسم القديم فقط؛ ولم تُؤكّد إصدارات برامج التشغيل المطابقة لهذه الإصدارات. ترفض هذه الأداة إدخال المقاييس بالحالة 2، وهي حالة nvidia-smi للمعامل غير الصالح، ويفشل جمع العقدة حتى تضيف السطر التالي أيضًا:

<!-- extend-legacy:start -->
```
extend -cacheTime 30 cacti_gpu_legacy /usr/bin/env -i TZ=UTC /usr/bin/timeout -k 2 10 /usr/bin/nvidia-smi --query-gpu=timestamp,uuid,index,pci.bus_id,driver_version,ecc.mode.current,utilization.gpu,utilization.memory,memory.used,memory.total,temperature.gpu,power.draw,power.limit,clocks.sm,clocks.mem,ecc.errors.corrected.volatile.total,ecc.errors.uncorrected.volatile.total,retired_pages.pending,retired_pages.sbe,retired_pages.dbe,clocks_throttle_reasons.active --format=csv,noheader,nounits
```
<!-- extend-legacy:end -->

`cacti_gpu_legacy` هو استعلام المقاييس بالاسم القديم `clocks_throttle_reasons.active` في الموضع نفسه. لا يقرأه الملحق إلا عندما ينتهي `cacti_gpu` بالحالة 2، ولذلك لا يعمل مطلقًا مع برنامج تشغيل حديث. يطبع الاسمان قناع البتات نفسه، ويفك الملحق ترميزه بالطريقة نفسها. تحتاج العقدة المعدّة وفق إصدار أقدم من هذه الصفحة إلى استبدال سطر `cacti_gpu` بالسطر أعلاه: يضيف هذا الإصدار `ecc.mode.current` و`retired_pages.sbe` و`retired_pages.dbe` إلى قائمة الحقول، وحتى يُستبدل السطر يفشل جمع العقدة برسالة *رُفض الخرج* لأن عدد حقول السطر لم يعد مطابقًا. لا يعالج البديل الخاص بالحالة 2 هذه الحالة لأن السطر القديم يعمل بنجاح. يجوز اختلاف اسم أسباب أحداث الساعة فقط: يعمل أيضًا سطر `cacti_gpu` الذي يحتوي قائمة الحقول الحالية و`clocks_throttle_reasons.active` في الموضع الأخير، لأن الاسمين يطبعان قناع البتات نفسه هناك.

<a id="run-snmpd-as-an-unprivileged-user"></a>
## تشغيل snmpd كمستخدم بلا امتيازات

يعمل أمر `extend` بهوية المستخدم الذي يشغّل `snmpd`، وهو root ما لم يُضبط `agentuser` أو `-u`. لا تتطلب الحقول أعلاه امتيازات ([research/privileges.md](../../../research/privileges.md)). شغّل الوكيل بحساب غير ذي امتيازات:

```
agentuser Debian-snmp
```

استخدم الحساب الذي توفره حزمة snmpd في توزيعتك (توفر Debian وUbuntu الحساب `Debian-snmp` وتبدآن snmpd بالخيار `-u Debian-snmp`)، أو أنشئ حسابًا. لا يستطيع الملحق معرفة المستخدم الذي شغّل الأمر، لذا تحقّق أثناء الجمع، مثلًا باستخدام `ps -o user= -C nvidia-smi`. المستخدم الافتراضي لكل توزيعة موضوع تحقق حي في #22.

<a id="snmpv3-is-the-default"></a>
## SNMPv3 هو الخيار الافتراضي

استخدم SNMPv3 مع المصادقة والتشفير، وقصّر وصول المستخدم على مجموعة النظام وجداول extend:

```
view   cactigpu included .1.3.6.1.2.1.1
view   cactigpu included .1.3.6.1.4.1.8072.1.3.2
rouser -s usm cactigpu priv -V cactigpu
```

أنشئ المستخدم باستخدام `net-snmp-create-v3-user`، أو أضف السطر `createUser cactigpu SHA-256 <auth passphrase> AES <privacy passphrase>` إلى إعدادات snmpd الدائمة (`/var/lib/snmp/snmpd.conf` على Debian وUbuntu) أثناء توقف snmpd. ثم اضبط جهاز العقدة في Cacti على SNMP الإصدار 3 بالمستخدم نفسه ومصادقة SHA-256 وتشفير AES.

يعمل SNMP v1 وv2c أيضًا، لكنهما يرسلان اسم المجتمع وكل قيمة بنص صريح. إذا استخدمتهما، فقصّر المجتمع على نطاق العرض نفسه وعنوان مستطلع Cacti، مثل `rocommunity <community> <poller address> -V cactigpu`.

<a id="in-cacti"></a>
## في Cacti

1. تأكد من أن العقدة جهاز في Cacti بإعدادات SNMP عاملة. إن لم تكن كذلك، تستطيع GPU Nodes إنشاء جهاز من الإعدادات الافتراضية للموقع؛ اضبط إعدادات SNMP لاحقًا في صفحة الجهاز في Cacti.
2. في **وحدة التحكم > الإدارة > عقد GPU**، أضف عقدة للجهاز. يضيف حفظ العقدة استعلام بيانات GPU إلى الجهاز.
3. يعمل المجمّع في نهاية كل دورة استطلاع. تصبح كل GPU يجدها فهرسًا لاستعلام بيانات GPU، وينشئ الملحق رسوم GPU لها.

لا تُجمع بعد بيانات العقد الموجودة على مجمّعات بيانات بعيدة؛ فالمجمّعات البعيدة ضمن المرحلة 3.

<a id="an-snmp-port-other-than-161-on-cacti-12x"></a>
### منفذ SNMP غير 161 على Cacti 1.2.x

يضع فرع Cacti 1.2.x (بعد 1.2.31) عنوان الجهاز بين قوسين مربعين لطلب SNMP get منفرد عندما لا يكون المنفذ 161، مثل `[192.0.2.10]:1161`. يقرأ امتداد SNMP في PHP الاسم بين القوسين باعتباره عنوان IPv6، فيفشل الطلب لعنوان IPv4 أو اسم مضيف لا يملك عنوان IPv6، وتظهر الرسالة `Unknown failure while resolving '[192.0.2.10]:1161'` في سجل Cacti. لا يتأثر Cacti 1.2.31 ولا العقدة على المنفذ 161.

يقرأ الملحق العقدة عبر جلسة SNMP في Cacti، كما يفعل مستطلع Cacti، وهي لا تضع القوسين إلا حول عنوان يحتوي نقطتين رأسيتين، لذلك يعمل الجمع على أي منفذ. لكن فحص توفر SNMP الخاص بـCacti يستخدم الطلب المنفرد، لذا عند استخدام cmd.php كمستطلع تُعلَّم العقدة على منفذ آخر بأنها متوقفة وينتظر الملحق لإعادة فهرستها. على Cacti 1.2.x، إلى أن يُصلح ذلك في Cacti، أبقِ وكيل العقدة على المنفذ 161، أو اضبط كشف توقف الجهاز على Ping أو None. يستخدم الاختبار الشامل الخيار الأخير.
