# Allegro — B.1.3: uzgodnienie z aktualnym main i kwalifikacja lokalnego release

**28.10.2026, Europe/Warsaw. Home & Garden Outlet / mgoutlet.pl.**

**Wynik: B12-BASE-01 zamknięty dla izolowanego lokalnego kandydata.** Bazą jest aktualny pobrany main, cały nowszy OLX zachowano, a diff współdzielonego panelu względem main to wyłącznie link Allegro. Lokalna kwalifikacja i deterministyczność 11 payloadów: PASS. To nie wynik CI PHP8.3/Node22 ani zgoda na commit, publikację, Actions, deploy lub OAuth.

## A. Aktualny origin/main

Repozytorium źródłowe: `C:/Users/Albert/Documents/Strona www/github-deploy`.
Zastany lokalny HEAD i gałąź: `849121bf20ec012f89c293b17e690ddf91c28b36`, `codex/gbp-read-diagnostics`.
Przeczytano cały aktualny raport B.1.2; jego historyczny wynik STOP pozostaje opisem poprzedniego snapshotu i nie był nadpisywany.

Wykonano dozwolony read-only `git fetch origin main` na początku i ponownie przed końcowym raportem. **origin/main = `df4c32cc77f2500a5ba238d892803f9afac1f7bd`**, zgodny z B.1.2. Różnica HEAD...origin/main: **0 / 9**. Nowej zmiany bazy od B.1.2 nie wykryto.

Praca implementacyjna odbyła się wyłącznie w lokalnej kopii na detached HEAD tego main:

- Kandydat: `C:/Users/Albert/Documents/Strona www/github-deploy/.local-cache/allegro-b13-candidate`.
- Niezależna kopia kontrolna: `C:/Users/Albert/Documents/Strona www/github-deploy/.local-cache/allegro-b13-clean`.

Nie wykonywano rebase/merge/reset/clean ani stash w oryginalnym worktree. Zastanych sześciu zmian tracked, nowych plików Allegro i raportów innych prac nie ruszano. Hashami potwierdzono zachowanie wszystkich zastanych plików oryginału. W oryginale powstaje tylko ten raport i ignorowane dowody/kopie. Żadnego git add lub nowego commitu.

**Uwaga operacyjna:** oryginalny katalog nadal zawiera STAREGO kandydata B/B.1.1. Nowy kwalifikowany release jest w podanej izolowanej kopii. Nie commitować pierwotnego brudnego katalogu jako B.1.3. Lokalne kopie utworzono z lokalnej historii; ich origin jest lokalnym źródłem, a nie nową zdalną gałęzią. Ewentualne przygotowanie gałęzi/remote do publikacji wymaga późniejszego świadomego kroku i osobnej zgody.

## B. Lista nowych commitów

Przejrzano skumulowany pełny diff 19 plików (1423 dodane / 93 usunięte linie), kod nowych bibliotek/stron, workflow/helper, dokumenty i testy oraz kolejność dziewięciu commitów:

| SHA | Zmiana main |
|---|---|
| `ff2d71af15840734a4754d3080cac87f9ffafa94` | Exchange OLX OAuth codes immediately without persisting them |
| `bac01818f31f565c58501a39f6036ea918971868` | Allow final local FTPS verification and reuse matching completed OLX backup |
| `d189859d5dd840d061f63e7a389d910363769b7b` | Navigate to OLX from same-origin POST document preserving CSP |
| `7325a703b388e405d95fb02c943f8d5361a73136` | Add OLX Stage C configuration reads and isolated validated product drafts |
| `5c396d34f3dab836276549e3e16f7e0a54cbb4bc` | Keep OLX preview fields within mobile viewport and render one mapping selector |
| `a87e34a9bd6bf57fea19368cca024c799060a35a` | Add explicit single-product OLX publication with durable duplicate barrier |
| `b74596e57267ee508c4ec286b59d009d3759e5de` | Omit restricted OLX external URL and classify documented publishing errors |
| `7302ece30cfb25b499d5a965eb131561e5589a73` | Show OLX product photos, clear statuses and explicit manual actions |
| `df4c32cc77f2500a5ba238d892803f9afac1f7bd` | Scope presentation deployment to two PHP files with verified current backups |

To funkcje istniejącego main, nie implementacja Allegro ani upoważnienie do rzeczywistych operacji OLX. Testy używają atrap, a helper deploya OLX nie był wykonywany.

## C. Konflikty plików — MAIN / Allegro / rozwiązanie

