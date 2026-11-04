# Allegro — wiele ofert: weryfikacja 2026-11-04

## Zakres i baza

Pobrano `origin/main`; HEAD i ostatni znany commit to `f678c31bab176e97fe56e591079041c1fcd1fe34`. Przejrzano historię ośmiu ostatnich commitów, strukturę `hosting/getspace/admin/allegro`, adapter statusów, synchronizację i istniejące testy. Brak repozytoryjnego AGENTS.md. Zmiany runtime ograniczają się do pięciu plików z instrukcji DirectAdmin. OLX, katalog publiczny, polityka transportu i konfiguracja OAuth pozostają bez zmian.

Środowisko lokalne: Windows, PHP 8.3.36 (sodium, curl, openssl, mbstring, zip, GD), Node 24.22.1 oraz Python z runtime Codex. Nie jest to dokładna macierz CI (Node 22/Python 3.12/Linux). Zainstalowano lokalnie zależność Composer przypiętą w composer.json; plik lock wygenerowany wyłącznie na potrzeby lokalnych testów nie wchodzi do commita ani paczki. Migawka publiczna: 206 produktów, pobrana tylko do odczytu i lokalnego buildu.

## PASS: zmieniony moduł i jego integracje

- `php tests/allegro-pilot-tests.php`: **350 asercji**, wyłącznie transport syntetyczny. Zachowane dotychczasowe przypadki kategorii, UUID, parametrów, GPSR, uploadu, szkicu, publikacji, 202/303, niepewnego wyniku i kontrolowanego odblokowania. Nowe przypadki: rzeczywisty stary format szyfrogramu, identyczność historycznego rekordu i tokenów, idempotencja, dwie oferty, timeout drugiej bez zmiany pierwszej, brak ponownego POST, odzyskanie drugiego offerId, publikacja tylko drugiej przez mock, kolizja offerId, niespójny productId i zachowanie historii po syntetycznym odłączeniu konta.
- `php tests/allegro-oauth-tests.php`: **276 asercji**, Device, sejf, rotacja, blokady, logi i atomowe odrzucenie zbyt dużego zapisu. Próg testu rozmiaru dostosowany do sejfu wielu ofert.
- `php tests/admin-marketplace-tests.php`: **50 asercji**, projekcja wielu statusów bez zapisu i bez API, bez ujawniania payloadów/operacji, brak odczytu historycznego pilota jako aktualnego rekordu po odblokowaniu.
- `php tests/admin-marketplace-sync-tests.php`: **66 asercji**, symulowane GET, rekordy publikacji niezmienione.
- `node tests/allegro-http-tests.mjs`: PASS, logowanie, CSRF, Device POST, PRG, odświeżenie współbieżne, atomowe odczyty, przygotowanie formularza i statusy ofert. Nowy przypadek wyboru drugiej zapisanej oferty nieobecnej w aktualnym katalogu: właściwy status, offerId i productId formularza, bez przycisku publikacji innego produktu i bez zmiany szyfrogramu. Rzeczywisty curl_exec wyłączony. Opcjonalna gałąź Playwright nie była uruchamiana.
- `node tests/admin-marketplace-http-tests.mjs`: PASS, filtry, statusy, nawigacja, sprzedaż/przywracanie produktu, sejfy niezmienione.
- `node tests/allegro-artifact-tests.mjs` i wariant `--artifact=publish`: PASS, allowlista i brak prywatnych danych.

## PASS: szersza regresja

Uruchomiono wszystkie samodzielne pliki testowe PHP i JS oraz trzy zestawy Python. Ostatecznie przeszło **38 uruchomień zestawów PHP/JS** (licząc osobno frontend enabled/disabled, bez podwójnego liczenia powtórzeń i wariantu artefaktu Allegro): powyższe zestawy oraz admin-figure-entry, catalog-presentation, codex-draft, diagnostic-events, google-business-diagnostics (`php -n`), google-business-payload (GD), location-stats, merchant-feed, merchant-local, merchant-live-sample, olx-oauth, olx-preparation, olx-publication, owner-decisions-migration, paynow-v3, product-id-migration, shop-frontend (oba tryby), shop-order, sitemap-lastmod, stats-browser-exclusion-http, traffic-chart-stats, catalog-performance, homepage-figure-exclusion, homepage-figure-price-filter, olx-http, olx-identity-verification, olx-publication-http, prerender-privacy, public-products-snapshot i public-artifact.

