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

namespace Uhifadhi\Roster\Service;

use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckInStatus;
use Uhifadhi\Bundle\AreaBundle\Repository\CheckInStatusRepository;
use Uhifadhi\Bundle\AreaBundle\Service\CheckInStatusService;
use Uhifadhi\Bundle\ShellBundle\Frame\Controller\ConfigureController;
use Uhifadhi\Contracts\Shell\ConfigurationSection;

/**
 * WHAT THE SETTINGS SECTION'S CHECK-IN STATUSES CARD READS: the AREA's list,
 * read here and never written, and the door to where the area edits it.
 *
 * THE DOOR EXISTS ONLY WHERE THE EDITOR DOES. The core's editor saves through
 * the route `area_check_in_statuses` (core 0.3.1 and later); a core without
 * that route has no editor, and the card draws no door rather than one to a
 * card that is not there. The name is written out because the core's class
 * that declares it is absent from the cores this line also runs on.
 */
final readonly class CheckInStatusesCardService
{
    /** The core's save route for the area's statuses — present where the editor is. */
    public const string EDITOR_ROUTE = 'area_check_in_statuses';

    /** The editor card's anchor on the area's Area settings. */
    public const string EDITOR_ANCHOR = 'check-in-statuses';

    public function __construct(
        private CheckInStatusService $statuses,
        private CheckInStatusRepository $repository,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * EVERY STATUS THE AREA HOLDS: the offered ones in their order, then the
     * ones no longer offered, which keep their past check-ins.
     *
     * @return list<CheckInStatus>
     */
    public function statusesOf(AreaOfInterest $area): array
    {
        // Seeds an area that was never asked, as the handset's read does.
        $this->statuses->offeredBy($area);

        return $this->repository->findBy(['area' => $area], ['active' => 'DESC', 'position' => 'ASC', 'id' => 'ASC']);
    }

    /** Where the area edits its statuses, or null on a core with no editor. */
    public function editorUrlFor(string $areaUuid): ?string
    {
        try {
            $this->urls->generate(self::EDITOR_ROUTE, ['uuid' => $areaUuid]);
        } catch (RouteNotFoundException) {
            return null;
        }

        return $this->urls->generate(ConfigureController::AREA_ROUTE, ['uuid' => $areaUuid, 'section' => ConfigurationSection::SETTINGS]).'#'.self::EDITOR_ANCHOR;
    }
}
