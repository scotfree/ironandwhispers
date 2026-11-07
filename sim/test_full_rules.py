"""Full rules (issue #6, game option 105): two more Empire unit types.

The Counterinsurgency Unit adds no presence but reads an extra card in its
own town and one in every neighbour's, and Shock Troops add double presence
at double supply cost. Both are build-only — a human choice at the popup —
and never march (`Town.troops_ci` / `Town.troops_shock`), so no bot ever
needs to know they exist; what has to be right is that the shared formulas
(presence, supply cost, occupancy, attrition) still work when they are 0,
which is every game before full rules, and work correctly when they are not.

Reuses `scenario`/`state`/`seed_pile`/`line_map` from test_engine.py rather
than building a second fixture set. Their `state()` starts one base-unit
troop at "a" (the scenario's own `empire_start`) and the base unit there has
presence 3 (test_engine.py's INFANTRY, chosen so troop count and presence
read differently in its own tests) — every test below either zeroes that
troop explicitly or accounts for it, since it is easy to trip over.
"""

from __future__ import annotations

from dataclasses import replace

import pytest

from .config import GameMap
from .engine import (
    CI_UNIT,
    SHOCK_UNIT,
    EmpireTurn,
    IllegalMove,
    Side,
    apply_empire_turn,
    component_of,
    ceiling,
    empire_holds,
    empire_is_eliminated,
    resolve_town,
    tiebreak,
    town_is_uncontested,
    town_troop_presence,
    troops_in,
    validate_disband,
)
from .test_engine import line_map, scenario, seed_pile, state


def empire_turn(st, **kwargs) -> None:
    """apply_empire_turn, with the bookkeeping these tests don't care about."""
    st.to_move = Side.EMPIRE
    apply_empire_turn(st, EmpireTurn(**kwargs))


# ---------------------------------------------------------------------------
# Presence
# ---------------------------------------------------------------------------

def test_shock_troops_add_double_presence_at_resolution():
    st = state()
    st.towns["a"].troops = 0
    st.towns["a"].troops_shock = 2  # presence 4
    seed_pile(st, "a", presence=3)
    assert resolve_town(st, "a", Side.EMPIRE) is Side.EMPIRE
    assert st.scores[Side.EMPIRE] == 3


def test_counterinsurgency_adds_no_presence_at_resolution():
    st = state()
    st.towns["a"].troops = 0
    st.towns["a"].troops_ci = 5  # presence 0, whatever the count
    seed_pile(st, "a", presence=1)
    assert resolve_town(st, "a", Side.INSURGENCY) is Side.INSURGENCY


def test_the_three_types_add_up():
    st = state()
    st.towns["a"].troops = 2         # presence 3 each (test_engine's INFANTRY) = 6
    st.towns["a"].troops_ci = 3      # 0
    st.towns["a"].troops_shock = 2   # presence 2 each = 4
    assert town_troop_presence(st.scenario, st.towns["a"]) == 10


def test_tiebreak_presence_includes_all_three_types():
    st = state()
    st.game_over = True
    st.towns["a"].resolved, st.towns["a"].winner = True, Side.EMPIRE
    st.towns["a"].troops = 0
    st.towns["a"].troops_shock = 1  # presence 2
    assert tiebreak(st, Side.EMPIRE)[1] == 2


# ---------------------------------------------------------------------------
# Occupancy: a garrison of only special units still holds the ground
# ---------------------------------------------------------------------------

def test_a_counterinsurgency_only_garrison_still_occupies_the_town():
    st = state()
    st.towns["a"].troops = 0
    st.towns["a"].troops_ci = 1
    assert empire_holds(st, "a")
    assert component_of(st, "a") == {"a"}


def test_a_shock_only_garrison_still_blocks_elimination():
    st = state(map=line_map(producers=()))  # nothing to build anywhere
    st.towns["a"].troops = 0
    st.towns["a"].troops_shock = 1
    assert not empire_is_eliminated(st)


def test_a_town_with_no_troops_of_any_type_is_uncontested():
    st = state()
    st.towns["a"].troops = 0
    assert town_is_uncontested(st.towns["a"])
    st.towns["a"].troops_ci = 1
    assert not town_is_uncontested(st.towns["a"])


def test_losing_a_town_clears_all_three_troop_types():
    st = state()
    st.towns["a"].troops = 1
    st.towns["a"].troops_ci = 1
    st.towns["a"].troops_shock = 1
    seed_pile(st, "a", presence=20)
    resolve_town(st, "a", Side.INSURGENCY)
    assert (st.towns["a"].troops, st.towns["a"].troops_ci, st.towns["a"].troops_shock) == (0, 0, 0)


# ---------------------------------------------------------------------------
# Supply: Shock Troops cost double
# ---------------------------------------------------------------------------

def test_shock_troops_cost_double_supply():
    st = state(map=line_map(supply=2))
    st.towns["a"].troops = 0
    st.towns["a"].troops_shock = 1  # costs 2, the town's whole supply
    component = component_of(st, "a")
    assert troops_in(st, component) == ceiling(st, component)  # exactly full

    st.towns["a"].troops_shock = 2  # costs 4, over a supply of 2
    assert troops_in(st, component) > ceiling(st, component)


def test_mixed_garrison_costs_are_added_not_counted():
    st = state(map=line_map(supply=3))
    st.towns["a"].troops = 1        # costs 1
    st.towns["a"].troops_shock = 1  # costs 2
    component = component_of(st, "a")
    assert troops_in(st, component) == 3
    assert troops_in(st, component) == ceiling(st, component)


