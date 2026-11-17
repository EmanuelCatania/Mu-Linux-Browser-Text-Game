<?php

declare(strict_types=1);

namespace Mu\Domain\Character;

use Mu\Data\AccountRepository;
use Mu\Data\BattleRepository;
use Mu\Data\CharacterRepository;
use Mu\Data\Transaction;
use Mu\Domain\Account\Account;
use Mu\Domain\Battle\Battle;
use Mu\Domain\Inventory\InventoryException;
use Mu\Domain\World\Monster;

/**
 * Misiones de clase, como en Data/Quest del servidor Season 5 (Quest.txt, QuestObjective.txt, QuestReward.txt).
 *
 *  Sebina the Priestess (nivel 150)
 *   1. Scroll of Emperor, de monstruos de nivel 45 a 60 ................................ +10 puntos
 *   2. El tesoro de la clase, de monstruos de nivel 62 a 76 .............................. +10 puntos y segunda clase
 *  Marlon (nivel 220, con la segunda clase)
 *   3. Ring of Honor, de monstruos de nivel 72 a 108 ..................................... +1 punto por nivel desde el 220
 *   4. Sólo Dark Knight: Dark Stone, de monstruos de nivel 72 a 108 ...................... Combo
 *  Apostle Devin (nivel 380; Magic Gladiator y Dark Lord empiezan acá)
 *   5. Los trofeos de Death Beam Knight, Hell Maine y Phoenix of Darkness .................. +20 puntos
 *   6. Nivel 400: 20 Balram, 20 Death Spirit y 20 Soram en Barracks ........................ +20 puntos
 *   7. El Dark Elf de Barracks ............................................................. +30 puntos y tercera clase
 *
 * Cada misión se paga al aceptarla. Los ítems de misión no ocupan lugar en el inventario: al caer quedan anotados
 * (evoStep y evoProgress) y sólo caen mientras la misión que los pide está activa.
 *
 * Marlon, en el MU real, además le enseña Infinity Arrow a la Muse Elf: acá también.
 */
final class EvolutionService
{
    public const int NONE = 0;
    public const int SEEKING_SCROLL = 1;
    public const int HAS_SCROLL = 2;
    public const int SEEKING_TREASURE = 3;
    public const int HAS_TREASURE = 4;
    /** Segunda clase lograda. */
    public const int DONE = 5;
    public const int SEEKING_RING = 6;
    public const int HAS_RING = 7;
    public const int SEEKING_STONE = 8;
    public const int HAS_STONE = 9;
    public const int MARLON_DONE = 10;
    public const int DEVIN_TROPHIES = 11;
    public const int DEVIN_BARRACKS = 12;
    public const int DEVIN_DARK_ELF = 13;
    public const int MASTER = 14;

    public const string SCROLL = 'Scroll of Emperor';
    public const string RING = 'Ring of Honor';
    public const string STONE = 'Dark Stone';
    /** Trofeo de Devin → monstruo que lo deja. */
    public const array TROPHIES = [
        'Flame of Death Beam Knight' => 'death_beam_knight',
        'Horn of Hell Maine' => 'hell_maine',
        'Feather of Dark Phoenix' => 'phoenix_of_darkness',
    ];
    /** Mapa donde cuentan las bajas de Devin y cuántas pide de cada monstruo. */
    public const string BARRACKS = 'barracks';
    public const array BARRACKS_KILLS = ['balram' => 20, 'death_spirit' => 20, 'soram' => 20];
    public const array DARK_ELF_KILLS = ['dark_elf' => 1];

    /** Clase inicial → [evolución, tesoro que tiene que conseguir]. */
    private const array PATHS = [
        CharacterClass::DarkKnight->value => [CharacterClass::BladeKnight, 'Broken Sword'],
        CharacterClass::DarkWizard->value => [CharacterClass::SoulMaster, 'Soul Shard of Wizard'],
        CharacterClass::FairyElf->value => [CharacterClass::MuseElf, 'Tear of Elf'],
        CharacterClass::Summoner->value => [CharacterClass::BloodySummoner, 'Eye of the Abyssal'],
    ];

