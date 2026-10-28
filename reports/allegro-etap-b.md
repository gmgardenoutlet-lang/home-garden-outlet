# Allegro — raport etapu B

Data: **26.10.2026**, Europe/Warsaw. Home & Garden Outlet, mgoutlet.pl.

**Stan: infrastruktura zaimplementowana i sprawdzona lokalnie; sprzedażowe API write-disabled. Nie wykonano deploya, GitHub Actions, FTPS, prawdziwego OAuth ani zmian danych produkcyjnych. Nie rozpoczęto etapu C.**

Repozytorium: `C:/Users/Albert/Documents/Strona www/github-deploy`. Wszystkie ścieżki poniżej są względem tego katalogu. Podstawa: cały `reports/allegro-audyt-etap-a.md`; szczegółowa instrukcja operacyjna: `documents/allegro-etap-b.md`. Ten raport jest końcowym raportem A–U; wcześniejszy `reports/allegro-etap-b-lokalnie.md` jest pomocniczym podsumowaniem.

## A. Stan przed zmianami

Przed pierwotną implementacją B: HEAD `849121bf20ec012f89c293b17e690ddf91c28b36`, brak zmian śledzonego kodu; istniały nieśledzone raporty. Architektura PHP/Getspace, istniejący productId i OAuth OLX były zgodne z audytem. Nie zmieniano źródła produktów ani nie migrowano ID.

Przed bieżącym dokończeniem: ten sam HEAD, sześć śledzonych plików zmienionych przez wcześniejszą pracę B, nowy moduł/testy/dokumentacja B i zastane nieśledzone raporty. Są to kontynuowane, autoryzowane zmiany tego czatu; nie cofano ich i nie nadpisano cudzych raportów. Dokładne nowe pliki i zmiany względem HEAD podano w N–O.

Istotne doprecyzowania dokumentacji: wymiana kodu z PKCE używa client_id/verifier zamiast Basic; kod autoryzacyjny Allegro żyje **10 sekund**. Pierwotny lokalny wariant z ręcznym potwierdzeniem do 2 minut był błędny dla takiego kodu. Poprawiono go na automatyczny mostek, nadal z loginem, binding i POST+CSRF. Nie zmieniano OLX ani globalnej sesji.

## B. Zaimplementowana architektura

Osobny `hosting/getspace/admin/allegro/`, bez wspólnego frameworka z OLX. Loader/config, storage, HTTP, OAuth, callback, panel, mały skrypt mostka i ochrona Apache. Wykorzystuje istniejące helpery login/CSRF/flash oraz styl panelu. Produkty i productId nie są zmieniane przez moduł.

Prawdziwa konfiguracja i tokeny mają być poza Git i webrootem, w osobnym private_allegro. Nie utworzono ich na hostingu. Moduł nie ma oferty, propozycji produktu, uploadu zdjęć, synchronizacji, pętli po katalogu ani operacji masowych. OAuth domyślnie wyłączony.

## C. OAuth flow

1. Zalogowany administrator rozpoczyna przez POST+CSRF. Serwer sprawdza gotowość, generuje state i PKCE, zapisuje krótki kontekst zaszyfrowany.
2. Przekierowanie do właściwego hosta Allegro, Authorization Code i minimalny zakres profilu.
3. Callback sprawdza i zużywa state, bez uruchamiania sesji i bez wymiany kodu. Zapisuje szyfrowany pending z kodem/verifierem i losowy handle.
4. Natychmiastowa nawigacja dokumentu callbacku na tę samą domenę przez meta refresh; jest również link awaryjny. Do przeglądarki trafia tylko handle, bez kodu/state/tokenów/verifiera.
5. Panel wymaga istniejącej sesji i sprawdza binding/TTL. Wyłącznie wtedy dołącza bridge.js, który automatycznie wysyła formularz finish metodą POST z sesyjnym CSRF. GET nie wymienia kodu. Skrypt dopuszcza tylko lokalny action `/admin/allegro/` i usuwa handle z adresu strony panelu.
6. Finish jednorazowo usuwa pending przed wymianą kodu. Serwer wymienia kod, odczytuje /me i dopiero po walidacji zapisuje connected. Błąd nie uruchamia ślepego ponowienia.

