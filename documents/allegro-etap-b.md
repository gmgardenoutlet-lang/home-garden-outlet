# Allegro — etap B, infrastruktura lokalna

26.10.2026. Podstawa: `reports/allegro-audyt-etap-a.md`. Repo przed pracą: HEAD `849121bf20ec012f89c293b17e690ddf91c28b36`, bez zmian śledzonego kodu. Nie stwierdzono różnic architektury wymagających zatrzymania prac. Zastane nieśledzone raporty zachowano.

## Zakres i stan

Zaimplementowano lokalnie moduł `hosting/getspace/admin/allegro/`, link w panelu, routing callbacku, prywatną konfigurację, PKCE/state, mostek dla SameSite=Strict, szyfrowanie, refresh, odczyt konta, lokalne odłączenie i diagnostykę. **Nie wykonano deploya, commitów, prawdziwego OAuth ani operacji Allegro.** Nie zmieniono `admin/olx/*`, danych produktów, uploads ani schematu katalogu.

Etap B ma zamkniętą politykę HTTP: jedynie `POST /auth/oauth/token` na właściwym serwerze autoryzacji oraz `GET /me` na właściwym API. Wszystkie pozostałe ścieżki i metody są odrzucane **przed transportem**, również przy wstrzykniętym transporcie testowym. Niskopoziomowy cURL ponownie sprawdza dokładną allowlistę URL/metod. Nie ma modułów ofert, propozycji produktów, uploadu zdjęć, zmian cen/stanu, synchronizacji ani przycisków publikacji. Nie ma konfigurowalnego przełącznika włączającego zapisy sprzedażowe.

## Oficjalna dokumentacja sprawdzona ponownie

