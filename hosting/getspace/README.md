# Publikacja Home & Garden Outlet na Getspace

Ten wariant przenosi publiczną stronę na hosting Getspace, ale pozostawia panel
Decap CMS na Netlify. Panel nadal zapisuje produkty i zdjęcia do GitHuba, a
GitHub Actions automatycznie publikuje każdą zmianę na Getspace.

## Dlaczego panel pozostaje na Netlify

Obecny panel używa `git-gateway` oraz Netlify Identity. Te usługi nie działają
na zwykłym hostingu Apache. Adres `/admin/` na Getspace przekierowuje więc do
działającego panelu Netlify.

Pełne usunięcie Netlify wymaga zmiany backendu Decap CMS na `github` i
uruchomienia osobnego, bezpiecznego serwera OAuth.

## Sekrety GitHub Actions

W repozytorium GitHub przejdź do:
`Settings` → `Secrets and variables` → `Actions` → `New repository secret`.

Dodaj:

- `FTP_SERVER` — adres serwera FTP/FTPS z DirectAdmin,
- `FTP_USERNAME` — użytkownik FTP,
- `FTP_PASSWORD` — hasło FTP,
- `FTP_REMOTE_DIR` — katalog publikacji, najczęściej `/domains/mgoutlet.pl/public_html/`
  albo `/public_html/`; dokładną wartość należy potwierdzić w DirectAdmin.

Workflow nie uruchomi wdrożenia FTP, dopóki wymagane sekrety nie istnieją.

## Pierwsze uruchomienie

1. W DirectAdmin wykonaj kopię aktualnej zawartości `public_html`.
2. Potwierdź poprawny katalog `FTP_REMOTE_DIR`.
3. Dodaj sekrety w GitHub.
4. Uruchom ręcznie workflow `Deploy to Getspace`.
5. Sprawdź stronę pod tymczasowym adresem lub po zmianie DNS.
6. Dopiero po pełnym teście skieruj domenę `mgoutlet.pl` na hosting Getspace.

## Co publikuje workflow

- publiczne pliki HTML, CSS, JS, SEO i favicony,
- `data/products.json`,
- wyłącznie zdjęcia używane przez aktualne produkty,
- zdjęcia produktowe zoptymalizowane do WebP,
- `.htaccess` z routingiem, cache, kompresją i przekierowaniem www,
- małą stronę `/admin/`, która przekierowuje do działającego panelu Netlify.
- obsługę linków odzyskiwania hasła, które są przekierowywane do Netlify Identity.

Nie publikuje:

- repozytorium `.git`,
- workflow i plików roboczych,
- skryptów budujących,
- kopii zapasowej produktów,
- plików Netlify `_headers`, `_redirects` i `netlify.toml`.
