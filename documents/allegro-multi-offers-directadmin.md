# Allegro: wiele figur — ręczna aktualizacja DirectAdmin

Baza: `f678c31bab176e97fe56e591079041c1fcd1fe34`, main. Pakiet jest poprawką pięciu plików PHP, bez pełnego deploya i bez FTPS. Nie zawiera katalogu produktów, zdjęć, konfiguracji prywatnej ani tokenów.

## Co się zmienia

Sejf zachowuje dotychczasowe szyfrowanie, blokadę konta i atomowy zapis. `offersVersion=2` oraz `offers[productId]` rozdzielają offerId, payload, status, operację asynchroniczną, nonce odzyskiwania i blokadę publikacji każdego produktu. Stary `pilot` pozostaje niezmienną kopią historyczną; `pilotFailures` zachowuje dotychczasowe wpisy i przypisanie do produktu. Nie należy już traktować `pilot` jako aktualnego statusu.

Migracja jest lokalna, deterministyczna i idempotentna: odczyt starego sejfu tworzy reprezentację v2 w pamięci; pierwszy normalny zapis utrwala ją pod istniejącą blokadą. GET panelu nie zapisuje migracji. Brak wywołań API w migracji. Nieznany format, niezgodny productId i współdzielony offerId są odrzucane. Nie ma nadpisywania starego rekordu rekordem innej figury. Limit sejfu wynosi 8 MiB plus narzut szyfrowania; przed utworzeniem oferty pozostaje rezerwa na wynik i odzyskiwanie.

„Anioł z rozłożonymi skrzydłami – 75 cm” zachowuje wszystkie istniejące pola i identyfikatory. Prace nie odczytywały prywatnego sejfu produkcyjnego; zachowanie zweryfikowano na szyfrowanych danych syntetycznych. Kod nie zmienia istniejących ofert przy migracji ani wyborze innego produktu. Dotychczasowe potwierdzenia, kategorie, parametry, GPSR, upload zdjęć, kontrola duplikatów, INACTIVE, publikacja i odzyskiwanie wyniku pozostają w istniejącym procesie. Odłączenie konta zachowuje powiązania i historię v2; nie zmieniono sposobu autoryzacji ani odnawiania tokenów.

## Dokładne ścieżki

W Menedżerze plików DirectAdmin wybierz domenę **mgoutlet.pl**, jej katalog `domains/mgoutlet.pl/public_html`. Jeśli panel otwiera od razu `public_html`, poniższe ścieżki licz od tego katalogu. Prefiks systemowy `/home/<login>/` zależy od konta hostingowego i nie jest zapisany w repozytorium.

| Plik z pakietu | Cel w DirectAdmin |
| --- | --- |
| upload/admin/allegro/storage.php | domains/mgoutlet.pl/public_html/admin/allegro/storage.php |
| upload/admin/allegro/oauth.php | domains/mgoutlet.pl/public_html/admin/allegro/oauth.php |
| upload/admin/allegro/lib.php | domains/mgoutlet.pl/public_html/admin/allegro/lib.php |
| upload/admin/marketplace-status.php | domains/mgoutlet.pl/public_html/admin/marketplace-status.php |
| upload/admin/allegro/index.php | domains/mgoutlet.pl/public_html/admin/allegro/index.php |

## Kolejność ręcznego wgrania

1. Zakończ aktywne operacje administratorów w Allegro i synchronizację marketplace; nie wymieniaj bibliotek podczas trwających żądań PHP. Zachowaj prywatną kopię pięciu aktualnych plików serwera poza public_html. Nie zapisuj kopii prywatnego sejfu w katalogu publicznym.
2. Wgraj `rollback-pause/admin/allegro/index.php` na `domains/mgoutlet.pl/public_html/admin/allegro/index.php`. To uwierzytelniona przerwa techniczna HTTP 503 bez odczytu/zapisu sejfu i bez wywołań API. Upewnij się, że wcześniejsze żądania skończyły pracę.
3. Wgraj cztery biblioteki z tabeli w podanej kolejności, a **na końcu** `upload/admin/allegro/index.php`. Nie rozpakowuj całego ZIP-a do public_html. Pozostaw dotychczasowe uprawnienia plików.
4. Sprawdź panel po zalogowaniu: pierwsza oferta ma te same productId i offerId, status i link. Otwórz inną figurę: stan podsumowania musi dotyczyć jej productId. Powrót do Anioła ma pokazać jego zapis. Te kontrole GET nie publikują i nie utrwalają migracji. Nie używaj przycisku publikacji do testu wdrożenia.
5. Przy pierwszym późniejszym, świadomym zapisie panelu migracja utrwali się automatycznie. Nie uruchamiaj osobnego skryptu na produkcyjnym sejfie. `manifest.json` zawiera SHA256 plików i commit źródłowy.

## Rollback bez utraty nowych powiązań

W każdej chwili można przywrócić przerwę techniczną, wgrywając **wyłącznie** `rollback-pause/admin/allegro/index.php` na `domains/mgoutlet.pl/public_html/admin/allegro/index.php`. Pozostaw biblioteki v2 i bieżący sejf. Weryfikacja: po zalogowaniu GET oraz POST `/admin/allegro/` zwracają 503; katalog publiczny i OLX działają jak wcześniej. Przywrócenie panelu: ponownie wgraj `upload/admin/allegro/index.php`.

To celowo rollback do bezpiecznego wstrzymania modułu. Stary kod pilota nie rozumie wielu ofert i przy odłączeniu konta mógłby usunąć nowe powiązania. `reference-original-do-not-upload/` zawiera oryginalne pliki commita bazowego wyłącznie do porównania. Nie wgrywaj ich po utrwaleniu v2 i nie przywracaj starego `account.enc`: cofnęłoby to historię, blokady operacji oraz potencjalnie rotowane tokeny. Po utworzeniu nowych ofert ewentualny powrót do starego kodu wymaga osobnej migracji wstecz i uzgodnienia bieżących ofert; ten pakiet jej nie wykonuje.

## Testy i ograniczenia

Wyniki szczegółowe: `reports/allegro-multi-offers-tests.md`. Wszystkie operacje sprzedażowe testowano przez transport syntetyczny; testy HTTP miały wyłączony rzeczywisty transport. Nie wykonano publikacji, FTPS ani wdrożenia. Publiczną migawkę pobrano jedynie do lokalnej regresji.

Odtworzenie paczki lokalnie: `python scripts/package-allegro-multi-offers.py --output <nowy-katalog-poza-public_html>`.
