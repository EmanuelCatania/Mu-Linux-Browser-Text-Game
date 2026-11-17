-- Cadena completa de misiones de clase (Sebina, Marlon, Devin). evo_progress guarda en JSON los trofeos
-- conseguidos y las bajas que cuentan para la misión activa.
ALTER TABLE characters ADD COLUMN evo_progress VARCHAR(400) NOT NULL DEFAULT '{}';

-- Renumeración: antes la tercera clase iba de 6 a 11 y el árbol master era 12.
UPDATE characters SET evo_step = 14 WHERE evo_step = 12;
UPDATE characters SET evo_step = 0 WHERE evo_step BETWEEN 6 AND 11 AND class IN (5, 6);
UPDATE characters SET evo_step = 10 WHERE evo_step BETWEEN 6 AND 11;
