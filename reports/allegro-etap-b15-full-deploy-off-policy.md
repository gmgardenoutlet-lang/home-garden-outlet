# Allegro B.1.5 — pełny deploy zachowuje OAuth OFF

Data: 28.10.2026. Zakres: lokalna implementacja, kwalifikacja i raport. **Bez commitu, push, Actions, FTPS, Getspace, deploya i prawdziwego OAuth.** Raport B.1.4 przeczytano w całości; kandydat B.1.3 zweryfikowany hashami i aktualnym kodem. Historyczne raporty zachowano.

## A. Aktualny main

Wykonano dozwolony `git fetch origin main`. Aktualny origin/main: `df4c32cc77f2500a5ba238d892803f9afac1f7bd`, zgodny z B.1.3/B.1.4. Nie wykonywano reconciliation do innego SHA.

Nowy izolowany checkout: `C:/Users/Albert/Documents/Strona www/github-deploy/.local-cache/allegro-b15-candidate`. Utworzono lokalny klon historii, checkout detached HEAD na powyższym SHA i nałożono wyłącznie 29 plików B.1.3, sprawdzonych względem snapshotu B.1.4. Dopiero w tym nowym kandydacie wykonano zmiany B.1.5. B.1.3 i oryginalny brudny worktree pozostają bez zmian kodu; oryginał otrzymuje wyłącznie nowy raport i ignorowane dowody. Origin nowego klonu wskazuje lokalne repo, nie GitHub; przyszła publikacja wymaga jawnego przygotowania brancha i remote po zgodzie.

## B. B14-FULL-01

Dedykowany builder wymuszał `B.1.1-OAUTH-OFF-v1`, ale pełny builder kopiował aktywowalny źródłowy loader config.php. Kolejny pełny deploy mógł nadpisać OFF wersją zależną od ALLEGRO_OAUTH_ENABLED / ALLEGRO_CALLBACK_LOGS_REDACTED. B.1.4 w jednym zdaniu skracał tę drugą nazwę do ALLEGRO_LOGS_SAFE; rzeczywista nazwa w kodzie i testach to ALLEGRO_CALLBACK_LOGS_REDACTED. Nie zmieniano zmiennej konfiguracyjnej.

Problem dotyczył artefaktu i alternatywnej ścieżki publikacji, nie zmiany protokołu OAuth ani scope. Wyłączenie workflow nie jest rozwiązaniem tego problemu w kodzie.

## C. Analiza wariantu A

Pełny build dostarcza dziewięć publicznych plików `admin/allegro/`: .htaccess, index.php, callback.php, bridge.js, lib.php, config.php, oauth.php, http.php, storage.php. Dwa współdzielone pliki admin/.htaccess i admin/index.php nadal zawierają routing i nawigację. Wariant A wymusza dokładnie ten sam config.php OFF co dedykowany builder i normalizuje LF dziewięciu plików modułu. Zachowuje zgodność świeżej instalacji, callbacku i panelu.

Odrzucany jest nieznany hash źródłowego loadera, niejednoznaczna transformacja oraz zły hash payloadu. Build kończy się błędem przed krokiem transferu. Flagi i prywatny config nie wpływają na transformację.

## D. Analiza wariantu B

Przeczytano pełny workflow i kod faktycznie wskazanej action `SamKirkland/FTP-Deploy-Action@v4.4.0`. Odczyt tagu 2026-10-28T08:11:49Z: commit `110f9186c050f71550953127052e77650219c287`; SHA-256 pobranego dist/index.js: `dcacab0087a5b1f4c82bc0fbf3440f4639eed945c1b4deb5c219cb202bc1742a`. Nie uruchamiano action ani klienta FTP.

