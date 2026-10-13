-- =====================================================================
-- Migracja danych 1.0.x -> 1.1.0 (po imporcie sql/panel_schema.sql, który zmienia strukturę).
--
--     mysql mailserver < sql/upgrade_1.1.0.sql
--
-- Jedyna zmiana danych: adresy kont zapisane z wielkimi literami przechodzą na małe. Dovecot zawsze zamienia nazwę
-- użytkownika na małe litery, więc katalog poczty jest /var/mail/vhosts/<domena>/<nazwa-małymi>; konto zapisane
-- jako "Jan.Kowalski@..." było dla skryptu usuwania innym katalogiem niż ten na dysku (skrzynka zostawała).
-- UWAGA: samo porównanie `email <> LOWER(email)` nic nie zwraca — kolumna ma zwykle porównanie bez rozróżniania
-- wielkości liter; trzeba BINARY.
-- Dwa konta różniące się TYLKO wielkością liter (możliwe, gdy kolumna ma porównanie binarne / *_bin albo nie ma klucza
-- UNIQUE): migracja się ZATRZYMUJE przed jakąkolwiek zmianą (SIGNAL, klient mysql kończy z błędem) i wypisuje te
-- adresy — zdecyduj ręcznie, które konto zostaje (każde ma osobny katalog poczty?), potem uruchom plik ponownie.
-- Plik jest idempotentny: drugi przebieg nic nie zmienia.
-- Reszta pliku to RAPORTY (tylko SELECT) do przejrzenia po migracji.
-- =====================================================================

SELECT 'konta z wielkimi literami (przed migracją):' AS info;
SELECT id, email FROM virtual_users WHERE BINARY email <> BINARY LOWER(email);

SELECT 'adresy, które po zamianie na małe litery byłyby DWOMA kontami (powinno być pusto):' AS info;
SELECT LOWER(email) AS adres, COUNT(*) AS konta, GROUP_CONCAT(id ORDER BY id) AS id_kont
FROM virtual_users GROUP BY LOWER(email) HAVING COUNT(*) > 1;

DELIMITER $$
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM virtual_users GROUP BY LOWER(email) HAVING COUNT(*) > 1) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'upgrade_1.1.0: konta rozniace sie tylko wielkoscia liter (lista wyzej) - nic nie zmieniono, rozstrzygnij recznie';
  END IF;
END$$
DELIMITER ;

UPDATE virtual_users SET email = LOWER(email) WHERE BINARY email <> BINARY LOWER(email);

SELECT 'konta z wielkimi literami (po migracji, powinno być pusto):' AS info;
SELECT id, email FROM virtual_users WHERE BINARY email <> BINARY LOWER(email);

-- ---- raporty ----

SELECT 'konta o nazwach zarezerwowanych (sprawdź, czy to na pewno Twoje):' AS info;
SELECT id, email FROM virtual_users
WHERE SUBSTRING_INDEX(email, '@', 1) IN (
  'admin','administrator','hostmaster','postmaster','webmaster','abuse','security','noc','root','ssl-admin',
  'nobody','daemon','mailer-daemon','noreply','no-reply','donotreply','dmarc','www','ftp','news','info','support'
);

SELECT 'aliasy bez skrzynki docelowej w virtual_users (zewnętrzne cele są normalne; lokalne mogą oznaczać skasowane konto):' AS info;
SELECT a.id, a.source, a.destination
FROM virtual_aliases a
LEFT JOIN virtual_users u ON u.email = a.destination
WHERE u.id IS NULL AND a.destination NOT LIKE '%,%'
ORDER BY a.id;

SELECT 'aliasy, których NAZWA jest też skrzynką (poczta idzie do aliasu, nie do skrzynki):' AS info;
SELECT a.id, a.source, a.destination
FROM virtual_aliases a
JOIN virtual_users u ON u.email = a.source
ORDER BY a.id;
