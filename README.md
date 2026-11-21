# Konektory e-commerce LimeControl Feedback

Konektory przekazują do skonfigurowanej instalacji LimeSurvey stan zamówienia oraz umożliwiają jej ponowny, podpisany odczyt przed wysłaniem zaproszenia. Każdy konektor ustala, które pozycje zamówienia kwalifikują się do ankiety. Po zmianie stanu sklepu oczekujące zaproszenie podlega ponownej weryfikacji. Baza kolejki przechowuje identyfikator zamówienia, skrót stanu, rewizje i informacje o ponowieniach. Shopify i Shoper zapisują też postęp odczytu, a Shoper decyzje operatora o rozliczeniu zamówienia. Adres do zaproszenia, nazwy produktów i ilości pobierane są z aktualnego zamówienia.

## Instalacja

W katalogu źródeł uruchom `php build.php /tmp/limecontrol-packages`. W każdym z pięciu archiwów jest kod jednego konektora. Gotowe pliki znajdują się także w [zestawie instalacyjnym](https://github.com/lukasz5/limecontrol-survey-suite/tree/main/limesurvey-plugins/suite/dist). Dla WooCommerce i PrestaShop wgraj ZIP przez instalator wtyczek WordPress lub modułów PrestaShop.

- **WooCommerce** — włącz wtyczkę osobno na każdej witrynie. Otwórz **WooCommerce → Ustawienia → Zaawansowane → LimeControl Feedback**. WP-Cron uruchamia wysyłkę co pięć minut przy ruchu w sklepie. Jeśli korzystasz z systemowego crona, wywołuj standardowy mechanizm WP-Cron lub `wp cron event run lc_feedback_dispatch`.
- **PrestaShop** — w menedżerze modułów skonfiguruj LimeControl Feedback w kontekście konkretnego sklepu. Wpisz identyfikatory statusów kwalifikujących zamówienia. Uruchamiaj co pięć minut `php /sciezka/do/sklepu/modules/limecontrolfeedback/bin/dispatch.php --shop=1` z identyfikatorem tego sklepu. Kod wyjścia 1 oznacza, że co najmniej jedno dostarczenie zostanie ponowione.
- **Shopify** — rozpakuj konektor na własnym serwerze. Połącz aplikację sklepu z Admin GraphQL API, włącz proces cykliczny i wpisz adres weryfikacji w LimeSurvey. [Konfiguracja Shopify](shopify/README.md)
- **Shoper** — rozpakuj konektor na własnym serwerze. Podłącz konto REST z prawem odczytu i ustaw statusy sklepu. Dla zwykłego statusu doręczenia operator potwierdza ilości dla każdej pozycji. Sklep może też wydzielić status potwierdzający doręczenie bez zwrotu i w ten sposób kwalifikować zamówienia automatycznie. [Konfiguracja Shoper i rozliczanie pozycji](shoper/README.md)
- **OpenCart 4** — wgraj `limecontrol_feedback.ocmod.zip` przez **Extensions → Installer**, zachowując nazwę pliku. Skonfiguruj statusy zamówień i decyzje o zwrotach dla każdego sklepu. [Instalacja i harmonogram OpenCart](opencart/README.md)

W ustawieniach WooCommerce i PrestaShop wklej dokładny adres HTTPS funkcji **ingest** wygenerowany przez aktywną wtyczkę LimeControl Feedback (trasa LimeSurvey `plugins/unsecure`), unikalny identyfikator sklepu oraz wspólny losowy klucz (minimum 32 znaki). Przepisz URL **snapshot** wyświetlony przez sklep do konfiguracji tego samego połączenia w LimeSurvey. Serwery muszą wzajemnie widzieć swoje adresy HTTPS i utrzymywać zsynchronizowane zegary. Każdy sklep otrzymuje własny klucz.

Status „zrealizowano” kwalifikuje zamówienie do ankiety po realizacji. Osobne pole zawiera statusy potwierdzające fizyczne doręczenie. PrestaShop odczytuje pierwsze przejście do takiego statusu w historii. WooCommerce zapisuje moment zaobserwowanej zmiany od czasu włączenia konektora. Dla PrestaShop przypisz również końcowe statusy procesu zwrotów (zaakceptowane/zakończone i oddalone). Nierozstrzygnięta sprawa wstrzymuje zaproszenie.

## Przepływ danych

- Podpis HMAC-SHA256 obejmuje zapis znacznika czasu, kropkę i surowy JSON. Nagłówki `X-LC-Store`, `X-LC-Timestamp` i `X-LC-Signature` identyfikują połączenie i podpis. Sklep tak samo podpisuje odpowiedź na żądanie snapshot. Opóźnienie zegara do pięciu minut jest akceptowane.
- W przypadku kwalifikującego się zamówienia LimeSurvey otrzymuje adres e-mail, identyfikatory zamówienia, pozycji, produktu i wariantu, nazwę, kategorię (gdy jest dostępna), SKU oraz liczby sztuk. Identyfikator klienta pochodzi wyłącznie z zarejestrowanego konta. Wyłączone pozycje o znanej zwróconej ilości trafiają do `returned_items`. Księgowy zwrot bez przypisania kwoty do pozycji wstrzymuje wysyłkę. Sklep przekazuje jedynie fakty dostępne w jego własnych danych o zwrotach.
- Kolejne identyczne zdarzenia mają tę samą rewizję. Po braku poprawnego potwierdzenia odbioru wysyłka ponawia się z narastającym opóźnieniem do sześciu godzin. Dane są sprawdzane ponownie także podczas ponawiania.
- Wyłączenie wtyczki WooCommerce lub modułu PrestaShop zatrzymuje wysyłki. Zachowane wpisy kolejki pozwalają wznowić pracę po ponownym włączeniu. Historyczne zamówienia nie są importowane automatycznie.

## Sprawdzenie połączenia

Uruchom `npm run test:commerce` z katalogu głównego repozytorium. Testy wykorzystują fikcyjne zamówienia. Obejmują między innymi podpisy, częściowy zwrot, zmianę stanu przed wysyłką oraz przyjęcie rewizji przez LimeControl Feedback. Do uruchomienia potrzebne są PHP z cURL i PDO SQLite, Python 3, OpenSSL i możliwość otwarcia portu na loopback.

Przed wysyłką pierwszych zaproszeń w Shopify lub Shoper powtórz próbę na sklepie testowym z aplikacją i uprawnieniami sprzedawcy. Złóż zamówienie z dwoma wariantami, sprawdź dane kwalifikujące je do ankiety, rozlicz zwrot jednej sztuki i sprawdź stan obu pozycji. W drugim zamówieniu sprawdź anulowanie między przyjęciem zdarzenia a zaproszeniem. Próba z dostawcami i pilotaż z zespołem sklepu pozostają otwarte.