Automatyczne finish realizuje zamiar rozpoczęty świadomym „Połącz z Allegro” i zgodą w Allegro; nie osłabia loginu/CSRF i nie wykonuje żadnych operacji sprzedażowych. Brak JS, utrata sesji lub zbyt długi powrót może wymagać rozpoczęcia od nowa.

## D. PKCE/state

State: 32 losowe bajty, hex 64 znaki, hash w szyfrowanym vault, binding sesji, TTL 10 min. Jedna oczekująca próba na konto; nowa zastępuje starą. Callback zużywa prawidłowy state również przy odmowie, braku kodu i wygaśnięciu. Replay jest odrzucany.

Verifier: 64 losowe bajty, base64url bez paddingu, **86 znaków**, wyłącznie serwerowo. Challenge: base64url(SHA256(verifier)), S256. Test obejmuje wektor RFC7636 i granice 43–128 znaków.

Pending mostka: **maks. 8 sekund od callbacku**, nie dłużej niż state, z marginesem wobec 10 sekund ważności kodu. Kontrola czasu po uzyskaniu blokady zapobiega przedłużeniu ważności przez oczekiwanie na lock. Wygasłe dane są odrzucane, a następne wejście do panelu je usuwa. Nie ma crona: nieużywany wygasły materiał może pozostać zaszyfrowany do następnego dostępu, lecz nie jest używalny.

## E. Magazyn tokenów

Sodium secretbox, odrębny klucz Allegro (32 bajty, base64 w prywatnej konfiguracji), losowy nonce na zapis. Zaszyfrowany dokument zawiera schemaVersion, environment, hash Client ID/redirect, status, minimalne identity, parę tokenów i expires_at; tymczasowo state/pending.

Ścieżka: prywatny storage root → prod albo sandbox → account.enc. Account lock obejmuje odczyt, zmianę, transport i zapis. Nowy plik powstaje przez unikalny temp w tym samym katalogu, pełny zapis/fflush/fsync i atomowy rename, bez usuwania starego vaultu. Błąd zachowuje poprzedni kompletny plik. Na Windows krótkie ponowienia dotyczą tylko lokalnego rename, nigdy HTTP.

Podkatalog 0700, pliki 0600; symlinki prywatnych plików są odrzucane. Ścieżki poza znanym webrootem i Git, także zakaz private_olx. Na Windows wymagane dodatkowe ACL; realne prawa hostingu nie zostały potwierdzone. Backup tokenów chronić oddzielnie od klucza, a nie we wspólnym publicznym archiwum.

## F. Refresh token

Access jest używany do expires_at z marginesem 90 s; odczyt samego statusu nie odświeża tokenów. Jawna diagnostyka lub serwerowe pobranie access w razie potrzeby odświeża pod account.lock. Dwa równoległe procesy wykonują jeden refresh.

Przed użyciem refresh trwale zapisywany jest reconnect bez tokenów. Nową parę i scope trzeba zweryfikować i atomowo zapisać przed zwróceniem access. Timeout, niejednoznaczność lub restart oznacza fail-closed i nowe połączenie; brak agresywnego retry starego refresh. Dokumentacja opisuje access 12 h, odnawialny refresh 3 miesiące i okno starego refresh 60 s; kod używa faktycznego expires_in i nie wykorzystuje okna do ślepych ponowień.

## G. Callback

`hosting/getspace/admin/allegro/callback.php`; bez boot_admin/session_start. Przyjmuje tylko skalarne code/state/error przez GET. Nie wypisuje wartości wejściowych, nagłówków ani tokenów. Używa no-store, no-referrer i restrykcyjnego CSP.

