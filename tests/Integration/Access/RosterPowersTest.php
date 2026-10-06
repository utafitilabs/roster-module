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

namespace Uhifadhi\Roster\Tests\Integration\Access;

use Symfony\Component\Security\Core\Authorization\UserAuthorizationCheckerInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Enum\PostingSource;
use Uhifadhi\Bundle\AreaBundle\Service\PostingService;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\Placement;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Permissions\PowerCatalogue;
use Uhifadhi\Contracts\Access\Power;
use Uhifadhi\Contracts\Access\PowerGroup;
use Uhifadhi\Contracts\Access\PowerTarget;
use Uhifadhi\Roster\Access\RosterPowers;
use Uhifadhi\Roster\Controller\RosterConfigureController;
use Uhifadhi\Roster\Controller\RosterController;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Tests\Integration\IntegrationTestCase;

/**
 * THE ROSTER'S POWERS OVER SOMEBODY ELSE'S WATCHES, as Team › Permissions
 * reads them: declared, found in the installation's catalogue, and asked of
 * the area the person's watches stand in, so the real rules answer them.
 */
final class RosterPowersTest extends IntegrationTestCase
{
    public function testThePermissionsPageCatalogueCarriesEveryRosterPower(): void
    {
        $catalogue = $this->service(PowerCatalogue::class);
        self::assertInstanceOf(PowerCatalogue::class, $catalogue);

        $ours = array_values(array_filter($catalogue->all(), static fn (Power $power): bool => str_starts_with($power->key, 'roster-')));
        $byKey = [];
        foreach ($ours as $power) {
            $byKey[$power->key] = $power;
        }

        self::assertSame([
            RosterPowers::CHANGE_A_WATCH,
            RosterPowers::OFFER_A_WATCH,
            RosterPowers::GENERATE_WATCHES,
        ], array_keys($byKey));

        foreach ($ours as $power) {
            self::assertSame(PowerGroup::OthersRecords, $power->group, $power->key);
            self::assertSame([PowerTarget::AColleague, PowerTarget::SomebodyBeyondTheirArea], $power->targets, $power->key);
            self::assertInstanceOf(RosterPowers::class, $catalogue->sourceOf($power));
        }

        self::assertSame([RosterController::RECORD], $byKey[RosterPowers::CHANGE_A_WATCH]->questions);
        self::assertSame([RosterController::RECORD], $byKey[RosterPowers::OFFER_A_WATCH]->questions);
        self::assertSame([RosterConfigureController::CONFIGURE], $byKey[RosterPowers::GENERATE_WATCHES]->questions);
    }

    public function testAColleagueIsAskedAboutWhereTheirLatestWatchStands(): void
    {
        $kilimani = $this->anArea('Kilimani');
        $tambarare = $this->anArea('Tambarare');
        $colleague = $this->aPerson('colleague@example.test');
        $this->aDuty($kilimani, $this->aStation($kilimani, 'north gate', 'NG'), $colleague, '-20 days');
        $this->aDuty($tambarare, $this->aStation($tambarare, 'river post', 'RP'), $colleague, '-2 days');
        $this->em->flush();

        $subject = $this->powers()->subject($this->power(RosterPowers::CHANGE_A_WATCH), RosterController::RECORD, PowerTarget::AColleague, $colleague);

        self::assertInstanceOf(AreaOfInterest::class, $subject);
        self::assertSame($tambarare->getId(), $subject->getId());
    }

    public function testSomebodyWithNoWatchYetIsAskedAboutWhereTheyArePosted(): void
    {
        $kilimani = $this->anArea('Kilimani');
        $gate = $this->aStation($kilimani, 'north gate', 'NG');
        $colleague = $this->aPerson('colleague@example.test');
        $this->em->flush();
        $this->postings()->post($gate, $colleague, PostingSource::WrittenHere);

        $subject = $this->powers()->subject($this->power(RosterPowers::OFFER_A_WATCH), RosterController::RECORD, PowerTarget::AColleague, $colleague);

        self::assertInstanceOf(AreaOfInterest::class, $subject);
        self::assertSame($kilimani->getId(), $subject->getId());
    }

    public function testSomebodyTheRosterCannotReachIsAskedOfNothing(): void
    {
        $stranger = $this->aPerson('stranger@example.test');
        $this->em->flush();

        $powers = $this->powers();
        $power = $this->power(RosterPowers::GENERATE_WATCHES);

        self::assertNull($powers->subject($power, RosterConfigureController::CONFIGURE, PowerTarget::AColleague, $stranger));
        self::assertNull($powers->subject($power, RosterConfigureController::CONFIGURE, PowerTarget::AColleague, null));
    }

    /**
     * THE PAGE ASKS AS THE ACTOR, NOT THE SESSION: a duty officer holding
     * the roster's record pair at Kilimani changes a Kilimani ranger's
     * watches and not a Tambarare ranger's.
     */
    public function testTheRealRulesAnswerItForADutyOfficerPlacedAtOneArea(): void
    {
        $kilimani = $this->anArea('Kilimani');
        $tambarare = $this->anArea('Tambarare');
        $operations = new Department()->setName('Operations');
        $this->em->persist($operations);

        $seat = new Position()->setName('Duty officer')->setGrantValues([RosterController::RECORD], [RosterController::RECORD]);
        $this->em->persist($seat);
        $placement = new Placement()->inAreas([$kilimani])->inDepartment($operations);
        $this->em->persist($placement);
        $officer = $this->aPerson('officer@example.test')->setPosition($seat)->setPlacement($placement);

        $near = $this->aPerson('near@example.test');
        $far = $this->aPerson('far@example.test');
        $this->aDuty($kilimani, $this->aStation($kilimani, 'north gate', 'NG'), $near, '-1 day');
        $this->aDuty($tambarare, $this->aStation($tambarare, 'river post', 'RP'), $far, '-1 day');
        $this->em->flush();

        $checker = static::getContainer()->get('security.authorization_checker');
        self::assertInstanceOf(UserAuthorizationCheckerInterface::class, $checker);
        $power = $this->power(RosterPowers::CHANGE_A_WATCH);

        self::assertTrue($checker->isGrantedForUser($officer, RosterController::RECORD, $this->powers()->subject($power, RosterController::RECORD, PowerTarget::AColleague, $near)));
        self::assertFalse($checker->isGrantedForUser($officer, RosterController::RECORD, $this->powers()->subject($power, RosterController::RECORD, PowerTarget::SomebodyBeyondTheirArea, $far)));
    }

    private function aDuty(AreaOfInterest $area, Station $station, User $person, string $when): Duty
    {
        $duty = new Duty($area, $station, $person, 'day', new \DateTimeImmutable($when)->setTime(0, 0));
        $this->em->persist($duty);

        return $duty;
    }

    private function powers(): RosterPowers
    {
        $powers = $this->service(RosterPowers::class);
        self::assertInstanceOf(RosterPowers::class, $powers);

        return $powers;
    }

    private function postings(): PostingService
    {
        $postings = $this->service(PostingService::class);
        self::assertInstanceOf(PostingService::class, $postings);

        return $postings;
    }

    private function power(string $key): Power
    {
        foreach ($this->powers()->powers() as $power) {
            if ($key === $power->key) {
                return $power;
            }
        }

        self::fail('The roster declares no power '.$key);
    }
}
