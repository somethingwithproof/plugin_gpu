<!-- Source: docs/node-setup.md; SHA256: 3babf869a8665fbf304f5b8f079391435900cf50ab354addb85d287dd9dacdbc -->

<a id="setting-up-a-gpu-node"></a>
# GPU नोड सेट करना

प्लगइन NET-SNMP-EXTEND-MIB ([ADR 0008](../../../decisions/0008-collect-through-snmp-extend.md)) के माध्यम से हर GPU नोड के SNMP एजेंट से उसका `nvidia-smi` आउटपुट पढ़ता है। नोड प्रशासक `snmpd.conf` में दो `extend` प्रविष्टियाँ जोड़ता है और केवल पुराने `nvidia-smi` के लिए तीसरी प्रविष्टि जोड़ता है। प्लगइन नोड के Cacti डिवाइस की SNMP सेटिंग से एजेंट को केवल पढ़ने के अनुरोध भेजता है। नोड पर `snmpd` (net-snmp) के अलावा कुछ इंस्टॉल नहीं किया जाता।

<a id="snmpdconf"></a>
## snmpd.conf

हर नोड को नीचे दी गई दोनों पंक्तियाँ ठीक इसी रूप में चाहिए। प्रविष्टि के नाम, फ़ील्ड सूची और क्रम बनाए रखें: प्लगइन फ़ील्ड को स्थान के आधार पर पढ़ता है। गलत फ़ील्ड संख्या अस्वीकृत होती है, लेकिन वैध संख्यात्मक फ़ील्ड का क्रम बदलने पर बिना पहचान के गलत अर्थ निकाला जा सकता है।

<!-- extend-lines:start -->
```
extend -cacheTime 30 cacti_gpu /usr/bin/env -i TZ=UTC /usr/bin/timeout -k 2 10 /usr/bin/nvidia-smi --query-gpu=timestamp,uuid,index,pci.bus_id,driver_version,ecc.mode.current,utilization.gpu,utilization.memory,memory.used,memory.total,temperature.gpu,power.draw,power.limit,clocks.sm,clocks.mem,ecc.errors.corrected.volatile.total,ecc.errors.uncorrected.volatile.total,retired_pages.pending,retired_pages.sbe,retired_pages.dbe,clocks_event_reasons.active --format=csv,noheader,nounits
extend -cacheTime 300 cacti_gpu_names /usr/bin/env -i /usr/bin/timeout -k 2 10 /usr/bin/nvidia-smi --query-gpu=uuid,name --format=csv,noheader
```
<!-- extend-lines:end -->

हर भाग का काम:

- `-execType sh` के बिना `extend` प्रोग्राम को निश्चित आर्ग्युमेंट वेक्टर से, बिना शेल के चलाता है। Cacti, नोड या GPU से कोई जानकारी कमांड लाइन तक नहीं पहुँचती।
- `/usr/bin/env -i` टूल को खाली परिवेश से शुरू करता है। मेट्रिक प्रविष्टि केवल `TZ=UTC` सेट करती है, क्योंकि `nvidia-smi` नमूने का समय बिना समय क्षेत्र के छापता है और प्लगइन उसे UTC मानता है।
- `/usr/bin/timeout -k 2 10` टूल को 10 सेकंड बाद रोकता है और उसके 2 सेकंड बाद बलपूर्वक समाप्त करता है। snmpd अपनी चाइल्ड प्रक्रिया की प्रतीक्षा में खुद कोई टाइमआउट नहीं लगाता, इसलिए इसके बिना अटका `nvidia-smi` एजेंट को रोक सकता है।
- `-cacheTime 30` एक नमूना 30 सेकंड तक रखता है, और टाइमआउट इस अवधि से कम है, इसलिए अगला रन शुरू हो सकने से पहले वर्तमान रन हमेशा समाप्त हो जाता है। इस अवधि में Cacti के सभी पोल वही नमूना देखते हैं और प्लगइन नमूने की अपनी आयु बताता है।
- GPU नाम अलग प्रविष्टि से आते हैं क्योंकि उत्पाद का नाम मुक्त पाठ होता है जिसमें CSV विभाजक हो सकता है। नाम शायद ही बदलते हैं, इसलिए यह प्रविष्टि 5 मिनट तक कैश होती है।
- हर कमांड और उसके आर्ग्युमेंट extend की 510-बाइट सीमा से छोटे हैं।
- प्रति नोड अधिकतम 128 GPU समर्थित हैं ताकि मेट्रिक आउटपुट snmpd की 100 KiB सीमा से काफी कम रहे। संकलित फ़िक्स्चर में मेट्रिक पंक्ति 194 से 197 बाइट की थी, और सभी मेट्रिक `[Not Supported]` छापें तो 370 बाइट की: 128 GPU के लिए अधिकतम लगभग 46 KiB। फ़िक्स्चर में नाम प्रविष्टि का आउटपुट प्रति GPU 64 बाइट है। सीमा से ऊपर, संपूर्ण परीक्षण में snmpd ने बिना आउटपुट निकास स्थिति 4 दी, जिसे प्लगइन *अनुमति अस्वीकृत* दिखाता है; इसका कारण स्पष्ट नहीं है, इसलिए सीमा के भीतर रहें।

