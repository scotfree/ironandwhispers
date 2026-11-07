<?php
/**
 * Full rules (issue #6, game option 105): two more Empire unit types.
 *
 * Mirrors sim/test_full_rules.py case for case. The Counterinsurgency Unit
 * adds no presence but reads an extra card in its own town and one in every
 * neighbour's; Shock Troops add double presence at double supply cost. Both
 * are build-only and never march, so no bot ever needs to know they exist —
 * what has to be right is that the shared formulas (presence, supply cost,
 * occupancy, peeking, attrition) still work when they are 0, which is every
 * game before full rules, and work correctly when they are not.
 *
 * Most of these test Rules.php directly, the same pure-array style as
 * test_rules.php. A few — clearing all three columns on a loss, and a build
 * actually landing in the right column with the right notification — need
 * Board.php or the state machine, so they go through newGame() instead.
 */
declare(strict_types=1);

use Bga\Games\IronAndWhisper\Game;
use Bga\Games\IronAndWhisper\IllegalMove;
use Bga\Games\IronAndWhisper\Rules;
use Bga\Games\IronAndWhisper\States\InsurgencyTurn;

// The base unit's own presence, chosen unequal to any troop count below so a
// mismatched test would show up as a wrong number rather than a coincidence —
// the same reason test_engine.py's INFANTRY fixture is 3, not 1.
const FR_PRESENCE = 3;

function frTown(array $overrides = []): array
{
    return array_merge(
        [
            'neighbors' => [], 'troops' => 0, 'troopsCi' => 0, 'troopsShock' => 0,
            'resolved' => false, 'winner' => null,
            'pile' => [], 'revealed' => [],
            'supply' => 1, 'production' => 0,
        ],
        $overrides,
    );
}

/** A path graph a-b-c, the simplest thing with real adjacency. */
function frBoard(array $overrides = []): array
{
    $towns = [
        'a' => frTown(['neighbors' => ['b']]),
        'b' => frTown(['neighbors' => ['a', 'c']]),
        'c' => frTown(['neighbors' => ['b']]),
    ];
    foreach ($overrides as $townId => $override) {
        $towns[$townId] = array_merge($towns[$townId], $override);
    }
    return $towns;
}

/** `$count` face-down cards, each worth `$presence`. */
function frPile(int $count, int $presence = 1, int $firstId = 1): array
{
    $pile = [];
    for ($i = 0; $i < $count; $i++) {
        $pile[] = ['id' => $firstId + $i, 'type' => "presence{$presence}", 'presence' => $presence];
    }
    return $pile;
}

// -- presence -----------------------------------------------------------

function test_shock_troops_add_double_presence_at_resolution(): void
{
    $towns = frBoard(['a' => ['troopsShock' => 2, 'pile' => frPile(3, 1)]]);
    $outcome = Rules::resolveTown($towns, 'a', FR_PRESENCE, true);
    assertSame(Rules::EMPIRE, $outcome['winner']);
    assertSame(3, $outcome['points']);
}

function test_counterinsurgency_adds_no_presence_at_resolution(): void
{
    $towns = frBoard(['a' => ['troopsCi' => 5, 'pile' => frPile(1, 1)]]);
    $outcome = Rules::resolveTown($towns, 'a', FR_PRESENCE, true);
    assertSame(Rules::INSURGENCY, $outcome['winner'], 'presence 0, whatever the count');
}

function test_the_three_types_add_up(): void
{
    $town = frTown(['troops' => 2, 'troopsCi' => 3, 'troopsShock' => 2]);
    assertSame(10, Rules::townTroopPresence($town, FR_PRESENCE), '2*3 + 3*0 + 2*2');
}

function test_tiebreak_presence_includes_all_three_types(): void
{
    $towns = frBoard(['a' => ['resolved' => true, 'winner' => Rules::EMPIRE, 'troopsShock' => 1]]);
    $tiebreak = Rules::tiebreak($towns, Rules::EMPIRE, FR_PRESENCE);
    assertSame(2, $tiebreak[1]);
}