| Plik zmieniony na nowym main | Co zmienił main | Co dodaje Allegro | Konflikt / zachowanie obu funkcji |
|---|---|---|---|
| `.github/workflows/deploy-olx-oauth.yml` | Osobny ręczny deploy OLX z czterema zakresami; testy i FTPS | Nic | Bez zmian; wyłącznie zachować |
| `documents/olx-etap-c.md` | Kontrakt szkiców/mapowania/cache i oddzielenia produktu od OLX | Nic | Bez zmian; dokument odziedziczony |
| `documents/olx-oauth-getspace-2026-10-26.md` | Natychmiastowa wymiana OLX, czysty return i świadoma polityka logów OLX | Nic | Bez zmian; nie przenosić wyjątku logów do Allegro |
| `hosting/getspace/admin/index.php` | 11-liniowy blok statusu OLX, publish-lib i link szkicu | Link w nawigacji | Konflikt starej pełnej kopii; main + wyłącznie link Allegro |
| `hosting/getspace/admin/olx/.htaccess` | Ochrona lib/prepare-lib/publish-lib | Nic | Bez zmian |
| `hosting/getspace/admin/olx/callback.php` | Wymiana od razu, 303 do return.php, bez pending code | Nic | Bez zmian; niezależny callback Allegro |
| `hosting/getspace/admin/olx/index.php` | Preflight, dokument nawigacji po POST, diagnostyka i link przygotowania | Nic | Bez zmian |
| `hosting/getspace/admin/olx/lib.php` | Polityka logów OLX, callback bez utrwalania kodu, preflight, account GET | Nic | Bez zmian; funkcje olx_* odrębne od allegro_* |
| `hosting/getspace/admin/olx/prepare-lib.php` | Whitelist GET konfiguracji, cache, szkice po productId, walidacja i preview | Nic | Bez zmian |
| `hosting/getspace/admin/olx/prepare.php` | Szkice, kategorie/mapowanie/lokalizacja, zdjęcia i statusy produktów | Nic | Bez zmian |
| `hosting/getspace/admin/olx/publish-lib.php` | Bariery duplikatów, trwały stan, walidacja/GPSR i kontrolowana publikacja | Nic | Bez zmian; transport w testach syntetyczny |
| `hosting/getspace/admin/olx/publish.php` | Sesja/CSRF/nonce/podgląd/potwierdzenie jednej publikacji i reconcile | Nic | Bez zmian |
| `hosting/getspace/admin/olx/return.php` | Czysty dokument powrotu bez tworzenia sesji | Nic | Bez zmian |
| `scripts/deploy-olx-oauth.mjs` | Zakresy deploya, kopie bieżącego kodu, katalog hash-gate i rollback | Nic | Bez zmian i bez uruchamiania |
| `tests/olx-http-tests.mjs` | Nowy callback, szkice/mapowanie, miniatury/statusy i blokada istniejącej oferty | Nic | Bez zmian; uruchomiono |
| `tests/olx-oauth-tests.php` | Natychmiastowa wymiana, brak kodu w vault, polityki logów/preflight | Nic | Bez zmian; uruchomiono |
| `tests/olx-preparation-tests.php` | Syntetyczne GET/cache, kategorie/cena/atrybuty/zdjęcia/szkic | Nic | Bez zmian; uruchomiono |
| `tests/olx-publication-http-tests.mjs` | CSRF/nonce/potwierdzenie/double click/payload injection | Nic | Bez zmian; uruchomiono |
| `tests/olx-publication-tests.php` | Bariery i trwałość, błędy bez retry, identity, statusy i lifecycle | Nic | Bez zmian; uruchomiono |

Pozostałe współdzielone `.gitignore`, `admin/.htaccess`, builder całej witryny, workflow Getspace i README nie zmieniły się między starą bazą a main — sprawdzono ich wersje Git, nie założono zgodności. Session/CSRF (`admin/lib.php`), product identity, root routing i `data/products.json` również nie zmieniły się w tych dziewięciu commitach. Nie ma nowej zależności Allegro od publikacji/kategorii OLX.

## D. admin/index.php

**MAIN jest bazą.** Na wersję `df4c32c` zastosowano tylko patch Allegro, zamiast kopiować starszy plik. Zachowano dokładnie wszystkie 11 linii nowszej prezentacji OLX: warunek trwałego productId, require `olx/publish-lib.php`, odczyt `olx_publication_status(olx_config(), ...)`, etykietę statusu, ID/external ID/timestamps, odnośnik ogłoszenia oraz przycisk przygotowania szkicu. Nie dodano wywołania publikacji do zapisu produktu.

Dokładny diff względem main:

```diff
diff --git a/hosting/getspace/admin/index.php b/hosting/getspace/admin/index.php
index 2d1a7b3..9ca3acf 100644
--- a/hosting/getspace/admin/index.php
+++ b/hosting/getspace/admin/index.php
@@ -841,6 +841,7 @@ if ($showStats) {
       <a class="<?= $showOrders ? 'active' : '' ?>" href="/admin/?orders=1">ZamĂłwienia sklepu</a>
       <a class="<?= $showShipping ? 'active' : '' ?>" href="/admin/?shipping=1">Cennik dostaw</a>
       <a href="/admin/olx/">OLX</a>
+      <a href="/admin/allegro/">Allegro</a>
       <a href="/sklep/figury-ogrodowe" target="_blank" rel="noopener">PodglÄ…d sklepu figur</a>
     </nav>
```

Odejmowanie tego jednego linku daje dokładnie aktualny main po normalizacji LF. Aktualne testy OLX i test save_product Allegro przechodzą. Nowy test uzgodnienia dodatkowo pilnuje niezmienności całego kodu OLX/testów/helpera i wspólnej biblioteki administratora.

## E. admin/.htaccess

Dokładny diff względem main:

```diff
diff --git a/hosting/getspace/admin/.htaccess b/hosting/getspace/admin/.htaccess
index 53b9714..6743408 100644
--- a/hosting/getspace/admin/.htaccess
+++ b/hosting/getspace/admin/.htaccess
@@ -8,6 +8,7 @@ DirectoryIndex index.php
 <IfModule mod_rewrite.c>
   RewriteEngine On
   RewriteRule ^olx/callback/?$ olx/callback.php [END]
+  RewriteRule ^allegro/callback/?$ allegro/callback.php [END]
   RewriteRule ^export-events\.php$ index.php?export_events=1 [QSA,L]
   RewriteRule ^storage(?:/|$) - [F,L]
 </IfModule>
```

Istniejąca reguła OLX pozostaje pierwsza, Allegro ma odrębny zakotwiczony prefiks, następnie dotychczasowe export-events/storage. Nie usunięto żadnej reguły, ochrony bibliotek, Strict cookie ani CSP main. Reguły/efektywne nagłówki prawdziwego Apache nadal wymagają przyszłego odbioru Getspace; lokalne testy ich nie zastępują.

## F. Pozostałe współdzielone pliki — trzyczęściowy diff

### .gitignore

MAIN jest identyczny ze starą bazą dla tego pliku. Po normalizacji LF stara zmiana Allegro i nowy kandydat są identyczne. Poniższy diff opisuje zarówno MAIN → stare B, jak i MAIN → B.1.3; nie skopiowano starego pliku, zastosowano wyłącznie patch do main.

```diff
--- MAIN/.gitignore
+++ B13/.gitignore
@@ -23,3 +23,13 @@
 !hosting/getspace/vendor/.htaccess
 private/geoip/
 *.mmdb
+# Allegro: configuration, tokens and authorization data must stay outside Git/webroot.
+**/private_allegro/
+hosting/getspace/admin/allegro/storage/
+hosting/getspace/admin/allegro/logs/
+hosting/getspace/admin/allegro/cache/
+hosting/getspace/admin/allegro/*.enc
+hosting/getspace/admin/allegro/*.jsonl*
+hosting/getspace/admin/allegro/*.lock
+hosting/getspace/admin/allegro/config.private.php
+hosting/getspace/admin/allegro/.env*
```

