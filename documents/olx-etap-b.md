# OLX — przygotowanie etapu B, 16.10.2026

## Stan

Implementacja lokalna, bez wdrożenia, migracji prawdziwych produktów, połączenia z prawdziwym kontem OLX i jakichkolwiek operacji na ogłoszeniach. Bez potwierdzonej ochrony logów hostingu moduł pozostaje zablokowany. Zmiennej odblokowującej nie wolno traktować jako samego zabezpieczenia logów.

## 1. Pliki

- `hosting/getspace/admin/product-identity.php`: generator i czysta funkcja migracji ID.
- `hosting/getspace/admin/lib.php`: włączenie funkcji ID.
- `hosting/getspace/admin/index.php`: generowanie ID nowych produktów przy zapisie i odnośnik OLX; bez transportu OLX w obsłudze produktów.
- `hosting/getspace/admin/.htaccess`: callback i ochrona pomocniczego pliku PHP.
- `hosting/getspace/admin/olx/{lib.php,index.php,callback.php,.htaccess}`: połączenie konta, szyfrowany magazyn, callback, panel.
- `scripts/migrate-product-ids.php`: oddzielna migracja CLI, domyślnie podgląd.
- `scripts/build-getspace.mjs`: wykluczenie storage, credentials i plików środowiskowych już na etapie budowania artefaktu.
- `.github/workflows/deploy-getspace.yml`: wymagane rozszerzenia i testy; bez nowego mechanizmu deploymentu i bez uruchamiania migracji ID.
- `tests/{olx-oauth-tests.php,olx-http-tests.mjs,product-id-migration-tests.php}`: testy bez konta OLX, na sztucznych tokenach i danych.

## 2. productId — plan do zatwierdzenia przed migracją

Format `prd_` + `bin2hex(random_bytes(16))`: 128 bitów losowości. Generator sprawdza kolizję w katalogu; migrator odrzuca nieprawidłowe i powtórzone istniejące ID zamiast je podmieniać. ID nie pochodzi ze sluga, indeksu, SKU ani nazwy. Przy zwykłej edycji ID jest zachowywane z rekordu serwerowego i nie jest pobierane z POST. Nowe produkty otrzymują je automatycznie przy tworzeniu. Istniejące rekordy bez ID pozostają bez zmian do osobnej migracji; wdrożenie kodu samo nie uruchamia migracji.

Przed zastosowaniem na serwerze:
1. Zatwierdzić ten plan; ustalić krótkie okno techniczne, zatrzymać wszystkie zapisy produktów, w tym z zamówień i innych procesów. Sam lock migratora nie blokuje starszych zapisów aplikacji.
2. Pobrać aktualny prywatny katalog produkcji i wykonać podgląd. Nie korzystać ze starej kopii repo ani publicznej migawki, która nie zawiera pól prywatnych.
3. Na kopii sprawdzić liczbę rekordów, unikalność ID i pełną zgodność danych po usunięciu nowego pola. Zachować SHA256 aktualnego pliku.
4. Uruchomić migrację z `--apply`, oczekiwanym SHA256, potwierdzeniem zatrzymania zapisów i prywatnym katalogiem backupu. Skrypt sprawdza hash ponownie przed zamianą pliku, tworzy zweryfikowaną kopię bajt w bajt i zapisuje przez plik tymczasowy w tym samym katalogu.
5. Sprawdzić wszystkie ID, URL-e i edycję produktu, następnie wznowić zapisy. Powtórne uruchomienie nie zmienia już nadanych ID.

Przykład PODGLĄDU (nie wykonano na prawdziwych produktach):

```sh
php scripts/migrate-product-ids.php --products=/PELNA/SCIEZKA/public_html/data/products.json
```

Osobne zastosowanie, dopiero po akceptacji planu i zatrzymaniu zapisów:

```sh
php scripts/migrate-product-ids.php --products=/PELNA/SCIEZKA/public_html/data/products.json --apply --maintenance-confirmed --expect-sha256=HASH_Z_AKTUALNEGO_PODGLADU --backup-dir=/PRYWATNY/KATALOG/KOPII
```

Skrypt nie jest kopiowany do publicznego artefaktu strony. Uruchomić go z prywatnej kopii repo lub prywatnego katalogu narzędzi. Pole `productId` nie jest dodawane do publicznej listy dozwolonych pól katalogu. Publiczne URL-e pozostają oparte na dotychczasowych slugach.

## 3. OAuth i state

Start wymaga zalogowanego administratora i POST z istniejącym CSRF. Losowy state ma 32 bajty (`random_bytes(32)`), termin 10 minut. Serwer zapisuje tylko jego SHA256, powiązanie z losowym identyfikatorem zapisanym w sesji i czas ważności — w zaszyfrowanym magazynie. Nowy start unieważnia poprzednią próbę.