    /** Clase desde la que se pasa a la tercera → tercera clase. */
    private const array THIRD = [
        CharacterClass::BladeKnight->value => CharacterClass::BladeMaster,
        CharacterClass::SoulMaster->value => CharacterClass::GrandMaster,
        CharacterClass::MuseElf->value => CharacterClass::HighElf,
        CharacterClass::BloodySummoner->value => CharacterClass::DimensionMaster,
        CharacterClass::MagicGladiator->value => CharacterClass::DuelMaster,
        CharacterClass::DarkLord->value => CharacterClass::LordEmperor,
    ];

    /** @param array<string, mixed> $config sección `evolution` de data/game.php */
    public function __construct(
        private readonly CharacterRepository $characters,
        private readonly AccountRepository $accounts,
        private readonly BattleRepository $battles,
        private readonly Transaction $transaction,
        private readonly array $config,
    ) {
    }

    /** Segunda clase a la que puede evolucionar, o null. */
    public static function target(Character $character): ?CharacterClass
    {
        return self::PATHS[$character->class->value][0] ?? null;
    }

    public static function treasure(Character $character): ?string
    {
        return self::PATHS[$character->class->base()->value][1] ?? null;
    }

    /** Tercera clase a la que puede evolucionar desde la que tiene, o null. */
    public static function thirdTarget(Character $character): ?CharacterClass
    {
        return self::THIRD[$character->class->value] ?? null;
    }

    /** Las clases que no tienen segunda clase (Magic Gladiator, Dark Lord) no pasan por Sebina ni por Marlon. */
    private static function skipsMarlon(Character $character): bool
    {
        return !isset(self::PATHS[$character->class->base()->value]);
    }

    /** Paso real: quien ya tiene la segunda clase cuenta al menos como DONE, y la tercera como MASTER. */
    public static function step(Character $character): int
    {
        return match ($character->class->tier()) {
            3 => self::MASTER,
            2 => max(self::DONE, $character->evoStep),
            default => $character->evoStep,
        };
    }

    /** Premio de Marlon: un punto más por cada nivel desde el 220. */
    public static function hasPlusStat(Character $character): bool
    {
        return !self::skipsMarlon($character) && self::step($character) >= self::SEEKING_STONE;
    }

    /** Premio de Marlon para el Dark Knight. */
    public static function hasCombo(Character $character): bool
    {
        return $character->class->base() === CharacterClass::DarkKnight && self::step($character) >= self::MARLON_DONE;
    }

    /** Premio de Marlon para la Fairy Elf. */
    public static function hasInfinityArrow(Character $character): bool
    {
        return $character->class->base() === CharacterClass::FairyElf && self::step($character) >= self::MARLON_DONE;
    }

    /**
     * Qué tiene que conseguir en batalla para la misión activa.
     *
     * @param array<string, mixed> $config
     *
     * @return array{drops: list<array{item: string, min: int, max: int, monster: string}>, map: string, kills: array<string, int>}
     *         drops: ítems que pueden caer (de un monstruo puntual, o de cualquiera en esa franja de niveles);
     *         kills: cuántos le faltan de cada monstruo en `map`
     */
    public static function hunt(Character $character, array $config): array
    {
        $range = static fn (string $item, string $key): array => [
            'item' => $item, 'min' => (int) $config[$key][0], 'max' => (int) $config[$key][1], 'monster' => '',
        ];
        $kills = static function (array $needed) use ($character): array {
            $done = $character->evoProgress['kills'] ?? [];
            $left = [];
            foreach ($needed as $monster => $n) {
                $left[$monster] = max(0, $n - (int) ($done[$monster] ?? 0));
            }

            return array_filter($left);
        };
        $none = ['drops' => [], 'map' => '', 'kills' => []];

        return match (self::step($character)) {
            self::SEEKING_SCROLL => ['drops' => [$range(self::SCROLL, 'scroll_levels')]] + $none,
            self::SEEKING_TREASURE => ['drops' => [$range(self::treasure($character) ?? '', 'treasure_levels')]] + $none,
            self::SEEKING_RING => ['drops' => [$range(self::RING, 'marlon_levels')]] + $none,
            self::SEEKING_STONE => ['drops' => [$range(self::STONE, 'marlon_levels')]] + $none,
            self::DEVIN_TROPHIES => ['drops' => array_values(array_map(
                static fn (string $item): array => ['item' => $item, 'min' => 0, 'max' => 0, 'monster' => self::TROPHIES[$item]],
                array_diff(array_keys(self::TROPHIES), $character->evoProgress['items'] ?? []),
            ))] + $none,
            self::DEVIN_BARRACKS => ['drops' => [], 'map' => self::BARRACKS, 'kills' => $kills(self::BARRACKS_KILLS)],
            self::DEVIN_DARK_ELF => ['drops' => [], 'map' => self::BARRACKS, 'kills' => $kills(self::DARK_ELF_KILLS)],
            default => $none,
        };
    }

