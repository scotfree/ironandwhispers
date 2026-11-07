<?php
/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * IronAndWhisper implementation : © Scot Free Kennedy
 *
 * This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
 * See http://en.boardgamearena.com/#!doc/Studio for more information.
 * -----
 */
/**
 * Iron and Whisper — the rules, as pure functions.
 *
 * This is a port of the pure logic in sim/engine.py. It touches no database, no
 * framework services and no notifications: it takes plain arrays describing the
 * board and returns either an answer or a *plan* of changes for the caller to
 * persist. That is what lets it be tested without BGA, and what keeps the
 * comparison against the simulator honest.
 *
 * Board representation — an array keyed by town id, each entry:
 *   [
 *     'neighbors' => string[],
 *     'troops'    => int,
 *     'resolved'  => bool,
 *     'pile'      => face-down cards, list of ['id', 'type', 'presence'],
 *     'revealed'  => face-up cards beside the pile, same shape,
 *   ]
 * Pile index 0 is the TOP. New cards are placed on top; a look flips the top
 * card into `revealed`, where it stays. Both count at resolution.
 */
declare(strict_types=1);

namespace Bga\Games\IronAndWhisper;

final class Rules
{
    public const EMPIRE = 'empire';
    public const INSURGENCY = 'insurgency';

    /**
     * Full rules only (issue #6, game option 105): the two extra unit types
     * a human Empire player may choose at the build popup. Build-only — see
     * `$town['troopsCi']` / `$town['troopsShock']` — neither may march yet,
     * so no bot ever needs to know they exist.
     */
    public const CI_UNIT = 'counterinsurgency';
    public const SHOCK_UNIT = 'shock';

    /**
     * Fixed stats for the two full-rules types. Kept in sync with
     * data/units.json by tests/test_full_rules.php, not read from it:
     * Rules.php stays free of the framework and takes plain arrays, and
     * these two are not meant to vary by scenario the way the base unit's
     * own stats do.
     */
    private const CI_PRESENCE = 0;
    private const CI_PEEK = 2;
    private const CI_REVEAL_NEIGHBORS = 1;
    private const SHOCK_PRESENCE = 2;
    private const SHOCK_SUPPLY_COST = 2;

    public static function otherSide(string $side): string
    {
        return $side === self::EMPIRE ? self::INSURGENCY : self::EMPIRE;
    }

    // -- reading the board --------------------------------------------------

    /**
     * Everything committed to a town, face down and face up alike. Turning a
     * card face up tells the Empire what it is; it does not take it out of the
     * fight.
     *
     * @param array{pile: array, revealed: array} $town
     */
    public static function cardPresence(array $town): int
    {
        $total = 0;
        foreach (array_merge($town['pile'], $town['revealed']) as $card) {
            $total += (int) $card['presence'];
        }
        return $total;
    }

    /** @param array{pile: array, revealed: array} $town */
    public static function townCardCount(array $town): int
    {
        return count($town['pile']) + count($town['revealed']);
    }

    public static function troopPresence(int $troops, int $unitPresence): int
    {
        return $troops * $unitPresence;
    }

    /**
     * All three unit types' presence, added up. Collapses to
     * `troopPresence($town['troops'], $unitPresence)` — the whole of the
     * base game — whenever the other two are 0, which is always unless full
     * rules let a human Empire player build one.
     *
     * @param array{troops: int, troopsCi?: int, troopsShock?: int} $town
     */
    public static function townTroopPresence(array $town, int $unitPresence): int
    {
        return self::troopPresence((int) $town['troops'], $unitPresence)
            + ((int) ($town['troopsCi'] ?? 0)) * self::CI_PRESENCE
            + ((int) ($town['troopsShock'] ?? 0)) * self::SHOCK_PRESENCE;
    }

    /**
     * Every troop standing here, of any type — occupancy, not presence or
     * supply cost, which differ by type.
     *
     * @param array{troops: int, troopsCi?: int, troopsShock?: int} $town
     */
    public static function townTotalTroops(array $town): int
    {
        return (int) $town['troops'] + (int) ($town['troopsCi'] ?? 0) + (int) ($town['troopsShock'] ?? 0);
    }