# ---------------------------------------------------------------------------
# Peeking: the Counterinsurgency Unit's own look, and its neighbours'
# ---------------------------------------------------------------------------

def test_counterinsurgency_reads_two_cards_in_its_own_town():
    st = state(map=line_map(n=2, supply=10))
    st.towns["a"].troops = 0
    st.towns["a"].troops_ci = 1
    seed_pile(st, "a", presence=3)  # 3 face-down cards
    empire_turn(st)
    assert len(st.towns["a"].revealed) == 2
    assert len(st.towns["a"].pile) == 1


def test_counterinsurgency_reveals_one_card_in_every_neighbour():
    st = state(map=line_map(n=3, supply=10))  # a-b-c
    st.towns["a"].troops = 0
    st.towns["a"].troops_ci = 1
    seed_pile(st, "b", presence=2)
    seed_pile(st, "c", presence=2)  # not a's neighbour
    empire_turn(st)
    assert len(st.towns["b"].revealed) == 1   # a's only neighbour
    assert len(st.towns["c"].revealed) == 0   # two hops away


def test_counterinsurgency_needs_no_presence_in_the_town_it_reveals():
    """The whole point of the unit: it sees ground the Empire does not hold."""
    st = state(map=line_map(n=2, supply=10))
    st.towns["a"].troops = 0
    st.towns["a"].troops_ci = 1
    st.towns["b"].troops = 0  # the Empire is not there at all
    seed_pile(st, "b", presence=1)
    empire_turn(st)
    assert len(st.towns["b"].revealed) == 1


def test_two_counterinsurgency_units_reveal_two_cards_in_a_neighbour():
    st = state(map=line_map(n=2, supply=10))
    st.towns["a"].troops = 0
    st.towns["a"].troops_ci = 2
    seed_pile(st, "b", presence=3)
    empire_turn(st)
    assert len(st.towns["b"].revealed) == 2


def test_a_marched_troop_does_not_peek_but_counterinsurgency_still_does():
    st = state(map=line_map(n=2, supply=10))
    st.towns["a"].troops = 1
    st.towns["a"].troops_ci = 1
    seed_pile(st, "a", presence=5)
    empire_turn(st, moves=[("a", "b", 1)])
    # The infantry marched off and does not peek; the CI unit stayed and does.
    assert len(st.towns["a"].revealed) == 2  # CI's own peek


# ---------------------------------------------------------------------------
# Building: one capacity, shared across the three types
# ---------------------------------------------------------------------------

def _with_production(base: GameMap, town_id: str, production: int) -> GameMap:
    towns = tuple(replace(t, production=production) if t.id == town_id else t for t in base.towns)
    return replace(base, towns=towns)


def test_build_may_split_capacity_across_the_three_types():
    rich = _with_production(line_map(supply=10), "a", production=2)
    st = state(map=rich)
    empire_turn(st, produce={"a": 1}, produce_ci={"a": 1})
    assert st.towns["a"].troops == 1 + 1  # the empire_start troop, plus the build
    assert st.towns["a"].troops_ci == 1


def test_build_is_refused_past_the_towns_combined_capacity():
    st = state(map=line_map(supply=10, producers=("a",)))
    # production_cost=1, town production=1 -> capacity 1. Two different
    # types together still ask for 2.
    with pytest.raises(IllegalMove):
        empire_turn(st, produce={"a": 1}, produce_shock={"a": 1})


def test_building_shock_troops_logs_the_type():
    st = state(map=line_map(supply=10, producers=("a",)))
    empire_turn(st, produce_shock={"a": 1})
    assert st.towns["a"].troops_shock == 1
    assert any("Shock Troops" in line for line in st.log)


# ---------------------------------------------------------------------------
# Attrition: points, not a head count, and a sane default priority
# ---------------------------------------------------------------------------

def test_attrition_catches_overage_a_head_count_would_miss():
    """Two Shock Troops look like two troops; they cost a network four."""
    st = state(map=line_map(supply=3))
    st.towns["a"].troops = 0
    st.towns["a"].troops_shock = 2
    component = component_of(st, "a")
    assert troops_in(st, component) - ceiling(st, component) == 1


def test_attrition_sheds_counterinsurgency_before_combat_troops():
    """The default order protects combat value and the Insurgency's score:
    CI carries neither, so it is the cheapest thing to lose first."""
    st = state(map=line_map(supply=1))
    st.towns["a"].troops = 1
    st.towns["a"].troops_ci = 1
    st.towns["a"].starving = 1  # already warned
    empire_turn(st)
    assert st.towns["a"].troops == 1       # the combat troop survives
    assert st.towns["a"].troops_ci == 0    # the CI unit starves instead


def test_attrition_scores_what_actually_starves_not_a_flat_rate():
    """A starved Shock Troop pays the Insurgency its real presence (2), not
    the base unit's (3, test_engine's INFANTRY)."""
    st = state(map=line_map(supply=0))
    st.towns["a"].troops = 0
    st.towns["a"].troops_shock = 1
    st.towns["a"].starving = 2  # warned for the full cost
    empire_turn(st)
    assert st.towns["a"].troops_shock == 0
    assert st.scores[Side.INSURGENCY] == 2


def test_validate_disband_is_denominated_in_supply_points():
    st = state(map=line_map(supply=0))  # no supply at all
    st.towns["a"].troops = 0
    st.towns["a"].troops_shock = 1  # one troop, but it costs 2 points
    st.towns["a"].starving = 2
    with pytest.raises(IllegalMove):
        validate_disband(st, {"a": 1})  # a head count would say "there is only one"
    validate_disband(st, {"a": 2})  # the real shortfall, in points
