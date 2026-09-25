<?php

declare(strict_types=1);

/*
 * This file is part of the UhifadhiLabs Roster Module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Roster\Enum;

use Uhifadhi\Roster\Model\RuleValue;

/**
 * THE RULES AN AREA SETS, AND ANY STATION MAY OVERRULE.
 *
 * RULED 20 sep. Owner: "rules configurable like exceptions" — any rule,
 * not just hours. So the rules are one list with one shape, an area default
 * each and a per-station exception under any of them, and nothing in the
 * product assumes one way of working. The one exception is Ping every, the
 * area's own number, which the card shows and does not set
 * ({@see isSetOnTheArea()}).
 *
 * RAISE SHORT COVER IS ABOUT THE DASHBOARD AND NOT ABOUT THE SHEET (ruled
 * 21 sep, and the reason the checkbox beside it is gone). A station-day
 * under the number it names is on the sheet the moment it is true —
 * always, with no setting, as a cover token on the station's own row.
 * This rule says only when that station-day is RAISED as needing a
 * decision, which is why the row reads "raise short cover 4 hours before
 * it starts · as needing a decision" and there is nothing to tick.
 */
enum RuleKind: string
{
    case LateAfter = 'late_after';
    case OfflineAfter = 'offline_after';
    case PingEvery = 'ping_every';
    case CheckInWithin = 'check_in_within';
    case RaiseShortCover = 'raise_short_cover';

    /*
     * ---- FILLING. RULED 21 sep -------------------------------------------
     * The four the SHEET obeys when it fills days from a pattern. They are
     * area rules and they live here, on the Watches card, because a rule a
     * fill obeys is a rule a station may overrule — and the fill row above
     * the sheet states them read-only with a door back to this card rather
     * than offering a second place to set them.
     */
    case RestBetween = 'rest_between';
    case NightThenDay = 'night_then_day';
    case FillAhead = 'fill_ahead';
    case ForbiddenDay = 'forbidden_day';

    /** The uppercase mono label at the head of the row. */
    public function label(): string
    {
        return match ($this) {
            self::LateAfter => 'Late after',
            self::OfflineAfter => 'Offline after',
            self::PingEvery => 'Ping every',
            self::CheckInWithin => 'Check-in within',
            self::RaiseShortCover => 'Raise short cover',
            self::RestBetween => 'Rest between watches',
            self::NightThenDay => 'Night then day',
            self::FillAhead => 'Fill ahead',
            self::ForbiddenDay => 'A day the rules forbid',
        };
    }

    /**
     * A RULE THE AREA SETS AND THIS CARD ONLY SHOWS. How often a handset
     * pings is the area's fact — the check-in, the pings and the presence
     * read from them are the area's, and the handset is told the area's
     * number — so its row is read-only with a door to the area's settings,
     * no station overrules it, and this module stores no copy of it.
     */
    public function isSetOnTheArea(): bool
    {
        return self::PingEvery === $this;
    }

    /**
     * THE FOUR A FILL OBEYS. The card draws them under their own group
     * head, and the sheet's fill row states them; everything else on this
     * enum is about a watch that is already standing.
     */
    public function isFilling(): bool
    {
        return \in_array($this, [self::RestBetween, self::NightThenDay, self::FillAhead, self::ForbiddenDay], true);
    }

    /**
     * THE FRAGMENT AFTER THE CONTROL — what the rule is about, in three or
     * four words.
     *
     * NOT A SENTENCE (cut 21 sep). Each rule used to carry a second line
     * saying what happens if you get the value wrong; five of them was
     * about 330 words standing between five controls, and it is what made
     * the card a wall. The reasoning lives in the markup's comments for
     * whoever implements it; the screen states facts.
     */
    public function fragment(): string
    {
        return match ($this) {
            self::LateAfter => 'without a ping',
            self::OfflineAfter => 'the map stops claiming to know',
            self::PingEvery => 'set on the area · per handset',
            self::CheckInWithin => 'of the station',
            self::RaiseShortCover => 'before it starts · as needing a decision',
            self::RestBetween => 'one watch ending to the next starting',
            self::NightThenDay => 'a day watch the morning after a night watch',
            self::FillAhead => 'refilled every night',
            self::ForbiddenDay => 'never filled wrongly',
        };
    }

