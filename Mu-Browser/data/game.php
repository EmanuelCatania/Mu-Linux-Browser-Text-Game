<?php

// Reglas ajustables del juego. Los valores por defecto son los del original salvo donde se indica.
return [
    // Jugadores que pueden pelear a la vez en un mismo spot.
    'spot_capacity' => 10,

    // Cantidad de enemigos por ronda: al azar entre estos dos valores.
    'enemies_min' => 1,
    'enemies_max' => 5,

    // Experiencia por la vida del monstruo: suma vida/N a la base (nivel + defensa). 0 = como el original,
    // donde los mapas altos casi no rinden. Ver tools/balance.php.
    'exp_hp_divisor' => 5,

    // Probabilidad (%) de que aparezca el jefe del spot en cada lugar de la ronda (uno por visita como mucho).
    'boss_chance' => 10,

    // Vida con la que queda el personaje después de una derrota, en % de su máximo.
    // El original lo dejaba con 1 punto de vida (poner 0 para ese comportamiento): sin pociones a mano
    // eso lleva a morir de nuevo en el primer golpe. La pérdida de experiencia se aplica igual.
    'defeat_hp_percent' => 100,

    // Ítems de las tiendas NPC: 'normal' los vende limpios; 'table' tal cual figuran en data/shops/*.xml
    // (los datos heredados de Classic-2011 traen muchos con luck 9 y marca excellent).
    'shop_quality' => 'normal',

    // Eventos con horario: horas UTC en las que abren y cuántos minutos queda abierta la entrada.
    'events' => [
        'devil_square' => ['hours' => [1, 5, 9, 13, 17, 21], 'minutes' => 15],
        'blood_castle' => ['hours' => [3, 7, 11, 15, 19, 23], 'minutes' => 15],
    ],
    // Devil Square da más experiencia (%); Blood Castle paga zen por nivel del personaje al vencer al jefe.
    'event_rewards' => ['devil_square_exp' => 150, 'blood_castle_zen_per_level' => 50000],

    // Party: cuánto de lo que gana cada uno reciben además sus compañeros que pelean en el mismo mapa, y el máximo de miembros.
    'party' => ['share_percent' => 30, 'max' => 5],

    'world_boss' => [
        // Probabilidad (%) por lugar de la ronda de que aparezca el jefe del mapa, si está vivo.
        'chance' => 4,
        // Segundos que tarda en volver después de que alguien lo vence.
        'respawn_seconds' => 4 * 3600,
    ],

    'golden' => [
        // Horas UTC en las que hay Golden Invasion; cada una dura una hora.
        'hours' => [0, 8, 16],
        // Probabilidad (%) por lugar de la ronda de que aparezca el monstruo dorado del mapa.
        'chance' => 5,
    ],

    'drop' => [
        // Probabilidades de drop por monstruo vencido, en "1 de cada N". Se prueban en este orden.
        'regular_one_in' => 4,     // 25%
        'skills_one_in' => 100,    // 1%
        'parts_one_in' => 100,     // 1%, monstruos de nivel 21 a 120
        'rings_one_in' => 500,     // 0,2%, nivel 21 o más
        'jewels_one_in' => 500,    // 0,2%
        'sunday_box_one_in' => 50, // 2% los domingos

        // Calidad de los ítems comunes:
        //   'normal' → ítem normal, con `excellent_chance` % de salir excellent con una opción al azar.
        //   'table'  → tal cual figura en data/drop/*.xml. En las tablas heredadas de Classic-2011 todos
        //              vienen marcados como excellent, por eso ese juego sólo dropeaba ítems excellent.
        'regular_quality' => 'normal',
        'excellent_chance' => 5,
    ],

    'reset' => [
        // Zen que cuesta el reset. Se hace al llegar al nivel 400: el personaje vuelve al nivel 1 y conserva
        // atributos, puntos, habilidades, clase y equipo.
        'zen' => 10000000,
        // Deshabilitado: el final del juego es la tercera clase y el árbol master. Poner true para ofrecerlo otra vez.
        'enabled' => false,
    ],

    // Evolución a la segunda clase con Sebina the Priestess, como en el MU real.
    // Misiones de clase (ver EvolutionService), con los valores de Data/Quest del servidor Season 5.
    'evolution' => [
        // Sebina the Priestess: segunda clase
        'level' => 150,                    // nivel desde el que se puede empezar
        'scroll_zen' => 1000000,           // lo que cobra por encargar el Scroll of Emperor
        'scroll_levels' => [45, 60],       // niveles de monstruo que pueden dejar caer el pergamino
        'treasure_zen' => 2000000,         // lo que cobra por encargar el tesoro de la clase
        'treasure_levels' => [62, 76],     // ídem para Broken Sword, Soul Shard of Wizard, Tear of Elf, Eye of the Abyssal
        'points' => 10,                    // puntos de atributo de cada entrega
        // Marlon
        'marlon_level' => 220,
        'ring_zen' => 3000000,             // Ring of Honor
        'stone_zen' => 2000000,            // Dark Stone (sólo Dark Knight)
        'marlon_levels' => [72, 108],
        // Apostle Devin: tercera clase (trofeos, bajas en Barracks, Dark Elf)
        'devin_level' => 380,
        'devin_zen' => [5000000, 7000000, 10000000],
        'devin_points' => [20, 20, 30],
        // Ítems que caen de cualquier monstruo de una franja: "1 de cada N" bajas. Los trofeos de Devin, que
        // dejan monstruos puntuales, caen siempre.
        'drop_one_in' => 20,
    ],

    'pvp' => [
        // Segundos que un desafío espera respuesta (o confirmación) antes de darse por vencido.
        'expire_seconds' => 600,
        // Turnos por lado como máximo; si nadie cae, gana quien conserve más vida en proporción.
        'max_rounds' => 30,
        // Tope de zen que se puede apostar en un duelo.
        'max_zen' => 99999999,
    ],
];