### .github/workflows/deploy-getspace.yml

MAIN jest identyczny ze starą bazą dla tego pliku. Po normalizacji LF stara zmiana Allegro i nowy kandydat są identyczne. Poniższy diff opisuje zarówno MAIN → stare B, jak i MAIN → B.1.3; nie skopiowano starego pliku, zastosowano wyłącznie patch do main.

```diff
--- MAIN/.github/workflows/deploy-getspace.yml
+++ B13/.github/workflows/deploy-getspace.yml
@@ -67,6 +67,9 @@
           php tests/product-id-migration-tests.php
           node tests/olx-http-tests.mjs
           node tests/olx-identity-verification-tests.mjs
+          php tests/allegro-oauth-tests.php
+          node tests/allegro-http-tests.mjs
+          node tests/allegro-artifact-tests.mjs
           php tests/location-stats-tests.php
           php tests/stats-browser-exclusion-http-tests.php
           php tests/traffic-chart-stats-tests.php
@@ -85,6 +88,9 @@

       - name: Sprawdź publiczne identyfikatory i karty w artefakcie
         run: node tests/public-artifact-tests.mjs
+
+      - name: Sprawdź brak prywatnych danych Allegro w artefakcie
+        run: node tests/allegro-artifact-tests.mjs --artifact=publish

       - name: Sprawdź zgodność kompaktowych kart PHP, JavaScript i generatora
         run: |
@@ -241,6 +247,15 @@
             uploads/**/*.gif
             admin/.credentials.php
             admin/storage/**
+            **/private_allegro/**
+            admin/allegro/storage/**
+            admin/allegro/logs/**
+            admin/allegro/cache/**
+            admin/allegro/*.enc
+            admin/allegro/*.jsonl*
+            admin/allegro/*.lock
+            admin/allegro/config.private.php
+            **/.env*

       - name: Pobierz swieze dane, wykonaj migracje i zapisz kopie
         id: migration
```

### scripts/build-getspace.mjs

MAIN jest identyczny ze starą bazą dla tego pliku. Po normalizacji LF stara zmiana Allegro i nowy kandydat są identyczne. Poniższy diff opisuje zarówno MAIN → stare B, jak i MAIN → B.1.3; nie skopiowano starego pliku, zastosowano wyłącznie patch do main.

```diff
--- MAIN/scripts/build-getspace.mjs
+++ B13/scripts/build-getspace.mjs
@@ -1,6 +1,7 @@
 import { cp, mkdir, readFile, rm, stat } from "node:fs/promises";
 import { fileURLToPath } from "node:url";
 import path from "node:path";
+import { publicAdminSource, assertPrivateAllegroAbsent } from './getspace-artifact-privacy.mjs';

 const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
 const publish = path.join(root, "publish");
@@ -110,8 +111,7 @@

 await cp(path.join(root, "hosting", "getspace", "admin"), path.join(publish, "admin"), {
   recursive: true,
-  filter: source => !["storage", ".credentials.php", ".user.ini"].includes(path.basename(source))
-    && !path.basename(source).startsWith(".env")
+  filter: publicAdminSource
 });
 await cp(path.join(root, "hosting", "getspace", "lib"), path.join(publish, "lib"), { recursive: true });
 await cp(path.join(root, "hosting", "getspace", "shop-test"), path.join(publish, "shop-test"), { recursive: true });
@@ -129,4 +129,5 @@
 await cp(path.join(root, "hosting", "getspace", "homepage.php"), path.join(publish, "homepage.php"));
 await cp(path.join(root, "hosting", "getspace", "sitemap.php"), path.join(publish, "sitemap.php"));

+await assertPrivateAllegroAbsent(publish);
 console.log(`Przygotowano paczkę Getspace z ${products.length} produktami i ${uploadPaths.size} używanymi zdjęciami.`);
```

### hosting/getspace/README.md

MAIN jest identyczny ze starą bazą dla tego pliku. Po normalizacji LF stara zmiana Allegro i nowy kandydat są identyczne. Poniższy diff opisuje zarówno MAIN → stare B, jak i MAIN → B.1.3; nie skopiowano starego pliku, zastosowano wyłącznie patch do main.

```diff
--- MAIN/hosting/getspace/README.md
+++ B13/hosting/getspace/README.md
@@ -87,3 +87,14 @@
 Build bez migawki nie wraca po cichu do starszych 55 rekordów. Optymalizacja
 zdjęć z repo nie działa na tej migawce: mogłaby zmienić adresy zdjęć, których
 workflow celowo nie przesyła na serwer.
+
+## Allegro — lokalny etap B
+
+Moduł `admin/allegro/` obsługuje wyłącznie OAuth i odczyt konta. Sprzedażowe
+operacje API są blokowane w kodzie, a OAuth domyślnie wyłączony. Osobna
+konfiguracja, klucz i magazyn muszą być poza Git i webrootem; OLX pozostaje
+niezależny. Instrukcja i wymagane bramki bezpieczeństwa:
+[documents/allegro-etap-b.md](../../documents/allegro-etap-b.md).
+Nie uruchamiać prawdziwego OAuth przed potwierdzeniem ochrony query callbacku
+w logach hostingu oraz osobną zgodą właściciela. Lokalna implementacja nie
+oznacza wdrożenia ani połączenia konta.
```

Dodatki B do starego workflow to testy i wykluczenia prywatności; nie zmieniano jego triggerów, provisioning OLX, migratorów, zakresu transferu ani rollbacku. **Nadal nie wolno użyć tego pełnego workflow jako deploya Allegro-only.** Izolowany builder B.1.1 nie uruchamia pełnego buildera ani nie potrzebuje snapshotu produktów.

Surowe SHA-256 trzech wersji (końce linii w checkoutcie mogą się różnić; powyższe porównania semantyczne używają LF):