    /**
     * Anota lo que consiguió en batalla.
     *
     * @param array{items?: list<string>, kills?: array<string, int>} $progress
     * @param list<string>                                             $found  ítems de misión que cayeron
     * @param array<string, int>                                       $killed bajas que cuentan para la misión
     *
     * @return array{int, array{items?: list<string>, kills?: array<string, int>}} paso y progreso nuevos
     */
    public static function record(int $step, array $progress, array $found, array $killed): array
    {
        if ($found !== [] && in_array($step, [self::SEEKING_SCROLL, self::SEEKING_TREASURE, self::SEEKING_RING, self::SEEKING_STONE], true)) {
            return [$step + 1, $progress];
        }
        if ($step === self::DEVIN_TROPHIES) {
            $progress['items'] = array_values(array_unique([...($progress['items'] ?? []), ...array_intersect($found, array_keys(self::TROPHIES))]));
        }
        if ($step === self::DEVIN_BARRACKS || $step === self::DEVIN_DARK_ELF) {
            foreach ($killed as $monster => $n) {
                $progress['kills'][$monster] = (int) ($progress['kills'][$monster] ?? 0) + $n;
            }
        }

        return [$step, $progress];
    }

    /** @return array<string, mixed> lo que las páginas de Sebina, Marlon y Devin necesitan mostrar */
    public function status(Character $character): array
    {
        $c = $this->config;
        $step = self::step($character);
        $items = $character->evoProgress['items'] ?? [];
        $done = $character->evoProgress['kills'] ?? [];
        $count = static fn (array $needed): array => array_map(
            static fn (string $monster, int $n): array => ['label' => Monster::label($monster), 'done' => min($n, (int) ($done[$monster] ?? 0)), 'need' => $n],
            array_keys($needed),
            $needed,
        );
        $marlonOpen = !self::skipsMarlon($character) && $step >= self::DONE;
        $devinStart = self::skipsMarlon($character) ? self::NONE : self::MARLON_DONE;

        return [
            'step' => $step,
            'target' => self::target($character)?->label(),
            'treasure' => self::treasure($character),
            'scroll' => self::SCROLL,
            'eligible' => $character->class->tier() === 1 && self::target($character) !== null,
            'level_ok' => $character->level >= (int) $c['level'] || $character->resets > 0,
            'marlon' => [
                'open' => $marlonOpen,
                'level' => (int) $c['marlon_level'],
                'level_ok' => $character->level >= (int) $c['marlon_level'] || $character->resets > 0,
                'is_dk' => $character->class->base() === CharacterClass::DarkKnight,
                'is_elf' => $character->class->base() === CharacterClass::FairyElf,
                'ring' => self::RING,
                'stone' => self::STONE,
                'ring_zen' => (int) $c['ring_zen'],
                'stone_zen' => (int) $c['stone_zen'],
                'min' => (int) $c['marlon_levels'][0],
                'max' => (int) $c['marlon_levels'][1],
            ],
            'devin' => [
                'class' => self::thirdTarget($character)?->label(),
                'is_master' => $character->class->tier() === 3,
                // ready: puede aceptar la primera misión; trophies/barracks/dark_elf: la que tiene activa
                'phase' => match (true) {
                    $character->class->tier() === 3 => 'master',
                    self::thirdTarget($character) === null => 'locked',
                    $step === $devinStart => 'ready',
                    $step === self::DEVIN_TROPHIES => 'trophies',
                    $step === self::DEVIN_BARRACKS => 'barracks',
                    $step === self::DEVIN_DARK_ELF => 'dark_elf',
                    default => 'locked',
                },
                'level' => (int) $c['devin_level'],
                'level_ok' => $character->level >= (int) $c['devin_level'],
                'final_level' => Battle::MAX_LEVEL,
                'zen' => array_map(intval(...), $c['devin_zen']),
                'points' => array_map(intval(...), $c['devin_points']),
                'trophies' => array_map(
                    static fn (string $item, string $monster): array => ['name' => $item, 'monster' => Monster::label($monster), 'have' => in_array($item, $items, true)],
                    array_keys(self::TROPHIES),
                    self::TROPHIES,
                ),
                'barracks' => $count(self::BARRACKS_KILLS),
                'dark_elf' => $count(self::DARK_ELF_KILLS),
                'complete' => self::complete($character),
            ],
        ] + $c;
    }