Dodano `^allegro/callback/?$ allegro/callback.php [END]` w admin/.htaccess. Root nie przechwytuje ogólnie admina; główny .htaccess nie wymagał zmiany. Reguła Apache sprawdzona statycznie, a PHP testowy odtwarza trasę — nie jest to potwierdzenie produkcyjnego Apache.

Po poprawnym callbacku automatyczny powrót do tej samej domeny uruchamia bezpieczny POST w sesji; tokeny są wymieniane wyłącznie po stronie serwera. Krótkiego rzeczywistego round-trip w docelowej przeglądarce nie potwierdzono bez OAuth.

## H. Panel administratora

Link „Allegro” w istniejącej nawigacji. Strona pokazuje: gotowość konfiguracji i prywatnego storage, PROD/SANDBOX, włączenie OAuth i potwierdzenie logów, status połączenia, login/ID, ważność tokena i ostatnią diagnostykę. Wartości identity są escapowane.

Akcje: Połącz z Allegro, automatyczne finish, Sprawdź połączenie — odczyt konta, Odłącz konto z potwierdzeniem. Każda akcja wymaga loginu i POST+CSRF. Przycisk połączenia jest niedostępny bez bezpiecznej konfiguracji. Brak przycisków ofert przy figurach i brak batcha.

Odłączenie usuwa lokalne tokeny i wszystkie próby OAuth, nie dotyka przyszłych plików mapowań. Działa także z wyłączonym OAuth, o ile klucz/storage są poprawne. Nie wysyła revoke, nie kończy ofert i wyjaśnia, że użytkownik może usunąć aplikację w „Powiązanych aplikacjach” Allegro.

## I. Rozdzielenie PROD/SANDBOX

Środowisko pochodzi wyłącznie z prywatnej konfiguracji/env. Brak przełącznika GET. Hosty są ustalone w kodzie. Operator ma stosować odrębne rejestracje, credentiale, klucze i bazowe storage root dla instancji PROD/SANDBOX; dodatkowo kod dzieli magazyn na prod/sandbox i wiąże szyfrogram z environment oraz aplikacją/redirectem.

Test przekopiowania vaultu Sandbox do PROD przy tym samym syntetycznym kluczu kończy się odrzuceniem. Zmieniony Client ID również nie może odczytać starego vaultu. SANDBOX wymaga callbacku innego niż produkcyjny URI i odrębnej instancji administratora. Nie utworzono jej samowolnie.

## J. Zabezpieczenia HTTP

Dozwolone dokładnie POST token endpoint na allegro.pl lub allegro.pl.allegrosandbox.pl oraz GET /me na odpowiednim api. Wybór właściwego hosta wynika z config; niskopoziomowy cURL powtarza kontrolę metody/URL. Brak dowolnych URL, query i redirectów. HTTPS, TLS peer/host validation, connect timeout 8 s, timeout 25 s, limit odpowiedzi 128 KiB podczas odbioru. Ograniczenie 8 s pending nie jest gwarancją czasu odpowiedzi sieci — przeterminowany kod odrzuci także Allegro.

Wymiana PKCE: client_id i verifier, bez Basic. Refresh: Basic. Bearer wyłącznie serwerowo. Kontrolowane wyjątki ze stałymi komunikatami, bez surowych body/cURL errors. Nie ma automatycznych HTTP retry. Odczyt /me zatrzymuje dostęp przy 401 lub niezgodnym seller ID.

## K. Write-disable dla ofert

Brak wykonywalnych modułów tworzenia/edycji/publikacji/zakończenia oferty, proponowania produktu i zdjęć. Polityka nie dopuszcza żadnego `/sale/...`, także odczytu, bo etap B potrzebuje wyłącznie /me. POST/PATCH/PUT/DELETE do sale są odrzucane lokalnie **przed wywołaniem transportu**. Tak samo blokowane są nieznane hosty, upload i inne ścieżki. Nie istnieje flaga włączająca sprzedaż bez nowej zmiany kodu.

