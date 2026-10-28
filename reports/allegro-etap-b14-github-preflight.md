# Allegro B.1.4 — GitHub preflight

Data: 28.10.2026. Wyłącznie audyt odczytowy i nowy raport. **Wariant B: BLOCKER.** Nie wykonano bootstrapu ani żadnej operacji zapisującej na GitHub.

Przeczytano cały raport B.1.3 i sprawdzono aktualny kod kandydata oraz GitHub. Wyniki testów B.1.3 przywołane niżej są historycznym dowodem kwalifikacji niezmienionego snapshotu, a nie nowym uruchomieniem testów lub Actions. Stan GitHub jest migawką; przed przyszłą operacją wymaga ponownego odczytu.

## A. Aktualny main

Default branch: `main`. Git fetch origin main: wykonany. Lokalny origin/main, kandydat HEAD oraz GitHub wskazują `df4c32cc77f2500a5ba238d892803f9afac1f7bd` — dokładnie bazę B.1.3. **PASS: baza nie zmieniła się, reconciliation nie jest potrzebna na tę chwilę.** Końcowy odczyt API: 2026-10-28T07:55:37Z.

Repozytorium: [home-garden-outlet](https://github.com/gmgardenoutlet-lang/home-garden-outlet). Kandydat jest izolowanym lokalnym klonem z detached HEAD; nie istnieje jeszcze commit release. Jego origin wskazuje lokalny oryginalny klon, nie GitHub. Przed przyszłą publikacją trzeba jawnie przygotować branch i zweryfikować docelowy remote; obecnie ich nie zmieniano.

## B. Branch protection / rulesets

| Kontrola | Wynik | Dowód / ograniczenie |
|---|---|---|
| Pole protected gałęzi main | PASS: `false` | GET branches/main: 200 |
| Rulesets repozytorium wraz z parents | PASS: lista pusta | GET rulesets?includes_parents=true: 200, [] |
| Efektywne rules dla main | PASS: lista pusta | GET rules/branches/main: 200, [] |
| Klasyczne branch protection i szczegóły | NIEPOTWIERDZONE | GET branches/main/protection: 401 |
| Wymagany PR / approvals | NIEPOTWIERDZONE | Brak autoryzowanego odczytu protection |
| CODEOWNERS: plik | PASS: brak | git ls-tree trzech obsługiwanych lokalizacji na aktualnym main |
| Wymagany review CODEOWNERS | NIEPOTWIERDZONE | Brak pliku nie jest odczytem polityki |
| Dismiss stale reviews | NIEPOTWIERDZONE | Protection: 401 |
| Merge queue | NIEPOTWIERDZONE | Brak szczegółów polityki |
| Signed commits | NIEPOTWIERDZONE | Brak szczegółów polityki |
| Linear history | NIEPOTWIERDZONE | Brak szczegółów polityki |
| Push restrictions | NIEPOTWIERDZONE | Brak szczegółów polityki |
| Administrator bypass | NIEPOTWIERDZONE | Brak szczegółów polityki |
| Bezpieczna metoda publikacji | BLOCKER | Nie można wykluczyć konfliktu wymaganych checks z disable |

Pole protected=false i puste listy są konkretnymi obserwacjami. Nie zastępują brakującego odczytu szczegółów wymaganego tym audytem. 401 nie oznacza braku ochrony. Connector zwrócił permissions admin/maintain/push/pull=true dla konta, lecz nie udostępniał endpointu settings; uprawnienie konta nie dowodzi dostępności tego odczytu przez connector. Publiczny REST nie otrzymywał nagłówka Authorization. Nie poszukiwano lokalnych tokenów.

Właściciel powinien dostarczyć aktualny odczyt Settings → Branches oraz Rules → Rulesets dla main albo autoryzowany GET ochrony. Potrzebne są wszystkie pola z tabeli i sekcji C. Dokumentacja wymaga Administration(read) dla szczegółów ochrony: [Get branch protection](https://docs.github.com/en/rest/branches/branch-protection#get-branch-protection).

## C. Required checks

Exact names, expected app / app_id, strict up-to-date: **NIEPOTWIERDZONE** (protection endpoint 401). Nie ustalono, czy pełny deploy lub jego job jest wymaganym checkiem. Nie wolno przyjąć, że można go wyłączyć i nadal opublikować zgodnie z zasadami main. To **B14-GH-01**, blocker bootstrapu do odczytu rzeczywistej polityki. Nie zmieniać checks, nie obchodzić ich administratorem ani znacznikiem [skip ci].

## D. Workflow inventory

Pliki na main sprawdzono dla dokładnego SHA z A; lista Actions zawiera również historyczne rejestracje.

| Workflow | ID | Plik na main | Trigger na main | Stan API |
|---|---:|---|---|---|
| Deploy to Getspace | 295905982 | .github/workflows/deploy-getspace.yml | push branches:[main], workflow_dispatch | active |
| Deploy OLX OAuth code only | 397445392 | .github/workflows/deploy-olx-oauth.yml | wyłącznie workflow_dispatch; etapy oauth/preparation/publication/presentation | active |
| Allegro infrastructure ONLY (OAuth OFF) | jeszcze brak | brak; lokalny deploy-allegro-infra-getspace.yml | lokalnie tylko workflow_dispatch | niezarejestrowany na main |
| Invoice document PHP 8.3 tests | 351022303 | brak | nieustalany dla innych refs | active historyczny rekord |
| Invoice production fix PHP 8.3 tests | 351148292 | brak | nieustalany dla innych refs | active historyczny rekord |
| Paynow PHP 8.3 tests | 350988908 | brak | nieustalany dla innych refs | active historyczny rekord |

Main zawiera dokładnie dwa pliki workflow. Ich blob SHA: Getspace `a573e8e6b659699daddc7d42807259ff84d61683`, OLX `5f5452cc5c32b58f2bf9a06af2cf86277b838bc0`. Zgodność z B.1.3: PASS. Nie pojawił się nowy automatyczny workflow produkcyjny na default branch. Historyczne rekordy Actions nie są dodatkowymi plikami na main.

## E. Deploy to Getspace

ID **295905982**, ścieżka `.github/workflows/deploy-getspace.yml`, stan **active**. Push na main może uruchomić pełny deploy; workflow_dispatch również jest dostępny. Nie testowano disable/enable.

Końcowy odczyt repo-wide Actions dla każdego statusu `queued`, `waiting`, `pending`, `in_progress`, `requested`: HTTP 200, total_count=0. **PASS: zero aktywnych lub oczekujących runów we wszystkich workflow w chwili odczytu 07:55:37–07:55:39 UTC.** Nie jest to gwarancja przyszłego braku kolejki. Nie anulowano żadnego runu.

Odczytano 10 najnowszych runów pełnego workflow, wszystkie completed (API total_count=240; nie audytowano całej historii):

| Run | Utworzony UTC | Event | Conclusion | Head SHA |
|---|---|---|---|---|
| [39670987789](https://github.com/gmgardenoutlet-lang/home-garden-outlet/actions/runs/39670987789) | 2026-10-21T20:31:05Z | push | success | `849121bf20ec012f89c293b17e690ddf91c28b36` |
| [39664034479](https://github.com/gmgardenoutlet-lang/home-garden-outlet/actions/runs/39664034479) | 2026-10-21T19:42:53Z | push | success | `bd2292a8cc6cf55f97d7072865a681dafed9cf00` |
| [39662123280](https://github.com/gmgardenoutlet-lang/home-garden-outlet/actions/runs/39662123280) | 2026-10-21T19:29:44Z | push | failure | `4fb9bbdabf1504e3ece064fe282029eb15905764` |
| [39661973129](https://github.com/gmgardenoutlet-lang/home-garden-outlet/actions/runs/39661973129) | 2026-10-21T19:28:42Z | push | failure | `f8534ca55513540f46a21690e69d9b86c19757bc` |
| [39466463315](https://github.com/gmgardenoutlet-lang/home-garden-outlet/actions/runs/39466463315) | 2026-10-20T17:29:04Z | push | success | `34380b8aef5a26531e35d627cca903ae067a756a` |
| [39464415803](https://github.com/gmgardenoutlet-lang/home-garden-outlet/actions/runs/39464415803) | 2026-10-20T17:15:03Z | push | failure | `0c5a21dcdd3a9b161c4d8bc7d816d5610f10ea2f` |
| [39464137829](https://github.com/gmgardenoutlet-lang/home-garden-outlet/actions/runs/39464137829) | 2026-10-20T17:13:10Z | push | failure | `bc259b93abf4e8bdeffe8e9b7ef55ef4563c1505` |
| [39463892360](https://github.com/gmgardenoutlet-lang/home-garden-outlet/actions/runs/39463892360) | 2026-10-20T17:11:30Z | push | failure | `41caab76f212bc6198d2e2b8dcfbdaa14eac2502` |
| [39463631340](https://github.com/gmgardenoutlet-lang/home-garden-outlet/actions/runs/39463631340) | 2026-10-20T17:09:42Z | push | failure | `165590faba0f4bd28e68356a3e1419b0b225a02f` |
| [38643591327](https://github.com/gmgardenoutlet-lang/home-garden-outlet/actions/runs/38643591327) | 2026-10-14T21:52:43Z | push | success | `da0e001ce2ed59feffa9497fb4f0c054720a8793` |

Odczyt stanu jest dostępny. Dokumentacja przewiduje disable/enable z uprawnieniem Actions(write), ale możliwość zapisu nie była testowana. Disabled nie zastępuje kontroli już utworzonych runów: wymagane są odczyty przed i po przyszłym disable. [REST workflows](https://docs.github.com/en/rest/actions/workflows), [Disable and enable workflows](https://docs.github.com/en/actions/how-tos/manage-workflow-runs/disable-and-enable-workflows).

## F. GitHub Environment

`allegro-infra-production`: **NIE ISTNIEJE według aktualnego odczytu**. GET konkretnego environment: 404; dodatkowo LIST environments: 200, total_count=0, environments=[] (2026-10-28T07:52:30.547351Z). Wniosek nie opiera się wyłącznie na 404. [Environment API](https://docs.github.com/en/rest/deployments/environments#get-an-environment).

Required reviewers, ich liczba, prevent self-review, admin bypass, branch/tag restrictions oraz wait timer: brak skonfigurowanego Environment, nie ma ustawień do potwierdzenia. Użyteczność jako chronione production Environment dla main: **NIE**. Nie tworzyć go przez uruchomienie workflow.

Ręczna checklista właściciela przed osobno zatwierdzonym deployem:

1. Jawnie utworzyć dokładnie `allegro-infra-production` dopiero po osobnej autoryzacji tej zmiany.
2. Ustawić co najmniej jednego uprawnionego required reviewer; zapisać jego tożsamość/rolę i liczbę reviewerów. Zweryfikować dostępność osoby innej niż inicjator.
3. Włączyć prevent self-review i wyłączyć możliwość obejścia przez administratora (`can_admins_bypass=false`).
4. Włączyć ograniczenia deployment branches/tags i dopuścić wyłącznie uzgodniony main; nie dopuszczać dowolnego ref/tag. Potwierdzić zgodność ustawień z guardem w release.py.
5. Jawnie ustalić wait timer (także jeśli 0) i zapisać decyzję. Sam timer nie zastępuje review.
6. Dodać pięć wymaganych environment secrets z G, bez ujawniania ich wartości; osobno sprawdzić minimalny zakres konta FTPS i katalog docelowy.
7. Odczytać konfigurację ponownie i potwierdzić możliwość deploya z main, reviewers, self-review, bypass, branch policy oraz obecność nazw secrets. Nie uruchamiać deploya jako testu ustawień.

Brak Environment blokuje mode=deploy, ale sam nie blokuje qualify-only, którego joby go nie używają.

## G. Environment secrets

GET nazw environment secrets zwrócił 401. **Wartości nie były odczytywane.** Obecność wszystkich nazw poniżej: NIEPOTWIERDZONE; przy nieistniejącym Environment nie można uznać ich za gotowe jako environment secrets.

| Wymagana nazwa | Obecność |
|---|---|
| ALLEGRO_FTP_SERVER | NIEPOTWIERDZONE |
| ALLEGRO_FTP_USERNAME | NIEPOTWIERDZONE |
| ALLEGRO_FTP_PASSWORD | NIEPOTWIERDZONE |
| ALLEGRO_FTP_PUBLIC_DIR | NIEPOTWIERDZONE |
| ALLEGRO_SMOKE_ADMIN_COOKIE | NIEPOTWIERDZONE |

Nie wnioskowano o repository/organization secrets ani ich wartościach. Nie kompletować konfiguracji przez przypadkowy fallback secrets. Brak potwierdzenia blokuje deploy, nie qualify-only.

## H. Ocena wariantu B

**BLOCKER na obecnym poziomie potwierdzenia konfiguracji.** Mechanizm czasowego disable jest udokumentowany i workflow jest active, ale nie wolno teraz rekomendować wykonania bootstrapu:

- **B14-GH-01:** brak autoryzowanego potwierdzenia required checks i zasad publikacji; nie można wykluczyć zależności wymaganej przez main od wyłączanego workflow.
- **B14-FULL-01:** niezakończona polityka przyszłych pełnych wdrożeń i ponownego enable (I).

Nie stwierdzono aktywnego deploya ani zmiany bazy. Nie uznajemy 401 za dowód braku ochrony. Brak Environment jest dodatkowym blockerem deploya, a nie przyczyną niedopuszczenia samych lokalnych jobów qualify-only.

## I. Problem ponownego enable

**B14-FULL-01 — osobny blocker polityki przyszłych pełnych wdrożeń.** Aktualny kandydat pełnego workflow wywołuje `node scripts/build-getspace.mjs` i publikuje `publish/` przez FTP deploy action. Builder kopiuje hosting/getspace/admin z filtrem prywatności. Publiczny `admin/allegro/config.php` przechodzi ten filtr i pochodzi ze źródła: enabled zależy od ALLEGRO_OAUTH_ENABLED, logs_safe od ALLEGRO_LOGS_SAFE. Źródło pozwala aktywować OAuth przy prawidłowych flagach i konfiguracji.

Dedykowany builder `scripts/allegro-infra-release.py` jako jedyny zamienia loader na twarde enabled=false / logs_safe=false i bezwarunkową odmowę OAuth, profil `B.1.1-OAUTH-OFF-v1`. Pełny builder nie wywołuje tej transformacji. Zatem następny pełny deploy po enable może zastąpić wdrożony loader OFF źródłowym loaderem aktywowalnym. Nie twierdzimy, że na Getspace istnieją flagi aktywujące — tego nie odczytywano. Dowiedziony problem to obejście trwałości profilu OFF przez inną ścieżkę builda.

Przed bootstrapem właściciel musi zatwierdzić konkretną politykę przyszłego pełnego wdrażania i przywracania workflow. Bezpieczne rozwiązanie wymaga osobnego zakresu zmian, np. zapewnienia tego samego wymuszonego OFF w pełnym buildzie, albo jawnie odseparowanego zarządzania Allegro podczas pełnych wdrożeń. Każda zmiana kandydata wymaga ponownej kwalifikacji i nowego snapshotu. B.1.4 nie zmienia kodu.

Nie planować wiecznego disabled jako ukrytej naprawy. Przed przyszłym disable ustalić właściciela okna, termin przeglądu, warunki enable i reakcję, jeśli nie zostaną spełnione. Samo enable nie jest równoznaczne z uruchomieniem runu, lecz otwiera drogę kolejnemu push/dispatch.

## J. Release set 29

**PASS: dokładnie 29 plików.** Każdy istnieje; zbiór git diff oraz untracked kandydata jest identyczny z listą B.1.3. Porównano SHA-256 wszystkich 28 plików zapisanych w dowodzie reconciliation oraz oddzielnie niezmieniony raport B.1.3 (29. plik). Końcowa kontrola potwierdziła niezmienność 29 plików i wcześniejszych plików oryginalnego worktree. Index kandydata nie zawiera stagingu.

11 runtime + 7 CI/release + 4 testy + 7 dokumentów = 29 źródeł. Runtime pozostaje dokładnie 11 plików, nie 29. Żadna docelowa ścieżka Git nie zawiera .local-cache. Fizyczna lokalizacja izolowanego klonu pod ignorowanym cache nie jest ścieżką release. Brak prywatnych danych, plików uploads, produktów, raportów innych zadań i zmian plików OLX w zestawie. Aktualny blok OLX w admin/index.php zachowany; jedyna zmiana tego pliku to link Allegro.

Fingerprint 29 bieżących plików źródłowych: `df83788fdc21281278ca92fa05cfb152e4181a5fe320eb045d1221d5c6b9fe72` (lokalny manifest .local-cache/allegro-b14-release-source-manifest.json). To nie SHA commitu ani hash artefaktu. Zamrożony 11-source hash B.1.3: `3dfc289f54a50687ac5f65f692ef3ac108f6675f13569f0e9c55329b42b47a44`.

Dowód B.1.3 pozostaje aktualny dla identycznych plików: 197 OAuth assertions PASS, release tests 18 PASS, 6/6 OLX suites PASS, clean reconstruction PASS, dwa buildy z identycznym payloadem 11 plików. Nie przedstawiamy tego jako CI PHP 8.3/Node 22; lokalne wersje B.1.3 to PHP 8.5.11 i Node 24.22.1. Git przy skanowaniu zgłosił zbyt długą istniejącą nazwę JPEG w uploads; nie modyfikowano tego pliku, kontrola hashy wszystkich 29 jawnych ścieżek zakończyła się PASS.

## K. Plan stagingu

Źródło przyszłego commitu: `C:/Users/Albert/Documents/Strona www/github-deploy/.local-cache/allegro-b13-candidate`. Oryginalny brudny worktree jest wykluczony. Poniższe ścieżki są względne wobec root tego kandydata — dokładnie przyszła jawna allowlista stagingu; teraz niczego nie stage'owano.

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
```

Poza stagingiem MUSZĄ pozostać: całe .local-cache, publish, manifesty/backupy/journale/logi, konfiguracje prywatne, tokeny, cookies, credentials, uploads, produkty, wszelkie ścieżki spoza 29, raport B.1.4 oraz raporty innych zadań. Nie dodawać git add . ani git add -A. Nie kopiować raportu B.1.4 do zamrożonego kandydata w tym etapie.

## L. Proponowany commit

Proponowany tytuł: `Prepare Allegro OAuth-OFF infrastructure release on current main`.

Zwykły pojedynczy commit na rodzicu `df4c32cc77f2500a5ba238d892803f9afac1f7bd`, wyłącznie 29 ścieżek. Nie ma istniejącej serii commitów do squashowania. Metoda publikacji (PR albo dozwolony direct push) dopiero po potwierdzeniu zasad main; nie omijać wymaganej metody merge. Jeśli finalny merge generuje inny SHA, to on jest RELEASE_COMMIT_SHA i wymaga kwalifikacji oraz świeżego manifestu. Bez force push i bez przepisywania historii.

Commit SHA jeszcze nie istnieje. Przed utworzeniem trzeba zweryfikować staged paths/diff/blob oraz ewentualną normalizację LF przez Git; fingerprint lokalnych bajtów nie zastępuje kontroli treści przyszłego commitu. Zmiana semantyczna lub nowa baza oznacza STOP i ponowną kwalifikację. [skip ci] nie jest główną barierą. Plan nie stanowi zgody na commit/push.

## M. Runbook bootstrapu

**DRY RUN — niewykonany, obecnie zatrzymany przez B14-GH-01 i B14-FULL-01.** Wszystkie operacje zapisujące wymagają osobnego zatwierdzenia po zamknięciu blockerów.

| Krok | Warunek wejścia / przyszła czynność | Dowód PASS | STOP |
|---|---|---|---|
| A | Ponowny fetch i odczyt main | main nadal dokładnie baza B.1.3 | Nowy SHA: reconciliation, bez automatycznego przenoszenia |
| B | Kontrola izolowanego kandydata | 29 ścieżek, hashe, aktualny OLX, brak prywatnych danych | Dowolna nieuzgodniona różnica |
| C | Odczyt runów wszystkich produkcyjnych workflow i pięciu statusów | Zero queued/waiting/pending/in_progress/requested | Jakikolwiek aktywny lub oczekujący deploy; bez automatycznego cancel |
| D | Zamknięte blockery; potwierdzone Actions(write), zasady checks, plan enable, zgoda właściciela; disable tylko ID 295905982 | API potwierdza przyjęcie operacji | Brak zgody/uprawnień, konflikt checks, błąd API |
| E | Ponowny GET workflow i runów po disable | Dokładnie disabled_manually oraz nadal zero aktywnych/oczekujących | Inny stan, brak możliwości odczytu, istniejący run |
| F | Zweryfikowany lokalny branch i GitHub remote w kandydacie; jawny staging 29 ścieżek | Staged names równe liście K, przejrzany diff, brak sekretów | Oryginalny worktree, inne ścieżki lub różnica treści |
| G | Spełnione A–F, osobna zgoda na commit | Jeden commit o oczekiwanym rodzicu/treści; zapisany SHA | Baza/treść inna niż zatwierdzona |
| H | Pełny workflow nadal disabled; znana polityka PR/checks i zgoda na publikację | Publikacja zgodna z polityką, wymagane checks rzeczywiście PASS | Required check zależy od pełnego deploya, nieznana polityka, potrzeba bypass |
| I | Po zakończeniu wymaganej publikacji odczytać main | Zapisany RELEASE_COMMIT_SHA jest faktycznym main i ma zatwierdzoną treść | Inny main lub nieprzejrzany merge |
| J | Odczyt runów dla nowego SHA i całej kolejki | Brak runu pełnego deploya dla RELEASE_COMMIT_SHA, zero aktywnych, nadal disabled_manually | Run istnieje albo zmienił się stan workflow |
| K | Odczyt drzewa default branch i workflow API | Nowy workflow Allegro, właściwa treść, tylko dispatch | Brak pliku/rejestracji lub dodatkowy trigger |
| L | Osobna decyzja właściciela o qualify-only, zgodność main z RELEASE_COMMIT_SHA | Zatwierdzony ref/inputs i plan O; potem wyłącznie ręczny qualify | Brak zgody, drift SHA, mode inne niż qualify-only |
| M | Jawny przegląd przyszłego enable przez właściciela okna | Udokumentowana decyzja, termin i bezpieczna polityka pełnych buildów | Niezamknięty B14-FULL-01; bez automatycznego enable ani bezterminowego przemilczenia |

Statusy i main odczytywać ponownie bezpośrednio przed krytycznymi krokami; kontrolować również ręczne uruchomienia OLX w oknie. Nie wyłączać OLX w ramach tej procedury. Disable pełnego workflow nie rozwiązuje równoległych ręcznych operacji innego workflow; aktywność produkcyjna oznacza STOP.

## N. QUALIFY-only

Statyczny odczyt kandydata `.github/workflows/deploy-allegro-infra-getspace.yml`: default mode=qualify-only, jedyny trigger workflow_dispatch. Qualify wymusza PHP 8.3 z curl/sodium i Node 22 oraz sprawdza faktyczne wersje; Python 3.12, PyYAML 6.0.2. Joby qualify → build → verify nie mają production Environment ani odwołań do secrets. Nie wykonują CLI --execute.

Produkcja ma needs qualify/build/verify, Environment `allegro-infra-production` i warunek `inputs.mode == 'deploy' && inputs.confirmation == 'DEPLOY_ALLEGRO_INFRA_OAUTH_OFF'`. FTPS, backup, produkcyjny smoke i rollback są wewnątrz tego joba; przy qualify-only job jest pomijany. Nie ma FTPS, smoke produkcyjnego, prawdziwego OAuth, /me lub /sale w lokalnych jobach. Testy używają danych/transportów syntetycznych; nazwy OAuth i /me w testach nie oznaczają rzeczywistego wywołania Allegro.

Nie wywołuje workflow OLX ani narzędzia produkcyjnego OLX. Sześć zestawów OLX to testy regresji; nie zapisują zdalnych ofert ani produktów. Build/verify operują na workspace i lokalnych artefaktach. Pobieranie setup/actions/dependencies z internetu pozostaje zwykłą czynnością CI, nie dostępem do hostingu.

**PASS statyczny; wykonania na PHP 8.3/Node 22 jeszcze nie potwierdzono.**

## O. Plan pierwszego qualify-only

Dopiero po bezpiecznym bootstrapie i osobnej zgodzie: workflow `Allegro infrastructure ONLY (OAuth OFF)`, mode `qualify-only`, confirmation puste. Zarejestrować zatwierdzony konkretny RELEASE_COMMIT_SHA. Dispatch na ref `main` wyłącznie gdy main nadal wskazuje ten SHA; po utworzeniu runu sprawdzić jego head_sha. REST workflow_dispatch przyjmuje nazwę branch/tag, więc nie zakładać obsługi surowego SHA jako ref. [Workflow dispatch REST](https://docs.github.com/en/rest/actions/workflows#create-a-workflow-dispatch-event).

Oczekiwane dowody z tego jednego runu: PHP 8.3 PASS, Node 22 PASS, 197 OAuth assertions PASS, 18 release tests PASS (także testy zapakowanego release), 6/6 bieżących OLX suites PASS, build PASS, verify PASS; production job SKIPPED. Zachować URL/run ID, head_sha, logi bez sekretów, artefakt i manifest świeżego commitu z 11 plikami, source/payload hashes oraz profilem OFF. Nie używać starego lokalnego manifestu jako manifestu nowego commitu. Jakikolwiek błąd, produkcyjny job nieskipowany albo drift SHA: STOP, bez deploya i bez kolejnego automatycznego uruchomienia.

## P. Pozostałe blockery

| ID / zakres | Stan | Co zamyka |
|---|---|---|
| B14-GH-01: bootstrap | BLOCKER | Aktualny autoryzowany odczyt polityki main, exact checks/apps/strict i bezkonfliktowa metoda publikacji |
| B14-FULL-01: bootstrap / przyszłe pełne deploye | BLOCKER | Osobno zatwierdzona polityka i ewentualna poprawka zapewniająca trwałość OFF; po zmianie nowa kwalifikacja |
| Production Environment | BLOCKER deploya | Ręczna konfiguracja i odczyt dowodów z F |
| Pięć environment secrets | BLOCKER deploya | Potwierdzenie obecności nazw, bez ujawniania wartości |
| CI PHP 8.3/Node 22 | Jeszcze brak dowodu | Osobno zatwierdzony qualify-only po bootstrapie |
| Getspace / FTPS / callback / prywatny storage | Nadal niepotwierdzone zdalnie | Kontrole z raportów B.1/B.1.1 dopiero w osobno zatwierdzonym zakresie |
| Prawdziwy OAuth | Niedopuszczony | Osobny przyszły etap i zgoda; profil OFF pozostaje obowiązujący |

B12-BASE-01 pozostaje zamknięty dla obecnego SHA. Integralność B.1.3 nie jest blockerem. Nie zmieniono source tree, nie rozszerzono allowlisty.

## Q. Operacje zewnętrzne

| Operacja | Wykonano |
|---|---|
| fetch/read GitHub | TAK — fetch, connector i odczytowe GET public REST |
| git add | NIE |
| commit | NIE |
| push | NIE |
| PR | NIE |
| merge | NIE |
| workflow disable/enable | NIE |
| Actions run | NIE |
| GitHub settings mutation | NIE |
| environment mutation | NIE |
| secrets mutation / odczyt wartości | NIE |
| FTPS / Getspace | NIE |
| deploy | NIE |
| produkcyjny smoke | NIE |
| OAuth | NIE |
| /me | NIE |
| /sale | NIE |
| operacje OLX | NIE |

Lokalnie zapisano nowy raport i odczytowe dowody w ignorowanym .local-cache. Nie zmieniono zamrożonego kandydata ani wcześniejszych raportów. B.1.4 nie uruchamiał ponownie pełnych testów.

## R. DECYZJA

### Czy aktualny release nadal jest gotowy do commitu?

**TAK — jako niezmieniony, lokalnie zakwalifikowany zestaw 29 plików B.1.3.** To gotowość snapshotu, nie zgoda na wykonanie commitu lub publikacji; nie usuwa blockerów GitHub i pełnego builda.

### Czy wariant B można bezpiecznie wykonać na obecnej konfiguracji GitHub?

**NIE — BLOCKER B14-GH-01 i B14-FULL-01.**

### Czy można teraz dać właścicielowi do zatwierdzenia konkretną operację commit/push bootstrap?

**NIE.** Plan jest przygotowany, ale najpierw trzeba zamknąć wskazane blockery; nie prosić o akceptację operacji o niepotwierdzonych warunkach bezpieczeństwa.

### Czy można już uruchomić qualify-only?

**NIE — dopóki workflow nie znajdzie się bezpiecznie na default branch i właściciel nie wyrazi osobnej zgody.**

### Czy można deployować?

**NIE.**

### Czy można wykonać OAuth?

**NIE.**

Po zapisaniu raportu prace B.1.4 zatrzymano.
