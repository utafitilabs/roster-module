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

namespace Uhifadhi\Roster\Tests\Integration\Service;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Roster\Entity\RotationPoolMember;
use Uhifadhi\Roster\Enum\RotationPreset;
use Uhifadhi\Roster\Enum\RotationScope;
use Uhifadhi\Roster\Model\Cycle;
use Uhifadhi\Roster\Service\RotationEditor;
use Uhifadhi\Roster\Tests\Integration\IntegrationTestCase;

/**
 * DECLARING A ROTATION — the write behind "New rotation", against the real
 * database.
 *
 * IT IS THE HALF THE MODULE WAS MISSING. A post could be given a watch and
 * still generate nothing for ever, because nothing in the product said what
 * ring it ran; the seeder built rotations by hand and no screen did.
 */
final class RotationEditorDeclaresTest extends IntegrationTestCase
{
    private AreaOfInterest $area;
    private Station $gate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = $this->anArea();
        $this->gate = $this->aStation($this->area, 'north gate post');
        $this->theShiftVocabulary($this->area);
        $this->em->flush();
    }

    private function editor(): RotationEditor
    {
        $editor = $this->service(RotationEditor::class);
        self::assertInstanceOf(RotationEditor::class, $editor);

        return $editor;
    }

    public function testAPostsRingIsThePresetFilledWithThePostsOwnWatches(): void
    {
        $rotation = $this->editor()->declareForPost($this->gate, ['day', 'night'], RotationPreset::TwoOfEachThenOff, []);

        self::assertSame(RotationScope::Post, $rotation->getScope());
        self::assertSame($this->gate, $rotation->getStation());
        self::assertSame(['day', 'day', 'night', 'night', Cycle::OFF], $rotation->getCycle()->positions);
        self::assertSame(['day' => 1, 'night' => 1], $rotation->getSlotsPerShift());
        self::assertSame(RotationEditor::STARTING_HORIZON_DAYS, $rotation->getHorizonDays());
        self::assertNull($rotation->getGeneratedThrough(), 'Declaring is not generating.');
    }

    /**
     * THE POOL IS THE PEOPLE POSTED THERE, IN ORDER, because the order is
     * the plan: each person enters the ring one day later than the last.
     */
    public function testThePoolKeepsTheOrderItWasGivenIn(): void
    {
        $first = $this->aPerson('ada@example.test', 'Ada');
        $second = $this->aPerson('bea@example.test', 'Bea');
        $this->em->flush();

        $rotation = $this->editor()->declareForPost($this->gate, ['day'], RotationPreset::OneOfEachThenOff, [$first, $second]);

        $names = array_map(
            static fn (RotationPoolMember $member): string => (string) $member->getPerson()->getFirstName(),
            array_values($rotation->getPool()->toArray()),
        );

        self::assertSame(['Ada', 'Bea'], $names);
    }

    /**
     * ONE RING PER POST. A second declaration answers with the first rather
     * than quietly giving a post two patterns that would both generate.
     */
    public function testDeclaringTwiceAnswersWithTheRingThatAlreadyStands(): void
    {
        $first = $this->editor()->declareForPost($this->gate, ['day'], RotationPreset::OneOfEachThenOff, []);
        $again = $this->editor()->declareForPost($this->gate, ['night'], RotationPreset::AWeeklySet, []);

        self::assertSame($first->getUuid()->toRfc4122(), $again->getUuid()->toRfc4122());
        self::assertSame(['day', Cycle::OFF], $again->getCycle()->positions, 'And the standing ring is untouched.');
    }

    /**
     * A SQUAD'S RING IS FILED AT ITS BASE POST. A duty is one watch at one
     * station on one day, and every count in the product is keyed by
     * station — so the cycle travels with the team and the filing stands
     * still.
     */
    public function testASquadsRingIsFiledAtItsBasePost(): void
    {
        $rotation = $this->editor()->declareForTeam('crater response team', $this->gate, ['day'], RotationPreset::TenOnFourOff, []);

        self::assertSame(RotationScope::Team, $rotation->getScope());
        self::assertNull($rotation->getStation());
        self::assertSame('crater response team', $rotation->getTeamName());
        self::assertSame($this->gate, $rotation->getBaseStation());
        self::assertSame($this->gate, $rotation->watchStation());
    }

    public function testAnUnnamedSquadIsRefusedWithASentence(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/needs one/');

        $this->editor()->declareForTeam('   ', $this->gate, ['day'], RotationPreset::AWeeklySet, []);
    }

    public function testAPostThatStandsNoWatchIsRefusedRatherThanGivenAnEmptyRing(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->editor()->declareForPost($this->gate, [], RotationPreset::OneOfEachThenOff, []);
    }
}