    /**
     * What this town's whole garrison costs its network, by type. Shock
     * Troops cost double; everything else costs what the scenario's base
     * unit does.
     *
     * @param array{troops: int, troopsCi?: int, troopsShock?: int} $town
     */
    public static function townSupplyCost(array $town, int $supplyPerTroop): int
    {
        return ((int) $town['troops']) * $supplyPerTroop
            + ((int) ($town['troopsCi'] ?? 0)) * $supplyPerTroop
            + ((int) ($town['troopsShock'] ?? 0)) * self::SHOCK_SUPPLY_COST;
    }

    /** @param array<string, array> $towns */
    public static function unresolvedTownIds(array $towns): array
    {
        $ids = [];
        foreach ($towns as $townId => $town) {
            if (!$town['resolved']) {
                $ids[] = $townId;
            }
        }
        return $ids;
    }

    /**
     * The Empire's supply networks.
     *
     * A town is in a network if the Empire stands in it, and two occupied towns
     * are linked if the map links them. Resolution is irrelevant: a town the
     * Empire won and still garrisons carries supply like any other. Cutting a
     * network in two leaves two smaller ceilings, which is the whole reason the
     * Insurgency attacks a junction.
     *
     * @param array<string, array> $towns
     * @return array<int, string[]>
     */
    public static function components(array $towns): array
    {
        $occupied = [];
        foreach ($towns as $townId => $town) {
            if (self::townTotalTroops($town) > 0) {
                $occupied[$townId] = true;
            }
        }

        $seen = [];
        $components = [];

        foreach (array_keys($occupied) as $townId) {
            if (isset($seen[$townId])) {
                continue;
            }

            $component = [];
            $frontier = [$townId];
            $seen[$townId] = true;

            while ($frontier) {
                $current = array_pop($frontier);
                $component[] = $current;
                foreach ($towns[$current]['neighbors'] as $neighbor) {
                    if (isset($occupied[$neighbor]) && !isset($seen[$neighbor])) {
                        $seen[$neighbor] = true;
                        $frontier[] = $neighbor;
                    }
                }
            }

            sort($component);
            $components[] = $component;
        }

        return $components;
    }

    /**
     * @param array<string, array> $towns
     * @return string[] the network containing this town, empty if it holds none
     */
    public static function componentOf(array $towns, string $townId): array
    {
        foreach (self::components($towns) as $component) {
            if (in_array($townId, $component, true)) {
                return $component;
            }
        }
        return [];
    }

    /**
     * How much supply a network has to keep troops standing.
     *
     * A raw supply total now rather than a troop count divided down — the
     * division used to happen here because every troop cost the same, but
     * full rules (issue #6) can mix in Shock Troops at double cost, so the
     * only place left to divide is `troopsIn`, troop by troop and type by
     * type. The two are still compared the same way everywhere: `troopsIn
     * <= ceiling`. Numerically identical to the old formula whenever
     * supplyPerTroop is 1, which is every scenario that has ever been
     * tuned; only the stale control scenarios at 2 (CLAUDE.md) could tell
     * the difference, and only once something costs more than 1.
     *
     * @param array<string, array> $towns
     * @param string[] $component
     */
    public static function ceiling(array $towns, array $component): int
    {
        $supply = 0;
        foreach ($component as $townId) {
            $supply += self::townSupply($towns[$townId]);
        }
        return $supply;
    }

    /**
     * What a town gives the Empire, which is nothing once the rebels have won it.
     *
     * An Insurgency victory denies the ground permanently. The Empire may march
     * back in — the town is resolved, so it can never be contested again — and
     * it will hold a line, but it will never feed one. That is what makes
     * taking a town worth something lasting to a side that cannot build a
     * network of its own: it does not capture supply, it destroys it.
     *
     * @param array{resolved: bool, winner: ?string, supply: int} $town
     */
    public static function townSupply(array $town): int
    {
        if ($town['resolved'] && $town['winner'] === self::INSURGENCY) {
            return 0;
        }
        return (int) $town['supply'];
    }