Callback `/admin/olx/callback` nie wywołuje `session_start`, więc brak cookie Strict nie tworzy sesji i nie nadpisuje istniejącego cookie. Sprawdza state pod blokadą; pasujący state usuwa i zapisuje ten fakt przed obsługą kodu. Brak, błędny, wygasły i użyty state są odrzucane. Odmowa OLX i brak kodu także zużywają poprawny state. Nie ma wtedy wymiany tokenów.

Kod trafia tylko do zaszyfrowanego rekordu oczekującego, na maksymalnie 2 minuty i nie dłużej niż ważność pierwotnej próby. Strona powrotna nie zawiera kodu, tokenów ani zewnętrznych zasobów; ma no-store, no-referrer i restrykcyjne CSP. Przycisk prowadzi do tej samej domeny z losowym uchwytem oczekującej operacji. Uchwyt nie daje samodzielnie prawa do wymiany kodu.

Po powrocie panel wymaga aktywnej pierwotnej sesji i dodatkowego POST z CSRF. Sprawdza powiązanie operacji z sesją, termin i jednorazowość; usuwa oczekującą operację przed wymianą kodu. Jeżeli sesja została faktycznie utracona, należy zalogować się i rozpocząć nową próbę. Nie dopuszczamy kontynuacji cudzego procesu po samym zalogowaniu. Główne cookie pozostaje SameSite=Strict.

Rekordy wygasłe nie mogą zostać wykorzystane; fizycznie znikają przy obsłużeniu pasującego callbacku/dokończenia, nowej próbie lub rozłączeniu. Nie ma procesu okresowo kasującego nieodwiedzone, zaszyfrowane rekordy po upływie TTL.

## 4. Magazyn tokenów i odświeżanie

Jeden prywatny `account.enc` poza webrootem i repozytorium. Szyfrowanie uwierzytelnione libsodium secretbox, osobny losowy nonce dla każdego zapisu i 32-bajtowy klucz z prywatnej konfiguracji/środowiska. Klucz nie znajduje się w samym magazynie. Zapisy przez unikalny plik tymczasowy i atomową zamianę. Pliki 0600, katalog powinien mieć 0700 oraz właściciela procesu PHP.

`flock` obejmuje cały odczyt–odświeżenie–zapis, także komunikację z token endpointem. Równoległe odświeżenia i rozłączenie nie mogą nadpisać nowszego refresh tokenu. Przed wysłaniem refresh tokenu zapisujemy stan wymagający ponownego połączenia; po sukcesie atomowo zapisujemy nowe tokeny i stan połączenia. Po błędzie lub przerwaniu procesu nie ponawiamy ślepo starego rotowanego tokenu. To zachowanie celowo może wymagać ponownego OAuth po chwilowym problemie sieci.

Token odnawia się na żądanie podczas operacji wymagającej tokenu, z zapasem 90 sekund. W etapie B przycisk „Sprawdź ważność tokenu” wywołuje tę samą funkcję. Nie ma crona, publikacji ani synchronizacji katalogu. Po dłuższej przerwie OLX może wymagać ponownej autoryzacji. Panel nie zwraca żadnej wartości tokenu. Rozłączenie usuwa lokalne tokeny i oczekujące próby; nie wywołuje OLX API i nie cofa zgody udzielonej aplikacji po stronie OLX.

## 5. Client Secret i Getspace

Preferowane są prawdziwe zmienne środowiskowe procesu PHP domeny:

- `OLX_CLIENT_ID=203399`
- `OLX_REDIRECT_URI=https://mgoutlet.pl/admin/olx/callback`
- `OLX_CLIENT_SECRET` — prawdziwą wartość ustawia właściciel wyłącznie na serwerze.
- `OLX_TOKEN_KEY` — base64 losowych 32 bajtów; przechowywać oddzielnie od danych tokenowych.
- `OLX_STORAGE_DIR` — istniejący prywatny katalog poza webrootem i repo.
- `OLX_CALLBACK_LOGS_REDACTED=1` — dopiero po rzeczywistej weryfikacji wszystkich logów z hostingiem.

Nie mam dostępu administracyjnego do konta Getspace ani konfiguracji vhost/proxy. Inwentaryzacja dostępnych przeglądarek nie wykazała otwartej sesji Getspace (brak kart). Nie potwierdziłem, że ten pakiet udostępnia ustawianie env lub logów. Nie ma więc zweryfikowanej ścieżki menu dla zmiennych środowiskowych. Jeżeli ustawia je operator w puli PHP-FPM, potrzebne może być przeładowanie tej puli; wdrożenie kodu nie ustawia env. Nie wpisywać sekretu do publicznego `.htaccess`, `.user.ini`, phpinfo ani GitHub Actions.

