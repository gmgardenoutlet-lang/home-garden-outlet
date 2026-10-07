# Linkowanie katalogu — lokalna poprawka i decyzje o Figurach, 2026-10-06

## Aktualizacja po ostatecznej decyzji właściciela — 2026-10-07

Poniższa aktualizacja zastępuje wcześniejsze ustalenia o oczekiwaniu na zatwierdzenie
par URL. Dawny opis porównania zdjęć poniżej pozostaje wyłącznie historią decyzji.

- Zatwierdzone 301 (również wariant z końcowym slash):
  - /produkt/rzezba-ogrodowa-twarz-mala-dostepne-w-roznych-barwach → /sklep/figury-ogrodowe/produkt/figura-ogrodowa-twarz-czarna-artystyczne-wykonczenie
  - /produkt/rzezba-betonowa-do-ogrodu-dekoracyjna-glowa-120-cm → /sklep/figury-ogrodowe/produkt/figura-ogrodowa-twarz-kobiety-114-cm-czarna-z-miedzianym-motywem-winorosli
  - /produkt/rzezba-betonowa-do-ogrodu-z-siedziskiem-dekoracyjna-glowa-120-cm → /sklep/figury-ogrodowe/produkt/figura-ogrodowa-twarz-kobiety-z-zamknietymi-oczami-i-siedziskiem-114-cm-szaro-brazowa
- Cel każdego przekierowania: HTTP 200, brak dalszego przekierowania. Dane cen,
  wymiarów i dostępności pozostają bez zmian, mimo wcześniejszych rozbieżności.
- CAPTAIN …-2: 301, cel 200 i nadal sprzedany. Obie głowy 140 cm: 404 bez Location.
- Leżąca twarz: nadal 200, bez nowej karty sklepu ani przekierowania; poza Ogrodem
  i polecanymi. SMOKI: nadal outlet 200, poza Ogrodem/sklepem, dotychczasowa
  kwalifikacja do polecanych zachowana.
- Homepage: jedna dostępna, publiczna, polecana figura zostaje zarezerwowana,
  jeśli istnieje; pozostałe pięć miejsc preferuje polecane produkty outletowe.
  Gdy ich brakuje, istniejące uzupełnianie nadal zapewnia do sześciu kart.
  Nie wymusza się figury z featured=false, ukrytej, sprzedanej lub nieaktywnej.
  Aktywna wyszukiwarka i filtry nie stosują kwoty figury.
- Reguła działa w PHP, JS i generatorze. JS zachowuje sześć slugów wybranych
  przez PHP; obsługuje też shop URL w identyfikacji kart statycznych.
- Sitemap: 178 unikalnych adresów, zgodnych z widocznością bieżącej migawki,
  bez trzech przekierowanych duplikatów. Trzy cele występują po jednym razie.
  Nie przywrócono lastmod z mtime katalogu.
- Workflow zmieniono tylko w oczekiwaniach testu sitemapy, aby nie wymagał
  przekierowanych duplikatów. Wysyłka, ochrona danych, backup i rollback bez zmian.
- Migawka i artefakt: 168 rekordów; Dom 65 kart, Ogród 14, Figury 40.
  Test HTTP potwierdza identyczne listy źródło/artefakt/HTML serwerowy.
  W przeglądarce po JS: Dom 65, Ogród 14; homepage zgodna z wyborem PHP.
- PASS: build Getspace i prerender, testy homepage (kwota figury, brak duplikatów,
  utrzymanie SMOKÓW, zachowanie wyboru PHP, brak wymuszania niepolecanej figury),
  catalog-link-http, public-artifact, prerender-privacy, sitemap-lastmod,
  catalog-presentation, PHP lint, node --check script.js, git diff --check.
- Przeglądarka: 1440×900 i 390×844, wszystkie sześć zdjęć załadowane;
  wyszukiwanie SMOKI: jeden wynik outletowy; stara mała twarz: zero;
  filtr Dom: 65 bez figur online; brak błędów JS, brak overflow przy 390 i 320 px.