    /**
     * A RULE WHOSE ANSWER IS PICKED AND NOT MEASURED. Two of the four
     * filling rules have no number in them at all, so the row draws a
     * select and the entity stores a case instead of a pair.
     */
    public function isChoice(): bool
    {
        return \in_array($this, [self::NightThenDay, self::ForbiddenDay], true);
    }

    /**
     * THE OPTIONS THIS RULE OFFERS, in the order the select prints them;
     * empty on every measured rule.
     *
     * @return list<RuleChoiceInterface>
     */
    public function choices(): array
    {
        return match ($this) {
            self::NightThenDay => NightThenDay::cases(),
            self::ForbiddenDay => ForbiddenDay::cases(),
            default => [],
        };
    }

    /**
     * WHAT THIS AREA RUNS AT BEFORE ANYBODY TOUCHES IT, for a chosen rule.
     *
     * @throws \LogicException when the kind is measured and has no choices
     */
    public function standardChoice(): RuleChoiceInterface
    {
        return match ($this) {
            self::NightThenDay => NightThenDay::Never,
            self::ForbiddenDay => ForbiddenDay::LeftUnfilled,
            default => throw new \LogicException(\sprintf('"%s" is measured, not chosen; ask it for its standard value.', $this->label())),
        };
    }

    /**
     * THE CASE THIS RULE STORED, READ BACK. A value this kind does not
     * offer is refused here rather than three screens later.
     *
     * @throws \InvalidArgumentException when the stored value is not one of this rule's options
     */
    public function choiceOf(string $stored): RuleChoiceInterface
    {
        foreach ($this->choices() as $choice) {
            if ($choice->value === $stored) {
                return $choice;
            }
        }

        throw new \InvalidArgumentException(\sprintf('"%s" is not one of the answers "%s" offers.', $stored, $this->label()));
    }

    public function measuresTime(): bool
    {
        return self::CheckInWithin !== $this && !$this->isChoice();
    }

    /**
     * The units this rule may be stated in, in the order the select offers
     * them.
     *
     * @return list<RuleUnit>
     */
    public function units(): array
    {
        return match (true) {
            $this->isChoice() => [],
            self::CheckInWithin === $this => [RuleUnit::Kilometres, RuleUnit::Metres],
            // A HORIZON IS NOT A THRESHOLD. Nobody fills ninety minutes
            // ahead, and offering minutes on this row would be offering an
            // answer no area wants to give.
            self::FillAhead === $this => [RuleUnit::Days, RuleUnit::Weeks, RuleUnit::Months],
            default => [RuleUnit::Minutes, RuleUnit::Hours, RuleUnit::Days],
        };
    }

    /**
     * WHAT THIS AREA RUNS AT BEFORE ANYBODY TOUCHES IT. Not a blessed
     * option — a starting point, which every rule the roster sets is free of
     * the moment somebody types over it.
     */
    public function standard(): RuleValue
    {
        return match ($this) {
            self::RestBetween => new RuleValue(11.0, RuleUnit::Hours),
            self::FillAhead => new RuleValue(6.0, RuleUnit::Weeks),
            self::NightThenDay, self::ForbiddenDay => throw new \LogicException(\sprintf('"%s" is chosen, not measured; ask it for its standard choice.', $this->label())),
            self::LateAfter => new RuleValue(2.0, RuleUnit::Hours),
            self::OfflineAfter => new RuleValue(1.0, RuleUnit::Days),
            self::PingEvery => new RuleValue(30.0, RuleUnit::Minutes),
            self::CheckInWithin => new RuleValue(1.5, RuleUnit::Kilometres),
            self::RaiseShortCover => new RuleValue(4.0, RuleUnit::Hours),
        };
    }

    /**
     * A VALUE OF THIS KIND, refused where the unit cannot measure it.
     *
     * @throws \InvalidArgumentException when the unit measures the wrong thing
     */
    public function valueOf(float $value, RuleUnit $unit): RuleValue
    {
        // THE UNITS THE ROW OFFERS ARE THE UNITS THE RULE TAKES. Asking
        // whether the unit measures the right KIND of thing is not enough:
        // "late after 3 weeks" measures time and is still not an answer
        // this rule has ever offered anybody.
        if (!\in_array($unit, $this->units(), true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not stated in %s.', $this->label(), $unit->value));
        }

        return new RuleValue($value, $unit);
    }
}