// -- occupancy: a garrison of only special units still holds the ground -

function test_a_counterinsurgency_only_garrison_still_occupies_the_town(): void
{
    $towns = frBoard(['a' => ['troopsCi' => 1]]);
    assertTrue(Rules::empireHolds($towns['a']));
    assertSame(['a'], Rules::componentOf($towns, 'a'));
}

function test_a_shock_only_garrison_still_blocks_elimination(): void
{
    $towns = frBoard(['a' => ['troopsShock' => 1]]); // nothing to build anywhere
    assertFalse(Rules::empireIsEliminated($towns, 1));
}

function test_a_town_with_no_troops_of_any_type_is_uncontested(): void
{
    $town = frTown();
    assertTrue(Rules::townIsUncontested($town));
    $town['troopsCi'] = 1;
    assertFalse(Rules::townIsUncontested($town));
}

function test_losing_a_town_clears_all_three_troop_types(): void
{
    $game = newGame();
    $game->board->adjustTroops(['everlan' => 2]);
    $game->board->adjustTroops(['everlan' => 1], 'troops_ci');
    $game->board->adjustTroops(['everlan' => 1], 'troops_shock');

    $game->board->markResolved('everlan', [
        'winner' => Rules::INSURGENCY,
        'cardPresence' => 20,
        'troopPresence' => 0,
    ]);

    $town = $game->board->towns()['everlan'];
    assertSame(0, $town['troops']);
    assertSame(0, $town['troopsCi']);
    assertSame(0, $town['troopsShock']);
}

// -- supply: Shock Troops cost double ------------------------------------

function test_shock_troops_cost_double_supply(): void
{
    $towns = frBoard(['a' => ['supply' => 2, 'troopsShock' => 1]]);
    $component = Rules::componentOf($towns, 'a');
    assertSame(Rules::ceiling($towns, $component), Rules::troopsIn($towns, $component, 1), 'exactly full');

    $towns['a']['troopsShock'] = 2;
    assertTrue(Rules::troopsIn($towns, $component, 1) > Rules::ceiling($towns, $component));
}

function test_mixed_garrison_costs_are_added_not_counted(): void
{
    $towns = frBoard(['a' => ['supply' => 3, 'troops' => 1, 'troopsShock' => 1]]);
    $component = Rules::componentOf($towns, 'a');
    assertSame(3, Rules::troopsIn($towns, $component, 1));
    assertSame(Rules::ceiling($towns, $component), Rules::troopsIn($towns, $component, 1));
}

// -- peeking: the Counterinsurgency Unit's own look, and its neighbours' -

function test_counterinsurgency_reads_two_cards_in_its_own_town(): void
{
    $towns = frBoard(['a' => ['troopsCi' => 1, 'pile' => frPile(3)]]);
    $looks = Rules::peekPlan($towns, [], 1);
    assertSame(2, $looks['a'] ?? 0);
}

function test_counterinsurgency_reveals_one_card_in_every_neighbour(): void
{
    $towns = frBoard(['a' => ['troopsCi' => 1], 'b' => ['pile' => frPile(2)]]);
    $extra = Rules::neighborPeekPlan($towns);
    assertSame(1, $extra['b'] ?? 0, "a's only neighbour");
    assertFalse(isset($extra['c']), 'two hops away');
}

function test_counterinsurgency_needs_no_presence_in_the_town_it_reveals(): void
{
    // The whole point of the unit: it sees ground the Empire does not hold.
    $towns = frBoard(['a' => ['troopsCi' => 1], 'b' => ['pile' => frPile(1)]]);
    assertSame(0, Rules::townTotalTroops($towns['b']), 'the Empire is not in b at all');
    $extra = Rules::neighborPeekPlan($towns);
    assertSame(1, $extra['b'] ?? 0);
}

function test_two_counterinsurgency_units_reveal_two_cards_in_a_neighbour(): void
{
    $towns = frBoard(['a' => ['troopsCi' => 2], 'b' => ['pile' => frPile(3)]]);
    $extra = Rules::neighborPeekPlan($towns);
    assertSame(2, $extra['b'] ?? 0);
}

