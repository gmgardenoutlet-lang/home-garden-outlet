<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
shop_test_boot();
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Polityka prywatności | Home & Garden Outlet</title>
  <?php shop_test_stylesheets(); ?>
</head>
<body>
  <?php shop_test_header('figures'); ?>

  <main>
    <article class="legal-page">
      <div class="admin-ribbon admin-ribbon-inline">Tryb testowy — sklep niepubliczny</div>
      <p class="eyebrow">RODO i prywatność</p>
      <h1>Polityka prywatności Home &amp; Garden Outlet</h1>
      <p class="legal-lead">Ten dokument wyjaśnia, jakie dane osobowe mogą być przetwarzane podczas korzystania ze strony https://mgoutlet.pl oraz testowego modułu sklepu z figurami ogrodowymi.</p>

      <section>
        <h2>1. Administrator danych</h2>
        <p>Nazwa handlowa: Home &amp; Garden Outlet. Strona internetowa: <a href="https://mgoutlet.pl">https://mgoutlet.pl</a>.</p>
        <p>Administratorem danych osobowych jest EMAALL GARDEN OUTLET sp. z o.o., ul. Pogodna 4, 55-080 Małkowice, NIP: 8961601667, REGON: 388341909, KRS: 0000887089.</p>
        <p>Showroom, odbiór osobisty, zwroty i reklamacje: Home &amp; Garden Outlet, ul. Przelotowa 16, 55-080 Kębłowice.</p>
        <p>Kontakt w sprawach danych osobowych: <a href="mailto:biuro@mgoutlet.pl">biuro@mgoutlet.pl</a>, telefon: <a href="tel:+48577210777">577 210 777</a>.</p>
      </section>

      <section>
        <h2>2. Jakie dane przetwarzamy</h2>
        <p>W związku ze składaniem zamówień możemy przetwarzać imię i nazwisko, adres e-mail, numer telefonu, adres dostawy, kod pocztowy, miejscowość, treść uwag do zamówienia, informacje o zamówionych produktach, wybranej dostawie, płatności oraz historię obsługi zamówienia.</p>
        <p>W przypadku reklamacji, zwrotu lub odstąpienia od umowy możemy przetwarzać dane potrzebne do rozpatrzenia zgłoszenia, w tym opis sprawy, zdjęcia produktu, dane kontaktowe i dane rozliczeniowe.</p>
      </section>

      <section>
        <h2>3. Cele i podstawy przetwarzania</h2>
        <p>Dane przetwarzamy w celu obsługi zamówienia, dostawy, płatności, kontaktu z klientem, wystawienia dokumentów księgowych, realizacji obowiązków podatkowych, obsługi reklamacji, zwrotów i odstąpień od umowy.</p>
        <p>Podstawą przetwarzania danych jest wykonanie umowy lub działania przed jej zawarciem, obowiązki prawne administratora oraz prawnie uzasadniony interes polegający na obsłudze kontaktu, zabezpieczeniu roszczeń i poprawnym działaniu strony.</p>
      </section>

      <section>
        <h2>4. Dostawa, płatności i obsługa techniczna</h2>
        <p>Dane mogą być przekazywane podmiotom pomagającym w realizacji zamówienia: firmom kurierskim, operatorom płatności po uruchomieniu płatności online, dostawcom hostingu, poczty e-mail, obsługi technicznej strony, biuru rachunkowemu oraz innym podmiotom, którym dane muszą zostać przekazane zgodnie z prawem.</p>
        <p>Zakres przekazywanych danych jest ograniczony do tego, co jest potrzebne do wykonania danej usługi.</p>
      </section>

      <section>
        <h2>5. Statystyki i narzędzia techniczne</h2>
        <p>Strona może korzystać z podstawowych, własnych statystyk zdarzeń biznesowych, takich jak odsłony stron, odsłony produktów lub kliknięcia w przyciski kontaktowe. Statystyki są zagregowane i nie zapisują IP, User-Agent, cookies, fingerprintingu ani identyfikatora użytkownika.</p>
        <p>Strona może korzystać z Google Search Console do monitorowania widoczności w wyszukiwarce Google. Narzędzie to służy do analizy indeksacji i błędów technicznych strony.</p>
        <p>Na ten moment strona nie używa marketingowych cookies, nie prowadzi newslettera, nie posiada kont klientów i nie umożliwia dodawania komentarzy.</p>
      </section>

      <section>
        <h2>6. Czas przechowywania danych</h2>
        <p>Dane związane z zamówieniami, płatnościami i dokumentami księgowymi przechowujemy przez okres wymagany przepisami prawa. Dane dotyczące kontaktu, reklamacji, zwrotów i roszczeń przechowujemy przez okres potrzebny do obsługi sprawy oraz zabezpieczenia ewentualnych roszczeń.</p>
      </section>

      <section>
        <h2>7. Prawa użytkownika</h2>
        <p>Masz prawo dostępu do swoich danych, ich sprostowania, usunięcia, ograniczenia przetwarzania, przenoszenia danych, wniesienia sprzeciwu oraz złożenia skargi do Prezesa Urzędu Ochrony Danych Osobowych.</p>
        <p>W sprawach dotyczących danych osobowych skontaktuj się z nami pod adresem <a href="mailto:biuro@mgoutlet.pl">biuro@mgoutlet.pl</a> albo telefonicznie pod numerem 577 210 777.</p>
      </section>

      <section>
        <h2>8. Bezpieczeństwo</h2>
        <p>Stosujemy środki techniczne i organizacyjne mające chronić dane przed nieuprawnionym dostępem, utratą, zmianą lub zniszczeniem. Dostęp do danych mają wyłącznie osoby i podmioty, które potrzebują go do obsługi strony, zamówień lub obowiązków prawnych.</p>
      </section>

      <section>
        <h2>9. Zmiany polityki prywatności</h2>
        <p>Polityka prywatności może być aktualizowana wraz z rozwojem sklepu internetowego, wdrożeniem płatności online lub nowych funkcji strony. Aktualna wersja dokumentu będzie dostępna na tej stronie.</p>
      </section>

      <div class="shop-actions legal-actions">
        <a class="btn" href="/sklep-test/figury-ogrodowe">Wróć do sklepu</a>
        <a class="btn btn-light" href="/sklep-test/figury-ogrodowe/regulamin">Regulamin</a>
      </div>
    </article>
  </main>

  <?php shop_test_footer(); ?>
  <script>window.HGO_SHOP_PRODUCTS = <?= json_encode(shop_test_public_products(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
  <script src="/sklep-test/shop.js"></script>
</body>
</html>