Zwykły zapis produktu nie ładuje modułu Allegro. Test faktycznie zapisuje zmianę ceny syntetycznej figury w osobnej kopii aplikacji, zachowuje ID i potwierdza brak zmian vaultu/logów i brak modułu w included files. Sieć jest w tym serwerze testowym wyłączona.

## L. Logowanie i redakcja sekretów

Prywatne technical.jsonl/log.lock, rotacja 256 KiB do jednej kopii. Allowlista: czas UTC, stały typ operacji/errorCode, środowisko, lokalny correlationId, numeryczny HTTP status i seller ID. Pozostałe pola są odrzucane. Nie zapisujemy code, state, verifier, tokenów, secret, Authorization, cookies, pełnych callback URL ani surowych odpowiedzi.

**BLOCKER PRZED PRODUKCYJNYM OAUTH:** nie potwierdzono redakcji query w Apache/proxy/CDN/APM/Getspace. Nie badano logów produkcji. Operator musi sprawdzić zarówno czysty callback, jak i callback.php. Flaga ALLEGRO_CALLBACK_LOGS_REDACTED jedynie deklaruje wcześniejsze potwierdzenie, sama nie zmienia konfiguracji logów. Domyślnie jest 0, tak jak ALLEGRO_OAUTH_ENABLED.

## M. Testy

Wyniki dotyczą lokalnych atrap i izolowanych kopii, nie rzeczywistego konta. PHP 8.5.11 z curl/sodium, Node 24.22.1, Windows. CI pozostaje skonfigurowane na PHP 8.3/Node 22, ale nie uruchamiano Actions ani osobnego runtime 8.3.

| Wymagany test | Wynik | Dowód |
|---|---|---|
| 1. Generowanie state | PASS | Format, losowość kolejnych prób |
| 2. Jednorazowość state | PASS | Callback replay i powtórzenie finish odrzucone |
| 3. TTL state | PASS | Wygaśnięcie, zastąpienie i czyszczenie |
| 4. PKCE | PASS | Wektor RFC7636, długości i właściwy verifier w wymianie |
| 5. Szyfrowanie/dekrypcja | PASS | Odczyt po zapisie, świeży nonce, brak plaintext |
| 6. Błędny klucz | PASS | Odrzucona dekrypcja |
| 7. Atomowy zapis | PASS | Zachowanie starego pliku przy błędzie; 60 zapisów i 600 bezpośrednich odczytów |
| 8. Lock refresh | PASS | Dwa procesy PHP, jeden refresh |
| 9. Sanitizacja logów | PASS | Allowlista i odrzucane złośliwe dane |
| 10. CSRF | PASS | Wszystkie akcje HTTP, także niepoprawny typ parametru |
| 11. Callback błędny state | PASS | Błędny/brakujący/array state |
| 12. Callback bez code | PASS | Odrzucenie i zużycie state |
| 13. Callback error Allegro | PASS | Odmowa bez wymiany kodu, brak replay |
| 14. PROD/SANDBOX | PASS | Katalogi, kontekst, hosty, brak wyboru przez GET |
| 15. Brak tokenów w HTML | PASS | Sentinele sekretów, escaping loginu |
| 16. Brak tokenów w logach | PASS | Sentinele i złośliwe body nie występują |
| 17. Secret niepubliczny | PASS | Prywatne ścieżki poza rootem; sentinele nie przechodzą builda |
| 18. Mutujące sale zablokowane | PASS | Policy przed atrapą i także w cURL |
| 19. Zapis produktu bez Allegro | PASS | Rzeczywisty save_product na izolowanej kopii |
| 20. Regresja OLX | PASS | olx-oauth, olx-http, olx-identity-verification |
| Termin kodu 10 s | PASS | Pending 8 s, expiry/binding i stary czas sprzed locka |
| Automatyczny mostek | PASS | Meta refresh, sesyjny CSRF, pojedynczy lokalny POST w VM; obcy action odrzucony |
| Fail-closed refresh/wymiana | PASS | Brak agresywnych retry i powtórnego użycia |
| /me i zgodność identity | PASS | Minimalne zapisane dane, odrzucenie innego konta |
| Artefakt i routing | PASS | allegro-artifact-tests, kontrola publicznego publish |
| Lint/format | PASS | Lint PHP, składnia YAML i git diff --check |

