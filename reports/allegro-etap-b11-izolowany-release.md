# Allegro — B.1.1: izolowany mechanizm wydania infrastruktury

27.10.2026. Home & Garden Outlet, mgoutlet.pl.

**Wynik: przygotowano lokalnie oddzielny mechanizm ALLEGRO-ONLY. Nie wykonano deploya, push, commitów, Actions, FTPS, smoke produkcyjnego ani OAuth.** Pierwsza paczka ma OAuth i potwierdzenie logów trwale wyłączone w kodzie wydaniowym, niezależnie od env hostingu. Cały `/sale/...` pozostaje niedopuszczony. Nie rozpoczęto etapu C.

Repozytorium: `C:/Users/Albert/Documents/Strona www/github-deploy`.
HEAD zastany i końcowy: `849121bf20ec012f89c293b17e690ddf91c28b36`, gałąź `codex/gbp-read-diagnostics`.

## Audyt przed zmianami

Wskazany w zleceniu `reports/allegro-etap-b1-weryfikacja-srodowiska.md` nie istnieje. Przeczytano cały faktyczny raport `reports/allegro-etap-b1.md`, bez zmiany jego nazwy lub treści. Sprawdzono status/HEAD, obecny workflow, builder, helper prywatności, wszystkie dziewięć plików Allegro i diff współdzielonych plików. Porównanie wszystkich 30 hashów z `.local-cache/allegro-b1-baseline.json`: bez rozbieżności. Przed edycjami ustalono zakres uploadu i przekazano go w komunikacie do właściciela.

Wnioski B.1 pozostają aktualne: istniejący `deploy-getspace.yml` łączy wdrożenie całej strony z provisioning OLX oraz warunkową migracją productId. Nie jest deployem Allegro. W B.1.1 nie zmieniono ani bajtu tego workflow, buildera, helpera prywatności, aplikacji Allegro/OLX ani wcześniejszych dokumentów. Zastane sześć zmian śledzonych i nieśledzone pliki Etapu B nadal istnieją; nie przypisuje się ich do B.1.1.

## Dokładny zakres runtime — 11 plików

Jedyna autorytatywna lista ścieżek produkcyjnych to `ALLOWLIST` w `scripts/allegro-infra-release.py`. Build, weryfikacja, odczyt preimage, backup, upload, readback i rollback korzystają z tej samej listy.

| Źródło względem repo | Cel względem public_html |
|---|---|
| hosting/getspace/admin/allegro/.htaccess | admin/allegro/.htaccess |
| hosting/getspace/admin/allegro/config.php | admin/allegro/config.php |
| hosting/getspace/admin/allegro/storage.php | admin/allegro/storage.php |
| hosting/getspace/admin/allegro/http.php | admin/allegro/http.php |
| hosting/getspace/admin/allegro/oauth.php | admin/allegro/oauth.php |
| hosting/getspace/admin/allegro/lib.php | admin/allegro/lib.php |
| hosting/getspace/admin/allegro/bridge.js | admin/allegro/bridge.js |
| hosting/getspace/admin/allegro/callback.php | admin/allegro/callback.php |
| hosting/getspace/admin/allegro/index.php | admin/allegro/index.php |
| hosting/getspace/admin/.htaccess | admin/.htaccess |
| hosting/getspace/admin/index.php | admin/index.php |

Diff współdzielony zawiera dokładnie jedną regułę `^allegro/callback/?$ allegro/callback.php [END]` i jeden link `/admin/allegro/`. Builder usuwa te dodatki wyłącznie w pamięci i porównuje resztę z bazowym commitem powyżej. Inny diff zatrzymuje wydanie. Nie wdraża się wspólnego `admin/lib.php`, styli, root .htaccess, sklepu, Paynow ani katalogu produktów; są istniejącymi zależnościami hostingu, których zgodność trzeba potwierdzić przed wydaniem.

Nowe SOURCE/CI: osobny workflow, dwa helpery Python, test Python i ten raport. Żaden nie wchodzi do `runtime/`. Manifest jest obok `runtime/`, nigdy w public_html. Istniejący `publish/` jest całkowicie pomijany; dodanie do niego pliku nie wpływa na paczkę.

## Build i niezmienna blokada OAuth