    /** As with supply: a town the rebels took never builds for the Empire again. */
    public static function townProduction(array $town): int
    {
        if ($town['resolved'] && $town['winner'] === self::INSURGENCY) {
            return 0;
        }
        return (int) $town['production'];
    }

    /**
     * What this network's whole garrison costs it, by type — not a head
     * count. See `ceiling` above for why, and `townSupplyCost` for the sum.
     * `$supplyPerTroop` defaults to 1 so old 2-argument calls (every one
     * before full rules) keep returning exactly the head count they always
     * did; a real caller should pass the scenario's own value.
     *
     * @param array<string, array> $towns
     * @param string[] $component
     */
    public static function troopsIn(array $towns, array $component, int $supplyPerTroop = 1): int
    {
        $cost = 0;
        foreach ($component as $townId) {
            $cost += self::townSupplyCost($towns[$townId], $supplyPerTroop);
        }
        return $cost;
    }

    /**
     * Towns the Empire holds that can build and can afford to.
     *
     * @param array<string, array> $towns
     * @return string[]
     */
    /**
     * Whether a town is the Empire's to build in — held, or taken.
     *
     * Standing in a town counts, and so does having *won* it: a town the Empire
     * took at a resolution is permanently its ground, so it goes on building
     * once the garrison has marched away or starved. A town that is merely
     * empty is nobody's, which is what stops the Empire drawing troops out of a
     * factory it has never been near.
     *
     * @param array<string, mixed> $town
     */
    public static function empireHolds(array $town): bool
    {
        if ($town['resolved']) {
            return $town['winner'] === self::EMPIRE;
        }
        return self::townTotalTroops($town) > 0;
    }

    public static function productionSites(array $towns, int $productionCost): array
    {
        $sites = [];
        foreach ($towns as $townId => $town) {
            if (self::productionCapacity($towns, $townId, $productionCost) > 0) {
                $sites[] = $townId;
            }
        }
        return $sites;
    }

    /**
     * Whether the Empire can never act again: no troops on the board, and no
     * town left that will build it any. There is no move it could make for the
     * rest of the game, so there is no game left.
     *
     * @param array<string, array> $towns
     */
    public static function empireIsEliminated(array $towns, int $productionCost): bool
    {
        foreach ($towns as $town) {
            if (self::townTotalTroops($town) > 0) {
                return false;
            }
        }

        return self::productionSites($towns, $productionCost) === [];
    }

    /**
     * How many troops a town could raise this turn, ignoring the ceiling.
     *
     * @param array<string, array> $towns
     */
    public static function productionCapacity(array $towns, string $townId, int $productionCost): int
    {
        if (!isset($towns[$townId]) || $productionCost <= 0) {
            return 0;
        }
        if (!self::empireHolds($towns[$townId])) {
            return 0;
        }
        return intdiv(self::townProduction($towns[$townId]), $productionCost);
    }

    /**
     * Spare ceiling in the network this town belongs to.
     *
     * @param array<string, array> $towns
     */
    public static function headroom(array $towns, string $townId, int $supplyPerTroop): int
    {
        $component = self::componentOf($towns, $townId);
        if (!$component) {
            return 0;
        }
        return max(
            0,
            self::ceiling($towns, $component) - self::troopsIn($towns, $component, $supplyPerTroop),
        );
    }

