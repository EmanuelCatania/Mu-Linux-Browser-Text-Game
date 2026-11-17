-- Comando: quinto atributo, propio del Dark Lord (clase 6). Las demás clases lo dejan en 0.
ALTER TABLE characters
    ADD COLUMN command INT UNSIGNED NOT NULL DEFAULT 0;
