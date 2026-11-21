# OpenCart 4.1 i ankiety po zakupie

Moduł dla **OpenCart 4.1.0.4**, PHP 8.1+ z cURL i MySQL/MariaDB. W LimeSurvey potrzebna jest wtyczka LimeControl Feedback. Każdy sklep OpenCart otrzymuje własny identyfikator i sekret do podpisywania danych.

## Instalacja

1. Z katalogu projektu wykonaj `php commerce-connectors/opencart/build.php /tmp/feedback`. W panelu OpenCart otwórz **Extensions → Installer**, wgraj **limecontrol_feedback.ocmod.zip** i kliknij instalację pakietu. Zachowaj nazwę archiwum. OpenCart wyprowadza z niej nazwę rozszerzenia.
2. W **Extensions → Extensions → Modules** zainstaluj **LimeControl Feedback**. Moduł rejestruje obsługę zamówień i zwrotów oraz zadanie w **Extensions → Cron Jobs**. W harmonogramie hostingu ustaw wywołanie standardowego adresu OpenCart `index.php?route=cron/cron` co minutę. Panel Cron Jobs pokazuje pełny adres.
3. Zapisz prywatny plik JSON poza katalogami dostępnymi przez WWW. Nadaj procesowi PHP prawo odczytu i ustaw w jego środowisku `LIMECONTROL_OPENCART_CONFIG` na pełną ścieżkę do pliku. Klucze obiektu odpowiadają numerom sklepów OpenCart. Sklep główny ma numer `0`.
4. Skopiuj adres odbioru zdarzeń z konfiguracji LimeControl Feedback. Wygeneruj osobny sekret dla sklepu, np. `openssl rand -hex 32`, i zapisz go po obu stronach. Ustaw strefę czasową zgodną z konfiguracją sklepu. Liczby w przykładzie odpowiadają przykładowym statusom. Potwierdź ich znaczenie w swoim OpenCart przed uruchomieniem.
5. W LimeSurvey ustaw adres weryfikacji zamówienia na `https://SKLEP/index.php?route=extension/limecontrol_feedback/module/limecontrol_feedback.snapshot&store_id=0` z właściwą domeną i numerem sklepu. Skonfiguruj kampanie i harmonogram wysyłki zaproszeń LimeControl Feedback.

```json
{
  "0": {
    "url": "https://ankiety.example.invalid/index.php/plugins/unsecure?plugin=LimeControlFeedback&function=ingest",
    "store": "opencart-demo",
    "secret": "REPLACE_ME",
    "timezone": "Europe/Warsaw",
    "eligible": [5],
    "delivered": [],
    "cancelled": [7, 8, 10, 12, 13, 14, 16],
    "refunded": [11],
    "return_closed": [3],
    "return_denied": [],
    "return_actions": [2, 4]
  }
}
```

## Statusy i zwroty

Przejście do statusu z `eligible` lub `delivered` zapisuje zamówienie w kolejce. Kolejne zmiany aktualizują stan. Aby uruchomić ankietę o dostawie, w `delivered` wpisz wyłącznie statusy potwierdzające doręczenie. Czas pochodzi z historii zamówienia. Przed zaproszeniem LimeSurvey odczytuje bieżący stan zamówienia z podpisanej odpowiedzi sklepu.

Zamówienia gościnne korzystają z adresu e-mail podanego przy zakupie. Identyfikatory pozycji, produktów, wariantów oraz wybranych opcji rozróżniają oceniane produkty. Treść wpisywana przez kupującego w opcjach personalizacji i komentarzach pozostaje w OpenCart.

Dla zwrotów podaj statusy zakończenia w `return_closed` **oraz** działania potwierdzające zwrócone sztuki w `return_actions`. Statusy odrzucenia w `return_denied` pozwalają wznowić ocenę zakupionych produktów. Zwroty otwarte lub niejednoznaczne wstrzymują ankietę. Podobnie dzieje się, gdy zgłoszona ilość przekracza zakup albo ten sam produkt występuje w kilku pozycjach. OpenCart zapisuje w zwrocie identyfikator produktu i wtedy brak wskazania konkretnej pozycji.

Pełna refundacja zamówienia wstrzymuje ankiety zakupowe. Zwroty realizowane wyłącznie u operatora płatności należy odzwierciedlić statusem zamówienia lub zwrotem towaru w OpenCart.

Usunięcie wpisu sklepu z JSON wyłącza połączenie tego sklepu. Odinstalowanie modułu usuwa nasłuch zdarzeń i zadanie harmonogramu. Tabela kolejki zachowuje identyfikatory zamówień, liczniki wersji i skróty stanu, aby po ponownym włączeniu można było wznowić obsługę. Zachowaj powiązanie tego samego sklepu OpenCart z tym samym sklepem w LimeSurvey.

Testy danych syntetycznych uruchom z katalogu projektu poleceniem `php commerce-connectors/opencart/tests/orders.php`. [Oficjalne wydanie OpenCart 4.1.0.4](https://github.com/opencart/opencart/releases/tag/4.1.0.4) · [Instalacja rozszerzeń](https://docs.opencart.com/developer-guide/extensions)

## Weryfikacja na sklepie testowym

Na świeżym OpenCart 4.1.0.4 zainstalowanym przez oficjalny instalator CLI sprawdzono wgranie ZIP, rozpakowanie przez Installer i aktywację modułu przez panel. Dla zamówienia gościnnego na dwie sztuki wariantu natywne modele zamówień i zwrotów wytworzyły cztery kolejne wersje: zakup, wstrzymanie po otwarciu zwrotu, jedna sztuka zachowana i jedna zwrócona po zatwierdzeniu oraz anulowanie. Po każdej zmianie sprawdzono podpis odpowiedzi weryfikacyjnej i dostarczenie tej samej wersji do lokalnego odbiornika HTTPS przez harmonogram OpenCart. Podpis innego sklepu dał HTTP 401, a poprawnie podpisane zapytanie o cudze lub nieznane zamówienie dało HTTP 404.