| Plik | MAIN blob SHA-256 | Stare robocze Allegro B | Nowy kandydat — bajty źródła |
|---|---|---|---|
| `.gitignore` | `02a88e1ba4692de9f7e702192a2622d354064f31bc3d1e387b5583b85ba79ce2` | `ec2703b056cd0701201b32e0e6611963f4a4e2b9da0dee7ade1769fb4e5f19dc` | `e64f4b477b6819101fb3b7e64cca2c6c45c9685c642d657c10dfce4e46f5fc60` |
| `.github/workflows/deploy-getspace.yml` | `13f6eed44aea68d008b0a480c8cb527935e3736f550d9853b2883c9103d99489` | `cd06f7c7267ba414047cbaa27e77f66590703a6c6f4df0fd0abfbd3a9e58952a` | `25e2cb7f58e836c6b872006cc15a8e4e7ecec441745211344bbd63539c796e29` |
| `scripts/build-getspace.mjs` | `d7215843e16d79b0e22aeef7ef8a9cf7cc6be5bd390b8f002bc5c1dc5d06303e` | `ba3c254ecf6037e9268d2718695c75a0d766cd56a4800a691c68e17e77321722` | `807dae3cfea19a422d85b98d6ef82059dbffd70ff0f2ea21b22270b05529025b` |
| `hosting/getspace/README.md` | `cdfbe804e0349b2bd7862cf2dee185a87a3fc6ef18416e38fc6299f0989660db` | `972ee78d68d4ff10b3c8b4ce2b87ab92088cf5b94df8e1eadb730111aa81c283` | `e056224ac7d0a948c4a2dda768639b85af7cfe2fdf830886e1dc135f49248df0` |
| `hosting/getspace/admin/.htaccess` | `a523ceb5a93d733a222358e4eacc334c777ca1e5554b6bdaea98d0ccc415805d` | `843ebe07e10c8dedd2f83979ef93fc994184cad664249e32480e9bcdff635cd8` | `ba96fa70f56a2fae4ba103b71b8668d1f00881c74833043bc85557044cc122b7` |
| `hosting/getspace/admin/index.php` | `9ce29db773d3ae7cb4a961504acd94f1607634a89e4cfb0e9a27fba4619769e5` | `7483fcd9a96d3e3554049cc4af8c6eaa337d01af50617e92030e24eb1db82bd7` | `9d084838f15aae8ff11fddb902d3b2bc35e3420e015ce253efaf4ef45e455d54` |

## G. Moduł Allegro

Wszystkie dziewięć plików przejrzano względem dotychczasowego kontraktu i nowych zależności. Są bajt w bajt identyczne z B; nie była potrzebna korekta runtime:

| Plik | Decyzja | SHA-256 źródła |
|---|---|---|
| `.htaccess` | Bez zmian | `2fa63713f028f986e90234ab9895ae1bc66bd29af218fb9c47f770f629aec9b8` |
| `config.php` | Bez zmian | `7347656d2cbb9c1fb796aa16bbc25d074f98fb698f6b08b9b0016da48326f037` |
| `storage.php` | Bez zmian | `d2810966f4f8a6c428e836a7335eb8a2dd7db61eeabd603f7f628124ebd458e1` |
| `http.php` | Bez zmian | `569e731e9eb5f174b733623046047908e7a89321ee41dfe07e2ef1e789de2bfc` |
| `oauth.php` | Bez zmian | `3181801778eb0005dfe5807a777e81a9df56f74448b3daf5152d197d434d864e` |
| `lib.php` | Bez zmian | `fcf3115b9951bbbf680891fc5d745ebe3ffd2b25d9f42e3e199119938935bece` |
| `bridge.js` | Bez zmian | `1e064793701e275351ccfce5618aaa712d0e69df7afeda7043d3580da1a32dd1` |
| `callback.php` | Bez zmian | `7a506efd7ebe3d255b65cb6c8cef3e977312e03a9bd7bd8fbcca9552a0dc2f10` |
| `index.php` | Bez zmian | `420fdc061e6e2350df95f3002ba21888381bbb5c16d455a0d354de129ee2ad1a` |

Admin boot/login/CSRF i sesja Strict są niezmienione na nowym main. Wszystkie funkcje/wyjątki/storage Allegro są odrębne od olx_*. Callback OLX na main zmienił model wymiany, lecz nie zmienia to kontraktu Allegro: zachowano PKCE, krótki mostek, szyfrowanie i blokadę G16. Świadoma zgoda właściciela na politykę logów OLX **nie jest zgodą na osłabienie ochrony Allegro**.

Readiness źródła B i wydanie infrastruktury OFF pozostają rozdzielone. Wydaniowy config ma dosłownie enabled=false, logs_safe=false i bezwarunkową odmowę walidacji OAuth; env=1 nie wystarcza. Profil nadal **B.1.1-OAUTH-OFF-v1**: transformacja, scope i transport nie zmieniły się. Cały /sale pozostaje poza whitelistą.

## H. BASE / preimages — stare → nowe

W dedykowanym helperze kandydata:

- OLD BASE: `849121bf20ec012f89c293b17e690ddf91c28b36`.
- NEW BASE: `df4c32cc77f2500a5ba238d892803f9afac1f7bd`.

| Plik | Stary preimage LF SHA-256 | Nowy przejrzany main LF SHA-256 | Ten sam main w CRLF SHA-256 |
|---|---|---|---|
| `hosting/getspace/admin/.htaccess` | `a523ceb5a93d733a222358e4eacc334c777ca1e5554b6bdaea98d0ccc415805d` | `a523ceb5a93d733a222358e4eacc334c777ca1e5554b6bdaea98d0ccc415805d` | `2be2a73b50c907c4af9da883713417c4372fd4d9ebe848684d4087d26221d8a3` |
| `hosting/getspace/admin/index.php` | `6ebad9a90f6fb43e6afe659188a997cd2f6ce10db0b0502e710e1f5cb5de1f2d` | `9ce29db773d3ae7cb4a961504acd94f1607634a89e4cfb0e9a27fba4619769e5` | `9a513c6ceabe66e5db7a0278523de037a3cfc63412aef58d871b7c871bed7f1e` |

`admin/index.php` ma nowy preimage dlatego, że zachowuje przejrzany blok OLX z main. Nie zaakceptowano starego pliku jako alternatywnej wersji panelu. `.htaccess` nie zmienił się na main, więc jego nowy przejrzany preimage ma taki sam hash. LF/CRLF to dwie reprezentacje identycznej znanej treści, nie kilka nieznanych wersji „na wszelki wypadek”.