    /**
     * Where a network's excess would be taken from, if it were taken now.
     *
     * The Empire's own choices are honoured first, then the largest garrisons,
     * so a turn is always legal even when it names nowhere. Within a town there
     * is no choice to make: a town holds a count of troops, not troops.
     *
     * This is used twice each turn — once to decide what actually starves, and
     * once to forecast what will starve next turn if nothing changes.
     *
     * "Size" and the plan's values are both in supply points, not a head
     * count, now that full rules (issue #6) can make a troop cost more
     * than one — a town with no Shock Troops costs exactly its troop
     * count, so nothing here changes before full rules are on.
     *
     * @param array<string, array> $towns
     * @param array<string, int> $disband  the Empire's preferences, in supply points
     * @return array<string, int> town id => supply points lost
     */
    public static function attritionPlan(array $towns, int $supplyPerTroop, array $disband = []): array
    {
        $losses = [];

        foreach (self::components($towns) as $component) {
            $over = self::troopsIn($towns, $component, $supplyPerTroop)
                - self::ceiling($towns, $component);
            if ($over <= 0) {
                continue;
            }

            $order = [];
            foreach ($component as $townId) {
                if (($disband[$townId] ?? 0) > 0) {
                    $order[] = $townId;
                }
            }
            $rest = $component;
            usort(
                $rest,
                static fn(string $a, string $b) =>
                    self::townSupplyCost($towns[$b], $supplyPerTroop) <=> self::townSupplyCost($towns[$a], $supplyPerTroop),
            );
            $order = array_merge($order, $rest);

            $taken = 0;
            foreach ($order as $townId) {
                if ($taken >= $over) {
                    break;
                }
                $already = $losses[$townId] ?? 0;
                $available = self::townSupplyCost($towns[$townId], $supplyPerTroop) - $already;
                // A town named in $disband gives up that many points and no
                // more; one that is not named gives up everything it has if
                // it comes to it.
                $cap = isset($disband[$townId]) ? $disband[$townId] - $already : $available;
                $take = min($available, $over - $taken, max(0, $cap));
                if ($take > 0) {
                    $losses[$townId] = $already + $take;
                    $taken += $take;
                }
            }
        }

        return $losses;
    }

    /**
     * The networks that lose troops at the end of this turn, and how many each.
     *
     * Judged on the board after building and marching: a network carrying a
     * starvation mark from last turn (any of its towns) that is *still* over
     * its ceiling. One that has only just gone short is marked, not starved,
     * and appears nowhere here.
     *
     * @param array<string, array> $towns the board after this turn's moves
     * @return array<int, array{towns: string[], over: int}>
     */
    public static function requiredLosses(array $towns, int $supplyPerTroop): array
    {
        $required = [];
        foreach (self::components($towns) as $component) {
            $warned = false;
            foreach ($component as $townId) {
                if (($towns[$townId]['starving'] ?? 0) > 0) {
                    $warned = true;
                    break;
                }
            }
            $over = self::troopsIn($towns, $component, $supplyPerTroop)
                - self::ceiling($towns, $component);
            if ($warned && $over > 0) {
                $required[] = ['towns' => $component, 'over' => $over];
            }
        }
        return $required;
    }

    /**
     * A person's choice of where starvation falls, checked to be the whole of it.
     *
     * The Empire chooses which troops a starving army loses, and has to choose
     * all of them: exactly the shortfall in every network that starves, from
     * troops standing in that network, and nowhere else. Bots are not held to
     * this — their `disband` is a preference, with the largest garrisons
     * making up any remainder — so it is checked by the state action rather
     * than by `attritionPlan`.
     *
     * @param array<string, array> $towns the board after this turn's moves
     * @param array<string, int> $disband town id => troops to lose there
     */
    public static function validateDisband(array $towns, int $supplyPerTroop, array $disband): void
    {
        $required = self::requiredLosses($towns, $supplyPerTroop);

        $networkOf = [];
        foreach ($required as $index => $network) {
            foreach ($network['towns'] as $townId) {
                $networkOf[$townId] = $index;
            }
        }

        $chosen = array_fill(0, count($required), 0);
        foreach ($disband as $townId => $count) {
            $count = (int) $count;
            if ($count <= 0) {
                throw new IllegalMove("a loss at {$townId} must be positive");
            }
            if (!isset($networkOf[$townId])) {
                throw new IllegalMove("{$townId} is not in an army that starves this turn");
            }
            $cost = self::townSupplyCost($towns[$townId], $supplyPerTroop);
            if ($count > $cost) {
                throw new IllegalMove("{$townId} can lose at most {$cost} points of supply, cannot lose {$count}");
            }
            $chosen[$networkOf[$townId]] += $count;
        }

        foreach ($required as $index => $network) {
            if ($chosen[$index] !== $network['over']) {
                $where = implode(', ', $network['towns']);
                throw new IllegalMove(
                    "the army at {$where} must lose {$network['over']} points of supply, {$chosen[$index]} chosen"
                );
            }
        }
    }

