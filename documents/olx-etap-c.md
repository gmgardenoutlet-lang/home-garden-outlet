# ETAP C OLX — przygotowanie, bez publikacji

Specyfikacja: oficjalne `https://developer.olx.pl/swagger/v2/partner_api.yaml`, pobrane 26.10.2026. GET categories, categories/{id}, attributes, suggestion?q, cities?offset&limit, cities/{id}, cities/{id}/districts, currencies. Każdy rzeczywisty GET ma Bearer i Version 2.0. Brak funkcji wysyłającej payload ogłoszenia. Transport konfiguracji ma stałą metodę GET i zamkniętą listę ścieżek; adverts, konta, URL zewnętrzny lub nieznane parametry są odrzucane przed transportem.

Kategorie mają czytelną ścieżkę i wybieralne wyłącznie leaf. Niepełne drzewo jest uzupełniane przez parent_id. Sugestia jest pokazywana dopiero na żądanie dla wybranego produktu i nie wybiera kategorii. Zatwierdzenie kategorii szkicu jest osobnym formularzem i czyści parametry poprzedniej kategorii.

Prywatne pliki w istniejącym `private_olx/storage`:

- `cache/*.json`: odpowiedzi konfiguracji, TTL 24h, strony miast 7 dni, SHA256 ścieżki i query w nazwie; bez sekretów i tokenów.
- `settings/mapping.json`: zatwierdzone mapowania kategorii i domyślna lokalizacja.
- `drafts/prd_….json`: oddzielne ustawienia pojedynczego produktu, bez advert_id; klucz tylko trwały productId.
- istniejące `account.enc` i klucz szyfrowania pozostają nietknięte przez ETAP C.

Katalogi 700, pliki JSON 600, atomowy zapis przez temp i rename, zakaz symlinków, walidacja ścieżek poza webrootem. Podgląd, konfiguracja i każdy formularz wymagają logowania; każdy zapis formularza wymaga CSRF. Biblioteka jest blokowana zarówno przez .htaccess, jak i przez bezpośredni guard PHP. Nie ma endpointu zapisującego produkty.

Walidacja obejmuje tytuł 16–150, opis 80–9000, powtarzaną interpunkcję, wielkie litery, możliwe telefony/e-maile, zwykły tekst, cenę outletPrice z możliwością niezależnej zmiany, walutę z API, kategorię leaf, wymagane atrybuty, enum, numeric, boolean, multi, jednostki i granice. Nierozpoznane ograniczenia nie pozwalają uznać wypełnionego parametru za poprawny. Typ salary/delivery poza zakresem tego sklepu wymaga osobnej obsługi i nie jest po cichu pomijany po wypełnieniu.

Nie skracamy tytułów, nie dobieramy stanu, nie wymyślamy materiału. Dane źródłowe stanu/materiału/koloru są pokazane do ręcznego porównania. Wybrane zdjęcia muszą należeć do galerii tego produktu. Pierwsze wybrane jest głównym; nadmiar ponad photos_limit blokuje walidację, nie usuwa zdjęć. Początkowo żadne zdjęcie nie jest zaznaczone.

Lokalizacja nie ma zakodowanego ID. Panel pobiera stronicowany słownik miast i filtruje nazwy lokalnie. Każde wskazane city_id jest ponownie sprawdzane przez API wraz z dzielnicami. Domyślny adres showroomu pochodzi z publicznych danych mgoutlet.pl: Przelotowa 16, Kębłowice; identyfikator trzeba potwierdzić w rzeczywistym słowniku OLX.

Rzeczywiste kategorie sklepu w momencie analizy: Wyposażenie domu 110, Wyposażenie ogrodu 56, Figury i dekoracje ogrodowe 32, Dekoracje 1, Oświetlenie 1. Szerokie grupy nie dają jednoznacznego leaf dla wszystkich produktów. Mapowanie pozostaje propozycją do ręcznego zatwierdzenia osobno w każdym szkicu. Edycja/zapis produktu nie uruchamia żadnego odczytu ani zapisu w OLX.

Lokalny podgląd payloadu pokazuje external_id = productId i oddzielne wartości OLX; nie jest wysyłany. Status „Gotowe lokalnie” nie gwarantuje późniejszej akceptacji przez zewnętrzną walidację OLX. Specyfikacja wspomina również warunkowe wymagania product_safety_regulation; przed ETAPEM D trzeba potwierdzić je dla wybranego produktu, bez zgadywania producenta lub danych bezpieczeństwa.

Wdrożenie wybiera jawny manifest preparation: dwie nowe strony/biblioteki OLX, nawigacja OLX, ochrona biblioteki .htaccess i mały odnośnik na edycji produktu. Wykorzystuje zweryfikowaną pełną kopię tylko przy zgodności całego products.json; bieżący kod ma dodatkową, zweryfikowaną kopię przyrostową przed podmianą. Pliki są stagingowane poza webrootem. Nie uruchamia migracji ani nie zapisuje products.json. Błąd uruchamia rollback tylko plików kodu z manifestu.

Testy: `olx-preparation-tests.php` oraz rozszerzone `olx-http-tests.mjs`, w tym syntetyczny preview i zapis, mapowanie, CSRF i brak tokenów w HTML. Transport testowy nie jest sterowany parametrami HTTP i nie występuje w produkcyjnym formularzu.