Local build: `scripts/build-getspace.mjs`, następnie `SITE_ROOT=publish node scripts/prerender-products.mjs`: PASS, 206 produktów. `public-artifact-tests.mjs`: PASS po wymaganym prerenderze. Odczytowa kontrola Merchant: 55 publicznych adresów figur i zdjęć, schema oraz ceny — PASS; symulacja merchant-live-sample: 52 gotowe, 3 pominięte z raportowanymi ograniczeniami danych. Bez modyfikacji produkcji.

`php -l` wszystkich **59 plików PHP** w hosting/getspace: PASS. `git diff --check`: PASS. Test cookie HTTP na Windows wypisał PASS; jego proces lokalnego serwera wymagał zakończenia po teście, ponieważ Windows pozostawiał otwarte potoki procesu potomnego.

## Istniejące niepowodzenia poza zakresem poprawki

Nie ma pełnego zielonego wyniku całego repozytorium:

1. `allegro-infra-release-tests.py`, `allegro-device-ready-release-tests.py` i `allegro-full-build-off-tests.py`: stary release builder zatrzymuje się z `Unrelated shared code change; review required`. Jego historyczna baza oraz hashe nie odpowiadają obecnemu main. Na czystym archiwum **f678c31** uruchomiono ponownie infra-release: ten sam błąd, 44 błędy konfiguracji przypadków. Wspólne pliki admin/index.php i admin/.htaccess nie zostały zmienione w tym zadaniu. Nie osłabiano tej kontroli ani nie autoryzowano starej ścieżki deploya dla nowego modułu. Pakiet ręczny korzysta z osobnego, lokalnego skryptu i nie wywołuje release buildera.
2. `allegro-device-ready-tests.php` to helper wymagający dwóch argumentów oraz konfiguracji przekształconego historycznego artefaktu. Bezpośrednie uruchomienie bez argumentów nie jest samodzielnym testem; jego właściwa ścieżka przez testy release jest zablokowana jak wyżej. Nie zgłaszamy go jako PASS.
3. `compact-listing-cards-tests.mjs` i `listing-state-notes-tests.mjs`: aktualna publiczna migawka nie zawiera wymaganych przez historyczne asercje fragmentów opisu wanny HAWES (`ubytek przy narożniku`, `rysy`). Pliki opisów, generator, frontend i katalog nie zmieniły się w tej poprawce.
4. `catalog-link-http-tests.mjs`: statyczne identyfikatory kart w repozytoryjnym dom.html różnią się od lokalnego artefaktu z aktualną migawką. Po wymaganym prerenderze sam artefakt przechodzi public-artifact-tests; test porównania z historycznym źródłem nadal nie przechodzi. Nie zmieniano publicznego katalogu, aby dopasować go do starego testu.

Pierwsze niepowodzenia związane z brakującymi lokalnymi rozszerzeniami, sposobem uruchomienia testu diagnostycznego, brakiem migawki i pominięciem prerenderowania skorygowano i uruchomiono ponownie. Nie są liczone jako końcowe błędy produktu.

## Dostarczenie

Pakiet ma dokładnie pięć plików runtime, SHA256, oryginały bazowe do porównania i rollback w postaci uwierzytelnionej przerwy technicznej. Nie zawiera danych produkcyjnych. Skrypt sprawdza CRC ZIP-a i zgodność każdego wpisu po odczycie. Nie wykonano publikacji, deploya ani FTPS. Jedyny zapis zewnętrzny przewidziany w zadaniu to jeden commit `[skip ci]` na main i git push.
