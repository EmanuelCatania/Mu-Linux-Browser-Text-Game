<?php

declare(strict_types=1);

namespace Mu\Tests\Domain;

use InvalidArgumentException;
use Mu\Domain\Character\CharacterClass;
use Mu\Domain\Item\Item;
use Mu\Domain\Item\Requirement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ItemTest extends TestCase
{
    private const string KRIS = '1.12.1.0011.00.0.1027.2027.00.0.0.00.00.0.022.022.000000.kris';

    public function testParsesEveryField(): void
    {
        $item = Item::parse('2.24.3.0130.07.1.1425!2206.05.3.2.04.25.1.094.095.00000A.rune_blade');

        self::assertSame('2', $item->type);
        self::assertSame([2, 4], [$item->width, $item->height]);
        self::assertSame('3', $item->subType);
        self::assertSame(130, $item->effect);
        self::assertSame(7, $item->level);
        self::assertSame(1, $item->luck);
        self::assertSame([1, 425], [$item->requirement1Kind, $item->requirement1Value]);
        self::assertTrue($item->requiresLevel380);
        self::assertSame([2, 206], [$item->requirement2Kind, $item->requirement2Value]);
        self::assertSame(5, $item->requiredClass);
        self::assertSame('3', $item->skill);
        self::assertSame(2, $item->option);
        self::assertSame(4, $item->excellent);
        self::assertSame([2, 5], [$item->harmonyType, $item->harmonyValue]);
        self::assertSame('1', $item->guardian);
        self::assertSame([94, 95], [$item->durability, $item->durabilityMax]);
        self::assertSame('00000A', $item->socket);
        self::assertSame('rune_blade', $item->name);
    }

    public function testToCodeRoundTrips(): void
    {
        $code = '2.24.3.0130.07.1.1425!2206.05.3.2.04.25.1.094.095.00000A.rune_blade';

        self::assertSame($code, Item::parse($code)->toCode());
        self::assertSame(self::KRIS, Item::parse(self::KRIS)->toCode());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCodes(): iterable
    {
        yield 'vacío' => [''];
        yield 'truncado' => ['1.12.1.0011.00.0.1027'];
        yield 'nombre con ruta' => ['1.12.1.0011.00.0.1027.2027.00.0.0.00.00.0.022.022.000000.../../etc/passwd'];
        yield 'nombre con comillas' => ['1.12.1.0011.00.0.1027.2027.00.0.0.00.00.0.022.022.000000.kris"onload="x'];
        yield 'letras donde van números' => ['1.12.1.00AA.00.0.1027.2027.00.0.0.00.00.0.022.022.000000.kris'];
    }

    #[DataProvider('invalidCodes')]
    public function testRejectsInvalidCodes(string $code): void
    {
        $this->expectException(InvalidArgumentException::class);
        Item::parse($code);
    }

    public function testDisplayName(): void
    {
        self::assertSame('Kris', Item::parse(self::KRIS)->displayName());
        self::assertSame(
            'Sword Of Assasin +3',
            Item::parse('1.13.1.0018.03.0.1041.2034.00.0.0.00.00.0.024.024.000000.sword_of_assasin')->displayName(),
        );
    }

    public function testWeaponDamageGrowsWithLevelAndExcellent(): void
    {
        // Igual que el tooltip original: máx = efecto + nivel*3 (+30 si es excellent), mín = 5/6 del máx.
        self::assertSame([9, 11], Item::parse(self::KRIS)->damageRange());
        self::assertSame([14, 17], Item::parse('1.12.1.0011.02.0.1027.2027.00.0.0.00.00.0.022.022.000000.kris')->damageRange());
        self::assertSame([39, 47], Item::parse('1.12.1.0011.02.0.1027.2027.00.0.0.01.00.0.022.022.000000.kris')->damageRange());
        self::assertNull(Item::parse('3.23.1.0010.00.0.1020.0000.00.0.0.00.00.0.030.030.000000.pad_armor')->damageRange());
    }

    public function testDefenseByType(): void
    {
        self::assertSame(16, Item::parse('3.23.1.0010.02.0.1020.0000.00.0.0.00.00.0.030.030.000000.pad_armor')->defense());
        // escudo: defensa = efecto + nivel; tasa de defensa = efecto*3 + nivel*3
        $shield = Item::parse('9.22.1.0004.02.0.1020.0000.00.0.0.00.00.0.030.030.000000.small_shield');
        self::assertSame(6, $shield->defense());
        self::assertSame(18, $shield->defenseRate());
        // alas de 1.er nivel suman 3 por nivel; las demás, 2
        self::assertSame(16, Item::parse('8.32.1.0010.02.0.4180.0000.00.0.0.00.00.0.200.200.000000.wings_of_elf')->defense());
        self::assertSame(34, Item::parse('8.43.2.0030.02.0.4215.0000.00.0.0.00.00.0.200.200.000000.wings_of_spirits')->defense());
        self::assertNull(Item::parse(self::KRIS)->defense());
    }

    public function testRequirementsGrowWithLevelAndExcellent(): void
    {
        $plain = Item::parse(self::KRIS)->requirements();
        self::assertSame(
            [[Requirement::Strength, 27], [Requirement::Agility, 27]],
            array_map(static fn (array $r): array => [$r['kind'], $r['value']], $plain),
        );

        $upgraded = Item::parse('1.12.1.0011.03.0.1027.2027.00.0.0.01.00.0.022.022.000000.kris')->requirements();
        self::assertSame([72, 72], array_column($upgraded, 'value'));
    }

    public function testLevel380MarkerAddsALevelRequirement(): void
    {
        $requirements = Item::parse('1.24.1.0135.00.0.1466!2174.21.0.0.00.00.0.095.095.000000.bone_blade')->requirements();

        self::assertSame(Requirement::Level, $requirements[2]['kind']);
        self::assertSame(380, $requirements[2]['value']);
    }

    public function testClassRestrictions(): void
    {
        $anyone = Item::parse(self::KRIS);
        $knightOrElf = Item::parse('1.13.1.0056.00.0.1120.2120.11.0.0.00.00.0.043.043.000000.double_blade');
        $bladeKnightOnly = Item::parse('1.24.1.0135.00.0.1466!2174.21.0.0.00.00.0.095.095.000000.bone_blade');

        self::assertTrue($anyone->canBeUsedBy(CharacterClass::Summoner));

        self::assertTrue($knightOrElf->canBeUsedBy(CharacterClass::DarkKnight));
        self::assertTrue($knightOrElf->canBeUsedBy(CharacterClass::BladeKnight), 'la evolución hereda los ítems de su clase base');
        self::assertTrue($knightOrElf->canBeUsedBy(CharacterClass::FairyElf));
        self::assertFalse($knightOrElf->canBeUsedBy(CharacterClass::DarkWizard));

        self::assertTrue($bladeKnightOnly->canBeUsedBy(CharacterClass::BladeKnight));
        self::assertFalse($bladeKnightOnly->canBeUsedBy(CharacterClass::DarkKnight));
    }

    public function testShopPrices(): void
    {
        // base 50 + efecto*2, multiplicado por 1 + nivel + luck + opción (+10 si es excellent)
        self::assertSame(72, Item::parse(self::KRIS)->buyPrice());
        self::assertSame(36, Item::parse(self::KRIS)->sellPrice());
        self::assertSame(72 * 15, Item::parse('1.12.1.0011.02.1.1027.2027.00.0.1.01.00.0.022.022.000000.kris')->buyPrice());
        // joyas: base fija de 1000
        self::assertSame(1000, Item::parse('D.11.1.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.jewel_of_bless')->buyPrice());
    }
}