    /**
     * The presence requirement (Decision 5): you may only resolve a town you
     * are actually in. Without it the Empire freezes empty towns from anywhere
     * for free.
     *
     * @param array<string, array> $towns
     */
    public static function canDeclare(array $towns, string $townId, string $side): bool
    {
        if (!isset($towns[$townId])) {
            return false;
        }
        $town = $towns[$townId];
        if ($town['resolved']) {
            return false;
        }
        return $side === self::EMPIRE
            ? self::townTotalTroops($town) > 0
            : self::townCardCount($town) > 0;
    }

    /**
     * @param array<string, array> $towns
     * @return string[]
     */
    /**
     * Whether nobody has committed anything here — no troops, no cards.
     *
     * Presence is required to *declare* a resolution (Decision 5), and the same
     * rule applies to the sweep at the end of the game: a town neither side
     * ever set foot in is left open rather than handed to whoever wins ties. It
     * is worth nothing to either side by definition, and reading "the Empire
     * takes it for 0" about a town nobody was ever in is just noise.
     *
     * @param array<string, mixed> $town
     */
    public static function townIsUncontested(array $town): bool
    {
        return self::townTotalTroops($town) === 0 && self::townCardCount($town) === 0;
    }

    public static function legalResolutions(array $towns, string $side): array
    {
        $ids = [];
        foreach (self::unresolvedTownIds($towns) as $townId) {
            if (self::canDeclare($towns, $townId, $side)) {
                $ids[] = $townId;
            }
        }
        return $ids;
    }

    // -- resolution ---------------------------------------------------------

    /**
     * Who takes a town, and what the winner scores.
     *
     * You score what you take off the opponent: the Empire banks the presence
     * it suppressed, the Insurgency banks the presence it absorbed. When the
     * table scores locations, the winner also banks the town's value — either
     * side, the same value (issue #1). The Empire wins ties (Decision 7).
     *
     * `points` is the presence alone and `locationPoints` the value alone, so
     * the two can be reported and counted apart.
     *
     * @return array{winner: string, cardPresence: int, troopPresence: int, points: int, locationPoints: int}
     */
    public static function resolutionOutcome(int $cardPresence, int $troopPresence, bool $empireWinsTies, int $locationValue = 0): array
    {
        if ($troopPresence > $cardPresence) {
            $winner = self::EMPIRE;
        } elseif ($cardPresence > $troopPresence) {
            $winner = self::INSURGENCY;
        } else {
            $winner = $empireWinsTies ? self::EMPIRE : self::INSURGENCY;
        }

        return [
            'winner' => $winner,
            'cardPresence' => $cardPresence,
            'troopPresence' => $troopPresence,
            'points' => $winner === self::EMPIRE ? $cardPresence : $troopPresence,
            'locationPoints' => $locationValue,
        ];
    }

    /**
     * Resolution outcome for one town on the given board.
     *
     * @param array<string, array> $towns
     * @param int $locationValue the town's value, or 0 when locations are not scored
     * @return array{winner: string, cardPresence: int, troopPresence: int, points: int, locationPoints: int}
     */
    public static function resolveTown(array $towns, string $townId, int $unitPresence, bool $empireWinsTies, int $locationValue = 0): array
    {
        if (!isset($towns[$townId])) {
            throw new IllegalMove("unknown town {$townId}");
        }
        if ($towns[$townId]['resolved']) {
            throw new IllegalMove("{$townId} is already resolved");
        }

        return self::resolutionOutcome(
            self::cardPresence($towns[$townId]),
            self::townTroopPresence($towns[$townId], $unitPresence),
            $empireWinsTies,
            $locationValue,
        );
    }

    // -- the tie-breaker ----------------------------------------------------

