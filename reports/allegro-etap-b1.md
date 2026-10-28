# Allegro — etap B.1: audyt gotowości infrastruktury

**27.10.2026, Europe/Warsaw. Home & Garden Outlet, mgoutlet.pl.**

Repozytorium: `C:/Users/Albert/Documents/Strona www/github-deploy`.
HEAD: `849121bf20ec012f89c293b17e690ddf91c28b36`.
Gałąź lokalna: `codex/gbp-read-diagnostics`.

**Wniosek:** aktualne testy infrastruktury Allegro przechodzą, ale **nie ma bezwarunkowej gotowości do uruchomienia obecnego deploy workflow ani do prawdziwego OAuth**. Najważniejsza dodatkowa bramka wdrożenia: workflow wykonuje również przygotowanie prywatnego OLX i może migrować productId. Nie jest izolowanym wdrożeniem Allegro. Logi, rzeczywiste flagi wyłączenia OAuth i pozostałe właściwości Getspace pozostają niepotwierdzone.

W B.1 **nie zmieniono kodu ani konfiguracji aplikacji, OLX, danych produktów lub workflow**. Nie wykonano commitów, push, Actions, FTPS, logowania do Getspace/Allegro, tworzenia aplikacji Sandbox ani OAuth. Utworzono ten raport i ignorowane lokalne dowody audytu; przebudowano wyłącznie ignorowany lokalny `publish/`. Nie rozpoczęto etapu C.

## 1. Zakres i sposób weryfikacji

Przeczytano cały `reports/allegro-etap-b.md` i porównano jego stwierdzenia z bieżącą implementacją. Uwzględniono kontekst `reports/allegro-audyt-etap-a.md` oraz `documents/allegro-etap-b.md`. Audyt objął wszystkie dziewięć plików `hosting/getspace/admin/allegro/`, nadrzędny i główny .htaccess, nawigację i zapis produktów, model sesji, README, Git ignore, builder, helper prywatności, cały workflow, jego helper OLX oraz testy.

Przed kontrolami zapisano SHA-256 30 plików źródłowych/dokumentów w `.local-cache/allegro-b1-baseline.json`; na zakończenie porównano je ponownie. Manifest nie zawiera wartości sekretów. Stan Git sprawdzono przed pracą. Zastanych zmian i nieśledzonych raportów nie cofano.

Odczyty sieciowe dotyczyły oficjalnej dokumentacji i OpenAPI. Nie wywoływano OAuth/token endpointów, zasobów konta /me ani API sprzedażowego. Nie odczytywano prywatnych danych serwera, środowiska GitHub Secrets ani produkcyjnych tokenów. Z tego powodu **brak dowodu wdrożenia/OAuth przez inne osoby nie jest dowodem, że nikt ich nie wykonał**. Raport potwierdza zakres naszych działań i lokalny stan; aktualny hosting wymaga odrębnej kontroli operatora.

## 2. Stan repozytorium a raport B

| Stwierdzenie raportu B | Aktualny wynik |
|---|---|
| Osobny moduł Allegro | POTWIERDZONE: dziewięć plików, bez refaktoryzacji OLX |
| Authorization Code, PKCE S256, state | POTWIERDZONE w kodzie i testach |
| Verifier serwerowo, 86 znaków | POTWIERDZONE; challenge bez paddingu, SHA-256 |
| State 10 min, pending 8 s | POTWIERDZONE; jednorazowość i kontrola czasu po uzyskaniu locka |
| Szyfrowany/atomowy vault | POTWIERDZONE lokalnie; realne prawa/filesystem hostingu niepotwierdzone |
| Rozdzielone PROD/SANDBOX | POTWIERDZONE w ścieżkach, kontekście vaultu i hostach; realne credentiale nieodczytane |
| /sale write-disabled | POTWIERDZONE: niedopuszczony jest cały /sale, nie tylko mutacje |
| Zapis produktu bez Allegro | POTWIERDZONE realnym save_product na kopii testowej |
| 197 asercji | POTWIERDZONE ponownym uruchomieniem |
| Regresja OLX | PASS lokalnych testów; nie jest to próba połączenia prawdziwego konta |
| Brak deploya/OAuth | W tej pracy nie wykonano; zdalnego stanu nie badano |

HEAD jest taki sam jak w raporcie B. Nadal istnieje sześć zmienionych śledzonych plików integracji i nieśledzony moduł/testy/helpery/dokumenty. **Sam checkout tego HEAD nie zawiera nowej integracji**. Przed jakimkolwiek przyszłym wdrożeniem trzeba osobno przygotować kompletne, przejrzane wydanie obejmujące także nowe pliki. Nie wykonywać masowego `git add` obejmującego zastane raporty innych zadań.