    /** Si ya consiguió todo lo que pide la misión de Devin activa. */
    private static function complete(Character $character): bool
    {
        $items = $character->evoProgress['items'] ?? [];
        $kills = $character->evoProgress['kills'] ?? [];
        $enough = static function (array $needed) use ($kills): bool {
            foreach ($needed as $monster => $n) {
                if ((int) ($kills[$monster] ?? 0) < $n) {
                    return false;
                }
            }

            return true;
        };

        return match (self::step($character)) {
            self::DEVIN_TROPHIES => array_diff(array_keys(self::TROPHIES), $items) === [],
            self::DEVIN_BARRACKS => $enough(self::BARRACKS_KILLS),
            self::DEVIN_DARK_ELF => $enough(self::DARK_ELF_KILLS),
            default => false,
        };
    }

    /**
     * Sebina the Priestess: segunda clase.
     *
     * @return string clave del mensaje de lo que pasó
     *
     * @throws InventoryException
     */
    public function talk(Account $account, Character $character): string
    {
        return $this->npc($account, $character, function (Character $character) use ($account): string {
            $target = self::target($character);
            if ($target === null || $character->class->tier() > 1) {
                throw new InventoryException('evo.error_done');
            }

            switch ($character->evoStep) {
                case self::NONE:
                    // Tras un reset el nivel vuelve a 1: quien ya llegó una vez al máximo no tiene que esperar de nuevo.
                    if ($character->level < (int) $this->config['level'] && $character->resets < 1) {
                        throw new InventoryException('evo.error_level');
                    }
                    $this->pay($account, (int) $this->config['scroll_zen']);
                    $this->characters->setEvolution($character->id, self::SEEKING_SCROLL);

                    return 'evo.started';
                case self::HAS_SCROLL:
                    $this->pay($account, (int) $this->config['treasure_zen']);
                    $this->characters->addPoints($character->id, (int) $this->config['points']);
                    $this->characters->setEvolution($character->id, self::SEEKING_TREASURE);

                    return 'evo.scroll_delivered';
                case self::HAS_TREASURE:
                    $this->characters->addPoints($character->id, (int) $this->config['points']);
                    $this->characters->evolve($character->id, $target, self::DONE);

                    return 'evo.evolved';
                default:
                    throw new InventoryException('evo.error_hunting');
            }
        });
    }