Dedykowany builder nie uruchamia buildera całej strony, nie pobiera snapshotu i nie czyta `products.json`. Czyta wyłącznie 11 źródeł oraz publiczne wersje dwóch współdzielonych plików z Git. Weryfikuje zamrożony odcisk przejrzanego modułu B.1, znormalizowanego do LF:

`770c1e9dd135c60f5c1ee27dace6660669b2ffb1558d4713f9cd823b3aa8c44c`.

Każda zmiana modułu wymaga nowej świadomej oceny i aktualizacji odcisku. Nie wystarczy, że przyszły kod mieści się w nazwach allowlisty. Prosty skaner dodatkowo odrzuca literalne przypisania credentiali, PEM i typowe JWT. Nie jest ogólnym dowodem braku dowolnego sekretu; ochroną tego konkretnego wydania jest również zamrożona przejrzana zawartość oraz diff dwóch współdzielonych plików.

Wyjście ma LF. Tylko wydaniowy `config.php` podlega trzem dokładnym, sprawdzanym podmianom:

1. `enabled` jest dosłownie `false`.
2. `logs_safe` jest dosłownie `false`.
3. Walidacja konfiguracji dla OAuth bezwarunkowo zgłasza kontrolowane `disabled` przed dopuszczeniem operacji OAuth.

Źródłowy loader Etapu B pozostaje niezmieniony. Paczka nie jest jego zwykłą kopią — to jawnie deklarowany profil `B.1.1-OAUTH-OFF-v1`. Manifest zawiera `source_sha256` i wydaniowy `sha256` osobno dla każdego pliku; verifier odtwarza transformację i porównuje bajty. Zmiana fragmentów wejściowych, ręczna aktywacja paczki albo niedozwolone pliki dają FAIL. Samo przestawienie env na `1` nie odblokuje tego wydania. Przyszły OAuth wymaga osobnego przejrzanego wydania i zgody; nie usuwać tej blokady ręcznie na serwerze.

Test prawdziwego PHP na syntetycznej kopii potwierdza: anonimowo login; zalogowany status bez Connect; kompletna syntetyczna konfiguracja z obiema flagami env `1` nadal OFF; poprawny CSRF nie pozwala na connect/finish/check; callback czysty i bezpośredni z pustymi i sztucznymi parametrami daje kontrolowane 400, bez cookie i bez vaultu token/state/pending. Brak klucza/konfiguracji daje bezpieczne 400, bez Connect. cURL i URL fopen są wyłączone w procesie testowym. Nie wykonuje się prawdziwych połączeń Allegro.

Minimalny scope źródłowy nadal `allegro:api:profile:read`; whitelist HTTP pozostaje tylko token POST i `/me` GET. Odcisk modułu oraz 197 asercji chronią obecną politykę; nie dodano żadnego endpointu `/sale`.

## Manifest i lokalna paczka

Manifest: `.local-cache/allegro-infra-release-b11/allegro-infra-release-manifest.json`.
Runtime: `.local-cache/allegro-infra-release-b11/runtime/`.
SHA-256 lokalnego manifestu: `2e950cc6f8d3057d6a96305a7e0423009eeb031b9922b604b53e35f683291fd9`.

Manifest zawiera commit/ref, UTC, odcisk źródła runtime, SHA-256 źródła i payloadu każdego pliku, wymagane PHP 8.3, kwalifikację Node 22, rzeczywistą wersję Node buildu, `oauth_enabled=false`, `callback_logs_redacted=false`, `sale_api_enabled=false`, profil transformacji i dopuszczone preimages. Nie zawiera credentiali ani danych konta.

Lokalny odcisk źródła runtime: `786244c17f5c425e56545307b36a35eb5067546df3e9b47a7986c45750479262`.
Lokalne źródła są nadal częściowo nieśledzone; sam podany HEAD nie odtwarza integracji. Manifest lokalny dokumentuje kwalifikowany snapshot runtime, **nie jest zatwierdzonym artefaktem produkcyjnym**. Przyszły CI musi zbudować nowy manifest ze spójnego przejrzanego commitu. Verify w Actions wymaga rzeczywistego Node 22 i zgodności commit/ref z bieżącym uruchomieniem.

## Architektura workflow

Nowy plik: `.github/workflows/deploy-allegro-infra-getspace.yml`.

