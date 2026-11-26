<!-- Source: README.md; SHA256: 126deef1783d64f878bf565f1433c10f31aef828eb9bc313aeff2b0335e54c7c -->

<!-- translations:start -->
[English](../../../README.md) | [简体中文](../zh-CN/README.md) | [हिन्दी](../hi-IN/README.md) | [Español (España)](../es-ES/README.md) | [Español (Latinoamérica)](../es-419/README.md) | [العربية (السعودية)](../ar-SA/README.md) | [Afrikaans](../af-ZA/README.md) | [አማርኛ](../am-ET/README.md) | [العربية (مصر)](../ar-EG/README.md) | [العربية (المغرب)](../ar-MA/README.md) | [বাংলা](../bn-BD/README.md) | [Bosanski](../bs-BA/README.md) | [Deutsch](../de-DE/README.md) | [فارسی](../fa-IR/README.md) | [Filipino](../fil-PH/README.md) | [Français](../fr-FR/README.md) | [Français (Maroc)](../fr-MA/README.md) | [Français (Sénégal)](../fr-SN/README.md) | [Hausa](../ha-NG/README.md) | [עברית](../he-IL/README.md) | [Hrvatski](../hr-HR/README.md) | [Bahasa Indonesia](../id-ID/README.md) | [Igbo](../ig-NG/README.md) | [Italiano](../it-IT/README.md) | [日本語](../ja-JP/README.md) | [한국어](../ko-KR/README.md) | [Malagasy](../mg-MG/README.md) | [मराठी](../mr-IN/README.md) | [Bahasa Melayu](../ms-MY/README.md) | [Nederlands](../nl-NL/README.md) | [Polski](README.md) | [Português (Brasil)](../pt-BR/README.md) | [Русский](../ru-RU/README.md) | [Kinyarwanda](../rw-RW/README.md) | [Soomaali](../so-SO/README.md) | [Српски](../sr-RS/README.md) | [Svenska](../sv-SE/README.md) | [Kiswahili](../sw-KE/README.md) | [தமிழ்](../ta-IN/README.md) | [తెలుగు](../te-IN/README.md) | [ไทย](../th-TH/README.md) | [Türkçe](../tr-TR/README.md) | [Українська](../uk-UA/README.md) | [اردو](../ur-PK/README.md) | [Tiếng Việt](../vi-VN/README.md) | [isiXhosa](../xh-ZA/README.md) | [Yorùbá](../yo-NG/README.md) | [繁體中文](../zh-TW/README.md) | [isiZulu](../zu-ZA/README.md)
<!-- translations:end -->

# plugin_gpu

Wtyczka dla Cacti 1.2 dostarczająca do Cacti informacje o wykorzystaniu poszczególnych układów GPU, pamięci, temperaturze, poborze mocy, błędach ECC oraz przyczynach ograniczania taktowania (throttling) z narzędzi NVIDIA. Obsługa AMD oraz zbieranie błędów XID są planowane, lecz nie zostały jeszcze zaimplementowane. Każdy układ GPU staje się indeksem zapytania o dane w urządzeniu swojego węzła, dzięki czemu wykresy GPU znajdują się obok pozostałych wykresów węzła zgodnie z uprawnieniami Cacti, a wtyczki Thold i Maint mogą generować dla nich alerty oraz wyciszać je.

