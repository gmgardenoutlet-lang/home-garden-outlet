# Figury ogrodowe — automatyczny katalog Google

Źródło prawdy: produkcyjne `data/products.json` oraz `data/shipping-profiles.json`, edytowane w obecnym panelu PHP na Getspace. Repozytoryjny katalog nie jest aktualnym asortymentem. Feed nie używa migawki ani cache i nie zawiera mebli ani notatek wewnętrznych.

Docelowy adres po wdrożeniu: `https://mgoutlet.pl/google-merchant-feed.xml`.

## Uruchomienie

1. W panelu, na liście figur, wybierz pochodzenie nazw/opisów. Dla figur przygotowanych przez Codexa wybierz AI. „Przygotuj pierwsze 3 figury” zapisuje potwierdzone dane: nowy stan, brak nadanych identyfikatorów, wysyłka od producenta oraz stałe losowe ID. Nie zmienia ceny, opisu ani dostawy. Przygotowanie wymaga zalogowania i CSRF.
2. Sprawdź feed i karty tych trzech produktów. W sekcji kontroli publikacji zobaczysz konkretne braki. Następnie „Przygotuj obecne figury” obejmuje pozostałe obecne rekordy. Nowych produktów nie obejmuje automatycznie stara deklaracja właściciela.
3. Konto Merchant Center 5858074999 → Ustawienia → Źródła danych → Dodaj źródło produktów → plik pod adresem URL. Kraj Polska, język polski, bezpłatne oferty; bez reklam i kampanii.
4. Użyj jednego podstawowego źródła XML. Wybierz codzienne pobieranie, np. 06:00 Europe/Warsaw, lub najczęstszy dostępny harmonogram. Nie dodawaj wszystkich produktów znalezionych przez Google. Nie importuj i nie przywracaj dawnych ofert. Nie usuwaj starego źródła na ślepo: sprawdź, które rekordy są archiwizowane i czy nie dublują obecnych figur.
5. Zweryfikuj/zgłoś domenę i dokończ pozostałe zadania konta. Feed przekazuje ceny rzeczywistych metod dostawy dla każdego produktu w Polsce, pomijając odbiór osobisty i metody wymagające wyceny lub ceny „od”. Terminy i zasady zwrotów ustaw na podstawie strony oraz potwierdzonych warunków firmy, bez fikcyjnych czasów transportu.

## Codzienna obsługa

- Przy dodawaniu figury zaznacz publikację w Google, potwierdź stan, sposób realizacji, identyfikatory i pochodzenie treści. Używaj rzeczywistego koloru i wspólnej grupy tylko dla wariantów tego samego modelu.
- Cena Google pochodzi z „Cena brutto w sklepie”. Zmiana w panelu zmienia feed przy następnym odczycie.
- Przy sprzedaży egzemplarza użyj „Sprzedane”. W sprzedaży od producenta zwykłe zamówienie nie oznacza wyczerpania całej oferty. Gdy producent nie ma towaru, zmień „Dostępność” z dokładnej wartości „Dostępny u producenta” na informację o braku. Rezerwacja i sprzedaż dają `out_of_stock`; ukrycie, wyłączenie sklepu lub odznaczenie publikacji wycofuje produkt z feedu.
- Nie zgaduj identyfikatorów. Brak EAN nie oznacza automatycznie braku marki/MPN. Stan outletowy nie jest automatycznie nowy.
- Sklep nie prowadzi ilości własnego magazynu i nie odejmuje ich przy zamówieniach; zgodnie z potwierdzonym modelem jest to dostawa bezpośrednio od producenta. Przy przejściu na własny magazyn potrzebna będzie obsługa ilości i rezerwacji przed publikacją takich ofert.

Feed reaguje od razu po zapisie, ale Google pobiera i przetwarza go według swojego harmonogramu. Przy pobieraniu raz na dobę opóźnienie może wynieść do około doby plus czas przetwarzania. Usuwanie pominiętych ofert zależy od przetworzenia nowego pełnego źródła. W pilnych przypadkach uruchom ręczne pobranie źródła.

## Meble lokalne

Meble wymagające kontaktu lub zakupu w showroomie nie trafiają do tego feedu online. Polska jest objęta ofertami lokalnymi, lecz dostępne warianty funkcji trzeba potwierdzić dla tego konta. Potrzebna jest zweryfikowana wizytówka, kod sklepu i osobne dane lokalnej dostępności. Polska ma ograniczenia niektórych funkcji odbioru i stron lokalnych w bezpłatnych ofertach. Feed online nie synchronizuje ręcznych produktów w wizytówce Google.

## Oficjalne źródła

- https://support.google.com/merchants/answer/7052112 — specyfikacja, identyfikatory, warianty, treści przygotowane przez AI.
- https://support.google.com/merchants/answer/6324478 — identifier_exists.
- https://support.google.com/merchants/answer/14991445 — harmonogram pobierania.
- https://support.google.com/merchants/answer/3271956 — oferty lokalne, Polska.
- https://support.google.com/merchants/answer/16761172 — ograniczenia odbioru w ofertach lokalnych.

Ten dokument opisuje konfigurację, nie stanowi dowodu wdrożenia ani zatwierdzenia produktów przez Google. Wynik wykonania jest w raporcie z danej sesji.