Nie stwierdzono nowej rozbieżności funkcjonalnej w module wobec raportu B. Dodatkowe ustalenia audytu dotyczą granic dowodu: zakresu workflow, nieśledzonych plików wydania, historycznego README, niepełnego testu przeglądarki/Apache oraz ścisłej interpretacji scope opisanej w punkcie 5.

## 3. Aktualna oficjalna dokumentacja Allegro

Sprawdzono ponownie 27.10.2026:

- [Uwierzytelnianie i autoryzacja](https://developer.allegro.pl/tutorials/uwierzytelnianie-i-autoryzacja-zlq9e75GdIR).
- [Informacje podstawowe i środowiska](https://developer.allegro.pl/tutorials/informacje-podstawowe-b21569boAI1).
- [OpenAPI swagger.yaml](https://developer.allegro.pl/swagger.yaml), GET 200; metadane odczytu w `.local-cache/allegro-b1-openapi-meta.json`.

| Element | Oficjalny stan i porównanie z kodem |
|---|---|
| Authorization Code | Obsługiwany; implementacja korzysta z niego |
| PKCE | S256; verifier 43–128 znaków; kod generuje 86 i testuje granice |
| Kod autoryzacyjny | Jednorazowy, 10 s; pending 8 s i automatyczny mostek nie zakładają dwóch minut |
| Redirect | Musi odpowiadać zarejestrowanemu; kod wymusza konkretny URI PROD |
| Wymiana PKCE | client_id + code_verifier zamiast Basic; zgodne z implementacją |
| Token endpoint | /auth/oauth/token; wymiana i refresh po stronie serwera |
| Refresh | Basic, nowa para; access 12 h, odnawialny refresh 3 miesiące, starego refresh można użyć jeszcze przez 60 s po odnowieniu; kod nie wykonuje ślepych retry |
| /me | Aktualne; OpenAPI wymaga allegro:api:profile:read |

**Nie stwierdzono zmiany powyższych wymagań względem implementacji.** Nie oznacza to identyczności całej dokumentacji bajt w bajt ani pozytywnego testu konta. Nie wystąpiła przesłanka „zmiana dokumentacji → STOP”. Jeżeli ponowna kontrola przed przyszłym wydaniem wykaże taką zmianę, wydanie należy zatrzymać i ponownie ocenić kod.

### Hosty i URI

| Cel | PROD | SANDBOX |
|---|---|---|
| Autoryzacja | https://allegro.pl/auth/oauth/authorize | https://allegro.pl.allegrosandbox.pl/auth/oauth/authorize |
| Token | https://allegro.pl/auth/oauth/token | https://allegro.pl.allegrosandbox.pl/auth/oauth/token |
| API | https://api.allegro.pl | https://api.allegro.pl.allegrosandbox.pl |
| Rejestracja aplikacji | https://apps.developer.allegro.pl/ | https://apps.developer.allegro.pl.allegrosandbox.pl/ |

Źródło hostów: oficjalne informacje podstawowe powyżej; nie stare domeny WebAPI. W tej pracy niczego nie rejestrowano.

Docelowy redirect PROD: **`https://mgoutlet.pl/admin/allegro/callback`**, bez query i końcowego slash. Sandbox ma mieć odrębną instancję HTTPS pod ścieżką `/admin/allegro/callback`; faktycznej domeny nie ustalono i nie uznano przykładowego adresu z testów za działający.

## 4. Audyt plików modułu

| Plik | Ustalenie |
|---|---|
| lib.php | Oddzielny loader i kontrolowane wyjątki; brak transportu ofert |
| config.php | Env nad prywatnym plikiem, domyślnie SANDBOX i obie flagi 0; walidacja ścieżek poza webrootem/Git, zakaz private_olx, ścisły URI PROD |
| storage.php | Secretbox, nonce per zapis, temp/fflush/fsync/rename, account.lock, 0700/0600, log allowlista; brak gwarancji ACL/aliasów hostingu bez operatora |
| http.php | Dwa dokładne endpointy/metody, TLS peer/host, bez redirectów, 8 s connect/25 s timeout, limit odbioru 128 KiB, bez surowych błędów |
| oauth.php | State/binding/TTL, PKCE, jednorazowy pending, zapis nowej pary przed zwrotem, fail-closed refresh, kontrola identity, lokalne disconnect |
| callback.php | Bez session_start; input skalarny; zużycie state przed pending; meta refresh; bez kodu/state/tokenów w HTML |
| index.php | Login, POST+CSRF, walidacja typów wejścia, bezpieczny status i escapowanie identity; brak UI ofert |
| bridge.js | Wyłącznie lokalny action POST, session CSRF z formularza; handle usuwany z adresu; brak kodu/tokenów/verifiera |
| .htaccess | Biblioteki PHP niedostępne bezpośrednio; ochrona katalogów; lokalny CSP dla index i callback |

`hosting/getspace/admin/index.php` różni się w zakresie Allegro dodaniem linku w nawigacji. Ścieżka save_product nie ładuje Allegro. `admin/lib.php` utrzymuje session hgo_admin, Strict, HttpOnly i Secure zależne od rozpoznania HTTPS. To ostatnie wymaga kontroli przy terminacji TLS przed PHP.

## 5. Granice potwierdzenia OAuth i mostka

Mostek ma prawidłowe lokalne zabezpieczenia, ale jego wykonalność w terminie kodu pozostaje **bramką przed OAuth**, nie potwierdzonym faktem. 10 s liczy Allegro; 8 s pending liczy serwer od obsługi callbacku. Przesył kodu do callbacku, dysk, lock, meta refresh, przywrócenie Strict cookie, pobranie bridge.js, POST, DNS/TLS i dostarczenie wymiany do Allegro zużywają ten sam krótki budżet. Timeout HTTP 25 s nie przedłuża ważności kodu.

Test HTTP używa serwera PHP, ręcznie podaje cookie, odtwarza routing i wykonuje bridge.js w VM. **Nie symuluje pełnej polityki SameSite ani nawigacji/meta refresh/CSP w prawdziwej przeglądarce.** Nie potwierdza nagłówków Apache, cache/CDN ani czasu sieci Getspace→Allegro. Operator musi potem zmierzyć przebieg w zatwierdzonym środowisku testowym, bez eksportowania HAR z credentialami.

Kolejka locków jest dodatkowym ryzykiem dostępności: account.lock obejmuje HTTP, więc wolny odczyt /me/refresh może opóźnić callback lub finish. Kontrola czasu jest bezpieczna (nie przedłuża TTL), lecz może wymusić ponowne rozpoczęcie połączenia. Taki błąd ma kończyć się bez tokena i bez retry kodu.

**Zakresy odpowiedzi tokenowej:** oauth.php:62–73 akceptuje dokładnie profilowy scope. Oficjalny poradnik w ogólnym przykładzie odpowiedzi nadal pokazuje `allegro_api`. To nie dowód zmiany API ani gwarancja rzeczywistej odpowiedzi z wąskim zakresem. Implementacja celowo odrzuci taki ogólny scope. Przed uznaniem realnego OAuth za zgodny trzeba potwierdzić rzeczywistą reprezentację scope w zatwierdzonym teście; nie osłabiać walidacji na podstawie domysłu. To ograniczenie interoperacyjności przed OAuth, nie powód do włączania zapisu ofert.

## 6. Ponownie uruchomione kontrole

Środowisko lokalne: Windows, PHP portable 8.5.11 z curl/sodium, Node 24.22.1. PHP 8.3/Node 22 z workflow ani realny handler PHP Getspace **nie były tymi testami uruchamiane**.

| Kontrola | Wynik 27.10.2026 |
|---|---|
| tests/allegro-oauth-tests.php | PASS — **197 asercji** |
| tests/allegro-http-tests.mjs | PASS — login/CSRF/Strict bridge/HTML, concurrent refresh, atomowe odczyty, zapis produktu |
| tests/allegro-artifact-tests.mjs | PASS — sentinele, Git/FTPS/routing, exact allowlista |
| tests/olx-oauth-tests.php | PASS |
| tests/olx-http-tests.mjs | PASS |
| tests/olx-identity-verification-tests.mjs | PASS |
| tests/product-id-migration-tests.php | PASS na syntetycznych danych |
| tests/admin-figure-entry-tests.php | PASS |
| tests/catalog-presentation-tests.php | PASS |
| node scripts/build-getspace.mjs | PASS — 185 produktów z zastanej publicznej migawki |
| SITE_ROOT=publish node scripts/prerender-products.mjs | PASS — lokalny katalog 185 produktów |
| tests/public-artifact-tests.mjs | PASS — publiczne karty/ID |
| tests/prerender-privacy-tests.mjs | PASS |
| tests/public-products-snapshot-tests.mjs | PASS |
| allegro-artifact-tests --artifact=publish po prerenderze | PASS |
| Lint siedmiu PHP modułu, node --check bridge.js | PASS |
| Parsowanie YAML workflow i git diff --check | PASS |
| Dziewięć plików Allegro w publish identycznych ze źródłem | PASS |
| Kontrola nazw .env/.enc/.jsonl/.key/.pem/private_allegro w publish | Brak kandydatów |
| Ponowne porównanie baseline plików źródłowych | Bez zmian podczas B.1 |

Nie uruchomiono całego wielofunkcyjnego workflow, zdalnego smoke testu ani migracji produkcji. Testowy HTTP ma wyłączone curl_exec i URL fopen, a workery używają atrap. Regresja OLX nie wymagała realnego OAuth. Liczba 185 dotyczy starej lokalnej migawki i **nie jest aktualną liczbą produktów produkcji**. Nie pobierano nowej produkcyjnej bazy.

## 7. Build, artefakt i ochrona sekretów

Potwierdzony łańcuch lokalny: publiczny snapshot → build → prerender → kontrola publicznych danych → kontrola Allegro. Builder nie korzysta awaryjnie ze starego repozytoryjnego katalogu. `publish/data/products.json` jest tylko kopią publicznej migawki; transfer FTPS go wyklucza, a .htaccess blokuje bezpośredni HTTP.

Ochrona jest warstwowa:

1. `.gitignore`: private_allegro, lokalne storage/logs/cache, pliki vault/log/lock, env i prywatny config.
2. `publicAdminSource`: dotychczasowe wykluczenia prywatnego admina oraz dokładna lista dziewięciu dozwolonych plików Allegro. Nieznane pliki modułu nie przechodzą.
3. `assertPrivateAllegroAbsent`: końcowa kontrola artefaktu; odrzuca prywatny katalog, niedozwolone pliki Allegro i symlinki.
4. Testy sentinelów dowodzą, że config.private.php/account.enc/env/technical.jsonl/nieznany PHP nie przechodzą kopiowania. Oddzielne testy chronią publiczny snapshot/prerender.
5. Workflow: kontrole przed FTPS i dodatkowe wykluczenia private_allegro, storage/logs/cache, enc/jsonl/lock/env.
6. Runtime: prawdziwy config i vault poza public_html; config.php w module jest loaderem, nie plikiem z credentialami.

**Ocena:** w zbadanym stanie lokalna paczka nie zawiera prywatnych plików Allegro, a testowane ścieżki przypadkowego dołączenia są blokowane. Nie wykryto wycieku sekretu w badanym module. Nie jest to dowód nieistnienia dowolnego sekretu w całej historii Git, wszystkich binariach/vendor ani prywatnych danych hostingu.

Ograniczenia, których nie wolno pominąć:

- Gitignore nie usuwa już śledzonego pliku. Nowy commit wydania trzeba obejrzeć przed push; aktualne nowe pliki nadal są nieśledzone.
- Allowlista nazw nie jest skanerem zawartości. Dopisanie sekretu do dozwolonego loadera config.php lub innego publicznego źródła nadal byłoby błędem. Do configu w repo nie wpisuje się prawdziwych wartości.
- Build może wykryć niedozwolony plik dopiero w końcowej kontroli. Po FAIL nie wolno wysyłać ani serwować pozostawionego katalogu publish; potrzebny ponowny czysty build i PASS.
- Uprawnienia i aliasy WWW prywatnego katalogu, realne env i logi pośredników wymagają Getspace. Nazwa katalogu poza public_html nie chroni, jeśli operator wystawił go aliasem.
- Backup całego public_html obejmuje także istniejące sekrety innych usług i prywatne dane admina. Ich kopia musi pozostać prywatna. Brak uploadu artefaktu Actions z backupem w przejrzanym workflow nie potwierdza uprawnień docelowego private_backups.

## 8. Audyt obecnego deploy workflow — ważne ustalenie

`.github/workflows/deploy-getspace.yml` uruchamia się na push main oraz workflow_dispatch. Bieżąca lokalna gałąź nie jest main. Jeden job łączy testy, budowę, pełny backup, przygotowanie OLX, transfer, kontrole danych, smoke i rollback. **Nie uruchamiać go w ramach B.1.**

### 8.1 Czego nie wolno pomylić z wdrożeniem tylko Allegro

- Linia 123: `olx-production-prepare.mjs preflight` odczytuje pełny produkcyjny katalog.
- Linia 218: ten sam helper z `apply`. Gdy brakuje productId, może założyć blokadę zapisów, przygotować i wysłać migrację ID. Przy kompletnych ID nie przesyła zmiany katalogu, ale **nadal przechodzi do prywatnego OLX**.
- Helper OLX w końcowej części tworzy brakujące katalogi/config i wykonuje chmod 700/600 także na już istniejących ścieżkach. Nie nadpisuje istniejącej wartości sekretu, lecz nie jest operacją wyłącznie odczytową.
- Linia 287: owner-decisions migration `--write` dotyczy plików na runnerze. Następujące cmp wymaga braku zmian, a workflow w tej sekcji nie wysyła katalogu/dostaw na produkcję. **Nie należy więc błędnie twierdzić, że ta sekcja zawsze migruje dane produkcyjne.** Może jednak zatrzymać wydanie po transferze i uruchomić rollback.
- Rollback od linii 471 używa mirror --reverse --delete z wykluczeniem data/uploads/admin storage/.credentials. Nie sięga do prawidłowo umieszczonego private_allegro poza public_html. Musi mieć zweryfikowany target i kompletny prywatny backup.

### 8.2 Co oznacza to dla decyzji

Zgoda „wdrożenie infrastruktury Allegro, OLX i dane nienaruszone” **nie jest wystarczająco precyzyjna dla obecnego workflow**. Przed kolejnym zatwierdzeniem trzeba:

- przygotować i przejrzeć osobny wariant wydania kodu bez ponawiania provisioning/migracji OLX, albo jawnie rozszerzyć zatwierdzany zakres po analizie bieżących danych;
- zachować testy regresji OLX, backup i kontrole konfliktów;
- wyodrębnić testy/build od kroków produkcyjnych, żeby kwalifikacja PHP 8.3/Node 22 nie wymagała uruchamiania wdrożenia;
- dodać do planu odbioru nieautoryzujące testy tras i nagłówków Allegro; obecny smoke nie zawiera osobnych kontroli `/admin/allegro/`, callbacku i bibliotek.

Tych zmian nie wykonano w audytowym B.1. To **blocker zatwierdzenia obecnego workflow jako izolowanego deploya Allegro**, a nie powód do samodzielnej naprawy infrastruktury produkcji.

README od początku nadal opisuje historyczny panel Decap/Netlify, sprzeczny z obecnym PHP. Nowa końcowa sekcja Allegro jest trafna. Przed użyciem runbooka wydania trzeba odróżnić/usunąć stare instrukcje; nie wykonywać historycznych kroków przekierowania panelu lub DNS.

## 9. Routing, nagłówki i sesja — co trzeba sprawdzić na Getspace

Lokalny kod pozwala obsłużyć `https://mgoutlet.pl/admin/allegro/callback`: root nie ma ogólnego przechwytującego admina, a admin/.htaccess:11 mapuje czystą trasę na callback.php. Plik jest w artefakcie; biblioteki blokuje .htaccess modułu.

Warunki serwerowe: właściwy DocumentRoot i canonical HTTPS, brak redirectu do Netlify, obsługa .htaccess, rewrite/authz/headers i wymagane AllowOverride, brak przechwycenia przez cache/WAF. Nie wystarczy uruchomić PHP development server i uznać, że Apache zachowa się identycznie.

Root, admin, moduł i PHP ustawiają nagłówki. Operator musi zbadać **efektywną odpowiedź**, także 400/302, i obie tabele nagłówków Apache. Należy potwierdzić brak konfliktujących CSP, możliwość dozwolonego redirectu po POST do hosta Allegro, brak cache strony z handle/CSRF, no-referrer i brak niezamierzonej publicznej polityki cache bridge.js. Kolejność/scalanie jest zależna od realnej konfiguracji; [oficjalne mod_headers](https://httpd.apache.org/docs/2.4/mod/mod_headers.html) opisuje odrębność onsuccess/always i konteksty dyrektyw.

Cookie administratora ma pozostać Strict/HttpOnly/Secure. Przy TLS terminowanym na proxy trzeba sprawdzić, czy PHP rozpoznaje HTTPS; sam kod włącza Secure zależnie od tej informacji. Nie naprawiać powrotu OAuth globalnym obniżeniem SameSite.

## 10. Logi callbacku — dokładny warunek odbioru

**BLOCKER PRZED PRODUKCYJNYM OAUTH: niepotwierdzone usuwanie query code/state we wszystkich warstwach.** PHP loguje bezpieczne rekordy, ale nie kontroluje Apache, proxy, CDN, WAF, APM, PHP error/access/slow logów, support dumpów ani analityki infrastruktury.

Operator ma wskazać i sprawdzić konfigurację każdej aktywnej warstwy dla:

- `/admin/allegro/callback` i wariantu ze slash;
- `/admin/allegro/callback.php` (bezpośredni adres nadal istnieje);
- `/admin/allegro/?resume=...` — handle nie jest kodem/tokenem i jest związany z sesją, lecz również nie powinien być zbędnie przechowywany w pełnych URL.

W Apache `%r` zawiera linię żądania, `%q` query, a `%U` ścieżkę bez query; usunięcie jednego pola nie daje gwarancji, jeśli równolegle zapisywane są inne logi. LogFormat/CustomLog wymagają operatora, nie są naprawą przez zwykły plik aplikacji. Źródło: [oficjalne mod_log_config](https://httpd.apache.org/docs/2.4/mod/mod_log_config.html).

Po przyszłej zgodzie na kontrole serwera można użyć jawnego, niesekretnego markera `B1_PUBLIC_PROBE` w sztucznym query, bez prawdziwego kodu/state. Taki niepoprawny callback nie ma wykonywać wymiany tokenów. Marker nie może pojawić się w żadnej zachowanej surowej kopii query. Kontrola ma objąć błędy i warianty tras, a nie tylko pojedynczy log 200. Nie wysyłać prawdziwych kodów „dla testu redakcji” i nie eksportować pełnych logów do raportu/czatu.

W B.1 **nie wysłano nawet takiego markera do Getspace**. Wynik pozostaje NIEPOTWIERDZONY. Dopiero pozytywna kontrola operatora uzasadnia flagę ALLEGRO_CALLBACK_LOGS_REDACTED=1; sama flaga niczego nie redaguje.

## 11. Checklista Getspace z wymaganym dowodem

Wszystkie pozycje serwerowe są obecnie **DO POTWIERDZENIA**, nie PASS. Nie podano zmyślonych ścieżek menu DirectAdmin ani numerów ticketów.

### Przed osobnym zatwierdzeniem wdrożenia kodu z OAuth wyłączonym

| ID | Kontrola operatora | Wymagany dowód bez sekretów |
|---|---|---|
| G01 | Właściwa domena, konto, DocumentRoot i docelowy katalog FTPS | Zatwierdzona mapa ścieżek; brak innej domeny/aliasu |
| G02 | Faktyczny handler/wersja PHP i curl/sodium, file/lock/fsync/rename | Prywatna diagnostyka/ticket; bez publicznego phpinfo |
| G03 | Rzeczywiste flagi ALLEGRO_OAUTH_ENABLED=0 i LOGS_REDACTED=0 lub brak | Status logiczny po kontroli config/env; nigdy wartości sekretów |
| G04 | Odrębność Allegro/OLX, brak odziedziczonej aktywnej konfiguracji Allegro | Potwierdzenie operatora, bez czytania/publikowania tokenów |
| G05 | Aktualna pełna kopia kodu i danych oraz możliwość odtworzenia | Lokalizacja poza rootem, kontrola hashów/odczytu, procedura restore |
| G06 | Wybrany zakres deploya nie wykonuje niezatwierdzonych operacji OLX/danych | Przejrzany diff workflow/release i lista operacji |
| G07 | Kompletne wydanie wraz z nowymi plikami, właściwy target/ref | Lista plików, SHA wydania, testy PHP 8.3/Node 22 albo właściwego runtime |

Nie ma wymogu przekazania Client Secret, żeby zatwierdzać **wyłącznie kod z wyłączonym OAuth**. Konfiguracja może pozostać niekompletna i blokować połączenie. Jeżeli operator przygotowuje prywatny klucz/storage, robi to wyłącznie w osobno autoryzowanym zakresie, nigdy w repo.

### Odbiór po przyszłym zatwierdzonym wdrożeniu, nadal bez OAuth

| ID | Kontrola | Warunek PASS |
|---|---|---|
| G08 | Czysta trasa i bezpośredni callback.php z brakującymi parametrami | Kontrolowany błąd, brak 500, brak nowej sesji i wymiany tokenów |
| G09 | Anonimowe wejście i akcja POST do panelu Allegro | Wymagane logowanie; brak auto-connect |
| G10 | Panel zalogowany, konfiguracja brak/wyłączona | Czytelny status, przycisk połączenia niedostępny; brak tokenów/ścieżek prywatnych |
| G11 | Biblioteki lib/config/storage/http/oauth.php przez WWW | 403/404, bez kodu i wartości konfiguracji |
| G12 | Ewentualne prywatne config/vault i backupy | Brak dostępu przez WWW/alias, prawa i właściciel właściwi |
| G13 | Efektywne CSP/Cache-Control/Referrer-Policy, statusy 200/302/400 i JS | Brak sprzeczności/cache wrażliwych stron; skrypt bridge dostępny z własnej domeny |
| G14 | Sesja admina za realnym HTTPS/proxy | Strict, HttpOnly, Secure, bez osłabienia globalnej sesji |
| G15 | Regresja strony, panelu, OLX i danych | Trasy działają, chronione dane zachowane, brak zapisów marketplace |

Status HTTP zalogowanego panelu z całkiem brakującym configiem może wynosić 400 (kod tak obsługuje bezpieczny błąd), a nie 200. To nie jest zgoda na akceptację surowego błędu/500; sprawdzić treść i przyczynę zamiast wymagać arbitralnie 200 bez konfiguracji.

### Dodatkowo przed przyszłym prawdziwym OAuth

| ID | Kontrola | Warunek PASS |
|---|---|---|
| G16 | Wszystkie logi, APM/CDN/WAF/proxy, wszystkie trasy callbacku | Konfiguracja i test niesekretnego markera; zero zachowanego code/state/query |
| G17 | Serwerowy TLS/DNS/CA i dostęp wyjściowy do właściwych hostów | Działające zaufanie cURL, brak wyłączenia TLS i niejawnego redirectu |
| G18 | Aplikacja/redirect/scope w odpowiednim środowisku | Zgodny URI, minimalny profil, odrębne credentiale/klucze/storage |
| G19 | Klucze, rotacja/restore, uprawnienia i retencja backupów | Klucz poza publiczną kopią; brak współdzielenia z OLX |
| G20 | Zatwierdzony test Sandbox w prawdziwej przeglądarce | Strict + CSP + automatyczny mostek działają w krótkim budżecie; poprawny scope odpowiedzi i /me |
| G21 | Osobna decyzja właściciela przed PROD | Jasny zakres: tylko OAuth i /me; nadal zero ofert |

G20 nie oznacza polecenia utworzenia konta/aplikacji albo wykonania OAuth teraz. To późniejszy etap po osobnej zgodzie. Bez G16–G21 prawdziwy OAuth nadal jest zabroniony i zablokowany.

## 12. Plan przyszłego kontrolowanego deploya — niewykonany

1. **Zamknąć bramki wydania:** kompletny, przejrzany release zamiast nieśledzonych plików; właściwy ref, osobna kwalifikacja runtime; rozstrzygnięty workflow bez niezatwierdzonego provisioning OLX. Nie pushować na main ani nie uruchamiać workflow w ramach audytu.
2. **Uzyskać potwierdzenia G01–G07**, w tym realne wyłączenie OAuth niezależne od domyślnych wartości kodu. Nie zmieniać jeszcze scope ani danych produktów.
3. **Przedstawić właścicielowi konkretną paczkę/diff i plan rollbacku**, ze wszystkimi operacjami na serwerze. Dopiero osobna zgoda uruchamia przyszłe wdrożenie.
4. **W zatwierdzonym wydaniu**: nowy, świeży publiczny snapshot do publicznych kart, czysty build/prerender/testy, kontrola sekretów i hashów. Obecnego lokalnego snapshotu 185 nie traktować jako danych produkcyjnych do wysyłki.
5. **Wykonać i zweryfikować aktualny backup poza rootem**, bez publicznych artefaktów z sekretami. Wysyłać wyłącznie zatwierdzony kod; dane/uploads/tokeny/config pozostają poza transferem.
6. **Publikować bez startu OAuth**, zachowując Strict i blokadę /sale. Nie uruchamiać migracji figur ani automatycznych jobów marketplace.
7. **Odebrać G08–G15** bez autoryzacji konta. Zgodnie z planem sprawdzić invalid callback, biblioteki, nagłówki i status konfiguracji. W razie FAIL zatrzymać odbiór i wykonać zatwierdzony rollback kodu, nie całego katalogu danych.
8. **Zamknąć wydanie kodu z wyłączonym OAuth.** Dopiero kolejny, osobno autoryzowany etap realizuje G16–G21, konfigurację aplikacji i test konta. Nie łączyć pierwszego deploya i live OAuth w jedną niekontrolowaną operację.

Rollback: przywrócić poprzedni zestaw kodu/.htaccess z potwierdzonej kopii, z ochroną data/uploads/admin storage/.credentials oraz prywatnych katalogów poza rootem. Zweryfikować target przed operacją z usuwaniem. Nie przenosić katalogów tokenów do webrootu i nie odtwarzać danych produktu sprzed późniejszych edycji. Udokumentować powód i wyniki smoke; callback nie może pozostać częściowo wdrożony z aktywnymi flagami.

## 13. Rejestr blockerów i decyzja

| ID | Blokada | Przed czym | Jak ją zamknąć |
|---|---|---|---|
| B1-REL-01 | Nieśledzone pliki i brak kwalifikowanego release/ref | Zatwierdzeniem deploya | Przejrzany kompletny snapshot/commit późniejszego wydania, bez cudzych raportów |
| B1-REL-02 | Obecny job zawiera provisioning OLX i warunkową migrację ID | Uznaniem joba za deploy tylko Allegro | Osobno przejrzany wariant bez tych skutków lub jawnie zatwierdzony rozszerzony zakres |
| B1-ENV-01 | Runtime, target, backup i rzeczywiste flagi hostingu niepotwierdzone | Deployem z gwarancją wyłączonego OAuth | G01–G07, testy docelowego runtime |
| B1-OAUTH-01 | Logi callbacku i warstwy pośrednie niepotwierdzone | Prawdziwym OAuth | G16; nie wystarczy flaga w pliku |
| B1-OAUTH-02 | Brak realnego testu Strict/CSP/10 s na docelowym środowisku | PROD OAuth | G17/G20 po osobnej zgodzie na test |
| B1-OAUTH-03 | Brak potwierdzonej konfiguracji aplikacji i reprezentacji scope | Prawdziwym OAuth/odbiorem połączenia | G18–G20; nie zmieniać walidacji bez dowodu |

**Czy lokalna implementacja przechodzi obecne testy? TAK.**

**Czy bieżący workflow można już bezwarunkowo zatwierdzić jako izolowany deploy Etapu B? NIE.** Najpierw B1-REL-01/02 i B1-ENV-01. Nie ma podstaw, by twierdzić, że brak blockerów wyłącznie dlatego, że testy są zielone.

**Czy brak potwierdzonych logów sam w sobie zakazuje przyszłego wdrożenia nieaktywnego kodu? Nie, o ile wyłączenie OAuth jest realnie zapewnione i zakres wydania zweryfikowany.** Logi są twardą bramką przed uruchomieniem OAuth. To rozdzielenie nie usuwa obecnych blockerów wydania ani potrzeby osobnej zgody.

**Czy możemy teraz przejść do prawdziwego OAuth? NIE.** Pozostają wskazane bramki hostingu i zgody. Nie stwierdzono zmiany oficjalnych wymagań OAuth, która nakazywałaby przebudowę mechanizmu, lecz nie wykonano testu rzeczywistego konta.

Etap B.1 kończy się raportem. Nie wykonano deploya, OAuth ani etapu C.

## 14. Odcisk badanego stanu

Manifest 30 plików: `.local-cache/allegro-b1-baseline.json`, SHA-256 `0ac54f40d61c0665717af6ba38fdf16fc361063e53e1b1f7f5767cfc9aaf6edd`. Na zakończenie wszystkie 30 hashów odpowiadało źródłom; B.1 nie zmienił aplikacji ani dotychczasowej dokumentacji. Poniżej odciski kluczowych plików, ponieważ samo HEAD nie obejmuje nieśledzonego modułu.

| Plik | SHA-256 |
|---|---|
| hosting/getspace/admin/allegro/.htaccess | `2fa63713f028f986e90234ab9895ae1bc66bd29af218fb9c47f770f629aec9b8` |
| hosting/getspace/admin/allegro/bridge.js | `1e064793701e275351ccfce5618aaa712d0e69df7afeda7043d3580da1a32dd1` |
| hosting/getspace/admin/allegro/callback.php | `7a506efd7ebe3d255b65cb6c8cef3e977312e03a9bd7bd8fbcca9552a0dc2f10` |
| hosting/getspace/admin/allegro/config.php | `7347656d2cbb9c1fb796aa16bbc25d074f98fb698f6b08b9b0016da48326f037` |
| hosting/getspace/admin/allegro/http.php | `569e731e9eb5f174b733623046047908e7a89321ee41dfe07e2ef1e789de2bfc` |
| hosting/getspace/admin/allegro/index.php | `420fdc061e6e2350df95f3002ba21888381bbb5c16d455a0d354de129ee2ad1a` |
| hosting/getspace/admin/allegro/lib.php | `fcf3115b9951bbbf680891fc5d745ebe3ffd2b25d9f42e3e199119938935bece` |
| hosting/getspace/admin/allegro/oauth.php | `3181801778eb0005dfe5807a777e81a9df56f74448b3daf5152d197d434d864e` |
| hosting/getspace/admin/allegro/storage.php | `d2810966f4f8a6c428e836a7335eb8a2dd7db61eeabd603f7f628124ebd458e1` |
| scripts/build-getspace.mjs | `ba3c254ecf6037e9268d2718695c75a0d766cd56a4800a691c68e17e77321722` |
| scripts/getspace-artifact-privacy.mjs | `cd3ed72926cd1e19a74a4abbee4f478dedfcdcfb8ffc1351d034ded159506a1e` |
| .github/workflows/deploy-getspace.yml | `cd06f7c7267ba414047cbaa27e77f66590703a6c6f4df0fd0abfbd3a9e58952a` |