Testy: tests/allegro-oauth-tests.php, tests/allegro-http-tests.mjs, tests/allegro-artifact-tests.mjs. Dodatkowe uruchomione regresje: tests/olx-oauth-tests.php, tests/olx-http-tests.mjs, tests/olx-identity-verification-tests.mjs, tests/product-id-migration-tests.php, tests/admin-figure-entry-tests.php i tests/catalog-presentation-tests.php. Migrator testowany tylko na danych syntetycznych, nie wykonano migracji katalogu.

Końcowy wynik zestawu logiki Allegro: **197 asercji PASS**. Wszystkie wskazane powyżej zestawy przeszły po poprawieniu mostka; sprawdzono również składnię bridge.js.

Nie wykonano prawdziwego testu przeglądarkowego OAuth, Sandbox ani produkcyjnego smoke testu. „OLX PASS” oznacza testy regresji i brak zmiany kodu, nie nowe połączenie rzeczywistego konta. Szybkość mostka i nagłówki docelowego Apache pozostają do sprawdzenia przed live OAuth.

## N. Lista nowych plików

Względem HEAD początku etapu B; istniejące nieśledzone raporty innych zadań nie są częścią tej listy.

| Plik | Powód |
|---|---|
| hosting/getspace/admin/allegro/lib.php | Loader i bezpieczne wyjątki |
| hosting/getspace/admin/allegro/config.php | Prywatny config/env, środowisko, bramki |
| hosting/getspace/admin/allegro/storage.php | Szyfrowanie, atomowość, blokady i logi |
| hosting/getspace/admin/allegro/http.php | Zamknięta polityka i transport TLS |
| hosting/getspace/admin/allegro/oauth.php | State/PKCE, tokeny, konto, refresh/disconnect |
| hosting/getspace/admin/allegro/callback.php | Callback bez zastępowania sesji |
| hosting/getspace/admin/allegro/index.php | Kontrolowany panel i POST+CSRF |
| hosting/getspace/admin/allegro/bridge.js | Szybki POST mostka, bez kodu/tokenów w JS |
| hosting/getspace/admin/allegro/.htaccess | Ochrona bibliotek i lokalny CSP |
| scripts/getspace-artifact-privacy.mjs | Allowlista kodu i kontrola prywatności artefaktu |
| tests/allegro-oauth-tests.php | Logika i testy bezpieczeństwa |
| tests/allegro-http-tests.mjs | HTTP, sesje, mostek, współbieżność, izolowany zapis |
| tests/allegro-artifact-tests.mjs | Sentinele, Git/FTPS/routing |
| documents/allegro-etap-b.md | Pełna instrukcja operacyjna |
| reports/allegro-etap-b-lokalnie.md | Wcześniejsze pomocnicze podsumowanie, uaktualnione po korekcie mostka |
| reports/allegro-etap-b.md | Wymagany końcowy raport A–U |

## O. Lista zmienionych plików

| Plik | Obecna funkcja i powód zmiany |
|---|---|
| .gitignore | Ochrona private_allegro, storage/logs/cache/sekretów przed Git |
| scripts/build-getspace.mjs | Budowa publicznej paczki; ścisła allowlista modułu i końcowa kontrola |
| .github/workflows/deploy-getspace.yml | Dodane lokalne testy/warunki CI i dodatkowe wykluczenia FTPS; workflow nieuruchomiony |
| hosting/getspace/admin/.htaccess | Jedna dodatkowa reguła callbacku, dotychczasowa OLX zachowana |
| hosting/getspace/admin/index.php | Wyłącznie link Allegro w nawigacji, bez zmiany zapisu produktów |
| hosting/getspace/README.md | Odnośnik do instrukcji i ostrzeżenie o blokadzie OAuth |

