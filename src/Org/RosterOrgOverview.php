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

namespace Uhifadhi\Roster\Org;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Uhifadhi\Bundle\AreaBundle\Overview\ContributesStylesheetInterface;
use Uhifadhi\Bundle\AreaBundle\Overview\NowTile;
use Uhifadhi\Bundle\AreaBundle\Overview\OrgOverviewContributorInterface;
use Uhifadhi\Bundle\ShellBundle\Widget\Model\Widget;
use Uhifadhi\Bundle\ShellBundle\Widget\Model\WidgetGroup;
use Uhifadhi\Contracts\Shell\Scope;
use Uhifadhi\Roster\Controller\RosterOrgController;
use Uhifadhi\Roster\Module\RosterModuleProvider;
use Uhifadhi\Roster\Service\RosterOrgService;
use Uhifadhi\Roster\UhifadhiRosterBundle;

/**
 * WHAT THE ROSTER PUTS ON THE ORGANIZATION DASHBOARD — the figure "on duty
 * now" in the strip, and today's watches across every area.
 *
 * DECLARED BY THE DESIGN, not chosen here: `org.widgets.js` gives this
 * module the `roster` group and the `watches` cell, and gives the `kpis`
 * strip one figure per contributor with this module's first of the four.
 *
 * EVERY NUMBER IS THE AREA READING ONE SCOPE WIDER. The figure is
 * {@see RosterOrgService::figuresFor()} — which is the area's own
 * `RosterFiguresService` over the scope's areas — and the rows are
 * {@see RosterOrgService::watches()}, which is the area's own presence
 * reading with the area column added. This class counts nothing itself,
 * because a second aggregate for this screen would be two answers to one
 * question with no way to say which was right.
 *
 * THE SCOPE IS THE ONE IT IS HANDED. The dashboard is the organization
 * today, but the contract passes a scope rather than assuming one, and this
 * answers whatever it is given — which is what will let the same cells be
 * drawn for one area the day a scope control appears above them.
 *
 * IT DRAWS NOTHING ITSELF. The cell is this bundle's own partial, rendered
 * by the host with `with_context: false` against ONE map whose figures sit
 * under `by.<slug>` — so a cell cannot read another module's numbers by
 * accident, and this module's own template is the only place its markup
 * lives.
 */
final readonly class RosterOrgOverview implements OrgOverviewContributorInterface, ContributesStylesheetInterface
{
    /** The same slug the module provider declares — how a cell disappears with the module. */
    public const string SLUG = RosterModuleProvider::SLUG;

    /** The cell the design names, and the id its partial is found by. */
    public const string WATCHES = 'watches';

    /** What the strip's tile is called, in the design's own words. */
    public const string ON_DUTY_LABEL = 'On duty now';

    /**
     * HOW MANY ROWS THE CELL DRAWS BEFORE IT STOPS.
     *
     * A DASHBOARD CARD NEVER GROWS WITH ITS DATA (ruled): an organization
     * of forty stations would otherwise push every cell under it off the
     * screen. The rest are reached through the one door the card carries,
     * and the tab says how many there are so the cap is never silent.
     */
    public const int ROWS_SHOWN = 5;

    public function __construct(
        private RosterOrgService $org,
        private UrlGeneratorInterface $router,
    ) {
    }

    public function moduleSlug(): string
    {
        return self::SLUG;
    }

    public function group(): WidgetGroup
    {
        return new WidgetGroup(
            self::SLUG,
            'Roster',
            'Who is working, where they are, and how old that answer is — at organization scope.',
        );
    }

    public function widgets(): array
    {
        return [
            new Widget(
                self::WATCHES,
                'Today’s watches',
                self::SLUG,
                6,
                [12, 9, 6, 3],
                true,
                'One row per station that runs a watch, in any area: what it expects, who is on it, and how many of them the handset verified.',
            ),
        ];
    }

    public function partialPattern(): string
    {
        return '@UhifadhiRoster/org/_w_%s.html.twig';
    }

    /**
     * THE SHEET THIS CELL'S ROWS ARE DRAWN IN. The dashboard links it once;
     * without it the cell renders in browser defaults on a page that has
     * never heard of this module.
     */
    public function stylesheet(): string
    {
        return UhifadhiRosterBundle::STYLESHEET;
    }

    /**
     * ONE FIGURE — who is on duty across the organization right now.
     *
     * THE DENOMINATOR IS WHO WAS DUE, not who exists. "14 of 22" answers
     * "did the day get staffed", which is the question a control room opens
     * this page for; against a headcount it would answer "is the
     * organization fully employed", which nobody asks at six in the
     * morning.
     *
     * A NO-CHECK-IN IS THE ALARM AND NEVER PART OF THE SUBLINE. It is the
     * one of the three that needs somebody to act, so the strip prints it
     * apart and in the failing tone — and only when there is one, because a
     * card that always shows an alarm slot has trained everybody to ignore
     * it.
     */
    public function figures(Scope $scope, \DateTimeImmutable $now): array
    {
        $areas = $this->org->areasIn($scope, []);
        if ([] === $areas) {
            return [];
        }

        $day = $now->setTime(0, 0);
        $figures = $this->org->figuresFor($areas, $day, $now);

        // NOTHING ON THE BOOKS IS NOT A ZERO. An organization that has
        // given no station a watch has not staffed nobody — it has not been
        // set up, and the strip's own empty tile says that better than a
        // nought would.
        if (!$figures->hasABook()) {
            return [];
        }

        return [new NowTile(
            index: self::SLUG.'-on-duty',
            moduleSlug: self::SLUG,
            label: self::ON_DUTY_LABEL,
            value: (string) $figures->checkedIn,
            subline: \sprintf(
                '%d at a station · %d unverified',
                $figures->verified,
                $figures->flagged,
            ),
            unit: \sprintf('of %d', $figures->expected),
            alarm: $figures->noCheckIn > 0 ? \sprintf('%d no check-in', $figures->noCheckIn) : null,
            tone: NowTile::TONE_HOT,
            live: true,
            url: $this->router->generate(RosterOrgController::TODAY_ROUTE),
            priority: 10,
        )];
    }

    /**
     * EVERYTHING THIS CONTRIBUTOR'S PARTIAL READS, at this scope, measured
     * once — two cards of one module's that disagreed by a second would be
     * worse than either being wrong.
     */
    public function context(Scope $scope, \DateTimeImmutable $now): array
    {
        $areas = $this->org->areasIn($scope, []);
        $day = $now->setTime(0, 0);
        $rows = [] === $areas ? [] : $this->org->watches($areas, $day, $now);

        // THE TAB'S OWN TWO FIGURES, FOLDED FROM THE ROWS THEMSELVES — not
        // asked of anything a second time. A caption that disagreed with
        // the table under it is the one defect a summary line can have.
        $onTheWatch = 0;
        $short = 0;
        foreach ($rows as $row) {
            $onTheWatch += $row->onItNow;
            $short += max(0, $row->expected - $row->onItNow);
        }

        return [
            'day' => $day,
            'watches' => $rows,
            'onTheWatch' => $onTheWatch,
            'short' => $short,
            'shown' => self::ROWS_SHOWN,
            // THE DOOR IS THE ORGANIZATION'S OWN ROSTER, never one area's:
            // a card that read across every area and led into one of them
            // would answer a question nobody asked it.
            'rosterUrl' => $this->router->generate(RosterOrgController::TODAY_ROUTE),
        ];
    }
}
