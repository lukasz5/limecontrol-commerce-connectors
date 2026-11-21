# Konektory e-commerce LimeControl Feedback

Konektory przekazują do skonfigurowanej instalacji LimeSurvey stan zamówienia oraz umożliwiają jej ponowny, podpisany odczyt przed wysłaniem zaproszenia. Nowe zamówienie trafia do kolejki dopiero po wejściu w jeden ze statusów wybranych przez sklep. Baza kolejki przechowuje identyfikator zamówienia, skrót stanu, rewizje i stan ponawiania. Adres do zaproszenia, nazwy produktów i ilości pobierane są z aktualnego zamówienia.

## Instalacja

W katalogu źródeł uruchom `php commerce-connectors/build.php /tmp/limecontrol-packages`. W każdym archiwum jest pełny kod jednego konektora. Wgraj odpowiedni ZIP przez instalator wtyczek WordPress lub modułów PrestaShop.

- **WooCommerce** — włącz wtyczkę osobno na każdej witrynie. Otwórz **WooCommerce → Ustawienia → Zaawansowane → LimeControl Feedback**. WP-Cron uruchamia wysyłkę co pięć minut przy ruchu w sklepie. Jeśli korzystasz z systemowego crona, wywołuj standardowy mechanizm WP-Cron lub `wp cron event run lc_feedback_dispatch`.
- **PrestaShop** — w menedżerze modułów skonfiguruj LimeControl Feedback w kontekście konkretnego sklepu. Wpisz identyfikatory statusów kwalifikujących zamówienia. Uruchamiaj co pięć minut `php /sciezka/do/sklepu/modules/limecontrolfeedback/bin/dispatch.php --shop=1` z identyfikatorem tego sklepu. Kod wyjścia 1 oznacza, że co najmniej jedno dostarczenie zostanie ponowione.

W obu panelach wklej dokładny adres HTTPS funkcji **ingest** wygenerowany przez aktywną wtyczkę LimeControl Feedback (trasa LimeSurvey `plugins/unsecure`), unikalny identyfikator sklepu oraz wspólny losowy klucz (minimum 32 znaki). Przepisz URL **snapshot** wyświetlony przez sklep do konfiguracji tego samego połączenia w LimeSurvey. Serwery muszą wzajemnie widzieć swoje adresy HTTPS i utrzymywać zsynchronizowane zegary. Każdy sklep otrzymuje własny klucz.

Status „zrealizowano” kwalifikuje zamówienie do ankiety po realizacji. Osobne pole zawiera statusy potwierdzające fizyczne doręczenie. PrestaShop odczytuje pierwsze przejście do takiego statusu w historii. WooCommerce zapisuje moment zaobserwowanej zmiany od czasu włączenia konektora. Dla PrestaShop przypisz również końcowe statusy procesu zwrotów (zaakceptowane/zakończone i oddalone). Nierozstrzygnięta sprawa wstrzymuje zaproszenie.

## Przepływ danych

- Podpis HMAC-SHA256 obejmuje zapis znacznika czasu, kropkę i surowy JSON. Nagłówki `X-LC-Store`, `X-LC-Timestamp` i `X-LC-Signature` identyfikują połączenie i podpis. Sklep tak samo podpisuje odpowiedź na żądanie snapshot. Opóźnienie zegara do pięciu minut jest akceptowane.
- W przypadku kwalifikującego się zamówienia LimeSurvey otrzymuje adres e-mail, identyfikatory zamówienia, pozycji, produktu i wariantu, nazwę, kategorię (gdy jest dostępna), SKU oraz liczby sztuk. Identyfikator klienta pochodzi wyłącznie z zarejestrowanego konta. Wyłączone pozycje o znanej zwróconej ilości trafiają do `returned_items`. Księgowy zwrot bez przypisania kwoty do pozycji wstrzymuje wysyłkę. Sklep przekazuje jedynie fakty dostępne w jego własnych danych o zwrotach.
- Kolejne identyczne zdarzenia mają tę samą rewizję. Po braku poprawnego potwierdzenia odbioru wysyłka ponawia się z narastającym opóźnieniem do sześciu godzin. Dane są sprawdzane ponownie także podczas ponawiania.
- Wyłączenie wtyczki WooCommerce lub modułu PrestaShop zatrzymuje wysyłki. Zachowane wpisy kolejki pozwalają wznowić pracę po ponownym włączeniu. Historyczne zamówienia nie są importowane automatycznie.

## Kwalifikacja kolejnych adapterów

W repozytoriach LimeControl i jego strony nie znaleziono oznaczonego sklepu testowego ani danych próbnych klienta dla Shoper i Shopify. Poniższe próby planujemy przed rozpoczęciem integracji z tymi platformami.

| Kandydat | Warunek rozpoczęcia | Próba na syntetycznym zamówieniu |
| --- | --- | --- |
| Shoper | Właściciel udostępnia sklep testowy i uprawnienia OAuth do odczytu zdarzeń, zamówień, pozycji i statusów. | Zarejestrować powiadomienie, potwierdzić podpis i możliwość odczytu bieżącego statusu. Na zakupie gościnnym z dwoma wariantami rozróżnić realizację i doręczenie. Sprawdzić anulowanie, zwrot jednej sztuki i zwrot kwotowy oraz ustalić, które stany i dane o zwrotach ten sklep faktycznie udostępnia. [Dokumentacja Shoper](https://developers.shoper.pl/) |
| Shopify | Właściciel udostępnia development store i aplikację testową z uzasadnionymi zakresami odczytu. Przed dystrybucją należy potwierdzić dostęp do wymaganych pól chronionych, w szczególności e-maila. | Przy dwóch wariantach rozdzielić częściowe fulfillment, doręczenie i refund, sprawdzić ilości, anulowanie i ponowiony event. Przy niedostępnym e-mailu wstrzymać zaproszenie i wskazać brakujące uprawnienie operatorowi. [Zdarzenia Shopify](https://shopify.dev/docs/apps/build/events/migrate-from-webhooks) · [Dane chronione](https://shopify.dev/docs/apps/launch/protected-customer-data) |