Dokładne miejsce w obsługiwanym wariancie plikowym, bez potrzeby obsługi env:

1. Zalogować się do Getspace → aktywna usługa → DirectAdmin → menedżer plików.
2. Otworzyć katalog domeny zawierający `public_html`, zwykle `/home/NAZWA_KONTA/domains/mgoutlet.pl/`. Potwierdzić go względem rzeczywistego DocumentRoot; nie zgadywać nazwy konta.
3. **Obok** `public_html` utworzyć `private_olx` (0700), a w nim `storage` (0700). Nie tworzyć ich wewnątrz `public_html`, innego publicznego webrootu ani repozytorium.
4. W `private_olx/config.php` (0600) umieścić poniższą strukturę i wkleić prawdziwy sekret wyłącznie pomiędzy apostrofy przy `OLX_CLIENT_SECRET`. Wartości zawierające apostrof lub backslash muszą być prawidłowo escapowane jako literał PHP. Klucz wygenerować bezpiecznie na serwerze przez `base64_encode(random_bytes(32))`, nie przesyłać go do czatu. Puste wartości pozostawiają moduł zablokowany.

```php
<?php
return [
    'OLX_CLIENT_ID' => '203399',
    'OLX_CLIENT_SECRET' => '',
    'OLX_REDIRECT_URI' => 'https://mgoutlet.pl/admin/olx/callback',
    'OLX_TOKEN_KEY' => '',
    'OLX_STORAGE_DIR' => __DIR__ . '/storage',
    'OLX_CALLBACK_LOGS_REDACTED' => '0',
];
```

Kod domyślnie szuka tego pliku w katalogu `private_olx` obok publicznego katalogu aplikacji, wyliczając ścieżkę z lokalizacji `admin/olx`. Dla niestandardowej struktury można wskazać bezwzględną ścieżkę przez `HGO_OLX_CONFIG`. Konfiguracja ze środowiska ma pierwszeństwo. Kod odrzuca pliki i magazyny w webroocie aplikacji oraz w repozytorium Git. Operator musi również potwierdzić, że prywatnego katalogu nie udostępnia inna domena, alias lub symlink.

Przy pliku konfiguracji zwykle wystarczy nowy request; przy wyłączonej walidacji timestampów OPcache potrzebne może być jego odświeżenie przez operatora. Nie wymaga to ponownego uploadu sekretu przez deployment. W panelu `/admin/olx/` widoczny jest wyłącznie stan konfiguracji, bez wartości i fragmentów sekretów. Status „Gotowa” oznacza, że spełniono lokalne warunki konfiguracji, a nie że OLX już zaakceptował dane konta.

## Ochrona access logów — warunek przed rzeczywistym OAuth

Nie wprowadzono pozornego czyszczenia query w PHP. PHP nie może cofnąć logowania wcześniejszych warstw. Dla Apache rozwiązanie wymaga konfiguracji serwera/vhost: warunkowego wyłączenia logowania callbacku lub formatu requestu bez query (`%m %U %H`, bez `%r`, `%q` i query w refererze). Dla ewentualnego nginx/proxy/CDN/WAF trzeba osobno wyłączyć zapis query/request body oraz diagnostykę OAuth. Sam dodatkowy „bezpieczny” log nie pomaga, jeśli pozostały nadal zapisuje pełne żądanie. Zabezpieczenie musi objąć także bezpośredni techniczny adres `/admin/olx/callback.php`, błędy, redirecty i ewentualne warianty ścieżki.

Do potwierdzenia przez Getspace przed ustawieniem flagi:

> Proszę ustalić wszystkie warstwy logowania żądań dla mgoutlet.pl, w tym HTTPS, proxy, WAF i logi błędów. Dla ścieżek callbacku OLX `/admin/olx/callback` i `/admin/olx/callback.php` proszę wyłączyć logowanie query string (code/state), treści i wrażliwych nagłówków albo wyłączyć ich access log. Proszę potwierdzić brak zapisu w pozostałych logach i możliwość bezpiecznego ustawienia env procesu PHP oraz prywatnego katalogu poza webrootem.

Nie wysłano tej wiadomości. Po zmianie operator powinien sprawdzić wszystkie logi przy użyciu sztucznego znacznika, bez prawdziwego authorization code. Dopiero po potwierdzeniu ustawić flagę na `1`. Jeśli hosting nie umożliwia ochrony logów, **produkcyjne OAuth pozostaje wyłączone**; należy uzgodnić rozwiązanie z operatorem przed uruchomieniem. Nie ma potwierdzenia, że obecne logi Getspace są już bezpieczne.

