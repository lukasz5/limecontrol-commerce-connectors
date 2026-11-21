# Ankiety LimeControl dla Shoper

Konektor uruchamiasz na swoim serwerze z PHP 8.1+, cURL i PDO SQLite. Jeden proces łączy jeden sklep Shoper z jednym sklepem w LimeControlFeedback. Zamówienia mogą uruchamiać ankiety o zakupie, dostawie, konkretnym produkcie oraz obsłudze zakończonego zwrotu.

## Podłączenie sklepu

1. W panelu Shoper otwórz **Aplikacje i integracje → Zewnętrzne aplikacje**, dodaj integrację typu **Other** i nadaj jej odczyt obszaru **Zamówienia**. Zapisz wygenerowany token w menedżerze sekretów procesu. [Instrukcja Shoper](https://www.shoper.pl/learn/artykul/clien-id-token-api-shoper)
2. Rozpakuj cały ZIP. Skieruj główny katalog serwera WWW na podkatalog `public/`. W panelu LimeControlFeedback jako URL sprawdzania zamówienia podaj publiczny adres HTTPS tego katalogu z końcówką `/index.php`. Skonfiguruj ten sam identyfikator i sekret po obu stronach.
3. Przekaż poniższe zmienne procesowi CLI i PHP obsługującemu żądania WWW. Trwały katalog SQLite powinien być poza katalogiem publicznym, dostępny do zapisu dla obu procesów.
4. Uruchom `php cli.php sync`, a następnie uruchamiaj tę samą komendę z harmonogramu, np. co minutę. Pierwsze wykonanie zapisuje ID najnowszego zamówienia. Kolejne wykonania włączają do ankiet nowe zamówienia, odczytują zmiany statusów wcześniej włączonych zamówień i ponawiają dostawy do LimeSurvey. Jedno wykonanie odkrywa do 50 nowych zamówień, przegląda do 50 wcześniejszych i wysyła do 50 zdarzeń.
5. Na fikcyjnym zamówieniu ze swojego sklepu sprawdź mapowanie statusów, liczby sztuk i datę doręczenia. Godzina ostatniej zmiany na skonfigurowany status doręczenia musi odpowiadać potwierdzeniu faktycznego odbioru.

| Zmienna | Wartość |
| --- | --- |
| `SHOPER_HOST` | Domena sklepu obsługująca `/webapi/rest/`, np. `twojsklep.shoparena.pl` |
| `SHOPER_API_TOKEN` | Prywatny token API integracji zewnętrznej |
| `SHOPER_TIMEZONE` | Strefa czasu dat zwracanych przez sklep, np. `Europe/Warsaw` |
| `SHOPER_DELIVERED_STATUS_IDS` | ID statusów potwierdzonego doręczenia do ręcznego rozliczenia, rozdzielone przecinkami |
| `SHOPER_RECONCILED_STATUS_IDS` | Opcjonalne ID specjalnych statusów potwierdzających jednocześnie doręczenie i brak zwrotów |
| `SHOPER_CANCELLED_STATUS_IDS` | Opcjonalne ID statusów anulowanego zamówienia |
| `LC_STORE`, `LC_SIGNING_SECRET` | Identyfikator sklepu LimeControlFeedback i wspólny sekret o długości co najmniej 32 znaków |
| `LC_INGEST_URL` | Pełny adres HTTPS odbiornika zdarzeń LimeControlFeedback |
| `LC_SQLITE_PATH` | Pełna ścieżka trwałego pliku SQLite przypisanego temu sklepowi |

Podaj co najmniej jeden status doręczenia lub status automatyczny. Listy muszą być rozłączne. Wszystkie pozostałe statusy, w tym wysłanie przesyłki, spór lub zwrot w toku, wstrzymują kwalifikację.

## Automatyczne uruchomienie ankiet

Sklep może utworzyć własny status, np. „Doręczone i rozliczone bez zwrotów”, i wskazać jego ID w `SHOPER_RECONCILED_STATUS_IDS`. Ustawienie tego statusu potwierdza wszystkie aktualne pozycje i kwalifikuje zamówienie automatycznie. Przy rozpoczęciu zwrotu, sporu albo korekty sprzedawca musi najpierw zmienić status na nierozliczony lub użyć `php cli.php hold ID`. Wstrzymanie działa także między dwoma wykonaniami harmonogramu, ponieważ LimeControlFeedback sprawdza zamówienie w Shoper bezpośrednio przed wysyłką. Jeśli sklep prowadzi zwroty w osobnym narzędziu i nie odzwierciedla ich w statusach Shoper, należy używać poniższego trybu ręcznego.

## Potwierdzanie pozycji i zwrotów

Przy zwykłym statusie doręczenia operator rozlicza zamówienie przed uruchomieniem ankiet. Polecenie `php cli.php inspect 123` wyświetla pozycje oraz `fingerprint` bieżącej wersji. W poniższym przykładzie zamówienie ma dwie pozycje o ID 17 i 22. Podstaw rzeczywisty odcisk zwrócony przez `inspect`.

```sh
php cli.php reconcile 123 ODCISK '{"17":0,"22":0}'
php cli.php sync
```

Gdy klient zgłosi zwrot, wstrzymaj dalsze ankiety poleceniem `php cli.php hold 123` albo ustaw odpowiedni status Shoper. Po rozliczeniu zwrotu ponownie ustaw status potwierdzonego doręczenia, pobierz aktualny odcisk i wpisz łączne liczby zwróconych sztuk dla wszystkich pozycji. Dodatkowy argument to rzeczywisty czas zakończenia zwrotu jako sekundy Unix. Poniższy przykład rozlicza jedną zwróconą sztukę pozycji 17.

```sh
php cli.php inspect 123
php cli.php reconcile 123 ODCISK '{"17":1,"22":0}' 1760000000
php cli.php sync
```

Rozliczenie obejmuje wersję zamówienia odczytaną z API. Zmiana statusu, odbiorcy, rozliczenia albo pozycji wymaga ponownego potwierdzenia. Po blokadzie lub ręcznej decyzji samo przejście na specjalny status automatyczny nie przywróci wysyłki. Potwierdzone zwroty częściowe pozostawiają w ankiecie produktowej tylko zachowane sztuki. Przy pełnym zwrocie pozostaje kwalifikacja do kampanii dotyczącej obsługi zwrotu.

Konektor obsługuje do 250 pozycji w pełnych sztukach. Błąd API lub niepełna lista pozycji zatrzymują odczyt przed wysyłką. Zamówienia grupujące podzamówienia pozostają wstrzymane. Dane adresowe, notatki sklepu i pola personalizacji zakupów nie trafiają do zdarzeń. Standardowe wyszukiwanie zwrotów finansowych w Shoper jest dostępne dla wybranych aplikacji, a podgląd możliwego zwrotu nie stanowi historii rozliczenia sztuk. Źródłem informacji o rozliczeniu są decyzje sprzedawcy opisane powyżej. [Shoper REST API](https://developers.shoper.pl/docs/) · [Model pozycji w oficjalnym SDK Shoper](https://github.com/dreamcommerce/shoper-sdk-php/blob/716e79ebf0b473a7f93fdcaa5e3188a60929367e/src/Rest/Types/OrderProduct.php)