function test_a_marched_troop_does_not_peek_but_counterinsurgency_still_does(): void
{
    // Board as it stands after the move: the departed troop no longer
    // counts, and the CI unit, which never marches, is still there.
    $towns = frBoard(['a' => ['troopsCi' => 1, 'pile' => frPile(5)]]);
    $looks = Rules::peekPlan($towns, [], 1);
    assertSame(2, $looks['a'] ?? 0, "the CI unit's own peek, and nothing for the troop that left");
}

// -- building: one capacity, shared across the three types --------------

function test_build_may_split_capacity_across_the_three_types(): void
{
    $towns = frBoard(['a' => ['troops' => 1, 'production' => 2]]);
    Rules::validateProduction($towns, ['a' => 2], 1); // 1 produce + 1 produce_ci, capacity 2
}

function test_build_is_refused_past_the_towns_combined_capacity(): void
{
    $towns = frBoard(['a' => ['troops' => 1, 'production' => 1]]); // capacity 1
    assertThrows(IllegalMove::class, fn() => Rules::validateProduction($towns, ['a' => 2], 1));
}

function test_building_shock_troops_notifies_the_type(): void
{
    $game = newGame();
    assertSame(InsurgencyTurn::class, enterNextTurn($game));

    $insurgency = $game->playerIdForSide(Rules::INSURGENCY);
    $empire = $game->playerIdForSide(Rules::EMPIRE);
    $hand = $game->board->handCardIds();
    insurgencyTurn($game)->actCommitTurn(['ashford' => $hand], '0', $insurgency);

    resolvePhaseIfAny($game);
    $game->bga->notify->clear();
    empireTurn($game)->actCommitTurn([], [], [], '0', $empire, [], ['everlan' => 1]);

    assertSame(1, $game->board->towns()['everlan']['troopsShock'], 'the build landed in the right column');

    $sent = $game->bga->notify->of('built');
    assertTrue(
        (bool) array_filter($sent, static fn(array $n) => str_contains((string) $n['message'], 'Shock Troops')),
        'the notification names the type',
    );
}

// -- attrition: points, not a head count, and a sane default priority ---

function test_attrition_catches_overage_a_head_count_would_miss(): void
{
    // Two Shock Troops look like two troops; they cost a network four.
    $towns = frBoard(['a' => ['supply' => 3, 'troopsShock' => 2]]);
    $component = Rules::componentOf($towns, 'a');
    assertSame(1, Rules::troopsIn($towns, $component, 1) - Rules::ceiling($towns, $component));
}

function test_attrition_sheds_counterinsurgency_before_combat_troops(): void
{
    // CI carries neither combat value nor the Insurgency's score, so it is
    // the cheapest thing to lose first.
    $town = frTown(['troops' => 1, 'troopsCi' => 1]);
    $removed = Rules::splitLossByType($town, 1, FR_PRESENCE, 1);
    assertSame(0, $removed['troops'], 'the combat troop survives');
    assertSame(1, $removed['troopsCi'], 'the CI unit starves instead');
}

function test_attrition_scores_what_actually_starves_not_a_flat_rate(): void
{
    // A starved Shock Troop pays its real presence (2), not the base unit's
    // (3, this file's FR_PRESENCE).
    $town = frTown(['troopsShock' => 1]);
    $removed = Rules::splitLossByType($town, 2, FR_PRESENCE, 1);
    assertSame(1, $removed['troopsShock']);
    assertSame(2, $removed['presence']);
}

function test_validate_disband_is_denominated_in_supply_points(): void
{
    $towns = frBoard(['a' => ['supply' => 0, 'troopsShock' => 1, 'starving' => 2]]);
    // A head count would say "there is only one"; the real shortfall is 2 points.
    assertThrows(IllegalMove::class, fn() => Rules::validateDisband($towns, 1, ['a' => 1]));
    Rules::validateDisband($towns, 1, ['a' => 2]);
}
