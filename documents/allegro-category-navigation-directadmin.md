# Allegro — naprawa wyboru kategorii wielu figur

Baza: aktualny main `7f8189a526b15fba1e9326f8b27ae9aa94180535`.

## Przyczyna i poprawka

POST `pilot-load` prawidłowo zapisywał `allegro_pilot_categories` w sesji i przekierowywał 303 na `/admin/allegro/?productId=…`. Renderer usuwał jednak lokalny widok nawigacji, gdy nie było jeszcze końcowych metadanych (`!$ready`). Wybór kategorii z definicji rozpoczyna się bez takich metadanych. Błąd potwierdzono na kodzie bazowym przez zmianę testu HTTP tak, aby podążał za rzeczywistym Location: test kończył się brakiem „Kategorie główne”. Dotychczasowy test po POST otwierał czysty URL i omijał problem przekierowania.

Renderer sprawdza teraz osobno powiązanie productId metadanych i nawigacji. Nawigacja właściwego produktu pozostaje widoczna również przed wyborem kategorii końcowej. Nawigacja innej figury jest ukrywana bez usuwania sesji. Dodatkowy warunek wymaga zgodności wyświetlanej nawigacji z aktualnie wybranym produktem. Przy błędzie POST renderer uwzględnia również przesłany product_id, aby nie pokazać kategorii poprzedniej figury.

Stan sesji jest wymieniany dopiero po poprawnym pobraniu kategorii. Brak dostępnych kategorii daje czytelny błąd zamiast sukcesu. HTTP 500 i niepoprawny JSON również kończą operację błędem, pozostawiając ostatni potwierdzony stan. Komunikaty powodzenia rozróżniają pobranie listy kategorii i wybór kategorii końcowej.

Nie zmieniono formatu danych, sejfu, OAuth, OLX, katalogu, tworzenia szkicu ani publikowania. Zachowano poprawkę odpowiedzi 202/303. Przełączenie produktu przez rozpoczęcie nowego wyboru zastępuje dotychczasowy roboczy stan kategorii; nie tworzymy osobnych sesyjnych edytorów dla każdej figury. Samo przejście GET do innej figury nie usuwa rozpoczętego wyboru. Nowy wybór jest zawsze przypisany do wskazanego productId, a odświeżenie i powrót do panelu w tej samej sesji go zachowują.

## Testy i wyniki

`node tests/allegro-http-tests.mjs`: PASS. Rzeczywisty lokalny serwer PHP, sesje/cookie, POST formularza, odpowiedź 303 i GET z faktycznego Location. Odpowiedzi Allegro są symulowane; rzeczywisty curl_exec jest wyłączony, transport kategorii odrzuca metody inne niż GET.

Scenariusz obejmuje:

1. GET panelu i wybór nowej figury „Rzeźba ogrodowa Rozdarta twarz kobiety z miejscem na doniczkę – szaro-brązowa”.
2. POST pilot-load, 303, GET z productId i listę kategorii głównych; potwierdzenie productId, środowiska i sprzedawcy w sesji.
3. Odświeżenie URL z productId i ponowne wejście na czysty `/admin/allegro/`.
4. Wybór kategorii głównej, podkategorii i końcowej, a następnie metadane właściwego produktu.
5. Przełączenie GET do pierwszej figury i powrót, bez pokazania kategorii drugiej figury; przełączenie również przez nowy POST pilot-load.
6. Odrzucenie nonce i kategorii pochodzących z wyboru innego produktu, bez sukcesu i bez zmiany potwierdzonej sesji.
7. Symulowane HTTP 500, niepoprawny JSON i pustą listę kategorii: 400, czytelny błąd, brak zielonego komunikatu i zachowane metadane.
8. Przygotowanie lokalnego podglądu drugiej figury i dostępność przycisku „Utwórz szkic”, bez jego wywołania.
9. Pierwsza oferta ma w danych syntetycznych ID **19082641588**, status **ACTIVE** i historię. Przed i po całym przepływie szyfrogram sejfu jest identyczny: oferty, tokeny i historia nie zmieniają się. Ten test nie odczytuje prywatnego sejfu produkcyjnego.

Regresja PASS:

- `php tests/allegro-operation-tests.php` — 560 asercji;
- `php tests/allegro-pilot-tests.php` — 350 asercji;
- `php tests/allegro-oauth-tests.php` — 276 asercji;
- `php tests/admin-marketplace-tests.php` — 50 asercji;
- `php tests/admin-marketplace-sync-tests.php` — 66 asercji;
- `node tests/admin-marketplace-http-tests.mjs`;
- `node tests/allegro-artifact-tests.mjs`;
- kontrola składni obu zmienionych plików PHP i `git diff --check`.

Opcjonalna gałąź Playwright nie była uruchamiana. Historyczne ograniczenia testów release opisane wcześniej w `reports/allegro-multi-offers-tests.md` pozostają poza zakresem tej poprawki. Nie wykonano rzeczywistych POST/PATCH do Allegro, deploya ani FTPS.

## Minimalny pakiet DirectAdmin

| Plik z ZIP | Dokładny cel w Menedżerze plików DirectAdmin |
| --- | --- |
| upload/admin/allegro/lib.php | domains/mgoutlet.pl/public_html/admin/allegro/lib.php |
| upload/admin/allegro/index.php | domains/mgoutlet.pl/public_html/admin/allegro/index.php |

Wybierz domenę mgoutlet.pl. Jeżeli Menedżer plików otwiera od razu public_html, ścieżki od tego miejsca to `admin/allegro/lib.php` i `admin/allegro/index.php`. Prefiks systemowy `/home/<login>/` zależy od konta hostingowego, którego loginu repozytorium nie podaje.

1. Zakończ aktywne żądania administratorów Allegro. Zachowaj prywatne kopie aktualnych dwóch plików serwerowych poza public_html.
2. Wgraj `lib.php`, następnie `index.php`, zachowując uprawnienia. Nie rozpakowuj całej paczki w public_html. Nie nadpisuj konfiguracji ani sejfu.
3. Po ręcznym wgraniu wybierz drugą figurę, kliknij „Wybierz kategorię Allegro” i przejdź do kategorii końcowej. Odśwież stronę, wróć do panelu, przełącz produkt i sprawdź Anioła ACTIVE / 19082641588. Można przygotować lokalny podgląd; nie używaj przycisków tworzenia i publikacji do testu wdrożenia.

## Rollback

Po zakończeniu aktywnych żądań przywróć oba pliki z prywatnej kopii serwera. Paczka zawiera też `rollback/admin/allegro/lib.php` i `rollback/admin/allegro/index.php` z commita bazowego 7f8189a — ich cele są identyczne jak w tabeli. Użyj ich, jeśli serwer odpowiadał tej bazie. Rollback zachowuje obsługę wielu ofert i naprawę 202/303, ale przywraca błąd wyboru kategorii. Nie cofaj account.enc, historii, tokenów ani danych katalogu. Przy błędzie sesji po rollbacku rozpocznij wybór kategorii ponownie; nie resetuj sejfu i nie ponawiaj publikacji.

`manifest.json` zawiera commit źródłowy oraz SHA256 plików aktualizacji i rollbacku. Pakiet jest sprawdzony przez ponowny odczyt wszystkich wpisów i CRC ZIP-a.