- Wyłącznie `workflow_dispatch`; domyślnie `qualify-only`. Brak push/PR/schedule i brak wywołania istniejącego workflow.
- QUALIFY: PHP 8.3 + curl/sodium, Node 22 i sprawdzenie rzeczywistych wersji; lint, JS, YAML, whitespace, testy lokalne.
- BUILD po QUALIFY: osobny builder 11 plików, manifest poza runtime, artefakt CI.
- VERIFY RELEASE po BUILD: pobranie artefaktu z tego runu, porównanie źródeł/hashów/list, testy paczki OFF, kontrola publicznego payloadu, PHP lint.
- Job produkcyjny zależy od wszystkich trzech. Wymaga `mode=deploy`, dokładnej frazy `DEPLOY_ALLEGRO_INFRA_OAUTH_OFF` i środowiska `allegro-infra-production`.
- Następnie osobne kroki: ponowny VERIFY/guard → BACKUP TARGET FILES → utrwalenie backupu jako artefaktu → DEPLOY ALLOWLIST → SMOKE TEST → warunkowy ROLLBACK → artefakt dziennika/odzyskiwania.

Wspólna grupa concurrency `getspace-production` zapobiega jednoczesnemu transferowi z istniejącym workflow. Nie zmienia jego treści ani zachowania poza naturalnym oczekiwaniem na tę samą blokadę. Nie chroni przed ręcznymi edycjami FTP lub innymi narzędziami operatora; wymagane jest wyłączne okno zmiany kodu.

Nie używa się `olx-production-prepare`, migratorów, produkcyjnej konfiguracji OLX, tokenów, chmod, katalogu produktów, pełnego mirror ani `mirror --delete`. Lokalne regresje OLX działają na atrapach. Test migracji ID, dodatkowo uruchomiony lokalnie, dotyczył wyłącznie danych syntetycznych, nie jest krokiem produkcyjnym nowego workflow.