Weryfikator nadal wymaga, aby usunięcie dokładnie jednego dodatku Allegro ze współdzielonego źródła dało nowy BASE. Na serwerze dopuszcza wyłącznie ten znany preimage lub identyczny payload OFF (idempotentne powtórzenie); nieznany plik/przyszła nowsza wersja zatrzyma cały preflight przed transferem. Nie odczytywano serwera i nie stwierdza się, że faktyczny kod produkcji ma już te hashe.

## I. Frozen hash — stare → nowe

Dotychczasowy zamrożony hash dziewięciu plików modułu został **ponownie obliczony i świadomie potwierdzony**, a nie automatycznie odziedziczony:

- OLD MODULE: `770c1e9dd135c60f5c1ee27dace6660669b2ffb1558d4713f9cd823b3aa8c44c`.
- NEW MODULE: `770c1e9dd135c60f5c1ee27dace6660669b2ffb1558d4713f9cd823b3aa8c44c`.
- Powód braku zmiany: wszystkie dziewięć plików ma niezmienione bajty; wspólne zależności sesji/CSRF nie uległy zmianie.

Dodatkowo helper B.1.3 wymusza nowy **REVIEWED_RUNTIME_SHA256 dla wszystkich 11 źródeł runtime**:

- OLD 11 SOURCES: `a1d785c4bd5b23e46f42d79e16019a6358c0e74886f695f23cf034a4aa22a1e3` (wyliczony do porównania; poprzednio osobnym pinem objęty był tylko moduł).
- NEW 11 SOURCES: `3dfc289f54a50687ac5f65f692ef3ac108f6675f13569f0e9c55329b42b47a44` (nowa jawna stała i kontrola w expected_files).
- Powód zmiany: aktualny `admin/index.php` zawiera nowsze OLX oraz minimalny link Allegro.

Algorytm: w kolejności ALLOWLIST SHA-256 z konkatenacji target + NUL + UTF-8 źródła z LF, przed kontrolowaną transformacją configu. Nowy pin obejmuje również współdzielony panel; nie można go po cichu zastąpić starszą kopią lub dodać innego kodu.

Odcisk wierszy source/payload/preimage manifestu zmienił się z lokalnego B.1.2 `786244c17f5c425e56545307b36a35eb5067546df3e9b47a7986c45750479262` na lokalny B.1.3 **`38c9e1ff782db6360e07f920954b13a54704815372545296ad1ec48d39dcb232`**. Ten odcisk i pin źródeł to różne, jawnie opisane kontrole.

## J. Allowlista runtime — nadal dokładnie 11

Allowlisty nie rozszerzono. Zachowano pojedynczą autorytatywną listę dla build/verify/backup/upload/rollback. Nie dodano plików OLX do transferu; jego nowe biblioteki są już częścią main i zależnościami istniejącego panelu, a nie plikami nowego deploya Allegro.

| Źródło względem kandydata | Target względem public_html | SHA-256 payloadu lokalnego builda |
|---|---|---|
| `hosting/getspace/admin/allegro/.htaccess` | `admin/allegro/.htaccess` | `2fa63713f028f986e90234ab9895ae1bc66bd29af218fb9c47f770f629aec9b8` |
| `hosting/getspace/admin/allegro/config.php` | `admin/allegro/config.php` | `d1042b53f70713be38edde0158ee656c619a624a2034cbea26f44f138e00bfd9` |
| `hosting/getspace/admin/allegro/storage.php` | `admin/allegro/storage.php` | `d2810966f4f8a6c428e836a7335eb8a2dd7db61eeabd603f7f628124ebd458e1` |
| `hosting/getspace/admin/allegro/http.php` | `admin/allegro/http.php` | `569e731e9eb5f174b733623046047908e7a89321ee41dfe07e2ef1e789de2bfc` |
| `hosting/getspace/admin/allegro/oauth.php` | `admin/allegro/oauth.php` | `3181801778eb0005dfe5807a777e81a9df56f74448b3daf5152d197d434d864e` |
| `hosting/getspace/admin/allegro/lib.php` | `admin/allegro/lib.php` | `fcf3115b9951bbbf680891fc5d745ebe3ffd2b25d9f42e3e199119938935bece` |
| `hosting/getspace/admin/allegro/bridge.js` | `admin/allegro/bridge.js` | `1e064793701e275351ccfce5618aaa712d0e69df7afeda7043d3580da1a32dd1` |
| `hosting/getspace/admin/allegro/callback.php` | `admin/allegro/callback.php` | `7a506efd7ebe3d255b65cb6c8cef3e977312e03a9bd7bd8fbcca9552a0dc2f10` |
| `hosting/getspace/admin/allegro/index.php` | `admin/allegro/index.php` | `420fdc061e6e2350df95f3002ba21888381bbb5c16d455a0d354de129ee2ad1a` |
| `hosting/getspace/admin/.htaccess` | `admin/.htaccess` | `843ebe07e10c8dedd2f83979ef93fc994184cad664249e32480e9bcdff635cd8` |
| `hosting/getspace/admin/index.php` | `admin/index.php` | `fd966af9611f9020a8cc56a9eb848c04a8cf94f2e54b951eaa074884179ff3c1` |

Manifest poza runtime. Prywatny config, klucze/vaulty/state/pending/logi, produkty, uploady, OLX, Paynow i narzędzia CI pozostają poza transferem. Zmiana scope lub dopuszczonego API nie nastąpiła.

## K. Nowy release set — 29 plików przyszłego commitu

Poniżej **dokładne ścieżki w nowym kandydacie**, nie w starym brudnym worktree. Względem B.1.2 liczba wzrosła z 28 do 29 przez dodanie niniejszego raportu. Zmieniono też helper, nowy workflow i test release w ramach tych samych pozycji. Pozostałe pliki odziedziczonego main — w tym wszystkie aktualne testy OLX — już są śledzone i pozostaną w drzewie przyszłego commitu bez ponownego dodawania.

### RUNTIME

