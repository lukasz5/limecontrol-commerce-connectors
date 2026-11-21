# Shopify — ankiety po realizacji zamówienia

Konektor obsługuje pojedynczy sklep przez Shopify Admin GraphQL API w wersji `2026-07`. Uruchamia się na serwerze sprzedawcy. Proces cykliczny pobiera zmiany i wysyła je do LimeControl Feedback. Przed wysłaniem zaproszenia LimeSurvey odpytuje konektor, a ten ponownie odczytuje zamówienie z Shopify.

## Instalacja

1. W Dev Dashboard organizacji właściciela sklepu utwórz aplikację, skonfiguruj wersję, dodaj zakresy `read_orders`, `read_products` i `read_returns`. Udostępnij aplikacji chronione pole e-mail zamówienia zgodnie z zasadami Shopify dla danego rodzaju aplikacji i planu sklepu. Wydaj wersję aplikacji i zainstaluj ją we własnym sklepie. Z ustawień aplikacji pobierz Client ID i Client secret. Grant client credentials działa dla aplikacji i sklepu należących do tej samej organizacji. [Instrukcja Shopify dotycząca uwierzytelniania](https://shopify.dev/docs/apps/build/authentication-authorization/client-credentials-grant) · [Dostęp do danych klientów](https://shopify.dev/docs/apps/launch/protected-customer-data)
2. Rozpakuj `limecontrol-feedback-shopify-0.1.0.zip` na serwerze z 64-bitowym PHP 8.1+, cURL oraz PDO SQLite. Ustaw katalog główny witryny HTTPS na katalog `public/` konektora. Dla bazy SQLite utwórz osobny, prywatny katalog poza witryną. Proces CLI i PHP-FPM powinny działać na tym samym hoście z prawem zapisu do tego katalogu.
3. Ustaw poniższe zmienne środowiskowe dla procesu CLI i dla puli PHP obsługującej witrynę. Dane poufne przekaż z menedżera sekretów. Przy domyślnym `clear_env` w PHP-FPM przekaż zmienne jawnie w konfiguracji puli.

| Zmienna | Wartość |
| --- | --- |
| `SHOPIFY_SHOP` | Kanoniczna domena sklepu, np. `przyklad.myshopify.com` |
| `SHOPIFY_CLIENT_ID`, `SHOPIFY_CLIENT_SECRET` | Dane zainstalowanej aplikacji. Konektor sam pobiera krótkotrwały token, używany w pamięci procesu |
| `SHOPIFY_OFFLINE_TOKEN` | Alternatywa dla powyższej pary, jeśli istniejąca integracja dostarcza ważny token offline. Jego odnowienie należy do procesu, który go dostarcza |
| `SHOPIFY_INCLUDE_TEST_ORDERS` | Domyślnie wyłączone. Tylko dla izolowanego sklepu deweloperskiego ustaw `1`, aby przejść cały proces na zamówieniu testowym. Połącz go z testową ankietą i lokalnym SMTP przechwytującym wiadomości (np. Mailpit) |
| `LC_STORE` | Unikalny identyfikator połączenia zgodny z konfiguracją LimeControl Feedback |
| `LC_SIGNING_SECRET` | Wspólny losowy sekret tego połączenia, minimum 32 znaki |
| `LC_INGEST_URL` | Pełny adres HTTPS funkcji ingest z konfiguracji LimeControl Feedback w LimeSurvey |
| `LC_SQLITE_PATH` | Bezwzględna ścieżka prywatnej bazy tego sklepu, np. `/var/lib/limecontrol/shopify/store.sqlite` |

4. W tym samym połączeniu w LimeSurvey zapisz publiczny URL HTTPS prowadzący do `public/index.php`, np. `https://opinie-sklep.example/index.php`, jako adres odczytu aktualnego stanu. Utrzymuj synchronizację czasu obu serwerów.
5. Uruchom `php /opt/limecontrol-feedback-shopify/worker.php`, a następnie uruchamiaj tę komendę co 5 minut za pomocą crona lub timera systemd. Ścieżkę dostosuj do instalacji. Pierwszy przebieg zapisuje czas włączenia. Kolejne pobierają zamówienia utworzone od tego czasu, stronicują aktualizacje i wznawiają przerwaną stronę. Kod wyjścia 1 oznacza błąd konfiguracji lub oczekującą próbę. Stan SQLite obejmuje identyfikatory, rewizje, terminy ponowień oraz pozycję odczytu. Zachowaj tę bazę przy aktualizacji. Domyślnie Shopify udostępnia zamówienia z ostatnich 60 dni. Dłuższy horyzont wymaga odpowiedniego dostępu `read_all_orders` w Shopify. [Zakresy dostępu](https://shopify.dev/docs/api/usage/access-scopes)

## Kwalifikacja

- Zamówienie musi mieć adres e-mail, status finansowy `PAID` albo `PARTIALLY_REFUNDED` i status realizacji `FULFILLED`. Pełny zwrot może zakwalifikować się do ankiety o zwrocie. Anulowanie wyklucza zaproszenie. Zamówienia testowe są pomijane przy ustawieniach domyślnych.
- Do LimeSurvey trafiają adres do zaproszenia, bieżące liczby sztuk, nazwa, SKU oraz dostępne identyfikatory pozycji, produktów i wariantów. Dane konta klienta, adresy dostawy i numery telefonów są pomijane. Kampania dostawy korzysta z `deliveredAt` dopiero po dostarczeniu wszystkich aktywnych fizycznych przesyłek. [Order](https://shopify.dev/docs/api/admin-graphql/2026-07/objects/Order) · [LineItem](https://shopify.dev/docs/api/admin-graphql/2026-07/objects/LineItem) · [Fulfillment](https://shopify.dev/docs/api/admin-graphql/2026-07/objects/Fulfillment)
- Przy częściowym zwrocie pozycje zachowane u klienta pochodzą z `currentQuantity`. Do ankiety o zwrocie kwalifikuje się zwrot przypisany do zamkniętej sprawy Shopify albo rozliczona pozycja z `restockType=RETURN`. Dla zwrotu bez takiej sprawy kotwicą czasu jest przetworzenie refundacji. Trwające zwroty, zwrot wyłącznie kwotowy, nieprzypisana różnica kwotowa oraz nieudana lub oczekująca transakcja wstrzymują zaproszenie. Konektor sprawdza ilości w zamkniętych sprawach oraz kwotę refundacji w walucie sklepu. [Refund](https://shopify.dev/docs/api/admin-graphql/2026-07/objects/Refund) · [Return](https://shopify.dev/docs/api/admin-graphql/2026-07/objects/Return)
- Odczyt wstrzymuje zamówienie, które przekroczy 250 pozycji, 20 refundacji lub 20 spraw zwrotu. Pojedyncza refundacja może zawierać do 100 pozycji i 20 transakcji. Powyżej 50 przesyłek kampania dostawy czeka na potwierdzenie. Jawne anulowanie wynika z pola `cancelledAt`. Utrata widoczności zapisanego wcześniej zamówienia (np. po przekroczeniu 60 dni), błąd API lub zmiana podczas odczytu refundacji powodują odpowiedź HTTP 503 przy weryfikacji i ponowienie w kolejce.

## Sprawdzenie

Z katalogu repozytorium uruchom `python3 commerce-connectors/shopify/tests/run.py`. Test używa fikcyjnych zamówień i tymczasowego serwera HTTPS na loopback. Sprawdza wymianę client credentials, odczyt stron po przerwie, podpisy, powtórzenia, częściowy i pełny zwrot, zwrot kwotowy, brak drugiego doręczenia oraz zmianę stanu przed zaproszeniem. Do potwierdzenia na koncie sprzedawcy pozostają instalacja aplikacji, rzeczywiste uprawnienia i przepływ na jego zamówieniu.