Przykładowy wybór PHP zgodny z JS, widoczny na zapisanych zrzutach:

| Karta | Cena z migawki | Adres |
| --- | --- | --- |
| Twarz kobiety z falowanymi włosami, szaro-brązowa | 600 zł, Zakup online | /sklep/figury-ogrodowe/produkt/figura-ogrodowa-twarz-kobiety-z-falowanymi-wlosami-szaro-brazowa |
| Łóżko dziecięce COSSAYE | 500 zł | /produkt/lozko-dzieciece-cossaye-domek |
| Sofa ORSA | 1800 zł | /produkt/sofa-rozkladana-orsa-dla-3-osob-jasnobrazowa |
| Leżak ROMEO | 550 zł / szt. | /produkt/lezak-ogrodowy-romeo-kasztanowy-antyk |
| Lampa BODRI | 230 zł | /produkt/lampa-wiszaca-led-bodri-czarna |
| Zestaw 2 krzeseł PISECO | 470 zł | /produkt/zestaw-2-krzesel-piseco-ciemnozielone |

Lokalny podgląd: http://127.0.0.1:4173/
Zrzuty: .local-cache/linkowanie-2026-10-07/home-desktop.jpg oraz home-mobile.jpg.

Zmiany od b71e7fe, łącznie z zachowanymi poprawkami wcześniejszego etapu:
hosting/getspace/{catalog,product,homepage,sitemap}.php; script.js;
scripts/prerender-products.mjs; index.html; dom.html; ogrod.html;
netlify.toml; .github/workflows/deploy-getspace.yml;
tests/{local-preview-router.php,prerender-privacy-tests.mjs,public-artifact-tests.mjs,
catalog-link-http-tests.mjs,homepage-figure-exclusion-tests.mjs}; ten raport.

Nie zmieniono data/products.json, zdjęć, CSS, sklepu, płatności ani danych serwera.
HEAD nadal b71e7fe. Brak commitu i wdrożenia. Nie ma nierozstrzygniętej pary URL
w zakresie obecnego polecenia; leżąca twarz czeka na osobne wystawienie w sklepie.

## Historia wcześniejszego etapu (nie opisuje aktualnych statusów)

Stan wyjściowy: commit b71e7fe. Publiczna migawka pobrana tylko do odczytu przez
scripts/fetch-public-products.mjs: 168 produktów, 168 unikalnych slugów.
Nie edytowano data/products.json, danych serwera, cen, statusów ani profili dostawy.
Brak commitu i wdrożenia.

## Przygotowana poprawka

- product.php: właściciel potwierdził duplikat CAPTAIN; adres
  /produkt/krzeslo-biurowe-captain-jasnobezowe-2 zwraca 301 do
  /produkt/krzeslo-biurowe-captain-jasnobezowe. Wykorzystano istniejący mechanizm
  przekierowań PHP. Docelowy produkt zachowuje status sprzedania.
- Oba dawne adresy głów 140 cm pozostają 404, bez przekierowania.
- Generator w katalogu źródłowym czyta publiczną migawkę z .local-cache, zamiast
  historycznego data/products.json (55 rekordów). SITE_ROOT=publish nadal czyta
  kopię migawki przygotowaną przez istniejący build. Pusta migawka przerywa
  generowanie przed zapisem szablonów.
- index.html, dom.html i ogrod.html odświeżono generatorem. Oprócz bloków
  STATIC_PRODUCTS_START / END podbito wersję script.js do 20261006-links1,
  aby przeglądarka pobrała aktualną regułę linkowania. Wersjonowanie CSS bez zmian.
- Netlify pobiera publiczną migawkę przed generowaniem; zapisuje ją w /tmp poza
  katalogiem publikacji. Workflow Getspace, wykluczenia danych i rollback bez zmian.

## Ostateczna decyzja o SMOKACH

/produkt/figurki-ogrodowe-dekoracyjne-styl-kamienny zachowuje własną kartę
outletową (200, bez przekierowania), cenę 65 zł i wymiary 16–25 cm. Pozostaje
wyłączony z /ogrod. Nie jest łączony z osobnymi smokami ani dodawany do Figur.