admin/olx/*, admin/lib.php, catalog.php, data/products.json i product-identity pozostają bez zmian. Publiczny publish jest lokalnym, ignorowanym wynikiem builda, nie wdrożeniem. Nie wykonano commitów.

## P. Znane ograniczenia/blockery

1. **BLOCKER PRZED PRODUKCYJNYM OAUTH:** niepotwierdzone logi hostingu, query callbacku i warstwy pośrednie.
2. Brak faktycznej konfiguracji aplikacji/credentiali, storage, klucza i potwierdzonych praw na hostingu. Nie prosimy teraz o Client Secret.
3. Kod nie jest wdrożony; produkcyjny callback nie jest obecnie potwierdzony jako działający.
4. Brak skonfigurowanej instancji i credentiali Sandbox; niczego nie tworzono samowolnie.
5. Brak rzeczywistego round-trip w przeglądarce z Strict/CSP i wymianą w terminie 10 s. Atrapy nie dowodzą czasu rzeczywistej sieci.
6. Testy lokalne PHP 8.5; CI 8.3 nie zostało uruchomione. Przed przyszłym deployem przejść właściwe CI.
7. .htaccess sprawdzony statycznie; PHP development server nie odtwarza wszystkich reguł Apache.
8. Przy utracie klucza/uszkodzeniu vaultu potrzebny operator; panel nie obchodzi walidacji ani nie usuwa dowolnych plików.
9. Brak zakresu ofert jest celowy. GTIN, kategorie, ilości i zdjęcia pozostają blockerami późniejszych etapów, nie zostały rozstrzygnięte przez ten moduł.

## Q. Instrukcja konfiguracji serwera

**Procedura na później, niewykonana teraz.** Właściciel najpierw zatwierdza osobny etap uruchomienia. Operator:

1. Przygotowuje właściwą instancję PROD albo Sandbox, PHP z curl/sodium, TLS i poprawny routing. Potwierdza logi callbacku we wszystkich warstwach oraz nagłówki no-store/no-referrer/CSP.
2. Poza Git i public_html tworzy osobny private_allegro z config.php i storage. Foldery 0700, pliki 0600 według właściciela PHP; na Windows sprawdza ACL. Klucz nowy, niezależny od OLX. Nie publikuje configu ani klucza.
3. Wskazuje HGO_ALLEGRO_CONFIG lub stosuje domyślny private_allegro/config.php obok webrootu. Env ma pierwszeństwo, także jawnie puste wartości. Konfiguracja ma postać poniżej — puste miejsca nie są prawdziwymi danymi.

```php
<?php
return [
    'ALLEGRO_ENVIRONMENT' => 'SANDBOX',
    'ALLEGRO_CLIENT_ID' => '',
    'ALLEGRO_CLIENT_SECRET' => '',
    'ALLEGRO_REDIRECT_URI' => '',
    'ALLEGRO_TOKEN_KEY' => '', // losowe 32 bajty zakodowane base64
    'ALLEGRO_STORAGE_DIR' => '', // istniejący prywatny katalog
    'ALLEGRO_OAUTH_ENABLED' => '0',
    'ALLEGRO_CALLBACK_LOGS_REDACTED' => '0',
];
```

4. PROD/SANDBOX otrzymują odrębne credentiale, klucze i storage root. Nie wybiera środowiska parametrem URL i nie kopiuje tokenów między instancjami.
5. Po zatwierdzonym przyszłym deployu sprawdza, że biblioteki są niedostępne przez WWW, prywatne pliki poza rootem, a konfiguracja widoczna jest wyłącznie jako gotowa/brak. Wykonuje właściwy reload PHP/OPcache po zmianach; zmiana pliku nie jest dowodem aktywacji nowej konfiguracji.
6. Dopiero po potwierdzeniu logów i uzyskaniu osobnej zgody ustawia flagi na 1 w danym środowisku. Testuje Sandbox, potem osobno zatwierdzony PROD. Nie publikuje ofert.

Nie umieszczać sekretów w Git, Actions, JS, HTML, publicznym JSON, logach ani czacie. Kopie vaultu i klucza chronić oddzielnie. Odłączenie w panelu jest lokalne; cofnięcie zgody wykonuje użytkownik w ustawieniach Allegro.

## R. Dokładny redirect URI do późniejszej rejestracji

**PROD: `https://mgoutlet.pl/admin/allegro/callback`** — dokładnie, bez końcowego slash i query. Kod routingu ten URI obsługuje, ale nie został wdrożony ani zarejestrowany.

**SANDBOX:** jeszcze nie ustalono działającej domeny. Wymagana odrębna instancja i HTTPS URI zakończony `/admin/allegro/callback`, różny od PROD; należy go najpierw faktycznie przygotować i dopiero zarejestrować. Nie wpisano fikcyjnego adresu jako istniejącego.

## S. Jakie scope będą potrzebne

| Etap | Scope | Decyzja |
|---|---|---|
| B, identyfikacja konta /me | allegro:api:profile:read | Jedyny żądany obecnie |
| Przyszły odczyt ofert | allegro:api:sale:offers:read | Potwierdzony w OpenAPI, teraz nieżądany |
| Przyszłe zarządzanie ofertami | allegro:api:sale:offers:write | Potwierdzony w OpenAPI, teraz nieżądany |

Nie żądać zakresów zamówień, płatności ani wiadomości. Rozszerzenie zakresu wymaga przyszłej zmiany kodu i nowej zgody. Niezależnie od zakresu tokena obecna polityka HTTP nie pozwala zapisywać sale.

## T. Ręczne działania w Allegro Developer — później

**Nie wykonywaliśmy ich i nie są poleceniem uruchomienia OAuth teraz.** Po decyzji właściciela:

1. Przygotować własną aplikację w [Zarządzaniu aplikacjami Allegro](https://apps.developer.allegro.pl/), odpowiednio dla wybranego środowiska; Sandbox zgodnie z oficjalną instrukcją, odrębnie od PROD.
2. Wybrać typ aplikacji posiadającej dostęp do przeglądarki użytkownika, właściwy dla Authorization Code.
3. Zarejestrować dokładny, faktycznie przygotowany redirect URI z R; dla Sandbox dopiero po ustaleniu i uruchomieniu jego domeny.
4. Dla B wybrać tylko zakres profilu. Nie zaznaczać niepotrzebnych zamówień/płatności/wiadomości ani nie rozszerzać teraz do ofert.
5. Client ID/Secret przekazać wyłącznie do prywatnej konfiguracji serwera. Nie wklejać ich tutaj; teraz ich nie wymagamy.
6. Po spełnieniu blockerów i osobnej zgodzie rozpocząć „Połącz z Allegro” z właściwego panelu, sprawdzić konto i zakres. Testować wyłącznie infrastrukturę i /me; żadnych ofert.

Źródła sprawdzone ponownie 26.10.2026: [oficjalny poradnik OAuth](https://developer.allegro.pl/tutorials/uwierzytelnianie-i-autoryzacja-zlq9e75GdIR), [oficjalne OpenAPI](https://developer.allegro.pl/swagger.yaml). OpenAPI potwierdza /me oraz wskazane zakresy ofert. Nie bazowano na nieoficjalnych tutorialach.

## U. Czy możemy przejść do prawdziwego OAuth?

**NIE — obecnie nie są spełnione warunki uruchomienia i nie ma zgody na rzeczywisty flow.** Najważniejsze: logi callbacku niepotwierdzone, brak wdrożonej/przygotowanej prywatnej konfiguracji i aplikacji, brak rzeczywistego testu krótkiego mostka w Sandbox oraz weryfikacji docelowego Apache/TLS/CSP.

Lokalny kod i atrapy są gotowe do dalszej kontrolowanej weryfikacji infrastruktury. To nie jest zgoda na deploy, założenie Sandbox ani OAuth produkcyjny. Po spełnieniu opisanych warunków właściciel osobno autoryzuje następny krok. **Prace zatrzymują się na etapie B.**
