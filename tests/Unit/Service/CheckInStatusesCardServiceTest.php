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

namespace Uhifadhi\Roster\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Uhifadhi\Bundle\AreaBundle\Repository\CheckInStatusRepository;
use Uhifadhi\Bundle\AreaBundle\Service\CheckInStatusService;
use Uhifadhi\Roster\Service\CheckInStatusesCardService;

/**
 * THE CARD'S ONE DOOR goes to the area's editor where the core has one
 * (0.3.1 and later) and is not drawn where it has none (the 0.2 line).
 */
#[CoversClass(CheckInStatusesCardService::class)]
final class CheckInStatusesCardServiceTest extends TestCase
{
    private const string AREA = '0f9ca41e-0000-4000-8000-000000000001';

    public function testACoreWithTheEditorGetsTheDoorToItsCard(): void
    {
        $card = $this->card(withEditor: true);

        self::assertSame('/areas/'.self::AREA.'/configure/settings#check-in-statuses', $card->editorUrlFor(self::AREA));
    }

    public function testACoreWithoutTheEditorGetsNoDoor(): void
    {
        self::assertNull($this->card(withEditor: false)->editorUrlFor(self::AREA));
    }

    private function card(bool $withEditor): CheckInStatusesCardService
    {
        $urls = new class($withEditor) implements UrlGeneratorInterface {
            public function __construct(private readonly bool $withEditor)
            {
            }

            /** @param array<string, mixed> $parameters */
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                $uuid = \is_string($parameters['uuid'] ?? null) ? $parameters['uuid'] : '';

                return match (true) {
                    'area_check_in_statuses' === $name && $this->withEditor => '/areas/'.$uuid.'/configure/check-in-statuses',
                    'shell_area_configure' === $name => '/areas/'.$uuid.'/configure/'.(\is_string($parameters['section'] ?? null) ? $parameters['section'] : ''),
                    default => throw new RouteNotFoundException($name),
                };
            }

            public function setContext(RequestContext $context): void
            {
            }

            public function getContext(): RequestContext
            {
                return new RequestContext();
            }
        };

        return new CheckInStatusesCardService(
            new CheckInStatusService($this->createStub(EntityManagerInterface::class), $this->createStub(CheckInStatusRepository::class)),
            $this->createStub(CheckInStatusRepository::class),
            $urls,
        );
    }
}