    /**
     * What breaks a level score, in order (2026-10-09): locations resolved in
     * your favour, then presence still on the board — the Empire's standing
     * troops, the rebels' agents left in the locations they won or in an open
     * pile — and finally the Empire, as it wins ties everywhere else
     * (Decision 7). Mirrors `tiebreak` in sim/engine.py.
     *
     * @param array<string, array> $towns
     * @return array{0: int, 1: int, 2: int}
     */
    public static function tiebreak(array $towns, string $side, int $unitPresence): array
    {
        $locations = 0;
        $presence = 0;
        foreach ($towns as $town) {
            if ($town['resolved'] && $town['winner'] === $side) {
                $locations++;
            }
            $presence += $side === self::EMPIRE
                ? self::townTroopPresence($town, $unitPresence)
                : self::cardPresence($town);
        }
        return [$locations, $presence, $side === self::EMPIRE ? 1 : 0];
    }

    /**
     * The tie-breaker as one number for the auxiliary score, which BGA splits
     * back into its parts with `tie_breaker_split` [10000, 100, 1] in
     * gameinfos.jsonc. Presence stays under 100: the whole deck is 36 and the
     * map feeds 24 troops.
     */
    public static function tiebreakAux(array $tiebreak): int
    {
        return $tiebreak[0] * 10000 + $tiebreak[1] * 100 + $tiebreak[2];
    }

    // -- the Insurgency turn ------------------------------------------------

    /**
     * Validate a hand assignment.
     *
     * The entire hand must be placed every turn (Decision 6). That is what
     * makes pile height uninformative and what makes the deck an exact clock,
     * so it is enforced here rather than left to the interface.
     *
     * @param array<string, array> $towns
     * With `$mayHold` (game option 104) any part of the hand may be placed,
     * or none of it: Decision 6 relaxed, the rest stays in hand.
     *
     * @param int[] $hand              card ids currently in hand
     * @param array<string, int[]> $placements  town id => card ids
     */
    public static function validatePlacements(array $towns, array $hand, array $placements, bool $mayHold = false): void
    {
        $placed = [];
        foreach ($placements as $townId => $cardIds) {
            if (!isset($towns[$townId])) {
                throw new IllegalMove("unknown town {$townId}");
            }
            if ($towns[$townId]['resolved']) {
                throw new IllegalMove("{$townId} is resolved; no cards may be placed there");
            }
            foreach ($cardIds as $cardId) {
                $placed[] = (int) $cardId;
            }
        }

        if ($mayHold) {
            $inHand = array_map('intval', $hand);
            if (count(array_unique($placed)) !== count($placed) || array_diff($placed, $inHand)) {
                throw new IllegalMove(sprintf(
                    'placements must name distinct cards from the hand: got [%s], hand is [%s]',
                    implode(',', $placed),
                    implode(',', $inHand),
                ));
            }
            return;
        }

        $sortedPlaced = $placed;
        $sortedHand = array_map('intval', $hand);
        sort($sortedPlaced);
        sort($sortedHand);

        if ($sortedPlaced !== $sortedHand) {
            throw new IllegalMove(sprintf(
                'the entire hand must be placed exactly once: got [%s], hand is [%s]',
                implode(',', $sortedPlaced),
                implode(',', $sortedHand),
            ));
        }
    }

    // -- the Empire turn ----------------------------------------------------

    /**
     * Check a set of builds: that the Empire holds the town, and the town's own
     * production rate.
     *
     * A *garrison* is not required, only presence or ownership — requiring
     * troops on the spot was a chicken-and-egg, since an Empire that lost the
     * last troop in a factory it had already won could never raise another
     * there. An empty town nobody has taken is nobody's.
     *
     * Supply is deliberately not checked. The ceiling caps how many troops a
     * network can *keep*, not how many it can raise — treating it as both made
     * it two rules wearing one name, and it stopped the Empire building troops
     * and marching them out to supply in the same turn. Overshoot is settled by
     * attrition, which now gives a turn of warning first, so it is a stated
     * risk rather than an ambush.
     *
     * @param array<string, array> $towns
     * @param array<string, int> $produce town id => troops to raise there
     */
    public static function validateProduction(
        array $towns,
        array $produce,
        int $productionCost,
    ): void {
        foreach ($produce as $townId => $count) {
            $count = (int) $count;
            if ($count <= 0) {
                throw new IllegalMove('build count must be positive');
            }
            if (!isset($towns[$townId])) {
                throw new IllegalMove("unknown town {$townId}");
            }
            if (!self::empireHolds($towns[$townId])) {
                throw new IllegalMove("cannot build at {$townId}: the Empire does not hold it");
            }
            $capacity = self::productionCapacity($towns, $townId, $productionCost);
            if ($count > $capacity) {
                throw new IllegalMove("{$townId} can build {$capacity}, asked for {$count}");
            }
        }
    }