Transfer główny: local-dir=./publish/, server-dir z sekretu, protokół ftps. Nie ustawiono dangerous-clean-slate, którego domyślna wartość w kodzie wynosi false. To jednak synchronizacja ze stanem, a nie wyłącznie upload. Action pobiera listę wcześniej wdrożonych plików, filtruje exclude po obu stronach, oblicza HashDiff i usuwa pozycje ze starego stanu nieobecne lokalnie. Brak pliku w publish może więc spowodować usunięcie pliku wcześniej zarządzanego przez tę action. Plik dodany innym narzędziem i nieobecny w stanie nie jest automatycznie tym samym przypadkiem; bez odczytu stanu hostingu nie można zakładać jego klasyfikacji. [Kod action](https://github.com/SamKirkland/FTP-Deploy-Action/blob/110f9186c050f71550953127052e77650219c287/dist/index.js).

Jawne exclude obejmujące cały moduł mogłoby wyłączyć go z głównego publish/delete; samo pominięcie w artefakcie tego nie zapewnia. dangerous-clean-slate usuwa także excluded, jeśli ktoś je włączy. [Kontrakt inputs](https://github.com/SamKirkland/FTP-Deploy-Action/blob/110f9186c050f71550953127052e77650219c287/action.yml).

Pozostałe ścieżki workflow też mają znaczenie: backup pobiera produkcję, kopiuje backup na prywatny katalog przez reverse mirror; rollback wykonuje `mirror --reverse --delete` z backupu do public_html. Nie jest to wyłącznie append-only transfer. Wariant B wymagałby przeglądu i uzgodnienia wykluczeń wszystkich tych ścieżek, zarządzania istniejącą wersją modułu oraz gwarancji jego obecności przy nowym wdrożeniu. Sam source tree z linkiem/routingiem i pominiętym modułem nie gwarantuje braku 404/500. **Wariant B w obecnym workflow odrzucony: wymaganych gwarancji nie udowodniono.**

## E. Wybrane rozwiązanie

**Wariant A.** Wspólny kontrakt `scripts/allegro-off-profile.json` jest jedynym źródłem trzech zamian, reviewed source hash, expected payload hash oraz nazwy profilu. Python i Node czytają ten sam plik; nie mają dwóch niezależnych zestawów replacementów. Każdy konsument sprawdza schema/profile, hash źródła, dokładnie jedno wystąpienie każdej zamiany i końcowy hash. Niezależna asercja testu publicznego artefaktu zamraża dotychczasowy wynik d104…bfd9.

Nie refaktoryzowano architektury. W helperze prywatności dodano minimalne zastosowanie kontraktu do publicznego modułu. Dedykowany builder zachowuje dotychczasowe reguły review i allowlistę. Pełny builder kończy transformację przed sukcesem buildu; workflow zachowuje bramkę artefaktu przed transferem. Oba workflow otrzymały syntetyczny test pełnego OFF. Pełny checkout ma fetch-depth:0, ponieważ nowy test dedykowanego release odczytuje jawny BASE przez git show; płytki checkout przyszłego commitu mógłby go nie zawierać.

Profile name pozostaje `B.1.1-OAUTH-OFF-v1`: runtime payload nie zmienił się ani o bajt. Zmieniła się gwarancja jego użycia również przez pełny build. v2 sugerowałoby inną semantykę runtime i nie jest potrzebne.

## F. Zmienione pliki

Delta kodu/narzędzi/dokumentacji wykonawczej względem kandydata B.1.3:

| Ścieżka Git | Zmiana |
|---|---|
| `.github/workflows/deploy-getspace.yml` | zmieniony |
| `.github/workflows/deploy-allegro-infra-getspace.yml` | zmieniony |
| `scripts/build-getspace.mjs` | zmieniony |
| `scripts/getspace-artifact-privacy.mjs` | zmieniony |
| `scripts/allegro-infra-release.py` | zmieniony |
| `tests/allegro-artifact-tests.mjs` | zmieniony |
| `tests/allegro-infra-release-tests.py` | zmieniony |
| `hosting/getspace/README.md` | zmieniony |
| `scripts/allegro-off-profile.json` | nowy |
| `tests/allegro-full-build-off-tests.py` | nowy |
| `tests/catalog-performance-tests.mjs` | zmieniony |

Dodatkowo do nowego release dołączono niezmieniony raport B.1.4 i nowy raport B.1.5. Zmiana testu catalog-performance dopuszcza tylko konkretne dodatki Allegro oraz fetch-depth; pozostały tekst workflow, transfer, backup, migratory, OLX i rollback nadal porównuje z dotychczasową bazą. Nie usunięto tej asercji i nie rozszerzono jej na dowolne zmiany workflow.

Nie zmieniono żadnego źródłowego pliku runtime Allegro, admin/index.php ani admin/.htaccess względem B.1.3. Nie zmieniono admin/olx, workflow OLX, helperów, mapowań, draftów, publikacji ani produktów. Do wykonania B.1.5 nie używano stagingu.

## G. Pełny build

Uruchomiono rzeczywisty `node scripts/build-getspace.mjs` na osobnym fixture, z ośmioma syntetycznymi produktami, syntetycznymi plikami reviews/shipping i build-only placeholderem vendor/autoload.php. Nie pobierano ani nie używano produkcyjnego products.json w buildzie. Placeholder nie jest kwalifikacją zależności Composer i nie jest elementem release set; test dotyczy polityki Allegro oraz procesu kopiowania. Testy nie zapisują katalogu sklepu.

ENV buildu zawierało ALLEGRO_OAUTH_ENABLED=1, ALLEGRO_CALLBACK_LOGS_REDACTED=1 i kompletną syntetyczną konfigurację SANDBOX. Zasiano prywatne sentinele w private_allegro, storage/account.enc, cache/state.json, logs/technical.jsonl, config.private.php, .env i nieprzejrzanym pliku modułu. Publiczny moduł zawiera dokładnie dziewięć dozwolonych plików; sentinele nie trafiły do publish. Brak private_allegro/vault/.env/tokenów/logów. Test public-artifact po syntetycznym prerenderze: PASS.

Kontrolowany negatywny przypadek zmienia loader na enabled=true: pełny build odrzuca źródło z błędem Unreviewed Allegro. Następnie przywrócono wyłącznie fixture i ponowny pełny build przeszedł. To oczekiwana asercja fail-closed, nie nierozwiązany FAIL.

Lokalny fingerprint surowego pełnego artefaktu fixture (146 plików, przed prerenderem): `2ef685b82bc120b7542dceccfda026f12c688f95549bf8206e8c083f59e63f28`. Algorytm: SHA-256 konkatenacji sorted relative-path + NUL + raw bytes. Jest dowodem tego syntetycznego buildu; projekt nie ma zamrożonego pinu całej strony i ten hash nie jest pinem produkcyjnym.

## H. Dedykowany build

Dedykowany release ponownie zbudowano i zweryfikowano lokalnie. Runtime nadal zawiera dokładnie 11 plików, manifest jest poza katalogiem runtime. Nie przenosi produktów, uploads, OLX, prywatnej konfiguracji, tokenów ani nowego kontraktu JSON do public_html.

Lokalny manifest opisuje HEAD bazy i nieudostępnione zmiany robocze poprzez source hashes — nie jest dowodem przyszłego commitu. Po osobno zatwierdzonym commicie wymagany jest świeży manifest rzeczywistego RELEASE_COMMIT_SHA i qualify-only.

## I. Porównanie payloadów

**9/9 publicznych plików admin/allegro identyczne bajtowo** między pełnym i dedykowanym buildem. **11/11 runtime zgodne po normalizacji LF**. Jedyna kontrolowana różnica w pełnym artefakcie dotyczy CRLF dwóch współdzielonych plików admin/.htaccess i admin/index.php, które pełny builder kopiuje bez zmiany; dedykowany builder zapisuje LF. Ich funkcjonalna treść i aktualny blok OLX są identyczne. Kod modułu normalizowany jest przez obie ścieżki.

Config payload SHA-256 w obu ścieżkach: `d1042b53f70713be38edde0158ee656c619a624a2034cbea26f44f138e00bfd9`. Runtime fingerprint 11 payloadów w kolejności ALLOWLIST, target+NUL+bytes: `ae5e1b2ec456cb8572f48b85491ff483b186f7367b65081396e3aff0abf1aeba`.

Symulacja przyszłego pełnego deploya: lokalny stan serwera utworzono z dedykowanego OFF, następnie nałożono pełne publish; wszystkie 11 ścieżek zachowane z bezpieczną treścią, callback istnieje, OLX identyczny. Dla synchronizacji stanowej wszystkie publiczne ścieżki modułu i katalog są obecne po obu stronach, więc nie powstaje brak ścieżki powodujący delete. To test plików i analiza algorytmu action, bez emulowania FTPS lub Apache. Rollback rozpoczynający się od backupu istniejącego OFF przywraca OFF; nie rozszerzamy gwarancji na nieznane, wcześniej aktywowalne backupy ani ręczne zmiany serwera.

## J. OAuth OFF przy ENV=1

**PASS.** Wspólny test realnego PHP HTTP/session/CSRF uruchomiono na plikach pełnego publish, nie tylko na źródle lub mocku loadera. Syntetyczna kompletna konfiguracja i ENV=1 nie udostępniają Connect; POST connect/finish/check zwracają 400 bez Location. Callback pretty i callback.php, także ze state/code, zwraca 400, nie tworzy sesji/tokenów i nie ujawnia code. UI wskazuje OAuth Wyłączony oraz logs_safe niepotwierdzone. Nie utworzono account.enc ani pending/state vault.

Osobny probe ustawia w pamięci enabled=true i logs_safe=true w poprawnej konfiguracji: walidacja OAuth nadal rzuca disabled. To dowód bezwarunkowej bramki, niezależny od flag interfejsu. Wariant z pustą/brakującą konfiguracją również bezpiecznie odmawia.

curl_exec wyłączony i allow_url_fopen=0 na lokalnym serwerze PHP; requesty Python/Node dotyczą wyłącznie 127.0.0.1. Nie wykonano prawdziwego HTTP Allegro ani wymiany tokenu.

## K. /sale policy

**PASS.** http.php nie zmieniono, jego reviewed hash pozostaje zamrożony. Probe na runtime sprawdza PROD/SANDBOX, GET/POST/PUT/PATCH/DELETE oraz /sale/offers, /sale/product-offers, /sale/offer-publication-commands: wszystkie 30 prób kończy się policy przed transportem. Licznik wywołań wstrzykniętego transportu = 0. Nie są to rzeczywiste requesty /sale. Nadal dopuszczone technicznie są wyłącznie token POST i /me GET; profil OFF blokuje ich wykonanie przez walidację konfiguracji.

## L. Frozen hashes / pins

Treść runtime przejrzano i porównano przed sprawdzeniem hashy. Nie aktualizowano pinów w celu przepuszczenia zmiany runtime.

| Wartość | OLD B.1.3 | NEW B.1.5 |
|---|---|---|
| Module reviewed hash | 770c1e9dd135c60f5c1ee27dace6660669b2ffb1558d4713f9cd823b3aa8c44c | identyczny |
| 11-source reviewed hash | 3dfc289f54a50687ac5f65f692ef3ac108f6675f13569f0e9c55329b42b47a44 | identyczny |
| Źródłowy config LF | 7347656d2cbb9c1fb796aa16bbc25d074f98fb698f6b08b9b0016da48326f037 | identyczny |
| Dedykowany config OFF | d1042b53f70713be38edde0158ee656c619a624a2034cbea26f44f138e00bfd9 | identyczny |
| Pełny config | 7347656d2cbb9c1fb796aa16bbc25d074f98fb698f6b08b9b0016da48326f037 | d1042b53f70713be38edde0158ee656c619a624a2034cbea26f44f138e00bfd9 |
| Profil | B.1.1-OAUTH-OFF-v1 | identyczny |
| Wspólny kontrakt JSON | brak | dc9ede6d1ded4032c30dcb5bef414a18620ce5e93d9edfa0d8932682b0694619 |

Pełny config OLD oznacza LF-normalizowane źródło kopiowane przez dawny builder. Rzeczywiste raw bytes przy CRLF mogą mieć inny hash; nowy wynik jest jawnie LF.

Preimages współdzielone pozostają bez zmian, baza to aktualny main:

- admin/.htaccess LF `a523ceb5a93d733a222358e4eacc334c777ca1e5554b6bdaea98d0ccc415805d`, CRLF `2be2a73b50c907c4af9da883713417c4372fd4d9ebe848684d4087d26221d8a3`.
- admin/index.php LF `9ce29db773d3ae7cb4a961504acd94f1607634a89e4cfb0e9a27fba4619769e5`, CRLF `9a513c6ceabe66e5db7a0278523de037a3cfc63412aef58d871b7c871bed7f1e`.

Fingerprint starego 29-source snapshotu B.1.4 df8378…9fe72 nie opisuje nowego zestawu 34. Nowy manifest ścieżek i raw source hashes znajduje się w ignorowanym .local-cache/allegro-b15-release-set.json; nie jest częścią runtime ani przyszłym commit SHA. Hash samego raportu wyliczany jest po jego końcowym zapisie, bez samoodwołania w treści.

## M. Release set

**OLD: 29 → NEW: 34.** Zachowano 29 ścieżek B.1.3. Dodano pięć pozycji: wspólny kontrakt JSON, nowy test pełnego OFF, istniejący śledzony test catalog-performance z konieczną adaptacją bramki workflow, historyczny raport B.1.4 oraz raport B.1.5. To 34 źródła, nadal tylko 11 plików dedykowanego runtime.

Dokładna allowlista przyszłego stagingu, względem root nowego kandydata:

```text
hosting/getspace/admin/allegro/.htaccess
hosting/getspace/admin/allegro/config.php
hosting/getspace/admin/allegro/storage.php
hosting/getspace/admin/allegro/http.php
hosting/getspace/admin/allegro/oauth.php
hosting/getspace/admin/allegro/lib.php
hosting/getspace/admin/allegro/bridge.js
hosting/getspace/admin/allegro/callback.php
hosting/getspace/admin/allegro/index.php
hosting/getspace/admin/.htaccess
hosting/getspace/admin/index.php
.gitignore
.github/workflows/deploy-getspace.yml
.github/workflows/deploy-allegro-infra-getspace.yml
scripts/build-getspace.mjs
scripts/getspace-artifact-privacy.mjs
scripts/allegro-infra-release.py
scripts/allegro-infra-smoke.py
tests/allegro-oauth-tests.php
tests/allegro-http-tests.mjs
tests/allegro-artifact-tests.mjs
tests/allegro-infra-release-tests.py
hosting/getspace/README.md
documents/allegro-etap-b.md
reports/allegro-etap-b.md
reports/allegro-etap-b1.md
reports/allegro-etap-b11-izolowany-release.md
reports/allegro-etap-b12-release-bootstrap.md
reports/allegro-etap-b13-main-reconciliation.md
scripts/allegro-off-profile.json
tests/allegro-full-build-off-tests.py
tests/catalog-performance-tests.mjs
reports/allegro-etap-b14-github-preflight.md
reports/allegro-etap-b15-full-deploy-off-policy.md
```

Poza stagingiem: całe .local-cache, publish, fixture, syntetyczny vendor, dane/katalogi produkcyjne, produkty i uploads, prywatne konfiguracje, klucze, tokeny/cookies, logi, backupy, manifesty, niepowiązane raporty i wszystkie ścieżki spoza tej listy. Brak git add . / git add -A. Raport B.1.5 w oryginale i kandydacie ma tę samą treść.

## N. Clean reconstruction

**PASS.** Utworzono `C:/Users/Albert/Documents/Strona www/github-deploy/.local-cache/allegro-b15-clean` z historii aktualnego main, checkout dokładnie df4c32cc77f2500a5ba238d892803f9afac1f7bd, i nałożono wyłącznie nowy zestaw 34 ścieżek. Przed testami brak .local-cache, publish, vendor, prywatnych configów/tokenów i zewnętrznych zależności aplikacji. Portable PHP wskazano jako narzędzie testowe poza checkoutem.

W obu kopiach wykonano 18 poleceń zestawów testowych (w tym release suite 18 testów) oraz dodatkowo shop-frontend enabled. Wszystkie zakończyły się kodem 0. Powtórzono lint 123 PHP, pięć JS syntax, cztery Python AST, trzy YAML i 36 bash -n, git diff --check. Brak FAIL. Dwa niezależne pełne artefakty (146 plików każdy) są bajtowo identyczne; dwa dedykowane runtime 11/11 są bajtowo identyczne. Różnice full/dedicated wyłącznie CRLF dwóch wspólnych plików, opisane w I.

Końcową treść raportu skopiowano do obu checkoutów po testach; żaden plik wykonywalny ani dokumentacja używana przez testy nie zmieniły się po kwalifikacji. Zbiór modified/untracked każdego checkoutu odpowiada dokładnie 34 ścieżkom, index pusty. Dowody: .local-cache/allegro-b15-candidate-results.json, allegro-b15-clean-results.json, allegro-b15-static-evidence.json i allegro-b15-clean-evidence.json w oryginalnym repo. Cache i wygenerowane artefakty nie są częścią release.

## O. Testy Allegro

Kandydat B.1.5: 197 asercji allegro-oauth PASS; allegro-http PASS (login/CSRF/Strict bridge, współbieżny refresh, atomowy vault, izolacja zapisu produktu); allegro-artifact PASS; allegro-infra-release **18/18 PASS**. Dodatkowy pełny test OFF: PASS, w tym jeden współdzielony test HTTP runtime wywołany na pełnym artefakcie, równość payloadów, ENV=1, /sale policy, prywatność, fail-closed i symulacja nadpisania serwera.

PHP lint: źródła hosting/getspace 57, pełny payload 58 (w tym syntetyczny placeholder autoload), dedykowany payload 8 — razem **123 PASS**. JS syntax: pięć plików PASS. Python AST: cztery pliki PASS. YAML: trzy workflow PASS; bash -n wszystkich bloków run: Allegro 12, pełny Getspace 21, OLX 3 — **36 PASS**. git diff --check PASS. Brak nierozwiązanych FAIL.

## P. Regresja OLX

**6/6 PASS:** olx-oauth-tests.php, olx-http-tests.mjs, olx-identity-verification-tests.mjs, olx-preparation-tests.php, olx-publication-tests.php, olx-publication-http-tests.mjs. Syntetyczny transport i lokalne fixture; brak operacji produkcyjnych. Kontrola zgodności plików OLX z main w release tests PASS; pełny artefakt zachowuje ich bajty.

## Q. Pozostałe regresje

PASS: product-id-migration, admin-figure-entry, catalog-presentation, prerender-privacy, public-products-snapshot. Bezpośrednio zależne od pełnego buildu: catalog-performance PASS, shop-frontend disabled i enabled PASS oraz public-artifact po prerenderze syntetycznych ośmiu rekordów PASS. Pełny test OFF jest dodatkową regresją rzeczywistego build-getspace. Nie uruchamiano workflow jako całości: zawiera świadomie niedopuszczone kroki produkcyjne.

## R. PHP8.3 / Node22

PHP 8.3 — **NIEZWERYFIKOWANE**. Node 22 — **NIEZWERYFIKOWANE**. Dostępne lokalnie PHP 8.5.11 z curl/sodium, Node 24.22.1, Python 3.12.10, PyYAML 6.0.3. Nie instalowano nowych runtime. Workflow qualify-only nadal wymaga rzeczywistego PHP 8.3 i Node 22; nie uruchomiono go. Zaktualizowane testy dojdą do tej osobnej kwalifikacji po bezpiecznym bootstrapie.

## S. Pozostałe blockery

**B14-GH-01 pozostaje otwarty**: brak potwierdzonych szczegółów branch protection/required checks, expected app, strict i bezkonfliktowej metody publikacji przy czasowym disable pełnego deploya. Nie zmieniano settings ani nie reinterpretowano wcześniejszego 401.

**B14-FULL-01 zamknięty lokalnie** po PASS czystej rekonstrukcji. Brak Environment i potwierdzenia pięciu secrets stwierdzony w B.1.4, brak CI docelowych runtime, kontrole Getspace i G16/logowanie callbacku pozostają bramkami przyszłego deploya/OAuth. W B.1.5 nie odczytywano ponownie ustawień Environment ani secrets; nie aktualizujemy tamtej migawki GitHub. Nie stanowią dodatkowych przeszkód dla samego lokalnego qualify-only po bezpiecznym bootstrapie. Osobna zgoda właściciela na commit/push i później Actions jest nadal wymagana, nawet po zamknięciu technicznego blockera.

Przyszłe enable pełnego workflow nadal musi być świadomą decyzją właściciela po bootstrapie i weryfikacji main/runów. Naprawa oznacza, że nie trzeba pozostawiać go bezterminowo disabled z powodu aktywowalnego loadera; nie jest zgodą na pełny deploy strony ani produkcyjne kroki OLX tego workflow.

## T. Operacje zewnętrzne

| Operacja | Wykonano |
|---|---|
| read-only fetch GitHub | TAK |
| odczyt źródła upstream action / tagu | TAK |
| git add w oryginalnym lub izolowanym repo | NIE |
| commit | NIE |
| push | NIE |
| PR / merge | NIE |
| Actions | NIE |
| workflow disable/enable | NIE |
| GitHub settings / Environment / secrets mutation | NIE |
| FTPS | NIE |
| Getspace | NIE |
| deploy | NIE |
| produkcyjny smoke | NIE |
| OAuth | NIE |
| /me | NIE — wyłącznie testy syntetycznej polityki |
| /sale | NIE — wyłącznie lokalne próby odrzucone przed transportem |
| OLX produkcyjny zmieniony | NIE |

## U. DECYZJA

### Czy B14-FULL-01 został zamknięty?

**TAK — w lokalnie zakwalifikowanym kandydacie B.1.5.** Zmiana nie została opublikowana ani wdrożona.

### Czy przyszły pełny deploy może nadal osłabić profil OAuth OFF?

**NIE — dla tego niezmienionego, przejrzanego release i standardowego workflow.** Oba buildy wymuszają ten sam loader OFF, również przy ENV=1; błędna transformacja zatrzymuje build przed transferem. Nie jest to gwarancja wobec późniejszych nieprzejrzanych zmian kodu, ręcznego ominięcia bramek lub przywrócenia obcego historycznego backupu.

### Czy aktualny release jest ponownie zakwalifikowany?

**TAK — lokalnie, nowy zestaw 34 plików i czysta rekonstrukcja PASS.** Docelowy CI PHP8.3/Node22 pozostaje niewykonany.

### Czy B14-GH-01 pozostaje jedynym blockerem bootstrapu?

**TAK — jedynym otwartym technicznym blockerem opisanej procedury bootstrapu.** Oddzielna zgoda właściciela na operacje nadal wymagana. Environment/secrets, Getspace, docelowe CI oraz G16 pozostają bramkami późniejszego deploya/OAuth, nie są zamykane tym etapem.

### Czy można commit/push?

**NIE — bez osobnej zgody właściciela.**

### Czy można deployować?

**NIE.**

### Czy można wykonać OAuth?

**NIE.**

Po końcowym raporcie prace zatrzymano.