प्लगइन इन सभी स्थितियों को डेटा के बजाय अज्ञात मानों वाला विफल संग्रह मानता है: शून्य से अलग निकास स्थिति (टाइमआउट सहित), snmpd की 100 KiB या 1024-पंक्ति सीमा पर कटा आउटपुट, माँगे गए से कम या अधिक फ़ील्ड वाली पंक्ति और आउटपुट में कोई अन्य पाठ, जैसे stderr से snmpd द्वारा मिलाया गया त्रुटि संदेश।

<a id="optional-an-nvidia-smi-older-than-the-clock-event-reasons-rename"></a>
### वैकल्पिक: क्लॉक इवेंट कारणों के नाम बदलने से पुराना nvidia-smi

मेट्रिक प्रविष्टि क्लॉक इवेंट कारण `clocks_event_reasons.active` नाम से माँगती है (REQ-NVSMI-005)। nvidia-smi के बदलाव विवरण के अनुसार v530 में `clocks_throttle_reasons.*` का नाम बदला और v535 में पुराने क्वेरी नाम फिर जोड़े गए ([शोध](../../../research/clock-event-reasons.md)), इसलिए पुराना `nvidia-smi` केवल पुराना नाम जान सकता है; ये संस्करण किन ड्राइवर रिलीज़ से संबंधित हैं, इसकी पुष्टि नहीं हुई है। ऐसा टूल मेट्रिक प्रविष्टि को स्थिति 2 से अस्वीकार करता है, जो nvidia-smi में अमान्य आर्ग्युमेंट की स्थिति है; नोड का संग्रह तब तक विफल होगा जब तक आप यह पंक्ति भी नहीं जोड़ते:

<!-- extend-legacy:start -->
```
extend -cacheTime 30 cacti_gpu_legacy /usr/bin/env -i TZ=UTC /usr/bin/timeout -k 2 10 /usr/bin/nvidia-smi --query-gpu=timestamp,uuid,index,pci.bus_id,driver_version,ecc.mode.current,utilization.gpu,utilization.memory,memory.used,memory.total,temperature.gpu,power.draw,power.limit,clocks.sm,clocks.mem,ecc.errors.corrected.volatile.total,ecc.errors.uncorrected.volatile.total,retired_pages.pending,retired_pages.sbe,retired_pages.dbe,clocks_throttle_reasons.active --format=csv,noheader,nounits
```
<!-- extend-legacy:end -->

`cacti_gpu_legacy` वही मेट्रिक क्वेरी है जिसमें उसी स्थान पर पुराना नाम `clocks_throttle_reasons.active` है। प्लगइन उसे तभी पढ़ता है जब `cacti_gpu` स्थिति 2 से समाप्त होता है, इसलिए वर्तमान ड्राइवर पर वह कभी नहीं चलता। दोनों नाम समान बिटमास्क छापते हैं और प्लगइन उसे समान ढंग से डिकोड करता है। इस पृष्ठ के पुराने संस्करण के अनुसार सेट नोड की प्राथमिक मेट्रिक पंक्ति को ऊपर दिखाई गई `cacti_gpu_legacy` क्वेरी से बदलना आवश्यक है, केवल उसका प्रविष्टि नाम `cacti_gpu` करें और अंतिम फ़ील्ड `clocks_throttle_reasons.active` ही रखें: यह रिलीज़ फ़ील्ड सूची में `ecc.mode.current`, `retired_pages.sbe` और `retired_pages.dbe` जोड़ती है। पंक्ति बदलने तक फ़ील्ड संख्या न मिलने के कारण नोड का संग्रह *आउटपुट अस्वीकार* से विफल होगा। स्थिति 2 वाला वैकल्पिक तरीका यह समस्या नहीं संभालता, क्योंकि पुरानी पंक्ति सफलतापूर्वक चलती है। केवल क्लॉक इवेंट कारण का नाम अलग हो सकता है: वर्तमान फ़ील्ड सूची और अंतिम स्थान पर `clocks_throttle_reasons.active` वाली `cacti_gpu` पंक्ति भी काम करती है, क्योंकि दोनों नाम वहाँ समान बिटमास्क छापते हैं।

<a id="run-snmpd-as-an-unprivileged-user"></a>
## snmpd को बिना विशेषाधिकार वाले उपयोगकर्ता से चलाएँ

`extend` कमांड उसी उपयोगकर्ता से चलती है जिससे `snmpd` चलता है। `agentuser` या `-u` सेट न होने पर यह root है। ऊपर के फ़ील्ड को विशेषाधिकार नहीं चाहिए ([research/privileges.md](../../../research/privileges.md))। एजेंट को बिना विशेषाधिकार वाले खाते से चलाएँ:

