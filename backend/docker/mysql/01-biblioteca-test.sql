-- Se ejecuta solo la primera vez que arranca el contenedor de MySQL (volumen vacío).
-- La suite de tests corre contra una base aparte, biblioteca_test (ver phpunit.xml),
-- y el usuario de la aplicación necesita permisos sobre ella.
CREATE DATABASE IF NOT EXISTS biblioteca_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON biblioteca_test.* TO 'biblioteca'@'%';
FLUSH PRIVILEGES;
