# Audyt potencjalnych duplikatów figur — 2026-08-16

## Źródła sprawdzone

- Aktualne źródło lokalnego serwisu: `data/products.json` — 55 produktów.
- Zachowana migawka produkcyjna: `../production-backups/data-products.backup-before-dash-fix-2026-08-06-120705.json` — 102 produkty.

Aktualny plik danych nie zawiera pól `saleType`, `shopVisible`, `shopStatus`, `grossPrice` ani `shippingProfileIds`. W konsekwencji lokalny katalog figur nie ma obecnie żadnego produktu spełniającego produkcyjny warunek widoczności sklepu. Nie jest to powód do zmiany danych w ramach tego zadania.

Za aktywną figurę sklepu uznawany jest wyłącznie rekord, który spełnia wszystkie warunki: `saleType = garden_figure`, `shopVisible = true`, `shopStatus = Dostępny` oraz nie ma statusu `Sprzedany` / `Ukryty`. Jest to dokładnie reguła używana przez `hosting/getspace/shop-test/lib.php`.

## A. Potwierdzone duplikaty

W bieżącym `data/products.json`: brak — nie ma aktywnych produktów sklepu, z którymi można utworzyć parę.

W zachowanej migawce produkcyjnej: poniższe sześć rekordów spełniało warunek sklepu. Ich ten sam slug był używany jednocześnie przez lokalny URL `/produkt/...` i sklepowy URL `/sklep/figury-ogrodowe/produkt/...`; są więc potwierdzonymi duplikatami routingu tego samego rekordu, nie dopasowaniem po nazwie.

### Dekoracyjna kula ogrodowa z ornamentem różanym – złoto-brązowa

- Stary URL: `/produkt/dekoracyjna-kula-ogrodowa-ornament-rozany-zloto-brazowa`
- URL sklepu: `/sklep/figury-ogrodowe/produkt/dekoracyjna-kula-ogrodowa-ornament-rozany-zloto-brazowa`
- Czy to na pewno ten sam produkt: **TAK** — ten sam rekord i slug.
- ShopVisible: `true`
- ShopStatus: `Dostępny`
- Cena sklepowa: `110 zł`
- Profil dostawy: `paczkomat-maly`
- Uwagi: `category = Wyposażenie ogrodu`, `productType = figura ogrodowa`.

### Dekoracyjna figura ogrodowa Pies Sznaucer – ciemna z efektem postarzenia

- Stary URL: `/produkt/figura-ogrodowa-pies-sznaucer-ciemna`
- URL sklepu: `/sklep/figury-ogrodowe/produkt/figura-ogrodowa-pies-sznaucer-ciemna`
- Czy to na pewno ten sam produkt: **TAK** — ten sam rekord i slug.
- ShopVisible: `true`
- ShopStatus: `Dostępny`
- Cena sklepowa: `155 zł`
- Profil dostawy: `paczkomat-duzy`
- Uwagi: `category = Wyposażenie ogrodu`, `productType = figura ogrodowa`.

### Dekoracyjna figura ogrodowa Para Kotków – brązowa z efektem postarzenia

- Stary URL: `/produkt/figura-ogrodowa-para-kotkow-brazowa`
- URL sklepu: `/sklep/figury-ogrodowe/produkt/figura-ogrodowa-para-kotkow-brazowa`
- Czy to na pewno ten sam produkt: **TAK** — ten sam rekord i slug.
- ShopVisible: `true`
- ShopStatus: `Dostępny`
- Cena sklepowa: `120 zł`
- Profil dostawy: `paczkomat-sredni`
- Uwagi: `category = Wyposażenie ogrodu`, `productType = figura ogrodowa`.

### Dekoracyjna figura ogrodowa Twarz – czarna z artystycznym wykończeniem

- Stary URL: `/produkt/figura-ogrodowa-twarz-czarna-artystyczne-wykonczenie`
- URL sklepu: `/sklep/figury-ogrodowe/produkt/figura-ogrodowa-twarz-czarna-artystyczne-wykonczenie`
- Czy to na pewno ten sam produkt: **TAK** — ten sam rekord i slug.
- ShopVisible: `true`
- ShopStatus: `Dostępny`
- Cena sklepowa: `250 zł`
- Profil dostawy: `paczkomat-duzy`
- Uwagi: `category = Wyposażenie ogrodu`, `productType = figura ogrodowa`.

### Donica dekoracyjna samochód retro — szara

- Stary URL: `/produkt/donica-dekoracyjna-samochod-retro-szara`
- URL sklepu: `/sklep/figury-ogrodowe/produkt/donica-dekoracyjna-samochod-retro-szara`
- Czy to na pewno ten sam produkt: **TAK** — ten sam rekord i slug.
- ShopVisible: `true`
- ShopStatus: `Dostępny`
- Cena sklepowa: `330 zł`
- Profil dostawy: `kurier-standardowy`
- Uwagi: `category = Wyposażenie ogrodu`, `productType = Donica`; do sklepu trafiała przez `saleType`, nie przez nazwę lub typ.

### Donica betonowa 47 cm — dekoracyjna, naturalna

- Stary URL: `/produkt/donica-betonowa-47-cm-dekoracyjna-naturalna`
- URL sklepu: `/sklep/figury-ogrodowe/produkt/donica-betonowa-47-cm-dekoracyjna-naturalna`
- Czy to na pewno ten sam produkt: **TAK** — ten sam rekord i slug.
- ShopVisible: `true`
- ShopStatus: `Dostępny`
- Cena sklepowa: `320 zł`
- Profil dostawy: `kurier-gabarytowy`
- Uwagi: `category = Wyposażenie ogrodu`, `productType = donica`; do sklepu trafiała przez `saleType`, nie przez nazwę lub typ.

## B. Prawdopodobne duplikaty

Brak. Nie klasyfikowano produktów tylko na podstawie podobnej nazwy.

## C. Brak odpowiednika

W aktualnym pliku danych są cztery starsze, aktywne produkty outletowe, które nie mają potwierdzonego odpowiednika w sklepie, ponieważ nie mają pól sklepowych:

- `/produkt/rzezba-ogrodowa-twarz-mala-dostepne-w-roznych-barwach`
- `/produkt/rzezba-betonowa-do-ogrodu-dekoracyjna-glowa-140-cm`
- `/produkt/rzezba-betonowa-do-ogrodu-z-siedziskiem-dekoracyjna-glowa-140-cm`
- `/produkt/lezaca-rzezba-betonowa-do-ogrodu-dekoracyjna-twarz`

Nie należy dla nich tworzyć przekierowań ani zmieniać indeksowania bez niezależnego potwierdzenia biznesowego.

## Wniosek

Zmiana listingu opiera się wyłącznie na jednoznacznej fladze sklepowej. Nie usuwa danych, kart produktowych, canonicali, elementów sitemap ani nie wprowadza przekierowań 301.