    /**
     * Work out the net troop movement, checking adjacency and supply.
     *
     * Movement is simultaneous: everyone leaves, then everyone arrives. Resolved
     * towns are ordinary terrain — pacified, passable, simply no longer
     * contestable — so troops move freely in and out of them.
     *
     * @param array<string, array> $towns
     * @param array<int, array{0: string, 1: string, 2: int}> $moves  [from, to, count]
     * @return array{departures: array<string, int>, arrivals: array<string, int>}
     */
    public static function planMoves(array $towns, array $moves): array
    {
        $departures = [];
        $arrivals = [];

        foreach ($moves as [$from, $to, $count]) {
            $count = (int) $count;
            if ($count <= 0) {
                throw new IllegalMove('move count must be positive');
            }
            if (!isset($towns[$from]) || !isset($towns[$to])) {
                throw new IllegalMove("unknown town in move {$from} -> {$to}");
            }
            if (!in_array($to, $towns[$from]['neighbors'], true)) {
                throw new IllegalMove("{$to} is not adjacent to {$from}");
            }

            $departures[$from] = ($departures[$from] ?? 0) + $count;
            if ($departures[$from] > $towns[$from]['troops']) {
                throw new IllegalMove(sprintf(
                    '%s has %d troops, tried to move %d',
                    $from,
                    $towns[$from]['troops'],
                    $departures[$from],
                ));
            }
            $arrivals[$to] = ($arrivals[$to] ?? 0) + $count;
        }

        return ['departures' => $departures, 'arrivals' => $arrivals];
    }

    /**
     * How many cards each town's pile gets peeked at.
     *
     * Every troop that did not move looks; peeks stack per town. There is no
     * decision in it — looking is free, always available to a stationary troop,
     * and never disadvantageous — so it happens automatically at the end of the
     * move step rather than as a prompt.
     *
     * `$towns` must already reflect generation and movement. A troop raised
     * this turn counts as stationary: it did not move.
     *
     * A town whose pile is entirely face up has nothing left to read, so it is
     * skipped: the pile only ever holds cards nobody has seen.
     *
     * @param array<string, array> $towns    board after generation and movement
     * @param array<string, int> $arrivals   from planMoves()
     * @return array<string, int>            town id => number of cards to look at
     */
    public static function peekPlan(array $towns, array $arrivals, int $peekPerTroop): array
    {
        $looks = [];
        foreach ($towns as $townId => $town) {
            if ($town['resolved'] || count($town['pile']) === 0) {
                continue;
            }
            $stationary = max(0, (int) $town['troops'] - ($arrivals[$townId] ?? 0));
            // The Counterinsurgency Unit never marches (full rules, issue #6),
            // so every one present is stationary by definition and always
            // adds its own peek, on top of the base unit's.
            $own = $stationary * $peekPerTroop + ((int) ($town['troopsCi'] ?? 0)) * self::CI_PEEK;
            if ($own > 0) {
                $looks[$townId] = $own;
            }
        }
        return $looks;
    }

    /**
     * Full rules only (issue #6): extra looks a neighbouring Counterinsurgency
     * Unit grants, keyed by the town being *read* rather than the one the
     * unit stands in — add this to peekPlan's own-town counts, not instead of
     * them. Needs no presence at all in the town it reveals: the whole point
     * of the unit is seeing ground the Empire does not hold.
     *
     * @param array<string, array> $towns  board after generation and movement
     * @return array<string, int>          town id => extra cards to look at
     */
    public static function neighborPeekPlan(array $towns): array
    {
        $looks = [];
        foreach ($towns as $town) {
            $ci = (int) ($town['troopsCi'] ?? 0);
            if ($ci <= 0) {
                continue;
            }
            $each = $ci * self::CI_REVEAL_NEIGHBORS;
            if ($each <= 0) {
                continue;
            }
            foreach ($town['neighbors'] as $neighborId) {
                $neighbor = $towns[$neighborId];
                if ($neighbor['resolved'] || count($neighbor['pile']) === 0) {
                    continue;
                }
                $looks[$neighborId] = ($looks[$neighborId] ?? 0) + $each;
            }
        }
        return $looks;
    }