## Cztery twarze — kandydaci do zatwierdzenia par URL

Właściciel potwierdził prezentację wyłącznie w Figurach, lecz nie zatwierdził par
URL. Nie dodano nowych kart, sekcji showroomowej, przekierowań ani zmian danych.
Stare cztery rekordy pozostają bez linków w Ogrodzie. Wyłączono je dodatkowo
z polecanych produktów na stronie głównej, zgodnie w PHP, JS i generatorze.
Bezpośrednie adresy starych rekordów nadal zwracają 200 do czasu decyzji o parach.
Istniejące 40 kart sklepu pozostaje bez zmian.

Publiczne dane nie dostarczają wspólnego trwałego identyfikatora par. Nie ma też
wspólnych plików zdjęć głównych lub galerii między tymi rekordami a sklepem.
Poniżej kandydaci wynikający z porównania fotografii i wariantu, nie z samej nazwy.
Żadna para nie została jeszcze uznana za zatwierdzone przekierowanie.

| Stary rekord i zdjęcie | Kandydat sklepu i zdjęcie | Rozbieżność / decyzja |
| --- | --- | --- |
| [Mała twarz](https://mgoutlet.pl/produkt/rzezba-ogrodowa-twarz-mala-dostepne-w-roznych-barwach) ![Mała twarz, kilka kolorów](https://mgoutlet.pl/uploads/dekoracyjna-glowa-oslonka-twarz-home-garden-outlet.webp) | [Czarna twarz](https://mgoutlet.pl/sklep/figury-ogrodowe/produkt/figura-ogrodowa-twarz-czarna-artystyczne-wykonczenie) ![Czarna twarz ze sklepu](https://mgoutlet.pl/uploads/dekoracyjna-figura-ogrodowa-twarz-czarna-z-artystycznym-wykonczeniem-20260707-100443-dcd52e.webp) | 51 → 49 cm; cena 250 zł w obu rekordach. Stara karta obejmuje kilka kolorów, kandydat konkretny czarny wariant. Wymaga potwierdzenia modelu, koloru i pary URL. |
| [Głowa 120 cm](https://mgoutlet.pl/produkt/rzezba-betonowa-do-ogrodu-dekoracyjna-glowa-120-cm) ![Ciemna głowa ze starego rekordu](https://mgoutlet.pl/uploads/rzezba-betonowa-do-ogrodu-glowa-140cm-home-garden-outlet.webp) | [Twarz kobiety 114 cm, czarno-miedziana](https://mgoutlet.pl/sklep/figury-ogrodowe/produkt/figura-ogrodowa-twarz-kobiety-114-cm-czarna-z-miedzianym-motywem-winorosli) ![Twarz 114 cm, czarno-miedziana](https://mgoutlet.pl/uploads/figura-ogrodowa-twarz-kobiety-145-cm-czarna-z-miedzianym-motywem-winorosli-20260810-094640-05b747.webp) | 120 → 114 cm; 1400 → 1500 zł. Zgodny charakterystyczny kształt i motyw miedzianych pnączy, lecz rozbieżne dane. Nie utożsamiać z inną szarą twarzą 120 cm. Wymaga zatwierdzenia pary. |
| [Głowa z siedziskiem 120 cm](https://mgoutlet.pl/produkt/rzezba-betonowa-do-ogrodu-z-siedziskiem-dekoracyjna-glowa-120-cm) ![Stara głowa z siedziskiem](https://mgoutlet.pl/uploads/rzezba-betonowa-do-ogrodu-z-siedziskiem-glowa-140cm-home-garden-outlet.webp) | [Twarz kobiety z siedziskiem 114 cm, szaro-brązowa](https://mgoutlet.pl/sklep/figury-ogrodowe/produkt/figura-ogrodowa-twarz-kobiety-z-zamknietymi-oczami-i-siedziskiem-114-cm-szaro-brazowa) ![Twarz kobiety z siedziskiem ze sklepu](https://mgoutlet.pl/uploads/figura-ogrodowa-twarz-kobiety-z-zamknietymi-oczami-i-siedziskiem-114-cm-szaro-brazowa-20260823-114459-df4a8b.webp) | 120 → 114 cm; cena 1500 zł w obu rekordach. Podobny kształt i wykończenie, lecz rozbieżny wymiar. Wymaga zatwierdzenia pary. |
| [Leżąca twarz](https://mgoutlet.pl/produkt/lezaca-rzezba-betonowa-do-ogrodu-dekoracyjna-twarz) ![Biała twarz leżąca poziomo](https://mgoutlet.pl/uploads/lezaca-rzezba-betonowa-do-ogrodu-twarz-home-garden-outlet.webp) | Nie znaleziono potwierdzonego odpowiednika w 40 aktywnych kartach sklepu. | Stara cena 600 zł; brak wymiaru. Właściciel musi wskazać istniejącą kartę albo zdecydować o dalszym losie starego rekordu. Nie proponuje się nowej karty lub sekcji showroomowej. |

Wszystkie siedem użytych zdjęć publicznych odpowiada HTTP 200 (HEAD). Aktualna
migawka: 168 produktów. Nie zmieniono żadnej ceny, wymiaru, stanu ani profilu dostawy.

## Weryfikacja lokalna

- node scripts/build-getspace.mjs + SITE_ROOT=publish node scripts/prerender-products.mjs: PASS.
- node tests/public-artifact-tests.mjs: PASS; 168 rekordów, ceny i identyfikatory
  kart zgodne z migawką. Oferta serwera nie była nadpisywana.
- node tests/prerender-privacy-tests.mjs: PASS, także zachowanie szablonu przy
  pustym wejściu. node tests/public-products-snapshot-tests.mjs: PASS.
- php -l hosting/getspace/product.php: PASS; testy prezentacji katalogu i
  frontend sklepu w trybie disabled: PASS.
- node tests/catalog-link-http-tests.mjs: PASS; 301 CAPTAIN (także końcowy slash),
  cel 200 bez dalszego przekierowania i ze statusem sprzedania, obie głowy 140 cm
  404 bez Location; identyczne zbiory linków źródło/artefakt/HTML PHP.
- node tests/homepage-figure-exclusion-tests.mjs: PASS; deterministyczny fixture
  potwierdza wyłączenie czterech starych twarzy i zachowanie kwalifikacji SMOKÓW
  do polecanych produktów w PHP, JavaScripcie i generatorze statycznym.
- Po JavaScripcie: Dom 65 kart, Ogród 14, Figury 40. Zbiory linków zgodne z HTML
  serwerowym i artefaktem. Brak trzech starych linków.
- Strona główna: sześć kart po JS odpowiada sześciu slugom wybranym przez PHP
  w tym samym żądaniu; sam wybór na kolejnych żądaniach nadal jest losowy.
- W przeglądarce wyszukanie „rzeźba ogrodowa twarz mała” na stronie głównej
  nie przywraca starej karty (0 wyników); „SMOKI” daje dokładnie jeden wynik
  /produkt/figurki-ogrodowe-dekoracyjne-styl-kamienny. Brak błędów JavaScript.
- git diff --check: PASS. CSS, dane i workflow Getspace bez zmian; JavaScript
  zmieniono wyłącznie w zakresie kwalifikacji czterech starych twarzy do polecanych.

Podgląd: http://127.0.0.1:4173/dom, /ogrod i /sklep/figury-ogrodowe.

## Zmienione pliki

- hosting/getspace/product.php
- hosting/getspace/homepage.php
- script.js
- scripts/prerender-products.mjs
- netlify.toml
- index.html, dom.html, ogrod.html
- tests/prerender-privacy-tests.mjs
- tests/catalog-link-http-tests.mjs
- tests/homepage-figure-exclusion-tests.mjs
- reports/linkowanie-i-figury-decyzje-2026-10-06.md