Źródła: [OLX API](https://developer.olx.pl/api/doc), [oficjalny schemat](https://developer.olx.pl/swagger/v2/partner_api.yaml), [Apache mod_log_config](https://httpd.apache.org/docs/current/en/mod/mod_log_config.html), [DirectAdmin — konfiguracja Apache](https://docs.directadmin.com/webservices/apache/customizing.html), [Getspace — wejście do DirectAdmin](https://support.getspace.pl/pl/knowledgebase/article/jak-zalogowa%C4%87-si%C4%99-do-direct-admin-twojego-panelu-serwera).

## 6. Testy

Testy lokalne używają sztucznych kodów/tokenów, kontrolowanego transportu i katalogów tymczasowych. OAuth: generowanie URL/scope, brak/błędny/wygasły/ponownie użyty state, odmowa, inna sesja, ponowne użycie i wygaśnięcie dokończenia, szyfrowanie, rotacja refresh, brak ponowień po błędzie, rozłączenie, zły klucz, blokada niepotwierdzonych logów i publicznej ścieżki magazynu. Test HTTP korzysta z lokalnego serwera i testowej sesji; potwierdza Strict, HttpOnly, logowanie, CSRF, callback bez Set-Cookie, brak kodu w HTML, replay i powiązanie z sesją.

Migrator CLI testowany na sztucznym JSON: podgląd bez zapisu, bramki SHA256/okna technicznego, odrzucenie publicznego backupu, zgodna kopia, zachowanie pól i idempotencja. Funkcja migracji zachowuje ID po zmianie nazwy/sluga/kategorii i kolejności.

Przeszły też istniejące testy: admin-figure-entry, catalog-presentation, codex-draft, owner-decisions-migration, public-products-snapshot i prerender-privacy; lint zmienionych plików PHP, składnia JS i git diff --check. Osobny test dwóch równoległych procesów potwierdził pojedyncze odświeżenie i użycie wspólnego nowego tokenu. Nowe testy dodano do workflow. Nie uruchomiono pełnego workflow wdrożeniowego.

Nie wykonano realnego OAuth na OLX, testu cookie w prawdziwej przeglądarce przy przejściu między domenami ani testów zalogowanego panelu na urządzeniach. Test HTTP sam nie dowodzi zachowania polityk przeglądarki. Po rozwiązaniu logów, konfiguracji i wdrożeniu na uzgodnione środowisko należy sprawdzić: połączenie konta → powrót z OLX → dokończenie → ważność tokenu → rozłączenie. Bez publikowania ogłoszeń.

## 7. Ryzyka

- Niepotwierdzone logi Getspace to blokada produkcyjnego OAuth, nie rozwiązany problem hostingu.
- Wymagane rozszerzenia PHP: cURL i sodium, bezpieczne CA/TLS, zapisywalny prywatny katalog i poprawny właściciel plików; CI je przygotowuje, stan serwera trzeba sprawdzić.
- Nieodwracalny skutek wycieku tokenów/klucza wymaga cofnięcia uprawnień OLX i ponownego połączenia, nie tylko rollbacku kodu.
- Refresh po błędzie wymaga ponownego OAuth, by unikać użycia nieaktualnego rotowanego tokenu.
- Zapis awaryjny pliku i kopie prywatnego magazynu muszą pozostać niedostępne publicznie; backup konfiguracji/klucza wymaga oddzielnej kontroli dostępu.
- Migracja wymaga zatrzymania wszystkich zapisów produktów; sam ostatni hash nie usuwa wyścigu ze starszym kodem między sprawdzeniem a rename.
- Aktualny kod panelu nadal używa indeksów w starszych operacjach. Etap B nie przebudowuje tego mechanizmu; przyszłe operacje OLX muszą wybierać rekord przez productId.

## 8. Rollback

Nie wykonano push, commitu ani deploymentu. Powrót lokalnego kodu obejmuje wyłącznie pliki tej zmiany; nie ruszać zastanych nieśledzonych raportów. Przed przyszłym wdrożeniem zapisać commit bazowy i sprawdzić kopię istniejącego workflow. Rollback kodu przez dotychczasowy Actions/FTPS nie może nadpisać produktów, uploads, zamówień ani sekretów.

Przed wycofaniem po uruchomieniu OAuth rozłączyć lokalne konto. W sytuacji incydentu cofnąć uprawnienia także na OLX. Prywatna konfiguracja poza webrootem nie jest objęta deploymentem; usunięcie jej/klucza nie kończy ogłoszeń, lecz uniemożliwia odczyt magazynu. Nie przywracać starej kopii refresh tokenu: po rotacji mogła utracić ważność.

Migracja ID jest addytywna: przy rollbacku kodu najlepiej zachować ID, bo starszy zapis zachowuje nieznane pola istniejącego rekordu. Pełne odtworzenie JSON z kopii tylko w zatrzymanym oknie i po sprawdzeniu, czy nie utraci nowszych zmian. Po przyszłym powiązaniu z OLX nie wolno regenerować ani usuwać ID.