- `C:/Users/Albert/Documents/Strona www/github-deploy/.local-cache/allegro-b13-candidate/hosting/getspace/admin/allegro/.htaccess`
- `C:/Users/Albert/Documents/Strona www/github-deploy/.local-cache/allegro-b13-candidate/hosting/getspace/admin/allegro/config.php`
- `C:/Users/Albert/Documents/Strona www/github-deploy/.local-cache/allegro-b13-candidate/hosting/getspace/admin/allegro/storage.php`
- `C:/Users/Albert/Documents/Strona www/github-deploy/.local-cache/allegro-b13-candidate/hosting/getspace/admin/allegro/http.php`
- `C:/Users/Albert/Documents/Strona www/github-deploy/.local-cache/allegro-b13-candidate/hosting/getspace/admin/allegro/oauth.php`
- `C:/Users/Albert/Documents/Strona www/github-deploy/.local-cache/allegro-b13-candidate/hosting/getspace/admin/allegro/lib.php`
- `C:/Users/Albert/Documents/Strona www/github-deploy/.local-cache/allegro-b13-candidate/hosting/getspace/admin/allegro/bridge.js`
- `C:/Users/Albert/Documents/Strona www/github-deploy/.local-cache/allegro-b13-candidate/hosting/getspace/admin/allegro/callback.php`
- `C:/Users/Albert/Documents/Strona www/github-deploy/.local-cache/allegro-b13-candidate/hosting/getspace/admin/allegro/index.php`
- `C:/Users/Albert/Documents/Strona www/github-deploy/.local-cache/allegro-b13-candidate/hosting/getspace/admin/.htaccess`
- `C:/Users/Albert/Documents/Strona www/github-deploy/.local-cache/allegro-b13-candidate/hosting/getspace/admin/index.php`

### CI / RELEASE

- `C:/Users/Albert/Documents/Strona www/github-deploy/.local-cache/allegro-b13-candidate/.gitignore`
- `C:/Users/Albert/Documents/Strona www/github-deploy/.local-cache/allegro-b13-candidate/.github/workflows/deploy-getspace.yml`
- `C:/Users/Albert/Documents/Strona www/github-deploy/.local-cache/allegro-b13-candidate/.github/workflows/deploy-allegro-infra-getspace.yml`
- `C:/Users/Albert/Documents/Strona www/github-deploy/.local-cache/allegro-b13-candidate/scripts/build-getspace.mjs`
- `C:/Users/Albert/Documents/Strona www/github-deploy/.local-cache/allegro-b13-candidate/scripts/getspace-artifact-privacy.mjs`
- `C:/Users/Albert/Documents/Strona www/github-deploy/.local-cache/allegro-b13-candidate/scripts/allegro-infra-release.py`
- `C:/Users/Albert/Documents/Strona www/github-deploy/.local-cache/allegro-b13-candidate/scripts/allegro-infra-smoke.py`

### TESTY

- `C:/Users/Albert/Documents/Strona www/github-deploy/.local-cache/allegro-b13-candidate/tests/allegro-oauth-tests.php`
- `C:/Users/Albert/Documents/Strona www/github-deploy/.local-cache/allegro-b13-candidate/tests/allegro-http-tests.mjs`
- `C:/Users/Albert/Documents/Strona www/github-deploy/.local-cache/allegro-b13-candidate/tests/allegro-artifact-tests.mjs`
- `C:/Users/Albert/Documents/Strona www/github-deploy/.local-cache/allegro-b13-candidate/tests/allegro-infra-release-tests.py`

### DOKUMENTACJA

- `C:/Users/Albert/Documents/Strona www/github-deploy/.local-cache/allegro-b13-candidate/hosting/getspace/README.md`
- `C:/Users/Albert/Documents/Strona www/github-deploy/.local-cache/allegro-b13-candidate/documents/allegro-etap-b.md`
- `C:/Users/Albert/Documents/Strona www/github-deploy/.local-cache/allegro-b13-candidate/reports/allegro-etap-b.md`
- `C:/Users/Albert/Documents/Strona www/github-deploy/.local-cache/allegro-b13-candidate/reports/allegro-etap-b1.md`
- `C:/Users/Albert/Documents/Strona www/github-deploy/.local-cache/allegro-b13-candidate/reports/allegro-etap-b11-izolowany-release.md`
- `C:/Users/Albert/Documents/Strona www/github-deploy/.local-cache/allegro-b13-candidate/reports/allegro-etap-b12-release-bootstrap.md`
- `C:/Users/Albert/Documents/Strona www/github-deploy/.local-cache/allegro-b13-candidate/reports/allegro-etap-b13-main-reconciliation.md`

Trwałe dokumenty B/B.1/B.1.1/B.1.2 są historycznymi kontraktami i dowodami; B.1.3 wskazuje aktualne BASE, piny i snapshot. Nie nadpisywano starszych raportów, aby nie zmieniać historii audytu. Nowe wykonawcze zależności nie znajdują się w dokumentacji ani w zewnętrznym cache.

### WYKLUCZONE

Zastane raporty innych prac i archiwalne materiały Allegro poza release:

- `C:/Users/Albert/Documents/Strona www/github-deploy/reports/gbp-api-wniosek-2026-10-14.md`
- `C:/Users/Albert/Documents/Strona www/github-deploy/reports/gbp-media-diagnostics-2026-10-21.md`
- `C:/Users/Albert/Documents/Strona www/github-deploy/reports/google-merchant-naprawa-2026-10-18.md`
- `C:/Users/Albert/Documents/Strona www/github-deploy/reports/google-merchant-wdrozenie-2026-10-14.md`
- `C:/Users/Albert/Documents/Strona www/github-deploy/reports/google-showroom-wdrozenie-2026-10-15.md`
- `C:/Users/Albert/Documents/Strona www/github-deploy/reports/karty-produktow-propozycja-2026-10-09.md`
- `C:/Users/Albert/Documents/Strona www/github-deploy/reports/olx-audyt-2026-10-16.md`
- `C:/Users/Albert/Documents/Strona www/github-deploy/reports/olx-etap-b-wdrozenie-2026-10-20.md`
- `C:/Users/Albert/Documents/Strona www/github-deploy/reports/olx-etap-b1-hosting-2026-10-16.md`
- `C:/Users/Albert/Documents/Strona www/github-deploy/reports/allegro-audyt-etap-a.md`
- `C:/Users/Albert/Documents/Strona www/github-deploy/reports/allegro-etap-b-lokalnie.md`

