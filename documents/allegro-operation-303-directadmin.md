# Allegro: odczyt operacji 202/303 — poprawka ręczna

Baza: main `bebdff94af55280dc653b23656b88e86cf355693` (z zachowaniem wielu ofert).

## Zmiana

`allegro_pilot_api()` rozpoznaje dokładny endpoint GET `/sale/product-offers/{offerId}/operations/{operationId}`. Odpowiedzi 202 i 303 nie wymagają treści JSON. 303 wymaga Location wskazującego dokładnie `/sale/product-offers/{offerId}`: dozwolona jest ścieżka względna od korzenia lub bezwzględny adres HTTPS API Allegro właściwy dla środowiska PROD/SANDBOX. Inne domeny, inne środowisko, identyfikator, query string i fragment są odrzucane. Nie ma automatycznego podążania za przekierowaniem.

202 pozostawia operację i status, aktualizując wyłącznie termin następnego sprawdzenia zgodnie z dotychczasowym Retry-After. Opcjonalne Location 202 musi wskazywać tę samą operację. Po 303 wykonywany jest jawny GET oferty z oczekiwanym offerId. Dopiero prawidłowa odpowiedź 200 z zgodnymi id i external.id oraz statusem ACTIVE, INACTIVE, ACTIVATING lub ENDED pozwala usunąć operację i zapisać rzeczywisty status.

Obserwacja korzysta z kopii rekordu: przy błędzie potwierdzenia nie zmienia nawet rekordu przekazanego przez referencję. Sprawdzenie statusu nie wykonuje POST ani PATCH, nie tworzy oferty, nie zmienia innych ofert ani historii. Mechanizmy OAuth i sejfu pozostają bez zmian.

Dokumentacja producenta: [sprawdzanie przetwarzania operacji](https://developer.allegro.pl/documentation#operation/getProductOfferProcessingStatus), [przykład odpowiedzi 303](https://developer.allegro.pl/tutorials/jak-jednym-requestem-wystawic-oferte-powiazana-z-produktem-D7Kj9gw4xFA).

## Pakiet i dokładne ścieżki

Pakiet ma jeden plik runtime oraz jego oryginał z powyższej bazy. Nie zawiera danych produkcyjnych ani konfiguracji. `manifest.json` zawiera commit źródłowy i SHA256.

| Operacja | Plik z paczki | Cel w Menedżerze plików DirectAdmin |
| --- | --- | --- |
| Aktualizacja | upload/admin/allegro/lib.php | domains/mgoutlet.pl/public_html/admin/allegro/lib.php |
| Rollback | rollback/admin/allegro/lib.php | domains/mgoutlet.pl/public_html/admin/allegro/lib.php |

Wybierz domenę mgoutlet.pl i katalog public_html. Jeśli panel otwiera od razu public_html, celem jest `admin/allegro/lib.php`. Systemowy prefiks `/home/<login>/` zależy od konta hostingowego; repozytorium nie podaje loginu.

1. Zakończ aktywne żądania administratorów Allegro i synchronizacji. Zachowaj prywatną kopię aktualnego serwerowego lib.php poza public_html.
2. Wgraj wyłącznie lib.php z `upload/` pod wskazaną ścieżkę, zachowując uprawnienia. Nie rozpakowuj całego archiwum w public_html.
3. Po wdrożeniu można świadomie użyć „Sprawdź końcowy status / odzyskaj ID” dla istniejącej oferty 19082641588. Jest to odczyt GET; nie używaj przycisków tworzenia ani publikacji do weryfikacji. Identyfikatory innych ofert muszą pozostać bez zmian. Poprawka nie była testowana na prywatnym sejfie ani rzeczywistym API konta.
4. Rollback: po zakończeniu aktywnych żądań przywróć prywatną kopię serwerowego lib.php albo plik `rollback/` (jeśli serwer odpowiadał wskazanej bazie). To przywraca kod sprzed poprawki, nadal obsługujący wiele ofert; przywraca też znany błąd pustego 303. Nie cofaj account.enc, tokenów, konfiguracji ani historii. Po rollbacku nie ponawiaj tworzenia oferty — poprawka nie tworzy żadnego nowego szkicu.

Nie wykonano deploya ani FTPS. Ręczne wgranie pozostaje osobną czynnością.

## Testy

- `php tests/allegro-operation-tests.php`: PASS, **560 asercji**, wyłącznie syntetyczne GET. Puste 202 i 303; Location względne i HTTPS; obca domena i środowisko; inne offerId i operationId; brak/nieprawidłowy Location; nietypowe statusy HTTP; niezgodne offerId/productId; wszystkie cztery rzeczywiste statusy; zachowanie innych ofert, tokenów i historii; przy błędach identyczny szyfrogram i niezmieniony rekord w pamięci.
- `php tests/allegro-pilot-tests.php`: PASS, 350 asercji.
- `php tests/allegro-oauth-tests.php`: PASS, 276 asercji.
- `php tests/admin-marketplace-tests.php`: PASS, 50 asercji.
- `php tests/admin-marketplace-sync-tests.php`: PASS, 66 asercji.
- `node tests/allegro-http-tests.mjs`, `node tests/admin-marketplace-http-tests.mjs`, `node tests/allegro-artifact-tests.mjs`: PASS. Testy HTTP korzystają z izolowanych danych i wyłączonego rzeczywistego transportu.
- Kontrola składni zmienionych PHP oraz `git diff --check`: PASS.

Nie wykonano rzeczywistych operacji publikacji ani odczytu wskazanego zasobu produkcyjnego. Dotychczasowe ograniczenia historycznych testów release opisane w `reports/allegro-multi-offers-tests.md` pozostają bez zmian; nie zmieniano tych testów ani polityk deploya.
