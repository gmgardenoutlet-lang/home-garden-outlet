# Marketplace — zwijana lista Allegro i zakres liczników

Baza: `bfd658265a1f4dd68d35944730a3f8bdf4e82e41`. Commit źródłowy pakietu i SHA256 każdego pliku są w `manifest.json`.

## Zachowanie

- „Moje oferty Allegro (liczba)” obejmuje zapisy istniejącego sejfu. Sekcja jest zwinięta; lista po rozwinięciu ma maksymalnie 220 px wysokości i wyszukiwanie po nazwie / ID. GET i filtr nie resetują metadanych, kategorii, podglądu ani ofert.
- Sekcja „Marketplace — Allegro i OLX” pokazuje liczbę ofert i grupy statusów. Zakres obok każdej liczby wyjaśnia, czy są to zapisy lokalne, częściowe wyniki API, czy zakończony odczyt konta. Sumy nie oznaczają liczby produktów katalogu.
- Allegro: grupowanie productId i walidacja całej paginacji pozostają zachowane. Osobno liczone są faktycznie zwrócone oferty ACTIVE, INACTIVE, ACTIVATING i ENDED. Niepełny / niejednoznaczny wynik zachowuje poprzedni potwierdzony status na karcie, z ostrzeżeniem.
- OLX: dotychczasowy odczyt pojedynczych ogłoszeń obejmuje wyłącznie lokalne publikacje. Po nim następuje odczyt `GET /adverts`, `limit=50`, `offset`, po jednej stronie na ręczne kliknięcie. Koniec potwierdza pusta strona, nie sama krótka odpowiedź. Powtórzone ID / błędna struktura / HTTP 429 pozostawiają pełną liczbę nieustaloną. Jedno kliknięcie nadal wykonuje najwyżej jeden GET OLX i jeden GET Allegro; istniejące odnawianie tokenów pozostaje bez zmian.
- Inwentaryzacja konta OLX liczy wszystkie ogłoszenia zwrócone przez API i osobno powiązane: unikatowe lokalne advert_id albo dokładne external_id równe trwałemu productId. Sprzeczne identyfikatory nie tworzą powiązania. Brak podobieństwa nazwy / ceny nie jest wykorzystywany. Inwentaryzacja nie dopisuje rekordów publikacji i nie wybiera oferty do karty na podstawie domysłu. Karty nadal odświeżają dotychczasowe powiązania publikacji.
- Lista konta OLX zapisuje wyłącznie ID, znormalizowaną grupę statusu i potwierdzony productId albo null. Treść, kontakt, tokeny i odpowiedzi API nie trafiają do cache / logów. Unknown z nowej wersji statusu API jest zliczany jako nieustalony. Statusy moderacji / usunięcia należą w podsumowaniu konta do nieaktywnych; karty zachowują szczegółowe oznaczenia.
- Postęp główny wskazuje sprawdzone lokalne ogłoszenia i znalezione oferty. Liczba przeszukanych produktów oraz błędy są w „Szczegółach synchronizacji”. Przed końcem paginacji nie ma deklaracji pełnej liczby.
- Stary cache schema=1 pozostaje obsługiwany, bez usuwania statusów. Rozpoczęta synchronizacja starego formatu kończy się bez dodawania nowych operacji w jej środku. Pełne nowe podsumowanie i inwentaryzacja konta są dostępne od następnego pełnego ręcznego odświeżenia. Ostatni ukończony odczyt konta pozostaje zachowany po błędzie nowego przebiegu i jest oznaczony datą.
- Licznik zmian porównuje status, nie samo pierwsze wyszukanie lub znalezienie ID. Nieustalony → nieustalony i identyczny status nie zwiększają licznika.

