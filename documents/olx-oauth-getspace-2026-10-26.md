# OLX OAuth na Getspace — decyzja z 26.10.2026

Administrator świadomie zaakceptował standardowe logowanie query string przez hosting współdzielony. Nie twierdzimy, że logi Getspace są redagowane. Dotychczasowa flaga `OLX_CALLBACK_LOGS_REDACTED=0` pozostaje zgodna z rzeczywistością; osobna prywatna opcja `OLX_SHARED_HOST_LOGGING_ACCEPTED=1` dokumentuje akceptację. Ta decyzja zastępuje wcześniejszą blokadę opisaną w dokumencie etapu B.

Callback nie otwiera sesji. Po sprawdzeniu losowego, zahashowanego state usuwa state pod blokadą, natychmiast wymienia kod i zapisuje tylko zaszyfrowane tokeny oraz metadane. Kod nigdy nie jest zapisywany w vault, pliku, bazie, logach aplikacji ani HTML. Błędy i ponowienia nie przywracają zużytego state. Limit state: 600 sekund. Start wymaga sesji administratora i CSRF.

Sukces: 303 do `/admin/olx/return.php` bez query string. Ta czysta strona bez sesji wykonuje nawigację do `/admin/olx/?connected=1`. Osobny dokument przywraca kontekst same-origin dla głównego cookie SameSite=Strict; nie zmieniamy polityki cookie ani nie tworzymy zastępczej sesji podczas callbacku. Status „Połączone” pochodzi z serwerowego vault, nigdy z parametru `connected`.

Sekret i istniejący klucz pozostają wyłącznie w prywatnym config.php. Sodium secretbox, atomowy rename, plik 600, katalogi 700, flock dla state, tokenów, refresh i rozłączenia. Refresh wykonywany tylko przy zbliżającym się wygaśnięciu; obrót refresh zapisuje nowy token pod tą samą blokadą. Niejednoznaczny błąd wymaga ponownego połączenia zamiast ponowienia starego refresh.

Dokumentacja sprawdzona 26.10.2026: https://developer.olx.pl/api/doc oraz oficjalny https://developer.olx.pl/swagger/v2/partner_api.yaml. Token: POST `https://www.olx.pl/api/open/oauth/token`, grant authorization_code, client_id, client_secret, code, scope i dokładny redirect_uri. Jedyny dodatkowy odczyt: GET `https://www.olx.pl/api/partner/users/me`, Bearer i Version 2.0. Brak transportu ogłoszeń.

Wdrożenie: osobny ręcznie wyzwalany workflow `deploy-olx-oauth.yml`, pełna kopia public_html do private_backups, weryfikacja kopii katalogu, wysłanie wyłącznie czterech plików PHP OLX. Brak migracji lub zapisu products.json. SHA256 katalogu sprawdzane przed i po. Nie zmienia konfiguracji Apache ani .htaccess, sekretu lub klucza. Rollback dotyczy wyłącznie wcześniejszych plików PHP OLX.

Prawdziwy OAuth rozpoczyna administrator ręcznie. Samo wdrożenie, preflight i wejście do panelu nie generują state, nie wykonują OAuth ani operacji na ogłoszeniach.
