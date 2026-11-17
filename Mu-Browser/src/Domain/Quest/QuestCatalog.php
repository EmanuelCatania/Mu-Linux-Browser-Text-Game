<?php

declare(strict_types=1);

namespace Mu\Domain\Quest;

use Mu\Domain\Item\Item;
use RuntimeException;

/**
 * Misiones, leídas de data/quests.xml. Los títulos y descripciones se muestran desde lang/
 * (claves quest.<id>.title y quest.<id>.text); los del XML son sólo la referencia original en inglés.
 */
final class QuestCatalog
{
    /** @var array<int, Quest>|null */
    private ?array $quests = null;

    public function __construct(private readonly string $file)
    {
    }

    /** @return array<int, Quest> por id */
    public function all(): array
    {
        if ($this->quests !== null) {
            return $this->quests;
        }

        $xml = simplexml_load_file($this->file);
        if ($xml === false) {
            throw new RuntimeException('No se pudieron leer las misiones.');
        }

        $this->quests = [];
        foreach ($xml->quest as $node) {
            $id = (int) $node['id'];
            $reward = trim((string) $node->reward);
            $this->quests[$id] = new Quest(
                id: $id,
                levelMin: (int) $node['lmin'],
                levelMax: (int) $node['lmax'],
                target: trim((string) $node->target),
                amount: (int) $node->amount,
                reward: $reward === 'level' ? null : Item::parse($reward),
            );
        }

        return $this->quests;
    }

    public function get(int $id): ?Quest
    {
        return $this->all()[$id] ?? null;
    }
}