- [Authorization Code, PKCE, scope, refresh](https://developer.allegro.pl/tutorials/uwierzytelnianie-i-autoryzacja-zlq9e75GdIR).
- [Aktualne OpenAPI](https://developer.allegro.pl/swagger.yaml), pobrane ponownie GET: `/me` wymaga `allegro:api:profile:read`; tworzenie ofert wymaga `allegro:api:sale:offers:write`, ich odczyty `allegro:api:sale:offers:read` zgodnie z konkretnym zasobem.
- [Zmiana PKCE — wymagane S256](https://developer.allegro.pl/news/autoryzacja-30-grudnia-2024-wprowadzimy-zmiany-w-mechanizmie-pkce-jnvovjqxAuo).

**Doprecyzowanie audytu:** aktualny poradnik dla wymiany kodu z PKCE poleca `client_id` i `code_verifier` w formularzu **zamiast nagłówka Basic**. Tak działa kod. Refresh stosuje Basic Client ID/Secret zgodnie z bieżącym przykładem odświeżania. Nie kopiowano wyłączania walidacji TLS ani wypisywania tokenów z przykładów.

Scope etapu B jest stały: **wyłącznie `allegro:api:profile:read`**. Nie prosimy o zamówienia, płatności, wiadomości, zapis profilu ani zbędne uprawnienia. Potwierdzono zakresy ofert read/write dla przyszłości, ale nie są teraz żądane. Ich późniejsze dodanie wymaga osobnej zmiany kodu, testów i ponownej zgody OAuth. Odpowiedź tokenowa musi potwierdzić dokładnie obecny zakres; nie akceptujemy po cichu ogólnego/starszego `allegro_api` lub dodatkowych uprawnień.

## Pliki

| Plik | Odpowiedzialność |
|---|---|
| admin/allegro/lib.php | Loader i wyjątki ze stałymi bezpiecznymi komunikatami |
| admin/allegro/config.php | Prywatna konfiguracja/env, środowisko, hosty, bramki gotowości |
| admin/allegro/storage.php | Sodium secretbox, atomowy zapis, blokady, ograniczony dziennik |
| admin/allegro/http.php | Zamknięte endpointy i metody, TLS, timeouty, sanitizacja |
| admin/allegro/oauth.php | State/PKCE, callback, finish, refresh, /me, disconnect |
| admin/allegro/index.php | Zalogowany panel i akcje POST/CSRF |
| admin/allegro/callback.php | Callback bez tworzenia sesji i bez wymiany tokenów |
| admin/allegro/bridge.js | Automatyczny lokalny POST po powrocie do istniejącej sesji, bez kodu/tokenów |
| admin/allegro/.htaccess | Zakaz odczytu bibliotek i katalogów prywatnych |
| scripts/getspace-artifact-privacy.mjs | Allowlista plików modułu oraz kontrola artefaktu |
| tests/allegro-oauth-tests.php | Logika, szyfrowanie, state/PKCE, polityka i logi |
| tests/allegro-http-tests.mjs | HTTP panelu, CSRF, sesje, współbieżność i izolowany zapis produktu |
| tests/allegro-artifact-tests.mjs | Prywatne sentinele, Git/FTPS, allowlista i routing |

Ścieżki admin/... powyżej są pod `hosting/getspace/`. Zmiany istniejących plików ograniczono do linku nawigacji, jednej reguły callbacku, zabezpieczeń Git/build/FTPS, testów CI i dokumentacji. Nie refaktoryzowano OLX.

## Konfiguracja — jeszcze niewprowadzona na serwerze

Loader czyta `HGO_ALLEGRO_CONFIG`, a bez niego plik `private_allegro/config.php` obok webrootu. Domyślna ścieżka w lokalnym checkoutcie jest nieakceptowalna, jeśli leży w repo: testy używają odrębnego prywatnego katalogu tymczasowego. Nie tworzyć konfiguracji z sekretami wewnątrz projektu nawet jako pliku ignorowanego.

Env ma pierwszeństwo nad plikiem, także jeśli jawnie zawiera pustą wartość. Domyślne środowisko to SANDBOX, OAuth domyślnie **wyłączony**. Przy niepoprawnym środowisku loader odrzuca konfigurację; parametr GET nie zmienia środowiska.

Poniższy schemat ma puste wartości, **nie jest gotową konfiguracją**:

```php
<?php
return [
    'ALLEGRO_ENVIRONMENT' => 'SANDBOX', // tylko PROD albo SANDBOX
    'ALLEGRO_CLIENT_ID' => '',
    'ALLEGRO_CLIENT_SECRET' => '',
    'ALLEGRO_REDIRECT_URI' => '',
    'ALLEGRO_TOKEN_KEY' => '',          // osobny losowy klucz 32 bajty zakodowany base64
    'ALLEGRO_STORAGE_DIR' => '',        // istniejący prywatny katalog poza Git i webrootem
    'ALLEGRO_OAUTH_ENABLED' => '0',
    'ALLEGRO_CALLBACK_LOGS_REDACTED' => '0',
];
```

Nie wklejać credentiali do czatu, Git, Actions, HTML, JS ani publicznych JSON. Klucz Allegro musi być nowy, niezależny od OLX. Operator tworzy prywatny katalog 0700, konfigurację 0600, pod właściwym właścicielem PHP. Na Windows `chmod` nie zastępuje ACL; prywatną konfigurację chronić ACL konta. Na hostingu zweryfikować realne prawa, właściciela i brak mapowania prywatnej ścieżki przez alias WWW.

Po zmianie prywatnej konfiguracji operator musi uwzględnić OPcache/reload właściwego procesu PHP i potwierdzić nowe flagi w panelu. Zmiana env wymaga właściwego przeładowania procesu według hostingu. Wyłączenie nie jest potwierdzone samą edycją pliku. Backup tokenów chronić oddzielnie od klucza/configu; nie pakować ich razem do publicznego lub dostępnego zespołowo artefaktu.

PROD i SANDBOX wymagają **odrębnych zarejestrowanych aplikacji/credentiali, plików konfiguracji, kluczy i prywatnych katalogów bazowych**. Loader dodatkowo dzieli storage na podkatalog prod/sandbox. Zaszyfrowany dokument ma schemaVersion, environment i hash powiązania Client ID + redirect URI; przekopiowanie vaultu między środowiskami/aplikacjami jest odrzucane nawet przy identycznym kluczu testowym. Nie przenosić tokenów ręcznie.

## Routing i powrót do sesji

Routing obecnego PHP pozwala na dokładny adres **`https://mgoutlet.pl/admin/allegro/callback`**: w `admin/.htaccess` dodano równoległą do OLX regułę `^allegro/callback/?$ allegro/callback.php [END]`. Root nie ma przechwytującego ogólnego routingu admina. Nie trzeba zmieniać głównego .htaccess. Ten adres **nie został zarejestrowany ani uruchomiony na produkcji**.

PROD dopuszcza dokładnie ten URI. SANDBOX wymaga skonfigurowanego innego HTTPS URI pod `/admin/allegro/callback`, na odrębnej instancji administratora (np. dedykowana subdomena — operator musi ją wcześniej utworzyć i zabezpieczyć). Żadnych query, fragmentów, userinfo, IP ani dowolnego portu. Odrębny sandbox callback nie jest obecnie działającą usługą.

Lokalna polityka CSP strony Allegro zachowuje dotychczasowe ograniczenia panelu, dopuszczając dodatkowo wyłącznie dwa hosty autoryzacji w form-action, aby przeglądarka mogła wykonać przekierowanie po chronionym POST. Nie zmienia to globalnej polityki ani cookie. Callback ma osobną politykę default-src/form-action none. Nagłówki Apache i zachowanie docelowej przeglądarki wymagają późniejszej weryfikacji Sandbox.

Przebieg:

1. Zalogowany panel, POST+CSRF: start, losowe state (32 bajty), verifier (64 losowe bajty → 86 znaków base64url), S256. W vault trafia hash state, binding sesji, verifier i ważność 10 min. Jedna oczekująca próba na konto; nowa zastępuje poprzednią.
2. Callback nie wywołuje boot_admin/session_start, więc nie zastępuje brakującego Strict cookie. Sprawdza i zużywa state, także przy odmowie, braku kodu lub wygaśnięciu. Nie kontaktuje się z token endpointem. Kod i verifier pozostają zaszyfrowane w pending maksymalnie 8 s, nie dłużej niż pierwotny state; dokumentacja Allegro określa ważność samego kodu na 10 s.
3. Minimalna strona bez zewnętrznych zasobów natychmiast przechodzi przez meta refresh na tę samą domenę, do panelu z losowym handle. Handle nie jest kodem/tokenem. Nagłówki no-store/no-referrer i restrykcyjny CSP. Ani code, state, verifier, ani tokeny nie wracają w HTML.
4. Panel w tej samej istniejącej sesji sprawdza binding i TTL, po czym bridge.js automatycznie wysyła finish jako lokalny POST+CSRF. GET nie wymienia kodu. Skrypt nie otrzymuje kodu ani tokenów, usuwa handle z adresu panelu i nie wysyła formularza do obcej domeny. Pending jest usuwany przed wymianą kodu; restart lub niejednoznaczny wynik nie pozwala powtórzyć wymiany. Czas sprawdzany po zdobyciu locka nie pozwala przedłużyć ważności przez oczekiwanie.
5. Serwer wymienia kod i pobiera /me. Dopiero po sprawdzeniu wymaganej pary tokenów, scope i identity zapisuje stan connected. Zachowuje wyłącznie id/login, nie e-mail, adres czy nazwisko.

Ważność jest sprawdzana przy każdej próbie użycia. Wygasłe state/pending usuwa także następne otwarcie panelu. Nie ma crona sprzątającego: jeśli nikt nie otworzy panelu, wygasły materiał może pozostać zaszyfrowany do kolejnej wizyty/startu/disconnect, ale nie może zostać wykorzystany. Retencję szyfrowanych backupów ustala operator.

## Tokeny i współbieżność

`account.enc`: secretbox z losowym nonce na każdy zapis. Plik powstaje przez restrykcyjny unikalny temp w tym samym katalogu, pełny zapis, fflush/fsync i rename. account.lock obejmuje odczyt, mutację, wymianę/refresh i zapis. Pliki 0600, podkatalog 0700; symlinki prywatnych plików i storage są odrzucane. Ścieżki muszą leżeć poza znanym webrootem i repo; ścieżki private_olx również odrzucane.

Nie odświeżamy przy każdym żądaniu ani przy zwykłym otwieraniu statusu. Access jest używany do expires_at z marginesem 90 s. Jawna diagnostyka /me lub serwerowe pobranie access w razie potrzeby wykonuje jeden refresh pod wspólną blokadą. Najpierw trwale zapisuje reconnect bez tokenów, potem używa refresh, waliduje i atomowo zapisuje nową parę, dopiero wtedy zwraca access. Dwa procesy wykonują jeden refresh. Błąd/timeout/restart po wysłaniu pozostawia reconnect i wymaga nowego połączenia, bez ślepego ponawiania starego refresh.

Dokumentacja opisuje access na 12 h i odnawiany refresh na 3 miesiące, ze starym refresh używalnym jeszcze przez 60 s po odnowieniu. Kod korzysta z rzeczywistego expires_in i celowo nie używa tego okna do agresywnych retry. Nowa para i scope muszą być kompletne; brak/niejednoznaczność zatrzymuje operację.

Diagnostyka pobiera tylko /me, weryfikuje zgodność zapisanego ID i odświeża login/czas sprawdzenia. 401 lub inna tożsamość zatrzymuje dostęp i wymaga reconnect; nie ponawia automatycznie. Błąd sieci samego odczytu nie udaje odłączenia lub zmiany tożsamości.

## HTTP i logi

Allowlista dokładnych hostów wynika wyłącznie z konfiguracji: allegro.pl/api.allegro.pl albo ich domeny allegrosandbox.pl. HTTPS, TLS peer/host verification, brak redirectów, connect timeout 8 s, całość 25 s i limit odpowiedzi 128 KiB podczas odbioru. Bearer tylko serwerowo. Brak raw request/response/error body w wyjątkach i logach.

Logi w prywatnym podkatalogu środowiska: technical.jsonl, oddzielny log.lock, rotacja 256 KiB do jednej kopii. Tylko stałe operation/errorCode, czas UTC, środowisko, lokalny losowy correlationId, numeryczny status HTTP i numeryczny seller ID. Dowolne inne klucze/wartości są ignorowane. Nie logujemy danych query, nagłówków, cookies, tokenów, secret, state, verifier ani kodu.

**BLOCKER PRZED PRODUKCYJNYM OAUTH:** lokalnie nie można potwierdzić konfiguracji Apache/proxy/CDN/APM/Getspace. Nie zmieniano ani nie badano logów produkcji. Operator musi potwierdzić, że callback (w tym bezpośredni callback.php) nie zapisuje query code/state w żadnej warstwie, i sprawdzić no-referrer/no-store/CSP. Dopiero wtedy ustawić flagę potwierdzenia logów; samo ustawienie flagi nie wykonuje redakcji. Nie logować rzeczywistych kodów w celu „sprawdzenia”, czy są ukrywane. Osobno wymagana zgoda właściciela na prawdziwy OAuth.

## Odłączenie

Wyłącznie zalogowany POST+CSRF z potwierdzeniem. Usuwa z vaultu wszystkie lokalne tokeny, konto i próby state/pending. Działa również przy wyłączonym OAuth, pustym Client Secret lub niepotwierdzonych logach, o ile klucz/storage są poprawne. Nie dotyka innych plików przyszłych mapowań ofert. Nie wysyła revoke, nie kończy ofert i nie deklaruje cofnięcia zgody na Allegro; panel wyjaśnia usunięcie aplikacji w ustawieniach Allegro. Przy utracie klucza lub uszkodzeniu vaultu potrzebne jest prywatne działanie operatora, nie obejście walidacji w panelu.

## Git, build i przyszły deploy

.gitignore chroni private_allegro i lokalne storage/logs/cache. Build kopiuje moduł według dokładnej listy publicznych plików kodu; prywatne sentinele, nieznane PHP, account.enc, env, logs/cache nie przechodzą. Na końcu build sprawdza brak prywatnych katalogów/dowiązań i niedozwolonych plików Allegro w całym artefakcie. FTPS ma dodatkowe wykluczenia, a CI uruchamia nowe testy i kontrolę artefaktu przed wdrożeniem.

Nie uruchomiono workflow ani transferu. Zwykły workflow nadal robi istniejące migracje/backupy; ten etap ich nie wykonuje. Przed przyszłym autoryzowanym deployem oddzielnie ocenić aktualny katalog i backup. Plik config.php w module jest wyłącznie loaderem; prawdziwe wartości pozostają poza artefaktem.

## Testy — polecenia

```sh
php tests/allegro-oauth-tests.php
node tests/allegro-http-tests.mjs
node tests/allegro-artifact-tests.mjs
php tests/olx-oauth-tests.php
php tests/product-id-migration-tests.php
node tests/olx-http-tests.mjs
node tests/olx-identity-verification-tests.mjs
```

PHP wymaga curl/sodium. Testy Node obsługują HGO_TEST_PHP i JSON HGO_TEST_PHP_ARGS dla lokalnego runtime. Test HTTP tworzy oddzielną pełną kopię PHP w katalogu tymczasowym, stosuje syntetyczny produkt i sesje, wyłącza curl_exec oraz URL fopen w serwerze i używa atrap transportu w workerach. Nie wykonuje OAuth/żądań do Allegro i nie zmienia lokalnego/produkcyjnego katalogu. Testowe dane i magazyny są usuwane.

Pokrycie wymaganych kontroli:

| Wymaganie | Test |
|---|---|
| State, jednorazowość, TTL, zastąpienie próby | OAuth unit, callback replay w HTTP |
| PKCE/verifier/S256 | Wektor RFC7636, długości, challenge i parametry wymiany |
| Szyfrowanie, błędny klucz, świeży nonce | Odczyt vaultu i kontrola braku plaintext |
| Atomowość | Zachowanie starego pliku przy błędzie, równoległy zapis i bezpośrednie odczyty ciphertextu |
| Lock refresh | Dwa osobne procesy PHP, dokładnie jedno wywołanie refresh |
| Logi | Allowlista kluczy/wartości, sentinele i raw błędy nie trafiają do logu |
| CSRF/sesja | HTTP wszystkich akcji, inna sesja nie kończy OAuth, Strict pozostaje |
| Niepoprawny callback | Błędny state, brak code, odmowa, array input i replay |
| PROD/SANDBOX | Różne katalogi/hosty, kontekst szyfrowany, brak przełączania przez GET |
| HTML i prywatne pliki | Brak tokenów/secret, escaping loginu, prywatne ścieżki poza rootem, test artefaktu |
| Blokada sprzedaży | POST/PATCH/PUT/DELETE i inne metody do sale odrzucone przed atrapą transportu |
| Zapis produktu | Rzeczywisty save_product na kopii: zmiana ceny i zachowane ID, Allegro niezaładowane, vault/logi bez zmian |

Wyniki uruchomień i ograniczenia środowiska są zapisane w `reports/allegro-etap-b-lokalnie.md`.

Końcowy raport w wymaganej strukturze A–U: `reports/allegro-etap-b.md`. Uwzględnia korektę pierwotnego ręcznego mostka: dwuminutowe oczekiwanie nie było zgodne z dziesięciosekundową ważnością kodu Allegro. Obecnie kontynuacja jest automatyczna i ma 8 s TTL; wolna sieć lub brak sesji/JS kończy się koniecznością ponownego połączenia. Prawdziwy round-trip z cookie Strict i CSP nadal wymaga późniejszej weryfikacji Sandbox i nie został wykonany.

Nie potwierdzono live Apache/Getspace, działania domeny Sandbox, rejestracji aplikacji ani prawdziwego round-trip w przeglądarce. Serwer PHP testów odtwarza routing callbacku; dokładna reguła Apache jest sprawdzana statycznie. To nie jest dowód działania .htaccess na produkcji.
