<?php

// Habilidades del juego (functions.skills.js del original). La clave es el código de habilidad,
// que también da el nombre de la imagen: public/assets/img/skills/skill<código>.gif.
//
// Campos opcionales: damage [tipo, texto] con tipo normal|wizardry|curse; targets; duration (turnos);
// life (invocaciones); attr (atributo elemental); fx: lista de [clave skill.fx_*, valor].
//
// battle define cómo se resuelve en combate (BattleEngine); sin esa clave la habilidad todavía no se puede usar:
//   ['phys', mín, máx]   daño del personaje + rango + ENE/10
//   ['wiz', mín, máx]    daño mágico + rango, aumentado por el bastón (un 4.º valor = turnos de efecto que deja)
//   ['curse', mín, máx]  daño mágico + rango, aumentado por el libro
//   ['fixed', máx]       máx + ENE/10, sin sumar el daño del personaje (mín = 5/6)
//   ['plain']            el daño normal del personaje (disparos múltiples)
//   ['effect']           sólo aplica un efecto durante `duration` turnos
//   ['heal']             cura 5 + ENE/5
//   ['summon', mín, máx] invoca un aliado con `life` de vida que pega ese rango cada turno
// Los nombres son nombres propios de MU y no se traducen.

return [
    // generales
    '1' => ['name' => 'Plasma Storm', 'group' => 'general', 'damage' => ['normal', '250 + ENE/10'], 'targets' => 3, 'mana' => 50, 'attr' => 'lightning', 'battle' => ['fixed', 250]],
    '2' => ['name' => 'Raid', 'group' => 'general', 'damage' => ['normal', '200 + ENE/10'], 'targets' => 1, 'mana' => 9, 'attr' => 'fire', 'battle' => ['fixed', 200]],

    // de arma
    '3' => ['name' => 'Uppercut', 'group' => 'weapon', 'damage' => ['normal', '20 ~ 40'], 'targets' => 1, 'mana' => 10, 'battle' => ['phys', 20, 40]],
    '4' => ['name' => 'Cyclone', 'group' => 'weapon', 'damage' => ['normal', '20 ~ 40'], 'targets' => 1, 'mana' => 10, 'battle' => ['phys', 20, 40]],
    '5' => ['name' => 'Lunge', 'group' => 'weapon', 'damage' => ['normal', '20 ~ 40'], 'targets' => 1, 'mana' => 10, 'battle' => ['phys', 20, 40]],
    '6' => ['name' => 'Slash', 'group' => 'weapon', 'damage' => ['normal', '20 ~ 40'], 'targets' => 1, 'mana' => 10, 'battle' => ['phys', 20, 40]],
    '7' => ['name' => 'Falling Slash', 'group' => 'weapon', 'damage' => ['normal', '20 ~ 40'], 'targets' => 1, 'mana' => 10, 'battle' => ['phys', 20, 40]],
    '8' => ['name' => 'Defense', 'group' => 'weapon', 'fx' => [['reduce_damage', '50%']], 'duration' => 5, 'mana' => 30, 'battle' => ['effect']],
    '9' => ['name' => 'Triple Shot', 'group' => 'weapon', 'fx' => [['shoot_arrows', '3']], 'targets' => 3, 'mana' => 5, 'battle' => ['plain']],
    'A' => ['name' => 'Explosion', 'group' => 'weapon', 'damage' => ['curse', '80 ~ 110'], 'targets' => 1, 'mana' => 90, 'battle' => ['curse', 80, 110]],
    'B' => ['name' => 'Requiem', 'group' => 'weapon', 'damage' => ['curse', '70 ~ 100'], 'targets' => 3, 'mana' => 100, 'battle' => ['curse', 70, 100]],
    'C' => ['name' => 'Pollution', 'group' => 'weapon', 'damage' => ['curse', '90 ~ 120'], 'targets' => 3, 'mana' => 120, 'battle' => ['curse', 90, 120]],
    'D' => ['name' => 'Power Slash', 'group' => 'weapon', 'damage' => ['normal', '20 ~ 40'], 'targets' => 1, 'mana' => 10, 'battle' => ['phys', 20, 40]],

    // Dark Knight
    '21' => ['name' => 'Impale', 'group' => 'dk', 'damage' => ['normal', '70 ~ 120'], 'targets' => 1, 'mana' => 8, 'battle' => ['phys', 70, 120]],
    '22' => ['name' => 'Twisting Slash', 'group' => 'dk', 'damage' => ['normal', '60 ~ 110'], 'targets' => 3, 'mana' => 10, 'attr' => 'wind', 'battle' => ['phys', 60, 110]],
    '23' => ['name' => 'Greater Fortitude', 'group' => 'dk', 'fx' => [['increase_hp', '12% + ENE/20']], 'duration' => 20, 'mana' => 22, 'battle' => ['effect']],
    '24' => ['name' => 'Death Stab', 'group' => 'dk', 'damage' => ['normal', '85 ~ 140'], 'targets' => 1, 'mana' => 15, 'attr' => 'wind', 'battle' => ['phys', 85, 140]],
    '25' => ['name' => 'Rageful Blow', 'group' => 'dk', 'damage' => ['normal', '70 ~ 120'], 'targets' => 3, 'mana' => 25, 'attr' => 'earth', 'battle' => ['phys', 70, 120]],
    '26' => ['name' => 'Explosion', 'group' => 'dk', 'damage' => ['normal', '90 ~ 135'], 'targets' => 3, 'mana' => 30, 'attr' => 'ice', 'battle' => ['phys', 90, 135]],

    // Magic Gladiator
    '27' => ['name' => 'Fire Slash', 'group' => 'mg', 'damage' => ['normal', '10 ~ 20'], 'targets' => 1, 'mana' => 15, 'attr' => 'fire', 'battle' => ['phys', 10, 20]],
    '28' => ['name' => 'Flame Strike', 'group' => 'mg', 'damage' => ['normal', '20 ~ 30'], 'targets' => 3, 'mana' => 20, 'attr' => 'fire', 'battle' => ['phys', 20, 30]],
    '29' => ['name' => 'Gigantic Storm', 'group' => 'mg', 'damage' => ['normal', '80 ~ 120'], 'targets' => 3, 'mana' => 190, 'attr' => 'lightning', 'battle' => ['phys', 80, 120]],

    // Dark Lord
    '80' => ['name' => 'Force', 'group' => 'dl', 'damage' => ['normal', '10 ~ 20'], 'targets' => 1, 'mana' => 10, 'battle' => ['phys', 10, 20]],
    '81' => ['name' => 'Fire Burst', 'group' => 'dl', 'damage' => ['normal', '100 ~ 150'], 'targets' => 1, 'mana' => 25, 'attr' => 'fire', 'battle' => ['phys', 100, 150]],
    '82' => ['name' => 'Critical Damage', 'group' => 'dl', 'fx' => [['increase_critical', 'CMD/25 + ENE/30']], 'duration' => 20, 'mana' => 50, 'battle' => ['effect']],
    '83' => ['name' => 'Electric Spike', 'group' => 'dl', 'damage' => ['normal', '250 ~ 300'], 'targets' => 3, 'mana' => 100, 'attr' => 'lightning', 'battle' => ['phys', 250, 300]],
    '84' => ['name' => 'Fire Scream', 'group' => 'dl', 'damage' => ['normal', '130 ~ 180'], 'targets' => 3, 'mana' => 45, 'attr' => 'fire', 'battle' => ['phys', 130, 180]],
    '85' => ['name' => 'Chaotic Diseier', 'group' => 'dl', 'damage' => ['normal', '190 ~ 250'], 'targets' => 3, 'mana' => 50, 'battle' => ['phys', 190, 250]],
    'E' => ['name' => 'Earthshake', 'group' => 'dl', 'damage' => ['normal', '150 ~ 200'], 'targets' => 3, 'mana' => 50, 'attr' => 'earth', 'battle' => ['phys', 150, 200]],

    // Dark Wizard
    '30' => ['name' => 'Energy Ball', 'group' => 'dw', 'damage' => ['wizardry', '3 ~ 4'], 'targets' => 1, 'mana' => 1, 'battle' => ['wiz', 3, 4]],
    '31' => ['name' => 'Fire Ball', 'group' => 'dw', 'damage' => ['wizardry', '8 ~ 12'], 'targets' => 1, 'mana' => 3, 'attr' => 'fire', 'battle' => ['wiz', 8, 12]],
    '32' => ['name' => 'Power Wave', 'group' => 'dw', 'damage' => ['wizardry', '14 ~ 21'], 'targets' => 1, 'mana' => 5, 'battle' => ['wiz', 14, 21]],
    '33' => ['name' => 'Meteor', 'group' => 'dw', 'damage' => ['wizardry', '21 ~ 31'], 'targets' => 1, 'mana' => 12, 'attr' => 'fire', 'battle' => ['wiz', 21, 31]],
    '34' => ['name' => 'Lighting', 'group' => 'dw', 'damage' => ['wizardry', '17 ~ 25'], 'targets' => 1, 'mana' => 15, 'attr' => 'lightning', 'battle' => ['wiz', 17, 25]],
    '35' => ['name' => 'Ice', 'group' => 'dw', 'damage' => ['wizardry', '10 ~ 15'], 'targets' => 1, 'mana' => 38, 'attr' => 'ice', 'battle' => ['wiz', 10, 15]],
    '36' => ['name' => 'Poison', 'group' => 'dw', 'damage' => ['wizardry', '12 ~ 18'], 'fx' => [['poison_duration', '10']], 'mana' => 42, 'attr' => 'poison', 'battle' => ['wiz', 12, 18, 10]],
    '37' => ['name' => 'Flame', 'group' => 'dw', 'damage' => ['wizardry', '25 ~ 37'], 'targets' => 1, 'mana' => 50, 'attr' => 'fire', 'battle' => ['wiz', 25, 37]],
    '38' => ['name' => 'Twister', 'group' => 'dw', 'damage' => ['wizardry', '35 ~ 52'], 'targets' => 1, 'mana' => 60, 'attr' => 'wind', 'battle' => ['wiz', 35, 52]],
    '39' => ['name' => 'Evil Spirits', 'group' => 'dw', 'damage' => ['wizardry', '45 ~ 67'], 'targets' => 3, 'mana' => 90, 'battle' => ['wiz', 45, 67]],
    '40' => ['name' => 'Hellfire', 'group' => 'dw', 'damage' => ['wizardry', '120 ~ 180'], 'targets' => 3, 'mana' => 160, 'attr' => 'fire', 'battle' => ['wiz', 120, 180]],
    '41' => ['name' => 'Aquabeam', 'group' => 'dw', 'damage' => ['wizardry', '80 ~ 120'], 'targets' => 1, 'mana' => 140, 'attr' => 'water', 'battle' => ['wiz', 80, 120]],
    '42' => ['name' => 'Soul Barrier', 'group' => 'dw', 'fx' => [['reduce_damage', '10 + ENE/100 (%)']], 'duration' => 20, 'mana' => 70, 'battle' => ['effect']],
    '43' => ['name' => 'Cometfall', 'group' => 'dw', 'damage' => ['wizardry', '70 ~ 105'], 'targets' => 1, 'mana' => 150, 'attr' => 'lightning', 'battle' => ['wiz', 70, 105]],
    '44' => ['name' => 'Inferno', 'group' => 'dw', 'damage' => ['wizardry', '100 ~ 150'], 'targets' => 3, 'mana' => 200, 'battle' => ['wiz', 100, 150]],
    '45' => ['name' => 'Ice Storm', 'group' => 'dw', 'damage' => ['wizardry', '80 ~ 120'], 'targets' => 3, 'mana' => 100, 'attr' => 'ice', 'battle' => ['wiz', 80, 120]],
    '46' => ['name' => 'Decay', 'group' => 'dw', 'damage' => ['wizardry', '95 ~ 142'], 'targets' => 1, 'mana' => 110, 'attr' => 'poison', 'battle' => ['wiz', 95, 142]],
    '47' => ['name' => 'Nova', 'group' => 'dw', 'damage' => ['wizardry', '200 ~ 250'], 'targets' => 3, 'mana' => 180, 'attr' => 'fire', 'battle' => ['wiz', 200, 250]],
    '48' => ['name' => 'Wizardry Enhance', 'group' => 'dw', 'fx' => [['increase_wizardry', '20 + ENE/100 (%)']], 'duration' => 20, 'mana' => 200, 'battle' => ['effect']],

    // Fairy Elf
    '51' => ['name' => 'Heal', 'group' => 'elf', 'fx' => [['heal', '5 + ENE/5']], 'mana' => 20, 'battle' => ['heal']],
    '52' => ['name' => 'Greater Defense', 'group' => 'elf', 'fx' => [['increase_defense', '2 + ENE/8']], 'duration' => 20, 'mana' => 30, 'battle' => ['effect']],
    '53' => ['name' => 'Greater Damage', 'group' => 'elf', 'fx' => [['increase_damage', '3 + ENE/7']], 'duration' => 20, 'mana' => 40, 'battle' => ['effect']],
    '54' => ['name' => 'Penetration', 'group' => 'elf', 'damage' => ['normal', '144 ~ 218'], 'targets' => 1, 'mana' => 7, 'attr' => 'wind', 'battle' => ['phys', 144, 218]],
    '55' => ['name' => 'Ice Arrow', 'group' => 'elf', 'damage' => ['normal', '204 ~ 322'], 'targets' => 1, 'mana' => 10, 'attr' => 'ice', 'battle' => ['phys', 204, 322]],
    '56' => ['name' => 'Multi Shot', 'group' => 'elf', 'fx' => [['shoot_arrows', '5']], 'targets' => 3, 'mana' => 10, 'battle' => ['plain']],
    '57' => ['name' => 'Summon Goblin', 'group' => 'elf', 'life' => 60, 'damage' => ['normal', '9 ~ 11'], 'mana' => 40, 'battle' => ['summon', 9, 11]],
    '58' => ['name' => 'Summon Stone Golem', 'group' => 'elf', 'life' => 465, 'damage' => ['normal', '62 ~ 68'], 'mana' => 70, 'battle' => ['summon', 62, 68]],
    '59' => ['name' => 'Summon Assasin', 'group' => 'elf', 'life' => 800, 'damage' => ['normal', '95 ~ 100'], 'mana' => 110, 'battle' => ['summon', 95, 100]],
    '60' => ['name' => 'Summon Elite Yeti', 'group' => 'elf', 'life' => 900, 'damage' => ['normal', '105 ~ 110'], 'mana' => 160, 'battle' => ['summon', 105, 110]],
    '61' => ['name' => 'Summon Dark Knight', 'group' => 'elf', 'life' => 3000, 'damage' => ['normal', '150 ~ 155'], 'mana' => 200, 'battle' => ['summon', 150, 155]],
    '62' => ['name' => 'Summon Bali', 'group' => 'elf', 'life' => 5000, 'damage' => ['normal', '165 ~ 170'], 'mana' => 250, 'battle' => ['summon', 165, 170]],
    '63' => ['name' => 'Summon Soldier', 'group' => 'elf', 'life' => 5000, 'damage' => ['normal', '300 ~ 310'], 'mana' => 350, 'battle' => ['summon', 300, 310]],
    'F' => ['name' => 'Infinity Arrow', 'group' => 'elf', 'fx' => [['infinite_arrows', '']], 'duration' => 30, 'mana' => 50, 'battle' => ['effect']],

    // Summoner
    '70' => ['name' => 'Drain Life', 'group' => 'summoner', 'damage' => ['curse', '35 ~ 52'], 'fx' => [['restores_hp', '']], 'mana' => 50, 'battle' => ['curse', 35, 52]],
    '71' => ['name' => 'Sleep', 'group' => 'summoner', 'fx' => [['sleep', '']], 'duration' => 5, 'mana' => 20, 'battle' => ['effect']],
    '72' => ['name' => 'Chain Lighting', 'group' => 'summoner', 'damage' => ['curse', '70 ~ 105'], 'targets' => 1, 'mana' => 50, 'attr' => 'lightning', 'battle' => ['curse', 70, 105]],
    '73' => ['name' => 'Damage Reflection', 'group' => 'summoner', 'fx' => [['reflect_damage', '20 + ENE/40 (%)']], 'duration' => 20, 'mana' => 40, 'battle' => ['effect']],
    '74' => ['name' => 'Berserker', 'group' => 'summoner', 'fx' => [['increase_damage', '30 + ENE/20'], ['decrease_defense', '10 + ENE/20']], 'duration' => 20, 'mana' => 100, 'battle' => ['effect']],
    '75' => ['name' => 'Weakness', 'group' => 'summoner', 'fx' => [['reduce_enemy_damage', '20 + ENE/20']], 'duration' => 20, 'mana' => 50, 'battle' => ['effect']],
    '76' => ['name' => 'Lighting Shock', 'group' => 'summoner', 'damage' => ['curse', '95 ~ 142'], 'targets' => 3, 'mana' => 120, 'attr' => 'lightning', 'battle' => ['curse', 95, 142]],
    '77' => ['name' => 'Innovation', 'group' => 'summoner', 'fx' => [['reduce_enemy_defense', '20 + ENE/20']], 'duration' => 20, 'mana' => 70, 'battle' => ['effect']],
];