Wykluczone są także całe .local-cache/publish, __pycache__, artefakty testowe, prywatne configi/klucze/tokeny, backupy, dane produkcyjne, uploads, nieprzejrzane pliki i wszystkie niepowiązane zmiany. Sam fakt umieszczenia kandydata w ignorowanym katalogu nie oznacza, że należy dodać ten katalog do Git oryginału. Przyszły commit powstaje dopiero w uzgodnionym lokalnym checkoutcie tego snapshotu, po zgodzie; bez zbiorczego git add oryginalnych raportów.

## L. Clean checkout — PASS

Utworzono jeszcze jedną niezależną lokalną kopię historii dokładnie na nowym BASE, następnie nałożono wyłącznie pliki listy K. Przed testami brakowało w niej .local-cache i publish; nie przeniesiono ignorowanych zasobów, prywatnych configów, vaultów, tokenów ani cache aplikacji. Jawnie wskazany portable PHP jest narzędziem testowym, nie źródłem aplikacji lub sekretu z cache.

Build uruchomiono od pustego katalogu. Syntetyczne configi/vaulty/sesje są tworzone dopiero przez testy w ich tymczasowych katalogach. Dokument B.1.3 dodano do obu kopii przy sporządzeniu raportu; nie jest zależnością wykonawczą. Weryfikacja kompletności wszystkich 29 plików i zgodności źródeł między kandydatem a kopią kontrolną przechodzi.

Nie tworzono commitu, więc nie istnieje nowy SHA wydania; test jest rekonstrukcją przyszłego drzewa z aktualnej bazy + zamkniętej listy zmian. W CI checkout ma fetch-depth=0 i dostęp do przejrzanego BASE. Brak dodatkowej nieśledzonej zależności spoza listy.

## M. Dwa niezależne buildy — PASS

W nowej czystej kopii zbudowano od zera `.local-cache/b13-build-a` oraz `.local-cache/b13-build-b`. Wszystkie **11 payloadów i ich hashe są identyczne**. Manifesty są identyczne po wyłączeniu jedynie created_utc. Zawierają nowy BASE jako lokalny HEAD, aktualny odcisk źródeł i niezmieniony profil OFF.

Manifest A SHA-256: **`935a4d892b4da37619406720c3ceeaafe1ef7f6f593bbdb7246131f8276a73cf`**.

Jest to manifest lokalny z niecommitowanego overlay, nie produkcyjny manifest przyszłego release. Przyszły CI musi wygenerować świeży manifest z prawdziwego nowego commitu i sprawdzić zgodność GITHUB_SHA/GITHUB_REF, źródeł, wersji narzędzi i artefaktu. LF normalizuje payload; raw source hashes i wersje patch narzędzi mogą różnić się w innym checkoutcie, więc lokalnego hasha całego manifestu nie używać jako spodziewanego hasha CI.

## N. Testy Allegro

W drugiej czystej kopii aktualnego main:

| Zestaw | Wynik |
|---|---|
| tests/allegro-oauth-tests.php | PASS — **197 asercji**, bez zmiany liczby |
| tests/allegro-http-tests.mjs | PASS — także realny izolowany save_product bez Allegro, współbieżny refresh, atomowe odczyty |
| tests/allegro-artifact-tests.mjs | PASS |
| tests/allegro-infra-release-tests.py | PASS — **18 testów** |
| tests/allegro-infra-release-tests.py --release .local-cache/b13-build-a | PASS — **18 testów** na gotowej paczce |
| Artifact/privacy test faktycznego runtime A | PASS |
| PHP lint siedmiu modułowych PHP i admin/index.php | PASS |
| PHP lint wszystkich ośmiu PHP paczki | PASS |
| node --check bridge.js źródła i payloadu | PASS |
| Python AST trzech plików release/tests | PASS |
| YAML wszystkich trzech workflow | PASS |
| bash -n wszystkich 36 bloków run | PASS — Allegro 12, pełny Getspace 21, OLX 3 |
| git diff --check kandydata i clean copy | PASS |

**17 → 18**: dodano `test_reconciliation_preserves_current_main_olx`. Sprawdza niezmienność kodu OLX, wszystkich sześciu jego testów, osobnego workflow/helpera, admin/lib i identity względem nowego BASE oraz wymaga całej regresji OLX w QUALIFY. Nie zmieniono testu OLX ani nie dopasowywano go do Allegro. 18 przypadków wykonano w dwóch profilach; nie są to 36 różne testy. **0 FAIL.**

Profil OFF przeszedł test z kompletną syntetyczną konfiguracją i obiema flagami env=1: brak Connect, brak auto-finish i kontrolowana odmowa connect/finish/check/callback; brak prawdziwych żądań token/me/sale. /sale pozostaje blokowane przez niezmienioną politykę B i frozen source. Żaden test nie użył credentiali produkcyjnych.

## O. Pełna regresja OLX AKTUALNEGO MAIN

Zidentyfikowano wszystkie sześć plików testowych OLX w drzewie origin/main. Każdy uruchomiono na kandydacie i ponownie w niezależnej czystej kopii. Nowy workflow Allegro QUALIFY również zawiera je wszystkie:

| Zestaw | Wynik | Zakres |
|---|---|---|
| tests/olx-oauth-tests.php | PASS | Wymiana syntetyczna, state, vault/refresh, nowe polityki/preflight |
| tests/olx-http-tests.mjs | PASS | Login/Strict/CSRF, callback/clean return, szkice/mapowanie, lista/statusy |
| tests/olx-identity-verification-tests.mjs | PASS | Brak podmiany/duplikacji ID i danych |
| tests/olx-preparation-tests.php | PASS | GET allowlist/cache, kategorie/atrybuty/cena/zdjęcia/szkice |
| tests/olx-publication-tests.php | PASS | Bariery duplikatów, lock, błędy/timeout bez retry, trwałość ID/status, lifecycle |
| tests/olx-publication-http-tests.mjs | PASS | Sesja/CSRF/nonce/finalne potwierdzenie, double click/replay, ignorowanie payloadu klienta |

**6/6 zestawów PASS, 0 FAIL.** Rzeczywiste OAuth, publikacja, /me i serwis OLX nie były wywoływane. Symulowane POST/GET są obsługiwane przez jawne atrapy w testach. Osobny produkcyjny helper OLX nie był uruchamiany. Jego kod, publikowanie, przygotowanie, mapowania i testy nie zostały zmienione względem main.