    /**
     * Marlon: Ring of Honor (y, para el Dark Knight, Dark Stone).
     *
     * @throws InventoryException
     */
    public function talkMarlon(Account $account, Character $character): string
    {
        return $this->npc($account, $character, function (Character $character) use ($account): string {
            if (self::skipsMarlon($character)) {
                throw new InventoryException('evo.error_done');
            }
            $step = self::step($character);
            if ($step < self::DONE) {
                throw new InventoryException('evo.error_need_second');
            }
            if ($step >= self::MARLON_DONE) {
                throw new InventoryException('evo.error_done');
            }

            switch ($step) {
                case self::DONE:
                    if ($character->level < (int) $this->config['marlon_level'] && $character->resets < 1) {
                        throw new InventoryException('evo.error_level');
                    }
                    $this->pay($account, (int) $this->config['ring_zen']);
                    $this->characters->setEvolution($character->id, self::SEEKING_RING);

                    return 'evo.marlon_started';
                case self::HAS_RING:
                    // un punto por cada nivel que ya pasó del 220; los próximos los suma al subir
                    $this->characters->addPoints($character->id, max(0, $character->level - (int) $this->config['marlon_level']));
                    if ($character->class->base() === CharacterClass::DarkKnight) {
                        $this->pay($account, (int) $this->config['stone_zen']);
                        $this->characters->setEvolution($character->id, self::SEEKING_STONE);

                        return 'evo.ring_delivered_dk';
                    }
                    $this->characters->setEvolution($character->id, self::MARLON_DONE);

                    return $character->class->base() === CharacterClass::FairyElf ? 'evo.ring_delivered_elf' : 'evo.ring_delivered';
                case self::HAS_STONE:
                    $this->characters->setEvolution($character->id, self::MARLON_DONE);

                    return 'evo.combo_learned';
                default:
                    throw new InventoryException('evo.error_hunting');
            }
        });
    }

    /**
     * Apostle Devin: tercera clase.
     *
     * @throws InventoryException
     */
    public function talkThird(Account $account, Character $character): string
    {
        return $this->npc($account, $character, function (Character $character) use ($account): string {
            $third = self::thirdTarget($character);
            if ($third === null) {
                throw new InventoryException($character->class->tier() === 3 ? 'evo.error_done' : 'evo.error_need_second');
            }
            $step = self::step($character);
            $zen = $this->config['devin_zen'];
            $points = $this->config['devin_points'];
            $start = self::skipsMarlon($character) ? self::NONE : self::MARLON_DONE;
            if ($step < $start) {
                throw new InventoryException('evo.error_need_marlon');
            }

            if ($step === $start) {
                if ($character->level < (int) $this->config['devin_level']) {
                    throw new InventoryException('evo.error_level');
                }
                $this->pay($account, (int) $zen[0]);
                $this->characters->setEvoProgress($character->id, self::DEVIN_TROPHIES, []);

                return 'evo.devin_started';
            }
            if (!self::complete($character)) {
                throw new InventoryException('evo.error_hunting');
            }

            switch ($step) {
                case self::DEVIN_TROPHIES:
                    if ($character->level < Battle::MAX_LEVEL) {
                        throw new InventoryException('evo.error_level');
                    }
                    $this->pay($account, (int) $zen[1]);
                    $this->characters->addPoints($character->id, (int) $points[0]);
                    $this->characters->setEvoProgress($character->id, self::DEVIN_BARRACKS, []);

                    return 'evo.trophies_delivered';
                case self::DEVIN_BARRACKS:
                    $this->pay($account, (int) $zen[2]);
                    $this->characters->addPoints($character->id, (int) $points[1]);
                    $this->characters->setEvoProgress($character->id, self::DEVIN_DARK_ELF, []);

                    return 'evo.barracks_done';
                default:
                    $this->characters->addPoints($character->id, (int) $points[2]);
                    $this->characters->evolve($character->id, $third, self::MASTER);
                    $this->characters->setEvoProgress($character->id, self::MASTER, []);

                    return 'evo.third_evolved';
            }
        });
    }

    /** Hablar con un NPC de misión: fuera de batalla y con los datos frescos del personaje. */
    private function npc(Account $account, Character $character, \Closure $talk): string
    {
        return $this->transaction->run($account->id, function () use ($character, $talk): string {
            $character = $this->characters->findById($character->id) ?? throw new InventoryException('error.character_not_found');
            if ($this->battles->find($character->id) !== null) {
                throw new InventoryException('inventory.error_in_battle');
            }

            return $talk($character);
        });
    }

    private function pay(Account $account, int $zen): void
    {
        if (!$this->accounts->spendZen($account->id, $zen)) {
            throw new InventoryException('shop.error_no_money');
        }
    }
}