```
agentuser Debian-snmp
```

अपने वितरण के snmpd पैकेज द्वारा दिया गया खाता उपयोग करें (Debian और Ubuntu `Debian-snmp` देते हैं और snmpd को `-u Debian-snmp` से शुरू करते हैं), या खाता बनाएँ। प्लगइन नहीं देख सकता कि कमांड किस उपयोगकर्ता ने चलाई, इसलिए संग्रह चलते समय इसकी पुष्टि करें, जैसे `ps -o user= -C nvidia-smi` से। हर वितरण में डिफ़ॉल्ट उपयोगकर्ता कौन है, यह #22 की वास्तविक वातावरण जाँच का हिस्सा है।

<a id="snmpv3-is-the-default"></a>
## SNMPv3 डिफ़ॉल्ट है

प्रमाणीकरण और एन्क्रिप्शन के साथ SNMPv3 उपयोग करें तथा उपयोगकर्ता को केवल सिस्टम समूह और extend तालिकाओं तक सीमित रखें:

```
view   cactigpu included .1.3.6.1.2.1.1
view   cactigpu included .1.3.6.1.4.1.8072.1.3.2
rouser -s usm cactigpu priv -V cactigpu
```

उपयोगकर्ता `net-snmp-create-v3-user` से बनाएँ, या snmpd बंद रहते हुए उसके स्थायी कॉन्फ़िगरेशन (Debian और Ubuntu पर `/var/lib/snmp/snmpd.conf`) में `createUser cactigpu SHA-256 <auth passphrase> AES <privacy passphrase>` पंक्ति जोड़ें। फिर नोड के Cacti डिवाइस को उसी उपयोगकर्ता, SHA-256 प्रमाणीकरण और AES एन्क्रिप्शन के साथ SNMP संस्करण 3 पर सेट करें।

SNMP v1 और v2c भी काम करते हैं, लेकिन कम्युनिटी और सभी मान सादे पाठ में भेजते हैं। यदि उनका उपयोग करें तो कम्युनिटी को उसी व्यू और Cacti पोलर के पते तक सीमित रखें, उदाहरण: `rocommunity <community> <poller address> -V cactigpu`।

<a id="in-cacti"></a>
## Cacti में

1. सुनिश्चित करें कि नोड चालू SNMP सेटिंग वाला Cacti डिवाइस है। यदि नहीं, तो GPU Nodes साइट की डिवाइस डिफ़ॉल्ट सेटिंग से उसे बना सकता है; इसके बाद Cacti के डिवाइस पृष्ठ पर उसकी SNMP सेटिंग करें।
2. **कंसोल > प्रबंधन > GPU नोड** में डिवाइस के लिए नोड जोड़ें। नोड सहेजने से GPU डेटा क्वेरी डिवाइस में जुड़ती है।
3. कलेक्टर हर पोलर चक्र के अंत में चलता है। मिलने वाला हर GPU, GPU डेटा क्वेरी का एक इंडेक्स बनता है और प्लगइन उसके GPU ग्राफ़ बनाता है।

दूरस्थ डेटा कलेक्टर पर स्थित नोड अभी संकलित नहीं किए जाते; दूरस्थ कलेक्टर चरण 3 में हैं।

<a id="an-snmp-port-other-than-161-on-cacti-12x"></a>
### Cacti 1.2.x पर 161 के अलावा SNMP पोर्ट

Cacti की 1.2.x शाखा (1.2.31 के बाद) पोर्ट 161 न होने पर एकल SNMP get के लिए डिवाइस का पता कोष्ठकों में रखती है, जैसे `[192.0.2.10]:1161`। PHP का SNMP एक्सटेंशन कोष्ठक वाले नाम को IPv6 पता मानता है, इसलिए IPv4 पते या बिना IPv6 पते वाले होस्ट नाम पर get विफल होता है और Cacti लॉग में `Unknown failure while resolving '[192.0.2.10]:1161'` आता है। Cacti 1.2.31 और पोर्ट 161 वाला नोड प्रभावित नहीं हैं।

प्लगइन Cacti के पोलर की तरह Cacti SNMP सत्र से नोड पढ़ता है, जो केवल कोलन वाले पते के आसपास कोष्ठक लगाता है, इसलिए संग्रह किसी भी पोर्ट पर काम करता है। लेकिन Cacti की अपनी SNMP उपलब्धता जाँच एकल get उपयोग करती है, इसलिए cmd.php पोलर होने पर दूसरे पोर्ट वाला नोड डाउन चिह्नित होता है और प्लगइन उसे रीइंडेक्स करने की प्रतीक्षा करता है। Cacti 1.2.x में मूल समस्या ठीक होने तक एजेंट को पोर्ट 161 पर रखें या डिवाइस का Downed Device Detection, Ping या None पर सेट करें। संपूर्ण परीक्षण अंतिम विकल्प उपयोग करता है।