Dodatkowe porównanie 110 przejrzanych chronionych plików main (OLX/testy/helper, admin session/identity, data, uploads i shop-test) w obu kopiach: zgodność, bez cofnięcia treści. Minimalny diff admin/index jest jedynym wspólnym runtime dodatkiem poza regułą callbacku; zachowanie reszty main sprawdza również Git diff.

## P. Inne regresje

| Zestaw | Wynik |
|---|---|
| tests/product-id-migration-tests.php | PASS — tylko syntetyczne dane; bez migracji repo/produkcji |
| tests/admin-figure-entry-tests.php | PASS |
| tests/catalog-presentation-tests.php | PASS |
| tests/prerender-privacy-tests.mjs | PASS |
| tests/public-products-snapshot-tests.mjs | PASS |

W dziewięciu commitach wszystkie nowe testy to właśnie dodatkowa regresja OLX z sekcji O; nie pominięto nowego niezależnego zestawu z tego diffu. Nie przebudowywano całej witryny, kart produktów ani produkcyjnego katalogu — release wysyła wyłącznie 11 plików kodu. Data/productId/figury/Paynow/uploads nie były edytowane.

## Q. PHP 8.3 / Node 22 lokalnie

- **LOCAL PHP 8.3: NIEZWERYFIKOWANE.** Użyty dostępny portable PHP 8.5.11 z curl/sodium.
- **LOCAL NODE 22: NIEZWERYFIKOWANE.** Dostępny Node 24.22.1.
- Python 3.12.10 / lokalny PyYAML 6.0.3. Bez instalowania systemowo nowych wersji.

Nowy workflow nadal rzeczywiście ustawia PHP8.3 curl/sodium, Node22 i kontroluje ich major/minor; Python3.12, PyYAML==6.0.2. Rozszerzono wyłącznie listę lokalnych testów OLX. Kwalifikacja 8.3/22 w Actions jest osobną późniejszą bramką, jeszcze nie uruchomioną. Lokalnych PASS nie opisuje się jako PASS docelowego runtime.

## R. Blockery i granice decyzji

| Element | Status |
|---|---|
| B12-BASE-01 — starsza baza cofa nowe OLX | **ZAMKNIĘTY lokalnie**: nowy BASE, minimalny diff, przejrzane preimages/piny, pełna regresja |
| Brak kompletnego odtwarzalnego źródła na aktualnym main | **ZAMKNIĘTY dla kandydata**: lista 29, clean reconstruction i dwa buildy PASS |
| Rzeczywiste PHP8.3/Node22 w CI | Otwarte — qualify-only wymaga osobnej zgody i bootstrapu |
| Branch protection / required checks / chroniony environment | Nadal do potwierdzenia przed późniejszym bootstrapem/deployem; nie zmieniano ustawień |
| Bootstrap workflow na default branch | Nadal niewykonany; warunkowy wariant B z B.1.2 pozostaje planem |
| Getspace runtime/target/aliasy/Apache/backup i faktyczne preimages | Nadal do potwierdzenia przed przyszłym wdrożeniem; brak zdalnego odczytu FTPS |
| G16 oraz pozostałe bramki OAuth | Nadal otwarte; rzeczywisty OAuth zabroniony, pierwsza paczka OFF |

Kolejny pełny deploy Getspace nadal może użyć źródłowego loadera B zamiast profilu OFF. Samo uzgodnienie z main nie rozwiązuje polityki przyszłych pełnych wydań. Nie uruchamiać starego workflow ani nie publikować na main, dopóki zatwierdzony bootstrap i kontrola kolejnych deployów nie zostaną odrębnie uzgodnione. Nie wyłączano ani nie włączano workflow.

## S. Operacje zewnętrzne

| Operacja | Wykonano |
|---|---|
| fetch | TAK — wyłącznie git fetch origin main, odczyt |
| commit / git add | NIE |
| push / force-push | NIE |
| merge / rebase / reset / clean oryginału | NIE |
| PR / PR merge | NIE |
| Actions | NIE |
| workflow disable/enable | NIE |
| GitHub settings / branch protection / environment / secrets | NIE |
| FTPS / Getspace login | NIE |
| deploy / smoke produkcyjny | NIE |
| OAuth Allegro/OLX | NIE |
| rzeczywiste /me | NIE |
| rzeczywiste /sale | NIE |
| OLX produkcyjny zmieniony | NIE |

Nowe lokalne źródła zmieniono wyłącznie w izolowanym kandydacie, a następnie skopiowano jawny release set do drugiej lokalnej kopii. Oryginalny worktree zachowano. Nie tworzono zdalnej gałęzi ani nie wykonywano dodatkowych operacji na serwisach.

## T. DECYZJA

### Czy blocker B12-BASE-01 został zamknięty?

**TAK — dla nowego lokalnego kandydata opartego na przejrzanym aktualnym main `df4c32c`.**

### Czy aktualny release zachowuje cały nowszy OLX?

**TAK.** Kod i testy OLX, osobny workflow/helper, przygotowanie/publikacja/statusy i nowszy blok wspólnego panelu zachowane; 6/6 zestawów PASS. Nie jest to test prawdziwego konta produkcyjnego.

### Czy release na aktualnym main jest kompletny i gotowy do przyszłego commitu?

**TAK — lokalny snapshot kandydata z listy K**, po osobnym zatwierdzeniu właściciela. Pełna kwalifikacja CI 8.3/22 oraz bramki bootstrap/deploy pozostają osobnymi, niewykonanymi krokami. Oryginalny brudny katalog nie stał się przez to aktualną bazą wydania.

### Czy można już wykonywać commit/push?

**NIE — potrzebna osobna zgoda właściciela.**

### Czy można wykonywać Actions qualify-only?

**NIE — osobna zgoda po zatwierdzeniu release i przygotowaniu bezpiecznego bootstrapu.**

### Czy można deployować?

**NIE.**

### Czy można wykonać OAuth?

**NIE.**

Etap B.1.3 kończy się tym raportem. Nie wykonano bootstrapu ani operacji produkcyjnych.