**Osobna bramka publikacji źródeł:** GitHub wymaga obecności workflow_dispatch na gałęzi domyślnej, a istniejący workflow nadal reaguje na push main. Samo dodanie nowego workflow nie neutralizuje starego. Zwykłe scalenie/push całego Etapu B na main mogłoby uruchomić pełny, niezatwierdzony deploy. Przed publikacją właściciel musi zatwierdzić sposób bezpiecznego wprowadzenia workflow bez takiego skutku. GitHub dokumentuje znacznik komunikatu commitu `[skip ci]`, który pomija push/PR, lecz nie workflow_dispatch; jego zachowanie trzeba uwzględnić w finalnym commicie scalenia i sprawdzić zgodność z wymaganymi checks (mogą pozostać Pending). Nie stosowano tego znacznika ani nie wyłączano zdalnych workflow teraz. Alternatywna czasowa zmiana konfiguracji Actions wymaga odrębnej decyzji właściciela. Źródła: [ręczne uruchamianie](https://docs.github.com/en/actions/how-tos/manage-workflow-runs/manually-run-a-workflow), [pomijanie workflow](https://docs.github.com/en/actions/how-tos/manage-workflow-runs/skip-workflow-runs), odczyt 27.10.2026.

## GitHub Environment — status NIEPOTWIERDZONY

Przejrzane dotychczasowe workflow nie deklarują środowiska o tej nazwie. Narzędzia GitHub dostępne w sesji nie udostępniły dedykowanego odczytu konfiguracji environments; nie odczytywano wartości Secrets ani nie zakładano środowiska. Nie uznano braku wpisu w YAML za dowód braku zdalnego środowiska.

Przed przyszłym uruchomieniem właściciel musi potwierdzić dostępność ochrony w planie/widoczności repo, utworzyć lub sprawdzić środowisko, required reviewers, prevent self-review, brak bypass administratora i ograniczenie gałęzi/tagów. Guard GET oficjalnego GitHub API, z `actions:read`, wymaga tych właściwości. Brak odpowiedzi/pola, 403/404 albo automatycznie utworzone niechronione środowisko = FAIL przed FTPS, bez automatycznego obniżania zabezpieczeń.

Źródła: [GitHub — zarządzanie environments](https://docs.github.com/en/actions/how-tos/deploy/configure-and-manage-deployments/manage-environments) oraz [REST — odczyt environment](https://docs.github.com/en/rest/deployments/environments#get-an-environment), odczytane 27.10.2026. Potwierdzenie realnej odpowiedzi API pozostaje warunkiem odbioru mechanizmu na GitHub, nie wynikiem lokalnych atrap.

Przyszłe environment secrets mają dedykowane nazwy: `ALLEGRO_FTP_SERVER`, `ALLEGRO_FTP_USERNAME`, `ALLEGRO_FTP_PASSWORD`, `ALLEGRO_FTP_PUBLIC_DIR` oraz krótko żyjący `ALLEGRO_SMOKE_ADMIN_COOKIE`. Nie dodawano ich teraz. To credentiale transferu i istniejącej sesji panelu, **nie credentiale Allegro**. Cookie tylko `hgo_admin=...`, bez nagłówków/CRLF, jest używane wyłącznie do GET panelu Allegro; nie trafia do manifestu, raportu, argv ani artefaktu. Operator ma odwołać sesję/usunąć sekret po odbiorze. Nie automatyzuje się logowania ani OAuth.

## Transfer, backup i konflikty

FTPS jawny TLS na 21, domyślny zaufany SSLContext, weryfikacja hosta/certyfikatu, chroniony kanał danych, timeout i limit pobieranego pliku kodu 1 MiB. Katalog docelowy musi jawnie kończyć się `public_html`; zakaz `..` i nietypowych znaków. Operator musi potwierdzić prawdziwy mapping konta/DocumentRoot i brak aliasów/symlinków. MLSD jest wymagany: listuje wyłącznie nazwy/typy w root/admin/module w celu kontroli regularnych ścieżek i braku plików; nie pobiera zawartości innych plików. Sam błąd 550 nie jest traktowany jako dowód nieistnienia pliku.

Przed jakimkolwiek zapisem wykonuje się pełny preflight 11 ścieżek. Współdzielone pliki muszą istnieć i odpowiadać znanej bazie LF/CRLF albo dokładnemu payloadowi. Nowe pliki modułu mogą nie istnieć; istniejące muszą już odpowiadać dokładnemu payloadowi OFF. Każda nieznana wersja = FAIL bez transferu, bez automatycznego nadpisania. Nie dopisuje się nowych dopuszczonych hashów z nieprzejrzanej produkcji.

Backup jest lokalny na runnerze: tylko istniejące pliki spośród 11 i JSON z hashami oraz informacją o nieistnieniu nowych ścieżek. Nie tworzy się niczego w prywatnych katalogach serwera ani pełnej kopii witryny. Kod preimage ma znany hash publicznych źródeł. Po pełnej weryfikacji backup jest utrwalany jako osobny artefakt Actions **przed** pierwszym uploadem; niepowodzenie utrwalenia blokuje deploy. Retencja 14 dni — operator musi pobrać/zachować kopię kodu dłużej, jeżeli wymaga tego plan odtworzenia. Zasady dostępu do artefaktów sprawdzić na repozytorium; backup nie zawiera konfiguracji prywatnych.

Przed transferem i każdym plikiem ponownie sprawdza się preimage. Najpierw reguły deny modułu i biblioteki, potem wejścia oraz wspólna trasa/nawigacja. Jedyny tworzony katalog WWW to brakujący `admin/allegro`; nie tworzy się private_allegro. Każdy STOR ma zapis zamiaru przed transferem, potem pobranie kodu i porównanie bajtów. Nie ma transferu dowolnego drzewa, plików staging w WWW ani manifestu do WWW.

**Ograniczenie:** STOR pliku i całe wydanie nie są transakcją atomową; przy przerwaniu możliwy jest chwilowo częściowy plik/500. Journal obejmuje także niedokończony STOR. Wymagane spokojne okno zmiany i skuteczny rollback. To nie jest gwarancja zerowego downtime ani zdalnie przetestowany adapter Getspace.

## Rollback

Po błędzie/przerwaniu kroku deploy lub smoke uruchamiany jest rollback zapisanych prób, w odwrotnej kolejności. Odtwarza istniejący preimage i sprawdza hash; usuwa tylko nowy plik, który nie istniał przed tą próbą. Nie kasuje rekurencyjnie katalogów. Pliki poza allowlistą i nieobjęte próbą pozostają nietknięte.

Odtworzenie nie nadpisuje nieznanej współbieżnej wersji: dopuszcza własny pełny/częściowy payload albo znany preimage. Konflikt lub błąd pojedynczego pliku zgłasza niepełny rollback i próbuje odzyskać pozostałe ścieżki. Przy błędzie odzyskiwania modułu zachowuje deny `.htaccess`. Odzyskiwanie nie zależy od dostępności API GitHub lub ważności sesji smoke, ale nadal wymaga jawnego działania w zatwierdzonym jobie oraz poprawnego manifestu, backupu i journalu.

Twarda utrata runnera/timeout całego joba może uniemożliwić automatyczny rollback i zapis końcowego journalu. Backup sprzed transferu jest już artefaktem. W takim przypadku operator ma przejąć odtworzenie wyłącznie tych samych plików ze sprawdzonej kopii, na podstawie znanych hashów i stanu serwera, po osobnej decyzji o odzyskiwaniu. Nie uruchamiać istniejącego pełnego workflow jako zamiennika rollbacku. Nie przygotowano ani nie uruchomiono dodatkowego zdalnego joba odzyskiwania.

## Przygotowane smoke — NIEURUCHOMIONE na produkcji

`scripts/allegro-infra-smoke.py` wymaga jawnego `--execute`. Adres hosta jest stały HTTPS mgoutlet.pl; wyłącznie GET, bez podążania za przekierowaniem. Żadnego kodu/state/authorize/token/me/sale, POST ani realnego OAuth. Sprawdza:

| Trasa | Wymagany wynik |
|---|---|
| /admin/allegro/ anonimowo | 302/303 do /admin/ |
| /admin/allegro/ z istniejącą sesją | 200 lub kontrolowane 400, status konfiguracji, OAuth Wyłączony, brak Connect/autoFinish |
| /admin/allegro/callback | 400 kontrolowane, bez Location/Set-Cookie |
| /admin/allegro/callback.php | analogicznie |
| lib/config/storage/http/oauth.php | 403/404, bez źródła |
| /admin/olx/ anonimowo | bramka login; bez wejścia w konfigurację konta OLX |
| /admin/olx/lib.php | 403/404 |
| /, /dom, /ogrod | 200 |

Raportuje efektywne CSP, Cache-Control, Referrer-Policy, status, Location bez query i atrybuty Set-Cookie bez wartości. Wymaga no-store/no-referrer, pojedynczego pełnego CSP dla tras Allegro oraz Strict/HttpOnly/Secure dla wystawionego hgo_admin. Konflikt CSP jest FAIL do oceny operatora, nie automatyczne osłabienie polityki. Nie utrwala body, cookie, odpowiedzi transportu z credentialami ani pełnych URL z query. Rzeczywiste nagłówki/Apache/aliasy/SameSite pozostają niepotwierdzone; lokalny PHP server nie testuje Apache.

Kod testowy dowodzi braku wymiany tokenów przy OFF; GET smoke obserwuje wynik HTTP i nie stanowi samodzielnego dowodu braku każdego połączenia wychodzącego serwera. Przed odbiorem potrzebne są także zweryfikowane hashe uploadu i bramki runtime.

## Wyniki lokalne

Windows; PHP portable 8.5.11 z curl/sodium, Node 24.22.1, Python 3.12, PyYAML 6.0.3. PHP 8.3/Node 22 lokalnie nie były dostępne jako używany runtime i nie instalowano ich systemowo. CI ma jawnie kwalifikować 8.3/22; **wyniku CI jeszcze nie ma**, ponieważ zakazano uruchomienia Actions. YAML i wszystkie 12 bloków run sprawdzono lokalnie parserem YAML i `bash -n`.

| Zestaw/kontrola | Wynik |
|---|---|
| tests/allegro-infra-release-tests.py | PASS — 17 testów, w tym realna syntetyczna paczka PHP OFF |
| tests/allegro-oauth-tests.php | PASS — 197 asercji |
| tests/allegro-http-tests.mjs | PASS — także save_product bez Allegro, współbieżny refresh i atomowe odczyty |
| tests/allegro-artifact-tests.mjs | PASS |
| tests/allegro-artifact-tests.mjs --artifact=.local-cache/allegro-infra-release-b11/runtime | PASS |
| tests/olx-oauth-tests.php | PASS |
| tests/olx-http-tests.mjs | PASS |
| tests/olx-identity-verification-tests.mjs | PASS |
| tests/product-id-migration-tests.php | PASS — wyłącznie syntetyczny katalog |
| tests/admin-figure-entry-tests.php | PASS |
| tests/catalog-presentation-tests.php | PASS |
| tests/prerender-privacy-tests.mjs | PASS |
| tests/public-products-snapshot-tests.mjs | PASS |
| Dedykowany build i verify manifestu/list/hashów | PASS |
| PHP lint źródła i wszystkich PHP paczki | PASS |
| node --check źródłowego i wydaniowego bridge.js | PASS |
| Python AST trzech nowych plików | PASS |
| YAML parse, statyczny graf jobów/triggerów, bash -n 12 kroków | PASS |
| git diff --check i whitespace nowych plików | PASS |
| Porównanie 30 źródeł B.1 | Bez zmian |

Testy nowego mechanizmu obejmują nadmiarowe pliki prywatne/OLX/products/uploads, zmieniony manifest/payload, wykrycie literalnego sekretu, TLS i ograniczenie adaptera FTPS na atrapie, konfigurację ochrony environment, konflikt/missing preimage, współbieżną zmianę, uszkodzony backup, przerwany STOR, dokładny rollback i odmowę nadpisania obcego kodu, redakcję cookie oraz odmowę przypadkowego uruchomienia zdalnego CLI. Nie stanowią próby FTPS Getspace.

Nie przebudowywano całego serwisu ani kart produktów: nowe wydanie nie zawiera tych plików. Kontrolę prywatności wykonano na faktycznym minimalnym publicznym runtime i syntetycznych regresjach snapshot/prerender. Nie podaje się starego testu pełnego public-artifact jako nowego wyniku.

## Bramki przed przyszłym, osobno zatwierdzonym wdrożeniem

1. Przejrzeć i przygotować kompletny commit B/B.1.1, wraz z nowymi plikami, bez cudzych raportów. Nie pushować teraz na main: istniejący push workflow nadal wykonuje pełny deploy.
2. Potwierdzić GitHub Environment i plan/reviewerów, dedykowane credentiale FTPS, mapę public_html, brak symlinków/aliasów, możliwość MLSD i prawa tylko do zamierzonego kodu. Nigdy nie przekazywać credentiali w raporcie.
3. Potwierdzić na hostingu runtime, zgodność istniejących zależności admina i brak aktywnej odziedziczonej konfiguracji Allegro (G01–G04). Mechanizm nie odczytuje prywatnych configów w celu samodzielnej diagnozy hostingu.
4. Dopiero po odrębnej zgodzie uruchomić qualify-only i uzyskać PASS PHP 8.3/Node 22 oraz świeży manifest. Obecnego lokalnego wyniku 8.5/24 nie traktować jako zastępstwa.
5. Przedstawić konkretny ref/manifest/preimages, okno zmiany, ograniczony backup i plan odzyskania. Osobna zgoda na deploy musi dotyczyć tych 11 plików i OAuth OFF. Zweryfikować retencję backupu i świeżą sesję smoke.
6. Po przyszłym wdrożeniu odebrać G08–G15: Apache/trasy/headers/sesja/publiczna regresja; przy FAIL uruchomić ograniczony rollback. OLX dane, Paynow, produkty i uploady pozostają poza transferem i odtwarzaniem.
7. **G16 pozostaje twardym blockerem przed OAuth.** Tylko operator może potwierdzić redakcję query w Apache/proxy/CDN/WAF/APM/logach hostingu. PHP i flaga logs_safe tego nie naprawiają. Wydanie OFF może później zostać zatwierdzone bez G16, ale nigdy prawdziwy OAuth. Pozostałe bramki G17–G21, rejestracja aplikacji, reprezentacja scope i test rzeczywistej przeglądarki/10 sekund nadal wymagają osobnego etapu i zgody.

**B.1.1 usuwa lokalny problem braku osobnej ścieżki wydania. Nie zamyka kwalifikacji CI ani kontroli Getspace i nie daje zgody na uruchomienie wdrożenia lub OAuth.**