> **Status: w trakcie prac, wersja nieopublikowana.** Kamień milowy 4 kończy etap implementacji fazy 1 (NVIDIA przez SNMP extend): zbieranie danych, identyfikację, wykresy, sygnały throttlingu i ECC, alerty Thold i Maint, stronę stanu oraz dokumentację operatora. Rozwiązanie było uruchamiane wyłącznie z rzeczywistym demonem `snmpd` i atrapą `nvidia-smi` generującą syntetyczne dane wyjściowe, nigdy na fizycznym sprzęcie GPU. Przed wydaniem pozostają do wykonania: testy pilotażowe na rzeczywistym sprzęcie ([docs/pilot-plan.md](../../pilot-plan.md), #54), zewnętrzny przegląd bezpieczeństwa ([docs/security-review.md](../../security-review.md)), dane testowe zarejestrowane z rzeczywistych narzędzi (#14) oraz pomiary skali na sprzęcie klasy produkcyjnej ([docs/performance.md](../../performance.md)). Obsługa błędów XID oczekuje na decyzję #8. Nie należy uruchamiać wtyczki w środowisku produkcyjnym.

## Role dostępu

| Obszar | Dostęp |
| --- | --- |
| GPU View | Istniejące strony monitorowania. |
| GPU Operator | Monitorowanie oraz strona Operations i osobiste potwierdzenia w ramach klasyfikacji incydentów (triage). |
| GPU Admin | Dostęp Operator oraz istniejące działania konfiguracyjne i administracyjne. |

Po skopiowaniu plików aktualizacji należy otworzyć stronę Plugins jako administrator, aby zarejestrować nowy obszar. Obszar Operator należy przypisać jawnie w sekcji **Configuration > Users** lub w grupach użytkowników.
Aktualizacje zachowują istniejące identyfikatory obszarów View i Admin oraz przypisane uprawnienia. Dostęp Admin dziedziczy dostęp Operator, a dostęp Operator dziedziczy dostęp View przy każdym żądaniu; nie dodaje to trwałych uprawnień do użytkowników ani grup. Ustawienia wtyczki wymagają dodatkowo dostępu GPU Admin oprócz uprawnienia Settings w Cacti.

Uprawnienia do urządzeń w Cacti nadal ograniczają wiersze i działania dostępne dla każdego użytkownika; nieprzypisane obiekty wymagają uprawnień administratora.
Poświadczenia, konfiguracja, mapowanie, usuwanie i inne zmiany administracyjne pozostają na stronach administracyjnych. Potwierdzenia obowiązują wyłącznie w bieżącej sesji użytkownika, przez maksymalnie jedną godzinę i tylko tak długo, jak ta sama obserwacja pozostaje aktualna. Nowe lub zmienione obserwacje unieważniają je. Nigdy nie oznaczają błędnego pomiaru jako prawidłowego ani nie tłumią alertów.

## Uzasadnienie

Węzły GPU są zazwyczaj już obecne w Cacti w celu monitorowania CPU, pamięci i interfejsów. Stan GPU to osobna kwestia, na którą dane SNMP węzła nie odpowiadają: karta może podlegać throttlingowi termicznemu, gromadzić błędy ECC lub zgłaszać usterkę XID, podczas gdy sam węzeł wygląda na sprawny. Narzędzia producentów (`nvidia-smi`, DCGM, `amd-smi`) raportują te dane, ale ich wyjście pozostaje poza wykresami, uprawnieniami i systemem alertów Cacti.

Wtyczka wypełnia tę lukę, przekształcając wyjście narzędzi producentów w źródła danych Cacti, identyfikowane za pomocą tożsamości każdego GPU, a nie jego pozycji, dzięki czemu ponowne uruchomienie zmieniające kolejność GPU nie przypisuje historii jednej karty do drugiej. Wykorzystuje ponownie mechanizmy wykresów, uprawnień, zdalnych kolektorów danych, Thold i Maint z Cacti zamiast budowania ich równoległych odpowiedników.

## Planowany zakres

| Faza | Zakres | Kamienie milowe |
|---|---|---|
| 0, badania | Wyjście narzędzi producentów, sygnały XID i throttlingu, tożsamość GPU, uprawnienia, wybór ścieżki zbierania danych | [M1](https://github.com/somethingwithproof/plugin_gpu/milestone/1) |
| 1, nvidia | Zbieranie danych przez `nvidia-smi`, inwentaryzacja według UUID, wykresy, sygnały throttlingu, ECC i XID, Thold i Maint, strona stanu | [M2](https://github.com/somethingwithproof/plugin_gpu/milestone/2), [M3](https://github.com/somethingwithproof/plugin_gpu/milestone/3), [M4](https://github.com/somethingwithproof/plugin_gpu/milestone/4) |
| 2, backendy | Backend DCGM, backend `amd-smi` | [M5](https://github.com/somethingwithproof/plugin_gpu/milestone/5) |
| 3, mig i zdalne kolektory | Instancje MIG, zdalne kolektory danych Cacti | [M6](https://github.com/somethingwithproof/plugin_gpu/milestone/6) |

Poza zakresem: wszelkie polecenia modyfikujące stan GPU lub węzła (taktowania, limity mocy, resety, układ MIG), własny silnik alertów wtyczki, niestandardowy demon lub protokół sieciowy oraz rozliczanie zadań lub harmonogramowania. Zobacz plan, aby zapoznać się z listą zaległych prac badawczych.

## Co wprowadza kamień milowy 4

- **Przyczyny throttlingu jako wartości alertów.** Maska zdarzeń zegara jest dekodowana na jeden wskaźnik 0 lub 1 dla każdej przyczyny; nieznana maska daje nieznane wskaźniki, nigdy zera, a bity bez wskaźnika pozostają w surowej masce, którą wyświetla GPU Health.
- **ECC jako wartości alertów** ([docs/metrics.md](../../metrics.md)). Nowe błędy skorygowane i nieskorygowane od poprzedniej próbki są obliczane przez wtyczkę na podstawie dwóch kolejnych sum i zapisywane jako mierniki typu gauge: wartość nieznana dla pierwszej próbki, pominiętego odczytu lub przeładowania sterownika, dzięki czemu reset nigdy nie jest odczytywany jako zero ani jako wartość ujemna. Odczytywane są również liczby wycofanych stron jednobitowych i dwubitowych oraz tryb ECC. Dwa dodatkowe wykresy ECC na GPU umożliwiają Cacti ich przechowywanie; wraz z wykresem Power Percent daje to łącznie jedenaście wykresów. Błędy XID nie są zbierane: żadne źródło nie pasuje do stałych poleceń `nvidia-smi` (#8).
- **Thold i Maint** ([docs/alerting.md](../../alerting.md)). Dostarczane szablony Thold dla temperatury, poboru mocy względem limitu, wskaźników throttlingu, nowych nieskorygowanych błędów ECC, oczekującego wycofania stron oraz wieku próbki. Każda wartość jest typu gauge, ponieważ Thold 1.8.2 ulega awarii przy nieznanej wartości licznika. Test kompleksowy z niezmodyfikowanymi wtyczkami Thold 1.8.2 i Maint 1.3 potwierdza wyzwalanie i przywracanie każdego progu — także podczas resetu ECC — oraz ich wyciszanie przez Maint.
- **GPU Health** (GPU View). Na poziomie węzła: aktualność danych, sposób zakończenia ostatniego zbierania, stan Maint, backend, wersje narzędzia i sterownika oraz liczba GPU; na poziomie GPU: obecność, wiek próbki, stan ECC i surowa maska. Strona odczytuje wyłącznie zapisane rekordy.
- **Uprawnienia.** Zestaw testów uprawnień obejmuje każdy plik strony i każde działanie, cofnięcie uprawnień od kolejnego żądania oraz odmowy dostępu nieujawniające żadnych informacji.
- **Weryfikacja awarii i przywracania.** Zautomatyzowane testy kompleksowe zakłócają działanie agenta, poświadczeń, narzędzia, jego wyjścia, połączenia z bazą danych oraz zapisów RRD, a także przywracają bazę danych ze zrzutu, w tym starszego niż pliki RRD; nowy układ GPU nigdy nie dziedziczy pliku RRD innego urządzenia ([docs/backup.md](../../backup.md)).
- **Skala** ([docs/performance.md](../../performance.md)). Test skalowalności mierzy czas zbierania danych, wejścia serwera skryptów, tworzenia obiektów Cacti, cyklu pollera oraz strony stanu przy zadanej liczbie GPU; potok CI uruchamia go dla 100 GPU. Pomiary dla 1000 i 8000 GPU nie zostały jeszcze zarejestrowane, dlatego nie podano obsługiwanej liczby GPU.
- **Dokumentacja operatora** ([docs/operations.md](../../operations.md)): instalacja, aktualizacja, odinstalowanie, zasada najmniejszych uprawnień, wartości niedostępne oraz rozwiązywanie problemów. Zmiany są wymienione w pliku [CHANGELOG.md](../../../CHANGELOG.md).

## Co wprowadza kamień milowy 3

- **Zbieranie danych przez SNMP extend** ([ADR 0008](../../decisions/0008-collect-through-snmp-extend.md)). Administrator węzła dodaje dwa stałe wiersze `extend` do pliku `snmpd.conf` ([docs/node-setup.md](../../node-setup.md)); wtyczka odczytuje NET-SNMP-EXTEND-MIB za pomocą funkcji SNMP w Cacti z użyciem poświadczeń samego urządzenia, przy czym zalecany jest protokół SNMPv3. Nie uruchamia niczego na serwerze Cacti ani na węźle.
- **Ścisłe parsowanie.** Pola są odczytywane pozycyjnie, każdy wiersz identyfikuje swój układ GPU za pomocą UUID, a niezerowy kod wyjścia, przekroczenie limitu czasu, ucięte wyjście, wiersz o błędnej liczbie pól lub dołączony tekst błędu powodują niepowodzenie całego zbierania: wartości przyjmują `U`, żaden układ GPU nie jest oznaczany jako nieobecny, a strona GPU Nodes pokazuje przyczynę.
- **Kolektor po każdym cyklu pollera.** Odczytuje agenta każdego włączonego węzła, aktualizuje rekordy tożsamości i zapisuje najnowszą próbkę w pamięci podręcznej. Wejście serwera skryptów odczytuje wyłącznie tę pamięć podręczną, dzięki czemu odpytywanie w Cacti nigdy nie czeka na węzeł GPU.
- **Obiekty Cacti** ([ADR 0010](../../decisions/0010-attach-to-existing-node-devices.md)). Szablony oraz zapytanie o dane *GPU - Devices* są dostarczane jako pliki XML i importowane podczas instalacji oraz aktualizacji. Zapisanie węzła dołącza zapytanie o dane do jego istniejącego urządzenia z metodą ponownego indeksowania None; strona GPU Nodes może utworzyć urządzenie tylko wtedy, gdy węzeł jeszcze go nie ma. Każdy układ GPU stanowi indeks o kluczu złożonym z producenta i UUID, z jednym źródłem danych współdzielonym przez wykresy Utilization, Memory, Temperature, Power, Power Percent, Clocks, Throttle, ECC Errors, New ECC Errors, Retired Pages oraz Sample Age. Każde pole modelu jest rysowane przez któryś wykres, ponieważ Cacti przechowuje tylko pola używane przez wykresy. Wtyczka wykonuje ponowne indeksowanie po każdej zmianie GPU, przechowuje ponowne indeksowanie pominięte przez Cacti w czasie, gdy urządzenie było w stanie Down lub wyłączone, i uruchamia je, gdy urządzenie powróci do stanu Up, a także tworzy wykresy dla nowych GPU.
- **Historia pozostaje przypisana do karty.** Nieobecny układ GPU pozostaje jako indeks, więc jego wykresy wykazują przerwę i wznawiają zapis w tym samym pliku RRD po powrocie karty. Karta zastępcza w tym samym gnieździe otrzymuje własne źródło danych. Opcja **Remove from Data Query** na stronie węzła usuwa nieobecny układ GPU z listy; jego pliki RRD i wykresy są zachowywane do momentu ręcznego usunięcia.
- **Uprawnienia do urządzeń na stronie GPU Nodes.** Użytkownik z rolą GPU Admin widzi, zmienia, eksportuje i przenosi wyłącznie węzły i układy GPU na urządzeniach, do których ma dostęp w Cacti; przeniesienie GPU wymaga dostępu do obu węzłów. Węzeł, którego urządzenie zostało usunięte, można wyłączyć lub usunąć.
- **Wspólny model metryk** ([ADR 0009](../../decisions/0009-common-metric-core-with-vendor-extensions.md), [docs/metrics.md](../../metrics.md)). Pamięć wyrażana jest w bajtach, maska zdarzeń zegara jest dekodowana do wskaźników `thr_*`, a `age` oznacza wiek własnego znacznika czasu próbki GPU lub `U`, gdy jest nieznany.

Użytkownik instalujący (serwer WWW) musi mieć prawo zapisu w katalogu `<cacti>/resource/script_server/`, w którym wtyczka umieszcza plik XML zapytania o dane; w przeciwnym razie instalacja i aktualizacja przerywają działanie z odpowiednim komunikatem i ponawiają próbę przy kolejnym uruchomieniu. Odinstalowanie usuwa szablony i plik XML wyłącznie wtedy, gdy zaznaczona jest opcja *Remove the plugin's Cacti objects on uninstall*, i nigdy nie usuwa plików RRD, urządzeń, wykresów ani źródeł danych ([docs/operations.md](../../operations.md#uninstall)). Węzły na zdalnych kolektorach danych nie są odpytywane aż do fazy 3.

## Co dostarcza kamień milowy 2

- **Zabezpieczona instalacja.** W przypadku PHP w wersji niższej niż 8.2 instalacja i włączenie wtyczki są odrzucane z komunikatem wskazującym wymaganą wersję, a pozostała część Cacti działa bez zakłóceń. Plik `setup.php` oraz skrypty stron są poprawne składniowo w PHP 8.0.
- **Cykl życia.** Instalacja, aktualizacja, wyłączanie i odinstalowywanie za pośrednictwem strony Plugins w Cacti. Aktualizacje są uporządkowane sekwencyjnie, kończą się po przerwaniu i zmieniają zapisany numer wersji dopiero po pomyślnym zakończeniu. Odinstalowanie zachowuje tabele wtyczki, o ile nie zostanie odznaczona opcja *Keep Plugin Tables on Uninstall*; nigdy nie modyfikuje urządzenia, źródła danych, wykresu ani pliku RRD w Cacti.
- **Oddzielne obszary.** *GPU Admin* otwiera stronę **GPU Nodes**, na której rejestruje się urządzenia Cacti jako węzły GPU, potwierdza lub odrzuca przeniesienia GPU między węzłami oraz eksportuje wszystkie rekordy w formacie JSON. *GPU View* otwiera stronę **GPU Inventory**, wyświetlającą układy GPU wyłącznie na urządzeniach widocznych dla przeglądającego użytkownika. Obie strony znajdują się w sekcji Management, a każda zmiana jest żądaniem POST weryfikowanym przez csrf-magic.
- **Rekordy tożsamości GPU.** Każdy układ GPU jest identyfikowany przez producenta i UUID. Indeks, identyfikator magistrali PCI, nazwa, sterownik oraz narzędzie raportujące to atrybuty aktualizowane na miejscu. Nowy UUID w znanym gnieździe jest oznaczany jako potencjalna wymiana karty i rozpoczyna własną historię; układ GPU wykryty w innym węźle zostaje przeniesiony dopiero po potwierdzeniu przez operatora; układ GPU nieobecny w kolejnych udanych cyklach zbierania danych jest oznaczany jako nieobecny po przekroczeniu konfigurowalnej liczby prób i nigdy nie jest usuwany. Na ten moment akceptowane są wyłącznie pełne identyfikatory UUID układów NVIDIA GPU; instancje MIG oczekują na decyzję #13, a układy AMD na badania #19. Reguły są weryfikowane za pomocą sekwencji danych testowych, ponieważ żaden kolektor jeszcze ich nie zasila.
- **Testy i CI.** Zestawy testów jednostkowych i reguł kodu źródłowego bez użycia GPU i bazy danych, weryfikacja cyklu życia i uprawnień stron w środowiskach Cacti 1.2.31 oraz bieżącego 1.2.x na PHP od 8.2 do 8.5, zadanie weryfikujące odmowę dla PHP 8.1, kontrola składni punktów wejścia dla PHP 8.0, analiza PHPStan na poziomie 9 oraz REUSE. Środowisko testowe ma charakter tymczasowy do czasu podjęcia decyzji #12.

### Instalacja do celów ewaluacyjnych

Poniższe kroki instalują bieżący kod w celu jego przetestowania; pełny przewodnik operatora znajduje się w dokumencie [docs/operations.md](../../operations.md). Wymagają one Cacti 1.2.31 lub nowszego z PHP 8.2 lub nowszym.

1. Skopiuj repozytorium do katalogu `plugins/gpu` w katalogu instalacyjnym Cacti. Katalog musi nosić nazwę `gpu`.
2. W Cacti otwórz **Configuration > Plugins**, a następnie zainstaluj i włącz wtyczkę **GPU**.
3. Przypisz użytkownikom obszar *GPU View*, *GPU Operator* lub *GPU Admin* w sekcji **Configuration > Users** zgodnie z ich rolą. Użytkownik instalujący otrzymuje uprawnienia View oraz Admin; rola Admin obejmuje dostęp Operator.
4. Skonfiguruj agenta SNMP na każdym węźle GPU zgodnie z opisem w [docs/node-setup.md](../../node-setup.md), a następnie dodaj węzeł w sekcji **Management > GPU Nodes**.
5. Aby skonfigurować alerty, zaimportuj dostarczone szablony Thold zgodnie z instrukcją w [docs/alerting.md](../../alerting.md).
6. Opcjonalnie dostosuj ustawienia w sekcji **Configuration > Settings > GPU**: *Mark Absent After* (domyślnie 3 udane cykle zbierania, od 1 do 100), *Stale After* (maksymalny wiek próbki, po którym jej wartości są wypisywane jako `U`) oraz *Keep Plugin Tables on Uninstall* (domyślnie włączone).

## W jaki sposób metryki trafiają do Cacti

Decyzją [#7](https://github.com/somethingwithproof/plugin_gpu/issues/7) wybrano mechanizm SNMP `extend` ([ADR 0008](../../decisions/0008-collect-through-snmp-extend.md)): agent działający na węźle uruchamia stałe polecenie `nvidia-smi`, a wtyczka jedynie odczytuje wynik. W planie porównano dostępne opcje w sekcji [Ścieżki zbierania danych](../../PLAN.md#collection-paths).

## Docelowa zgodność

| Komponent | Wersja docelowa |
|---|---|
| Cacti | 1.2.31 lub nowsza, bez poprawek w kodzie głównym |
| PHP | 8.2 lub nowsza; `setup.php` zachowuje poprawność składniową w wersji 8.0, aby nieobsługiwane środowisko otrzymało komunikat odmowy zamiast uszkodzonego interfejsu Cacti |
| NVIDIA | Linie sterowników i narzędzi zostaną określone w badaniach [#26](https://github.com/somethingwithproof/plugin_gpu/issues/26); identyfikatory pól DCGM zweryfikowane w wersjach v3.3.9 i v4.7.0 |
| AMD | `amd-smi` w wydaniach ROCm zostanie określone w badaniach [#26](https://github.com/somethingwithproof/plugin_gpu/issues/26) |
| Thold, Maint | Thold 1.8.2 i Maint 1.3, bez modyfikacji, przetestowane kompleksowo ([docs/alerting.md](../../alerting.md)) |

## Architektura i projekt

- [docs/PLAN.md](../../PLAN.md) zawiera pełny plan: architekturę, fakty z projektów nadrzędnych, na których opiera się rozwiązanie (wraz ze źródłami), model tożsamości, granice bezpieczeństwa, obliczenia wydajnościowe, testowanie oraz ryzyka.
- [docs/decisions/](../../decisions) zawiera rekordy decyzji architektonicznych dla podjętych już wyborów.
- [docs/REQUIREMENTS.md](../../REQUIREMENTS.md) mapuje każdy identyfikator wymagania na odpowiadające mu zgłoszenie.

```mermaid
flowchart LR
    NODE[Węzeł GPU: snmpd extend uruchamiający nvidia-smi] -->|Odczyty SNMP, zalecane v3| C[Kolektor]
    C --> CACHE[(Pamięć podręczna wtyczki indeksowana według UUID GPU)]
    CACHE --> SS[Wejście zapytania o dane serwera skryptów]
    SS --> RRD[(Pliki RRD Cacti, jedno źródło danych na GPU)]
    SS -->|poller_output| T[Thold i Maint]
    RRD --> G[Wykresy i uprawnienia Cacti]
```

## Śledzenie prac

- **Kamienie milowe** odpowiadają kamieniom milowym planu. Każdy z nich ma zgłoszenie nadrzędne zawierające listę podzgłoszeń oraz dowody ukończenia.
- **Zgłoszenia** są klasyfikowane etykietami: `type: requirement`, `type: research`, `type: decision`, `type: epic`. Zgłoszenia wymagań określają numerowane, testowalne wymagania (`REQ-AREA-NNN`) oraz kryteria akceptacji.
- **Etykiety** zawierają również prefiksy `area:`, `phase:`, `priority:` oraz `status:`. Zobacz [CONTRIBUTING.md](../../../CONTRIBUTING.md#labels).
- [Tablica projektu](https://github.com/users/somethingwithproof/projects/9) prezentuje wszystkie zgłoszenia w podziale na kamienie milowe.

## Współpraca

Przeczytaj [CONTRIBUTING.md](../../../CONTRIBUTING.md). Commity wymagają podpisu DCO. Dyskusje projektowe odbywają się w zgłoszeniach; otwarte decyzje są oznaczone etykietą `type: decision`. Zarejestrowane wyjście z rzeczywistych narzędzi GPU jest najbardziej przydatnym wkładem w kamień milowy 1; przed przesłaniem należy usunąć nazwy hostów, numery seryjne oraz identyfikatory UUID.

## Bezpieczeństwo

Zgłaszaj podatności prywatnie; zobacz [SECURITY.md](../../../SECURITY.md).

## Licencja

GNU General Public License v2.0 — ta sama licencja co Cacti i jego wtyczki. Zobacz [LICENSE](../../../LICENSE).