    /**
     * Remove troops worth at least `$points` of supply, and report how many
     * troops and how much presence actually left the board.
     *
     * `attritionPlan` works in supply points, which a town with no Shock
     * Troops cannot tell apart from a head count — this is the one place
     * points turn back into actual troops, by type, and only here does the
     * choice of *which* type matter.
     *
     * Order is the Counterinsurgency Unit first, then the scenario's base
     * unit, Shock Troops last: CI carries no presence, so losing it first
     * costs nothing in the fight and hands the Insurgency nothing either —
     * strictly the cheapest loss on both counts, not a guess at which is
     * "worst." Between the other two, the cheaper-to-lose base unit goes
     * before Shock Troops, so an army sheds its least valuable combat
     * troops first and keeps its most expensive ones as long as the points
     * allow.
     *
     * @param array{troops: int, troopsCi?: int, troopsShock?: int} $town
     * @return array{troops: int, troopsCi: int, troopsShock: int, presence: int}
     *         how many of each type to remove, and the presence that frees
     */
    public static function splitLossByType(array $town, int $points, int $unitPresence, int $supplyPerTroop): array
    {
        $removed = ['troops' => 0, 'troopsCi' => 0, 'troopsShock' => 0];
        $presenceLost = 0;
        $freed = 0;

        $types = [
            ['field' => 'troopsCi', 'cost' => $supplyPerTroop, 'presence' => self::CI_PRESENCE],
            ['field' => 'troops', 'cost' => $supplyPerTroop, 'presence' => $unitPresence],
            ['field' => 'troopsShock', 'cost' => self::SHOCK_SUPPLY_COST, 'presence' => self::SHOCK_PRESENCE],
        ];

        foreach ($types as $type) {
            if ($freed >= $points) {
                break;
            }
            $available = (int) ($town[$type['field']] ?? 0);
            while ($available > 0 && $freed < $points) {
                $available--;
                $freed += $type['cost'];
                $removed[$type['field']]++;
                $presenceLost += $type['presence'];
            }
        }

        return $removed + ['presence' => $presenceLost];
    }

    /**
     * Flip the top cards of the face-down pile face up (Decision 8).
     *
     * There is no rotation and nothing to cap. The pile holds only cards nobody
     * has seen, so a look can never land on one that is already known, and when
     * the pile is empty the garrison has read the town.
     *
     * @param array<int, array{id: int}> $pile  face-down cards, index 0 = top
     * @return int[]                            ids of the cards turned face up
     */
    public static function revealFromPile(array $pile, int $lookCount): array
    {
        return array_map(
            static fn(array $card) => (int) $card['id'],
            array_slice(array_values($pile), 0, max(0, $lookCount)),
        );
    }

    /**
     * Whether a proposed deck order is legal: advanced rules (option 105,
     * issue #6), the one-time arrangement before play begins. It must name
     * exactly the cards currently in the deck, each once — nothing more,
     * nothing missing, no repeats.
     *
     * @param int[] $deckCardIds the deck's current card ids, any order
     * @param int[] $order       the Rebels' proposed order, index 0 drawn next
     */
    public static function validateDeckOrder(array $deckCardIds, array $order): void
    {
        $current = array_map('intval', $deckCardIds);
        $proposed = array_map('intval', $order);

        $sortedCurrent = $current;
        $sortedProposed = $proposed;
        sort($sortedCurrent);
        sort($sortedProposed);

        if ($sortedCurrent !== $sortedProposed) {
            throw new IllegalMove(sprintf(
                'deck order must name exactly the cards in the deck, once each: got [%s], deck is [%s]',
                implode(',', $proposed),
                implode(',', $current),
            ));
        }
    }
}