Nie wykonywano odczytu konta produkcyjnego w tej pracy. Liczba 20 w testach jest symulacją, a nie deklaracją faktycznej liczby ogłoszeń właściciela. Rzeczywista liczba pojawi się po ręcznym wdrożeniu i zakończeniu odczytu API; brak dostępu do listy konta pokaże jawny zakres lokalny / nieustalony. Nie zmieniono OAuth, scope, tokenów, formatów sejfu / publikacji, nonce, zabezpieczeń duplikacji ani obsługi HTTP 202/303. Nie wykonano publikowania, kończenia ani edycji ofert.

Dokumentacja odczytu listy i paginacji: [OLX Partner API — Get user adverts](https://developer.olx.pl/api/doc#tag/Adverts/paths/~1adverts/get). Istniejący helper `olx_advert_http()` już dopuszcza ten GET i parametry limit / offset; nie zmieniano allowlisty transportu ani uprawnień.

## Testy

PASS:

- marketplace sync: **108 asercji** — 40 produktów / 2 oferty Allegro, 20 ogłoszeń OLX, sumy statusów, powiązane / niepowiązane, brak lokalnych publikacji, niepełne strony, duplikaty, 429, wznowienie, zachowanie starego cache i statusu ACTIVE;
- marketplace projection: **50 asercji**;
- Allegro operation 202/303: **560 asercji**;
- Allegro per-product pilot: **350 asercji**;
- Allegro OAuth: **276 asercji**;
- HTTP panelu marketplace, Allegro oraz 6 zestawów OLX;
- przeglądarka: listy 2 / 20 / 100 ofert, zamknięty details, wyszukiwanie, ograniczenie wysokości, zachowanie formularza i szyfrogramu; desktop 1366 / 1920 i mobile 390, bez przepełnienia poziomego;
- artifact privacy, public products snapshot, prerender privacy; PHP lint, JavaScript syntax, git diff --check.

Wszystkie odpowiedzi marketplace były symulowane; nie wywoływano prawdziwych API kont ani operacji sprzedażowych. Historyczne blokady starych builderów release opisane w `reports/allegro-multi-offers-tests.md` pozostają bez zmian. Nie autoryzowano pełnej ścieżki deploya i nie aktualizowano jej hashy.

## Ręczny pakiet DirectAdmin

Wyłącznie cztery pliki runtime:

| Plik w upload | Docelowa ścieżka |
| --- | --- |
| admin/allegro/lib.php | /domains/mgoutlet.pl/public_html/admin/allegro/lib.php |
| admin/marketplace-sync.php | /domains/mgoutlet.pl/public_html/admin/marketplace-sync.php |
| admin/style.css | /domains/mgoutlet.pl/public_html/admin/style.css |
| admin/index.php | /domains/mgoutlet.pl/public_html/admin/index.php |

Przed nadpisaniem zachowaj prywatne kopie rzeczywistych czterech plików serwera poza public_html. Pakiet rollback zawiera wersje z bazy repozytorium, a nie odczytane z hostingu. Jeśli pliki serwera różnią się od bazowych SHA256 w manifeście, zatrzymaj nadpisywanie i wyjaśnij drift.

Zakończ aktywne żądania panelu, nie uruchamiaj równolegle publikacji ani synchronizacji. Wgraj lib.php, style.css, marketplace-sync.php, a admin/index.php na końcu. Nie przesyłaj testów, dokumentów, manifestu ani ZIP-a do public_html. Nie wgrywaj plików katalogu produktów, prywatnego configu, sejfu, cache ani publication JSON. Pierwszy podgląd GET nie uruchamia synchronizacji.

Rollback: przywróć najpierw bazowy admin/index.php, potem pozostałe trzy pliki z rollback. Nie usuwaj prywatnego cache, sejfów ani zapisów ofert. Zmiana nie wymaga migracji danych. Nie przywracaj starszych wersji sprzed wieloofertowego modułu / poprawki 202/303.

Nie wykonano deploya, FTPS, dispatch Actions ani żadnego zapisu produkcyjnego. Pełny Deploy to Getspace ma pozostać wyłączony.
